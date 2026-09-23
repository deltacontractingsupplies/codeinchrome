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
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libonig-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli zip gd intl bcmath opcache exif pcntl; \
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
    for ext in pdo_mysql mysqli zip gd intl bcmath exif pcntl; do \
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

# Belt and braces: even if a vhost were misconfigured to serve the project
# root, these never leave the container.
RUN printf '%s\n' \
    '<FilesMatch "^\\.env">' \
    '  Require all denied' \
    '</FilesMatch>' \
    '<FilesMatch "(composer\\.(json|lock)|package-lock\\.json|artisan)$">' \
    '  Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/deny-secrets.conf && a2enconf deny-secrets

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

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);'

CMD ["cic-start"]
