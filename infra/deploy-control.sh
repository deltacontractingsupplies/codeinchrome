#!/usr/bin/env bash
#
# Deploy the control plane to its own host.
#
#   infra/deploy-control.sh
#
# Idempotent. The control host is deliberately NOT a customer host: this
# machine holds every agent token and the Cloudflare API key, and those do not
# belong on a box that also runs customer containers.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

name=${CIC_CONTROL_HOST%%:*}
ip=${CIC_CONTROL_HOST##*:}
domain=${CIC_CONTROL_DOMAIN:-app.codeinchrome.com}

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" ]] && { echo "REFUSING: $ip is the production host" >&2; exit 1; }
done

say()  { printf '\n\033[1;36m[%s]\033[0m %s\n' "$name" "$*"; }
ok()   { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die()  { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
ssh_() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

# The host must run the SAME php minor as the one composer.lock was resolved
# against. It is not a style preference: the lock here was built on 8.4 and
# Symfony 8.1 requires >=8.4.1, so an 8.3 host fails `composer install` with a
# wall of "does not satisfy that requirement" after the code is already synced.
# Deriving it from the local php means the two cannot drift apart unnoticed.
PHP_VERSION=${CIC_PHP_VERSION:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')}
say "php $PHP_VERSION (matched to the local php that resolved composer.lock)"
ssh_ "PHP_VERSION=$PHP_VERSION bash -s" <<'REMOTE'
set -Eeuo pipefail
if ! command -v "php$PHP_VERSION" >/dev/null; then
  add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
  apt-get update -qq
fi
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  "php$PHP_VERSION-cli" "php$PHP_VERSION-fpm" "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" \
  "php$PHP_VERSION-curl" "php$PHP_VERSION-sqlite3" "php$PHP_VERSION-zip" "php$PHP_VERSION-intl" \
  "php$PHP_VERSION-bcmath" unzip git >/dev/null
command -v composer >/dev/null || {
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null
}
id -u codeinchrome >/dev/null 2>&1 || useradd -r -m -d /var/lib/codeinchrome -s /usr/sbin/nologin codeinchrome
mkdir -p /srv/control
REMOTE
remote_php=$(ssh_ "php$PHP_VERSION -v | head -1 | cut -d' ' -f2")
ok "php $remote_php"
[[ "$remote_php" == "$PHP_VERSION".* ]] || die "host php is $remote_php but composer.lock needs $PHP_VERSION"

say "code"
# Source only. vendor/ and node_modules are built on the host, and .env is
# never shipped - the live one is assembled there and stays there.
# --no-o --no-g: without them rsync -a carries the DEVELOPER's uid and gid onto
# the server. Here that landed /srv/control as `501:staff` - a macOS uid that
# means nothing on Linux - mode 0750, and caddy could not traverse it at all.
rsync -az --delete --no-o --no-g \
  --exclude vendor --exclude node_modules --exclude .env --exclude 'database/*.sqlite' \
  --exclude storage/logs --exclude storage/framework/cache --exclude public/build \
  -e 'ssh -o StrictHostKeyChecking=accept-new' \
  control/ "root@$ip:/srv/control/"
rsync -az --no-o --no-g -e 'ssh -o StrictHostKeyChecking=accept-new' control/public/build/ "root@$ip:/srv/control/public/build/"
ok "source synced"

say "environment"
# Built on the host from the local .env plus the live agent tokens, so no
# secret is ever written into the repository or into a shell history here.
tokens=""
for entry in $CIC_HOSTS; do
  h=${entry%%:*}; hip=${entry##*:}
  t=$(ssh -o StrictHostKeyChecking=accept-new "root@$hip" 'grep -o "[0-9a-f]\{64\}" /opt/codeinchrome/etc/agent.env')
  [[ -n "$t" ]] || die "no agent token on $h"
  tokens+="CIC_TOKEN_$(echo "$h" | tr '[:lower:]' '[:upper:]')=$t"$'\n'
done

# shellcheck disable=SC2016
ssh_ "umask 077; cat > /srv/control/.env" <<ENV
APP_NAME=codeinchrome
APP_ENV=production
APP_KEY=$(ssh_ 'grep "^APP_KEY=" /srv/control/.env 2>/dev/null | cut -d= -f2-' || true)
APP_DEBUG=false
APP_URL=https://$domain

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/codeinchrome/control.sqlite

SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync

$(grep -E '^(CLOUDFLARE|LEMONSQUEEZY)_' .env)
$tokens
ENV
ssh_ 'set -e
chmod 600 /srv/control/.env
touch /var/lib/codeinchrome/control.sqlite
chown -R codeinchrome:codeinchrome /var/lib/codeinchrome
chown -R codeinchrome:codeinchrome /srv/control/storage /srv/control/bootstrap/cache
chmod 640 /srv/control/.env; chown root:codeinchrome /srv/control/.env'
ok "environment written (0640 root:codeinchrome)"

say "install and migrate"
ssh_ "PHP_VERSION=$PHP_VERSION bash -s" <<'REMOTE'
set -e
cd /srv/control
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --quiet
# After composer, never before: artisan needs vendor/autoload.php to exist.
# The key is generated once and then preserved across deploys - regenerating it
# would invalidate every session and every encrypted value in the database.
grep -q "^APP_KEY=base64:" .env || php$PHP_VERSION artisan key:generate --force -q
# A normal, non-destructive migrate. Never migrate:fresh on a live database.
sudo -u codeinchrome php$PHP_VERSION artisan migrate --force -q
php$PHP_VERSION artisan config:cache -q
php$PHP_VERSION artisan route:cache -q
php$PHP_VERSION artisan view:cache -q
REMOTE
ok "dependencies installed, database migrated, config cached"

say "tunnel identity"
# The control host needs to open ONE tunnel per customer host, and nothing
# else. Giving it root ssh everywhere would mean a compromise of the control
# plane is a shell on every customer machine, so instead:
#
#   - it logs in as `cictunnel`, an unprivileged user with no shell
#   - its authorized_keys entry is `restrict`, which turns off agent
#     forwarding, X11, pty allocation and command execution
#   - `permitopen="127.0.0.1:9440"` limits forwarding to the agent port alone
#
# So the key opens a pipe to one port and can do nothing else with it - not
# even run a command. The agent still requires its bearer token on top.
ssh_ 'test -f /root/.ssh/id_ed25519 || ssh-keygen -q -t ed25519 -N "" -C "codeinchrome-control" -f /root/.ssh/id_ed25519'
control_key=$(ssh_ 'cat /root/.ssh/id_ed25519.pub')
[[ -n "$control_key" ]] || die "could not read the control host public key"

for entry in $CIC_HOSTS; do
  h=${entry%%:*}; hip=${entry##*:}
  # Unquoted heredoc ON PURPOSE: $control_key must expand HERE, on the client,
  # because the remote host has no way to know the control host's public key.
  # Everything that must NOT expand locally is escaped below.
  # shellcheck disable=SC2087
  ssh -o StrictHostKeyChecking=accept-new "root@$hip" "bash -s" <<REMOTE
set -Eeuo pipefail
id -u cictunnel >/dev/null 2>&1 || useradd -r -m -d /home/cictunnel -s /usr/sbin/nologin cictunnel
install -d -m 700 -o cictunnel -g cictunnel /home/cictunnel/.ssh
entry='restrict,port-forwarding,permitopen="127.0.0.1:9440" $control_key'
touch /home/cictunnel/.ssh/authorized_keys
grep -qF "\${entry##* }" /home/cictunnel/.ssh/authorized_keys 2>/dev/null \
  || echo "\$entry" >> /home/cictunnel/.ssh/authorized_keys
chown cictunnel:cictunnel /home/cictunnel/.ssh/authorized_keys
chmod 600 /home/cictunnel/.ssh/authorized_keys
# A nologin shell still permits port forwarding, which is exactly the amount
# of access this needs.
REMOTE
  ok "$h accepts the control key for forwarding to 127.0.0.1:9440 only"
done

say "tunnels to every customer host"
scp -q infra/hosts.env infra/tunnels.sh "root@$ip:/srv/control/"
ssh_ 'cd /srv/control && bash tunnels.sh install'
sleep 3
ssh_ 'cd /srv/control && bash tunnels.sh check' || die "tunnels are not up; the control plane cannot reach any agent"

say "php-fpm pool and the vhost"
ssh_ "PHP_VERSION=$PHP_VERSION bash -s" <<'POOL'
set -Eeuo pipefail
# The application runs as `codeinchrome`, and the socket is owned by `caddy`.
# The default pool runs as www-data and its socket is www-data-only, so caddy
# could not connect to it - a 502 that looks exactly like a broken app.
cat > "/etc/php/$PHP_VERSION/fpm/pool.d/codeinchrome.conf" <<CONF
[codeinchrome]
user = codeinchrome
group = codeinchrome
listen = /run/php/codeinchrome.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
; A stack trace on a 500 leaks paths, queries and sometimes credentials.
php_admin_value[display_errors] = Off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/php-codeinchrome.log
php_admin_value[expose_php] = Off
; The app has no business reading outside its own tree.
php_admin_value[open_basedir] = /srv/control:/var/lib/codeinchrome:/tmp:/usr/share/php
CONF
touch /var/log/php-codeinchrome.log
chown codeinchrome:codeinchrome /var/log/php-codeinchrome.log

# Caddy needs exactly two things: to TRAVERSE /srv/control, and to READ
# /srv/control/public. It needs nothing else, so it gets nothing else.
#
# The first version of this made everything group-readable and added caddy to
# the codeinchrome group, which handed the web server every agent token and the
# Cloudflare API key in .env - and the resolved copies of both in
# bootstrap/cache/config.php. The web server is the process most exposed to the
# internet; it is the last one that should hold the keys to the fleet.
chown -R codeinchrome:codeinchrome /srv/control
find /srv/control -type d -exec chmod 750 {} +
find /srv/control -type f -exec chmod 640 {} +

usermod -a -G codeinchrome caddy

# 0710: traverse, but not list. Caddy can walk THROUGH the root to public/ and
# cannot enumerate what else is in there.
chmod 710 /srv/control
chmod 750 /srv/control/public

# Secrets: owner only, group explicitly excluded. php-fpm runs as
# `codeinchrome` so the app still reads them; caddy, despite being in the
# group, cannot.
chmod 600 /srv/control/.env
chmod 700 /srv/control/bootstrap/cache /srv/control/storage
find /srv/control/bootstrap/cache -type f -exec chmod 600 {} + 2>/dev/null || true
systemctl enable --now "php$PHP_VERSION-fpm" >/dev/null 2>&1
systemctl restart "php$PHP_VERSION-fpm"
# caddy reads its groups at start, so a reload would NOT pick up the new
# membership and every request would still be denied.
systemctl restart caddy
POOL

ssh_ "set -e
cat > /opt/codeinchrome/caddy/sites/_control.caddy <<CADDY
# The control plane. Named with a leading underscore so it sorts before
# customer vhosts and is never mistaken for one.
$domain {
	root * /srv/control/public
	encode gzip zstd
	php_fastcgi unix//run/php/codeinchrome.sock
	file_server
	header {
		-Server
		Strict-Transport-Security \"max-age=31536000; includeSubDomains\"
		X-Content-Type-Options \"nosniff\"
		X-Frame-Options \"DENY\"
		Referrer-Policy \"strict-origin-when-cross-origin\"
	}
	# Nothing outside public/ is reachable, and these are refused even there.
	@hidden path /.env /.git/* /composer.json /composer.lock /artisan
	respond @hidden 404
	log {
		output file /var/log/caddy/_control.log
		format json
	}
}
CADDY
touch /var/log/caddy/_control.log && chown caddy:caddy /var/log/caddy/_control.log
caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 || { echo 'Caddyfile invalid'; exit 1; }
systemctl reload caddy"
ok "vhost written and caddy reloaded"

say "verifying"
fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
check "php-fpm running"        "ssh root@$ip 'systemctl is-active php$PHP_VERSION-fpm'"
check "caddy can read the docroot" "ssh root@$ip 'sudo -u caddy test -r /srv/control/public/index.php'"
check "caddy can reach the fpm socket" "ssh root@$ip 'sudo -u caddy test -w /run/php/codeinchrome.sock'"
check "app cannot read the agent tokens as caddy" "ssh root@$ip '! sudo -u caddy test -r /srv/control/.env'"
# Asserts the PROPERTY, not one exact mode. The previous version pinned 640
# and then failed when .env was correctly tightened to 600.
check "env readable by its owner only" "ssh root@$ip '[ \"\$(stat -c %a /srv/control/.env)\" = 600 ]'"
check "env owned by the app user"      "ssh root@$ip '[ \"\$(stat -c %U /srv/control/.env)\" = codeinchrome ]'"
check "config is cached"       "ssh root@$ip 'test -f /srv/control/bootstrap/cache/config.php'"
check "answers over https"     "[ \"\$(curl -s -o /dev/null -w %{http_code} --max-time 25 https://$domain/)\" = 200 ]"
check ".env not served"        "[ \"\$(curl -s -o /dev/null -w %{http_code} --max-time 15 https://$domain/.env)\" != 200 ]"
check "dashboard needs auth"   "[ \"\$(curl -s -o /dev/null -w %{http_code} --max-time 15 https://$domain/sites)\" = 302 ]"
(( fails )) && die "$fails check(s) failed"

say "control plane live at https://$domain"
