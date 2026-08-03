# WikiFlip — Apache + PHP webserver container
FROM php:8.3-apache-bookworm

# Enable rewrite, compression, expires; curl for healthchecks; zip for content backup
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j$(nproc) opcache zip \
    && a2enmod rewrite headers deflate expires \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# App lives in the default DocumentRoot
WORKDIR /var/www/html
COPY . /var/www/html/

# PHP upload limits + OPcache / realpath / zlib (see docker/php-uploads.ini)
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/99-wikiflip-uploads.ini
COPY docker/apache-performance.conf /etc/apache2/conf-available/wikiflip-performance.conf
RUN a2enconf wikiflip-performance

# Seed copy for empty volume mounts; keep pages writable by Apache
RUN cp -a /var/www/html/pages /var/www/html/pages.dist \
    && mkdir -p /var/www/html/pages \
    && chown -R www-data:www-data /var/www/html/pages /var/www/html/pages.dist \
    && chmod +x /var/www/html/docker/entrypoint.sh

# Default admin user (password via env / compose — not baked in)
ENV WIKIFLIP_ADMIN_USER=admin

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
