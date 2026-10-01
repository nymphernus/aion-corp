<?php
/**
 * Регистрация пользователя
 * 
 * - password_hash (bcrypt)
 * - prepared statements
 * - валидация входных данных
 * - CSRF-токен (hash_equals)
 */

require_once __DIR__ . '/../modules/connect.php';

session_start();

// Генерация CSRF-токена
csrf_token();

// GET — показываем форму (редирект на profile.php, где форма)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: /profile.php');
    exit();
}

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Проверка CSRF-токена
csrf_verify();

$name = trim($_POST['user_name'] ?? '');
$login = trim($_POST['user_login'] ?? '');
$pass = $_POST['user_pass'] ?? '';
$group = 'user';

// Валидация
$errors = [];

if (mb_strlen($login) < 3 || mb_strlen($login) > 25) {
    $errors[] = "Логин должен быть от 3 до 25 символов";
}

if (mb_strlen($name) < 3 || mb_strlen($name) > 20) {
    $errors[] = "Имя должно быть от 3 до 20 символов";
}

if (mb_strlen($pass) < 8 || mb_strlen($pass) > 20) {
    $errors[] = "Пароль должен быть от 8 до 20 символов";
}

if (!empty($errors)) {
    $_SESSION['old_login'] = $login;
    $_SESSION['old_name'] = $name;
    setcookie('error_access', implode(", ", $errors), [
        'expires' => time() + 1,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    header('Location: /profile.php');
    exit();
}

try {
    $mysql = connect();
} catch (RuntimeException $e) {
    error_log('DB connection error occurred');
    http_response_code(500);
    exit('Service unavailable');
}

// Проверка существования пользователя
$stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", $login);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    $_SESSION['old_login'] = $login;
    $_SESSION['old_name'] = $name;
    setcookie('error_access', "Такой пользователь уже существует", [
        'expires' => time() + 1,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    $mysql->close();
    header('Location: /profile.php');
    exit();
}

// Хеширование пароля
$hash = password_hash($pass, PASSWORD_BCRYPT);

// Создание пользователя
$stmt = db_prepare($mysql, "INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)", "ssss", $name, $login, $hash, $group);
$stmt->execute();

// Автологин после регистрации
$_SESSION['user_id'] = $mysql->insert_id;
$_SESSION['user_name'] = $name;
$_SESSION['user_login'] = $login;
$_SESSION['user_group'] = $group;

$mysql->close();

// Регенерация session ID
session_regenerate_id(true);

// Ротация CSRF-токена
csrf_rotate();

header('Location: /profile.php');
exit();
