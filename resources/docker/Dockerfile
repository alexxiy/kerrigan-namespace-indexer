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
    # The signing key checked against its fingerprint, as in the runtime stage below.
    keyfprs() { gpg --show-keys --with-colons "$1" | awk -F: '$1 == "pub" {p = 1; next} p && $1 == "fpr" {print $10; p = 0}' | LC_ALL=C sort | tr '\n' ' '; }; \
    checkkey() { [ "$(keyfprs "$1")" = "$2 " ] || { echo "unexpected signing key(s) in $1: $(keyfprs "$1")" >&2; exit 1; }; }; \
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury.gpg; \
    checkkey /usr/share/keyrings/sury.gpg 15058500A0235D97F5D10063B188E2B695BD4743; \
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
    # Checked against the installer's SHA-384 that Composer publishes on a second host
    # (composer.github.io), as its own install guide does: a download altered or cut short on
    # the way stops the build here instead of running.
    composer_sig="$(curl -fsSL https://composer.github.io/installer.sig)"; \
    echo "${composer_sig}  /tmp/composer-setup.php" | sha384sum -c -; \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; \
    rm -rf /var/lib/apt/lists/* /tmp/composer-setup.php /root/.gnupg

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
ARG RR_PROXY_VERSION=v0.1.1
# The SHA-256 of that release's rr-proxy.gz, as its SHA256SUMS asset gives it. The download
# is checked against it, so a release asset replaced or corrupted after the fact stops the
# build. Raised together with RR_PROXY_VERSION.
ARG RR_PROXY_SHA256=429eef657ff6428d48b81ff14cb06aeab185d84c084228bc697a9b54ce535959
ARG RR_PROXY_REPO=https://github.com/alexxiy/kerrigan-namespace-rr-proxy


# Debian trixie ships nginx 1.26.3; the current stable branch is 1.30.x and carries
# security fixes not backported here. Pinned to an exact release rather than floating, so a
# rebuild cannot silently cross into a new branch; a security release of the branch is taken
# by raising it here (1.30.6 fixes five CVEs of 1.30.4).
ARG NGINX_VERSION=1.30.6-1~trixie

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
    # Each signing key is checked against the fingerprints its publisher gives (sury.org;
    # dev.mysql.com, "Signature Checking"; nginx.org, "Linux packages"): every key in the file
    # and nothing else, since apt trusts each key of a keyring, so one added to the file would
    # sign as well. A key fetched over HTTPS is only as good as that host; the fingerprint is
    # what pins it. A key renewed under a new fingerprint stops the build here: check the new
    # one against the publisher's page, then raise it.
    keyfprs() { gpg --show-keys --with-colons "$1" | awk -F: '$1 == "pub" {p = 1; next} p && $1 == "fpr" {print $10; p = 0}' | LC_ALL=C sort | tr '\n' ' '; }; \
    checkkey() { [ "$(keyfprs "$1")" = "$2 " ] || { echo "unexpected signing key(s) in $1: $(keyfprs "$1")" >&2; exit 1; }; }; \
    \
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury.gpg; \
    checkkey /usr/share/keyrings/sury.gpg 15058500A0235D97F5D10063B188E2B695BD4743; \
    echo "deb [signed-by=/usr/share/keyrings/sury.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/sury.list; \
    \
    # The -2023 key EXPIRED on 2025-10-22 and apt then rejects the repo outright. This is
    # the same key renewed (fingerprint BCA43417C3B485DD, valid to 2027-10) — when it
    # lapses again, the fix is the next RPM-GPG-KEY-mysql-<year> file, not disabling
    # signature checking.
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o /tmp/mysql.asc; \
    checkkey /tmp/mysql.asc BCA43417C3B485DD128EC6D4B7B3B788A8D3785C; \
    gpg --dearmor -o /usr/share/keyrings/mysql.gpg /tmp/mysql.asc; \
    # The 9.7 LTS line (8.4 LTS up to v1.1.0). A data directory made by 8.4 is upgraded in
    # place on the first start under 9.7, and 8.4 cannot open it after that: going back to an
    # older image means a new mysql_data volume and a sync from scratch.
    echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/debian/ trixie mysql-9.7-lts" \
        > /etc/apt/sources.list.d/mysql.list; \
    \
    # Three keys in one file: the current one and the two nginx.org names for its next ones.
    curl -fsSL https://nginx.org/keys/nginx_signing.key -o /tmp/nginx.asc; \
    checkkey /tmp/nginx.asc "573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62 8540A6F18833A80E9C1653A42FD21310B49F6B46 9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3"; \
    gpg --dearmor -o /usr/share/keyrings/nginx.gpg /tmp/nginx.asc; \
    rm -f /tmp/mysql.asc /tmp/nginx.asc; \
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
    echo "${RR_PROXY_SHA256}  /tmp/rr-proxy.gz" | sha256sum -c -; \
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
    rm -rf /var/lib/apt/lists/* /var/lib/mysql /var/log/mysql /root/.gnupg; \
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
    # resources/ too: the API writes its anchored-root cache and lock there, and
    # indexer.php its resources/sync.lock; both run as www-data (see indexer-cron).
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
