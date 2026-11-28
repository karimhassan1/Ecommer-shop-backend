FROM composer:2.8 AS base

WORKDIR /app

COPY . /app

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && php artisan octane:install -n --server=swoole \
    && chmod -R ug+rwX storage bootstrap/cache

#######################################################
# Production runtime
#######################################################

FROM nodesol/php-base:8.5-swoole AS app

WORKDIR /app

COPY --from=base /app /app

RUN printf '#!/bin/sh\nset -e\nphp artisan optimize:clear\nphp artisan optimize\n(while true; do php artisan queue:work --tries=3 --max-time=3600 || true; sleep 5; done) &\nexec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000\n' \
    > /usr/bin/entrypoint \
    && chmod +x /usr/bin/entrypoint

EXPOSE 8000

CMD ["/usr/bin/entrypoint"]
