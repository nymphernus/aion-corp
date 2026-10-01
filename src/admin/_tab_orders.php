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
<?php
                    $checkSql = "SHOW COLUMNS FROM orders LIKE 'status'";
                    $checkStmt = $mysql->prepare($checkSql);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();

                    if ($checkResult && $checkResult->num_rows > 0) {
                        // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php

                        $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,assembly.assembly_id FROM users,assembly,orders
                                WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id
                                ORDER BY `orders`.`order_id` ASC LIMIT ? OFFSET ?";
                        $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        echo "<div class=\"table-wrap\"><table class=\"table\">
                            <thead><tr>
                                <th>Покупатель</th>
                                <th>Адрес</th>
                                <th>Сборка</th>
                                <th>Стоимость</th>
                                <th>Статус</th>
                                <th></th>
                            </tr></thead><tbody>";
                        if ($result) {
                            while ($row = $result->fetch_array()) {
                                if ($row['assembly_id'] > 3) {
                                    $row['assembly_name'] = "Сборка " . ($row['assembly_name'] ?? '');
                                }
                                $formId = 'ordForm' . (int) $row['order_id'];
                                // 3.7-f-4: данные строки для клика (модалка — в 3.7-h)
                                $rowData = json_encode([
                                    'id' => $row['order_id'],
                                    'user' => trim(($row['user_name'] ?? '') . ' ' . ($row['user_surname'] ?? '')),
                                    'address' => $row['user_address'],
                                    'assembly_name' => $row['assembly_name'],
                                    'assembly_price' => $row['assembly_price'],
                                    'status' => $row['status'],
                                ]);
                                echo "<tr data-row='" . escape($rowData) . "'>"
                                    . "<td>" . htmlspecialchars(($row['user_name'] ?? '') . " " . ($row['user_surname'] ?? '')) . "</td>"
                                    . "<td>" . htmlspecialchars($row['user_address'] ?? '') . "</td>"
                                    . "<td>" . htmlspecialchars($row['assembly_name'] ?? '') . "</td>"
                                    . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                    // select и кнопка — в одной форме через HTML5-атрибут form
                                    . "<td><select class=\"input\" size=\"1\" name=\"status\" form=\"$formId\">"
                                    . "<option " . ((($row['status'] ?? '') == 'Обрабатывается') ? 'selected' : '') . " value=\"Обрабатывается\">Обрабатывается</option>"
                                    . "<option " . ((($row['status'] ?? '') == 'Собирается') ? 'selected' : '') . " value=\"Собирается\">Собирается</option>"
                                    . "<option " . ((($row['status'] ?? '') == 'Доставляется') ? 'selected' : '') . " value=\"Доставляется\">Доставляется</option>"
                                    . "<option " . ((($row['status'] ?? '') == 'Выполнен') ? 'selected' : '') . " value=\"Выполнен\">Выполнен</option>"
                                    . "</select></td>"
                                    . "<td><form method=\"POST\" id=\"$formId\" class=\"row-form\">"
                                    . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">"
                                    . "<input type=\"hidden\" name=\"orderId\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">"
                                    . "<button class=\"delBtn\" style=\"color:blue;\" name=\"editOrderStatus\" type=\"submit\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">Сохранить</button>"
                                    . "</form></td>"
                                    . "</tr>";
                            }
                        }
                        echo "</tbody></table></div>";
                        echo render_pagination('orders', $page, $pages);
                    } else {
                        echo "<p>Столбец status отсутствует в таблице orders</p>";
                    }
?>
                </section>