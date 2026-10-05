<?php
/**
 * Идемпотентные миграции схемы и базовых данных (Stage 10-A).
 *
 * Запуск: php src/scripts/migrate.php [--dry-run]
 *
 * Создание самой БД не здесь: docker-compose env MYSQL_DATABASE создаёт её
 * автоматически, entrypoint ждёт healthcheck. Здесь только схема и
 * обязательные данные, без которых приложение не заводится.
 *
 * Идемпотентность:
 *   - таблицы: CREATE TABLE IF NOT EXISTS в актуальной структуре;
 *   - данные с известными id (categories, sockets): INSERT с проверкой
 *     существования строки;
 *   - site_settings: восполняются только недостающие ключи, значения
 *     существующих не перетираются.
 *
 * Демо-данные (компоненты, сборки) сюда не входят - за ними SEED_DATA=1
 * и seed_components.php из entrypoint.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/../modules/connect.php';

$dryRun = in_array('--dry-run', $argv, true);

$mysql = connect();

function mig_log(string $message): void
{
    echo $message . "\n";
}

/** Выполнить SQL, в dry-run только показать. */
function mig_exec(mysqli $mysql, bool $dryRun, string $sql, array $params = [], string $types = ''): bool
{
    if ($dryRun) {
        mig_log('  [dry] ' . preg_replace('/\s+/', ' ', substr($sql, 0, 90)));
        return true;
    }
    $stmt = db_prepare($mysql, $sql, $types, ...$params);
    $stmt->execute();
    return true;
}

/** Есть ли таблица. */
function table_exists(mysqli $mysql, string $table): bool
{
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row()[0];
}

// ---------------------------------------------------------------------------
// 1. Схема: CREATE TABLE IF NOT EXISTS (порядок важен: ФК-зависимости)
// ---------------------------------------------------------------------------

