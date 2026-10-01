#!/bin/sh
set -e

if [ -n "$ADMIN_LOGIN" ] && [ -n "$ADMIN_PASSWORD" ]; then
    for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30; do
        if php /var/www/html/scripts/create_admin.php --if-not-exists; then
            break
        fi
        echo "waiting for db ($i/30)..." >&2
        sleep 2
    done || true
fi

exec apache2-foreground
