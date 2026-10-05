## Описание проекта

Интернет-магазин персональных компьютеров индивидуальной комплектации с конструктором для создания собственных сборок. Пользователи выбирают компоненты, настраивают сборки и оформляют заказы. Стек — PHP 8.1, MySQL 8.0, Apache, всё в Docker.

**Полный отчёт о состоянии проекта: [REPORT.md](REPORT.md)** — что было сделано, что не сделано и какие риски остались.

---

## Быстрый запуск

```bash
git clone https://github.com/nymphernus/aion-corp.git
cd aion-corp

cp .env.example .env      # обязательно поменяйте пароли
docker compose up -d --build
```

Откроется на **http://localhost:8080**.

Что происходит при старте:

- поднимается MySQL 8.0, `init.sql` импортируется при первом создании volume;
- Apache с PHP 8.1 ждёт готовности базы по healthcheck;
- `docker/entrypoint.sh` создаёт администратора из `ADMIN_LOGIN` и `ADMIN_PASSWORD`;
- затем запускается уборка осиротевших сборок старше часа — сборки, которые не добавили в избранное и не заказали.

| Сервис | Порт снаружи | Примечание |
|---|---|---|
| `php` (Apache) | 8080 | доступен всем интерфейсам |
| `db` (MySQL 8.0) | 3306 | привязан к `127.0.0.1`, наружу не слышен |

Остановить: `docker compose down`.

---

## Администратор

Создаётся автоматически при первом запуске из переменных `.env`:

| Переменная | По умолчанию в `.env.example` |
|---|---|
| `ADMIN_LOGIN` | `admin` |
| `ADMIN_PASSWORD` | `change_me_at_least_8_chars` |

Скрипт вызывается с `--if-not-exists`, поэтому повторные запуски не пересоздают и не сбрасывают пароль. Если `ADMIN_PASSWORD` пустой, админ не создаётся вовсе.

> **Важно:** пароль в `.env` хранится в открытом виде и нужен только для первичного создания. После первого запуска его лучше удалить из `.env`.

Пароль хранится в базе как bcrypt-хеш. Сменить его вручную:

```bash
docker compose exec php php -r 'echo password_hash("новый_пароль", PASSWORD_BCRYPT), PHP_EOL;'
```

Полученный хеш подставить:

```sql
UPDATE users SET user_pass = '<хеш>' WHERE user_login = 'admin';
```

Удалить администратора:

```bash
docker compose exec db mysql -uadmin -p shop_db -e "DELETE FROM users WHERE user_login = 'admin';"
```

Создать админа вручную, минуя `.env`:

```bash
docker compose exec -it php php /var/www/html/scripts/create_admin.php
```

---

## Переменные окружения

Значения по умолчанию — из `.env.example`.

| Переменная | Назначение | Значение в `.env.example` |
|---|---|---|
| `MYSQL_ROOT_PASSWORD` | пароль root в MySQL | `root_password_change_me` |
| `MYSQL_USER` | пользователь приложения | `admin` |
| `MYSQL_PASSWORD` | пароль пользователя | `db_password_change_me` |
| `MYSQL_DATABASE` | имя базы данных | `shop_db` |
| `ADMIN_LOGIN` | логин администратора | `admin` |
| `ADMIN_PASSWORD` | пароль администратора | `change_me_at_least_8_chars` |
| `DEBUG_SQL_COUNT` | непустое значение включает счётчик SQL-запросов | пусто, выключено |

Имя базы задаётся **одной** переменной `MYSQL_DATABASE` — `docker-compose.yml`
передаёт её и mysql-сервису (`MYSQL_DATABASE`), и приложению (`DB_NAME`).
Отдельной строки `DB_NAME` в `.env` намеренно нет: раньше обе были
захардкожены в `docker-compose.yml` по отдельности, из-за чего правка
`.env` ничего не меняла и базу пришлось бы переименовать в двух местах.

| Параметр | Значение | Где используется |
|---|---|---|
| имя базы | `${MYSQL_DATABASE:-shop_db}` | `MYSQL_DATABASE` у `db`, `DB_NAME` у `php` |
| хост БД | `db` | `DB_HOST` у `php` |

`connect.php` читает `MYSQL_DATABASE`, затем `DB_NAME`, и только потом
падает на дефолт `shop_db` — то есть приложение работает и когда задана
только одна из переменных.

> При запуске **без** Docker нужно задать `MYSQL_DATABASE` (или `DB_NAME`),
> `DB_HOST`, `DB_USER`, `DB_PASSWORD` вручную — в `.env.example` есть только
> `MYSQL_DATABASE`, а без остальных переменных приложение вернёт 500.

Переименование базы на уже существующем томе требует двух шагов — MySQL
создаёт базу и выдаёт права только при первом `up`, когда переменная
`MYSQL_DATABASE` ещё совпадает с текущим именем:

