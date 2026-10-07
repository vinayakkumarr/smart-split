#!/bin/sh
set -e

# Default to port 80 if PORT is not set (Render passes $PORT, e.g. 10000)
export PORT="${PORT:-80}"

# Ensure required run & log directories exist for Nginx
mkdir -p /run/nginx /var/log/nginx /var/www/html/storage/receipts

# Substitute PORT in Nginx configuration template
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Set permissions on storage directory
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Start PHP-FPM in background daemon mode
php-fpm -D

# Start Nginx in foreground mode
exec nginx -g "daemon off;"
