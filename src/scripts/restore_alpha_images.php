<?php
/**
 * Восстановление прозрачности картинок корпусов (баг).
 *
 * Проблема: миграция webp -> jpg (scripts/convert_webp_to_jpg.php) перевела
 * все файлы в JPEG вслепую. Все 17 картинок корпусов были с прозрачностью,
 * поэтому прозрачный фон залился белым и в базу ушли пути на .jpg.
 *
 * Скрипт берёт оригиналы .webp (из git-истории или копии) и прогоняет их
 * через process_uploaded_image(), который после фикса сохраняет любой
 * формат с альфой как .png.
 *
 * Запуск:
 *   php src/scripts/restore_alpha_images.php <каталог с webp-оригиналами> [--dry-run]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/../modules/connect.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--dry-run'));
$dryRun = in_array('--dry-run', $argv, true);
$sourceDir = $args[0] ?? '';

if (!is_dir($sourceDir)) {
    echo "каталог с webp-оригиналами не задан или не найден: {$sourceDir}\n";
    echo "пример: php scripts/restore_alpha_images.php /tmp/webp_orig\n";
    exit(1);
}

$casesDir = dirname(__DIR__) . '/assets/images/cases/';
$mysql = connect();

$sources = glob($sourceDir . '/*.webp') ?: [];
sort($sources);

echo "оригиналов: " . count($sources) . ($dryRun ? ' (DRY RUN)' : '') . "\n";
$converted = 0;
$refUpdated = 0;
$skipped = [];

foreach ($sources as $src) {
    $base = basename($src, '.webp');
    $result = process_uploaded_image($src, $casesDir, $base);

    if ($result === null) {
        $skipped[] = $base;
        echo "  FAIL {$base}.webp\n";
        continue;
    }
    $newName = basename($result['path']);
    $newPath = 'assets/images/cases/' . $newName;
    $converted++;
    printf("  %-18s -> %-18s %8d bytes\n", $base . '.webp', $newName, filesize($result['path']));

    // Привязка ищется по имени БЕЗ расширения: в базе путь мог остаться
    // на .jpg после старой миграции, поэтому сравнение и по полной строке
    // (которая тогда не совпала бы) здесь бесполезно.
    $stmt = db_prepare(
        $mysql,
        "UPDATE components SET image = ?
         WHERE SUBSTRING_INDEX(SUBSTRING_INDEX(image, '/', -1), '.', 1) = ?",
        "ss",
        $newPath,
        $base
    );
    $stmt->execute();
    $refUpdated += $stmt->affected_rows;

    // Прежний .jpg от старой миграции больше не нужен, если на него не
    // осталось ни одной привязки. Проверка по полному имени файла: поиск
    // по basename без расширения нашёл бы новую .png-привязку и решил,
    // что .jpg ещё нужен.
    $old = $casesDir . $base . '.jpg';
    if ($newName !== $old && is_file($old)) {
        $chk = db_prepare(
            $mysql,
            "SELECT COUNT(*) FROM components WHERE SUBSTRING_INDEX(image, '/', -1) = ?",
            "s",
            $base . '.jpg'
        );
        $chk->execute();
        if ((int) $chk->get_result()->fetch_row()[0] === 0) {
            if ($dryRun) {
                echo "  [dry] удалить осиротевший {$base}.jpg\n";
            } else {
                unlink($old);
                echo "  удалён осиротевший {$base}.jpg\n";
            }
        }
    }
}

$mysql->close();
printf(
    "\nконвертировано: %d, привязок обновлено: %d, ошибок: %d\n",
    $converted,
    $refUpdated,
    count($skipped)
);