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

say "installing the agent"
scp -q agent/bin/cic-agent-linux "root@$ip:/opt/codeinchrome/bin/cic-agent.new"
scp -q infra/cic-mount "root@$ip:/opt/codeinchrome/bin/cic-mount"
ssh_ 'chmod 0750 /opt/codeinchrome/bin/cic-mount' 
ssh_ 'mv /opt/codeinchrome/bin/cic-agent.new /opt/codeinchrome/bin/cic-agent && chmod 0755 /opt/codeinchrome/bin/cic-agent'
ssh_ "CIC_HOST_NAME=$name bash -s" < infra/install-agent.sh

say "backups"
infra/setup-backups.sh "$name" "$ip"

say "ready"
