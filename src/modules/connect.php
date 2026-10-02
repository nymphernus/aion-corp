<?php
/**
 * Подключение к базе данных
 * 
 * Конфигурация читается из переменных окружения (getenv)
 * Поддерживает prepared statements через db_prepare()
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('connect')) {
    function connect(): mysqli
    {
        $dbhost = getenv('DB_HOST') ?: 'db';
        $dbuser = getenv('DB_USER') ?: 'admin';
        $dbpass = getenv('DB_PASSWORD');
        $dbname = getenv('DB_NAME') ?: 'aion_bd';
        
        if ($dbpass === false || $dbpass === '') {
            error_log("DB_PASSWORD not set");
            http_response_code(500);
            exit('Service unavailable');
        }
        
        $mysql = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        
        if ($mysql->connect_error) {
            error_log("DB Connection failed: " . $mysql->connect_error);
            http_response_code(500);
            exit('Service unavailable');
        }
        
        mysqli_set_charset($mysql, 'utf8');
        return $mysql;
    }
}

if (!function_exists('db_prepare')) {
    /**
     * Подготовка SQL-запроса (prepared statement)
     * 
     * @param mysqli $mysql Соединение с БД
     * @param string $sql SQL-запрос с плейсхолдерами (?)
     * @param string $types Типы параметров (i, d, s, b)
     * @param mixed ...$params Параметры для подстановки
     * @return mysqli_stmt
     * @throws RuntimeException При ошибке подготовки запроса
     */
    function db_prepare(mysqli $mysql, string $sql, string $types = '', ...$params): mysqli_stmt
    {
        $stmt = $mysql->prepare($sql);
        if ($stmt === false) {
            error_log("Prepare failed: " . $mysql->error);
            throw new RuntimeException("Prepare failed: " . $mysql->error);
        }
        
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        return $stmt;
    }
}

if (!function_exists('asset_url')) {
    /**
     * URL статики с версией по mtime.
     *
     * 3.7-f-2-8: сервер не отдаёт Cache-Control, браузер кэширует
     * статику эвристически и непоследовательно - правки CSS/JS
     * попадали в браузер через раз. ?v=mtime принудительно меняет
     * адрес при изменении файла и убирает проблему.
     *
     * @param string $path Путь от корня сайта, например /assets/css/base.css
     * @return string
     */
    function asset_url(string $path): string
    {
        $file = __DIR__ . '/..' . $path;
        if (is_file($file)) {
            return $path . '?v=' . filemtime($file);
        }
        return $path;
    }
}

if (!function_exists('escape')) {
    /**
     * Экранирование вывода в HTML (защита от XSS)
     * 
     * @param string $value Значение для экранирования
     * @return string
     */
    function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
