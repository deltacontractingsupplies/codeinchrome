#!/usr/bin/env bash
#
# Append-only backup server on the control host.
#
#   infra/setup-backup-server.sh
#
# Idempotent. Run by setup-backups.sh before any host is pointed at it.
#
# Why: with SFTP, a customer host had write access to its own repository, so
# root on a compromised host could delete that host's backups - exactly when
# they are needed. restic's rest-server in --append-only mode lets a client
# ADD snapshots and never remove or rewrite one. --private-repos confines each
# host's login to its own repository. Retention (forget/prune) runs only here,
# on the control host, against the files directly - see cic-backup-maintain.
#
# rest-server listens on loopback; Caddy terminates TLS for
# backups.codeinchrome.com in front of it, so passwords never cross the
# internet in the clear.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

ip=${CIC_CONTROL_HOST##*:}
domain=${CIC_BACKUP_DOMAIN:-backups.codeinchrome.com}
VERSION=0.14.0
SHA=$(curl -fsSL "https://github.com/restic/rest-server/releases/download/v$VERSION/SHA256SUMS" \
  | awk "/rest-server_${VERSION}_linux_amd64.tar.gz\$/{print \$1}")
[[ ${#SHA} == 64 ]] || { echo "cannot read rest-server's published checksum" >&2; exit 1; }

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
target() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

# DNS for the backup endpoint.
set -a; . ./.env; set +a
existing=$(curl -fsS -g "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records?name=$domain" \
  -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print(r[0]["id"] if r else "")')
if [[ -z $existing ]]; then
  curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records" \
    -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" \
    -d "{\"type\":\"A\",\"name\":\"$domain\",\"content\":\"$ip\",\"proxied\":false,\"ttl\":300}" >/dev/null
fi
ok "DNS $domain -> $ip"

target "VERSION=$VERSION SHA=$SHA DOMAIN=$domain bash -s" <<'REMOTE'
set -Eeuo pipefail
if ! /usr/local/bin/rest-server --version 2>/dev/null | grep -q "$VERSION"; then
  tmp=$(mktemp -d)
  curl -fsSL -o "$tmp/r.tgz" "https://github.com/restic/rest-server/releases/download/v$VERSION/rest-server_${VERSION}_linux_amd64.tar.gz"
  echo "$SHA  $tmp/r.tgz" | sha256sum -c --quiet - || { echo "rest-server checksum MISMATCH" >&2; exit 1; }
  tar -xzf "$tmp/r.tgz" -C "$tmp"
  install -m 0755 "$tmp/rest-server_${VERSION}_linux_amd64/rest-server" /usr/local/bin/rest-server
  rm -rf "$tmp"
fi
command -v htpasswd >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq apache2-utils >/dev/null

id -u restserver >/dev/null 2>&1 || useradd -r -M -d /nonexistent -s /usr/sbin/nologin restserver
install -d -m 0750 -o restserver -g restserver /srv/backups-rest
touch /srv/backups-rest/.htpasswd
chown restserver:restserver /srv/backups-rest/.htpasswd
chmod 0640 /srv/backups-rest/.htpasswd

cat > /etc/systemd/system/rest-server.service <<'UNIT'
[Unit]
Description=restic rest-server (append-only) for codeinchrome backups
After=network.target

[Service]
User=restserver
Group=restserver
ExecStart=/usr/local/bin/rest-server --path /srv/backups-rest --listen 127.0.0.1:8000 --append-only --private-repos --htpasswd-file /srv/backups-rest/.htpasswd
Restart=always
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/srv/backups-rest
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now rest-server >/dev/null 2>&1
systemctl restart rest-server

cat > /opt/codeinchrome/caddy/sites/_backups.caddy <<CADDY
# restic rest-server, append-only. TLS here; the server itself binds loopback.
$DOMAIN {
	reverse_proxy 127.0.0.1:8000
	request_body {
		max_size 256MB
	}
	log {
		output file /var/log/caddy/_backups.log
		format json
	}
}
CADDY
touch /var/log/caddy/_backups.log && chown caddy:caddy /var/log/caddy/_backups.log
caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 || { echo "Caddyfile invalid" >&2; exit 1; }
systemctl reload caddy
REMOTE
ok "rest-server $VERSION (append-only, private repos) behind https://$domain"

# Retention: the only place anything is ever removed from a repository.
scp -q infra/cic-backup-maintain "root@$ip:/opt/codeinchrome/bin/cic-backup-maintain"
target 'bash -s' <<'REMOTE'
set -Eeuo pipefail
chmod 0750 /opt/codeinchrome/bin/cic-backup-maintain
cat > /etc/systemd/system/cic-backup-maintain.service <<'UNIT'
[Unit]
Description=codeinchrome backup retention (forget, prune)
[Service]
Type=oneshot
ExecStart=/opt/codeinchrome/bin/cic-backup-maintain
Nice=10
IOSchedulingClass=idle
UNIT
cat > /etc/systemd/system/cic-backup-maintain.timer <<'UNIT'
[Unit]
Description=codeinchrome backup retention
[Timer]
# After every host's nightly window (02:00 + up to 90 min).
OnCalendar=*-*-* 05:30:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now cic-backup-maintain.timer >/dev/null 2>&1
REMOTE
ok "retention job scheduled on the control host"

fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
check "rest-server running"            "target 'systemctl is-active rest-server'"
check "listening on loopback only"     "target 'ss -Hltn | awk \"{print \\\$4}\" | grep -qx 127.0.0.1:8000' && ! target 'ss -Hltn | awk \"{print \\\$4}\" | grep -Eq \"^(0.0.0.0|\\\\*|\\\\[::\\\\]):8000\$\"'"
check "append-only flag is live"       "target 'grep -q -- --append-only /proc/\$(systemctl show rest-server -p MainPID --value)/cmdline'"
check "retention timer scheduled"      "target 'systemctl is-active cic-backup-maintain.timer'"
check "anonymous access refused"       "[[ \$(curl -s -o /dev/null -w %{http_code} https://$domain/) == 401 ]]"
(( fails )) && die "$fails check(s) failed"
printf '\033[32mbackup server ready\033[0m  https://%s\n' "$domain"
