<?php
/**
 * Вкладка «Комплектующие» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=components).
 * Прямой запрос к файлу → 404.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card">
                    <h2>Управление комплектующими</h2>
                    <div class="cmpForm">
                        <h3>Добавить комплектующие</h3><br>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <span class="assemblyTable">
                                <span><input type="text" placeholder="Название" name="nm" style="width:200px;"></span>
                                <span><input type="number" placeholder="Стоимость" name="pr"></span>
                                <span><input type="number" placeholder="Количество" name="col"></span>
                                <span><input id="tdpInp" type="checkbox" value="1" name="vc"><label for="tdpInp">Графическое ядро</label></span>
<?php
                                $sql = "SELECT * FROM categories ORDER BY `categories`.`category_id` ASC";
                                echo "<span><select size=\"1\" name=\"cat\"><option selected hidden disabled>Категория</option>";
                                $stmt = $mysql->prepare($sql);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        echo "<option value=\"" . htmlspecialchars($row['category_id'] ?? '') . "\">" . htmlspecialchars($row['category_name'] ?? '') . "</option>";
                                    }
                                }
                                echo "</select></span>";

                                $sql = "SELECT * FROM sockets ORDER BY `sockets`.`socket_id` ASC";
                                echo "<span><select size=\"1\" name=\"sock\"><option selected hidden disabled>Сокет</option>";
                                $stmt = $mysql->prepare($sql);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        echo "<option value=\"" . htmlspecialchars($row['socket_id'] ?? '') . "\">" . htmlspecialchars($row['socket_type'] ?? '') . "</option>";
                                    }
                                }
                                echo "</select></span>";
?>
                                <span><input type="number" placeholder="TDP" name="tdp"></span>
                                <span><button class="delBtn" style="color:blue;" name="addComponent" type="submit">Добавить</button></span>
                            </span>
                            <br>
                        </form>
                        <br>
                        <h3>Список комплектующих</h3>
                    </div>
<?php
                    echo "<br><br><div class=\"lineSpan\"></div>
                          <span class=\"assemblyTable\">
                            <span style=\"width:5%\">ID</span>
                            <span>Категория</span>
                            <span>Название</span>
                            <span style=\"width:5%\">Количество</span>
                            <span style=\"width:10%\">Стоимость</span>
                          </span><br><div class=\"lineSpan\"></div>";

                    $sql = "SELECT * FROM components,categories WHERE components.category_id = categories.category_id ORDER BY `components`.`component_id` ASC";
                    $stmt = $mysql->prepare($sql);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_array()) {
                            echo "<span class=\"assemblyTable\">
                                    <span style=\"width:5%\">" . htmlspecialchars($row['component_id'] ?? '') . "</span>
                                    <span>" . htmlspecialchars($row['category_name'] ?? '') . "</span>
                                    <span>" . htmlspecialchars($row['component_name'] ?? '') . "</span>
                                    <span style=\"width:5%\">" . htmlspecialchars($row['amount'] ?? '') . "</span>
                                    <span style=\"width:10%\">" . htmlspecialchars($row['component_price'] ?? '') . "</span>
                                  </span><br>";
                        }
                    }
?>
                </section>
