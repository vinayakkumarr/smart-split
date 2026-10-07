# =======================================================
# Smart Split V2 – Production Container
# High-Performance Alpine Linux + PHP 8.3-FPM + Nginx
# =======================================================

FROM php:8.3-fpm-alpine

# Install system dependencies, Nginx, and gettext (for envsubst)
RUN apk add --no-cache \
    nginx \
    gettext \
    curl \
    libpng-dev \
    libwebp-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    ca-certificates

# Install PHP extensions required by Smart Split V2
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        fileinfo \
        opcache \
        gd

# Copy PHP production configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/smartsplit.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-smartsplit.conf

# Copy Nginx template configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf.template

# Set working directory
WORKDIR /var/www/html

# Copy application source files
COPY . /var/www/html

# Ensure directories, normalize line endings (prevents Windows CRLF bugs), and set permissions
RUN mkdir -p /run/nginx /var/log/nginx /var/www/html/storage/receipts \
    && sed -i 's/\r$//' /var/www/html/docker/entrypoint.sh \
    && chmod +x /var/www/html/docker/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

# Expose dynamic ports
EXPOSE 80 10000

# Start PHP-FPM and Nginx via entrypoint
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
