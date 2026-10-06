<?php
/**
 * Настройки сайта 
 *
 * Хранятся в таблице site_settings как пары ключ-значение. Читаются
 * одним запросом и кэшируются в static на время запроса: на главной
 * к настройкам обращаются несколько раз подряд (телефон, почта, ссылки,
 * снимок карты), и десять значений запрашивать каждый раз незачем.
 *
 * Пользовательские значения (телефон, ссылки соцсетей) - это данные
 * владельца, а не сборки проекта. После первого сохранения их нужно
 * залить в init.sql вручную, иначе чистая база поднимется с дефолтами.
 */

require_once __DIR__ . '/connect.php';

if (!function_exists('site_settings')) {
    /**
     * Все настройки сайта.
     *
     * @return array<string, string|null>
     */
    function site_settings(mysqli $mysql): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $settings = [];
        try {
            $stmt = db_prepare(
                $mysql,
                "SELECT setting_key, setting_value FROM site_settings",
                ""
            );
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // NULL приводим к пустой строке, чтобы вызывающий код
                // не различал "нет значения" и "пустое значение"
                $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
            $stmt->close();
        } catch (Throwable $e) {
            // Таблицы может не быть (старая база, сид без 5-f-2).
            // Главная и админка обязаны работать и без настроек, поэтому
            // логируем и возвращаем пустой массив, а не роняем страницу.
            error_log('site_settings read failed: ' . $e->getMessage());
            $settings = [];
        }

        $cache = $settings;
        return $cache;
    }
}

if (!function_exists('site_setting')) {
    /**
     * Одно значение из настроек.
     */
    function site_setting(array $settings, string $key, string $default = ''): string
    {
        $value = $settings[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}

if (!function_exists('site_setting_save')) {
    /**
     * Записать настройки. Ключи фильтруются по белому списку -
     * имена колонок в UPDATE подставлять нельзя, приходит только значение.
     *
     * Отсутствующий ключ создаётся через INSERT, поэтому настройку можно
     * добавить без ALTER.
     *
     * @param array<string, string> $values
     */
    function site_setting_save(mysqli $mysql, array $values): void
    {
        if (!$values) {
            return;
        }

        // Ключ приходит из белого списка вызывающего кода, значение - из POST.
        // Оба идут параметрами: в SQL попадают только плейсхолдеры.
        foreach ($values as $key => $value) {
            $stmt = db_prepare(
                $mysql,
                "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                "ss",
                (string) $key,
                (string) $value
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}
