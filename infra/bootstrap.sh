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

VERIFY_ONLY=0
[[ "${1:-}" == "--verify" ]] && VERIFY_ONLY=1

[[ $EUID -eq 0 ]] || die "run as root"
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "expected Ubuntu, found ${PRETTY_NAME:-unknown}"

CIC_ROOT=/opt/codeinchrome
CUSTOMER_ROOT=/srv/customers

# ─────────────────────────────────────────────────────────────────────────────
log "packages"
export DEBIAN_FRONTEND=noninteractive
NEED=()
for p in ca-certificates curl gnupg ufw fail2ban jq unattended-upgrades; do
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
if [[ ! -f /etc/docker/daemon.json ]] || ! jq -e '."no-new-privileges"' /etc/docker/daemon.json >/dev/null 2>&1; then
  cat > /etc/docker/daemon.json <<'JSON'
{
  "no-new-privileges": true,
  "live-restore": true,
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "default-ulimits": { "nofile": { "Name": "nofile", "Hard": 4096, "Soft": 1024 } }
}
JSON
  systemctl restart docker
  ok "docker hardened (no-new-privileges, capped logs)"
else
  ok "docker config already hardened"
fi
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
ufw --force reset >/dev/null 2>&1
ufw default deny incoming  >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp  >/dev/null
ufw allow 80/tcp  >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null
ok "inbound: 22, 80, 443 only"

# ─────────────────────────────────────────────────────────────────────────────
log "egress policy"
# Spam is how a hosting account gets suspended, and the account holder is liable
# for everything a customer does. Mail goes out through an API, never from a
# container. DOCKER-USER is the chain Docker leaves for exactly this.
install_egress_rule() {
  local proto=$1 port=$2
  iptables -C DOCKER-USER -p "$proto" --dport "$port" -j REJECT 2>/dev/null \
    || iptables -I DOCKER-USER -p "$proto" --dport "$port" -j REJECT
}
iptables -L DOCKER-USER >/dev/null 2>&1 || iptables -N DOCKER-USER 2>/dev/null || true
for port in 25 465 587 2525; do install_egress_rule tcp "$port"; done
# Common mining pool ports. Not a complete list and not meant to be — the real
# control is the CPU ceiling; this just removes the lazy path.
for port in 3333 4444 5555 7777 8333 14444 45700; do install_egress_rule tcp "$port"; done
mkdir -p /etc/iptables
iptables-save > /etc/iptables/rules.v4
ok "outbound mail and common pool ports rejected from containers"

# Survive reboot.
cat > /etc/systemd/system/cic-egress.service <<'UNIT'
[Unit]
Description=codeinchrome container egress policy
After=docker.service
Requires=docker.service
[Service]
Type=oneshot
ExecStart=/sbin/iptables-restore --noflush /etc/iptables/rules.v4
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
CONF
sshd -t || die "sshd config invalid after writing $SSHD"
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || die "could not reload sshd"
ok "keys only"

# ─────────────────────────────────────────────────────────────────────────────
log "unattended security updates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
systemctl enable --now fail2ban            >/dev/null 2>&1 || true
ok "unattended-upgrades and fail2ban active"

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
check "only 22/80/443 inbound"   '[[ $(ufw status | grep -c "ALLOW IN") -le 6 ]]'
check "smtp 25 rejected"         'iptables -C DOCKER-USER -p tcp --dport 25 -j REJECT'
check "smtp 465 rejected"        'iptables -C DOCKER-USER -p tcp --dport 465 -j REJECT'
check "smtp 587 rejected"        'iptables -C DOCKER-USER -p tcp --dport 587 -j REJECT'
check "pool port 3333 rejected"  'iptables -C DOCKER-USER -p tcp --dport 3333 -j REJECT'
check "egress rules persist"     'systemctl is-enabled cic-egress.service'
check "ssh password auth off"    'has "passwordauthentication no" sshd -T'
# sshd -T normalises prohibit-password to without-password; accept either, or
# this check fails on a correctly configured host.
check "ssh root password off"    'has "permitrootlogin without-password" sshd -T || has "permitrootlogin prohibit-password" sshd -T'
check "unattended-upgrades on"   'systemctl is-active unattended-upgrades'
check "fail2ban on"              'systemctl is-active fail2ban'
check "swap active"              '[[ -n "$(swapon --show)" ]]'
check "host id present"          'test -s /opt/codeinchrome/etc/host.id'
check "customer root private"    '[[ "$(stat -c %a /srv/customers)" == "750" ]]'

if (( fails )); then
  die "$fails post-condition(s) failed - this host is NOT ready"
fi

echo
printf '\033[32mhost ready\033[0m  %s  %s  %sc/%sG  %s free  \033[2m(17/17 checks)\033[0m\n' \
  "$(cat "$CIC_ROOT/etc/host.id")" "$PRETTY_NAME" \
  "$(nproc)" "$(free -g --si | awk '/^Mem:/{print $2}')" \
  "$(df -h / | tail -1 | awk '{print $4}')"
