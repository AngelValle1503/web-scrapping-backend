#!/usr/bin/env sh

set -eu

: "${PORT:=10000}"

envsubst '$PORT' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Render generates this secret as raw base64. Laravel expects the base64: prefix.
case "${APP_KEY:-}" in
  base64:*) ;;
  '') ;;
  *) export APP_KEY="base64:${APP_KEY}" ;;
esac

# Free Render services do not support pre-deploy commands. Run outstanding
# migrations before accepting traffic so a fresh deployment has its schema.
php artisan migrate --force --no-interaction

php-fpm -D
exec nginx -g 'daemon off;'
