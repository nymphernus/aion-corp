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
                <!-- 3.7-e: отказ по FK — компонент используется в сборках -->
<?php if (isset($_GET['error']) && $_GET['error'] === 'used'): ?>
                <div class="alert alert--error">
                    Компонент используется в <?= (int) ($_GET['count'] ?? 0) ?> сборках. Удаление запрещено.
                </div>
<?php endif; ?>
                <section class="card admin-panel">
                    <div class="admin-header">
                        <h1>Управление комплектующими</h1>
                        <button type="button" class="btn btn--primary" data-action="open-modal" data-modal="addComponentModal">+ Добавить</button>
                    </div>

                    <!-- 3.7-f-2-2: фильтры категории / сокета / поиска по названию -->
                    <form method="get" class="admin-filters">
                        <input type="hidden" name="tab" value="components">

                        <select name="cat" class="input">
                            <option value="">Все категории</option>
<?php foreach ($catRows as $row): ?>
                            <option value="<?= (int) $row['category_id'] ?>"
                                    <?= (int) ($_GET['cat'] ?? 0) === (int) $row['category_id'] ? 'selected' : '' ?>>
                                <?= escape($row['category_name'] ?? '') ?>
                            </option>
<?php endforeach; ?>
                        </select>

                        <select name="sock" class="input">
                            <option value="">Все сокеты</option>
<?php foreach ($socketRows as $row): ?>
                            <option value="<?= (int) $row['socket_id'] ?>"
                                    <?= (int) ($_GET['sock'] ?? 0) === (int) $row['socket_id'] ? 'selected' : '' ?>>
                                <?= escape($row['socket_type'] ?? '') ?>
                            </option>
<?php endforeach; ?>
                        </select>

                        <input type="search" name="q" class="input" placeholder="Поиск по названию..."
                               value="<?= escape((string) ($_GET['q'] ?? '')) ?>">

                        <button type="submit" class="btn btn--primary">Применить</button>

<?php if ((int) ($_GET['cat'] ?? 0) > 0 || (int) ($_GET['sock'] ?? 0) > 0 || trim((string) ($_GET['q'] ?? '')) !== ''): ?>
                        <a href="?tab=components" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
                    </form>

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
                                // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php
                                // (нужно до вывода HTML — там же работает редирект page>N)

                                // 3.7-d: явный список колонок — нужен для data-component (edit)
                                $sql = "SELECT components.component_id, components.component_name, components.component_price,
                                               components.amount, components.category_id, categories.category_name,
                                               components.description, components.manufacturer, components.model,
                                               components.socket_id, components.tdp, components.frequency_mhz,
                                               components.video_core, components.ram_type, components.capacity_gb,
                                               components.memory_type, components.wattage, components.interface,
                                               components.form_factor, components.rpm, components.cooler_type
                                        FROM components,categories WHERE components.category_id = categories.category_id"
                                        . $compWhere . "
                                        ORDER BY `components`.`component_id` ASC LIMIT ? OFFSET ?";
                                // 3.7-f-2-2: параметры фильтров идут перед LIMIT/OFFSET
                                if ($compParams === []) {
                                    $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                                } else {
                                    $stmt = db_prepare($mysql, $sql, $compTypes . "ii", ...array_merge($compParams, [$perPage, $offset]));
                                }
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        $editData = json_encode([
                                            'id' => $row['component_id'],
                                            'name' => $row['component_name'],
                                            'price' => $row['component_price'],
                                            'amount' => $row['amount'],
                                            'category_id' => $row['category_id'],
                                            'description' => $row['description'],
                                            'manufacturer' => $row['manufacturer'],
                                            'model' => $row['model'],
                                            'socket_id' => $row['socket_id'],
                                            'tdp' => $row['tdp'],
                                            'frequency_mhz' => $row['frequency_mhz'],
                                            'video_core' => $row['video_core'],
                                            'ram_type' => $row['ram_type'],
                                            'capacity_gb' => $row['capacity_gb'],
                                            'memory_type' => $row['memory_type'],
                                            'wattage' => $row['wattage'],
                                            'interface' => $row['interface'],
                                            'form_factor' => $row['form_factor'],
                                            'rpm' => $row['rpm'],
                                            'cooler_type' => $row['cooler_type'],
                                        ]);
                                        // 3.7-f-1: клик по строке открывает edit-модалку,
                                        // колонки «Действия» больше нет
                                        echo "<tr data-component='" . escape($editData) . "'>"
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
                    <?= render_pagination('components', $page, $pages, $compQuery) ?>
                    <div style="color:var(--text-secondary);font-size:13px;text-align:center;">
                        Показано <?= min($perPage, max(0, $total - $offset)) ?> из <?= $total ?>, страница <?= $page ?> из <?= $pages ?>
                    </div>
                </section>

                <!--
                    3.7-c: модалка с динамическими группами полей.
                    Каждая группа .field-group несёт data-cat — список category_id,
                    для которых она релевантна. Все группы в DOM, скрыты по умолчанию;
                    показ/скрытие — scripts.js по событию change на select[name="cat"].
                    Бэкенд (admin.php) пишет только поля из маппинга категории,
                    остальные — NULL (скрытые input всё равно отправляются).
                -->
                <dialog id="addComponentModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=components">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <h2 id="modalTitle">Добавить комплектующий</h2>
                        <!-- 3.7-d: пустой = INSERT, заполненный = UPDATE -->
                        <input type="hidden" name="editComponentId" id="editComponentId" value="">

                        <!-- Всегда видны: обязательные поля -->
                        <div class="modal-row">
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
                        </div>

                        <!-- Всегда видны: опциональные идентификаторы -->
                        <div class="modal-row">
                            <div class="form-group">
                                <label class="form-label" for="ac_man">Производитель</label>
                                <input class="input" id="ac_man" name="manufacturer">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ac_model">Модель</label>
                                <input class="input" id="ac_model" name="model">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ac_desc">Описание</label>
                            <textarea class="input" id="ac_desc" name="description" rows="3"></textarea>
                        </div>

                        <!-- Динамические группы (маппинг категорий, ШАГ 1) -->

                        <!-- Сокет: Процессор, Материнская плата, Кулер -->
                        <div class="field-group" data-cat="1 2 7">
                            <div class="form-group">
                                <label class="form-label" for="ac_sock">Сокет</label>
                                <select class="input" id="ac_sock" name="socket">
                                    <option value="">Не указан</option>
