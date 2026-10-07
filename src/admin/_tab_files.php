<?php
/**
 * Вкладка «Изображения» админ-панели (файловый менеджер).
 *
 * Подключается только из admin.php (admin.php?tab=files).
 * Прямой запрос к файлу → 404.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}

// Ошибки и результат файловых операций.
// unlinked=N - столько привязок снято при удалении файла: без этого
// сообщения не видно, что корпуса стали непривязанными. deleted=1
// означает, что файл физически снесли, deleted=0 - что его уже не было
// на диске и почистились только привязки.
$deleteErrors = [
    'undelete' => 'Файл не удалился с диска. Возможно, его использует другой процессор. Привязки не тронуты.',
];
$deleteError = (string) ($_GET['error'] ?? '');
if (isset($deleteErrors[$deleteError])) {
    echo '<div class="alert alert--error">' . escape($deleteErrors[$deleteError]) . '</div>';
}
if (isset($_GET['unlinked']) && (int) $_GET['unlinked'] > 0) {
    // Русские окончания: 1 корпус, 2-4 корпуса, 5 и дальше корпусов.
    $n = (int) $_GET['unlinked'];
    $word = 'корпусов';
    if ($n === 1) {
        $word = 'корпуса';
    } elseif ($n >= 2 && $n <= 4) {
        $word = 'корпуса';
    }
    echo '<div class="alert alert--success">Файл удалён, привязки сняты с ' . $n
        . ' ' . $word . '. Они теперь без картинки.</div>';
} elseif (isset($_GET['deleted'])) {
    echo '<div class="alert alert--success">Файл удалён.</div>';
} elseif (isset($_GET['unlinked'])) {
    echo '<div class="alert alert--success">Файл не был привязан ни к одному корпусу, удалён.</div>';
}

// --- Логика сбора файлов ---
// Файл лежит в /admin/, каталог картинок - на уровень выше.
$dir = __DIR__ . '/../assets/images/cases/';
$files = [];
$totalSize = 0;

foreach (scandir($dir) as $name) {
    if ($name === '.' || $name === '..') continue;
    if (strpos($name, '.') === 0) continue;  // hidden
    
    $path = $dir . $name;
    if (!is_file($path)) continue;
    
    $url = 'assets/images/cases/' . $name;
    $size = filesize($path);
    $totalSize += $size;
    
    $files[] = [
        'basename' => $name,
        'url' => $url,
        'size' => $size,
        'size_human' => human_size($size),
        'used_by' => false,
        'components' => [],
    ];
}

// Привязки из БД. В components.image лежат пути без ведущего слеша
// (assets/images/cases/x.png), а new-загрузки пишутся так же, поэтому
// LIKE проверяет подстроку каталога. basename отрезает каталог,
// значит формат хранения на матчинг не влияет.
$stmt = db_prepare($mysql, 
    "SELECT component_id, component_name, image 
     FROM components WHERE image IS NOT NULL AND image LIKE '%assets/images/cases/%'", "");
$stmt->execute();
$result = $stmt->get_result();
$usedFiles = 0;
while ($row = $result->fetch_assoc()) {
    $basename = basename($row['image']);
    foreach ($files as &$f) {
        if ($f['basename'] === $basename) {
            $f['used_by'] = true;
            $f['components'][] = [
                'id' => $row['component_id'],
                'name' => $row['component_name'],
            ];
            $usedFiles++;
        }
    }
    unset($f);
}

// Фильтрация
$filter = $_GET['filter'] ?? '';
$query = trim($_GET['q'] ?? '');
$filteredFiles = [];
foreach ($files as $f) {
    if ($filter === 'used' && !$f['used_by']) continue;
    if ($filter === 'orphan' && $f['used_by']) continue;
    if ($query !== '' && stripos($f['basename'], $query) === false) continue;
    $filteredFiles[] = $f;
}

// Сводка
$totalFiles = count($files);
$orphanFiles = $totalFiles - $usedFiles;
$totalSizeHuman = human_size($totalSize);

// сколько корпусов осталось без картинки. Пустая строка и NULL
// равнозначны: писать '' в поле никто не должен, но подстраховаться стоит.
$stmt = db_prepare($mysql,
    "SELECT COUNT(*) FROM components
     WHERE category_id = 6 AND (image IS NULL OR image = '')", "");
$stmt->execute();
$casesWithoutImage = (int) $stmt->get_result()->fetch_row()[0];

// --- Хелпер для форматирования размера ---
function human_size(int $bytes): string {
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

// Результат загрузки пачки: сколько сохранилось и что не вышло.
$uploadedCount = isset($_GET['uploaded']) ? (int) $_GET['uploaded'] : null;
$uploadedBad = isset($_GET['bad']) ? trim((string) $_GET['bad']) : '';

?>

<div class="files-header">
    <h1 class="admin-title">Изображения</h1>
    <div class="files-header__actions">
        <label class="btn btn--primary">
            <input type="file" id="filesBatchInput"
                   name="files[]" multiple
                   accept="image/jpeg,image/png,image/webp,image/gif"
                   style="display:none">
            Загрузить изображения
        </label>
    </div>
</div>

<?php if ($uploadedCount !== null): ?>
    <div class="alert <?= $uploadedCount > 0 ? 'alert--success' : 'alert--error' ?>">
        Загружено файлов: <?= $uploadedCount ?><?php
        if ($uploadedBad !== '') {
            echo '. Не загружено: ' . escape($uploadedBad);
        }
        ?>. Привяжите их к корпусам кликом по картинке.
    </div>
<?php endif; ?>

<!-- Сводка -->
<div class="files-summary">
    <div class="files-stat">
        <span class="files-stat__value"><?= $totalFiles ?></span>
        <span class="files-stat__label">всего файлов</span>
    </div>
    <div class="files-stat">
        <span class="files-stat__value"><?= $usedFiles ?></span>
        <span class="files-stat__label">привязано к компонентам</span>
    </div>
    <div class="files-stat">
        <span class="files-stat__value"><?= $orphanFiles ?></span>
        <span class="files-stat__label">не используется</span>
    </div>
    <div class="files-stat">
        <span class="files-stat__value"><?= $totalSizeHuman ?></span>
        <span class="files-stat__label">общий размер</span>
    </div>
    <!-- счётчик кликабельный - ведёт к отфильтрованному списку -->
    <a href="/admin.php?tab=components&cat=6&no_image=1"
       class="files-stat files-stat--warning">
        <span class="files-stat__value"><?= $casesWithoutImage ?></span>
        <span class="files-stat__label">корпусов без картинки</span>
    </a>
</div>

<!-- Фильтр (всё в один ряд) -->
<div class="admin-filters">
    <form method="get" class="admin-filters__form">
        <input type="hidden" name="tab" value="files">
        <div class="admin-filters__row">
            <select name="filter" class="input">
                <option value="">Все файлы</option>
                <option value="used" <?= $filter === 'used' ? 'selected' : '' ?>>Привязанные</option>
                <option value="orphan" <?= $filter === 'orphan' ? 'selected' : '' ?>>Не используется</option>
            </select>
            <input type="search" name="q" class="input"
                   placeholder="Поиск по имени"
                   value="<?= escape($query) ?>">
            <button type="submit" class="btn btn--primary">Применить</button>
            <?php if ($filter !== '' || $query !== ''): ?>
                <a href="?tab=files" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
            <span class="admin-filters__found">Найдено: <?= count($filteredFiles) ?></span>
        </div>
    </form>
</div>

<!-- Сетка файлов. Меню-«kebab» убрано: клик по самой картинке
     привязывает файл к корпусу, удаление - отдельной иконкой. -->
<div class="files-grid">
    <?php foreach ($filteredFiles as $f): ?>
        <div class="file-card">
            <button type="button"
                    class="file-card__preview"
                    data-action="attach-file"
                    data-file-url="<?= escape($f['url']) ?>"
                    title="Привязать к корпусу">
                <img src="<?= escape($f['url']) ?>"
                     alt="<?= escape($f['basename']) ?>"
                     loading="lazy">
            </button>
            <div class="file-card__info">
                <div class="file-card__name" 
                     title="<?= escape($f['basename']) ?>">
                    <?= escape($f['basename']) ?>
                </div>
                <div class="file-card__meta">
                    <?= $f['size_human'] ?>
                    <?php if ($f['used_by']): ?>
                        · <span class="badge badge--success">используется</span>
                    <?php else: ?>
                        · <span class="badge badge--warning">не используется</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($f['components'])): ?>
                    <div class="file-card__used-by">
                        Привязано к:
                        <?php foreach ($f['components'] as $c): ?>
                            <a href="/admin.php?tab=components&q=<?= urlencode($c['name']) ?>">
                                <?= escape($c['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Удаление: отдельная иконка в углу карточки. Привязанный
                 файл тоже можно удалить - предупреждение с перечислением
                 компонентов показывает confirmDeleteFile (JS). -->
            <button type="button"
                    class="file-card__delete"
                    data-action="delete-file"
                    data-file="<?= escape($f['basename']) ?>"
                    data-used="<?= $f['used_by'] ? '1' : '0' ?>"
                    data-used-by="<?= escape(json_encode(
                        array_map(
                            static fn(array $c): array => ['id' => (int) $c['id'], 'name' => $c['name']],
                            $f['components']
                        ),
                        JSON_UNESCAPED_UNICODE
                    )) ?>"
                    aria-label="Удалить <?= escape($f['basename']) ?>"
                    title="Удалить файл">
                <svg width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path>
                    <path d="M10 11v6M14 11v6"></path>
                </svg>
            </button>
        </div>
    <?php endforeach; ?>
</div>

<!--
    БЛОК 4: модалка привязки файла к корпусу. Список корпусов без
    картинки собирается ниже; пустой список -> подсказка вместо кнопок.
-->
<dialog id="attachCaseModal" class="modal modal--wide">
    <div class="modal-form">
        <h2>Привязать к корпусу</h2>
        <p class="form-hint">Выберите корпус. У корпуса с картинкой она будет заменена.</p>

<?php
// Полный список корпусов, а не только тех, у кого картинки нет:
// привязать файл нужно и для замены существующей картинки, а список
// без картинок такой возможности не давал вовсе.
// Совпадения по имени файла, а не по полному пути: путь в базе может
// лежать и со слешем, и без.
$astmt = db_prepare($mysql,
    "SELECT c.component_id, c.component_name, c.image
     FROM components c
     WHERE c.category_id = 6
     ORDER BY c.component_name", "");
$astmt->execute();
$allCasesList = $astmt->get_result()->fetch_all(MYSQLI_ASSOC);

$casesWithImage = 0;
foreach ($allCasesList as $c) {
    if (!empty($c['image'])) {
        $casesWithImage++;
    }
}
?>
        <div class="attach-search">
            <input type="search" id="attachCaseSearch"
                   class="input" placeholder="Поиск по названию корпуса">
        </div>

<?php if (empty($allCasesList)): ?>
            <div class="alert">Корпусов в каталоге нет</div>
<?php else: ?>
            <div class="attach-cases-list" id="attachCasesList">
                <?php foreach ($allCasesList as $c): ?>
                    <div class="attach-case-row"
                         data-search="<?= escape(mb_strtolower((string) $c['component_name'])) ?>">
                        <div class="attach-case-row__img">
<?php if (!empty($c['image'])): ?>
                            <img src="<?= escape('/' . $c['image']) ?>" alt="" loading="lazy">
<?php else: ?>
                            <span class="attach-case-row__noimg">нет</span>
<?php endif; ?>
                        </div>
                        <div class="attach-case-row__name">
                            <?= escape((string) $c['component_name']) ?>
<?php if (!empty($c['image'])): ?>
                            <span class="attach-case-row__current">
                                сейчас: <?= escape(basename((string) $c['image'])) ?>
                            </span>
<?php endif; ?>
                        </div>
                        <button type="button"
                                class="btn <?= !empty($c['image']) ? 'btn--secondary' : 'btn--primary' ?> btn--sm"
                                data-action="attach-file-confirm"
                                data-case-id="<?= (int) $c['component_id'] ?>">
                            <?= !empty($c['image']) ? 'Заменить' : 'Привязать' ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="form-hint attach-cases-hint">
                Корпусов всего: <?= count($allCasesList) ?>,
                с картинкой: <?= $casesWithImage ?>,
                без картинки: <?= count($allCasesList) - $casesWithImage ?>.
            </p>
<?php endif; ?>

        <div class="modal-actions">
            <div class="modal-actions-right">
                <button type="button" class="btn btn--secondary"
                        data-action="close-modal">Отмена</button>
            </div>
        </div>
    </div>
</dialog>

<!-- Скрытая форма привязки файла к корпусу. -->
<form id="attachFileForm" method="post" action="/admin.php?tab=files" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="return_params" value="<?= escape(admin_list_query('files')) ?>">
    <input type="hidden" name="fileUrl" value="">
    <input type="hidden" name="caseId" value="">
    <input type="hidden" name="attachFile" value="1">
</form>

<!-- Загрузка пачки файлов. Форму отправляет JS: у input multiple
     нельзя задать FileList из разметки, файлы передаются через FormData.
     CSRF берётся отсюда же - отдельного токена в форме не нужно, он
     один на страницу. -->
<form id="batchUploadForm" method="post" action="/admin.php?tab=files"
      enctype="multipart/form-data" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="return_params" value="<?= escape(admin_list_query('files')) ?>">
    <input type="hidden" name="batchUpload" value="1">
</form>