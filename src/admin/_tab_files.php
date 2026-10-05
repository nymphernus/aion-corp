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

// Обработка ошибок удаления
if (isset($_GET['error']) && $_GET['error'] === 'used') {
    echo '<div class="alert alert--error">Файл используется компонентом. Сначала отвяжите его.</div>';
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
    
    $url = '/assets/images/cases/' . $name;
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
// (assets/images/cases/x.webp), а new-загрузки пишутся со слешем, поэтому
// LIKE проверяет только подстроку каталога - так ловятся оба формата.
// basename отрезает каталог, значит формат хранения на матчинг не влияет.
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

// FIX-3: сколько корпусов осталось без картинки. Пустая строка и NULL
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

?>

<h1 class="admin-title">Изображения</h1>

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
    <!-- FIX-3: счётчик кликабельный - ведёт к отфильтрованному списку -->
    <a href="/admin.php?tab=components&cat=6&no_image=1"
       class="files-stat files-stat--warning">
        <span class="files-stat__value"><?= $casesWithoutImage ?></span>
        <span class="files-stat__label">корпусов без картинки</span>
    </a>
</div>

<!-- Фильтр (FIX-4: селект+поиск в одном ряду, кнопки отдельной строкой) -->
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
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="btn btn--primary">Применить</button>
            <?php if ($filter !== '' || $query !== ''): ?>
                <a href="?tab=files" class="btn btn--ghost">Сбросить</a>
            <?php endif; ?>
            <span class="files-filter-found">Найдено: <?= count($filteredFiles) ?></span>
        </div>
    </form>
</div>

<!-- Сетка файлов -->
<div class="files-grid">
    <?php foreach ($filteredFiles as $f): ?>
        <div class="file-card">
            <div class="file-card__preview">
                <img src="<?= escape($f['url']) ?>" 
                     alt="<?= escape($f['basename']) ?>"
                     loading="lazy">
            </div>
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
            <div class="file-card__actions">
                <button type="button" 
                        class="btn-icon btn-icon--muted" 
                        data-action="delete-file"
                        data-file="<?= escape($f['basename']) ?>"
                        title="Удалить файл">
                    <svg width="16" height="16" viewBox="0 0 24 24" 
                         fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path>
                        <path d="M10 11v6M14 11v6"></path>
                    </svg>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>