<?php
/**
 * Вкладка «Сборки»: базовые сборки витрины с главной страницы.
 *
 * Подключается из admin.php. Ожидает:
 *   $mysql — соединение (есть из admin.php)
 *   $extraJs — добавляется список скриптов
 *
 * Это витрина, а не содержимое каталога: комплектующие и наценки живут
 * в комплектующих и конфигураторе. Здесь редактируется только то, что
 * видит покупатель на главной - название, состав и стоимость.
 *
 * Картинка не редактируется: она берётся из выбранного корпуса, и
 * отдельное поле для неё означало бы две правды об одном товаре.
 */

/**
 * Сообщения об отказе приходят кодом в адресе (?bad=...), потому что
 * обработчик в admin.php завершается редиректом: та же страница не может
 * одновременно принять POST и отрисоваться с результатом.
 */
$asmBadCodes = [
    'name' => 'Название сборки должно быть от 2 до 100 символов.',
    'price' => 'С��оимость должна быть от 0 до 10 000 000 ₽.',
    'cpu_required' => 'Выберите процессор: без него сборка не запустится.',
    'case_required' => 'Выберите корпус: картинка на главной берётся из него.',
    'comp_category' => 'Компонент не принадлежит своей категории. Обновите страницу и выберите заново.',
    'not_found' => 'Такой сборки больше нет.',
    'action' => 'Неизвестное действие со сборкой.',
    'used' => 'Сборка есть в заказах или в избранном - удалить её нельзя.',
];
$asmBadMessages = [];
foreach (explode(',', (string) ($_GET['bad'] ?? '')) as $asmCode) {
    $asmCode = trim($asmCode);
    if ($asmCode !== '') {
        $asmBadMessages[] = $asmBadCodes[$asmCode] ?? $asmCode;
    }
}

$asmUsed = isset($_GET['error']) && $_GET['error'] === 'used';
$asmSaved = isset($_GET['saved']);

$slots = assembly_slots();
$requiredSlots = assembly_required_slots();

// Компоненты для выпадающих списков: одна выборка на все категории вместо
// девяти. Порядок по цене - список от дешёвого к дорогому читается
// ожидаемее, а не по component_id.
//
// Берутся ВСЕ компоненты, а не только те, что в наличии. Список в форме
// служит и для правки: если бы в нём не было закончившегося компонента,
// то при открытии такой сборки слот молча сбросился бы на «не выбрано»,
// и при сохранении в колонку NOT NULL ушёл бы NULL. Восемь из девяти
// слотов обязательны на уровне схемы, и отказ пришёл бы ошибкой MySQL
// вместо сообщения.
$asmComponentsByCategory = [];
$stmt = db_prepare(
    $mysql,
    'SELECT component_id, category_id, component_name, component_price, amount
       FROM components
      ORDER BY component_price ASC, component_id ASC',
    ''
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $asmComponentsByCategory[(int) $row['category_id']][] = $row;
}
$stmt->close();

// Названия категорий идут из таблицы, а не из списка в коде: иначе
// подпись «Процессор» в форме и «Видеокарта» в каталоге разъедутся,
// если кто-то переименует категорию.
$asmCategoryNames = [];
$stmt = db_prepare($mysql, 'SELECT category_id, category_name FROM categories ORDER BY category_id ASC', '');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $asmCategoryNames[(int) $row['category_id']] = (string) $row['category_name'];
}
$stmt->close();

// Сборки витрины с именем корпуса и его картинкой: обе колонки нужны
// таблице, а второй запрос за ними означал бы обход строк на каждый
// элемент списка. Порядок по id - порядок добавления.
$asmRows = [];
$stmt = db_prepare(
    $mysql,
    'SELECT a.assembly_id, a.assembly_name, a.assembly_price, a.is_base,
            cs.component_name AS case_name, cs.image AS case_image
       FROM assembly a
       LEFT JOIN components cs ON cs.component_id = a.case_id
      WHERE a.is_base = 1
      ORDER BY a.assembly_id ASC',
    ''
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $asmRows[] = $row;
}
$stmt->close();

