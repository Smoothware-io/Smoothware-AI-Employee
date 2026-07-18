# Production image for the Smoothware AI Employee CRM.
#
# FrankenPHP rather than nginx+php-fpm+supervisor: one process, no separate
# web-server config, and it is what Laravel's own deployment docs target. The
# same image runs the web app, the queue worker, and the scheduler — they differ
# only by the command docker-compose gives them, so there is exactly one thing to
# build and one thing to patch when a CVE lands.

# --- Stage 1: front-end assets -------------------------------------------------
# Filament ships its own compiled assets, but this app has Vite entrypoints of its
# own (resources/css/app.css, resources/js/app.js). Without `vite build` the panel
# loads unstyled — and Filament ships no general utility CSS to fall back on.
FROM node:22-alpine AS assets

WORKDIR /app

# No package-lock.json in this repo, so `npm install` rather than `npm ci`.
# Less reproducible; the alternative is a build that cannot run at all.
COPY package.json ./
RUN npm install

COPY vite.config.js ./
COPY resources ./resources

# NOTE: vite.config.js pulls fonts from Bunny at build time. This needs network
# access during the build — fine on Dokploy, a surprise in an air-gapped builder.
RUN npm run build

# --- Stage 2: PHP dependencies -------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts: artisan post-install hooks need the full app tree, which is not
# here yet. They run in the final stage.
# --no-dev: Pest, Pint and the debug tooling have no business in production.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# --- Stage 3: runtime ----------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4

# pdo_pgsql  — the database
# intl       — Laravel/Filament formatting; Carbon and number formatting need it
# zip, gd    — Filament file and image handling
# pcntl      — the queue worker's graceful-shutdown signals. Without it a deploy
#              kills a worker mid-job instead of letting it finish the current one.
# opcache    — the single biggest PHP performance win, and free
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        gd \
        exif \
        bcmath \
        pcntl \
        opcache

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Now the full tree is present: optimised autoloader plus the package-discovery
# scripts skipped in stage 2.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# Laravel needs to write here; FrankenPHP runs as www-data.
RUN mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
RUN chmod +x /usr/local/bin/entrypoint.sh

# Traefik (Dokploy's proxy) terminates TLS and forwards plain HTTP. Without this,
# FrankenPHP tries to get its own certificate through Caddy's auto-HTTPS, fails
# behind the proxy, and serves nothing. This is the most common FrankenPHP-behind-
# a-proxy mistake and it presents as "the site is just down".
ENV SERVER_NAME=:80

# Deliberately NOT setting FRANKENPHP_CONFIG="worker ...": worker mode requires
# laravel/octane, which is not installed. Enabling it without Octane boots a
# worker that cannot bootstrap the app. Classic mode is correct here — and at this
# traffic level the difference is unmeasurable anyway.

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
