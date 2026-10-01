<?php
/**
 * Вкладка «Пользователи» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=users).
 * Прямой запрос к файлу → 404.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card admin-panel">
                    <h2>Управление пользователями</h2>
<?php
                    echo "<span class=\"assemblyTable\"><span>Имя</span><span>Логин</span><span>Группа</span><span>Адрес</span><span>Номер</span><span></span></span><br><div class=\"lineSpan\"></div>";
                    $sql = "SELECT user_id,user_name, user_login, user_group, user_address, user_number FROM users";
                    $stmt = $mysql->prepare($sql);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_array()) {
                            echo "<form method=\"POST\">
                                    <input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">
                                    <span class=\"assemblyTable\">
                                        <span>" . htmlspecialchars($row['user_name'] ?? '') . "</span>
                                        <span>" . htmlspecialchars($row['user_login'] ?? '') . "</span>
                                        <span>" . htmlspecialchars($row['user_group'] ?? '') . "</span>
                                        <span>" . htmlspecialchars($row['user_address'] ?? '') . "</span>
                                        <span>" . htmlspecialchars($row['user_number'] ?? '') . "</span>
                                        <span>
                                            <input style=\"display:none\" name=\"userId\" type=\"hidden\" value=\"" . htmlspecialchars($row['user_id'] ?? '') . "\">
                                            <button class=\"delBtn\" name=\"deleteUser\" type=\"submit\">Удалить</button>
                                        </span>
                                    </span>
                                    <br>
                                  </form>";
                        }
                    }
?>
                </section>
