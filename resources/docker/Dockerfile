# Kerrigan NameSpace Indexer — single-container appliance.
#
# Everything an independent indexer node needs runs here: MySQL, the rr-proxy SMT daemon,
# PHP-FPM, nginx and cron. That is deliberately not the "one process per container"
# pattern. The point of this image is that a third party can verify the registrar by
# running ONE command, and the value of that verification comes from how many people
# actually do it — a five-service compose topology would cost more adopters than the
# architectural purity is worth here.
#
# The usual objection is handled rather than ignored: the entrypoint supervises every
# service and exits if any one of them dies, so the container never sits "up" with a dead
# indexer inside it.
#
# ---------------------------------------------------------------------------------------
# Stage 1: composer dependencies.
#
# Separated so composer and unzip never reach the runtime image, and so editing
# application code does not re-resolve dependencies.
# ---------------------------------------------------------------------------------------
FROM debian:13-slim AS vendor

ARG DEBIAN_FRONTEND=noninteractive
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip; \
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/sury.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/sury.list; \
    apt-get update; \
    # composer.json declares ext-mysqli, ext-sodium, ext-bcmath and ext-ctype as platform
    # requirements, and composer refuses to resolve without them present — so this stage
    # needs the same extension set as the runtime, not just enough to run composer itself.
    # Installing them beats --ignore-platform-reqs, which would defer a genuine mismatch
    # to runtime instead of catching it here.
    apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-mysql php8.5-bcmath php8.5-mbstring php8.5-xml php8.5-curl php8.5-gmp; \
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php; \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; \
    rm -rf /var/lib/apt/lists/* /tmp/composer-setup.php

WORKDIR /build
# Manifests only, so this layer is reused whenever application code changes but the
# dependency set does not.
# composer.lock is committed, so two builds of the same commit resolve to the same
# dependency set — worth having for a component whose whole purpose is deterministic
# replication. The glob keeps the lockfile optional anyway, so a context without one still
# builds (composer then resolves fresh).
COPY composer.json composer.lock* ./
# --prefer-dist rather than --prefer-source: dist fetches one archive per package instead
# of cloning full histories, which is faster and drops git from the dependency set.
RUN set -eux; \
    composer install --no-dev --no-scripts --no-interaction \
        --prefer-dist --optimize-autoloader; \
    rm -rf /root/.composer /root/.cache

# ---------------------------------------------------------------------------------------
# Stage 2: runtime.
# ---------------------------------------------------------------------------------------
FROM debian:13-slim

LABEL org.opencontainers.image.title="Kerrigan NameSpace Indexer" \
      org.opencontainers.image.description="Independent indexer node for Kerrigan NameSpace" \
      org.opencontainers.image.source="https://github.com/alexxiy/kerrigan-namespace-indexer" \
      org.opencontainers.image.licenses="AGPL-3.0-only"

# Pinned: the daemon and the on-chain vKey must come from the same protocol generation.
# Floating this to "latest" would let a rebuild change the tree implementation underneath
# an already-synced database.
ARG RR_PROXY_VERSION=v0.1.0
ARG RR_PROXY_REPO=https://github.com/alexxiy/kerrigan-namespace-rr-proxy


# Debian trixie ships nginx 1.26.3; the current stable branch is 1.30.x and carries
# security fixes not backported here. Pinned to the minor line rather than floating, so a
# rebuild cannot silently cross into a new branch.
ARG NGINX_VERSION=1.30.4-1~trixie

ARG DEBIAN_FRONTEND=noninteractive

# One layer for every package, with the apt lists dropped in the same step — a later
# `apt-get clean` cannot shrink anything, because the earlier layer still carries them.
#
# MySQL, not MariaDB: the schema uses utf8mb4_0900_ai_ci (a MySQL 8.0+ collation MariaDB
# does not implement) and crash recovery needs MySQL's XA_RECOVER_ADMIN privilege. This is
# not a preference — MariaDB cannot import the schema as written.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    \
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/sury.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/sury.list; \
    \
    # The -2023 key EXPIRED on 2025-10-22 and apt then rejects the repo outright. This is
    # the same key renewed (fingerprint BCA43417C3B485DD, valid to 2027-10) — when it
    # lapses again, the fix is the next RPM-GPG-KEY-mysql-<year> file, not disabling
    # signature checking.
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 \
        | gpg --dearmor -o /usr/share/keyrings/mysql.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/debian/ trixie mysql-8.4-lts" \
        > /etc/apt/sources.list.d/mysql.list; \
    \
    curl -fsSL https://nginx.org/keys/nginx_signing.key \
        | gpg --dearmor -o /usr/share/keyrings/nginx.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] https://nginx.org/packages/debian trixie nginx" \
        > /etc/apt/sources.list.d/nginx.list; \
    \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        mysql-server \
        # No php8.5-sodium: from 8.5 the Sury build compiles sodium INTO the binary, so
        # no such package exists and asking for it fails the whole install. Verified
        # loaded, and sodium_crypto_sign_verify_detached exercised, at image build time.
        php8.5-fpm php8.5-cli php8.5-mysql php8.5-bcmath \
        php8.5-xml php8.5-mbstring php8.5-gmp php8.5-curl \
        "nginx=${NGINX_VERSION}" \
        cron logrotate \
        gzip procps; \
    \
    curl -fsSL "${RR_PROXY_REPO}/releases/download/${RR_PROXY_VERSION}/rr-proxy.gz" \
        -o /tmp/rr-proxy.gz; \
    mkdir -p /opt/rr-proxy; \
    gunzip -c /tmp/rr-proxy.gz > /opt/rr-proxy/rr-proxy; \
    chmod 0755 /opt/rr-proxy/rr-proxy; \
    rm -f /tmp/rr-proxy.gz; \

    \
    # Assert the crypto the indexer cannot run without is actually present. Better to
    # fail the build than to ship an image that starts and then rejects every command.
    php8.5 -r 'foreach (["sodium","mysqli","bcmath","mbstring","gmp","curl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } } $kp = sodium_crypto_sign_keypair(); $sig = sodium_crypto_sign_detached("x", sodium_crypto_sign_secretkey($kp)); exit(sodium_crypto_sign_verify_detached($sig, "x", sodium_crypto_sign_publickey($kp)) ? 0 : 1);'; \
    \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/* /var/lib/mysql /var/log/mysql; \
    rm -f /etc/nginx/sites-enabled/default /etc/php/8.5/fpm/pool.d/www.conf

COPY resources/docker/nginx.conf        /etc/nginx/nginx.conf
COPY resources/docker/php-fpm.conf      /etc/php/8.5/fpm/pool.d/indexer.conf
COPY resources/docker/my.cnf            /etc/mysql/conf.d/zz-indexer.cnf
COPY resources/docker/rr-proxy.toml     /etc/rr-proxy.toml
COPY resources/docker/logrotate-indexer /etc/logrotate.d/indexer
COPY resources/docker/indexer-cron      /etc/cron.d/indexer-sync

# Application code. `.dockerignore` keeps deployment configs, vendor/ and .git out — see
# the comments there; this COPY is what used to embed production credentials in the image.
WORKDIR /opt/kerrigan-name-indexer
COPY . /opt/kerrigan-name-indexer
COPY --from=vendor /build/vendor /opt/kerrigan-name-indexer/vendor


RUN set -eux; \
    # cron silently ignores a crontab that is group- or world-writable.
    chmod 0644 /etc/cron.d/indexer-sync /etc/logrotate.d/indexer; \
    # `cd` first: indexer.php requires ./vendor/autoload.php relatively, and cron runs
    # with a different cwd. The systemd units in resources/contrib set WorkingDirectory=
    # for the same reason, so this keeps the container consistent with them.
    printf '#!/bin/sh\ncd /opt/kerrigan-name-indexer || exit 1\nexec php indexer.php "$@"\n' > /usr/local/bin/indexer; \
    chmod 0755 /usr/local/bin/indexer; \
    mkdir -p /var/log/indexer /opt/rr-proxy/db /run/php /run/mysqld; \
    # resources/ too: the API writes its anchored-root cache and lock there as
    # www-data. indexer.php already writes resources/sync.lock, but cron runs it as
    # root, so this directory had never needed to be writable by the web user.
    chown -R www-data:www-data /var/log/indexer /opt/rr-proxy \
        /opt/kerrigan-name-indexer/resources; \
    chown -R mysql:mysql /run/mysqld

COPY resources/docker/configure.php  /usr/local/lib/configure.php
COPY resources/docker/entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh

EXPOSE 80

# Reports the indexer's own view of itself rather than "a port is open": /v1.0/info
# answers 200 only when PHP, MySQL and the rr-proxy socket are all responding.
# The API answers HTTP 200 even for errors and reports failure in the body, so `curl -f`
# alone reports healthy while every request is failing — it did exactly that during
# testing. Grep for the success envelope instead.
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS http://127.0.0.1/v1.0/info | grep -q '"response"' || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
