#!/usr/bin/env bash
#
# Hold open one SSH tunnel per host, so the control plane can reach each
# cic-agent over plain HTTP to 127.0.0.1 while the traffic crosses the
# internet inside SSH.
#
#   bash infra/tunnels.sh install     # systemd units (run on the control host)
#   bash infra/tunnels.sh up          # foreground tunnels (development)
#   bash infra/tunnels.sh check       # verify every agent answers
#
# Why a tunnel rather than exposing the agent: the agent can create and
# destroy every customer site on its host. An open port with a bearer token is
# one token leak away from total compromise, so it binds 127.0.0.1 and nothing
# else. This adds no listening port to a customer host, no new daemon and no
# new key material - it reuses the keys-only SSH we already depend on to
# deploy. See config/fleet.php.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

action=${1:-check}

install_units() {
  for entry in $CIC_HOSTS; do
    name=${entry%%:*}; ip=${entry##*:}
    port=$((9440 + ${name#h}))
    cat > "/etc/systemd/system/cic-tunnel@$name.service" <<UNIT
[Unit]
Description=codeinchrome agent tunnel to $name ($ip)
After=network-online.target
Wants=network-online.target

[Service]
# ExitOnForwardFailure makes a tunnel that cannot bind FAIL rather than sit
# there looking healthy while forwarding nothing. ServerAlive* turns a dead
# peer into a restart instead of a socket that accepts and never answers.
ExecStart=/usr/bin/ssh -NT \\
  -o ExitOnForwardFailure=yes \\
  -o ServerAliveInterval=15 \\
  -o ServerAliveCountMax=3 \\
  -o StrictHostKeyChecking=accept-new \\
  -L 127.0.0.1:$port:127.0.0.1:9440 root@$ip
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
UNIT
    systemctl enable --now "cic-tunnel@$name" >/dev/null 2>&1 || true
    echo "  installed cic-tunnel@$name -> 127.0.0.1:$port"
  done
  systemctl daemon-reload
}

up_foreground() {
  for entry in $CIC_HOSTS; do
    name=${entry%%:*}; ip=${entry##*:}
    port=$((9440 + ${name#h}))
    if nc -z 127.0.0.1 "$port" 2>/dev/null; then
      echo "  $name: already up on $port"
      continue
    fi
    ssh -fNT -o ExitOnForwardFailure=yes -o ServerAliveInterval=15 \
        -o StrictHostKeyChecking=accept-new \
        -L "127.0.0.1:$port:127.0.0.1:9440" "root@$ip"
    echo "  $name: tunnel up on $port"
  done
}

check() {
  fails=0
  for entry in $CIC_HOSTS; do
    name=${entry%%:*}; ip=${entry##*:}
    port=$((9440 + ${name#h}))
    # The health check must come from the AGENT, not from the socket. A tunnel
    # that connects but forwards nowhere still accepts a TCP connection.
    if body=$(curl -fsS --max-time 8 "http://127.0.0.1:$port/healthz" 2>/dev/null) \
       && [[ "$body" == *'"ok":true'* ]]; then
      echo "  $name ($ip) on $port: $(echo "$body" | sed 's/.*"version":"\([^"]*\)".*/agent \1/')"
    else
      echo "  $name ($ip) on $port: NO ANSWER"
      fails=$((fails+1))
    fi
  done
  (( fails )) && { echo "$fails host(s) unreachable"; exit 1; }
  echo "all agents answering"
}

case "$action" in
  install) install_units ;;
  up) up_foreground ;;
  check) check ;;
  *) echo "usage: tunnels.sh {install|up|check}" >&2; exit 1 ;;
esac
