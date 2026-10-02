<?php
/**
 * Контракт данных строки заказа для модалки (Stage 3.7-g-3).
 *
 * Один сборщик на две таблицы: заказы во вкладке и «Последние заказы»
 * на дашборде. JS открывает модалку по data-row и читает ключи по
 * именам, поэтому формат обязан быть один - раньше он жил только в
 * _tab_orders.php.
 *
 * Ожидает колонки:
 *   order_id, status, created_at, user_id, user_name, user_surname,
 *   user_login, user_group, user_email, user_number, user_postal_code,
 *   user_region, user_city, user_street, user_house, user_apartment,
 *   user_regdate, asm_id, assembly_name, assembly_price
 *
 * Требует, чтобы в $row уже было подставлено имя сборки с префиксом
 * «Сборка » для id > 3 — это делает вызывающий код, правило общее
 * для обеих таблиц.
 */
function adminOrderRowData(array $row): array
{
    return [
        'modal' => 'order',
        'id' => $row['order_id'],
        'user_id' => $row['buyer_id'],
        'buyer' => trim(($row['user_name'] ?? '') . ' ' . ($row['user_surname'] ?? '')),
        'user_email' => $row['user_email'],
        'user_number' => $row['user_number'],
        'address' => $row['user_address'] ?? null,
        // 3.7-f-4-2: данные покупателя для перехода в его модалку
        'user_name' => $row['user_name'],
        'user_surname' => $row['user_surname'],
        'user_login' => $row['user_login'],
        'user_group' => $row['user_group'],
        // 3.7-i-2: адрес разбит на поля, address остаётся legacy-строкой
        'user_postal_code' => $row['user_postal_code'],
        'user_region' => $row['user_region'],
        'user_city' => $row['user_city'],
        'user_street' => $row['user_street'],
        'user_house' => $row['user_house'],
        'user_apartment' => $row['user_apartment'],
        // 3.7-g-7: дата регистрации покупателя. Отформатирована здесь, а не
        // в JS: оба вызывающих отдают это поле в модалку покупателя, и
        // раньше там было пусто - из JSON кнопки покупателя (scripts.js)
        // ключ regdate просто не передавался
        'user_regdate' => !empty($row['user_regdate'])
            ? date('d.m.Y', strtotime((string) $row['user_regdate']))
            : '',
        'assembly_id' => $row['asm_id'],
        'assembly_name' => $row['assembly_name'],
        'assembly_price' => $row['assembly_price'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
    ];
}