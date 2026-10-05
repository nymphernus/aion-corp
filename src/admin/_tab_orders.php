<?php
/**
 * Вкладка «Заказы» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=orders).
 * Прямой запрос к файлу → 404.
 *
 * 3.7-f-3: переведено с legacy-разметки .assemblyTable (span-строки
 * с фиксированными ширинами) на .table из base.css — таблица
 * тянется на всю ширину карточки. Имена POST-полей не менялись.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card admin-panel">
                    <h1 class="page-title">Управление заказами</h1>

                    <!-- 3.7-f-4-3: фильтр по статусу и поиск по покупателю
                         (3.7-f-4b-4: сортировка убрана) -->
                    <form method="get" class="admin-filters">
                        <input type="hidden" name="tab" value="orders">

                        <select name="status" class="input">
<?php
    $statusOptions = ['' => 'Все статусы', 'Обрабатывается' => 'Обрабатывается',
        'Собирается' => 'Собирается', 'Доставляется' => 'Доставляется',
        'Выполнен' => 'Выполнен', 'Отменён' => 'Отменён'];
    $curStatus = (string) ($_GET['status'] ?? '');
    foreach ($statusOptions as $val => $label):
?>
                            <option value="<?= escape($val) ?>"<?= $curStatus === $val ? ' selected' : '' ?>><?= escape($label) ?></option>
<?php endforeach; ?>
                        </select>

                        <input type="search" name="q" class="input" placeholder="Поиск покупателя..."
                               value="<?= escape((string) ($_GET['q'] ?? '')) ?>">

                        <button type="submit" class="btn btn--primary">Применить</button>

<?php if (trim((string) ($_GET['status'] ?? '')) !== '' || trim((string) ($_GET['q'] ?? '')) !== ''): ?>
                        <a href="?tab=orders" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
                    </form>
<?php
                    $checkSql = "SHOW COLUMNS FROM orders LIKE 'status'";
                    $checkStmt = db_prepare($mysql, $checkSql);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();

                    if ($checkResult && $checkResult->num_rows > 0) {
                        // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php

                        $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,
                                       users.user_id AS buyer_id, assembly.assembly_id AS asm_id,
                                       orders.created_at, users.user_email, users.user_number,
                                       users.user_login, users.user_group,
                                       users.user_postal_code, users.user_region, users.user_city,
                                       users.user_street, users.user_house, users.user_apartment,
                                       users.user_regdate,
                                       users.email_verified, users.email_verification_requested,
                                       users.phone_verified, users.phone_verification_requested
                                FROM users,assembly,orders
                                WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id"
                                . $listWhere . "
                                ORDER BY {$listOrder} LIMIT ? OFFSET ?";
                        // 3.7-f-4-3: параметры фильтров идут перед LIMIT/OFFSET
                        if ($listParams === []) {
                            $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                        } else {
                            $stmt = db_prepare($mysql, $sql, $listTypes . "ii", ...array_merge($listParams, [$perPage, $offset]));
                        }
                        $stmt->execute();
                        $result = $stmt->get_result();

                        echo "<div class=\"table-wrap\"><table class=\"table\">
                            <thead><tr>
                                <th>Покупатель</th>
                                <th>Адрес</th>
                                <th>Сборка</th>
                                <th>Стоимость</th>
                                <th>Статус</th>
                            </tr></thead><tbody>";
                        if ($result) {
                            while ($row = $result->fetch_array()) {
                                // 3.7-h-1: колонка переименована в asm_id, условие обновлено
                                if ($row['asm_id'] > 3) {
                                    $row['assembly_name'] = "Сборка " . ($row['assembly_name'] ?? '');
                                }
                                // FIX-3: колонка адреса - из user_city, как в таблице пользователей.
                                // Раньше брала первую часть legacy user_address,
                                // поэтому показывала устаревшую строку.
                                $addr = trim((string) ($row['user_city'] ?? ''));
                                $shortAddress = $addr !== '' ? $addr : 'Не указан';

                                // 3.7-h-1: данные строки для модалки заказа.
                                // 3.7-g-3: сборщик вынесен в admin/_order_row_data.php,
                                // тем же пользуется дашборд - формат один.
                                $rowData = json_encode(adminOrderRowData($row));
                                echo "<tr data-row='" . escape($rowData) . "'>"
                                    . "<td>" . htmlspecialchars(($row['user_name'] ?? '') . " " . ($row['user_surname'] ?? '')) . "</td>"
                                    // 3.7-f-3-7: в таблице только город (первая часть до запятой),
                                    // полный адрес - в модалке заказа
                                    . "<td>" . htmlspecialchars($shortAddress) . "</td>"
                                    . "<td>" . htmlspecialchars($row['assembly_name'] ?? '') . "</td>"
                                    . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                    // 3.7-f-2-4: статус стал бейджем, смена - в модалке заказа
                                    . "<td><span class=\"badge " . match ($row['status'] ?? '') {
                                        'Выполнен' => 'badge--success',
                                        'Отменён' => 'badge--error',
                                        // 3.7-f-3-4: «Обрабатывается» тоже цветной,
                                        // иначе статус не читается как статус
                                        default => 'badge--warning',
                                    } . "\">" . htmlspecialchars($row['status'] ?? '') . "</span></td>"
                                    . "</tr>";
                            }
                        }
                        echo "</tbody></table></div>";
                        echo render_pagination('orders', $page, $pages, $listQuery);
                    } else {
                        echo "<p>Столбец status отсутствует в таблице orders</p>";
                    }
?>
                </section>

                <!-- 3.7-g-3: модалка заказа вынесена в
                     partials/admin-order-modal.php и подключается из admin.php -
                     её открывает и таблица заказов, и дашборд -->
