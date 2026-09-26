#!/usr/bin/env bash
#
# Bring one host from bare Hetzner image to ready-to-serve.
#
#   infra/deploy-host.sh h1 <ip>      (addresses: infra/hosts.local.env)
#
# Idempotent end to end: safe to re-run on a host that is already up, which is
# how an agent upgrade is rolled out.

set -Eeuo pipefail
cd "$(dirname "$0")/.."

name=${1:?usage: deploy-host.sh <name> <ip>}
ip=${2:?usage: deploy-host.sh <name> <ip>}

# The production box is not part of this fleet and no script here may touch it.
# shellcheck disable=SC1091
. infra/hosts.env

# A connection that dies mid-transfer (the laptop's network drops) must fail,
# not hang: on 2026-09-26 one scp waited five hours on a dead connection.
# Keepalives are answered by the server even while a long build prints
# nothing, so only a connection that is really gone is dropped (60 s).
SSH_KEEPALIVE=(-o ServerAliveInterval=15 -o ServerAliveCountMax=4)
ssh() { command ssh "${SSH_KEEPALIVE[@]}" "$@"; }
scp() { command scp "${SSH_KEEPALIVE[@]}" "$@"; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" ]] && { echo "REFUSING: $ip is the production host" >&2; exit 1; }
done

say() { printf '\n\033[1;36m[%s]\033[0m %s\n' "$name" "$*"; }
ssh_() { ssh -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

# ONE source of truth for the agent version. It used to be hardcoded here as
# well as in the ad-hoc build command, and they drifted: hosts deployed by this
# script reported 0.1.0 while running 0.2.0 code. Since the control plane
# refuses to provision onto an agent below a minimum version, a wrong label is
# not cosmetic - it makes a correctly deployed host look unusable.
version=$(cat agent/VERSION)

if [[ ${CIC_SKIP_TESTS:-0} != 1 ]]; then
  ( cd agent && go vet ./... && go test ./... >/dev/null ) || { echo "REFUSING to deploy: agent tests fail" >&2; exit 1; }
fi

say "building the agent $version"
( cd agent && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
    go build -ldflags="-s -w -X main.version=$version" -o bin/cic-agent-linux ./cmd/cic-agent )

say "hardening the host"
ssh_ 'bash -s' < infra/bootstrap.sh

say "database server"
ssh_ 'bash -s' < infra/mysql.sh

say "building the base image"
ssh_ 'mkdir -p /opt/codeinchrome/images/laravel-8.3'
scp -q infra/images/laravel-8.3/Dockerfile infra/images/laravel-8.3/cic-start "root@$ip:/opt/codeinchrome/images/laravel-8.3/"
ssh_ 'cd /opt/codeinchrome/images/laravel-8.3 && docker build -q -t codeinchrome/laravel:8.3 . >/dev/null && echo "  image built and self-verified"'
# The link scanner's page renderer (audit A15): Chromium, run by the agent
# in a throwaway container with every privilege taken away (render.go).
ssh_ 'mkdir -p /opt/codeinchrome/images/render'
scp -q infra/images/render/Dockerfile infra/images/render/shots.py "root@$ip:/opt/codeinchrome/images/render/"
ssh_ 'cd /opt/codeinchrome/images/render && docker build -q -t codeinchrome/render:1 . >/dev/null && echo "  renderer image built"'

say "installing the agent"
scp -q agent/bin/cic-agent-linux "root@$ip:/opt/codeinchrome/bin/cic-agent.new"
scp -q infra/cic-mount "root@$ip:/opt/codeinchrome/bin/cic-mount"
ssh_ 'chmod 0750 /opt/codeinchrome/bin/cic-mount' 
ssh_ 'mv /opt/codeinchrome/bin/cic-agent.new /opt/codeinchrome/bin/cic-agent && chmod 0755 /opt/codeinchrome/bin/cic-agent'
# Authenticated Origin Pulls (audit A33) are required at the edge only once
# the zone has them ON - requiring Cloudflare's certificate before it sends
# one would refuse every visitor. Unknown (no token, API down): left off,
# the side that keeps sites up; the host is re-deployed to turn it on.
aop=0
if [[ ${CIC_ORIGIN_PULLS:-auto} == off ]]; then
  echo "  origin pulls forced off (CIC_ORIGIN_PULLS=off)"
elif [[ -f .env ]]; then
  eval "$(grep -E '^CLOUDFLARE_(API_TOKEN|ZONE_ID)=' .env | sed 's/^/export /')"
  if [[ -n ${CLOUDFLARE_API_TOKEN:-} && -n ${CLOUDFLARE_ZONE_ID:-} ]] \
     && curl -fsS -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
          "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/settings/tls_client_auth" \
        | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin)["result"]["value"] == "on" else 1)' 2>/dev/null; then
    aop=1
    scp -q infra/cloudflare-origin-pull-ca.crt "root@$ip:/etc/caddy/origin/cloudflare-origin-pull.pem"
    ssh_ 'chown root:caddy /etc/caddy/origin/cloudflare-origin-pull.pem && chmod 0644 /etc/caddy/origin/cloudflare-origin-pull.pem'
  fi
fi
ssh_ "CIC_HOST_NAME=$name CIC_AOP=$aop bash -s" < infra/install-agent.sh

say "backups"
infra/setup-backups.sh "$name" "$ip"

say "ready"
