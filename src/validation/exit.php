<?php
/**
 * Выход из аккаунта.
 *
 * Сессия запускается и сбрасывается через общий помощник logout_user()
 * из modules/auth.php: тот же сброс нужен в profile.php, когда сессия
 * пережила удаление пользователя из базы.
 */
require_once __DIR__ . '/../modules/connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
logout_user();
header('Location: /');
exit();