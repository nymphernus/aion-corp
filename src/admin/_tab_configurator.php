<?php
/**
 * Вкладка «Конфигуратор»: пресеты бюджета и операционные системы.
 *
 * Подключается из admin.php. Ожидает:
 *   $mysql — соединение (есть из admin.php)
 *   $extraJs — добавляется список скриптов
 *
 * Обе сущности выведены в две таблицы рядом, а не в одну с типом:
 * у них разные наборы полей, и общая таблица заставила бы держать
 * лишние колонки пустыми у каждой строки.
 */

// Ошибки сохранения приходят в адрес (?bad=...), потому что обработчик
// в admin.php перезагружает страницу редиректом: та же форма не может
// одновременно принять POST и отрисоваться с результатом. Коды
// переводятся здесь - в адресе должен лежать текст, а не имя кода.
$badCodes = [
    'preset_name' => 'Укажите название пресета.',
    'preset_name_long' => 'Название пресета длиннее 50 символов.',
    'preset_budget' => 'Бюджет должен быть от 1 000 до 10 000 000 ₽.',
    'preset_icon' => 'Выберите иконку из списка.',
    'preset_not_found' => 'Такого пресета больше нет.',
    'preset_action' => 'Неизвестное действие с пресетом.',
    'os_name' => 'Укажите название операционной системы.',
    'os_name_long' => 'Название ОС длиннее 100 символов.',
    'os_price' => 'Стоимость должна быть от 0 до 1 000 000 ₽.',
    'os_not_found' => 'Такой операционной системы больше нет.',
    'os_action' => 'Неизвестное действие с операционной системой.',
];
$badMessages = [];
foreach (explode(',', (string) ($_GET['bad'] ?? '')) as $code) {
    $code = trim($code);
    if ($code !== '') {
        $badMessages[] = $badCodes[$code] ?? $code;
    }
}

$saved = isset($_GET['saved']);

// Выборки читаются здесь, а не в admin.php: обе таблицы короткие,
// «Порядок» в формах нет, и у добавленных через них строк он был бы DEFAULT 0,
// со списками. Порядок вывода - по добавлению (id), а не по sort_order: поля
$cfgPresets = [];
$stmt = db_prepare(
    $mysql,
    'SELECT preset_id, preset_name, preset_budget, preset_icon, is_active
       FROM configurator_presets
      ORDER BY preset_id ASC',
    ''
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cfgPresets[] = $row;
}

$cfgOs = [];
$stmt = db_prepare(
    $mysql,
    'SELECT os_id, os_name, os_price, is_active
       FROM configurator_os
      ORDER BY os_id ASC',
    ''
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cfgOs[] = $row;
}

$presetIconNames = cfg_preset_icon_names();
?>

<h1 class="page-title">Конфигуратор</h1>

<?php foreach ($badMessages as $badMessage): ?>
    <div class="alert alert--error"><?= escape($badMessage) ?></div>
<?php endforeach; ?>
<?php if ($saved && $badMessages === []): ?>
    <div class="alert alert--success">Сохранено.</div>
<?php endif; ?>

