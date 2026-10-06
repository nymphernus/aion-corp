<?php
/**
 * Идемпотентные миграции схемы и базовых данных .
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
  `user_email` varchar(100) DEFAULT NULL,
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
  `is_base` tinyint(1) NOT NULL DEFAULT 0,
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
  KEY `idx_base` (`is_base`,`assembly_id`),
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

    // Соцсети вынесены из site_settings отдельной таблицей. В site_settings
    // для трёх площадок понадобилось три ключа contact_* и по одной строке
    // в коде главной на каждую: четвёртую соцсеть было не добавить без
    // правки PHP.
    //
    // Составной индекс под порядок вывода: выборка на главной идёт всегда
    // по (sort_order, is_active), а link_id в конце нужен, чтобы порядок не
    // прыгал между строками с одинаковым sort_order.
    'social_links' => "CREATE TABLE IF NOT EXISTS `social_links` (
  `link_id` int NOT NULL AUTO_INCREMENT,
  `link_name` varchar(50) NOT NULL,
  `link_url` varchar(255) NOT NULL,
  `link_icon` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`link_id`),
  KEY `sort_active` (`sort_order`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Пресеты бюджета и операционные системы конфигуратора.
    // И то и другое лежало в разметке главной и в коде configurator.php:
    // четыре кнопки с data-budget и инлайновыми SVG плюс строки
    // 'windows' => 11000. Чтобы поменять цену Windows, нужно было
    // править PHP и выкатывать.
    //
    // Индекс sort_active под порядок вывода: главная выбирает всегда
    // по (sort_order, is_active), а id в конце - чтобы порядок не
    // прыгал между строками с одинаковым sort_order.
    'configurator_presets' => "CREATE TABLE IF NOT EXISTS `configurator_presets` (
  `preset_id` int NOT NULL AUTO_INCREMENT,
  `preset_name` varchar(50) NOT NULL,
  `preset_budget` int NOT NULL,
  `preset_icon` varchar(30) NOT NULL DEFAULT 'monitor',
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`preset_id`),
  KEY `sort_active` (`sort_order`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // os_price добавляется к цене сборки сверх бюджета. Ноль означает
    // бесплатно, а не «не задано»: Без ОС и Ubuntu стоят ноль, и
    // различать их нечего.
    'configurator_os' => "CREATE TABLE IF NOT EXISTS `configurator_os` (
  `os_id` int NOT NULL AUTO_INCREMENT,
  `os_name` varchar(100) NOT NULL,
  `os_price` int NOT NULL DEFAULT 0,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`os_id`),
  KEY `sort_active` (`sort_order`, `is_active`)
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
    // is_base отличает базовые сборки витрины от результатов конфигуратора.
    //
    // До этого признаком служил номер: сборки 1-3 из сида считались
    // базовыми, всё остальное - пользовательским. Номер - не признак:
    // админ создаёт четвёртую базовую сборку, она получает номер 4, и
    // cleanup_orphans.php удалял её через час как сироту. Флаг решает
    // это и заодно снимает хрупкое сравнение в пяти других местах кода.
    ['assembly', 'is_base', "ALTER TABLE `assembly` ADD COLUMN `is_base` tinyint(1) NOT NULL DEFAULT 0"],
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

    // Разметка сидовых сборок живёт здесь, а не отдельным шагом ниже.
    //
    // Причина: базовыми были ровно сборки 1-3, и других базовых на базе
    // не было. Повторять UPDATE «пометить 1-3» при каждом запуске
    // опасно: на чистой базе первая сборка, созданная админом, получает
    // номер 1 - и следующий запуск migrate.php пометил бы её как базовую
    // без всяких оснований. Привязка к появлению колонки выполняет
    // разметку ровно один раз и на той базе, где она нужна.
    if ($table === 'assembly' && $column === 'is_base') {
        mig_exec(
            $mysql,
            $dryRun,
            'UPDATE assembly SET is_base = 1 WHERE assembly_id IN (1, 2, 3)'
        );
        mig_log('  [data] сборки 1-3 помечены как базовые');
    }
}
if ($columnMigrations === []) {
    mig_log('  нет активных');
}

// Индексы отдельным списком от $columnMigrations: там проверяется
// «колонка есть», а у индекса своя проверка - существование в
// information_schema.STATISTICS. Смешивать их нельзя ещё и потому, что
// один ALTER может добавить и то и другое, - тогда пропуск по колонке
// тихо оставил бы базу без индекса.
$indexMigrations = [
    // Главная берёт базовые сборки одним запросом, cleanup - оставшиеся.
    // На текущем объёме (десятки строк) полный проход дешевле любого
    // индекса, но список витрины растёт вместе с базой, и idx_base
    // покрывает оба запроса сразу: сборка и фильтр по флагу.
    ['assembly', 'idx_base', 'ALTER TABLE `assembly` ADD INDEX `idx_base` (`is_base`, `assembly_id`)'],
];

$indexExists = static function (mysqli $mysql, string $table, string $index): bool {
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row()[0];
};

mig_log('=== миграция индексов ===');
foreach ($indexMigrations as [$table, $index, $alter]) {
    if ($indexExists($mysql, $table, $index)) {
        mig_log("  [skip] {$table}.{$index} (уже есть)");
        continue;
    }
    mig_exec($mysql, $dryRun, $alter);
    mig_log("  [index] {$table}.{$index}");
}
if ($indexMigrations === []) {
    mig_log('  нет активных');
}

// Расширение типов существующих колонок. Отдельный список от
// $columnMigrations: там проверка «колонка есть», а здесь надо «колонка
// уже нужной длины». Проверка по existence пропустила бы миграцию на
// любой базе, где колонка когда-то была создана, - то есть ровно там,
// где она нужна.
$columnWidenings = [
    // email стал обязательным при регистрации, и 50 символов перестало
    // хватать: реальные адреса с длинными доменами вроде
    // very.long.subdomain@company-domain.example.com в них не влезали.
    // Расширение varchar ничего не теряет, поэтому проверять, чем
    // заполнены существующие строки, не нужно.
    ['users', 'user_email', 100, "ALTER TABLE `users` MODIFY COLUMN `user_email` varchar(100) DEFAULT NULL"],
];

$columnLength = static function (mysqli $mysql, string $table, string $column): ?int {
    $stmt = $mysql->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row ? (int) $row[0] : null;
};

mig_log('=== Расширение типов ===');
foreach ($columnWidenings as [$table, $column, $target, $alter]) {
    $current = $columnLength($mysql, $table, $column);
    if ($current === null) {
        mig_log("  [skip] {$table}.{$column} (колонки нет)");
        continue;
    }
    if ($current >= $target) {
        mig_log("  [skip] {$table}.{$column} (уже {$current})");
        continue;
    }
    mig_exec($mysql, $dryRun, $alter);
    mig_log("  [alter] {$table}.{$column} {$current} -> {$target}");
}
if ($columnWidenings === []) {
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

// настройки сайта. Недостающие ключи вставляются, существующие
// значения не перетираются: админ мог уже вписать реальные контакты.
//
// брендинг. Название сайта, описание и картинки бренда
// вынесены из шаблонов в настройки, чтобы проект можно было переиспользовать
// как шаблон. Значения ниже - дефолты демо-проекта, их правит админ на
// вкладке «Настройки сайта».
//
// Favicon один и только PNG: его рисует генератор из буквы и цветов,
// см. modules/image.php.
$settings = [
    // контакты и карта заполняются дефолтами, а не пустыми
    // строками. Раньше ссылки соцсетей создавались пустыми, и чистая
    // база поднималась без единого способа связи: блок «Свяжитесь с
    // нами» на главной показывал только телефон и почту, а иконки
    // соцсетей исчезали. Дефолт ведёт на страницу проекта - это честнее,
    // чем пустота: админ видит незаполненное поле сразу.
    'map_address_text' => 'Москва, ул. Победы, д. 15',
    'map_lat' => '55.7558',
    'map_lng' => '37.6173',
    'map_zoom' => '12',
    'map_snapshot_url' => '',
    'contact_phone' => '+7 (987) 654-32-10',
    'contact_email' => 'mail@mail.ru',
    // contact_vk, contact_telegram и contact_whatsapp больше не в
    // дефолтах: соцсети живут в таблице social_links, а эти три ключа
    // переносятся в неё одноразовой миграцией ниже и удаляются. Пока
    // дефолты оставались здесь, повторный запуск migrate.php на старой
    // базе создавал бы ключи заново - и следующая миграция не имела бы
    // права их удалить.
    // брендинг. Название одно - site_name. Раньше было два ключа
    // («короткое» и «полное»), но различать их было незачем: в шапке,
    // подвале, заголовке вкладки и на главной всё равно требовалось одно
    // и то же написание, а редактировать приходилось два поля.
    'site_name' => 'Aion Corporation',
    // Год основания, а не текущий год: проект основан один раз, и
    // «© 2026» вместо «© 2022» менялось бы само собой каждый январь.
    'site_founded_year' => '2022',
    'site_description' => 'Уникальные компьютеры для игр, стриминга, работы с графикой, видео и большими объёмами данных',
    'site_logo_url' => '/assets/images/logo.png',
    // favicon делает генератор в branding/. Ключ
    // site_favicon_url (svg) удалён миграцией ниже.
    'site_favicon_png_url' => '/assets/images/branding/favicon.png',
    // Буква и цвета иконки. Дефолт - светлый фиолет #C99CFF с чёрной
    // буквой: так выглядел оригинальный SVG, и генератор повторяет его
    // по умолчанию. favicon_auto_color = 0 означает ручной цвет буквы.
    'favicon_letter' => 'A',
    'favicon_bg' => '#C99CFF',
    'favicon_text' => '#000000',
    'favicon_auto_color' => '0',
    // Текущий файл сгенерирован, а не загружен. Дефолт '0' обязателен:
    // база, где ключа нет, читалась бы как «неизвестно», и обычное
    // сохранение настроек затёрло бы загруженную иконку - ровно тот баг,
    // ради которого ключ и заведён.
    'favicon_is_custom' => '0',
    // Заголовок вкладки на главной. Без названия сайта: его
    // дописывает header.php, собирая «уточнение — Название».
    // Если бы название было и здесь, то при его смене заголовок главной
    // содержал бы старое имя - и заметнее всего это на главной.
    'site_home_title' => 'Интернет-магазин персональных компьютеров индивидуальной комплектации',
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

// Начальные строки конфигуратора: пресеты бюджета и ОС.
//
// Проверка на пустоту таблицы, а не на конкретные значения: миграция
// идёт при каждом развёртывании, и если админ уже добавил свой
// пресет, добивать сверху дефолтными было бы неверно. Сравнение по
// именам тоже не годится - админ вправе переименовать «Игры».
//
// Порядок и значения сняты с разметки главной (data-budget) и из
// configurator.php, где стояло 'windows' => 11000.
$configuratorSeed = [
    // [название, бюджет, иконка, порядок]
    'configurator_presets' => [
        ['Офис',      20000,  'monitor', 10],
        ['Игры',     100000,  'gamepad', 20],
        ['Работа',   250000,  'chart',   30],
        ['Максимум', 500000,  'zap',     40],
    ],
    // [название, цена, порядок]
    'configurator_os' => [
        ['Windows 10 Home', 11000, 10],
        ['Ubuntu 24.04 LTS',    0, 20],
        ['Без ОС',              0, 30],
    ],
];

foreach ($configuratorSeed as $seedTable => $seedRows) {
    if (!table_exists($mysql, $seedTable)) {
        mig_log("  [skip] $seedTable: таблицы нет (dry-run?)");
        continue;
    }
    $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM `$seedTable`");
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        mig_log("  [skip] $seedTable: строки уже есть");
        continue;
    }
    $inserted = 0;
    foreach ($seedRows as $row) {
        if ($seedTable === 'configurator_presets') {
            // s,i,s,i - имя, бюджет, иконка, порядок. Порядок типов
            // обязан совпадать с колонками: с "ssis" строка icon
            // приходилась на целое, и пресеты создавались с
            // preset_icon = 0 вместо 'monitor'.
            $sql = "INSERT INTO `$seedTable` (preset_name, preset_budget, preset_icon, sort_order) VALUES (?, ?, ?, ?)";
            $types = "sisi";
        } else {
            $sql = "INSERT INTO `$seedTable` (os_name, os_price, sort_order) VALUES (?, ?, ?)";
            $types = "sis";
        }
        mig_exec($mysql, $dryRun, $sql, $row, $types);
        $inserted++;
    }
    mig_log("  [insert] $seedTable: $inserted строк(и)");
}

// Одноразовая миграция: три ключа contact_* -> таблица social_links.
//
// Там, где три площадки, три ключа в site_settings и три строки в коде
// главной: четвёртую соцсеть было не добавить без правки PHP.
//
// Порядок важен: сначала значения переносятся, и только потом старые
// ключи удаляются - иначе потерялось бы и то, и другое.
//
// Пустые значения пропускаются. В site_settings пустая ссылка означала
// «иконки нет»: array_filter() на главной её отбрасывал. Строка с пустым
// URL в social_links - это то же самое, только теперь её ещё и видно в
// админке, где её можно заполнить.
$legacySocials = [
    // ключ в site_settings => [название, иконка, порядок]
    'contact_vk' => ['ВКонтакте', '/assets/images/social/vk.svg', 10],
    'contact_telegram' => ['Telegram', '/assets/images/social/telegram.svg', 20],
    'contact_whatsapp' => ['WhatsApp', '/assets/images/social/whatsapp.svg', 30],
];

// Таблицы social_links на dry-run нет: её создание идёт через mig_exec,
// который в dry-run ничего не выполняет, а перенос читает таблицу обычным
// SELECT. Заодно проверка страхует случай, когда создание не прошло.
if (table_exists($mysql, 'social_links')) {
    $legacyFound = false;
    foreach ($legacySocials as $legacyKey => [$label, $icon, $sort]) {
        $stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", $legacyKey);
        $stmt->execute();
        $url = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));

        if ($url === '') {
            continue;
        }
        $legacyFound = true;

        // Идемпотентность по названию: повторный запуск на уже
        // перенесённой базе не должен плодить вторую ВКонтакте.
        $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM social_links WHERE link_name = ?", "s", $label);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
            mig_log("  [skip] social_links $label уже есть");
            continue;
        }

        mig_exec(
            $mysql,
            $dryRun,
            "INSERT INTO social_links (link_name, link_url, link_icon, sort_order) VALUES (?, ?, ?, ?)",
            [$label, $url, $icon, $sort],
            'sssi'
        );
        mig_log("  [social] $label перенесён из $legacyKey");
    }

    // Ключи contact_* удаляются, как только главная перестала их читать.
    //
    // Перенос значений уже сделан блоком выше, а index.php читает
    // social_links. Раньше удалять было нельзя: пока index.php читал
    // contact_vk, а ключ уже удалён, site_setting() возвращала пустую
    // строку, и иконки соцсетей молча исчезали с главной - это было
    // проверено и послужило причиной отложить удаление на этот подэтап.
    //
    // Непустой ключ удаляется только когда его копия точно лежит в
    // social_links: иначе на базе, где перенос не сработал (таблица не
    // создалась), удалялись бы единственные копии ссылок.
    //
    // Пустой ключ удаляется безусловно: пустая ссылка означала «иконки
    // нет», переносить нечего, и держать пустой ключ незачем.
    $deletedCount = 0;
    foreach ($legacySocials as $legacyKey => [$label]) {
        // Существование проверяется отдельно: fetch_row для
        // отсутствующего ключа возвращает null, и после (string)
        // получается пустая строка - неотличимо от пустого значения.
        // Без этой проверки повторный запуск писал бы «удалён ключ»
        // про ключ, которого уже нет, и DELETE затрагивал 0 строк.
        $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM site_settings WHERE setting_key = ?", "s", $legacyKey);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            continue;
        }

        $stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", $legacyKey);
        $stmt->execute();
        $value = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
        if (trim($value) !== '') {
            $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM social_links WHERE link_name = ?", "s", $label);
            $stmt->execute();
            if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
                mig_log("  [social] ключ $legacyKey не удалён: в social_links нет «$label»");
                continue;
            }
        }

        mig_exec($mysql, $dryRun, "DELETE FROM site_settings WHERE setting_key = ?", [$legacyKey], "s");
        mig_log("  [social] удалён ключ $legacyKey");
        $deletedCount++;
    }
    if ($deletedCount > 0) {
        mig_log("  [social] удалено legacy-ключей: $deletedCount");
    }

    // Свежая установка: переносить нечего, но админу нужны три готовые
    // строки, чтобы он вписал адреса, а не заводил площадки с нуля.
    //
    // URL пустой намеренно. Прежние дефолты стояли ссылкой на репозиторий
    // GitHub - и одинаковой для VK, Telegram и WhatsApp, то есть все три
    // иконки вели в одно место. Такой «заполнитель» в таблице виден в
    // админке как незаполненная строка, а не как готовый контакт.
    if (!$legacyFound) {
        $stmt = db_prepare($mysql, "SELECT COUNT(*) FROM social_links", "");
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            foreach ($legacySocials as [$label, $icon, $sort]) {
                mig_exec(
                    $mysql,
                    $dryRun,
                    "INSERT INTO social_links (link_name, link_url, link_icon, sort_order) VALUES (?, '', ?, ?)",
                    [$label, $icon, $sort],
                    'ssi'
                );
                mig_log("  [social] создана пустая строка $label");
            }
        }
    }
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

// Одноразовая миграция: копирайт целиком -> год и название.
//
// Настройка site_footer_copyright позволяла написать подвал строкой. Но
// год и название задаются рядом, отдельными полями, и строка целиком
// требовала дублировать их значения в тексте - после смены названия в
// копирайте осталось бы имя прежнего владельца. Подвал теперь всегда
// «© {site_founded_year} {site_name}».
//
// Значение перед удалением разбирается, чтобы год основания не
// потерялся: если site_founded_year пуст или отсутствует, год берётся
// из копирайта. Символ © и год распознаются регулярным выражением -
// иначе «1999» из текста вида «(c) 1999 ООО» превратился бы в год
// основания.
$stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'site_footer_copyright'", "");
$stmt->execute();
$legacyCopyright = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));

if ($legacyCopyright !== '') {
    $stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'site_founded_year'", "");
    $stmt->execute();
    $currentYear = trim((string) ($stmt->get_result()->fetch_row()[0] ?? ''));

    if (preg_match('/(\d{4})/u', $legacyCopyright, $m) === 1 && $currentYear === '') {
        mig_exec(
            $mysql,
            $dryRun,
            "UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_founded_year'",
            [$m[1]],
            "s"
        );
        mig_log("  [migrate] site_founded_year <- «$legacyCopyright» ({$m[1]})");
    }
}

mig_exec($mysql, $dryRun, "DELETE FROM site_settings WHERE setting_key = 'site_footer_copyright'", []);
mig_log("  [migrate] удалён ключ site_footer_copyright");

// svg-favicon больше не используется.
//
// Иконка теперь одна и только PNG, которую рисует генератор из буквы и
// цвета. Ссылка на старый svg удаляется без переноса значения: подставить
// его в site_favicon_png_url нельзя, ключ объявлен как image/png, и
// браузер отверг бы такой ответ. Вместо переноса файл генерируется заново
// из favicon_letter и favicon_bg.

// Старый путь мог остаться в site_favicon_png_url, если иконку грузили
// до ПРАВКИ 4 и лежал она ещё в assets/images. Настоящий путь один -
// branding/favicon.png, иначе файл мог бы оказаться вне branding.
$stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'site_favicon_png_url'", "");
$stmt->execute();
$faviconUrl = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
if ($faviconUrl !== '/assets/images/branding/favicon.png') {
    mig_exec(
        $mysql,
        $dryRun,
        "UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_favicon_png_url'",
        ['/assets/images/branding/favicon.png'],
        "s"
    );
    mig_log("  [migrate] site_favicon_png_url -> /assets/images/branding/favicon.png (было: $faviconUrl)");
}

mig_exec($mysql, $dryRun, "DELETE FROM site_settings WHERE setting_key = 'site_favicon_url'", []);
mig_log("  [migrate] удалён ключ site_favicon_url (svg)");

// Иконку генерируем, только если её нет на диске. Существующий файл -
// это либо загруженная админом картинка, либо иконка, сгенерированная
// ранее с другими настройками; перезаписывать её при каждом старте
// контейнера означало бы терять загруженную иконку.
$faviconFile = __DIR__ . '/../assets/images/branding/favicon.png';
if (file_exists($faviconFile)) {
    mig_log("  [skip] favicon.png уже есть на диске");
} else {
    $stmt = db_prepare($mysql, "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('favicon_letter', 'favicon_bg')", "");
    $stmt->execute();
    $fav = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $fav[$row['setting_key']] = (string) $row['setting_value'];
    }
    $letter = $fav['favicon_letter'] ?? 'A';
    $bg = $fav['favicon_bg'] ?? '#C99CFF';
    $auto = ($fav['favicon_auto_color'] ?? '0') === '1';
    $text = $auto ? 'auto' : ($fav['favicon_text'] ?? '#000000');
    if (!$dryRun && function_exists('generate_favicon')) {
        $made = generate_favicon($letter, $bg, $text);
        mig_log($made !== null ? "  [favicon] сгенерирован из буквы $letter, фон $bg" : "  [favicon] не удалось сгенерировать");
    } else {
        mig_log("  [favicon] dry-run: был бы сгенерирован из буквы $letter, фон $bg");
    }
}

// Варианты иконки. Генератор и загрузка держат свои копии
// (favicon-generated.png и favicon-custom.png), а активная favicon.png -
// копия одного из них. На старой установке файлов вариантов ещё нет, и
// без добивки переключатель остался бы невидимым ни у кого.
//
// Активная иконка - это ровно один из двух вариантов, какой показывает
// флаг favicon_is_custom. Других источников взять неоткуда, поэтому
// второй вариант на этом шаге не появляется: он появится сам при первом
// переключении или после загрузки своей картинки.
$stmt = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = 'favicon_is_custom'", "");
$stmt->execute();
$favIsCustom = ((string) ($stmt->get_result()->fetch_row()[0] ?? '0')) === '1';

if (function_exists('favicon_seed_variants')) {
    $seeded = $dryRun
        ? ['generated' => !favicon_has_generated(), 'custom' => !favicon_has_custom()]
        : favicon_seed_variants($favIsCustom);

    foreach ($seeded as $which => $made) {
        if (!$made) {
            continue;
        }
        mig_log($dryRun
            ? "  [favicon] был бы создан вариант $which"
            : "  [favicon] создан вариант $which из активной иконки");
    }
    if (!$seeded['generated'] && !$seeded['custom']) {
        mig_log('  [skip] варианты иконки уже на диске');
    }
}

$mysql->close();
mig_log('=== migrate завершён ===');