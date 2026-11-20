# syntax=docker/dockerfile:1

# --- PHP 8.4 with the app's extensions ---
FROM php:8.4-fpm-trixie AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath curl gd intl mbstring opcache pcntl pdo_mysql xml xsl zip
WORKDIR /var/www/html

# --- Composer dependencies, no dev packages ---
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && php artisan livewire:publish --assets

# --- Front-end assets ---
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# --- Runtime: Nginx + PHP-FPM under supervisord ---
FROM base
RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx supervisor \
 && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data --from=vendor /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --chown=www-data:www-data --from=vendor /var/www/html/public/vendor ./public/vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN rm -f .env \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache

# tinker (PsySH) writes its config under $HOME, which www-data cannot write
ENV XDG_CONFIG_HOME=/tmp

EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
