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

// Выход администратора пишется ДО logout_user(): тот очищает
// $_SESSION, и после него проверка группы уже не сработает.
// Группа из сессии: она установлена при входе, а свежий SELECT
// здесь ни к чему - журнал фиксирует факт выхода, а не актуальность
// звания.
if (($_SESSION['user_group'] ?? '') === 'admin' && isset($_SESSION['user_id'])) {
    $logoutMysql = connect();
    admin_log($logoutMysql, 'auth.logout', 'user', (int) $_SESSION['user_id'], [
        'login' => (string) ($_SESSION['user_login'] ?? ''),
    ]);
    $logoutMysql->close();
}

logout_user();
header('Location: /');
exit();