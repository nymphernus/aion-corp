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
$surname = trim($_POST['user_surname'] ?? '');
$login = trim($_POST['user_login'] ?? '');
$email = trim($_POST['user_email'] ?? '');
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

// Фамилия необязательна, но если её ввели - длина всё равно ограничена.
// MySQL обрезал бы молча по varchar(30), и пользователь увидел бы в
// профиле полуфамилию, не понимая, где потерялось остальное.
if (mb_strlen($surname) > 30) {
    $errors[] = "Фамилия не должна быть длиннее 30 символов";
}

// Длину email проверяем до filter_var: у слишком длинного адреса
// FILTER_VALIDATE_EMAIL всё равно вернул бы false, и пользователь получил
// бы «некорректный email» вместо внятного «слишком длинный».
if (mb_strlen($email) > 100) {
    $errors[] = "Email не должен быть длиннее 100 символов";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Некорректный email";
}

if (mb_strlen($pass) < 8 || mb_strlen($pass) > 20) {
    $errors[] = "Пароль должен быть от 8 до 20 символов";
}

if (!empty($errors)) {
    $_SESSION['old_login'] = $login;
    $_SESSION['old_name'] = $name;
    $_SESSION['old_surname'] = $surname;
    $_SESSION['old_email'] = $email;
    // error_access общий для входа и регистрации, поэтому
    // отдельной меткой говорим profile.php, что сообщение из регистрации:
    // форма входа свёрнута, и ошибка внутри неё была бы не видна
    setcookie('error_from', 'reg', [
        'expires' => time() + 60,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
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

// Проверка существования пользователя.
//
// Один запрос на логин и email вместо двух: при неуникальном email
// проверялся бы только логин, запрос не дошёл бы до второй колонки,
// а сообщение всё равно одно на оба случая. Так видно, что именно занято.
//
// Сравнение через LOWER() и strcasecmp(): обе колонки под дефолтным
// коллационным регистронезависимы, поэтому 'Ivan' и 'ivan' должны считаться
// одним логином. Уникальный индекс на user_email намеренно не заведён:
// profile.php меняет email обычным UPDATE без проверки дублей, и индекс
// превратил бы такой UPDATE в фатальную ошибку MySQL вместо тихого
// сохранения дубликата.
$stmt = db_prepare(
    $mysql,
    "SELECT user_login, user_email FROM users WHERE user_login = ? OR LOWER(user_email) = LOWER(?)",
    "ss",
    $login,
    $email
);
$stmt->execute();
$found = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$takenLogin = false;
$takenEmail = false;
foreach ($found as $row) {
    if (strcasecmp((string) $row['user_login'], $login) === 0) {
        $takenLogin = true;
    }
    // Пустой $email сюда не доходит: его отсекает FILTER_VALIDATE_EMAIL
    // выше, иначе пустая строка совпала бы с пустым email у другой строки.
    if (strcasecmp((string) $row['user_email'], $email) === 0) {
        $takenEmail = true;
    }
}

if ($takenLogin || $takenEmail) {
    if ($takenLogin) {
        $errors[] = "Такой логин уже существует";
    }
    if ($takenEmail) {
        $errors[] = "Пользователь с таким email уже существует";
    }
    $_SESSION['old_login'] = $login;
    $_SESSION['old_name'] = $name;
    $_SESSION['old_surname'] = $surname;
    $_SESSION['old_email'] = $email;
    // см. выше - метка источника сообщения
    setcookie('error_from', 'reg', [
        'expires' => time() + 60,
        'path' => '/profile.php',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    setcookie('error_access', implode(", ", $errors), [
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
$stmt = db_prepare(
    $mysql,
    "INSERT INTO users (user_name, user_surname, user_login, user_email, user_pass, user_group) VALUES (?, ?, ?, ?, ?, ?)",
    "ssssss",
    $name,
    $surname,
    $login,
    $email,
    $hash,
    $group
);
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
