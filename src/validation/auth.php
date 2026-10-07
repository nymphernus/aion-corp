<?php
/**
 * Аутентификация пользователя
 * 
 * - password_verify (только bcrypt)
 * - session_regenerate_id(true) после успешного логина
 * - rate-limit через таблицу login_attempts
 * - одинаковое сообщение об ошибке (защита от user enumeration)
 * - legacy-пользователи → редирект на сброс пароля
 */

require_once __DIR__ . '/../modules/connect.php';

session_start();

csrf_token();

// Rate-limit: проверка количества неудачных попыток
function checkRateLimit(mysqli $mysql, string $login, string $ip): bool
{
    // Очистка старых записей (раз в 10 запросов — для производительности)
    if (random_int(1, 10) === 1) {
        $stmt = db_prepare($mysql, "DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", "");
        $stmt->execute();
    }
    
    // Подсчёт неудачных попыток за последние 15 минут
    // Лимит: 5 по login, 20 по ip (для NAT)
    $stmt = db_prepare($mysql, "SELECT 
        SUM(CASE WHEN login = ? THEN 1 ELSE 0 END) as login_attempts,
        SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END) as ip_attempts
        FROM login_attempts 
        WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", "ss", $login, $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $loginAttempts = $row['login_attempts'] ?? 0;
    $ipAttempts = $row['ip_attempts'] ?? 0;
    
    return $loginAttempts < 5 && $ipAttempts < 20;
}

// Запись неудачной попытки
function logFailedAttempt(mysqli $mysql, string $login, string $ip): void
{
    $stmt = db_prepare($mysql, "INSERT INTO login_attempts (login, ip, attempt_time) VALUES (?, ?, NOW())", "ss", $login, $ip);
    $stmt->execute();
}

// Успешный вход — очистка попыток
function clearAttempts(mysqli $mysql, string $login, string $ip): void
{
    $stmt = db_prepare($mysql, "DELETE FROM login_attempts WHERE login = ? OR ip = ?", "ss", $login, $ip);
    $stmt->execute();
}

// Проверка CSRF-токена
csrf_verify();

$login = trim($_POST['user_login'] ?? '');
$pass = $_POST['user_pass'] ?? '';

// Получение IP с учётом прокси (только доверенные)
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$trustedProxies = []; // пока пусто — добавить IP reverse-proxy при необходимости
if (in_array($ip, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($forwarded[0]);
    // Валидация IP
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = 'unknown';
    }
}

try {
    $mysql = connect();
} catch (RuntimeException $e) {
    error_log('DB connection error occurred');
    http_response_code(500);
    exit('Service unavailable');
}

// Проверка rate-limit
if (!checkRateLimit($mysql, $login, $ip)) {
    http_response_code(429);
    header('Retry-After: 900');
    exit('Слишком много попыток. Попробуйте через 15 минут.');
}

// Поиск пользователя
$stmt = db_prepare($mysql, "SELECT user_id, user_name, user_login, user_pass, user_group FROM users WHERE user_login = ?", "s", $login);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Проверка пароля
$passwordValid = false;

if ($user) {
    // Проверяем, что хеш в современном формате (bcrypt/argon2)
    $hashInfo = password_get_info($user['user_pass']);
    if ($hashInfo['algo'] !== 0) {
        if (password_verify($pass, $user['user_pass'])) {
            $passwordValid = true;
            
            // Миграция: если хеш устарел, перехешируем
            if (password_needs_rehash($user['user_pass'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($pass, PASSWORD_BCRYPT);
                $updateStmt = db_prepare($mysql, "UPDATE users SET user_pass = ? WHERE user_id = ?", "si", $newHash, $user['user_id']);
                $updateStmt->execute();
            }
        }
    } else {
        // Legacy-пользователь — редирект на сброс пароля
        setcookie('error_access', "Пароль требует сброса. Свяжитесь с администратором.", [
            'expires' => time() + 1,
            'path' => '/profile.php',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        header('Location: /validation/reset.php');
        exit();
    }
}

// Сохраняем введённый логин, чтобы вернуть его в форму после редиректа.
// (после редиректа $_POST пуст — значение передаём через сессию)
$_SESSION['old_login'] = $login;

if (!$passwordValid) {
    logFailedAttempt($mysql, $login, $ip);
    // Метка источника обязательна и здесь, не только в reg.php.
    // error_from живёт 60 секунд, error_access - одну. Провалилась
    // регистрация, страницу открыли позже: error_access истёк, а
    // error_from=reg осталась одна, и следующая неудачная попытка входа
    // показывала ошибку в свёрнутой форме регистрации.
    setcookie('error_from', 'auth', [
        'expires' => time() + 60,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    setcookie('error_access', "Неверный логин или пароль", [
        'expires' => time() + 1,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    header('Location: /profile.php');
    exit();
}

// Успешный вход
clearAttempts($mysql, $login, $ip);

// Ротация CSRF-токена
csrf_rotate();

// Регенерация session ID (защита от session fixation)
session_regenerate_id(true);

// Сохранение данных в сессию
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['user_name'] = $user['user_name'];
$_SESSION['user_login'] = $user['user_login'];
$_SESSION['user_group'] = $user['user_group'];

// Журнал действий: вход пишется только для администратора, вход
// обычного пользователя - не действие в админке. Группа читается из
// $user, а не перечитывается из базы: строка только что взята оттуда.
if ($user['user_group'] === 'admin') {
    admin_log($mysql, 'auth.login_success', 'user', (int) $user['user_id'], ['login' => $login]);
}

$mysql->close();
header('Location: /profile.php');
exit();
