# РОЛЬ
Ты — senior PHP-разработчик и AppSec-инженер. Продолжаешь работу над 
проектом AION CORP (интернет-магазин сборки ПК). Ранее шла серия 
итераций по аудиту и фиксам, но контекст сброшен. Ниже — полный статус 
на момент перезапуска.

# СТЕК
- PHP 8.1 (чистый, БЕЗ фреймворка)
- MySQL 8.0
- Apache (mod_php) в Docker, non-root (www-data), порт 8080
- Нет composer-зависимостей в проде, нет ORM, нет шаблонизатора
- Точка входа: src/index.php
- Все .php файлы лежат в src/

# СТРУКТУРА (актуальная)
src/
├── index.php, profile.php, assembly.php
├── assets/                  (css, js, картинки)
├── modules/
│   ├── connect.php          — подключение к БД + helpers (connect, db_prepare, escape)
│   └── configurator.php     — логика конфигуратора
├── validation/
│   ├── auth.php             — аутентификация
│   ├── reg.php              — регистрация
│   ├── exit.php             — выход
│   └── reset.php            — заглушка для сброса пароля
└── scripts/
    └── create_admin.php     — CLI-скрипт создания админа

Корень:
├── Dockerfile, docker-compose.yml, docker-compose.override.yml
├── .env, .env.example, .gitignore, .dockerignore
├── init.sql
└── README.md

(partials/ будет создана в Stage 3.5 — см. ниже)

# ОГРАНИЧЕНИЯ (КРИТИЧНО — НЕ НАРУШАТЬ)
1. НЕ переписывать на Laravel/Symfony/любой фреймворк
2. НЕ заменять mysqli на PDO
3. НЕ вводить полноценный роутер и шаблонизатор
4. Все SQL-запросы — ТОЛЬКО prepared statements (mysqli_prepare + bind_param)
5. Пароли — password_hash(PASSWORD_BCRYPT), md5 запрещён ПОЛНОСТЬЮ
6. Все выводы в HTML — htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') 
   через функцию escape()
7. CSRF-токены во все POST-формы
8. Авторизация только через $_SESSION, cookies — только «запомнить меня» 
   с подписью (сейчас не реализовано — не приоритет)
9. session_regenerate_id(true) — ПОСЛЕ password_verify, ДО записи в $_SESSION
10. Проверка админа — по данным из БД через сессию, не по cookie
11. Функции в connect.php обёрнуты в if (!function_exists()) — сохранять
12. require/require_once — только _once для connect.php
13. НЕ доверять $_SERVER['HTTP_X_FORWARDED_FOR'] без whitelist 
    ($trustedProxies = [] — пока пусто, XFF не читается)

# ЧТО УЖЕ СДЕЛАНО (НЕ ПЕРЕДЕЛЫВАТЬ)

## Docker (Stage 1) — ✅
- Dockerfile: php:8.1-apache, non-root www-data, Apache на 8080, 
  COPY --chown, mysqli/pdo/opcache
- docker-compose.yml: mysql:8.0, healthcheck через php-функцию, 
  порт БД 127.0.0.1:3306
- docker-compose.override.yml: volume ./src для dev
- .env, .env.example, .gitignore, .dockerignore
- Все страницы отдают 200, БД инициализируется

## Stage 3 (безопасность) — частично

### 7a connect.php — ✅
- .env читается через getenv() (docker-compose прокидывает env)
- Убран fallback-пароль (нет DB_PASSWORD → exit(500) + error_log)
- db_prepare(): mysqli_prepare + bind_param, throw при ошибке
- escape(): htmlspecialchars с ENT_QUOTES | ENT_HTML5
- Функции обёрнуты в if (!function_exists()) — проверить, что это так
- putenv() убран (не потокобезопасен в mod_php)

### 7b auth.php — ✅ (требует финальной проверки)
- session_start() в начале
- checkRateLimit(): таблица login_attempts, 5 по login, 20 по IP, 15 минут
- logFailedAttempt(), clearAttempts()
- password_verify() + password_get_info()['algo'] !== 0 для определения bcrypt
- Legacy (md5) → редирект на /validation/reset.php
- password_needs_rehash → перехеширование при логине
- session_regenerate_id(true) ПОСЛЕ verify, ДО $_SESSION
- $_SESSION['user_id'/'user_name'/'user_login'/'user_group']
- setcookie('user'/'login'/'uId') УДАЛЕНЫ
- Одинаковое сообщение «Неверный логин или пароль»
- setcookie('error_access', ..., httponly, samesite=Strict)
- SQL через db_prepare

### 7c reg.php — ✅ (требует финальной проверки)
- password_hash(PASSWORD_BCRYPT)
- Prepared statements для SELECT и INSERT
- Валидация: login 3-25, name 3-20, pass 8-20
- CSRF-токен (bin2hex(random_bytes(32)), hash_equals, ротация после успеха)
- GET → редирект (форма регистрации в profile.php)
- Автологин после регистрации ($_SESSION + session_regenerate_id)

