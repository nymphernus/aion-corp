#!/bin/sh
set -e

echo "waiting for db..."
i=1
while [ $i -le 30 ]; do
    if php -r 'exit(mysqli_get_server_version(new mysqli(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"))) > 0 ? 0 : 1);' 2>/dev/null; then
        break
    fi
    echo "  db not ready ($i/30)..." >&2
    i=$((i + 1))
    sleep 2
done

php /var/www/html/scripts/migrate.php

if [ -n "$ADMIN_LOGIN" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php /var/www/html/scripts/create_admin.php --if-not-exists
fi

if [ "$SEED_DATA" = "1" ]; then
    echo "SEED_DATA=1: loading demo components..."
    php /var/www/html/scripts/seed_components.php --if-not-exists
fi

php /var/www/html/scripts/cleanup_orphans.php --hours=1 || true

exec apache2-foreground