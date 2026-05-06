FROM composer:2 AS backend-vendor

WORKDIR /app/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY backend ./
RUN composer dump-autoload --no-dev --optimize


FROM node:22-alpine AS frontend-build

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend ./
RUN npm run build


FROM php:8.3-fpm-bookworm AS production

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=backend-vendor /app/backend ./
COPY --from=frontend-build /app/frontend/dist/ ./public/

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/php-fpm-www.conf /usr/local/etc/php-fpm.d/zz-render.conf
COPY docker/start.sh /usr/local/bin/render-start

RUN chmod +x /usr/local/bin/render-start \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && rm -f /etc/nginx/sites-enabled/default

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    PORT=10000

EXPOSE 10000

CMD ["render-start"]