$tables = [
    'categories' => "CREATE TABLE IF NOT EXISTS `categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) NOT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'sockets' => "CREATE TABLE IF NOT EXISTS `sockets` (
  `socket_id` int NOT NULL AUTO_INCREMENT,
  `socket_type` varchar(255) NOT NULL,
  PRIMARY KEY (`socket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'users' => "CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_name` varchar(20) NOT NULL,
  `user_surname` varchar(30) DEFAULT NULL,
  `user_login` varchar(25) NOT NULL,
  `user_pass` varchar(255) NOT NULL,
  `user_group` varchar(10) NOT NULL,
  `user_regdate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `user_address` varchar(1000) DEFAULT NULL,
  `user_region` varchar(100) DEFAULT NULL,
  `user_email` varchar(50) DEFAULT NULL,
  `user_number` varchar(13) DEFAULT NULL,
  `user_city` varchar(100) DEFAULT NULL,
  `user_street` varchar(150) DEFAULT NULL,
  `user_house` varchar(20) DEFAULT NULL,
  `user_apartment` varchar(20) DEFAULT NULL,
  `user_postal_code` varchar(10) DEFAULT NULL,
  -- 7: верификация контактов: раздельные заявки и результаты
  `email_verification_requested` tinyint(1) NOT NULL DEFAULT '0',
  `phone_verification_requested` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `phone_verified` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `user_login` (`user_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'login_attempts' => "CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `login` varchar(25) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `attempt_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `login` (`login`),
  KEY `ip` (`ip`),
  KEY `login_time` (`login`, `attempt_time`),
  KEY `ip_time` (`ip`, `attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'components' => "CREATE TABLE IF NOT EXISTS `components` (
  `component_id` int NOT NULL AUTO_INCREMENT,
  `component_name` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `category_id` int NOT NULL,
  `socket_id` int DEFAULT NULL,
  `video_core` tinyint(1) DEFAULT NULL,
  `tdp` int DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `component_price` int NOT NULL,
  `amount` int NOT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `specs` json DEFAULT NULL,
  `ram_type` varchar(10) DEFAULT NULL,
  `capacity_gb` int DEFAULT NULL,
  `frequency_mhz` int DEFAULT NULL,
  `memory_type` varchar(20) DEFAULT NULL,
  `wattage` int DEFAULT NULL,
  `interface` varchar(20) DEFAULT NULL,
  `form_factor` varchar(20) DEFAULT NULL,
  `rpm` int DEFAULT NULL,
  `cooler_type` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`component_id`),
  KEY `category_id` (`category_id`, `socket_id`),
  KEY `idx_cat_price` (`category_id`, `component_price`),
  CONSTRAINT `components_ibfk_1` FOREIGN KEY (`socket_id`) REFERENCES `sockets` (`socket_id`),
  CONSTRAINT `components_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'assembly' => "CREATE TABLE IF NOT EXISTS `assembly` (
  `assembly_id` int NOT NULL AUTO_INCREMENT,
  `assembly_name` varchar(255) NOT NULL,
  `cpu_id` int NOT NULL,
  `gpu_id` int DEFAULT NULL,
  `motherboard_id` int NOT NULL,
  `ram_id` int NOT NULL,
  `case_id` int NOT NULL,
  `cooler_id` int NOT NULL,
  `power_supply_id` int NOT NULL,
  `ssd_id` int NOT NULL,
  `os` varchar(255) DEFAULT NULL,
  `ssd_2_id` int DEFAULT NULL,
  `hdd_id` int DEFAULT NULL,
  `dvd_id` int DEFAULT NULL,
  `assembly_price` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assembly_id`),
  KEY `cpu_id` (`cpu_id`, `gpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `ssd_2_id`, `hdd_id`, `dvd_id`),
  KEY `gpu_id` (`gpu_id`),
  KEY `motherboard_id` (`motherboard_id`),
  KEY `ram_id` (`ram_id`),
  KEY `case_id` (`case_id`),
  KEY `cooler_id` (`cooler_id`),
  KEY `power_supply_id` (`power_supply_id`),
  KEY `ssd_id` (`ssd_id`),
  KEY `ssd_2_id` (`ssd_2_id`),
  KEY `hdd_id` (`hdd_id`),
  KEY `dvd_id` (`dvd_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `assembly_ibfk_1` FOREIGN KEY (`cpu_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_2` FOREIGN KEY (`gpu_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_3` FOREIGN KEY (`motherboard_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_4` FOREIGN KEY (`ram_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_5` FOREIGN KEY (`case_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_6` FOREIGN KEY (`cooler_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_7` FOREIGN KEY (`power_supply_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_8` FOREIGN KEY (`ssd_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_9` FOREIGN KEY (`ssd_2_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_10` FOREIGN KEY (`hdd_id`) REFERENCES `components` (`component_id`),
  CONSTRAINT `assembly_ibfk_11` FOREIGN KEY (`dvd_id`) REFERENCES `components` (`component_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'favorites' => "CREATE TABLE IF NOT EXISTS `favorites` (
  `favorit_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `assembly_id` int NOT NULL,
  PRIMARY KEY (`favorit_id`),
  KEY `user_id` (`user_id`, `assembly_id`),
  KEY `assembly_id` (`assembly_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'orders' => "CREATE TABLE IF NOT EXISTS `orders` (
  `order_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `assembly_id` int NOT NULL,
  `status` varchar(50) DEFAULT 'Обрабатывается',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `user_id` (`user_id`, `assembly_id`),
  KEY `orders_ibfk_1` (`assembly_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`assembly_id`) REFERENCES `assembly` (`assembly_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'site_settings' => "CREATE TABLE IF NOT EXISTS `site_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` mediumtext,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

mig_log('=== миграция схемы ===');
foreach ($tables as $name => $ddl) {
    if (table_exists($mysql, $name)) {
        mig_log("  [skip] $name (уже есть)");
        continue;
    }
    mig_exec($mysql, $dryRun, $ddl);
    mig_log("  [create] $name");
}

// ---------------------------------------------------------------------------
// 2. ALTER-миграции: добавление колонок с проверкой information_schema.
//    Для свежесозданных таблиц все колонки уже в DDL - блок оставлен как
//    точка входа будущих миграций существующих развёртываний.
// ---------------------------------------------------------------------------

$columnMigrations = [
    // ['table', 'column', 'ALTER-выражение после ADD COLUMN']
    // пример будущей миграции:
    // ['users', 'two_factor', "ALTER TABLE `users` ADD COLUMN `two_factor` tinyint(1) NOT NULL DEFAULT '0'"],
];

$columnExists = static function (mysqli $mysql, string $table, string $column): bool {
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row()[0];
};

mig_log('=== ALTER-миграции ===');
foreach ($columnMigrations as [$table, $column, $alter]) {
    if ($columnExists($mysql, $table, $column)) {
        mig_log("  [skip] {$table}.{$column} (уже есть)");
        continue;
    }
    mig_exec($mysql, $dryRun, $alter);
    mig_log("  [alter] {$table}.{$column}");
}
if ($columnMigrations === []) {
    mig_log('  нет активных');
}

// ---------------------------------------------------------------------------
// 3. Минимальные данные
// ---------------------------------------------------------------------------

mig_log('=== минимальные данные ===');

$categories = [1 => 'Процессор', 2 => 'Материнская плата', 3 => 'Видеокарта', 4 => 'Оперативная память', 5 => 'Блок питания', 6 => 'Корпус', 7 => 'Кулер', 8 => 'HDD', 9 => 'SSD'];
foreach ($categories as $id => $name) {
    $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM categories WHERE category_id = ?", "i", $id);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        mig_log("  [skip] category $id");
        continue;
    }
    mig_exec($mysql, $dryRun, "INSERT INTO categories (category_id, category_name) VALUES (?, ?)", [(string) $id, $name], "is");
    mig_log("  [insert] category $id ($name)");
}

$sockets = [1 => 'LGA1200', 2 => 'LGA1700', 3 => 'AM4', 4 => 'LGA1151', 5 => 'LGA1851', 6 => 'AM5', 7 => 'LGA1150'];
foreach ($sockets as $id => $type) {
    $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM sockets WHERE socket_id = ?", "i", $id);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        mig_log("  [skip] socket $id");
        continue;
    }
    mig_exec($mysql, $dryRun, "INSERT INTO sockets (socket_id, socket_type) VALUES (?, ?)", [$id, $type], "is");
    mig_log("  [insert] socket $id ($type)");
}

// 5-f-2: настройки сайта. Недостающие ключи вставляются, существующие
// значения не перетираются: админ мог уже вписать реальные контакты.
//
// Stage 9: брендинг. Название сайта, описание и картинки бренда
// вынесены из шаблонов в настройки, чтобы проект можно было переиспользовать
// как шаблон. Значения ниже - дефолты демо-проекта, их правит админ на
// вкладке «Настройки сайта».
//
// Фавиконов два: svg для современных браузеров и png для старых, которые
// svg не понимают. Одним ключом не обойтись - старый браузер показал бы
// иконку браузера по умолчанию.
$settings = [
    'map_address_text' => 'Москва, ул. Победы, д. 15',
    'map_lat' => '55.7558',
    'map_lng' => '37.6173',
    'map_zoom' => '14',
    'map_snapshot_url' => '',
    'contact_phone' => '+7 (999) 999-99-99',
    'contact_email' => 'mail@mail.ru',
    'contact_vk' => '',
    'contact_telegram' => '',
    'contact_whatsapp' => '',
    // Stage 9: брендинг. Название одно - site_name. Раньше было два ключа
    // («короткое» и «полное»), но различать их было незачем: в шапке,
    // подвале, заголовке вкладки и на главной всё равно требовалось одно
    // и то же написание, а редактировать приходилось два поля.
    'site_name' => 'Aion Corporation',
    // Год основания, а не текущий год: проект основан один раз, и
    // «© 2026» вместо «© 2022» менялось бы само собой каждый январь.
    'site_founded_year' => '2022',
    'site_description' => 'Уникальные компьютеры для игр, стриминга, работы с графикой, видео и большими объёмами данных',
    'site_logo_url' => '/assets/images/logo.png',
    'site_favicon_url' => '/assets/images/favicon.svg',
    'site_favicon_png_url' => '/assets/images/favicon.png',
    // Копирайт по умолчанию пустой: пустое значение означает «© год
    // основания + название», и при смене бренда подвал меняется сам.
    // Иначе в нём навсегда осталось бы имя прежнего владельца.
    'site_footer_copyright' => '',
];
foreach ($settings as $key => $value) {
    $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM site_settings WHERE setting_key = ?", "s", $key);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        mig_log("  [skip] setting $key");
        continue;
    }
    mig_exec($mysql, $dryRun, "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value], "ss");
    mig_log("  [insert] setting $key");
}

// Одноразовая миграция: два названия -> одно.
//
// Раньше настройки назывались site_name («короткое») и site_name_full
// («полное»). Разделение было лишним, и теперь значения должны быть
// слиты. Порядок важен: сначала значение переносится в site_name, и
// только потом старый ключ удаляется, иначе потерялось бы и то, и другое.
$stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'site_name_full'", "");
$stmt->execute();
$legacyFullName = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));

if ($legacyFullName !== '') {
    $stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'site_name'", "");
    $stmt->execute();
    $currentName = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));

    if ($currentName === '') {
        // Переносим единственное непустое значение
        mig_exec(
            $mysql,
            $dryRun,
            "UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_name'",
            [$legacyFullName],
            "s"
        );
        mig_log("  [migrate] site_name <- site_name_full ($legacyFullName)");
    } else {
        // Оба непустые: оставляем site_name как есть - он и есть
        // каноническое название, - но сообщаем, что было расхождение
        mig_log("  [migrate] site_name_full ($legacyFullName) удаляется, site_name ($currentName) остаётся");
    }
}

mig_exec($mysql, $dryRun, "DELETE FROM site_settings WHERE setting_key = 'site_name_full'", []);
mig_log("  [migrate] удалён ключ site_name_full");

$mysql->close();
mig_log('=== migrate завершён ===');