<?php
                                foreach ($socketRows as $row) {
                                    echo "<option value=\"" . htmlspecialchars($row['socket_id'] ?? '') . "\">" . htmlspecialchars($row['socket_type'] ?? '') . "</option>";
                                }
?>
                                </select>
                            </div>
                        </div>

                        <!-- TDP: Процессор, Видеокарта, Кулер -->
                        <div class="field-group" data-cat="1 3 7">
                            <div class="form-group">
                                <label class="form-label" for="ac_tdp">TDP, Вт</label>
                                <input class="input" id="ac_tdp" type="number" name="tdp" min="0">
                            </div>
                        </div>

                        <!-- Частота: Процессор, Оперативная память -->
                        <div class="field-group" data-cat="1 4">
                            <div class="form-group">
                                <label class="form-label" for="ac_freq">Частота, МГц</label>
                                <input class="input" id="ac_freq" type="number" name="frequency_mhz" min="0">
                            </div>
                        </div>

                        <!-- Графическое ядро: только Процессор -->
                        <div class="field-group" data-cat="1">
                            <div class="form-group form-check">
                                <label for="ac_vc"><input id="ac_vc" type="checkbox" name="video_core" value="1"> Встроенное графическое ядро</label>
                            </div>
                        </div>

                        <!-- Форм-фактор: Материнская плата, Блок питания, Корпус, HDD, SSD -->
                        <div class="field-group" data-cat="2 5 6 8 9">
                            <div class="form-group">
                                <label class="form-label" for="ac_ff">Форм-фактор</label>
                                <select class="input" id="ac_ff" name="form_factor">
                                    <option value="">Не указан</option>
                                    <option value="ATX">ATX</option>
                                    <option value="mATX">mATX</option>
                                    <option value="Mini-ITX">Mini-ITX</option>
                                    <option value="ATX Mid-Tower">ATX Mid-Tower</option>
                                    <option value="ATX Full-Tower">ATX Full-Tower</option>
                                    <option value="Mid-Tower">Mid-Tower</option>
                                    <option value="2.5&quot;">2.5"</option>
                                    <option value="3.5&quot;">3.5"</option>
                                    <option value="M.2">M.2</option>
                                    <option value="M.2 2280">M.2 2280</option>
                                </select>
                            </div>
                        </div>

                        <!-- Тип памяти: Материнская плата, Оперативная память -->
                        <div class="field-group" data-cat="2 4">
                            <div class="form-group">
                                <label class="form-label" for="ac_rt">Тип памяти</label>
                                <select class="input" id="ac_rt" name="ram_type">
                                    <option value="">Не указан</option>
                                    <option value="DDR3">DDR3</option>
                                    <option value="DDR4">DDR4</option>
                                    <option value="DDR5">DDR5</option>
                                </select>
                            </div>
                        </div>

                        <!-- Объём: Видеокарта, Оперативная память, HDD, SSD -->
                        <div class="field-group" data-cat="3 4 8 9">
                            <div class="form-group">
                                <label class="form-label" for="ac_cap">Объём, ГБ</label>
                                <input class="input" id="ac_cap" type="number" name="capacity_gb" min="0">
                            </div>
                        </div>

                        <!-- Тип видеопамяти: только Видеокарта -->
                        <div class="field-group" data-cat="3">
                            <div class="form-group">
                                <label class="form-label" for="ac_mt">Тип видеопамяти</label>
                                <input class="input" id="ac_mt" name="memory_type" placeholder="GDDR6, GDDR6X…">
                            </div>
                        </div>

                        <!-- Мощность: Видеокарта, Блок питания -->
                        <div class="field-group" data-cat="3 5">
                            <div class="form-group">
                                <label class="form-label" for="ac_wt">Мощность, Вт</label>
                                <input class="input" id="ac_wt" type="number" name="wattage" min="0">
                            </div>
                        </div>

                        <!-- Тип охлаждения: только Кулер -->
                        <div class="field-group" data-cat="7">
                            <div class="form-group">
                                <label class="form-label" for="ac_ct">Тип охлаждения</label>
                                <select class="input" id="ac_ct" name="cooler_type">
                                    <option value="">Не указан</option>
                                    <option value="Air">Air (воздушный)</option>
                                    <option value="AIO">AIO (СЖО)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Скорость вращения: только HDD -->
                        <div class="field-group" data-cat="8">
                            <div class="form-group">
                                <label class="form-label" for="ac_rpm">Обороты, об/мин</label>
                                <input class="input" id="ac_rpm" type="number" name="rpm" min="0">
                            </div>
                        </div>

                        <!-- Интерфейс: HDD, SSD, Привод -->
                        <div class="field-group" data-cat="8 9 10">
                            <div class="form-group">
                                <label class="form-label" for="ac_if">Интерфейс</label>
                                <select class="input" id="ac_if" name="interface">
                                    <option value="">Не указан</option>
                                    <option value="SATA III">SATA III</option>
                                    <option value="SATA">SATA</option>
                                    <option value="M.2 NVMe PCIe 3.0">M.2 NVMe PCIe 3.0</option>
                                    <option value="M.2 NVMe PCIe 4.0">M.2 NVMe PCIe 4.0</option>
                                    <option value="SAS">SAS</option>
                                </select>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <!-- 3.7-f-2: удаление доступно только в edit-режиме -->
                            <button type="button" class="btn btn--danger" id="modalDeleteBtn" data-action="open-delete-modal" hidden>Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="addComponent" id="modalSubmit" class="btn btn--primary">Добавить</button>
                            </div>
                        </div>
                    </form>
                </dialog>

                <!--
                    3.7-e: подтверждение удаления. Реальную проверку
                    использования в сборках делает бэкенд (FK assembly.*_id →
                    components.component_id с NO ACTION) — при отказе
                    редирект с ?error=used&count=N.
                -->
                <dialog id="deleteComponentModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=components">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="deleteComponentId" id="deleteComponentId" value="">

                        <h2>Удалить комплектующий?</h2>
                        <p id="deleteComponentName" style="color: var(--text-secondary); margin-bottom: 24px;"></p>

                        <div class="modal-actions">
                            <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                            <button type="submit" name="deleteComponent" class="btn btn--danger">Удалить</button>
                        </div>
                    </form>
                </dialog>
