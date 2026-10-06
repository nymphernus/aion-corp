<?php
/**
 * Вкладка «Комплектующие» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=components).
 * Прямой запрос к файлу → 404.
 *
 * форма добавления — в нативной модалке <dialog>,
 * список — на .table из base.css.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}

// Обработка ошибок загрузки изображения
$uploadErrors = [
    'upload' => 'Ошибка загрузки файла',
    'size'   => 'Файл слишком большой (макс 10 МБ)',
    'mime'   => 'Недопустимый тип файла',
    'image'  => 'Файл не является изображением',
    'save'   => 'Не удалось сохранить файл',
];
$error = $_GET['error'] ?? '';
if (isset($uploadErrors[$error])) {
    echo '<div class="alert alert--error">' . htmlspecialchars($uploadErrors[$error]) . '</div>';
}

// Фильтры текущей страницы для скрытого поля return_params.
//
// Форма шлёт POST на /admin.php?tab=components без фильтров в адресе,
// поэтому в $_GET на POST-запросе их нет: после сохранения или удаления
// админ возвращался на пустой список. Значение собирается здесь, при
// рендере страницы, и уезжает с формой - обработчику остаётся его
// просто прочитать.
$returnParams = http_build_query(array_filter([
    'page'     => $_GET['page'] ?? null,
    'cat'      => $_GET['cat'] ?? null,
    'sock'     => $_GET['sock'] ?? null,
    'q'        => $_GET['q'] ?? null,
    'sort'     => $_GET['sort'] ?? null,
    'no_image' => $_GET['no_image'] ?? null,
], static fn($v) => $v !== null && $v !== ''));

$catRows = [];
$sql = "SELECT * FROM categories ORDER BY `categories`.`category_id` ASC";
$stmt = db_prepare($mysql, $sql);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_array()) {
        $catRows[] = $row;
    }
}

$socketRows = [];
$sql = "SELECT * FROM sockets ORDER BY `sockets`.`socket_id` ASC";
$stmt = db_prepare($mysql, $sql);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_array()) {
        $socketRows[] = $row;
    }
}
?>
                <!-- отказ по FK — компонент используется в сборках -->
<?php if (isset($_GET['error']) && $_GET['error'] === 'used'): ?>
                <div class="alert alert--error">
                    Компонент используется в <?= (int) ($_GET['count'] ?? 0) ?> сборках. Удаление запрещено.
                </div>
<?php endif; ?>
                <section class="card admin-panel">
                    <div class="admin-header">
                        <h1 class="page-title">Управление комплектующими</h1>
                        <button type="button" class="btn btn--primary" data-action="open-modal" data-modal="addComponentModal">+ Добавить</button>
                    </div>

                    <!-- фильтры категории / сокета / поиска по названию -->
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

                        <?php
                            // сокет есть только у процессоров, материнских
                            // плат и кулеров - для остальных категорий фильтр скрываем
                            $fCatId = (int) ($_GET['cat'] ?? 0);
                            // показываем строго для CPU/платы/кулера;
                            // при первой загрузке (категория не выбрана) скрыт
                            $sockRelevant = in_array($fCatId, [1, 2, 7], true);
                            $sockCatsJs = [1, 2, 7];
?>
                            <span id="sockFilterWrap"<?= $sockRelevant ? '' : ' style="display:none"' ?>>
                                <select name="sock" class="input" id="sockFilter">
                                    <option value="">Все сокеты</option>
<?php foreach ($socketRows as $row): ?>
                                    <option value="<?= (int) $row['socket_id'] ?>"
                                            <?= (int) ($_GET['sock'] ?? 0) === (int) $row['socket_id'] ? 'selected' : '' ?>>
                                        <?= escape($row['socket_type'] ?? '') ?>
                                    </option>
<?php endforeach; ?>
                                </select>
                            </span>

                        <input type="search" name="q" class="input" placeholder="Поиск по названию..."
                               value="<?= escape((string) ($_GET['q'] ?? '')) ?>">

                        <!-- сортировка, значения проверяются по белому списку в admin.php -->
                        <select name="sort" class="input">
<?php
    $sortOptions = [
        '' => 'Без сортировки',
        'price_asc' => 'Цена ↑',
        'price_desc' => 'Цена ↓',
        'amount_asc' => 'Количество ↑',
        'amount_desc' => 'Количество ↓',
        'name_asc' => 'Название (А-Я)',
    ];
    $curSort = (string) ($_GET['sort'] ?? '');
    foreach ($sortOptions as $val => $label):
?>
                            <option value="<?= escape($val) ?>"<?= $curSort === $val ? ' selected' : '' ?>>
                                <?= escape($label) ?>
                            </option>
<?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn--primary">Применить</button>

<?php if ((int) ($_GET['cat'] ?? 0) > 0 || (int) ($_GET['sock'] ?? 0) > 0 || trim((string) ($_GET['q'] ?? '')) !== '' || trim((string) ($_GET['sort'] ?? '')) !== '' || isset($_GET['no_image'])): ?>
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
                                // $page/$pages/$offset/$total/$perPage считает admin.php
                                // (нужно до вывода HTML — там же работает редирект page>N)

                                // явный список колонок — нужен для data-component (edit).
                                // image в выборке — иначе превью в edit-модалке пустое
                                $sql = "SELECT components.component_id, components.component_name, components.component_price,
                                               components.amount, components.category_id, categories.category_name,
                                               components.description, components.manufacturer, components.model,
                                               components.image,
                                               components.socket_id, components.tdp, components.frequency_mhz,
                                               components.video_core, components.ram_type, components.capacity_gb,
                                               components.memory_type, components.wattage, components.interface,
                                               components.form_factor, components.rpm, components.cooler_type
                                        FROM components,categories WHERE components.category_id = categories.category_id"
                                        . $listWhere . "
                                        ORDER BY {$listOrder} LIMIT ? OFFSET ?";
                                // параметры фильтров идут перед LIMIT/OFFSET
                                if ($listParams === []) {
                                    $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                                } else {
                                    $stmt = db_prepare($mysql, $sql, $listTypes . "ii", ...array_merge($listParams, [$perPage, $offset]));
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
                                            // путь картинки нужен JS для превью
                                            // в edit-модалке корпуса
                                            'image' => $row['image'],
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
                                        // клик по строке открывает edit-модалку,
                                        // колонки «Действия» больше нет
                                        // Нулевой остаток помечается явно:
                                        // конфигуратор такой товар не
                                        // подбирает, и без отметки это
                                        // выглядит как обычное число.
                                        $amountCell = htmlspecialchars($row['amount'] ?? '');
                                        if ((int) ($row['amount'] ?? 0) === 0) {
                                            $amountCell .= ' <span class="badge badge--error">нет в наличии</span>';
                                        } elseif ((int) ($row['amount'] ?? 0) < 5) {
                                            $amountCell .= ' <span class="badge badge--warning">мало</span>';
                                        }

                                        echo "<tr data-component='" . escape($editData) . "'>"
                                            . "<td>" . htmlspecialchars($row['component_id'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['category_name'] ?? '') . "</td>"
                                            . "<td>" . htmlspecialchars($row['component_name'] ?? '') . "</td>"
                                            . "<td>" . $amountCell . "</td>"
                                            . "<td>" . htmlspecialchars($row['component_price'] ?? '') . "</td>"
                                            . "</tr>";
                                    }
                                }
?>
                            </tbody>
                        </table>
                    </div>
                    <?= render_pagination('components', $page, $pages, $listQuery) ?>
                    <div style="color:var(--text-secondary);font-size:13px;text-align:center;">
                        Показано <?= min($perPage, max(0, $total - $offset)) ?> из <?= $total ?>, страница <?= $page ?> из <?= $pages ?>
                    </div>
                </section>

                <!--
                    модалка с динамическими группами полей.
                    Каждая группа .field-group несёт data-cat — список category_id,
                    для которых она релевантна. Все группы в DOM, скрыты по умолчанию;
                    показ/скрытие — scripts.js по событию change на select[name="cat"].
                    Бэкенд (admin.php) пишет только поля из маппинга категории,
                    остальные — NULL (скрытые input всё равно отправляются).
                -->
                <dialog id="addComponentModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=components" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="return_params" value="<?= escape($returnParams) ?>">
                        <h2 id="modalTitle">Добавить комплектующий</h2>
                        <!-- пустой = INSERT, заполненный = UPDATE -->
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

                        <!-- Изображение корпуса (только для category_id = 6).
                             сама картинка (плейсхолдер) - кнопка, клик
                             открывает модалку выбора: загрузка или сетка
                             загруженных файлов. -->
                        <div class="field-group" data-cat="6">
                            <label class="form-label">Изображение корпуса</label>

                            <button type="button"
                                    class="image-picker-trigger"
                                    data-action="open-image-picker"
                                    id="imagePickerTrigger">
                                <img src="" alt="" id="imagePreviewImg"
                                     class="image-picker-trigger__img">
                                <div class="image-picker-trigger__placeholder"
                                     id="imagePreviewPlaceholder">
                                    <svg width="32" height="32" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                        <path d="M12 3v6m3-3H9"></path>
                                    </svg>
                                    Нажмите, чтобы выбрать изображение
                                </div>
                            </button>

                            <input type="file" name="image_file" id="imageFileInput"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   style="display:none">

                            <input type="hidden" name="image_selected_url" id="imageSelectedUrl" value="">
                            <input type="hidden" name="removeImage" id="removeImageFlag" value="0">

                            <p class="form-hint">
                                JPG, PNG, WebP, GIF. До 10 МБ. Итоговый формат выбирает прозрачность: картинка с прозрачностью сохраняется как PNG (любой формат на входе), без прозрачности сжимается в JPG, GIF-анимация остаётся как есть.
                            </p>
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
                                <label class="form-label" for="formFactorSelect">Форм-фактор</label>
<?php
                                // опции зависят от категории, карта отдаётся в JS
                                $formFactorsByCat = [
                                    2 => ['ATX', 'Micro-ATX', 'Mini-ITX'],
                                    5 => ['ATX', 'SFX', 'TFX'],
                                    6 => ['ATX Mid-Tower', 'ATX Full-Tower', 'mATX Mid-Tower', 'Mini-ITX', 'Mid-Tower'],
                                    8 => ['2.5"', '3.5"'],
                                    9 => ['2.5"', 'M.2'],
                                ];
?>
                                <select class="input" name="form_factor" id="formFactorSelect"
                                        data-options='<?= escape(json_encode($formFactorsByCat, JSON_UNESCAPED_UNICODE)) ?>'>
                                    <option value="">Выберите категорию</option>
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

                        <!-- Интерфейс: HDD, SSD. Категория 10 «Привод»
                             удалена в 5-b, её компонентов в базе больше нет. -->
                        <div class="field-group" data-cat="8 9">
                            <div class="form-group">
                                <label class="form-label" for="ac_if">Интерфейс</label>
                                <select class="input" id="ac_if" name="interface">
                                    <option value="">Не указан</option>
                                    <option value="SATA III">SATA III</option>
                                    <option value="SATA">SATA</option>
                                    <option value="M.2 NVMe PCIe 3.0">M.2 NVMe PCIe 3.0</option>
                                    <option value="M.2 NVMe PCIe 4.0">M.2 NVMe PCIe 4.0</option>
                                    <!-- значения без указания версии PCIe.
                                         Их проставил стадия 2b из названий старых
                                         накопителей («M.2» в имени, но не «NVMe»),
                                         и без этих опций поле в модалке выглядело
                                         пустым. Данные не стирались: хендлер пустые
                                         значения из формы игнорирует. -->
                                    <option value="M.2 NVMe">M.2 NVMe</option>
                                    <option value="M.2">M.2</option>
                                    <option value="SAS">SAS</option>
                                </select>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <!-- удаление доступно только в edit-режиме -->
                            <button type="button" class="btn btn--danger" id="modalDeleteBtn" data-action="open-delete-modal" hidden>Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="addComponent" id="modalSubmit" class="btn btn--primary">Добавить</button>
                            </div>
                        </div>
                    </form>
                </dialog>

                <!--
                    подтверждение удаления. Реальную проверку
                    использования в сборках делает бэкенд (FK assembly.*_id →
                    components.component_id с NO ACTION) — при отказе
                    редирект с ?error=used&count=N.
                -->
                <!--
                    подтверждение удаления показывает общая
                    #confirmModal из partials/header.php, поэтому
                    отдельная модалка с теми же кнопками удалена.
                    Осталась форма, которую отправляет confirmAction.
                -->
                <form id="deleteComponentForm" method="post" action="/admin.php?tab=components" hidden>
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="return_params" value="<?= escape($returnParams) ?>">
                    <input type="hidden" name="deleteComponentId" id="deleteComponentId" value="">
                    <!-- скрытый input вместо submit-кнопки,
                         потому что форму отправляет form.submit() -->
                    <input type="hidden" name="deleteComponent" value="1">
                </form>

                <!-- единая модалка выбора изображения корпуса.
                     Две секции: загрузка нового файла и сетка загруженных.
                     Сортировка сетки по ДОП-3: непривязанные сверху, внутри
                     групп - натуральный порядок по имени. -->
                <dialog id="filePickerModal" class="modal modal--wide">
                    <div class="modal-form">
                        <h2>Изображение корпуса</h2>

                        <!-- Секция 1: загрузка нового файла.
                             ВАЖНО: этот input вне формы компонента
                             (dialog идёт после </form>), поэтому name=""
                             и он служит только источником для JS - файл
                             переносится в #imageFileInput внутри формы.
                             С name="image_file" браузер отправил бы его
                             отдельным POST, которого сервер не ждёт. -->
                        <div class="image-picker-upload">
                            <label class="btn btn--primary">
                                <input type="file"
                                       id="pickerFileInput"
                                       accept="image/jpeg,image/png,image/webp,image/gif"
                                       style="display:none">
                                Загрузить новый файл
                            </label>
                            <span class="image-picker-upload__hint">
                                JPG, PNG, WebP, GIF до 10 МБ → сохранится в PNG
                            </span>
                        </div>

                        <div class="image-picker-divider">или выберите из загруженных</div>

                        <!-- Секция 2: сетка загруженных файлов -->
                        <div class="file-picker-grid" id="filePickerGrid">
<?php
                        // Сбор файлов + флаг привязки. Привязка может храниться
                        // с ведущим слешем и без него - считаем по basename.
                        $pickerDir = dirname(__DIR__) . '/assets/images/cases/';
                        $pickerImages = [];
                        foreach (scandir($pickerDir) as $name) {
                            if ($name === '.' || $name === '..' || $name[0] === '.') continue;
                            $p = $pickerDir . $name;
                            if (!is_file($p)) continue;
                            $pickerImages[$name] = [
                                'name' => $name,
                                'url' => 'assets/images/cases/' . $name,
                                'used' => false,
                            ];
                        }
                        $pstmt = db_prepare($mysql, "SELECT image FROM components WHERE image IS NOT NULL", "");
                        $pstmt->execute();
                        $pres = $pstmt->get_result();
                        while ($prow = $pres->fetch_assoc()) {
                            $pb = basename($prow['image']);
                            if (isset($pickerImages[$pb])) {
                                $pickerImages[$pb]['used'] = true;
                            }
                        }
                        usort($pickerImages, function ($a, $b) {
                            if ($a['used'] !== $b['used']) return $a['used'] ? 1 : -1;
                            return strnatcasecmp($a['name'], $b['name']);
                        });
                        foreach ($pickerImages as $pf):
?>
                            <button type="button"
                                    class="file-picker-item"
                                    data-action="pick-file"
                                    data-url="<?= escape($pf['url']) ?>">
                                <img src="<?= escape($pf['url']) ?>"
                                     alt="" loading="lazy">
                                <div class="file-picker-item__name">
                                    <?= escape($pf['name']) ?>
                                    <?php if (!$pf['used']): ?>
                                        <span class="badge badge--warning">не используется</span>
                                    <?php endif; ?>
                                </div>
                            </button>
<?php endforeach; ?>
                        </div>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--ghost"
                                        data-action="remove-image-from-picker">
                                    Убрать изображение
                                </button>
                                <button type="button" class="btn btn--secondary"
                                        data-action="close-modal">Отмена</button>
                            </div>
                        </div>
                    </div>
                </dialog>
