<?php
/** Вернуть favicon-настройки и иконку к дефолту A / #C99CFF / чёрная буква. */
declare(strict_types=1);
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../modules/site.php';
require_once __DIR__ . '/../modules/connect.php';
require_once __DIR__ . '/../modules/image.php';

$mysql = connect();
mysqli_set_charset($mysql, 'utf8mb4');
site_setting_save($mysql, [
    'favicon_letter' => 'A',
    'favicon_bg' => '#C99CFF',
    'favicon_text' => '#000000',
    'favicon_auto_color' => '0',
]);

$path = generate_favicon('A', '#C99CFF', '#000000');
echo 'иконка: ' . var_export($path, true) . "\n";

$st = db_prepare($mysql, "SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'favicon%' OR setting_key LIKE 'site_favicon%' ORDER BY setting_key", "");
$st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    printf("  %-22s %s\n", $row['setting_key'], $row['setting_value'] === '' ? '(пусто)' : $row['setting_value']);
}
$mysql->close();