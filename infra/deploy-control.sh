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
# Only the Cloudflare settings, for the bare-domain records below.
eval "$(grep -E '^CLOUDFLARE_(API_TOKEN|ZONE_ID|ZONE_NAME)=' .env | sed 's/^/export /')"

name=${CIC_CONTROL_HOST%%:*}
ip=${CIC_CONTROL_HOST##*:}
domain=${CIC_CONTROL_DOMAIN:-app.codeinchrome.com}

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" ]] && { echo "REFUSING: $ip is the production host" >&2; exit 1; }
done

# Never deploy code whose tests fail. (A deploy chained on `grep` finding the
# test summary line went out once with a failing test - the summary line is
# printed either way.) CIC_SKIP_TESTS=1 exists for emergencies, and says so.
if [[ ${CIC_SKIP_TESTS:-0} != 1 ]]; then
  ( cd control && PAO_DISABLE=1 php artisan test >.predeploy-tests.log 2>&1 ) || {
    tail -30 .predeploy-tests.log >&2
    echo "REFUSING to deploy: the control-plane tests fail (log: .predeploy-tests.log)" >&2
    exit 1
  }
  rm -f .predeploy-tests.log
else
  echo "WARNING: deploying WITHOUT running tests (CIC_SKIP_TESTS=1)" >&2
fi

# The browser code is shipped as built here (public/build), so build it from
# the source being deployed - every time. Once a fix to editor.js was deployed
# with an older build: the source was right and production ran the old code,
# with cic.check broken, until the e2e suite said so.
( cd control && npm run build --silent >/dev/null 2>&1 ) || {
  echo "REFUSING to deploy: the front end does not build (cd control && npm run build)" >&2
  exit 1
}

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
# /storage/ and /bootstrap/cache/ are the SERVER's state and are never
# shipped or --deleted: sessions (the file driver - shipping them signed every
# customer out on every deploy), uploaded files, and the package manifest,
# which from a laptop lists dev-only packages (Laravel Pail) that the
# per-minute scheduler then failed to load until composer rebuilt it.
rsync -az --delete --no-o --no-g \
  --exclude /vendor/ --exclude /node_modules/ --exclude /.env --exclude '/database/*.sqlite' \
  --exclude /storage/ --exclude /bootstrap/cache/ --exclude /public/build/ --exclude /tests/ \
  --exclude /.phpunit.result.cache --exclude /.predeploy-tests.log --exclude /skills/ \
  -e 'ssh -o StrictHostKeyChecking=accept-new' \
  control/ "root@$ip:/srv/control/"
ssh_ 'cd /srv/control && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache'
rsync -az --no-o --no-g -e 'ssh -o StrictHostKeyChecking=accept-new' control/public/build/ "root@$ip:/srv/control/public/build/"
# The agent skill, served at /agent/skill.md and read by cic.skill() in the
# editor. Inside /srv/control: php-fpm's open_basedir allows nothing outside
# it (CIC_SKILL_PATH below points here). Excluded from the sync above, so
# its --delete never removes it.
rsync -az --delete --no-o --no-g -e 'ssh -o StrictHostKeyChecking=accept-new' skills/ "root@$ip:/srv/control/skills/"
ssh_ 'rm -rf /srv/skills' # where it went first, before open_basedir said no
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
# WAL: readers never block the writer (a sign-in once failed with "database
# is locked" while the scheduler wrote). WAL adds control.sqlite-wal and -shm
# beside the file, created by whoever opens it first - so EVERYTHING that opens
# it runs as codeinchrome (artisan via sudo -u, the backup via runuser); a
# root-owned -shm would leave the app unable to write at all.
DB_JOURNAL_MODE=wal
DB_SYNCHRONOUS=normal
# Touched by cic-control-backup after each complete run; monitoring alerts
# when it grows old (infra/setup-control-backup.sh).
CIC_CONTROL_BACKUP_STAMP=/var/lib/codeinchrome/control-backup.ok
CIC_SKILL_PATH=/srv/control/skills/codeinchrome/SKILL.md
# Email sign-up: trusted providers only (config/signup.php); Google and Apple
# sign-in are always open. The e2e suite's reserved addresses are accepted -
# they can never receive mail, so they stay unverified and can do nothing.
CIC_SIGNUP_EMAIL_DOMAINS=gmail.com,googlemail.com
CIC_SIGNUP_TEST_DOMAIN=codeinchrome.test

SESSION_DRIVER=file
# __Host-: the browser refuses this cookie if it carries a Domain attribute,
# so a customer's site on name.codeinchrome.com cannot plant a session cookie
# for the dashboard ("cookie tossing" from a sibling subdomain).
SESSION_COOKIE=__Host-codeinchrome-session
SESSION_PATH=/
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync

$(grep -E '^(CLOUDFLARE|LEMONSQUEEZY|LS_VARIANT|CIC_ADMIN|CIC_ALERT|CIC_LEGAL|CIC_SUPPORT|CIC_REFUND|CIC_PAID|CIC_OWNER|MAIL|GOOGLE|APPLE|SHOWCASE)_' .env)
# The fleet, from infra/hosts.env - the one registry (config/fleet.php).
CIC_HOSTS="$CIC_HOSTS"
CIC_HOST_STATES="${CIC_HOST_STATES:-}"
CIC_FORBIDDEN_HOSTS="${CIC_FORBIDDEN_HOSTS:-}"
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

say "scheduler"
# One cron line drives every scheduled command (routes/console.php): usage
# measurement and retrying plan limits a host could not accept at the time.
# Runs as the app user, never root.
ssh_ "cat > /etc/cron.d/codeinchrome <<CRON
# Managed by codeinchrome infra/deploy-control.sh
* * * * * codeinchrome cd /srv/control && php$PHP_VERSION artisan schedule:run >/dev/null 2>&1
CRON
chmod 0644 /etc/cron.d/codeinchrome"
ok "scheduler installed"

say "tunnels to every customer host"
[[ -f infra/hosts.local.env ]] || die "infra/hosts.local.env is missing: it holds the fleet's addresses"
scp -q infra/hosts.env infra/hosts.local.env infra/tunnels.sh "root@$ip:/srv/control/"
ssh_ 'chmod 600 /srv/control/hosts.local.env'
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
; The editor's upload passes files through to the site (at most 32 MB there);
; a database import can be up to 95 MB - Cloudflare, in front, refuses request
; bodies over 100 MB, so a larger limit here could never be reached.
php_admin_value[upload_max_filesize] = 96M
php_admin_value[post_max_size] = 97M
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

# The bare domain and www point at this host (redirected below). Upserted, so
# re-running a deploy never duplicates them. PROXIED, like app: Cloudflare
# serves visitors and reaches h2 with the origin certificate
# (setup-cloudflare-proxy.sh). A DNS-only record here would show visitors a
# certificate only Cloudflare trusts.
zone=${CLOUDFLARE_ZONE_NAME:-codeinchrome.com}
for record in "$zone" "www.$zone"; do
  rec=$(curl -fsS -g "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records?type=A&name=$record" \
          -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print(r[0]["id"] if r else "")')
  body=$(printf '{"type":"A","name":"%s","content":"%s","ttl":1,"proxied":true}' "$record" "$ip")
  if [[ -n $rec ]]; then
    curl -fsS -X PUT "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records/$rec" \
      -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" -d "$body" >/dev/null
  else
    curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records" \
      -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" -d "$body" >/dev/null
  fi
done
ok "$zone and www.$zone point at $ip"

# h2's Caddyfile, managed here (it used to be left from an old agent install
# and drifted). Same rule as the hosts: CF-Connecting-IP is believed only from
# Cloudflare's own ranges.
cf_ranges=$(curl -fsS --retry 3 https://api.cloudflare.com/client/v4/ips \
  | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print(" ".join(r["ipv4_cidrs"] + r["ipv6_cidrs"]))')
[[ $cf_ranges == *"/"* ]] || die "could not fetch Cloudflare's IP ranges"
ssh_ "cat > /etc/caddy/Caddyfile.new" <<CADDYFILE
# Managed by codeinchrome infra/deploy-control.sh.
{
	admin localhost:2019
	email ops@codeinchrome.com
	grace_period 10s
	servers {
		trusted_proxies static private_ranges $cf_ranges
		trusted_proxies_strict
		client_ip_headers CF-Connecting-IP
	}
}
import /opt/codeinchrome/caddy/sites/*.caddy
CADDYFILE
ssh_ 'caddy validate --config /etc/caddy/Caddyfile.new --adapter caddyfile >/dev/null 2>&1 && mv /etc/caddy/Caddyfile.new /etc/caddy/Caddyfile' \
  || die "the new Caddyfile does not validate; the old one was kept"

# Proxied through Cloudflare: served with the origin certificate once
# setup-cloudflare-proxy.sh has installed it.
origin_tls=""
ssh_ 'test -s /etc/caddy/origin/cert.pem' && origin_tls="tls /etc/caddy/origin/cert.pem /etc/caddy/origin/key.pem"

ssh_ "set -e
cat > /opt/codeinchrome/caddy/sites/_control.caddy <<CADDY
# The control plane. Named with a leading underscore so it sorts before
# customer vhosts and is never mistaken for one.
$domain {
	$origin_tls
	root * /srv/control/public
	encode gzip zstd
	# PHP sees the VISITOR's address as REMOTE_ADDR - Caddy's client_ip,
	# resolved from CF-Connecting-IP on Cloudflare's connections only. Without
	# this every request came from a Cloudflare address, and per-IP rate
	# limits, lockouts and the audit log saw a handful of shared IPs.
	php_fastcgi unix//run/php/codeinchrome.sock {
		env REMOTE_ADDR {client_ip}
	}
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

# The bare domain and www: the public site IS the control plane's front page,
# so they redirect there rather than serving a second copy of it. One origin
# keeps sessions, CSRF and the CSP simple, and nothing is served here at all.
$zone, www.$zone {
	$origin_tls
	header -Server
	redir https://$domain{uri} 301
}
CADDY
touch /var/log/caddy/_control.log && chown caddy:caddy /var/log/caddy/_control.log
caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1 || { echo 'Caddyfile invalid'; exit 1; }
systemctl reload caddy"
ok "vhost written and caddy reloaded"

say "automatic reboots for kernel updates"
# After the customer hosts (install-agent.sh: from 04:30 UTC, 10 minutes apart):
# the owner's decision, 2026-09-25.
ssh_ "cat > /etc/apt/apt.conf.d/52cic-reboot" <<'APT'
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-WithUsers "true";
Unattended-Upgrade::Automatic-Reboot-Time "05:15";
APT
ok "an update that needs a reboot is applied at 05:15 UTC"

say "web ports: Cloudflare and the fleet only"
# The control plane is served through Cloudflare, and the backups endpoint
# answers the fleet's hosts only (restic, direct: larger than the proxy takes).
# Nothing else has any business on 80/443 here (2026-09-25); a request
# straight to this address bypasses Cloudflare. Every vhost here uses the
# Cloudflare origin certificate, so no certificate needs the world to reach
# this host either. New rules in before the open ones come out: no gap.
cf_ranges=$(curl -fsS --retry 3 https://api.cloudflare.com/client/v4/ips \
  | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print(" ".join(r["ipv4_cidrs"] + r["ipv6_cidrs"]))')
(( $(wc -w <<<"$cf_ranges") >= 15 )) || die "could not fetch Cloudflare's ranges; firewall left as it was"
fleet_ips=""
for entry in $CIC_HOSTS; do fleet_ips+=" ${entry##*:}"; done
ssh_ "CF='$cf_ranges' FLEET='$fleet_ips' bash -s" <<'FW'
set -Eeuo pipefail
for cidr in $CF;    do ufw allow proto tcp from "$cidr" to any port 80,443 comment cloudflare >/dev/null; done
for ip   in $FLEET; do ufw allow proto tcp from "$ip"   to any port 443    comment fleet      >/dev/null; done
ufw delete allow 80/tcp  >/dev/null 2>&1 || true
ufw delete allow 443/tcp >/dev/null 2>&1 || true
FW
ok "80/443 from Cloudflare's $(wc -w <<<"$cf_ranges") ranges and 443 from the fleet's hosts only"

say "verifying"
fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
# Public checks go the way a visitor's request does: through Cloudflare,
# resolved by a public resolver. This machine's own resolver may still hold a
# DNS-only answer from before the proxy, and then reaches h2 directly, where
# only Cloudflare trusts the certificate.
pub() {
  local url=${*: -1} host
  host=${url#https://}; host=${host%%/*}
  curl --resolve "$host:443:$(dig +short "$host" @1.1.1.1 | tail -1)" "$@"
}
check "the bare domain redirects to the app" "[ \"\$(pub -sS -o /dev/null -w '%{http_code} %{redirect_url}' --retry 10 --retry-all-errors --retry-delay 6 https://$zone/pricing)\" = '301 https://$domain/pricing' ]"
check "www redirects to the app" "[ \"\$(pub -sS -o /dev/null -w '%{http_code}' --retry 10 --retry-all-errors --retry-delay 6 https://www.$zone/)\" = 301 ]"
check "the session cookie is __Host- (no sibling subdomain can set it)" "pub -sS -D - -o /dev/null https://$domain/login | grep -i '^set-cookie: __Host-codeinchrome-session=' | grep -iv 'domain='"
# Hosts verify this certificate on every nightly upload. The proxy's origin
# wildcard once took its place (Caddy prefers a loaded matching certificate),
# which only Cloudflare trusts - backups would have failed that night.
# Hosts trust the origin certificate explicitly (setup-backups.sh); checked
# from one, as restic sees it.
bk_host=$(awk '{print $1}' <<<"$CIC_HOSTS"); bk_host=${bk_host##*:}
check "web ports closed to the world" "ssh root@$ip '! ufw status | grep -qE \"^(80|443)(/tcp)?( \\(v6\\))? +ALLOW( IN)? +Anywhere\"'"
check "backups endpoint answers the hosts over trusted TLS" "[ \"\$(ssh -n -o BatchMode=yes root@$bk_host 'curl -s -o /dev/null -w %{http_code} --max-time 15 --cacert /opt/codeinchrome/etc/restic-ca.pem https://backups.$zone/')\" = 401 ]"
check "php-fpm running"        "ssh root@$ip 'systemctl is-active php$PHP_VERSION-fpm'"
check "caddy can read the docroot" "ssh root@$ip 'sudo -u caddy test -r /srv/control/public/index.php'"
check "caddy can reach the fpm socket" "ssh root@$ip 'sudo -u caddy test -w /run/php/codeinchrome.sock'"
check "app cannot read the agent tokens as caddy" "ssh root@$ip '! sudo -u caddy test -r /srv/control/.env'"
# Asserts the PROPERTY, not one exact mode. The previous version pinned 640
# and then failed when .env was correctly tightened to 600.
check "env readable by its owner only" "ssh root@$ip '[ \"\$(stat -c %a /srv/control/.env)\" = 600 ]'"
check "env owned by the app user"      "ssh root@$ip '[ \"\$(stat -c %U /srv/control/.env)\" = codeinchrome ]'"
check "scheduler installed"    "ssh root@$ip 'grep -q schedule:run /etc/cron.d/codeinchrome'"
check "no dev-only package in the manifest" "! ssh root@$ip 'grep -q Pail /srv/control/bootstrap/cache/packages.php'"
check "config is cached"       "ssh root@$ip 'test -f /srv/control/bootstrap/cache/config.php'"
check "answers over https"     "[ \"\$(pub -s -o /dev/null -w %{http_code} --max-time 25 https://$domain/)\" = 200 ]"
check ".env not served"        "[ \"\$(pub -s -o /dev/null -w %{http_code} --max-time 15 https://$domain/.env)\" != 200 ]"
check "dashboard needs auth"   "[ \"\$(pub -s -o /dev/null -w %{http_code} --max-time 15 https://$domain/sites)\" = 302 ]"
(( fails )) && die "$fails check(s) failed"

say "control plane live at https://$domain"
