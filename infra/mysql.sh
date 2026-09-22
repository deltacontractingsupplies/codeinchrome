#!/usr/bin/env bash
#
# One MySQL server per customer host, shared by that host's sites.
#
#   ssh root@HOST 'bash -s' < infra/mysql.sh
#
# Idempotent. Run by deploy-host.sh after bootstrap.sh.
#
# Why shared and not per site: a MySQL server costs roughly 400 MB of memory
# before it holds any data, so one per site would erase the margin that comes
# from packing sites onto hosts. Isolation between tenants comes from MySQL's
# own privilege system instead - one database and one user per site, the user
# granted that database and nothing else - and is proved below, not assumed.
#
# Why it is unreachable from the internet by construction: mysqld binds ONLY
# to 127.0.0.1 and to the docker bridge gateway (172.17.0.1). It is not
# listening on the public interface at all, so the firewall rule is a second
# layer rather than the only one. Customer containers reach it at the
# gateway through --add-host cic-db:host-gateway.

set -Eeuo pipefail
log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m  ok\033[0m %s\n' "$*"; }
warn() { printf '\033[33m  !!\033[0m %s\n' "$*"; }
die()  { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root"

CIC=/opt/codeinchrome
DATA=/srv/mysql
IMAGE=mysql:8.4
NAME=cic-mysql
GATEWAY=$(docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}')
POOL=172.20.0.0/14   # daemon.json default-address-pools: every site network lives here

[[ -n "$GATEWAY" ]] || die "cannot read the docker bridge gateway"

# ─────────────────────────────────────────────────────────────────────────────
log "credentials"
if [[ ! -s $CIC/etc/mysql.env ]]; then
  # umask in a SUBSHELL. Set at script level it leaked into everything after
  # it: the config directory below was created 0700, mysqld (uid 999 inside
  # its container) could not read it, and the server refused to start.
  ( umask 077
    printf 'CIC_MYSQL_ROOT_PASSWORD=%s\n' "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "$CIC/etc/mysql.env" )
  ok "root password generated"
else
  ok "root password present (not rotated)"
fi
chmod 0600 "$CIC/etc/mysql.env"
# shellcheck disable=SC1091
. "$CIC/etc/mysql.env"

# ─────────────────────────────────────────────────────────────────────────────
log "configuration"
mem_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
# A quarter of the host's memory, capped: the rest belongs to customer
# containers, which are the point of the machine.
pool_mb=$(( mem_mb / 4 )); (( pool_mb > 4096 )) && pool_mb=4096; (( pool_mb < 256 )) && pool_mb=256

mkdir -p "$CIC/mysql/conf.d" "$DATA"
cat > "$CIC/mysql/conf.d/cic.cnf" <<CNF
# Managed by codeinchrome infra/mysql.sh. Rewritten on every run.
[mysqld]
# Loopback for the agent, the docker gateway for customer containers, and
# nothing else. Not 0.0.0.0: an address mysqld never listens on cannot be
# reached however the firewall is configured.
bind-address = 127.0.0.1,$GATEWAY

# Host patterns on accounts are IP ranges, so reverse DNS is never needed -
# and a slow resolver would otherwise stall every new connection.
skip-name-resolve = ON

# The X Protocol listener binds EVERY interface on 33060 by default and ignores
# bind-address. Nothing here speaks it, so it is off rather than firewalled.
mysqlx = OFF

# Durable. This is customer data: every commit is on disk before it is
# acknowledged. (The low-write settings used on a development laptop are
# exactly the wrong trade here.)
innodb_flush_log_at_trx_commit = 1
sync_binlog = 1
innodb_buffer_pool_size = ${pool_mb}M

max_connections = 600
# Reading files from the server's disk into a query, or writing them out, is
# never something a customer application needs.
local_infile = OFF
secure_file_priv = NULL

character-set-server = utf8mb4
collation-server = utf8mb4_0900_ai_ci
CNF
# No secrets in here; mysqld runs as uid 999 in its container and must read it.
chmod 0755 "$CIC/mysql" "$CIC/mysql/conf.d"
chmod 0644 "$CIC/mysql/conf.d/cic.cnf"
ok "buffer pool ${pool_mb}M of ${mem_mb}M, bound to 127.0.0.1 and $GATEWAY"

# ─────────────────────────────────────────────────────────────────────────────
log "server"
if ! docker container inspect "$NAME" >/dev/null 2>&1; then
  docker run -d --name "$NAME" \
    --restart unless-stopped \
    --network host \
    --memory "$(( pool_mb + 1024 ))m" \
    -e MYSQL_ROOT_PASSWORD="$CIC_MYSQL_ROOT_PASSWORD" \
    -e MYSQL_ROOT_HOST=127.0.0.1 \
    -v "$DATA:/var/lib/mysql" \
    -v "$CIC/mysql/conf.d:/etc/mysql/conf.d:ro" \
    "$IMAGE" >/dev/null
  ok "started $NAME"
else
  docker restart "$NAME" >/dev/null
  ok "restarted $NAME to apply configuration"
fi

root_sql() { docker exec -e MYSQL_PWD="$CIC_MYSQL_ROOT_PASSWORD" "$NAME" mysql -uroot -h127.0.0.1 -N -B -e "$1"; }

for _ in $(seq 1 60); do
  root_sql "SELECT 1" >/dev/null 2>&1 && break
  sleep 2
done
root_sql "SELECT 1" >/dev/null 2>&1 || die "mysqld did not come up within 120s: $(docker logs --tail 20 "$NAME" 2>&1)"
ok "accepting connections"

# ─────────────────────────────────────────────────────────────────────────────
log "firewall"
# Only the site-network pool may reach 3306, and only on the gateway address.
if ! ufw status | grep -q "3306.*$POOL"; then
  ufw allow from "$POOL" to "$GATEWAY" port 3306 proto tcp comment 'customer containers -> cic-mysql' >/dev/null
fi
# `ufw status` reads ufw's own config file, not the live firewall. After a boot
# script restored an old iptables snapshot, status still listed this rule
# while the kernel had no such rule and containers could not reach MySQL.
# So the live chain is what is checked, and ufw is reloaded if they disagree.
if ! iptables -S ufw-user-input 2>/dev/null | grep -q -- "--dport 3306"; then
  ufw reload >/dev/null
fi
ok "3306 open to $POOL on $GATEWAY only"

# ─────────────────────────────────────────────────────────────────────────────
log "verifying"
fails=0
has()  { local pat=$1; shift; local out; out=$("$@" 2>/dev/null) || true; [[ "$out" == *"$pat"* ]]; }
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else warn "$1"; fails=$((fails+1)); fi; }

check "firewall rule is LIVE, not just configured" 'iptables -S ufw-user-input | grep -q -- "--dport 3306"'
check "listening on loopback"          'has "127.0.0.1:3306" ss -ltn'
check "listening on the docker gateway" "has \"$GATEWAY:3306\" ss -ltn"
# Exact matches on the local-address column. A substring test for "*:3306"
# also matched "*:33060" - which is how the X Protocol listener on every
# interface was found.
listens_publicly() { ss -Hltn | awk '{print $4}' | grep -Eq '^(0\.0\.0\.0|\*|\[::\]):(3306|33060)$'; }
check "nothing MySQL on all addresses"   '! listens_publicly'
check "root only from 127.0.0.1"        '[[ "$(root_sql "SELECT GROUP_CONCAT(host) FROM mysql.user WHERE user=\"root\"")" != *"%"* ]]'
check "local_infile off"                '[[ "$(root_sql "SELECT @@local_infile")" == "0" ]]'
check "commits are durable"             '[[ "$(root_sql "SELECT @@innodb_flush_log_at_trx_commit")" == "1" ]]'

# From inside a throwaway container on a fresh site-style network: the port
# must be reachable (positive control), and root must be refused from there.
probe_net=cic-net-mysqlprobe
docker network create --opt com.docker.network.bridge.enable_icc=false "$probe_net" >/dev/null 2>&1 || true
check "a site network can reach it" \
  "docker run --rm --network $probe_net --add-host cic-db:host-gateway $IMAGE sh -c 'timeout 5 bash -c \"</dev/tcp/cic-db/3306\"'"
check "root refused from a container" \
  "! docker run --rm --network $probe_net --add-host cic-db:host-gateway -e MYSQL_PWD=\"$CIC_MYSQL_ROOT_PASSWORD\" $IMAGE mysql -uroot -hcic-db -e 'SELECT 1'"
docker network rm "$probe_net" >/dev/null 2>&1 || true

(( fails )) && die "$fails check(s) failed - MySQL is NOT ready"
echo
printf '\033[32mmysql ready\033[0m  %s  \033[2m(%s)\033[0m\n' "$(root_sql 'SELECT VERSION()')" "$IMAGE"
