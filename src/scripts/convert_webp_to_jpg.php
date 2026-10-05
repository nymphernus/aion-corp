<?php
/**
 * Конвертация .webp -> .jpg в assets/images/cases/ + перепривязка в БД.
 *
 * Запуск: php scripts/convert_webp_to_jpg.php [--dry-run]
 *
 * Логика:
 *   - обходит cases/, находит .webp;
 *   - imagecreatefromwebp -> imagejpeg(quality 85) с тем же basename;
 *   - удаляет .webp;
 *   - UPDATE components SET image = REPLACE(image, '.webp', '.jpg')
 *     (затрагивает и формы со слешем, и без).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../modules/connect.php';

$dryRun = in_array('--dry-run', $argv, true);
$dir = dirname(__DIR__) . '/assets/images/cases/';

if (!is_dir($dir)) {
    echo "Каталог не найден: $dir\n";
    exit(1);
}

// --- 1. Файлы .webp ---
$webpFiles = [];
foreach (scandir($dir) as $name) {
    if ($name === '.' || $name === '..' || $name[0] === '.') {
        continue;
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'webp' && is_file($dir . $name)) {
        $webpFiles[] = $name;
    }
}

// --- 2. Привязки в БД ---
$mysql = connect();
$stmt = db_prepare($mysql, "SELECT component_id, image FROM components WHERE image LIKE '%.webp'", "");
$stmt->execute();
$refs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "webp files: " . count($webpFiles) . "\n";
echo "db refs: " . count($refs) . "\n";
if ($dryRun) {
    echo "\n[DRY RUN] план:\n";
    foreach ($webpFiles as $f) {
        echo "  convert: $f -> " . preg_replace('/\.webp$/i', '.jpg', $f) . "\n";
    }
    foreach ($refs as $r) {
        echo "  ref: component {$r['component_id']} " . $r['image'] . " -> " . str_replace('.webp', '.jpg', $r['image']) . "\n";
    }
    exit(0);
}

echo "\n--- применяю ---\n";

// --- 3. Конвертация файлов ---
$converted = 0;
$failed = [];
foreach ($webpFiles as $name) {
    $src = $dir . $name;
    $dst = $dir . preg_replace('/\.webp$/i', '.jpg', $name);

    $im = @imagecreatefromwebp($src);
    if ($im === false) {
        $failed[] = $name;
        echo "  FAIL decode: $name\n";
        continue;
    }
    $ok = imagejpeg($im, $dst, 85);
    imagedestroy($im);
    if (!$ok) {
        $failed[] = $name;
        echo "  FAIL encode: $name\n";
        continue;
    }
    // webp уже сжатый, jpeg весит больше - удалять src можно только
    // если dst реально создан и не пуст
    if (!is_file($dst) || filesize($dst) === 0) {
        $failed[] = $name;
        echo "  FAIL empty dst: $name\n";
        continue;
    }
    unlink($src);
    $converted++;
    echo "  ok: $name -> " . basename($dst) . ' ' . filesize($dst) . " bytes\n";
}

// --- 4. UPDATE БД (только по успешно сконвертированным basename) ---
$okNames = [];
foreach ($webpFiles as $name) {
    $base = strtolower($name);
    if (!in_array($name, $failed, true)) {
        $okNames[basename($name)] = preg_replace('/\.webp$/i', '.jpg', basename($name));
    }
}

$updated = 0;
foreach ($refs as $r) {
    $old = (string) $r['image'];
    $base = basename($old);
    if (!isset($okNames[$base])) {
        echo "  SKIP ref (файл не сконвертирован): component {$r['component_id']} $old\n";
        continue;
    }
    $new = str_replace($base, $okNames[$base], $old);
    $u = db_prepare($mysql, "UPDATE components SET image = ? WHERE component_id = ?", "si", $new, (int) $r['component_id']);
    $u->execute();
    $updated++;
}

$mysql->close();
echo "\nconverted: $converted, failed: " . count($failed) . ", db updated: $updated\n";