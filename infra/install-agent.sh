#!/usr/bin/env bash
#
# Install cic-agent as a service on a host that bootstrap.sh has already prepared.
#
#   scp agent/bin/cic-agent-linux root@HOST:/opt/codeinchrome/bin/cic-agent
#   ssh root@HOST 'bash -s' < infra/install-agent.sh
#
# Idempotent. Generates the agent token once and never rotates it silently — a
# silently rotated token looks exactly like an outage to the control plane.

set -Eeuo pipefail
log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m  ok\033[0m %s\n' "$*"; }
warn() { printf '\033[33m  !!\033[0m %s\n' "$*"; }
die()  { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root"
CIC=/opt/codeinchrome
[[ -x $CIC/bin/cic-agent ]] || die "$CIC/bin/cic-agent missing - copy the binary first"
[[ -s $CIC/etc/host.id ]]   || die "no host id - run bootstrap.sh first"

# ─────────────────────────────────────────────────────────────────────────────
log "agent token"
if [[ ! -s $CIC/etc/agent.env ]]; then
  umask 077
  printf 'CIC_AGENT_TOKEN=%s\n' "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "$CIC/etc/agent.env"
  ok "token generated"
else
  ok "token already present (not rotated)"
fi
chmod 0600 "$CIC/etc/agent.env"

# ─────────────────────────────────────────────────────────────────────────────
log "caddy"
# Per-site vhosts are separate files the agent writes. Importing a glob means
# adding a site never rewrites a file that other sites depend on.
mkdir -p "$CIC/caddy/sites" /var/log/caddy
chown -R caddy:caddy /var/log/caddy 2>/dev/null || true
# Re-applied here, not only in bootstrap.sh: a host bootstrapped before this
# fix has a 0750 root:root $CIC that caddy cannot traverse, and the only
# symptom is an import glob that matches nothing and warns instead of failing.
chgrp caddy "$CIC" "$CIC/caddy" "$CIC/caddy/sites" 2>/dev/null || true
chmod 0750 "$CIC" "$CIC/caddy" "$CIC/caddy/sites"
chmod 0700 "$CIC/etc"
# This file is fully managed, so write it every run rather than guarding on a
# marker. The first version of this script guarded on the import line, which was
# already present from a previous run - so a FIX to the rest of the file was
# silently never applied, and the reload kept failing for a reason that had
# already been corrected in source.
cp -n /etc/caddy/Caddyfile /etc/caddy/Caddyfile.orig 2>/dev/null || true
# Cloudflare's published ranges: the only addresses whose CF-Connecting-IP
# header is believed. Fetched every run; refusing to write the file without
# them, because an empty list would make every visitor look like Cloudflare.
cf_ranges=$(curl -fsS --retry 3 https://api.cloudflare.com/client/v4/ips \
  | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print(" ".join(r["ipv4_cidrs"] + r["ipv6_cidrs"]))')
[[ $cf_ranges == *"/"* ]] || { echo "could not fetch Cloudflare's IP ranges" >&2; exit 1; }
cat > /etc/caddy/Caddyfile <<'CADDY'
# Managed by codeinchrome. Per-site configuration lives in its own file under
# /opt/codeinchrome/caddy/sites and is written by cic-agent.
{
	# The admin API is how `systemctl reload` applies config without dropping
	# connections. `admin off` breaks every reload, so keep it - bound to
	# loopback only. A customer container cannot reach it: 127.0.0.1 inside a
	# container is the container's own loopback, not the host's. That claim is
	# checked below rather than assumed.
	admin localhost:2019
	email ops@codeinchrome.com

	# Certificates are requested on the first TLS connection for a name, and
	# only for names the agent confirms it hosts. See the /tls-ask handler in
	# agent/internal/api/api.go for why: requesting at load time raced DNS
	# propagation, and a lost race left a new site without HTTPS for up to 30
	# minutes. The ask gate stops anyone from making us request certificates
	# for names we do not serve.
	on_demand_tls {
		ask http://127.0.0.1:9440/tls-ask
	}

	# Old servers finish in-flight requests for at most this long after a
	# reload. Unbounded, one lingering keep-alive connection (an internet
	# scanner, say) held port 80 open and the next reload failed with
	# "bind: address already in use".
	grace_period 10s
	# Visitors arrive through Cloudflare. The real address is CF-Connecting-IP,
	# believed ONLY on connections from Cloudflare's own ranges (strict: the
	# right-most untrusted hop), so nobody can claim an address by sending the
	# header directly. Access logs and rate limits then see the visitor.
	servers {
		trusted_proxies static private_ranges __CF_RANGES__
		trusted_proxies_strict
		client_ip_headers CF-Connecting-IP
	}
}

# NOTE: do not add a bare `:80` catch-all here. Caddy groups sites by listen
# address, so a `:80` block pulls the named site blocks into an HTTP-only
# server and AUTOMATIC HTTPS IS SILENTLY DISABLED for all of them - the symptom
# is Caddy never binding 443 at all. An unmatched request already fails the TLS
# handshake without revealing which customers are on this host.
import /opt/codeinchrome/caddy/sites/*.caddy
CADDY
sed -i "s|__CF_RANGES__|$cf_ranges|" /etc/caddy/Caddyfile
ok "Caddyfile written (managed; $(wc -w <<<"$cf_ranges") Cloudflare ranges trusted)"

# ── web ports: Cloudflare only ──────────────────────────────────────────────
# Every site is served through Cloudflare (proxied DNS, origin certificate).
# A request straight to this host's IP skips its protection, and an abuse
# report about a site then goes to the hosting company instead of to us. So
# 80 and 443 are open to Cloudflare's published ranges only (2026-09-25).
# The new rules go in BEFORE the open ones come out: there is no moment with
# the web ports shut. Custom domains, which would need direct access, stay
# off until Cloudflare for SaaS carries them (config fleet.custom_domains).
n_ranges=$(wc -w <<<"$cf_ranges")
(( n_ranges >= 15 )) || { echo "only $n_ranges Cloudflare ranges fetched; firewall left as it was" >&2; exit 1; }
for cidr in $cf_ranges; do
  ufw allow proto tcp from "$cidr" to any port 80,443 comment cloudflare >/dev/null
done
ufw delete allow 80/tcp  >/dev/null 2>&1 || true
ufw delete allow 443/tcp >/dev/null 2>&1 || true
ok "80 and 443 open to Cloudflare's $n_ranges ranges only"

# The host's own site, permanently. With it, Caddy's HTTP and HTTPS servers
# exist even when the host has no customer sites, so a reload always REUSES
# the listeners on :80 and :443 instead of tearing them down and binding them
# again. A host that went from one site to zero and back failed exactly there.
# Named with a leading underscore so it never collides with a site id.
if [[ -n ${CIC_HOST_NAME:-} ]]; then
  cat > "$CIC/caddy/sites/_host.caddy" <<HOSTSITE
# This host's own address - keeps the proxy's listeners alive. Managed.
${CIC_HOST_NAME}.codeinchrome.com {
	# Behind Cloudflare like every site (2026-09-25): the origin wildcard,
	# no public certificate - whose renewal needed 80/443 open to the world.
	tls /etc/caddy/origin/cert.pem /etc/caddy/origin/key.pem
	respond "codeinchrome host ${CIC_HOST_NAME}" 200
}
HOSTSITE
  ok "permanent host site ${CIC_HOST_NAME}.codeinchrome.com"
else
  warn "CIC_HOST_NAME not set: no permanent host site (run through deploy-host.sh)"
fi
# A name under the platform domain that this host does not serve - a deleted
# site, a typo, a scanner - gets a plain 404. With the origin wildcard
# certificate loaded, TLS completes for ANY such name, and Caddy then answered
# an empty 200 (found by the e2e suite: a deleted site looked alive). Exact
# site names always win over this wildcard; custom domains never reach it.
if [[ -s /etc/caddy/origin/cert.pem && -s /etc/caddy/origin/key.pem ]]; then
  cat > "$CIC/caddy/sites/_zz_unknown.caddy" <<UNKNOWN
# Any platform name this host does not serve. Managed.
*.${CIC_PLATFORM_DOMAIN:-codeinchrome.com} {
	tls /etc/caddy/origin/cert.pem /etc/caddy/origin/key.pem
	header -Server
	respond "Not found" 404
}
UNKNOWN
  ok "unknown platform names answer 404"
fi
caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 || die "Caddyfile invalid"
systemctl reload caddy 2>/dev/null || systemctl restart caddy
ok "caddy reloaded"

# ─────────────────────────────────────────────────────────────────────────────
log "site disks"
[[ -x $CIC/bin/cic-mount ]] || die "$CIC/bin/cic-mount missing - deploy-host.sh copies it"
# Every site's disk image is mounted at boot BEFORE docker starts. Otherwise
# restart=unless-stopped brings containers back onto bind mounts of EMPTY
# directories: the site serves nothing, or writes land on the host's own disk
# underneath the mountpoint and silently escape the quota.
cat > /etc/systemd/system/cic-mounts.service <<UNIT
[Unit]
Description=codeinchrome: mount every site disk before docker starts
DefaultDependencies=no
After=local-fs.target
Before=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$CIC/bin/cic-mount all

# Ordered before docker, but not REQUIRED by it: one bad image must not stop
# docker - and every other site on the host - from starting. The agent stops
# any container whose disk is not mounted when it starts (Reconcile).
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable cic-mounts.service >/dev/null 2>&1
systemctl start cic-mounts.service
ok "site disks mounted before docker at boot"

# ─────────────────────────────────────────────────────────────────────────────
log "automatic reboots for kernel updates"
# The owner's decision (2026-09-25): security updates install themselves, and
# when one needs a reboot the host takes it early in the morning (UTC) - a
# minute of downtime - one host at a time: h1 04:30, then 10 minutes apart by
# host number (the control host is 05:15, deploy-control.sh). Monitoring
# still alerts if an update waits more than 3 days (Monitoring REBOOT_GRACE_DAYS).
n=${CIC_HOST_NAME#h}; [[ $n =~ ^[0-9]+$ ]] || n=1
at_min=$(( 4*60 + 30 + (n - 1) * 10 ))
reboot_at=$(printf '%02d:%02d' $(( at_min / 60 )) $(( at_min % 60 )))
cat > /etc/apt/apt.conf.d/52cic-reboot <<APT
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-WithUsers "true";
Unattended-Upgrade::Automatic-Reboot-Time "$reboot_at";
APT
ok "an update that needs a reboot is applied at $reboot_at UTC"

log "evidence retention"
# A deleted site's access log is kept 30 days for abuse reports (the agent
# moves it to /var/log/caddy/deleted), then removed: they hold visitors' IPs.
cat > /etc/cron.daily/cic-evidence-prune <<'CRON'
#!/bin/sh
find /var/log/caddy/deleted -type f -name '*.log' -mtime +30 -delete 2>/dev/null
exit 0
CRON
chmod 0755 /etc/cron.daily/cic-evidence-prune
ok "deleted sites' access logs kept 30 days, then removed"

log "weekly base image rebuild"
# --pull fetches the latest php:8.3-apache: this is how PHP and Apache security
# fixes arrive. The Dockerfile asserts every extension loads, so a broken
# upstream fails the build and the old image stays in place. Running sites
# move onto the new image via the control plane's fleet:roll-image, one at a
# time, each checked from outside.
cat > /etc/systemd/system/cic-image-rebuild.service <<'UNIT'
[Unit]
Description=codeinchrome: rebuild the Laravel base image with upstream security fixes
[Service]
Type=oneshot
WorkingDirectory=/opt/codeinchrome/images/laravel-8.3
ExecStart=/usr/bin/docker build --pull -q -t codeinchrome/laravel:8.3 .
ExecStartPost=/usr/bin/docker image prune -f
Nice=10
UNIT
cat > /etc/systemd/system/cic-image-rebuild.timer <<'UNIT'
[Unit]
Description=codeinchrome weekly base image rebuild
[Timer]
OnCalendar=Sun *-*-* 03:30:00
RandomizedDelaySec=30min
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now cic-image-rebuild.timer >/dev/null 2>&1
ok "base image rebuilt weekly"

# ─────────────────────────────────────────────────────────────────────────────
log "service"
# Sites under the platform domain use the Cloudflare origin certificate once
# infra/setup-cloudflare-proxy.sh has installed it; until then, on-demand ACME.
origin_flags=""
if [[ -s /etc/caddy/origin/cert.pem && -s /etc/caddy/origin/key.pem ]]; then
  origin_flags="-platform-domain ${CIC_PLATFORM_DOMAIN:-codeinchrome.com} -origin-cert /etc/caddy/origin/cert.pem -origin-key /etc/caddy/origin/key.pem"
fi
cat > /etc/systemd/system/cic-agent.service <<UNIT
[Unit]
Description=codeinchrome host agent
After=docker.service caddy.service
Requires=docker.service

[Service]
Type=simple
EnvironmentFile=$CIC/etc/agent.env
# The leading "-" makes it optional: a host without MySQL still runs the agent,
# and creating a site there fails with that reason instead.
EnvironmentFile=-$CIC/etc/mysql.env
ExecStart=$CIC/bin/cic-agent -addr 127.0.0.1:9440 $origin_flags
Restart=always
RestartSec=3

# The agent drives docker and writes Caddy config, so it cannot be fully
# sandboxed - but it has no reason to touch anything outside these paths.
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=yes
ReadWritePaths=$CIC /srv/customers /var/log/caddy
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now cic-agent >/dev/null 2>&1
sleep 2
systemctl restart cic-agent
sleep 2
ok "cic-agent service installed"

# ─────────────────────────────────────────────────────────────────────────────
log "verifying"
fails=0
has()  { local pat=$1; shift; local out; out=$("$@" 2>/dev/null) || true; [[ "$out" == *"$pat"* ]]; }
exec 3>&2 # the real stderr, for a check that must say why it failed
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else warn "$1"; fails=$((fails+1)); fi; }

check "agent service active"     'systemctl is-active cic-agent'
check "agent answers /healthz"   'has "\"ok\":true" curl -fsS http://127.0.0.1:9440/healthz'
check "agent rejects no token"   '[[ "$(curl -s -o /dev/null -w %{http_code} http://127.0.0.1:9440/v1/host)" == "401" ]]'
check "agent accepts its token"  'has "\"ok\":true" curl -fsS -H "Authorization: Bearer $(grep -o "[0-9a-f]\{64\}" '"$CIC"'/etc/agent.env)" http://127.0.0.1:9440/v1/host'
check "agent not on public iface" '! has "0.0.0.0:9440" ss -ltn'
check "token file is 0600"       '[[ "$(stat -c %a '"$CIC"'/etc/agent.env)" == "600" ]]'
check "caddy active"             'systemctl is-active caddy'
check "base image present"       'docker image inspect codeinchrome/laravel:8.3'
check "caddy reload works"       'systemctl reload caddy'
check "admin api loopback only"  '! has "0.0.0.0:2019" ss -ltn'
check "weekly image rebuild scheduled" 'systemctl is-active cic-image-rebuild.timer'
check "site disks mount before docker" 'systemctl is-enabled cic-mounts.service && systemctl show docker -p After --value | grep -q cic-mounts.service'
check "tls gate refuses a stranger" '[[ "$(curl -s -o /dev/null -w %{http_code} "http://127.0.0.1:9440/tls-ask?domain=not-ours.example.com")" == "404" ]]'
check "caddy has the tls gate"   'has "tls-ask" curl -s http://127.0.0.1:2019/config/apps/tls/automation'
# The isolation claim that matters: a customer container must not be able to
# reconfigure the proxy that fronts every other customer on this host.
check "container cannot reach admin API" \
  '! docker run --rm --network bridge curlimages/curl:latest -s --max-time 4 http://127.0.0.1:2019/config/ >/dev/null 2>&1'

# The checks below exist because of a real outage: caddy could not traverse
# $CIC, its import glob matched nothing, and because an empty glob only WARNS,
# `caddy validate` and `systemctl reload` both reported success while caddy
# served nothing. Never test this by adapting the config as root - root can
# read the directory that caddy cannot, so it passes while production fails.
check "caddy user can read vhost dir" 'sudo -u caddy test -r '"$CIC"'/caddy/sites'
check "caddy user CANNOT read agent token" '! sudo -u caddy test -r '"$CIC"'/etc/agent.env'
# These three only mean anything once a vhost exists. On a fresh host the
# import glob CORRECTLY matches nothing and caddy CORRECTLY binds no port, so
# asserting otherwise fails every new host for doing the right thing - which is
# what the first version of this did, on two hosts out of three. The third
# passed only because the journal window happened to miss the warning, so the
# check was flaky as well as wrong.
if compgen -G "$CIC/caddy/sites/*.caddy" >/dev/null; then
  check "no unmatched import glob"           '! has "No files matching import" journalctl -u caddy --since "-60s" -o cat'
  # Polled: its certificate is obtained in the background after a reload, and
  # the first check ran in the seconds before it existed.
  host_site_answers() {
    for _ in $(seq 1 30); do
      # -k: the origin certificate is trusted by Cloudflare (Full strict), not
      # by curl; this checks the listener, "a site answers through
      # Cloudflare" below checks the real path.
      has "codeinchrome host" curl -sk --max-time 10 --resolve "${CIC_HOST_NAME:-none}.codeinchrome.com:443:127.0.0.1" \
        "https://${CIC_HOST_NAME:-none}.codeinchrome.com/" && return 0
      sleep 3
    done
    # Say why (fd 3: check() silences the rest): a bare "!!" sent a deploy
    # hunting for a cause.
    curl -sSk -o /dev/null --max-time 10 --resolve "${CIC_HOST_NAME:-none}.codeinchrome.com:443:127.0.0.1" \
      -w 'host site: HTTP %{http_code}, TLS verify %{ssl_verify_result}\n' "https://${CIC_HOST_NAME:-none}.codeinchrome.com/" >&3 2>&3 || true
    return 1
  }
  check "host site answers" 'host_site_answers'
  # The real path: out through Cloudflare and back in on its ranges.
  site_through_cloudflare() {
    local f name any=0
    for f in "$CIC"/caddy/sites/*.caddy; do
      name=$(basename "$f" .caddy); [[ $name == _* ]] && continue
      any=1
      [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://$name.${CIC_PLATFORM_DOMAIN:-codeinchrome.com}/") =~ ^[23] ]] && return 0
    done
    (( any == 0 )) # no sites yet: nothing to prove
  }
  check "a site answers through Cloudflare" 'site_through_cloudflare'
  check "web ports closed to the world" '! ufw status | grep -qE "^(80|443)(/tcp)?( \(v6\))? +ALLOW( IN)? +Anywhere"' 
  check "caddy bound to :443 (vhosts exist)" 'has ":443" ss -ltn'
  check "caddy bound to :80 (vhosts exist)"  'has ":80" ss -ltn'
else
  ok "no sites yet: empty import glob and no listener are both correct here"
fi

(( fails )) && die "$fails check(s) failed - agent is NOT ready"

echo
printf '\033[32magent ready\033[0m  %s  \033[2m(all checks)\033[0m\n' "$(cat "$CIC/etc/host.id")"
