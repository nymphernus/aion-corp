<?php
/**
 * Вкладка «Комплектующие» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=components).
 * Прямой запрос к файлу → 404.
 *
 * 3.6.2-c: форма добавления — в нативной модалке <dialog>,
 * список — на .table из base.css.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}

$catRows = [];
$sql = "SELECT * FROM categories ORDER BY `categories`.`category_id` ASC";
$stmt = $mysql->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_array()) {
        $catRows[] = $row;
    }
}

$socketRows = [];
$sql = "SELECT * FROM sockets ORDER BY `sockets`.`socket_id` ASC";
$stmt = $mysql->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_array()) {
        $socketRows[] = $row;
    }
}
?>
                <section class="card admin-panel">
                    <div class="admin-header">
                        <h1>Управление комплектующими</h1>
                        <button type="button" class="btn btn--primary" data-action="open-modal" data-modal="addComponentModal">+ Добавить</button>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Категория</th>
                                    <th>Название</th>
                                    <th>Количество</th>
                                    <th>Стоимость</th>
                                </tr>
                            </thead>
                            <tbody>
<?php
                                $sql = "SELECT * FROM components,categories WHERE components.category_id = categories.category_id ORDER BY `components`.`component_id` ASC";
                                $stmt = $mysql->prepare($sql);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        echo "<tr>"
                                            . "<td>" . htmlspecialchars($row['component_id'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['category_name'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['component_name'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['amount'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['component_price'] ?? '') . "</td>"
                                            . "</tr>";
                                    }
                                }
?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <dialog id="addComponentModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=components">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <h2>Добавить комплектующий</h2>

                        <div class="form-group">
                            <label class="form-label" for="ac_nm">Название</label>
                            <input class="input" id="ac_nm" name="nm" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_pr">Стоимость</label>
                            <input class="input" id="ac_pr" type="number" name="pr" required min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_col">Количество</label>
                            <input class="input" id="ac_col" type="number" name="col" required min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_cat">Категория</label>
                            <select class="input" id="ac_cat" name="cat" required>
                                <option value="" selected disabled>Категория</option>
<?php
                                foreach ($catRows as $row) {
                                    echo "<option value=\"" . htmlspecialchars($row['category_id'] ?? '') . "\">" . htmlspecialchars($row['category_name'] ?? '') . "</option>";
                                }
?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_sock">Сокет</label>
                            <select class="input" id="ac_sock" name="sock">
                                <option value="">Не указан</option>
<?php
                                foreach ($socketRows as $row) {
                                    echo "<option value=\"" . htmlspecialchars($row['socket_id'] ?? '') . "\">" . htmlspecialchars($row['socket_type'] ?? '') . "</option>";
                                }
?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_tdp">TDP</label>
                            <input class="input" id="ac_tdp" type="number" name="tdp">
                        </div>
                        <div class="form-group form-check">
                            <label for="ac_vc"><input id="ac_vc" type="checkbox" name="vc" value="1"> Графическое ядро</label>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                            <button type="submit" name="addComponent" class="btn btn--primary">Добавить</button>
                        </div>
                    </form>
                </dialog>
