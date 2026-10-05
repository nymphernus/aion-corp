#!/bin/sh
set -e

# --- Stage 10: ожидание готовности БД ---
# mysqladmin ping до healthcheck-цикла create_admin: раньше миграции могли
# стартовать до того, как db принимал соединения.
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

# --- Stage 10: миграции схемы (идемпотентные, каждый старт) ---
php /var/www/html/scripts/migrate.php

# --- Админ: опционально, по env ---
if [ -n "$ADMIN_LOGIN" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php /var/www/html/scripts/create_admin.php --if-not-exists
fi

# --- Stage 10: демо-данные только по флагу SEED_DATA=1 ---
if [ "$SEED_DATA" = "1" ]; then
    echo "SEED_DATA=1: loading demo components..."
    php /var/www/html/scripts/seed_components.php --if-not-exists
fi

# Уборка осиротевших сборок при старте контейнера.
# Один проход вместо фонового цикла: контейнер и так перезапускается при
# деплое, а сборки старше часа всё равно переживают перезапуск. Повторные
# проходы нужны были бы в фоне, для этого есть cron.
# || true - ошибка уборки не должна мешать поднять apache.
php /var/www/html/scripts/cleanup_orphans.php --hours=1 || true

exec apache2-foreground