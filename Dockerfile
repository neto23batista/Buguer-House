FROM dunglas/frankenphp:1-php8.4-alpine@sha256:f81693fa548684eb12964eeab78d86325b75c5cd91289c09ffc4dd4404a641e5 AS runtime

WORKDIR /app
RUN install-php-extensions gd pdo_mysql \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-production.ini /usr/local/etc/php/conf.d/burger-house.ini
COPY Caddyfile /etc/caddy/Caddyfile
COPY app ./app
COPY public ./public
COPY database ./database
COPY bin ./bin

ENV PORT=80 APP_ENV=production SESSION_DRIVER=mysql SESSION_COOKIE_SECURE=1
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=8s --start-period=15s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("PORT") ?: "80") . "/health.php") === false ? 1 : 0);'
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
