#!/usr/bin/env bash
#
# Add a customer host to the fleet: give it a fresh server, get capacity.
#
#   infra/add-host.sh <ip> [name]
#
# The server needs root SSH by key from this machine, and nothing else - a
# bare Ubuntu image. The name defaults to the next free hN. Every step is
# idempotent, so a run that stops half-way is finished by running it again.
#
#   1. refuse the forbidden hosts, a name or address already in the fleet,
#      and a server that is not a clean Linux box we can reach
#   2. deploy-host.sh: harden it, MySQL, the base image, the agent, backups
#   3. register it in infra/hosts.local.env (read through infra/hosts.env) - the ONE registry every script and the
#      control plane read (config/fleet.php derives hosts and tunnels from it)
#   4. setup-cloudflare-proxy.sh: the origin certificate and its DNS name
#   5. deploy-control.sh: the control plane learns its token, opens its
#      tunnel, and starts counting its capacity - the "N available" rises
#   6. prove it: tenant isolation on the new host, the agent answering
#      through the tunnel, and a real site created, served over HTTPS and
#      deleted again
#
# Nothing in the application is edited. Reversing it is draining the host
# (CIC_HOST_STATES="hN:draining" in hosts.env, then deploy-control.sh) and
# moving its sites off before removing its entry.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
. infra/hosts.env

ip=${1:?usage: add-host.sh <ip> [name]}
name=${2:-}

say() { printf '\n\033[1;36m[add-host]\033[0m %s\n' "$*"; }
ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

# ── 1. refuse what must never be touched, or is already here ─────────────────
[[ $ip =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "$ip is not an IPv4 address"
for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ $ip == "$forbidden" ]] && die "REFUSING: $ip is a production host outside this fleet"
done
[[ $ip == "${CIC_CONTROL_HOST##*:}" ]] && die "REFUSING: $ip is the control host; customer containers never run beside the fleet's keys"
for entry in $CIC_HOSTS; do
  [[ ${entry##*:} == "$ip" ]] && { existing=${entry%%:*}; }
done
if [[ -n ${existing:-} ]]; then
  [[ -z $name || $name == "$existing" ]] || die "$ip is already in the fleet as $existing"
  name=$existing
  say "$ip is already $name; re-running the remaining steps"
else
  if [[ -z $name ]]; then
    # The next free number, counting the control host's too (tunnel ports are 9440+N).
    n=1
    while [[ " $CIC_HOSTS $CIC_CONTROL_HOST " == *" h$n:"* ]]; do n=$((n + 1)); done
    name=h$n
  fi
  [[ $name =~ ^h[0-9]+$ ]] || die "the name must be hN (its tunnel port is 9440+N): $name"
  [[ " $CIC_HOSTS $CIC_CONTROL_HOST " == *" $name:"* ]] && die "$name is already taken"
fi

say "$name ($ip): can we reach it, and is it a clean Linux box?"
ssh -o ConnectTimeout=15 -o BatchMode=yes -o StrictHostKeyChecking=accept-new "root@$ip" \
  'test "$(uname -s)" = Linux && . /etc/os-release && echo "$PRETTY_NAME, $(nproc) CPUs, $(free -g | awk "/Mem:/{print \$2}") GB RAM, $(df -BG --output=size / | tail -1 | tr -d " ") disk"' \
  || die "cannot log in as root@$ip with a key from this machine"
ok "reachable"

# CIC_PROVE_ONLY=1 re-runs only step 6, against a host already in the fleet.
if [[ ${CIC_PROVE_ONLY:-0} != 1 ]]; then

# ── 2. the host itself ───────────────────────────────────────────────────────
say "deploying $name"
bash infra/deploy-host.sh "$name" "$ip"

# ── 3. the registry ──────────────────────────────────────────────────────────
if [[ " $CIC_HOSTS " != *" $name:$ip "* ]]; then
  new_hosts="$CIC_HOSTS $name:$ip"
  python3 - "$new_hosts" <<'PY'
import re, sys
p = "infra/hosts.local.env"  # the addresses are never in the committed hosts.env
s = open(p).read()
s2, n = re.subn(r'^CIC_HOSTS="[^"]*"', f'CIC_HOSTS="{sys.argv[1].strip()}"', s, count=1, flags=re.M)
assert n == 1, "CIC_HOSTS line not found"
open(p, "w").write(s2)
PY
  # shellcheck disable=SC1091
  . infra/hosts.env
  ok "$name registered in infra/hosts.local.env"
fi

# ── 4. its name and certificate ──────────────────────────────────────────────
say "Cloudflare origin certificate and DNS"
bash infra/setup-cloudflare-proxy.sh

# ── 5. the control plane ─────────────────────────────────────────────────────
say "control plane: token, tunnel, capacity"
bash infra/deploy-control.sh

fi

# ── 6. prove it ──────────────────────────────────────────────────────────────
say "proving $name"
control_ip=${CIC_CONTROL_HOST##*:}
artisan() { ssh -o ConnectTimeout=20 "root@$control_ip" "cd /srv/control && sudo -u codeinchrome php8.4 artisan $*"; }
artisan fleet:monitor >/dev/null 2>&1 || true
artisan fleet:status | sed 's/^/  /'

# Two real sites on the new host, owned by two probe accounts: one served over
# HTTPS as a visitor sees it, and the pair used to prove neither tenant can
# reach the other. Free accounts with no trial clock own them, so nothing is
# reserved and nothing expires.
stamp=$(date +%s | tail -c 6)
a="probe-$name-a$stamp" b="probe-$name-b$stamp"
cleanup() { artisan "site:reap $a" >/dev/null 2>&1 || true; artisan "site:reap $b" >/dev/null 2>&1 || true; }
trap cleanup EXIT
# Two accounts, one site each: the isolation proof is between two tenants.
for s in "$a" "$b"; do
  out=$(artisan "site:provision --create --host=$name probe-${s: -6:1}@codeinchrome.test $s" 2>&1) || die "could not create a probe site on $name: $out"
done
code=0
for _ in $(seq 1 30); do
  # -k: straight to the origin, whose certificate only Cloudflare trusts.
  code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$a.codeinchrome.com:443:$ip" "https://$a.codeinchrome.com/" || true)
  [[ $code == 200 ]] && break
  sleep 2
done
[[ $code == 200 ]] || die "the probe site on $name answered $code, not 200"
ok "a real site on $name is served over HTTPS"
ssh "root@$ip" "CIC_A=$a CIC_B=$b bash -s" < infra/verify-isolation.sh | sed 's/^/  /' \
  || die "isolation check failed on $name: it must not take customers"
ok "tenant isolation proved on $name"
cleanup
trap - EXIT

printf '\n\033[32m%s (%s) is in the fleet and taking sites.\033[0m\n' "$name" "$ip"