<div class="settings-block">
    <div class="settings-subsection">
        <div class="settings-subsection__header">
            <h3 class="settings-subsection__title">Пресеты бюджета</h3>
            <button type="button" class="btn btn--primary btn--sm" data-action="add-preset">
                + Добавить пресет
            </button>
        </div>
        <p class="settings-subsection__hint">
            Показываются кнопками над полем бюджета в конфигураторе на
            главной, в порядке поля «Порядок». Выключенные на главной не
            видны, но остаются здесь. Клик по кнопке подставляет бюджет в
            поле - название и иконка служат подсказкой.
        </p>
    <?php if ($cfgPresets === []): ?>
        <p class="settings-list__hint">Пресетов нет. Добавьте первый.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table cfg-table">
                <thead>
                    <tr>
                        <th class="cfg-table__icon" title="Иконка"></th>
                        <th>Название</th>
                        <th class="cfg-table__num">Бюджет</th>
                        <th class="cfg-table__actions"></th>
                    </tr>
                </thead>
                <tbody>
    <?php foreach ($cfgPresets as $cfgPreset): ?>
                    <tr data-preset-id="<?= (int) $cfgPreset['preset_id'] ?>">
                        <td class="cfg-row__icon"><?= render_preset_icon((string) $cfgPreset['preset_icon'], 20) ?></td>
                        <td>
                            <span class="social-row__name" title="<?= escape((string) $cfgPreset['preset_name']) ?>"><?= escape((string) $cfgPreset['preset_name']) ?></span>
        <?php if ((int) $cfgPreset['is_active'] === 1): ?>
                            <span class="badge badge--success">показ</span>
        <?php else: ?>
                            <span class="badge">скрыт</span>
        <?php endif; ?>
                        </td>
                        <td class="cfg-table__num"><?= number_format((int) $cfgPreset['preset_budget'], 0, '.', ' ') ?> ₽</td>
                        <td class="cfg-table__actions">
                            <button type="button" class="btn-icon btn-icon--muted"
                                    data-action="edit-preset"
                                    data-preset-id="<?= (int) $cfgPreset['preset_id'] ?>"
                                    title="Редактировать"
                                    aria-label="Редактировать <?= escape((string) $cfgPreset['preset_name']) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <button type="button" class="btn-icon btn-icon--danger"
                                    data-action="delete-preset"
                                    data-preset-id="<?= (int) $cfgPreset['preset_id'] ?>"
                                    data-preset-name="<?= escape((string) $cfgPreset['preset_name']) ?>"
                                    title="Удалить"
                                    aria-label="Удалить <?= escape((string) $cfgPreset['preset_name']) ?>">
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

    <div class="settings-subsection">
        <div class="settings-subsection__header">
            <h3 class="settings-subsection__title">Операционные системы</h3>
            <button type="button" class="btn btn--primary btn--sm" data-action="add-os">
                + Добавить ОС
            </button>
        </div>
        <p class="settings-subsection__hint">
            Показываются выпадающим списком в конфигураторе на главной.
            Стоимость добавляется к цене сборки сверх бюджета, поэтому
            бюджет на железо от неё не уменьшается.
        </p>
    <?php if ($cfgOs === []): ?>
        <p class="settings-list__hint">Операционных систем нет. Добавьте первую.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table cfg-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th class="cfg-table__num">Стоимость</th>
                        <th class="cfg-table__actions"></th>
                    </tr>
                </thead>
                <tbody>
    <?php foreach ($cfgOs as $cfgOsRow): ?>
                    <tr data-os-id="<?= (int) $cfgOsRow['os_id'] ?>">
                        <td>
                            <span class="social-row__name" title="<?= escape((string) $cfgOsRow['os_name']) ?>"><?= escape((string) $cfgOsRow['os_name']) ?></span>
        <?php if ((int) $cfgOsRow['is_active'] === 1): ?>
                            <span class="badge badge--success">показ</span>
        <?php else: ?>
                            <span class="badge">скрыта</span>
        <?php endif; ?>
                        </td>
                        <td class="cfg-table__num">
        <?php if ((int) $cfgOsRow['os_price'] > 0): ?>
                            <?= number_format((int) $cfgOsRow['os_price'], 0, '.', ' ') ?> ₽
        <?php else: ?>
                            <span class="settings-list__hint">бесплатно</span>
        <?php endif; ?>
                        </td>
                        <td class="cfg-table__actions">
                            <button type="button" class="btn-icon btn-icon--muted"
                                    data-action="edit-os"
                                    data-os-id="<?= (int) $cfgOsRow['os_id'] ?>"
                                    title="Редактировать"
                                    aria-label="Редактировать <?= escape((string) $cfgOsRow['os_name']) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <button type="button" class="btn-icon btn-icon--danger"
                                    data-action="delete-os"
                                    data-os-id="<?= (int) $cfgOsRow['os_id'] ?>"
                                    data-os-name="<?= escape((string) $cfgOsRow['os_name']) ?>"
                                    title="Удалить"
                                    aria-label="Удалить <?= escape((string) $cfgOsRow['os_name']) ?>">
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

<!-- Модалка пресета. Отдельная форма, а не часть общей: вложенные
     формы в HTML недопустимы, а у каждой сущности своя форма с
     multipart-ом. -->
