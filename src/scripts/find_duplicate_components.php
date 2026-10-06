<?php
/**
 * Поиск дублирующих компонентов по названию и категории.
 *
 * Дубли появляются при повторном запуске seed_components.php: скрипт
 * проверяет существование по имени, но не по категории, и при
 * частичном совпадении добавляет вторую строку.
 *
 * Скрипт только показывает дубли и их привязки к сборкам и избранному.
 * Удаление выполняется вручную после проверки.
 *
 * Запуск: php scripts/find_duplicate_components.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../modules/connect.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

$mysql = connect();
mysqli_set_charset($mysql, 'utf8');

$stmt = db_prepare(
    $mysql,
    'SELECT component_name, category_id, COUNT(*) AS cnt,
            GROUP_CONCAT(component_id ORDER BY component_id) AS ids,
            GROUP_CONCAT(component_price ORDER BY component_id) AS prices
       FROM components
      GROUP BY component_name, category_id
     HAVING cnt > 1
     ORDER BY cnt DESC, component_name',
    ''
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows) {
    echo "Дублей не найдено.\n";
    $mysql->close();
    exit(0);
}

echo "Дублирующие компоненты\n";
echo "======================\n\n";

foreach ($rows as $row) {
    $ids = explode(',', $row['ids']);
    $prices = explode(',', $row['prices']);
    echo "{$row['component_name']} (категория {$row['category_id']}) — {$row['cnt']} шт.\n";

    foreach ($ids as $i => $id) {
        $id = (int) $id;

        // Проверка привязок
        $stmt = db_prepare(
            $mysql,
            'SELECT COUNT(*) AS c FROM assembly WHERE
                cpu_id = ? OR motherboard_id = ? OR gpu_id = ? OR ram_id = ?
                OR case_id = ? OR cooler_id = ? OR power_supply_id = ? OR ssd_id = ?
                OR ssd_2_id = ? OR hdd_id = ? OR dvd_id = ?',
            'iiiiiiiiiii',
            ...array_fill(0, 11, $id)
        );
        $stmt->execute();
        $inAssembly = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $stmt = db_prepare(
            $mysql,
            'SELECT COUNT(*) AS c FROM favorites WHERE assembly_id IN (
                SELECT assembly_id FROM assembly WHERE
                    cpu_id = ? OR motherboard_id = ? OR gpu_id = ? OR ram_id = ?
                    OR case_id = ? OR cooler_id = ? OR power_supply_id = ? OR ssd_id = ?
                    OR ssd_2_id = ? OR hdd_id = ? OR dvd_id = ?
            )',
            'iiiiiiiiiii',
            ...array_fill(0, 11, $id)
        );
        $stmt->execute();
        $inFavorites = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $status = $inAssembly > 0 ? "в {$inAssembly} сборках" : ($inFavorites > 0 ? "в избранном" : "не используется");
        echo "  #{$id}  {$prices[$i]} ₽  — {$status}\n";
    }
    echo "\n";
}

$mysql->close();
