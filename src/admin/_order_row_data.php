<?php
/**
 * Контракт данных строки заказа для модалки .
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
 * «Сборка » для результатов конфигуратора - это делает вызывающий
 * код, правило общее для обеих таблиц. Признак пользовательской
 * сборки - флаг assembly.is_base, приходящий как asm_is_base, а не
 * номер: номер у сборок витрины может быть любым.
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
        // данные покупателя для перехода в его модалку
        'user_name' => $row['user_name'],
        'user_surname' => $row['user_surname'],
        'user_login' => $row['user_login'],
        'user_group' => $row['user_group'],
        // адрес разбит на поля, address остаётся legacy-строкой
        'user_postal_code' => $row['user_postal_code'],
        'user_region' => $row['user_region'],
        'user_city' => $row['user_city'],
        'user_street' => $row['user_street'],
        'user_house' => $row['user_house'],
        'user_apartment' => $row['user_apartment'],
        // дата регистрации покупателя. Отформатирована здесь, а не
        // в JS: оба вызывающих отдают это поле в модалку покупателя, и
        // раньше там было пусто - из JSON кнопки покупателя (scripts.js)
        // ключ regdate просто не передавался
        'user_regdate' => !empty($row['user_regdate'])
            ? date('d.m.Y', strtotime((string) $row['user_regdate']))
            : '',
        // 7: флаги верификации покупателя. Нужны блоку «Верификация» в его
        // модалке, которая открывается сюда кнопкой покупателя. Без них
        // кнопка «Подтвердить» всегда была бы disabled, то есть блок
        // выглядел бы работающим, но ничего не делал.
        //
        // Имена с префиксом user_ - как и остальные ключи покупателя в
        // этом сборщике. Флаги приходят из разных таблиц (users и orders),
        // поэтому префикс снимает неоднозначность.
        'user_email_verified' => (int) ($row['email_verified'] ?? 0),
        'user_email_verification_requested' => (int) ($row['email_verification_requested'] ?? 0),
        'user_phone_verified' => (int) ($row['phone_verified'] ?? 0),
        'user_phone_verification_requested' => (int) ($row['phone_verification_requested'] ?? 0),
        'assembly_id' => $row['asm_id'],
        'assembly_name' => $row['assembly_name'],
        'assembly_price' => $row['assembly_price'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
    ];
}
