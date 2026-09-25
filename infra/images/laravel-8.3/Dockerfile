# One shared base image for every Laravel site on a host.
#
# Fifty sites cost this image once plus fifty thin code layers. That is the
# whole reason we build our own rather than using a per-app auto-builder:
# Nixpacks produces 800 MB-1.3 GB per app with no sharing, which exhausts a
# small disk after three or four sites.
FROM php:8.3-apache

# Permanent runtime tools. Installed in their own layer because the extension
# build below deliberately purges everything it did not prove was needed, and
# would take these with it.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip git supervisor; \
    rm -rf /var/lib/apt/lists/*

# Extensions a real Laravel app needs. Kept to the list that is actually
# required - every extra one is more surface on fifty containers at once.
#
# The apt-mark dance is not ceremony. The first version of this file ended with
# a plain `apt-get purge -y --auto-remove <the -dev packages>`, which also
# removed the RUNTIME libraries those extensions link against - libpng16,
# libicu, libzip5. The build succeeded, the image shipped, and gd, intl and zip
# then failed to load at runtime with `cannot open shared object file`. Because
# display_errors is Off those warnings went only to stderr, so a served page
# looked perfectly healthy while Composer could not unzip anything.
#
# So: mark everything auto, restore what was manual before we started, then ask
# ldd which shared objects the built extensions ACTUALLY need and mark exactly
# those manual. Only then purge. This is the pattern the official PHP images use.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libonig-dev libuv1-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli zip gd intl bcmath opcache exif pcntl; \
    # uv: Laravel Reverb's event loop. Without it ReactPHP falls back to
    # stream_select, which cannot watch more than 1024 sockets - a hard
    # ceiling on WebSocket connections whatever the plan's memory.
    pecl install uv-0.3.0; \
    docker-php-ext-enable uv; \
    rm -rf /tmp/pear; \
    \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local/lib/php/extensions -name '*.so' -exec ldd '{}' ';' \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
      | sort -u \
      | xargs -r dpkg-query --search 2>/dev/null \
      | cut -d: -f1 \
      | sort -u \
      | xargs -r apt-mark manual > /dev/null; \
    \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Phpactor (MIT), the PHP language server behind the editor's IntelliSense:
# completion, hover, go to definition, diagnostics. It runs inside the site's
# own container, as the site's user, so it sees that site's vendor/ and
# nothing else. Pinned and checksummed: an unverified binary is not installed.
ARG PHPACTOR_VERSION=2026.06.23.0
ARG PHPACTOR_SHA256=25645647d9aa2dc69536fb4f75c976e33ef1a7b5533534a8456736e5e6fd5079
RUN set -eux; \
    curl -fsSLo /usr/local/bin/phpactor "https://github.com/phpactor/phpactor/releases/download/${PHPACTOR_VERSION}/phpactor.phar"; \
    echo "${PHPACTOR_SHA256}  /usr/local/bin/phpactor" | sha256sum -c -; \
    chmod 0755 /usr/local/bin/phpactor; \
    php /usr/local/bin/phpactor --version

# PROVE the extensions load. A build that installs an extension it cannot load
# is the failure this image already shipped once; an assertion here is the only
# thing that turns it from a silent runtime surprise into a red build.
RUN set -eux; \
    startup="$(php -v 2>&1)"; \
    case "$startup" in \
      *"Unable to load"*|*"Cannot load"*) \
        echo "FATAL: an extension failed to load at startup:"; echo "$startup"; exit 1 ;; \
    esac; \
    mods="$(php -m)"; \
    for ext in pdo_mysql mysqli zip gd intl bcmath exif pcntl uv; do \
      echo "$mods" | grep -qx "$ext" || { echo "FATAL: $ext built but not loaded"; echo "$mods"; exit 1; }; \
    done; \
    php -r 'exit(function_exists("opcache_get_status") ? 0 : 1);' || { echo "FATAL: opcache not active"; exit 1; }; \
    php -r 'exit(class_exists("ZipArchive") ? 0 : 1);'          || { echo "FATAL: ZipArchive missing, Composer would break"; exit 1; }; \
    php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);' || { echo "FATAL: gd not usable"; exit 1; }; \
    php -r 'exit(class_exists("NumberFormatter") ? 0 : 1);'     || { echo "FATAL: intl not usable"; exit 1; }; \
    composer --version > /dev/null                              || { echo "FATAL: composer broken"; exit 1; }; \
    echo "verified: every declared extension is loaded and usable"

# A ready-to-serve Laravel skeleton, baked in ONCE.
#
# Every new site is a copy of this directory rather than a `composer
# create-project`. The difference is 90 seconds and a few hundred network
# requests per site versus a few hundred milliseconds and none - and it means
# provisioning does not fail because packagist is having a bad afternoon.
#
# The skeleton carries NO application key. One is generated per site at
# creation, because a key shared across every customer on the platform would
# make every session cookie and every encrypted column forgeable across
# tenants.
RUN set -eux; \
    COMPOSER_ALLOW_SUPERUSER=1 composer create-project laravel/laravel /opt/cic-skeleton \
        --no-interaction --prefer-dist --no-progress --quiet; \
    # Laravel Boost (MIT, dev only): its MCP server gives the editor's agent
    # routes, schema, logs, the last error and version-specific docs
    # (window.cic.mcp). -W: its HTTP libraries need versions the skeleton's
    # lock does not have.
    COMPOSER_ALLOW_SUPERUSER=1 composer --working-dir=/opt/cic-skeleton require --dev -W \
        --no-interaction --no-progress --quiet laravel/boost; \
    php /opt/cic-skeleton/artisan list --raw | grep -q '^boost:mcp'; \
    rm -f /opt/cic-skeleton/.env; \
    rm -rf /opt/cic-skeleton/.git /root/.composer /tmp/*; \
    php /opt/cic-skeleton/artisan --version; \
    test -f /opt/cic-skeleton/public/index.php; \
    test ! -f /opt/cic-skeleton/.env

# The document root is /var/www/html/public and there is no supported way to
# move it above that. The exposure this prevents is not hypothetical: on a live
# aaPanel box we measured 31 mutually readable .env files, and one vhost whose
# root was the project directory rather than public/.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN set -eux; \
    sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf; \
    sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf; \
    a2enmod rewrite headers; \
    # Listen on 8080 so the container never needs a privileged port, which lets
    # us drop NET_BIND_SERVICE along with every other capability.
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:8080>/' /etc/apache2/sites-available/*.conf; \
    # Do not advertise the stack. Free information for an attacker, no value to us.
    printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' \
        > /etc/apache2/conf-available/hardening.conf; \
    a2enconf hardening

# Every request reaching this Apache came through the host's Caddy over HTTPS
# (Caddy redirects plain HTTP itself), so PHP is told so. Without it Laravel -
# which trusts no proxy by default and so ignores X-Forwarded-Proto - built
# every URL as http://: redirects, JSON and URLs handed to other services
# (a payment provider's return URL) all pointed at plain HTTP, hidden in HTML
# only because Cloudflare rewrites links there. With HTTPS=on and the
# original Host header (Caddy passes it through), Symfony derives https and
# port 443 for every generated URL, with no change to the customer's app.
RUN printf '%s\n' 'SetEnv HTTPS on' > /etc/apache2/conf-available/cic-https.conf && a2enconf cic-https

# Apache's OWN redirects (Laravel's trailing-slash rule, a directory without
# its slash) are absolute URLs built from Apache's idea of itself: they sent
# every site's visitors to http://<site>:8080/login, which never loads (found
# 2026-09-25). The name comes from the visitor's Host header; the scheme and
# port are the public ones, https on 443.
# The visitor's real address as PHP's REMOTE_ADDR (so Laravel's request()->ip()
# and per-IP rate limits see visitors, not the Docker gateway): the host's
# Caddy sends it as X-Real-IP, trusted only from the Docker networks, and the
# access log records it (%a).
RUN a2enmod remoteip && printf '%s\n' 'RemoteIPHeader X-Real-IP' 'RemoteIPInternalProxy 127.0.0.1 172.16.0.0/12' \
    > /etc/apache2/conf-available/cic-remoteip.conf && a2enconf cic-remoteip \
    && sed -ri 's/^(LogFormat "?)%h /\1%a /' /etc/apache2/apache2.conf && grep -q '^LogFormat "%a ' /etc/apache2/apache2.conf

# Inside the <VirtualHost *:8080>: set server-wide, the vhost's own port won.
RUN sed -ri 's!^(<VirtualHost \*:8080>)$!\1\n\tServerName https://localhost:443\n\tUseCanonicalName Off!' /etc/apache2/sites-available/000-default.conf \
    && grep -q 'ServerName https://localhost:443' /etc/apache2/sites-available/000-default.conf

# APP_DEBUG is forced off by the agent (docker run --env APP_DEBUG=false): a
# debug page on a public site shows visitors the values around a failure. A
# real environment variable beats .env in Laravel, but mod_php only puts it in
# $_SERVER - where Laravel reads first - when Apache is told to pass it on.
RUN printf '%s\n' 'PassEnv APP_DEBUG' > /etc/apache2/conf-available/cic-env.conf && a2enconf cic-env

# Belt and braces: even if a vhost were misconfigured to serve the project
# root, these never leave the container.
RUN printf '%s\n' \
    '<FilesMatch "^\\.env">' \
    '  Require all denied' \
    '</FilesMatch>' \
    '<FilesMatch "(composer\\.(json|lock)|package-lock\\.json|artisan)$">' \
    '  Require all denied' \
    '</FilesMatch>' \
    '<FilesMatch "(^\\.|\\.(sql|sqlite|sqlite3|db|log|bak|old|orig|swp|save|pem|key)$|^(auth\\.json|phpunit\\.xml)$)">' \
    '  Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/deny-secrets.conf && a2enconf deny-secrets

# Only the front controller runs, and .htaccess is ignored (the second security
# audit, 2026-09-25). A Laravel app has one entry point, public/index.php; any
# other PHP file reachable over HTTP - a webshell dropped in public/uploads, a
# second index.php in a subfolder, public/node_modules/x.php, the /x.php/a.exe
# path trick - is refused before PHP sees it, whether or not a scanner
# recognised it. .htaccess could make a .jpg run as PHP (AddType/SetHandler),
# so AllowOverride is None and Laravel's own public/.htaccess rules live here.
# Named zz- to load after the base image's docker-php.conf, which sets
# AllowOverride All on the same directory.
RUN printf '%s\n' \
    '<Directory ${APACHE_DOCUMENT_ROOT}>' \
    '  AllowOverride None' \
    '  Options -Indexes -MultiViews +FollowSymLinks' \
    '  RewriteEngine On' \
    '  RewriteCond %{HTTP:Authorization} .' \
    '  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]' \
    '  RewriteCond %{HTTP:x-xsrf-token} .' \
    '  RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]' \
    '  RewriteCond %{REQUEST_FILENAME} !-d' \
    '  RewriteCond %{REQUEST_URI} (.+)/$' \
    '  RewriteRule ^ %1 [L,R=301]' \
    '  RewriteCond %{REQUEST_FILENAME} !-d' \
    '  RewriteCond %{REQUEST_FILENAME} !-f' \
    '  RewriteRule ^ index.php [L]' \
    '</Directory>' \
    '<If "%{REQUEST_FILENAME} =~ /\.(php[0-9]?|phtml|pht|phar|phps|inc)$/i && %{REQUEST_FILENAME} != '"'"'${APACHE_DOCUMENT_ROOT}/index.php'"'"'">' \
    '  Require all denied' \
    '</If>' \
    > /etc/apache2/conf-available/zz-cic-exec.conf && a2enconf zz-cic-exec && apache2ctl -t

# Sane production PHP. display_errors off matters: a stack trace on a 500 leaks
# paths, queries and sometimes credentials to anyone who can trigger it.
RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'expose_php=Off'; \
      echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/zz-codeinchrome.ini

# The rootfs is mounted read-only at runtime; Apache still needs these.
# The start script (cic-start, beside this file) sizes Apache and runs the
# site's background processes; read it for the reasoning.
COPY cic-start /usr/local/bin/cic-start
RUN chmod 0755 /usr/local/bin/cic-start && sh -n /usr/local/bin/cic-start && command -v supervisord

# Apache sized to THIS container's memory. Prefork's default of up to 150
# workers is far more than a 256 MB - 2 GB container holds, and every worker
# may open a database connection: under a burst, the site failed with
# "max_user_connections" instead of queueing (measured by tests/load). At start
# the container reads its own memory limit and sets the worker count to fit -
# ~24 MB per busy worker after 64 MB for the rest - so excess requests wait in
# the listen queue rather than fail. The agent sets the site's database
# connection cap from the same formula (sites.WorkersFor), plus headroom.
RUN set -eux; \
    printf '%s\n' \
      '<IfModule mpm_prefork_module>' \
      '  StartServers 2' \
      '  MinSpareServers 1' \
      '  MaxSpareServers ${CIC_SPARE}' \
      '  ServerLimit ${CIC_WORKERS}' \
      '  MaxRequestWorkers ${CIC_WORKERS}' \
      '  MaxConnectionsPerChild 2000' \
      '</IfModule>' \
      > /etc/apache2/conf-available/cic-workers.conf; \
    a2enconf cic-workers; \
    CIC_WORKERS=10 CIC_SPARE=4 apache2ctl -t

VOLUME ["/var/www/html"]
EXPOSE 8080

# Healthy is any answer below 500, a redirect included - not followed: an app
# that sends / to its https login page was marked unhealthy for following it.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r '$c = stream_context_create(["http" => ["follow_location" => 0, "ignore_errors" => true, "timeout" => 4]]); @file_get_contents("http://127.0.0.1:8080/", false, $c); preg_match("#^HTTP/\\S+ (\\d+)#", $http_response_header[0] ?? "", $m); exit(($m[1] ?? 999) < 500 ? 0 : 1);'

CMD ["cic-start"]