// Состав каждой сборки - для заполнения полей модалки при правке.
// Одна выборка всех слотов, а не девять на строку: при десяти сборках
// это девяносто запросов вместо одного.
$asmPartsByAssembly = [];
if ($asmRows !== []) {
    $asmIds = array_map(static fn(array $r): int => (int) $r['assembly_id'], $asmRows);
    $asmColumns = array_keys($slots);
    $asmSelectList = implode(', ', array_map(static fn(string $c): string => "a.`$c`", $asmColumns));
    $asmPh = implode(',', array_fill(0, count($asmIds), '?'));
    $stmt = db_prepare(
        $mysql,
        "SELECT a.assembly_id, $asmSelectList FROM assembly a WHERE a.assembly_id IN ($asmPh)",
        str_repeat('i', count($asmIds)),
        ...$asmIds
    );
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $asmPartsByAssembly[(int) $row['assembly_id']] = $row;
    }
    $stmt->close();
}
?>

<h1 class="page-title">Сборки</h1>

<?php foreach ($asmBadMessages as $asmBadMessage): ?>
    <div class="alert alert--error"><?= escape($asmBadMessage) ?></div>
<?php endforeach; ?>
<?php if ($asmUsed): ?>
    <div class="alert alert--error"><?= escape($asmBadCodes['used']) ?></div>
<?php endif; ?>
<?php if ($asmSaved && $asmBadMessages === [] && !$asmUsed): ?>
    <div class="alert alert--success">Сохранено.</div>
<?php endif; ?>

<div class="settings-block">
    <div class="settings-subsection">
        <div class="settings-subsection__header">
            <h3 class="settings-subsection__title">Базовые сборки</h3>
            <button type="button" class="btn btn--primary btn--sm" data-action="add-assembly">
                + Добавить сборку
            </button>
        </div>
        <p class="settings-subsection__hint">
            Показываются на главной странице. Картинка берётся из выбранного
            корпуса, поэтому отдельного поля для неё нет. Сборки из
            конфигуратора сюда не попадают: их создаёт покупатель, и они
            удаляются как ненужные.
        </p>
    <?php if ($asmRows === []): ?>
        <p class="settings-list__hint">Базовых сборок нет. Добавьте первую.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th title="Картинка корпуса"></th>
                        <th>Название</th>
                        <th>Номер</th>
                        <th>Стоимость</th>
                        <th>Корпус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
    <?php foreach ($asmRows as $asmRow): ?>
        <?php
        $asmId = (int) $asmRow['assembly_id'];
        // состав строкой «категория:компонент» через запятую. Формат
        // выбран потому, что это обычный атрибут: значение не нужно
        // разбирать как JSON и не нужно экранировать - в нём только
        // цифры и двоеточия. Inline-скрипт с данными запрещён политикой
        // безопасности, а весь состав уже есть на странице.
        $asmPartsStr = [];
        foreach ($slots as $asmColumn => $asmCategoryId) {
            $asmPartId = (int) ($asmPartsByAssembly[$asmId][$asmColumn] ?? 0);
            if ($asmPartId > 0) {
                $asmPartsStr[] = $asmCategoryId . ':' . $asmPartId;
            }
        }
        ?>
                    <tr data-assembly-id="<?= $asmId ?>" data-parts="<?= escape(implode(',', $asmPartsStr)) ?>">
                        <td>
        <?php if (!empty($asmRow['case_image'])): ?>
                            <img src="<?= escape((string) $asmRow['case_image']) ?>" alt=""
                                 width="56" height="56" style="object-fit: contain;">
        <?php endif; ?>
                        </td>
                        <td>
                            <span class="social-row__name" title="<?= escape((string) $asmRow['assembly_name']) ?>"><?= escape((string) $asmRow['assembly_name']) ?></span>
                        </td>
                        <td>#<?= $asmId ?></td>
                        <td><?= number_format((int) $asmRow['assembly_price'], 0, '.', ' ') ?> ₽</td>
                        <td>
                            <span class="settings-list__hint"><?= escape((string) ($asmRow['case_name'] ?? '—')) ?></span>
                        </td>
                        <td>
                            <button type="button" class="btn-icon btn-icon--muted"
                                    data-action="edit-assembly"
                                    data-assembly-id="<?= $asmId ?>"
                                    title="Редактировать"
                                    aria-label="Редактировать <?= escape((string) $asmRow['assembly_name']) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <button type="button" class="btn-icon btn-icon--danger"
                                    data-action="delete-assembly"
                                    data-assembly-id="<?= $asmId ?>"
                                    data-assembly-name="<?= escape((string) $asmRow['assembly_name']) ?>"
                                    title="Удалить"
                                    aria-label="Удалить <?= escape((string) $asmRow['assembly_name']) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19a2 2 0 002 2h8a2 2 0 002-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                            </button>
                        </td>
                    </tr>
    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- Модалка сборки. Форма своя, отдельная от таблицы: вложенные формы
     в HTML недопустимы, а удаление идёт через свою скрытую форму. -->
