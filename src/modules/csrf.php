<?php
/**
 * Централизованная работа с CSRF-токенами
 *
 * - csrf_token(): string — создаёт токен в сессии если нет, возвращает
 * - csrf_verify(): void — проверяет POST, 403 + exit при неверном
 * - csrf_rotate(): void — ротация токена (после успешного POST)
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            http_response_code(403);
            exit('CSRF token invalid');
        }
    }
}

if (!function_exists('csrf_rotate')) {
    function csrf_rotate(): void
    {
        unset($_SESSION['csrf_token']);
    }
}
