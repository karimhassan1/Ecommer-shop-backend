FROM  composer:2.8 AS base
WORKDIR /app
COPY . /app
RUN cp .env.example .env \
    && composer update --no-dev \
    && php artisan octane:install -n --server=swoole \
    && chmod -R 777 storage bootstrap/cache \
    && php artisan key:generate

#######################################################
# App
#######################################################
FROM nodesol/php-base:8.5-swoole as app
WORKDIR /app
RUN printf "\n\
php artisan optimize && \n\
php artisan route:cache && \n\
php artisan config:cache && \n\
php artisan lighthouse:cache \n\
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000\n\
" > /usr/bin/entrypoint \
    && chmod a+x /usr/bin/entrypoint
COPY --from=base /app /app
EXPOSE 8000
CMD [ "sh","-c", "/usr/bin/entrypoint"]

#######################################################
# Default
#######################################################
FROM app
