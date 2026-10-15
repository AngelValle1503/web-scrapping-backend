#!/usr/bin/env sh

set -eu

: "${PORT:=10000}"

envsubst '$PORT' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Free Render services do not support pre-deploy commands. Run outstanding
# migrations before accepting traffic so a fresh deployment has its schema.
php artisan migrate --force --no-interaction

php-fpm -D
exec nginx -g 'daemon off;'
