<?php
/**
 * Восстановление контактов из legacy-значений.
 *
 * 19:12:11 три ключа, которые были непустыми (contact_phone,
 * contact_email, map_address_text), оказались пустыми. Записала их
 * отправка формы настроек целиком, с пустыми полями контактов.
 *
 * Берём значения из legacy_init.sql - это то, что было в базе до
 * затирания (дамп shop_db, снятый в 19:06, содержал ровно их).
 * Пишем через site_setting_save: русский текст через mysql CLI из
 * PowerShell проходит через кодировку консоли и даёт двойной UTF-8.
 */
declare(strict_types=1);
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../modules/site.php';
require_once __DIR__ . '/../modules/connect.php';

$legacy = [
    'contact_phone' => '+7 (999) 999-99-99',
    'contact_email' => 'mail@mail.ru',
    'map_address_text' => 'Москва, ул. Победы, д. 15',
];

$mysql = connect();
mysqli_set_charset($mysql, 'utf8mb4');
site_setting_save($mysql, $legacy);

$st = db_prepare(
    $mysql,
    "SELECT setting_key, setting_value FROM site_settings
     WHERE setting_key IN ('contact_phone', 'contact_email', 'map_address_text')
     ORDER BY setting_key",
    ""
);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$mysql->close();

foreach ($rows as $row) {
    // hex рядом со значением: так видно, что это честный UTF-8,
    // а не «двойной UTF-8», который даёт запись через консоль.
    printf("  %-18s [%s]  hex=%s\n", $row['setting_key'], $row['setting_value'], bin2hex($row['setting_value']));
}