### 7g begin.php → create_admin.php — ✅
- src/begin.php удалён (был установочный скрипт с md5 + SQLi + хардкод)
- src/scripts/create_admin.php создан:
  - PHP_SAPI === 'cli' проверка
  - readline prompt + stty -echo
  - password_hash(PASSWORD_BCRYPT)
  - Prepared statements
  - Идемпотентность: если админ есть → exit(1)
  - Путь require_once __DIR__ . '/../modules/connect.php'

### 7d assembly.php — ⏳ В РАБОТЕ, последний фикс НЕ ПРОТЕСТИРОВАН
- require → require_once (в assembly.php и configurator.php)
- Функции в connect.php обёрнуты в function_exists
- Все SELECT/DELETE/INSERT через db_prepare
- $_COOKIE['uId'] → $_SESSION['user_id']
- $_COOKIE['user'] → $_SESSION['user_name']
- XSS: escape() применён на выводах
- bind_param для INSERT assembly: "siiiiiiii" (9 символов) — проверить
- gpu_id обрабатывается через !empty() + UPDATE после INSERT
- maxID для orders/favorites — убран, используется AUTO_INCREMENT

**СТАТУС: причина 500 была в двойном require connect.php — фикс применён, 
но HTTP-тест не прогнан.**

# ЧТО ОСТАЛОСЬ

## Stage 3 (продолжение) — фиксы безопасности
- 7d: проверить, что POST /assembly.php → 302 (не 500)
- 7e: index.php — XSS в $_COOKIE['user'] (строка ~37)
- 7f: profile.php — IDOR (проверка админа по cookie → по сессии + БД)
- 7h: CSRF-токены во все POST-формы (assembly.php, profile.php, configurator.php)
- 7i: security-заголовки (CSP, X-Frame-Options, HSTS) через .htaccess
- 7j: миграция паролей — grep по src/ должен быть пуст по md5/i0b1tzvc7
- 7k: создание админа через .env + entrypoint:
  - create_admin.php читает ADMIN_LOGIN/ADMIN_PASSWORD из .env
  - поддержка флага --if-not-exists (тихий выход, если админ есть)
  - docker/entrypoint.sh вызывает скрипт при старте контейнера, retry-loop
  - Dockerfile: COPY docker/entrypoint.sh + ENTRYPOINT
  - docker-compose.yml: проброс ADMIN_LOGIN/ADMIN_PASSWORD в сервис php
  - .env.example: переменные + комментарий «удалить после первого запуска»
  - Тесты: down -v → up -d → админ есть; повторный up -d → не дублируется

## Stage 3.5 — HTML-рефакторинг (partials)

### Цель
Вынести повторяющийся HTML-каркас в partials. Бизнес-логику НЕ трогать.

### Границы (КРИТИЧНО)
✅ РАЗРЕШЕНО:
- Создать src/partials/header.php и src/partials/footer.php
- Использовать include/require для подключения
- Передавать через переменные $pageTitle, $extraCss, $extraJs, $bodyClass
- Немного почистить дублирующийся HTML

❌ ЗАПРЕЩЕНО:
- Менять пути/названия .php файлов
- Менять URL и action форм (/validation/auth.php и т.д.)
- Вводить шаблонизатор
- Перемещать modules/, validation/, scripts/, assets/
- Менять бизнес-логику, SQL, обработчики POST
- Менять классы/структуру CSS (только переиспользовать существующие)

### Структура
src/
├── index.php, profile.php, assembly.php   ← подключают partials
├── partials/
│   ├── header.php    ← <html><head>...<body><header>...<div class="wrapper">
│   └── footer.php    ← </div><footer>...</footer></body></html>
├── modules/, validation/, scripts/, assets/   ← НЕ ТРОГАТЬ

### Требования к header.php
1. Проверка session_status() перед session_start() (избежать двойного старта)
2. require_once __DIR__ . '/../modules/connect.php'
3. $pageTitle = $pageTitle ?? 'AION CORP'; вывод через escape($pageTitle)
4. $isLoggedIn вычисляется из $_SESSION['user_id']
5. Ссылки к assets — АБСОЛЮТНЫЕ (/assets/...), не относительные
6. Поддержка $extraCss (массив) — foreach с escape
7. Открывает <body>, <header>, <div class="wrapper">
8. Никаких SQL-запросов, только чтение $_SESSION

### Требования к footer.php
1. Закрывает </div><!-- /.wrapper -->, добавляет <footer>...</footer>, </body></html>
2. Поддержка $extraJs (массив)
3. Никакого PHP-кода, кроме foreach

### Использование в страницах
- Сверху: $pageTitle = "..."; $extraCss = [...]; 
  require __DIR__ . '/partials/header.php';
- Внизу: require __DIR__ . '/partials/footer.php';
- Если $mysql->close() — вызывать ПОСЛЕ footer.php

