<?php
/**
 * CLI-скрипт создания администратора
 * 
 * Использование:
 *   php src/scripts/create_admin.php
 *   php src/scripts/create_admin.php --if-not-exists
 * 
 * Если заданы переменные окружения ADMIN_LOGIN и ADMIN_PASSWORD,
 * используются они (без readline-prompt).
 * Пароль нигде не логируется.
 */

require_once __DIR__ . '/../modules/connect.php';

// Проверка запуска из CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

// Парсинг аргументов
$ifNotExists = in_array('--if-not-exists', $argv, true);

// Определение логина и пароля
$envLogin = getenv('ADMIN_LOGIN');
$envPass = getenv('ADMIN_PASSWORD');

if ($envLogin !== false && $envLogin !== '' && $envPass !== false && $envPass !== '') {
    // Используем переменные окружения
    $login = $envLogin;
    $pass = $envPass;
} else {
    // Интерактивный ввод
    echo "Создание администратора\n";
    echo "======================\n";

    $login = readline("Логин: ");

    // Скрытый ввод пароля
    echo "Пароль: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: нет stty, показываем предупреждение
        echo "(видимый ввод) ";
        $pass = readline();
    } else {
        // Unix: скрываем ввод
        shell_exec('stty -echo');
        $pass = readline();
        shell_exec('stty echo');
        echo "\n";
    }
}

// Валидация
if (mb_strlen($login) < 3 || mb_strlen($login) > 25) {
    echo "Ошибка: логин должен быть от 3 до 25 символов\n";
    exit(1);
}

if (mb_strlen($pass) < 8) {
    echo "Ошибка: пароль должен быть не менее 8 символов\n";
    exit(1);
}

try {
    $mysql = connect();
} catch (Throwable $e) {
    echo "Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}

// Проверка существования
$stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", $login);
$stmt->execute();
$result = $stmt->get_result();

if ($result->fetch_assoc()) {
    $mysql->close();
    if ($ifNotExists) {
        // Тихий выход при --if-not-exists
        exit(0);
    }
    echo "Ошибка: пользователь с таким логином уже существует\n";
    exit(1);
}

// Создание администратора
$name = 'Администратор';
$hash = password_hash($pass, PASSWORD_BCRYPT);

$stmt = db_prepare($mysql, "INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)", "ssss", $name, $login, $hash, 'admin');
$stmt->execute();

$mysql->close();

echo "✅ Админ '{$login}' создан. Удалите ADMIN_PASSWORD из .env и сделайте docker compose up -d\n";
