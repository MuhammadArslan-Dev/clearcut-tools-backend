# One image for both services in docker-compose.yaml:
#   - web:    serves HTTP through FrankenPHP (Caddyfile in this repo)
#   - worker: same image, overridden command = `php artisan queue:work`
#
# Replaces the Nixpacks build for clearcut-tools. The Vite assets are built
# in their own stage so the final image has no Node toolchain.

FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
ARG VITE_APP_NAME="ClearCutoff Tools"
ENV VITE_APP_NAME=$VITE_APP_NAME
RUN npm run build

FROM dunglas/frankenphp:1-php8.2 AS app

# pdo_pgsql: tools' database is PostgreSQL on Coolify.
RUN install-php-extensions pdo_pgsql bcmath intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=assets /build/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY Caddyfile /etc/frankenphp/Caddyfile

ENV SERVER_NAME=:80
EXPOSE 80