### Тесты после рефакторинга (обязательно)
Для каждой страницы — HTTP 200:
- docker compose exec php curl -s -o /dev/null -w "%{http_code}\n" http://localhost/
- docker compose exec php curl -s -o /dev/null -w "%{http_code}\n" http://localhost/profile.php
- docker compose exec php curl -s -o /dev/null -w "%{http_code}\n" http://localhost/assembly.php?init=1

Плюс визуальная проверка в браузере:
- шапка, меню, футер на месте
- CSS загружается
- в DevTools Console нет ошибок

### Порядок работы
1. Сначала — header.php и footer.php как отдельные файлы (не подключая никуда). 
   Показать код.
2. Потом — перевести index.php. Показать diff + тест.
3. Затем — profile.php. Diff + тест.
4. Затем — assembly.php. Diff + тест.
5. После каждой страницы — визуальная проверка в браузере обязательна.

Жди подтверждения после каждого шага.

## Stage 4 — тесты
- PHPUnit через dev-окружение (composer dev-deps или phar)
- HTTP-тесты для критичных сценариев: логин, регистрация, save/buy сборки, IDOR
- Покрытие критичных модулей (корзина/заказы/авторизация) ≥ 60%

## Stage 5 — оптимизация
- SQL: EXPLAIN, индексы, устранение N+1, пагинация
- PHP: opcache, optimized autoloader
- Фронт: gzip/brotli, кэш-заголовки
- Docker: слои, кэш сборки, размер образа
- Метрики «до/после»: время ответа, число SQL-запросов, размер образа
- Санитизация orphan-мусора: assembly без favorites/orders (удаление тестовых сборок)
- Проверка orphan-мусора в components: мусорные названия типа `вфыфы`,
  `ZZTest*` — кандидаты на удаление

## Stage 6 — финальный отчёт
- Резюме, архитектура AS-IS / TO-BE
- Найденные уязвимости и качество кода (таблица severity)
- Что исправлено (список коммитов)
- Тесты: покрытие, что покрыто / не покрыто
- Оптимизация: метрики «до/после»
- Docker: как запустить, состав сервисов
- Остаточные риски и TODO
- Инструкция для разработчика

# ПРАВИЛА РАБОТЫ

1. Показывай код ЦЕЛИКОМ (не summary). Для файлов <100 строк — весь файл, 
   для больших — затронутые фрагменты с контекстом 5 строк.
2. Перед ЛЮБЫМ изменением — покажи diff. Не «изменил файл», а конкретный 
   старый код → новый код.
3. После каждого фикса — HTTP-тест:
   docker compose exec php curl -s -o /dev/null -w "%{http_code}\n" URL
4. Статусы «✅ принято» ставит пользователь, не ты. Ты пишешь «⏳ готово к ревью».
5. НЕ зацикливайся. Если 2 попытки не дали результата — останови, напиши 
   «нужна помощь», не повторяй одно и то же.
6. НЕ рассуждай вслух длинно. Короткие сообщения: сделал X → проверил Y → вывод Z.
7. Если что-то непонятно — задай ОДИН конкретный вопрос, не строй предположения.
8. Работай в отдельных коммитах на задачу.
9. При рефакторинге — двигайся по одному файлу за раз. Не переписывай сразу 
   все три страницы. После каждой — тест и подтверждение.
10. НЕ меняй пути и URL. /validation/auth.php остаётся /validation/auth.php, 
    даже если «по-хорошему надо переименовать».
11. При сомнениях — оставь как было. Лучше не идеально, но работает, 
    чем идеально, но сломано.
12. Если код показываешь — вставляй в чат, а не ссылайся на «прочитал файл». 
    Используй: cat -n <file> через docker compose exec php, если обычный 
    вывод не работает.

# С ЧЕГО НАЧАТЬ

Шаг 1. Прогони быструю проверку состояния и покажи вывод (только вывод, 
без рассуждений):

   docker compose exec php curl -s -o /dev/null -w "%{http_code}\n" -X POST -d "save=1" http://localhost/assembly.php
   docker compose exec php grep -rn "md5\|i0b1tzvc7" /var/www/html/ 2>/dev/null
   docker compose exec php grep -rn "COOKIE\['uId'\]\|COOKIE\['user'\]" /var/www/html/ 2>/dev/null
   docker compose exec php grep -n "function_exists" /var/www/html/modules/connect.php
   ls src/scripts/create_admin.php
   docker compose ps

Шаг 2. Интерпретация:
   - POST /assembly.php → 302: 7d принят, идём в 7e (index.php XSS)
   - POST /assembly.php → 500: покажи код require-строк в assembly.php и 
     configurator.php + вывод ошибки из stderr, разберём
   - grep md5: должен быть пуст (если что-то есть — верни в работу 7b/7c)
   - grep COOKIE: должен быть пуст (если что-то есть — верни 7d/7f)

Жди моего подтверждения перед любыми правками. Сначала — только отчёт 
по шагу 1.