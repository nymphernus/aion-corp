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
                    <h2>Управление заказами</h2>

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
                    $checkStmt = $mysql->prepare($checkSql);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();

                    if ($checkResult && $checkResult->num_rows > 0) {
                        // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php

                        $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,
                                       users.user_id AS buyer_id, assembly.assembly_id AS asm_id,
                                       orders.created_at, users.user_email, users.user_number,
                                       users.user_login, users.user_group,
                                       users.user_postal_code, users.user_region, users.user_city,
                                       users.user_street, users.user_house, users.user_apartment
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

                                // 3.7-h-1: данные строки для модалки заказа
                                $rowData = json_encode([
                                    'modal' => 'order',
                                    'id' => $row['order_id'],
                                    'user_id' => $row['buyer_id'],
                                    'buyer' => trim(($row['user_name'] ?? '') . ' ' . ($row['user_surname'] ?? '')),
                                    'user_email' => $row['user_email'],
                                    'user_number' => $row['user_number'],
                                    'address' => $row['user_address'],
                                    // 3.7-f-4-2: данные покупателя для перехода в его модалку
                                    'user_name' => $row['user_name'],
                                    'user_surname' => $row['user_surname'],
                                    'user_login' => $row['user_login'],
                                    'user_group' => $row['user_group'],
                                    // 3.7-i-2: адрес покупателя разбит на поля,
                                    // address остаётся legacy-строкой
                                    'user_postal_code' => $row['user_postal_code'],
                                    'user_region' => $row['user_region'],
                                    'user_city' => $row['user_city'],
                                    'user_street' => $row['user_street'],
                                    'user_house' => $row['user_house'],
                                    'user_apartment' => $row['user_apartment'],
                                    'assembly_id' => $row['asm_id'],
                                    'assembly_name' => $row['assembly_name'],
                                    'assembly_price' => $row['assembly_price'],
                                    'status' => $row['status'],
                                    'created_at' => $row['created_at'],
                                ]);
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

                <!--
                    3.7-h-1: модалка заказа — детали, смена статуса,
                    ссылки на профиль покупателя и на сборку.
                    Отправляет name="editOrder" (новый обработчик в admin.php);
                    существующий editOrderStatus в таблице не тронут.
                -->
                <dialog id="editOrderModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=orders">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="orderId" id="editOrderId" value="">

                        <h2>Заказ №<span id="editOrderNumber"></span></h2>

                        <div class="modal-section">
                            <h3>Информация о покупателе</h3>
                            <div class="form-group">
                                <label class="form-label" for="editOrderBuyerBtn">Покупатель</label>
                                <!-- 3.7-f-3-2: кнопка вместо ссылки, клик откроет
                                     модалку пользователя (f-4) -->
                                <button type="button" class="btn btn--secondary btn--sm"
                                        id="editOrderBuyerBtn" data-action="open-user-from-order" data-user-id="">
                                    <span id="editOrderBuyerName"></span>
                                </button>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Контакты</label>
                                <div id="editOrderContacts"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Адрес доставки</label>
                                <div id="editOrderAddress"></div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Информация о сборке</h3>
                            <div class="form-group">
                                <label class="form-label" for="editOrderAssemblyBtn">Сборка</label>
                                <!-- 3.7-f-3-2: остаётся <a>, но оформлен кнопкой и
                                     открывается в новой вкладке -->
                                <a href="#" id="editOrderAssemblyBtn" target="_blank" rel="noopener"
                                   class="btn btn--secondary btn--sm"><span id="editOrderAssemblyName"></span></a>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Стоимость</label>
                                <div><span id="editOrderPrice"></span> руб.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Создан</label>
                                <div id="editOrderCreated"></div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Статус заказа</h3>
                            <div class="form-group">
                                <select class="input" name="status" id="editOrderStatusSelect">
                                    <option value="Обрабатывается">Обрабатывается</option>
                                    <option value="Собирается">Собирается</option>
                                    <option value="Доставляется">Доставляется</option>
                                    <option value="Выполнен">Выполнен</option>
                                    <option value="Отменён">Отменён</option>
                                </select>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="editOrder" class="btn btn--primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                </dialog>