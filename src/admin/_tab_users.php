<?php
/**
 * Вкладка «Пользователи» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=users).
 * Прямой запрос к файлу → 404.
 *
 * 3.7-f-3: переведено с legacy-разметки .assemblyTable на .table из
 * base.css — таблица тянется на всю ширину карточки.
 * Имена POST-полей (csrf_token, userId, deleteUser) не менялись.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card admin-panel">
                    <h2>Управление пользователями</h2>
<?php
                    $sql = "SELECT user_id,user_name, user_login, user_group, user_address, user_number FROM users";
                    $stmt = $mysql->prepare($sql);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    echo "<div class=\"table-wrap\"><table class=\"table\">
                        <thead><tr>
                            <th>Имя</th>
                            <th>Логин</th>
                            <th>Группа</th>
                            <th>Адрес</th>
                            <th>Номер</th>
                            <th></th>
                        </tr></thead><tbody>";
                    if ($result) {
                        while ($row = $result->fetch_array()) {
                            echo "<tr>"
                                . "<td>" . htmlspecialchars($row['user_name'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_login'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_group'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_address'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_number'] ?? '') . "</td>"
                                . "<td><form method=\"POST\" class=\"row-form\">"
                                . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">"
                                . "<input name=\"userId\" type=\"hidden\" value=\"" . htmlspecialchars($row['user_id'] ?? '') . "\">"
                                . "<button class=\"delBtn\" name=\"deleteUser\" type=\"submit\">Удалить</button>"
                                . "</form></td>"
                                . "</tr>";
                        }
                    }
                    echo "</tbody></table></div>";
?>
                </section>