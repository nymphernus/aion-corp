<?php
/**
 * Общие помощники сессии.
 *
 * Подключается через modules/connect.php, который тянут все страницы.
 */

if (!function_exists('logout_user')) {
    /**
     * Полный сброс сессии: данные, cookie, сама сессия.
     *
     * Нужен в двух случаях: человек нажал «Выйти», и сессия пережила
     * удаление пользователя из базы - во втором случае сброс обязателен,
     * иначе удалённый пользователь получал пустой профиль вместо формы
     * входа.
     *
     * Вызывать надо при активной сессии: session_start() до вызова.
     * Редирект и exit остаются на стороне вызывающего - здесь только сброс,
     * чтобы вызывающий сам решал, куда отправлять.
     */
    function logout_user(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

if (!function_exists('current_user_is_admin')) {
    /**
     * Админ ли текущий пользователь.
     *
     * Группа берётся свежей из базы, а не из сессии - так же, как в
     * admin.php. Разжалованный админ со старой сессией иначе продолжал бы
     * считаться админом до конца сессии.
     *
     * @param int|null $userId null, если никто не залогинен
     */
    function current_user_is_admin(mysqli $mysql, ?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }

        $stmt = db_prepare($mysql, 'SELECT user_group FROM `users` WHERE `user_id` = ?', 'i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ($row['user_group'] ?? '') === 'admin';
    }
}