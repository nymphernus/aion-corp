<?php
/**
 * Вкладка «Заказы» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=orders).
 * Прямой запрос к файлу → 404.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card">
                    <h2>Управление заказами</h2>
<?php
                    echo "<span class=\"assemblyTable\">
                            <span>Покупатель</span>
                            <span style=\"width:60%\">Адрес</span>
                            <span>Сборка</span>
                            <span>Стоимость</span>
                            <span>Статус</span>
                            <span></span>
                          </span><br><div class=\"lineSpan\"></div>";

                    $checkSql = "SHOW COLUMNS FROM orders LIKE 'status'";
                    $checkStmt = $mysql->prepare($checkSql);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();

                    if ($checkResult && $checkResult->num_rows > 0) {
                        $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,assembly.assembly_id FROM users,assembly,orders
                                WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id
                                ORDER BY `orders`.`order_id` ASC";
                        $stmt = $mysql->prepare($sql);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result) {
                            while ($row = $result->fetch_array()) {
                                if ($row['assembly_id'] > 3) {
                                    $row['assembly_name'] = "Сборка " . ($row['assembly_name'] ?? '');
                                }
                                echo "<form method=\"POST\">
                                        <input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">
                                        <span class=\"assemblyTable\">
                                            <span>" . htmlspecialchars(($row['user_name'] ?? '') . " " . ($row['user_surname'] ?? '')) . "</span>
                                            <span style=\"width:60%\">" . htmlspecialchars($row['user_address'] ?? '') . "</span>
                                            <span>" . htmlspecialchars($row['assembly_name'] ?? '') . "</span>
                                            <span>" . htmlspecialchars($row['assembly_price'] ?? '') . "</span>
                                            <span>
                                                <select size=\"1\" name=\"status\">
                                                    <option " . ((($row['status'] ?? '') == 'Обрабатывается') ? 'selected' : '') . " value=\"Обрабатывается\">Обрабатывается</option>
                                                    <option " . ((($row['status'] ?? '') == 'Собирается') ? 'selected' : '') . " value=\"Собирается\">Собирается</option>
                                                    <option " . ((($row['status'] ?? '') == 'Доставляется') ? 'selected' : '') . " value=\"Доставляется\">Доставляется</option>
                                                    <option " . ((($row['status'] ?? '') == 'Выполнен') ? 'selected' : '') . " value=\"Выполнен\">Выполнен</option>
                                                </select>
                                            </span>
                                            <span>
                                                <input type=\"hidden\" name=\"orderId\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">
                                                <button class=\"delBtn\" style=\"color:blue;\" name=\"editOrderStatus\" type=\"submit\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">Сохранить</button>
                                            </span>
                                        </span>
                                        <br>
                                      </form>";
                            }
                        }
                    } else {
                        echo "<p>Столбец status отсутствует в таблице orders</p>";
                    }
?>
                </section>
