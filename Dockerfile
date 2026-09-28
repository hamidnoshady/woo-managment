# Production image for the Woo Management web app.
#
# Plain PHP 8.2 + Apache (mod_php), mirroring docker-compose.dev.yml but as a
# self-contained image suitable for GHCR. The app has no build step (no
# Composer, no bundler), so this just installs the PHP extensions the code
# uses and copies the source under the Apache document root.
#
# DB credentials are NOT baked in: includes/config.php is generated at
# container start from DB_* environment variables (see docker-entrypoint.sh),
# matching the app's "config.php holds only the DB connection" design.
FROM php:8.2-apache

# System libs needed to build the PHP extensions below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
        curl \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache: enable rewrite + headers (public/.htaccess relies on mod_headers)
# and point the document root at public/, as the app expects.
RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/app.conf \
    && a2enconf app

# Production-friendly PHP settings (mirrors public/.user.ini intent).
RUN { \
        echo 'display_errors = Off'; \
        echo 'display_startup_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'expose_php = Off'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Copy application source. .dockerignore keeps VCS/dev files out.
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://localhost/login.php >/dev/null || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