<dialog id="presetModal" class="modal">
    <form method="post" action="/admin.php?tab=configurator" class="modal-form">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="presetAction" value="save">
        <input type="hidden" name="presetId" id="presetId" value="">

        <h2 id="presetModalTitle">Добавить пресет</h2>

        <div class="form-group">
            <label class="form-label" for="presetName">Название</label>
            <input class="input" type="text" name="preset_name" id="presetName"
                   maxlength="50" required placeholder="Например, Офис">
        </div>

        <div class="form-group">
            <label class="form-label" for="presetBudget">Бюджет (₽)</label>
            <input class="input" type="number" name="preset_budget" id="presetBudget"
                   min="1000" max="10000000" step="1000" required placeholder="50000">
            <p class="settings-block__hint">
                От этой суммы конфигуратор раздаёт железо по процентам.
            </p>
        </div>

        <div class="form-group">
            <span class="form-label">Иконка</span>
            <div class="social-icon-picker" id="presetIconPicker">
    <?php foreach ($presetIconNames as $presetIconValue => $presetIconLabel): ?>
                <label class="social-icon-option">
                    <input type="radio" name="preset_icon" value="<?= escape($presetIconValue) ?>"
                        <?= $presetIconValue === 'monitor' ? 'checked' : '' ?>>
                    <span class="social-icon-option__box">
                        <?= render_preset_icon($presetIconValue, 24) ?>
                        <span><?= escape($presetIconLabel) ?></span>
                    </span>
                </label>
    <?php endforeach; ?>
            </div>
        </div>

        <!-- Поля «Порядок» нет: вывод идёт в порядке добавления
             (preset_id / os_id), а ручная сортировка админу не нужна.
             Колонка sort_order в таблице осталась как резерв. -->
        <div class="form-group">
            <span class="form-label">Показ</span>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" id="presetActive" value="1" checked>
                Показывать в конфигураторе
            </label>
        </div>

        <div class="modal-actions">
            <div class="modal-actions-right">
                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                <button type="submit" class="btn btn--primary">Сохранить</button>
            </div>
        </div>
    </form>
</dialog>

<!-- Модалка ОС. Поля свои, форма своя: сущности независимы, и общая
     форма означала бы два набора скрытых полей с одним id. -->
<dialog id="osModal" class="modal">
    <form method="post" action="/admin.php?tab=configurator" class="modal-form">
        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
        <input type="hidden" name="osAction" value="save">
        <input type="hidden" name="osId" id="osId" value="">

        <h2 id="osModalTitle">Добавить операционную систему</h2>

        <div class="form-group">
            <label class="form-label" for="osName">Название</label>
            <input class="input" type="text" name="os_name" id="osName"
                   maxlength="100" required placeholder="Например, Windows 11 Pro">
        </div>

        <div class="form-group">
            <label class="form-label" for="osPrice">Стоимость (₽)</label>
            <input class="input" type="number" name="os_price" id="osPrice"
                   min="0" max="1000000" step="100" required placeholder="11000">
            <p class="settings-block__hint">
                Добавляется к цене сборки сверх бюджета. Ноль - бесплатно.
            </p>
        </div>

        <div class="form-group">
            <span class="form-label">Показ</span>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" id="osActive" value="1" checked>
                Показывать в списке на главной
            </label>
        </div>

        <div class="modal-actions">
            <div class="modal-actions-right">
                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                <button type="submit" class="btn btn--primary">Сохранить</button>
            </div>
        </div>
    </form>
</dialog>

<!-- Формы удаления. Скрытые отдельные формы, а не submit с name в
     общей: у соцсетей так же, и причина одна - вложенные формы
     недопустимы. -->
<form id="deletePresetForm" method="post" action="/admin.php?tab=configurator" hidden>
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="presetAction" value="delete">
    <input type="hidden" name="presetId" value="">
</form>
<form id="deleteOsForm" method="post" action="/admin.php?tab=configurator" hidden>
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="osAction" value="delete">
    <input type="hidden" name="osId" value="">
</form>

<?php
// Модалка подтверждения удаления - общая из partials/confirm-modal.php,
// подключается в header.php. Здесь нужен только вызов из JS.
//
// Скрипт этой вкладки подключается через $extraJs в admin.php, а не
// тегом <script> в конце файла: <head> к моменту include уже выведен,
// и по аналогии с остальными вкладками скрипт перечисляется там.
?>
