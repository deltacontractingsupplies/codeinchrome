#!/usr/bin/env bash
#
# Bring one host from bare Hetzner image to ready-to-serve.
#
#   infra/deploy-host.sh h2 203.0.113.104
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
for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" ]] && { echo "REFUSING: $ip is the production host" >&2; exit 1; }
done

say() { printf '\n\033[1;36m[%s]\033[0m %s\n' "$name" "$*"; }
ssh_() { ssh -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

say "building the agent"
( cd agent && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
    go build -ldflags="-s -w -X main.version=0.1.0" -o bin/cic-agent-linux ./cmd/cic-agent )

say "hardening the host"
ssh_ 'bash -s' < infra/bootstrap.sh

say "building the base image"
ssh_ 'mkdir -p /opt/codeinchrome/images/laravel-8.3'
scp -q infra/images/laravel-8.3/Dockerfile "root@$ip:/opt/codeinchrome/images/laravel-8.3/Dockerfile"
ssh_ 'cd /opt/codeinchrome/images/laravel-8.3 && docker build -q -t codeinchrome/laravel:8.3 . >/dev/null && echo "  image built and self-verified"'

say "installing the agent"
scp -q agent/bin/cic-agent-linux "root@$ip:/opt/codeinchrome/bin/cic-agent.new"
ssh_ 'mv /opt/codeinchrome/bin/cic-agent.new /opt/codeinchrome/bin/cic-agent && chmod 0755 /opt/codeinchrome/bin/cic-agent'
ssh_ 'bash -s' < infra/install-agent.sh

say "ready"