<dialog id="assemblyModal" class="modal modal--wide">
    <form method="post" action="/admin.php?tab=assemblies" class="modal-form">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="assemblyAction" value="save">
        <input type="hidden" name="assemblyId" id="assemblyId" value="">

        <h2 id="assemblyModalTitle">Добавить сборку</h2>

        <div class="asm-slots">
            <div class="form-group">
                <label class="form-label" for="asName">Название</label>
                <input class="input" type="text" name="assembly_name" id="asName"
                       maxlength="100" required placeholder="Например, Игровая">
            </div>
            <div class="form-group">
                <label class="form-label" for="asPrice">Стоимость (₽)</label>
                <input class="input" type="number" name="assembly_price" id="asPrice"
                       min="0" max="10000000" step="1" required placeholder="150000">
            </div>
        </div>
        <p class="settings-block__hint">
            Стоимость показывается на главной как есть и никак не сверяется с
            суммой комплектующих.
        </p>

        <div class="modal-section">
            <h3>Состав сборки</h3>
            <div class="asm-slots">
    <?php foreach ($slots as $asmColumn => $asmCategoryId): ?>
        <?php $asmIsRequired = in_array($asmColumn, $requiredSlots, true); ?>
                <div class="form-group">
                    <label class="form-label" for="asComp<?= $asmCategoryId ?>">
                        <?= escape($asmCategoryNames[$asmCategoryId] ?? ('Категория ' . $asmCategoryId)) ?><?= $asmIsRequired ? ' *' : '' ?>
                    </label>
                    <select class="input" name="comp_<?= $asmCategoryId ?>" id="asComp<?= $asmCategoryId ?>"
                            <?= $asmIsRequired ? 'required' : '' ?>>
                        <option value="0">— не выбрано —</option>
        <?php foreach ($asmComponentsByCategory[$asmCategoryId] ?? [] as $asmComponent): ?>
                        <option value="<?= (int) $asmComponent['component_id'] ?>">
                            <?= escape((string) $asmComponent['component_name']) ?>
                            (<?= number_format((int) $asmComponent['component_price'], 0, '.', ' ') ?> ₽)<?= (int) $asmComponent['amount'] > 0 ? '' : ' — нет на складе' ?>
                        </option>
        <?php endforeach; ?>
                    </select>
                </div>
    <?php endforeach; ?>
            </div>
            <p class="settings-block__hint">
                Процессор и корпус обязательны.
            </p>
        </div>

        <div class="modal-actions">
            <div class="modal-actions-right">
                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                <button type="submit" class="btn btn--primary">Сохранить</button>
            </div>
        </div>
    </form>
</dialog>

<!-- Форма удаления. Отдельная скрытая, а не submit с name в общей:
     причина та же, что у соцсетей и пресетов, - вложенные формы
     недопустимы. -->
<form id="deleteAssemblyForm" method="post" action="/admin.php?tab=assemblies" hidden>
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="assemblyAction" value="delete">
    <input type="hidden" name="assemblyId" value="">
</form>

<?php
// Скрипт этой вкладки подключается через $extraJs в admin.php, а не
// тегом <script> в конце файла: <head> к моменту include уже выведен.
//
// Состав сборки лежит в data-parts строки таблицы, а не в отдельном
// запросе и не в inline-скрипте: инлайн-скрипты запрещены политикой
// безопасности, а весь состав уже есть на странице - его достаточно
// отдать строкой в атрибуте.
?>
