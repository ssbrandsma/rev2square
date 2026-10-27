#!/bin/sh
# Production entrypoint: build Laravel caches from the runtime environment,
# then exec the service command (php-fpm, queue:work, or schedule:work).
#
# Migrations are deliberately NOT run here: queue/scheduler containers share
# this entrypoint and concurrent migrates would race. Migrate explicitly
# during deploy (see docs/DEPLOYMENT.md).
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
