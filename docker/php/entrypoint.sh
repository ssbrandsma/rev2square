#!/bin/sh
# Dev-only entrypoint: keep bind-mounted writable dirs usable by php-fpm.
set -e
if [ -d /var/www/storage ] && [ -d /var/www/bootstrap/cache ]; then
    chmod -R 777 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
fi
exec "$@"