```bash
docker compose exec -T db mysql -uroot -p'ПАРОЛЬ' \
  -e "CREATE DATABASE shop_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
docker compose exec -T db mysql -uroot -p'ПАРОЛЬ' \
  -e "GRANT ALL PRIVILEGES ON shop_db.* TO 'admin'@'%'; FLUSH PRIVILEGES"
```

Без `GRANT` приложение падает с `Access denied for user 'admin'@'%' to
database 'shop_db'`. Данные переносятся дампом внутри контейнера (см.
`AGENT.md`) — иначе русский текст проходит через кодировку консоли.

### Счётчик SQL-запросов

Пригодится, чтобы увидеть, сколько запросов делает страница. Значение читается при старте контейнера, поэтому после правки `.env` нужен пересозпуск:

```bash
# в .env:  DEBUG_SQL_COUNT=1
docker compose up -d php
curl -s http://localhost:8080/assembly.php?init=1 | tail -3
# внизу появится комментарий: <!-- db_prepare calls: 10 -->
```

---

## Тесты

Тесты ходят по HTTP на живой сайт, поэтому контейнеры должны быть подняты.

```bash
docker compose exec php php /var/www/html/tests/phpunit.phar \
  -c /var/www/html/tests/phpunit.xml --testdox
```

20 тестов, 89 утверждений. Файл `phpunit.phar` в репозиторий не попадает — после клонирования его нужно положить в `src/tests/` самостоятельно:

```bash
curl -sL https://phar.phpunit.de/phpunit-10.phar -o src/tests/phpunit.phar
```

Альтернатива — поставить зависимости через Composer (`composer.json` объявляет PHPUnit 10 и PHPStan):

```bash
composer install
vendor/bin/phpunit -c src/tests/phpunit.xml
```

**Требование к базе:** в `assembly` должно быть не меньше трёх сборок с `id` 1–3 — они лежат в `init.sql`. Тесты создают сборки с `id > 3` и удаляют их за собой: после прогона должно остаться ровно 3 сборки.

Отдельная проверка данных каталога, не входящая в PHPUnit:

```bash
docker compose exec php php /var/www/html/scripts/enrich_components.php --selftest
```

---

## Обслуживание

Уборка сборок, которые никто не сохранил и не заказал:

```bash
docker compose exec php php /var/www/html/scripts/cleanup_orphans.php --dry-run   # только показать
docker compose exec php php /var/www/html/scripts/cleanup_orphans.php --hours=6    # удалить старше 6 часов
docker compose exec php php /var/www/html/scripts/cleanup_orphans.php --help
```

По расписанию не выполняется — только один раз при старте контейнера. Cron не настроен.

> **Важно:** `init.sql` применяется только при создании volume БД. После изменения схемы нужен пересозданный volume: `docker compose down -v && docker compose up -d --build` — **все данные будут удалены**. Отдельных миграций схемы в проекте нет.

---

## Разработка

Dev-режим с монтированием исходников:

```bash
docker compose up -d
```

`docker-compose.override.yml` монтирует `./src` в `/var/www/html`, так что правки в файлах видны сразу, без пересборки образа. Там же включается Xdebug с `client_host=host.docker.internal` — полезно для отладки из IDE на хосте.

### Статический анализ

`composer.json` объявляет PHPStan, конфигурация — `phpstan-baseline.neon` (42 строки). Инструмент в репозиторий не установлен:

```bash
composer install
vendor/bin/phpstan analyse --level=5
```

> **О путях:** каталог `src/` — это корень сайта. На хосте он так и называется `src/`, а внутри контейнера те же файлы лежат от `/var/www/html/`, поэтому в командах `docker compose exec` путь начинается с `/var/www/html/`. Команды, выполняемые на хосте (`composer`, `curl -o`, `vendor/bin/...`), наоборот, используют `src/`.

---

## Структура

```
src/
├── index.php          главная
├── assembly.php       конфигуратор и страница сборки
├── profile.php        профиль покупателя
├── admin.php          админка
├── modules/           connect, csrf, auth, components, configurator, pagination
├── partials/          header, footer, сайдбар профиля, модалки
├── admin/             вкладки админки
├── validation/        логин, регистрация, сброс пароля, выход
├── scripts/           CLI: сид, обогащение, миграция, создание админа, уборка
├── tests/             PHPUnit
└── assets/            css, js, images
init.sql               схема и данные каталога, 230 компонентов
```

Подробное описание архитектуры, разбора по слоям и состояния безопасности — в [REPORT.md](REPORT.md).

---

## Скриншот

<img src="https://user-images.githubusercontent.com/103174654/229752211-483a3cf6-5fd4-4694-bb82-413255d884c6.png" alt="img_1">

> Скриншот сделан до рефакторинга интерфейса. Актуальный вид — на локальном запуске.
