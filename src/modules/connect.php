<?php
/**
 * Подключение к базе данных
 * 
 * Конфигурация читается из переменных окружения (getenv)
 * Поддерживает prepared statements через db_prepare()
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
// настройки сайта. Подключается здесь, чтобы site.php пользовался
// db_prepare из этого же файла, а страницам не приходилось знать про порядок
require_once __DIR__ . '/site.php';
// разметка поля пароля с кнопкой показа. Тот же смысл, что и у
// site.php - страницам не нужно знать про порядок подключения модулей
require_once __DIR__ . '/ui.php';
// 7.5: иконки конфигуратора. Здесь, а не в самой assembly.php, потому что
// escape() живёт в этом же файле ниже: иконка вызывается после
// подключения connect.php, но полагаться на это в разметке - значит
// завязать её на порядок require, который никто не проверяет.
require_once __DIR__ . '/icons.php';
// сжатие изображений при загрузке. Функции требуются
// обработчику в admin.php, который подключает connect.php, но не должен
// знать про порядок require внутри модулей.
require_once __DIR__ . '/image.php';

if (!function_exists('connect')) {
    function connect(): mysqli
    {
        $dbhost = getenv('DB_HOST') ?: 'db';
        $dbuser = getenv('DB_USER') ?: 'admin';
        $dbpass = getenv('DB_PASSWORD');
        // Имя базы берётся из окружения. Раньше здесь стоял захардкоженный
        // 'aion_bd' на случай пустого DB_NAME - но compose передаёт
        // MYSQL_DATABASE, и подставить надо именно его: иначе при
        // переименовании базы приложение молча пошло бы в старую.
        $dbname = getenv('MYSQL_DATABASE') ?: (getenv('DB_NAME') ?: 'shop_db');
        
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
        // счётчик запросов для замеров. Включается переменной окружения
        // DEBUG_SQL_COUNT, по умолчанию пусто и ничего не стоит.
        if (getenv('DEBUG_SQL_COUNT') !== false) {
            $GLOBALS['db_prepare_calls'] = ($GLOBALS['db_prepare_calls'] ?? 0) + 1;
        }

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
     * сервер не отдаёт Cache-Control, браузер кэширует
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
