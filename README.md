## Описание проекта
Данный проект представляет собой интернет-магазин персональных компьютеров индивидуальной комплектации с конструктором для создания собственных сборок. Пользователи могут выбирать компоненты, настраивать свои сборки и оформлять заказы. Проект реализован на PHP с использованием MySQL для хранения данных.

## Алгоритм установки и запуска проекта

### Быстрый запуск (одна команда)

```bash
# Клонирование репозитория
git clone https://github.com/nymphernus/aion-corp.git
cd aion-corp

# Копируем переменные окружения
cp .env.example .env

# Запуск приложения
docker compose up -d --build
```

Это создаст:
- Веб-сервер Apache с PHP (порт 8080)
- MySQL с предустановленной базой данных (порт 3306)
- Автоматически импортирует init.sql при первом запуске

### Первый запуск

При первом запуске (`docker compose up -d`) администратор создаётся **автоматически** на основе переменных окружения из `.env`:

- `ADMIN_LOGIN` — логин администратора (по умолчанию `admin`)
- `ADMIN_PASSWORD` — пароль администратора (по умолчанию `change_me_at_least_8_chars`)

> **Важно:** После первого запуска удалите `ADMIN_PASSWORD` из `.env` и выполните `docker compose up -d`, чтобы предотвратить повторное создание админа.

#### Смена пароля администратора

1. Подключитесь к базе данных:
   ```bash
   docker compose exec db mysql -uadmin -p aion_bd
   ```
2. Выполните SQL-запрос:
   ```sql
   UPDATE users SET user_pass = '<новый_хеш>' WHERE user_login = 'admin';
   ```
   Хеш можно получить через PHP:
   ```php
   echo password_hash('новый_пароль', PASSWORD_BCRYPT);
   ```

#### Удаление администратора

```bash
docker compose exec db mysql -uadmin -p aion_bd -e "DELETE FROM users WHERE user_login = 'admin';"
```

### Проверка работы

```bash
# Главная страница
curl http://localhost:8080/

# Профиль
curl http://localhost:8080/profile.php

# Сборка
curl http://localhost:8080/assembly.php?init=1
```

### Остановка

```bash
docker compose down
```

> **Важно:** `init.sql` применяется только при создании volume БД. При обновлении
> схемы БД нужен пересозданный volume: `docker compose down -v && docker compose up -d --build`
> (все данные БД будут удалены). TODO Stage 5: миграции БД вместо `down -v`.

### Переменные окружения

| Переменная | Описание | По умолчанию |
|------------|----------|--------------|
| `MYSQL_ROOT_PASSWORD` | Пароль root для MySQL | `change_me_root` |
| `MYSQL_USER` | Пользователь MySQL | `change_me_user` |
| `MYSQL_PASSWORD` | Пароль пользователя MySQL | `change_me_password` |
| `MYSQL_DATABASE` | Имя базы данных | `aion_bd` |
| `DB_HOST` | Хост базы данных | `db` |
| `DB_NAME` | Имя базы данных | `aion_bd` |

> **Примечание:** При запуске без Docker нужно экспортировать `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` вручную (см. `.env.example`). Иначе приложение вернёт 500 ошибку.

## Технологии
1. **PHP 8.1**: Основной серверный язык.
2. **MySQL 8.0**: Хранение данных (товары, заказы, пользователи)
3. **Apache** — веб-сервер
4. **HTML/CSS/JavaScript** — фронтенд, включая динамическую работу конструктора
5. **Docker** — контейнеризация для простоты развёртывания

## Скриншот
<img src="https://user-images.githubusercontent.com/103174654/229752211-483a3cf6-5fd4-4694-bb82-413255d884c6.png" alt="img_1">

## Разработка

### Dev-режим с volume

```bash
docker compose up -d
```

Файлы из `src/` автоматически монтируются в контейнер.

### Тесты

```bash
composer install
vendor/bin/phpunit
```

### Статический анализ

```bash
vendor/bin/phpstan analyse --level=5
```
