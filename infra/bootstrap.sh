#!/usr/bin/env bash
#
# Turn a bare Ubuntu 22.04/24.04 box into a codeinchrome host.
#
#   ssh root@HOST 'bash -s' < infra/bootstrap.sh
#
# Idempotent: safe to re-run. Every step checks before it acts, so this doubles
# as a repair tool and as the thing CI runs to prove a host is correctly set up.
#
# It installs nothing it does not need. The threat model that shapes this file is
# not a clever attacker first — it is an abusive customer getting the whole host
# account suspended, which takes every other customer offline with them.

set -Eeuo pipefail

log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m  ok\033[0m %s\n' "$*"; }
warn() { printf '\033[33m  !!\033[0m %s\n' "$*"; }
die()  { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

# --verify runs ONLY the post-condition checks and changes nothing. It used to
# be accepted and silently ignored, so anyone running it expecting a read-only
# check got a full bootstrap - which restarts docker and rewrites configs on a
# live host. shellcheck found the dead variable; the flag was the bug.
VERIFY_ONLY=0
[[ "${1:-}" == "--verify" ]] && VERIFY_ONLY=1

# Preconditions and constants stay OUTSIDE the mutating block: both paths need
# them, and the verification summary reads CIC_ROOT and PRETTY_NAME. Putting
# them inside meant `--verify` died on "unbound variable" after printing every
# check correctly.
[[ $EUID -eq 0 ]] || die "run as root"
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "expected Ubuntu, found ${PRETTY_NAME:-unknown}"

CIC_ROOT=/opt/codeinchrome
CUSTOMER_ROOT=/srv/customers

if [[ $VERIFY_ONLY -eq 0 ]]; then

# ─────────────────────────────────────────────────────────────────────────────
log "packages"
export DEBIAN_FRONTEND=noninteractive
NEED=()
for p in ca-certificates curl gnupg ufw fail2ban jq unattended-upgrades bzip2 conntrack; do
  dpkg -s "$p" >/dev/null 2>&1 || NEED+=("$p")
done
if ((${#NEED[@]})); then
  apt-get update -qq
  apt-get install -y -qq "${NEED[@]}" >/dev/null
  ok "installed: ${NEED[*]}"
else
  ok "base packages present"
fi

# ─────────────────────────────────────────────────────────────────────────────
log "docker"
if ! command -v docker >/dev/null; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin >/dev/null
  ok "docker installed"
else
  ok "docker $(docker --version | awk '{print $3}' | tr -d ,)"
fi

# Containers must never be able to gain privileges, and the daemon must not
# hand out the host's whole log disk to one noisy customer.
mkdir -p /etc/docker
if [[ ! -f /etc/docker/daemon.json ]] || ! jq -e '.icc == false' /etc/docker/daemon.json >/dev/null 2>&1; then
  cat > /etc/docker/daemon.json <<'JSON'
{
  "no-new-privileges": true,
  "live-restore": true,
  "icc": false,
  "default-address-pools": [ { "base": "172.20.0.0/14", "size": 28 } ],
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "default-ulimits": { "nofile": { "Name": "nofile", "Hard": 4096, "Soft": 1024 } }
}
JSON
  systemctl restart docker
  ok "docker hardened (icc=false, no-new-privileges, capped logs)"
else
  ok "docker config already hardened"
fi

# `icc: false` above is the reason this matters. It was added after a measured
# failure, not on principle: with every site on the shared default bridge, one
# tenant read 70,403 bytes of another tenant's live app straight off
# 172.17.0.2:8080, bypassing Caddy entirely. Nothing in this architecture needs
# container-to-container traffic - Caddy reaches each container through a port
# published on the host's loopback - so the correct amount of it is none.
#
# The address pool is sized for the per-site networks the agent creates:
# 172.20.0.0/14 in /28s is 16,384 networks, against a few hundred sites a host.
systemctl enable --now docker >/dev/null 2>&1 || true

# ─────────────────────────────────────────────────────────────────────────────
log "caddy"
if ! command -v caddy >/dev/null; then
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
    | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
    > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -qq
  apt-get install -y -qq caddy >/dev/null
  ok "caddy installed"
else
  ok "caddy $(caddy version | head -1 | awk '{print $1}')"
fi

# ─────────────────────────────────────────────────────────────────────────────
log "layout"
mkdir -p "$CIC_ROOT"/{bin,etc,var,caddy/sites} "$CUSTOMER_ROOT"
chmod 0750 "$CIC_ROOT" "$CUSTOMER_ROOT"
# Caddy runs as its own unprivileged user and must TRAVERSE $CIC_ROOT to reach
# the per-site vhosts it imports. With 0750 root:root it silently could not:
# Caddy logged `No files matching import glob pattern`, which is a WARNING, so
# `caddy validate` passed, `systemctl reload` passed, every check reported
# success - and Caddy served nothing at all, binding neither 80 nor 443.
# Group-owning the root by caddy grants exactly that traversal and nothing else.
chgrp caddy "$CIC_ROOT" 2>/dev/null || true
chmod 0750 "$CIC_ROOT"/caddy "$CIC_ROOT"/caddy/sites
chgrp -R caddy "$CIC_ROOT"/caddy 2>/dev/null || true
# The agent token lives here. Keep it unreadable to the caddy user even though
# the directory above it is now traversable.
chmod 0700 "$CIC_ROOT"/etc
if [[ ! -f "$CIC_ROOT/etc/host.id" ]]; then
  # Stable identity for this host, used by the control plane.
  printf 'host_%s\n' "$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "$CIC_ROOT/etc/host.id"
fi
chmod 0600 "$CIC_ROOT/etc/host.id"
ok "$(cat "$CIC_ROOT/etc/host.id") at $CIC_ROOT"

# ─────────────────────────────────────────────────────────────────────────────
log "firewall"
# Nothing inbound but SSH and the proxy. Everything a customer serves goes
# through Caddy; opening a port would bypass the edge.
#
# Converged, NEVER reset: this runs on every deploy, and `ufw --force reset`
# dropped every rule - the sites' route to MySQL (mysql.sh adds it) included -
# and switched filtering off until the later steps put them back. Found
# 2026-09-24: live sites were refused their database mid-deploy. Each call
# below changes nothing when the rule is already there.
if ! ufw status verbose | grep -q 'Default: deny (incoming), allow (outgoing)'; then
  ufw default deny incoming  >/dev/null
  ufw default allow outgoing >/dev/null
fi
# SSH only, to the world. 80 and 443 are opened to Cloudflare's ranges ONLY,
# by install-agent.sh (which fetches them): the sites are served through
# Cloudflare, so a request straight to this host's IP is someone bypassing it.
ufw allow 22/tcp >/dev/null
ufw status | grep -q '^Status: active' || ufw --force enable >/dev/null
# Anything else open inbound is drift: said, not silently removed.
extra=$(ufw status | awk '/ALLOW/ && $1 !~ /^(22\/tcp|80,443\/tcp)/ && $0 !~ /3306/' || true)
[[ -z $extra ]] || warn "unexpected inbound rules (not removed): $extra"
ok "inbound: 22 to the world; 80 and 443 from Cloudflare only (install-agent.sh)"

# ─────────────────────────────────────────────────────────────────────────────
log "egress policy"
# Spam is how a hosting account gets suspended, and the account holder is liable
# for everything a customer does. Mail goes out through an API, never from a
# container. DOCKER-USER is the chain Docker leaves for exactly this.
#
# The rules are applied by a script at every boot, NOT restored from an
# iptables-save snapshot. The first version saved the ENTIRE ruleset and
# restored it after UFW at boot - and restoring a declared chain flushes it,
# so every firewall change made after bootstrap was silently reverted on the
# next reboot, along with any Docker rules added since. It surfaced when a
# rebooted host lost the rule letting containers reach MySQL. This script
# touches DOCKER-USER and nothing else.
mkdir -p /opt/codeinchrome/bin
cat > /opt/codeinchrome/bin/cic-egress <<'EGRESS'
#!/usr/bin/env bash
# What a customer's container may send out (2026-09-25, after a security
# audit): Hetzner suspends a server - and can end the account - for spam,
# port scans, floods and mining, and a customer's PHP can attempt all of them.
#
# Everything lives in one chain of our own, CIC-EGRESS, rebuilt whole on every
# run and entered from DOCKER-USER for packets FROM a container bridge only.
# (The first version put bare --dport rules in DOCKER-USER, matching both
# directions: a reply to a container whose ephemeral port happened to be 45700
# was rejected.)
#
#   - replies to connections already allowed pass first
#   - private, shared, reserved and link-local destinations (the metadata
#     service, Hetzner's private networks, other tenants' addresses): refused
#   - mail (25 465 587 2525), mining-pool ports, and ports scanned for brute
#     force (telnet, SMB, MSSQL, RDP, VNC): refused
#   - UDP: DNS and QUIC only, rate-limited; everything else refused - a Laravel
#     app has no other use for it, and UDP is what floods are made of
#   - ICMP: a few a second
#   - per container: at most 256 open TCP connections (one site cannot fill
#     the host's connection table), 30 new ones a second on 80/443 (burst 120)
#     and 2 a second to any other port (burst 30) - scans are bursts of new
#     connections; normal apps reuse a handful
#   - every refusal is logged ("cic-egress: " in the kernel log, rate-limited),
#     with the container's address, for tracing an abuse report to a site
#
# Idempotent; re-applied at boot by cic-egress.service.
set -Eeuo pipefail

chain() { iptables -N "$1" 2>/dev/null || iptables -F "$1"; }
chain CIC-EGRESS
chain CIC-REJECT
iptables -L DOCKER-USER >/dev/null 2>&1 || iptables -N DOCKER-USER

# The refusal: logged (at most 6 a minute per container), then rejected, so
# the app gets a clear "connection refused" instead of a hang.
iptables -A CIC-REJECT -m hashlimit --hashlimit-upto 6/min --hashlimit-burst 6 --hashlimit-mode srcip \
  --hashlimit-name cic-log -j LOG --log-prefix "cic-egress: " --log-level warning
iptables -A CIC-REJECT -p tcp -j REJECT --reject-with tcp-reset
iptables -A CIC-REJECT -j REJECT --reject-with icmp-admin-prohibited

e() { iptables -A CIC-EGRESS "$@"; }
# Every container sends to the internet at most ~100 Mbit/s (12 MB/s, 24 MB
# burst), before anything else - established connections included (audit
# A20). Measured on h4, 2026-09-26: a 100 MB upload went 720-835 Mbit/s
# without it and 108-157 with it (the burst lifts short runs); a site's
# answers to its visitors never cross this chain (they reach Caddy through
# the host: 18-22 Gbit/s either way). A PHP app needs a fraction of this;
# a flood from one site no longer takes the host's whole line.
e -m hashlimit --hashlimit-above 12mb/s --hashlimit-burst 24mb --hashlimit-mode srcip --hashlimit-name cic-bytes -j DROP
e -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
for net in 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24 \
           192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24 224.0.0.0/4 240.0.0.0/4; do
  e -d "$net" -j CIC-REJECT
done
e -p tcp -m multiport --dports 25,465,587,2525,3333,4444,5555,7777,8333,14444,45700 -j CIC-REJECT
e -p udp -m multiport --dports 25,465,587,2525,3333,4444,5555,7777,8333,14444,45700 -j CIC-REJECT
e -p tcp -m multiport --dports 23,445,1433,3389,5900 -j CIC-REJECT
# DNS goes through Docker's resolver, which forwards from the HOST's side
# (measured 2026-09-26 on h4: a container's lookup crossed this chain 0
# times, a direct "nslookup x 8.8.8.8" twice). So a container talking DNS to
# an outside resolver itself - plain (53), over TLS (853), or over HTTPS to
# the public resolvers' addresses, which serve nothing else - is tunnelling
# or hiding lookups, not resolving names: refused (audit A21).
#
# Site bridges only (br-*): every site network is one, with Docker's
# resolver. The default bridge (docker0) is where image builds run, and it
# has NO embedded resolver - its containers ask the host's upstream servers
# themselves. Refusing that broke apt in a build (found 2026-09-26 on h4,
# the day it shipped, before the weekly image rebuild ran).
e -i br-+ -p udp --dport 53 -j CIC-REJECT
e -i br-+ -p tcp -m multiport --dports 53,853 -j CIC-REJECT
# What is left of UDP 53 is the default bridge's (builds): allowed, bounded,
# as before - without this it fell through to "no other UDP" below (the
# first fix restored nothing; its deploy check caught it).
e -p udp --dport 53 -m hashlimit --hashlimit-upto 50/sec --hashlimit-burst 100 --hashlimit-mode srcip --hashlimit-name cic-dns -j RETURN
for doh in 1.1.1.1 1.0.0.1 8.8.8.8 8.8.4.4 9.9.9.9 149.112.112.112 208.67.222.222 208.67.220.220 94.140.14.14 94.140.15.15; do
  e -d "$doh" -j CIC-REJECT
done
e -p udp --dport 443 -m hashlimit --hashlimit-upto 20/sec --hashlimit-burst 40  --hashlimit-mode srcip --hashlimit-name cic-quic -j RETURN
e -p udp -j CIC-REJECT
e -p icmp -m hashlimit --hashlimit-upto 5/sec --hashlimit-burst 10 --hashlimit-mode srcip --hashlimit-name cic-icmp -j RETURN
e -p icmp -j CIC-REJECT
e -p tcp --syn -m connlimit --connlimit-above 256 --connlimit-mask 32 --connlimit-saddr -j CIC-REJECT
e -p tcp --syn -m multiport --dports 80,443 -m hashlimit --hashlimit-above 30/sec --hashlimit-burst 120 \
  --hashlimit-mode srcip --hashlimit-name cic-syn-web -j CIC-REJECT
# Log-in ports, per destination: an SSH or FTP brute force is one target and
# one port, which neither the distinct-host watch nor the rate below noticed
# (the second security audit, 2026-09-25). An app's own SFTP storage opens a
# few connections a minute, not this many.
e -p tcp --syn -m multiport --dports 21,22,2222 -m hashlimit --hashlimit-above 6/min --hashlimit-burst 6 \
  --hashlimit-mode srcip,dstip,dstport --hashlimit-name cic-syn-login -j CIC-REJECT
e -p tcp --syn -m multiport ! --dports 80,443 -m hashlimit --hashlimit-above 2/sec --hashlimit-burst 30 \
  --hashlimit-mode srcip --hashlimit-name cic-syn-other -j CIC-REJECT
e -j RETURN

# Entered for traffic FROM a container bridge, first thing in DOCKER-USER.
for dev in 'br-+' docker0; do
  iptables -C DOCKER-USER -i "$dev" -j CIC-EGRESS 2>/dev/null || iptables -I DOCKER-USER -i "$dev" -j CIC-EGRESS
done
# The rules of the first version, if this host still has them.
while read -r rule; do
  # shellcheck disable=SC2086
  iptables -D DOCKER-USER ${rule#-A DOCKER-USER } 2>/dev/null || true
done < <(iptables -S DOCKER-USER | grep -E -- '^-A DOCKER-USER (-p tcp -m tcp --dport [0-9]+|-d 169\.254\.0\.0/16) -j REJECT' || true)

# IPv6: the site networks have none today; if one ever gets it, nothing leaves.
if ip6tables -L DOCKER-USER >/dev/null 2>&1; then
  for dev in 'br-+' docker0; do
    ip6tables -C DOCKER-USER -i "$dev" -j REJECT 2>/dev/null || ip6tables -I DOCKER-USER -i "$dev" -j REJECT
  done
fi

# A container reaches the host on its bridge gateway (that is how it gets to
# MySQL): not to the host's SSH, which is for the operator (the audit found it
# reachable - ufw allows 22 from anywhere, and a bridge is "anywhere").
for dev in 'br-+' docker0; do
  iptables -C INPUT -i "$dev" -p tcp --dport 22 -j REJECT 2>/dev/null || iptables -I INPUT -i "$dev" -p tcp --dport 22 -j REJECT
done

# The host itself sends no mail (only the control host does): a process that
# reaches the host's own network - cic-mysql runs with it - cannot either.
for port in 25 465 587 2525; do
  iptables -C OUTPUT -p tcp --dport "$port" -j REJECT 2>/dev/null || iptables -A OUTPUT -p tcp --dport "$port" -j REJECT
done
EGRESS
chmod 0750 /opt/codeinchrome/bin/cic-egress
/opt/codeinchrome/bin/cic-egress
# The old snapshot must not linger where something might restore it.
rm -f /etc/iptables/rules.v4
ok "container egress: private ranges, mail and pool ports, and direct DNS refused; bandwidth, UDP, new-connection rate and open connections limited"

# Survive reboot: re-applied after docker creates its chains.
cat > /etc/systemd/system/cic-egress.service <<'UNIT'
[Unit]
Description=codeinchrome container egress policy
After=docker.service ufw.service
Requires=docker.service
[Service]
Type=oneshot
ExecStart=/opt/codeinchrome/bin/cic-egress
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable cic-egress.service >/dev/null 2>&1 || true

# ─────────────────────────────────────────────────────────────────────────────
log "ssh hardening"
SSHD=/etc/ssh/sshd_config.d/10-codeinchrome.conf
cat > "$SSHD" <<'CONF'
PasswordAuthentication no
PermitRootLogin prohibit-password
KbdInteractiveAuthentication no
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
AllowAgentForwarding no
MaxStartups 10:30:60
CONF
sshd -t || die "sshd config invalid after writing $SSHD"
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || die "could not reload sshd"
ok "keys only"

# ─────────────────────────────────────────────────────────────────────────────
log "kernel hardening and audit"
# Kernel settings Ubuntu leaves at their defaults (the second security audit,
# 2026-09-25). Not ip_forward: Docker needs it. Not log_martians: with the
# Docker bridges it fills the kernel log.
cat > /etc/sysctl.d/60-cic.conf <<'CONF'
net.core.bpf_jit_harden = 2
kernel.kexec_load_disabled = 1
dev.tty.ldisc_autoload = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
kernel.sysrq = 0
fs.suid_dumpable = 0
CONF
sysctl -q --system >/dev/null
# auditd: every write to the configuration that controls this host is
# recorded (who, when, what), in a bounded log.
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq auditd >/dev/null
sed -ri 's/^max_log_file *=.*/max_log_file = 50/; s/^num_logs *=.*/num_logs = 4/' /etc/audit/auditd.conf
cat > /etc/audit/rules.d/cic.rules <<'CONF'
-w /opt/codeinchrome/etc -p wa -k cic-config
-w /etc/ssh -p wa -k ssh-config
-w /etc/ufw -p wa -k firewall
-w /etc/docker -p wa -k docker-config
-w /etc/caddy -p wa -k caddy-config
-w /etc/sudoers -p wa -k sudoers
-w /etc/sudoers.d -p wa -k sudoers
-w /root/.ssh -p wa -k root-ssh
CONF
systemctl enable --now auditd >/dev/null 2>&1
augenrules --load >/dev/null 2>&1 || true
ok "sysctl hardened; auditd watching the host configuration"

# ─────────────────────────────────────────────────────────────────────────────
log "malware scanning (ClamAV)"
# Customers upload files and run code the platform did not write; the owner
# decided (2026-09-25) that malware or encrypted PHP on a free site bans the
# account. clamd keeps the signatures in memory (~1 GB) so the agent can scan
# an upload the moment it lands. The agent sends the bytes (clamdscan
# --stream): --fdpass fails from inside the agent's private mount namespace
# (every file "Not a regular file" - found on the first real scan).
# freshclam updates the signatures several times a day.
if ! dpkg -s clamav-daemon >/dev/null 2>&1; then
  apt-get update -qq
  apt-get install -y -qq clamav clamav-daemon clamav-freshclam >/dev/null
fi
# clamd will not start without a signature database: fetch it once, now.
if ! ls /var/lib/clamav/main.c[lv]d /var/lib/clamav/main.cvd >/dev/null 2>&1; then
  systemctl stop clamav-freshclam 2>/dev/null || true
  freshclam --quiet || warn "freshclam could not fetch signatures yet; the service will retry"
fi
# Uploads may be 32 MB (the agent's MaxUploadSize): the defaults stop at 25 MB,
# and a file clamd will not take is an upload refused as unscanned.
conf=/etc/clamav/clamd.conf
restart_clamd=0
# Alert*: an archive or document ClamAV cannot open (password-protected, or
# past these limits) is reported as Heuristics.Encrypted/Limits.Exceeded
# instead of "OK" - an encrypted zip holding EICAR scanned clean (the second
# security audit, 2026-09-25). The agent refuses such uploads and sends
# them to review; it never bans for them.
for kv in "StreamMaxLength 100M" "MaxFileSize 100M" "MaxScanSize 400M" \
          "AlertEncryptedArchive yes" "AlertEncryptedDoc yes" "AlertExceedsMax yes"; do
  key=${kv%% *}
  if ! grep -qx "$kv" "$conf"; then
    sed -i "/^$key /d" "$conf"; echo "$kv" >> "$conf"; restart_clamd=1
  fi
done
systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
systemctl enable --now clamav-daemon >/dev/null 2>&1 || true
(( restart_clamd )) && systemctl restart clamav-daemon
for _ in $(seq 1 60); do [[ -S /run/clamav/clamd.ctl ]] && break; sleep 2; done
ok "clamd and freshclam enabled"

# ─────────────────────────────────────────────────────────────────────────────
log "unattended security updates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF
# Docker (dockerd, containerd, runc) and Caddy come from their own
# repositories, which unattended-upgrades left alone: their security fixes
# never installed (the second security audit, 2026-09-25). Docker restarts
# with live-restore on, so the sites keep running through it.
cat > /etc/apt/apt.conf.d/51cic-origins <<'CONF'
Unattended-Upgrade::Origins-Pattern {
        "origin=Docker,label=Docker CE";
        "origin=cloudsmith/caddy/stable";
};
CONF
# fail2ban: SSH is open to the world and gets thousands of attempts a day
# per host. Bans grow for repeat offenders, up to a week, and anyone banned
# again within a day is banned for a week (recidive).
cat > /etc/fail2ban/jail.d/cic.conf <<'CONF'
[DEFAULT]
bantime.increment = true
bantime.maxtime = 1w

[sshd]
mode = aggressive

[recidive]
enabled = true
bantime = 1w
findtime = 1d
CONF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
systemctl enable --now fail2ban            >/dev/null 2>&1 || true
systemctl restart fail2ban
ok "unattended-upgrades (incl. Docker and Caddy) and fail2ban (with recidive) active"

# ─────────────────────────────────────────────────────────────────────────────
log "swap"
# Builds spike. Without swap a composer install can OOM-kill a neighbour's
# container, which looks like a platform fault and is not one.
if ! swapon --show | grep -q .; then
  [[ -f /swapfile ]] || fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null 2>&1 || die "mkswap failed on /swapfile"
  swapon /swapfile        || die "swapon failed on /swapfile"
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  sysctl -qw vm.swappiness=10 >/dev/null
  grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
fi
# Claim it only after observing it. The first version printed "2G swap added"
# while mkswap had failed on an unsupported flag — a false success in the one
# file whose whole job is telling you a host is correctly set up.
swapon --show | grep -q . || die "swap still not active"
ok "swap active: $(free -h --si | awk '/^Swap:/{print $2}')"


# ─────────────────────────────────────────────────────────────────────────────
# Post-conditions. Every one is observed, never inferred from "the step ran".
else
  log "verify only: changing nothing"
fi

log "verifying"
fails=0
# `cmd | grep -q` is unsafe under `set -o pipefail`: grep -q exits on the first
# match, the producer is killed by SIGPIPE, and the pipeline reports 141. That
# silently converts a PASSING check into a failure, depending on output size and
# timing. A verifier that cries wolf gets ignored, so read the output first.
has()  { local pat=$1; shift; local out; out=$("$@" 2>/dev/null) || true; [[ "$out" == *"$pat"* ]]; }
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else warn "$1"; fails=$((fails+1)); fi; }

check "docker running"           'docker info'
check "docker no-new-privileges" 'jq -e ".\"no-new-privileges\" == true" /etc/docker/daemon.json'
check "caddy installed"          'command -v caddy'
check "ufw active"               'has "Status: active" ufw status'
# That nothing but SSH is open to the world is checked by install-agent.sh,
# AFTER it has put Cloudflare's rules in and taken the open ones out: checked
# here, it failed on every host being moved over, before the move.
check "no iptables snapshot to restore" '[[ ! -e /etc/iptables/rules.v4 ]]'
# The egress policy, rule by rule (cic-egress). The old checks looked only for
# four port rules; these cover what the policy is for.
E='iptables -C CIC-EGRESS'
check "containers enter the egress policy" 'iptables -C DOCKER-USER -i br-+ -j CIC-EGRESS'
check "mail and pool ports refused"  "$E -p tcp -m multiport --dports 25,465,587,2525,3333,4444,5555,7777,8333,14444,45700 -j CIC-REJECT"
check "private ranges refused"       "$E -d 10.0.0.0/8 -j CIC-REJECT && $E -d 172.16.0.0/12 -j CIC-REJECT && $E -d 192.168.0.0/16 -j CIC-REJECT"
check "metadata service refused"     "$E -d 169.254.0.0/16 -j CIC-REJECT"
check "other UDP refused"            "$E -p udp -j CIC-REJECT"
check "open connections capped"      "$E -p tcp --syn -m connlimit --connlimit-above 256 --connlimit-mask 32 --connlimit-saddr -j CIC-REJECT"
check "refusals are logged"          'iptables -S CIC-REJECT | grep -q -- "--log-prefix \"cic-egress: \""'
check "the host sends no mail"       'iptables -C OUTPUT -p tcp --dport 25 -j REJECT'
check "containers cannot reach the host's SSH" 'iptables -C INPUT -i br-+ -p tcp --dport 22 -j REJECT'
check "egress rules persist"     'systemctl is-enabled cic-egress.service'
# Not only "running": it must FIND something. EICAR is the industry's harmless
# test file, recognised by every scanner.
eicar_check() {
  local f; f=$(mktemp /tmp/cic-eicar.XXXX)
  printf '%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > "$f"
  local out; out=$(clamdscan --stream --no-summary "$f" 2>&1 || true); rm -f "$f"
  [[ $out == *"Eicar"*"FOUND"* ]]
}
check "clamd detects malware (EICAR)" 'eicar_check'
check "ssh password auth off"    'has "passwordauthentication no" sshd -T'
# sshd -T normalises prohibit-password to without-password; accept either, or
# this check fails on a correctly configured host.
check "ssh root password off"    'has "permitrootlogin without-password" sshd -T || has "permitrootlogin prohibit-password" sshd -T'
check "unattended-upgrades on"   'systemctl is-active unattended-upgrades'
check "fail2ban on"              'systemctl is-active fail2ban'
check "fail2ban recidive jail"   'fail2ban-client status recidive'
check "kernel: sysrq off, kexec off, BPF JIT hardened" '[[ "$(sysctl -n kernel.sysrq)" == 0 && "$(sysctl -n kernel.kexec_load_disabled)" == 1 && "$(sysctl -n net.core.bpf_jit_harden)" == 2 ]]'
check "auditd watching the configuration" 'auditctl -l | grep -q cic-config'
check "ssh: no X11 or agent forwarding" 'has "x11forwarding no" sshd -T && has "allowagentforwarding no" sshd -T'
check "Docker and Caddy updated automatically" 'apt-config dump | grep -q "origin=Docker,label=Docker CE"'
check "swap active"              '[[ -n "$(swapon --show)" ]]'
check "host id present"          'test -s /opt/codeinchrome/etc/host.id'
check "customer root private"    '[[ "$(stat -c %a /srv/customers)" == "750" ]]'
check "container upload capped (~100 Mbit/s each)" 'iptables -S CIC-EGRESS | head -2 | grep -q "hashlimit-name cic-bytes"'
# DNS (audit A21), proven with a throwaway container on a throwaway network:
# names still resolve through Docker's resolver, and a direct query to an
# outside resolver is refused. The test image is the site image already on
# the host, so nothing is pulled; everything made here is removed.
dns_probe() {
  local net=cic-dnsprobe out
  docker network rm "$net" >/dev/null 2>&1 || true
  docker network create "$net" >/dev/null || return 1
  out=$(docker run --rm --network "$net" --entrypoint php codeinchrome/laravel:8.3 -r '
    $ok = gethostbyname("example.com") !== "example.com";
    $s = @fsockopen("udp://8.8.8.8", 53, $e, $m, 3); $direct = false;
    if ($s) { stream_set_timeout($s, 3); fwrite($s, hex2bin("abcd01000001000000000000076578616d706c6503636f6d0000010001")); $direct = strlen((string) fread($s, 512)) > 0; }
    echo ($ok ? "resolves" : "no-resolve"), " ", ($direct ? "direct-answered" : "direct-refused");' 2>/dev/null)
  docker network rm "$net" >/dev/null 2>&1 || true
  echo "$out"
}
if docker image inspect codeinchrome/laravel:8.3 >/dev/null 2>&1; then
  # shellcheck disable=SC2034 # read by the eval'd checks below
  dns_result=$(dns_probe)
  check "containers resolve names through Docker's resolver" '[[ $dns_result == resolves* ]]'
  check "containers cannot query an outside resolver directly" '[[ $dns_result == *direct-refused ]]'
  # And an image build (the default bridge, no embedded resolver) still
  # resolves names: the weekly site-image rebuild depends on it.
  check "image builds resolve names (default bridge)" 'docker run --rm --network bridge --entrypoint php codeinchrome/laravel:8.3 -r "exit(gethostbyname(\"deb.debian.org\") === \"deb.debian.org\" ? 1 : 0);"'
else
  ok "DNS egress not proven yet: the site image is built later in a first deploy"
fi

if (( fails )); then
  die "$fails post-condition(s) failed - this host is NOT ready"
fi

echo
printf '\033[32mhost ready\033[0m  %s  %s  %sc/%sG  %s free  \033[2m(all checks)\033[0m\n' \
  "$(cat "$CIC_ROOT/etc/host.id")" "$PRETTY_NAME" \
  "$(nproc)" "$(free -g --si | awk '/^Mem:/{print $2}')" \
  "$(df -h / | tail -1 | awk '{print $4}')"
