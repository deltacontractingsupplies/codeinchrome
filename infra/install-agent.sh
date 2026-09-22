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
	servers {
		trusted_proxies static private_ranges
	}
}

# NOTE: do not add a bare `:80` catch-all here. Caddy groups sites by listen
# address, so a `:80` block pulls the named site blocks into an HTTP-only
# server and AUTOMATIC HTTPS IS SILENTLY DISABLED for all of them - the symptom
# is Caddy never binding 443 at all. An unmatched request already fails the TLS
# handshake without revealing which customers are on this host.
import /opt/codeinchrome/caddy/sites/*.caddy
CADDY
ok "Caddyfile written (managed)"
caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 || die "Caddyfile invalid"
systemctl reload caddy 2>/dev/null || systemctl restart caddy
ok "caddy reloaded"

# ─────────────────────────────────────────────────────────────────────────────
log "service"
cat > /etc/systemd/system/cic-agent.service <<UNIT
[Unit]
Description=codeinchrome host agent
After=docker.service caddy.service
Requires=docker.service

[Service]
Type=simple
EnvironmentFile=$CIC/etc/agent.env
ExecStart=$CIC/bin/cic-agent -addr 127.0.0.1:9440
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
  check "caddy bound to :443 (vhosts exist)" 'has ":443" ss -ltn'
  check "caddy bound to :80 (vhosts exist)"  'has ":80" ss -ltn'
else
  ok "no sites yet: empty import glob and no listener are both correct here"
fi

(( fails )) && die "$fails check(s) failed - agent is NOT ready"

echo
printf '\033[32magent ready\033[0m  %s  \033[2m(all checks)\033[0m\n' "$(cat "$CIC/etc/host.id")"
