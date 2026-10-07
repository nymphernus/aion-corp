<?php
/**
 * Логирование действий администратора.
 *
 * Записи попадают в таблицу admin_actions и показываются на вкладке
 * «Журнал» (/admin.php?tab=log). Записи старше 90 дней удаляются
 * в scripts/migrate.php при каждом старте - отдельной очистки нет.
 *
 * Журнал не должен ломать основное действие: любая ошибка записи
 * уходит в error_log, а вызывающий код продолжает работать.
 */

if (!function_exists('admin_log')) {
    /**
     * Записать действие администратора в журнал.
     *
     * @param mysqli      $mysql     Соединение с БД
     * @param string      $action    Код действия: <сущность>.<операция>,
     *                               например «component.update»
     * @param string|null $entityType Тип объекта (preset, os, assembly…)
     * @param int|null    $entityId  Идентификатор объекта
     * @param array|null  $details   Ключевые поля для колонки «Детали»
     *                               (пишется JSON, до 5000 символов)
     */
    function admin_log(
        mysqli $mysql,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $details = null
    ): void {
        // Гость сюда попасть не должен: все вызовы идут внутри
        // проверки на админа, но подстраховка на случай, если
        // сессия уже пуста.
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return;
        }

        $userLogin = (string) ($_SESSION['user_login'] ?? 'unknown');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        // В details - только ключевые поля, но на размер ставим
        // жёсткий потолок: текст не должен раздувать таблицу.
        $detailsJson = null;
        if ($details !== null) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $detailsJson = mb_substr($json, 0, 5000);
            }
        }

        try {
            $stmt = db_prepare(
                $mysql,
                "INSERT INTO admin_actions
                 (user_id, user_login, action, entity_type, entity_id, details, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                'isssisss',
                (int) $userId,
                $userLogin,
                $action,
                $entityType,
                $entityId,
                $detailsJson,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('admin_log failed (' . $action . '): ' . $e->getMessage());
        }
    }
}
