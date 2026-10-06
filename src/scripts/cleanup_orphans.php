<?php
/**
 * CLI-скрипт удаления осиротевших сборок.
 *
 * Сборка появляется в базе сразу после работы конфигуратора, но остаётся
 * там навсегда. Пользователь сохранил её в избранное или купил - понятно,
 * а просто подобрал и ушёл - нет, и такие сборки копятся без ограничений.
 *
 * Осиротевшей считается сборка, на которую нет ни строки в favorites, ни
 * строки в orders. Базовые сборки витрины не трогаются никогда: их зовут
 * EinTech, Eternal и Magic Workbench, они витрина, а не результат
 * конфигуратора, и заводятся админом вручную.
 *
 * Признак базовой - колонка assembly.is_base, а не номер сборки. Раньше
 * здесь стояло «assembly_id > 3», и четвёртая базовая сборка, созданная
 * админом, получала номер 4 и удалялась через час. Скрипт запускается при
 * каждом старте контейнера, то есть минуту после создания сборки её уже
 * могло не стать.
 *
 * Возраст берётся из assembly.created_at. У сборок, созданных до
 * появления колонки, стоит дата добавления колонки, поэтому первый запуск
 * после миграции ничего не удалит - это сделает следующий.
 *
 * Использование:
 *   php src/scripts/cleanup_orphans.php                  старше часа
 *   php src/scripts/cleanup_orphans.php --hours=6       старше шести часов
 *   php src/scripts/cleanup_orphans.php --dry-run       только показать
 *   php src/scripts/cleanup_orphans.php --help
 *
 * Код возврата 0 - всё в порядке, 1 - ошибка.
 */

require_once __DIR__ . '/../modules/connect.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

$hours = 1;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Удаление осиротевших сборок\n";
        echo "  --hours=N   возраст в часах, по умолчанию 1\n";
        echo "  --dry-run   ничего не удалять, только показать\n";
        exit(0);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--hours=(\d+)$/', $arg, $m)) {
        $hours = (int) $m[1];
        continue;
    }
    // поддерживаем и раздельную форму: --hours 6
    if ($arg === '--hours') {
        $hours = -1;
        continue;
    }
    if ($hours === -1 && ctype_digit($arg)) {
        $hours = (int) $arg;
        continue;
    }
    fwrite(STDERR, 'Неизвестный аргумент: ' . $arg . PHP_EOL);
    fwrite(STDERR, 'Список аргументов: --help' . PHP_EOL);
    exit(1);
}

// ноль часов удалил бы всё подряд, включая сборки, которые пользователь
// открыл секунду назад и ещё смотрит
if ($hours < 1) {
    fwrite(STDERR, 'Ошибка: --hours должен быть не меньше 1' . PHP_EOL);
    exit(1);
}

try {
    $mysql = connect();
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка подключения к БД: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
mysqli_set_charset($mysql, 'utf8');

$hasCreatedAt = false;
// обе колонки проверяются до запроса, а не после. Без is_base запрос
// с is_base = 0 упал бы с Unknown column, а без created_at - с другой
// ошибкой; и то и другое после ALTER в migrate.php, который запускается
// раньше. Отказ с инструкцией лучше молчаливого пропуска сирот.
$stmt = db_prepare($mysql, "SHOW COLUMNS FROM assembly LIKE 'created_at'", '');
$stmt->execute();
$hasCreatedAt = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hasCreatedAt) {
    fwrite(STDERR, 'В таблице assembly нет колонки created_at.' . PHP_EOL);
    fwrite(STDERR, "Выполните:ALTER TABLE assembly ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;" . PHP_EOL);
    $mysql->close();
    exit(1);
}

$stmt = db_prepare($mysql, "SHOW COLUMNS FROM assembly LIKE 'is_base'", '');
$stmt->execute();
$hasIsBase = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hasIsBase) {
    fwrite(STDERR, 'В таблице assembly нет колонки is_base.' . PHP_EOL);
    fwrite(STDERR, 'Без неё скрипт не может отличить базовую сборку от результата' . PHP_EOL);
    fwrite(STDERR, 'конфигуратора и удалил бы витрину. Выполните:' . PHP_EOL);
    fwrite(STDERR, "  php src/scripts/migrate.php" . PHP_EOL);
    $mysql->close();
    exit(1);
}

// порог считаем в PHP, а не через INTERVAL ? HOUR: плейсхолдер внутри
// INTERVAL в prepared-запросе полагаться нельзя
$cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);

$find = db_prepare(
    $mysql,
    "SELECT a.assembly_id, a.assembly_name, a.assembly_price, a.created_at
     FROM assembly a
     LEFT JOIN favorites f ON f.assembly_id = a.assembly_id
     LEFT JOIN orders o ON o.assembly_id = a.assembly_id
     WHERE a.is_base = 0
       AND f.favorit_id IS NULL
       AND o.order_id IS NULL
       AND a.created_at < ?
     ORDER BY a.assembly_id",
    's',
    $cutoff
);
$find->execute();
$rows = $find->get_result()->fetch_all(MYSQLI_ASSOC);
$find->close();

echo 'Осиротевшие сборки' . PHP_EOL;
echo '===================' . PHP_EOL;
echo 'Порог: created_at < ' . $cutoff . ' (с ' . $hours . ' ч назад)' . PHP_EOL;
echo 'Базовые сборки (is_base = 1) не трогаются никогда.' . PHP_EOL;
echo PHP_EOL;

if (!$rows) {
    echo 'Найдено: 0. Удалять нечего.' . PHP_EOL;
    $mysql->close();
    exit(0);
}

echo 'Найдено: ' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    $id = (int) $row['assembly_id'];
    // конфигуратор называет сборку «#N», а это уже напечатано слева;
    // имя выводим только если оно несёт что-то ещё
    $name = (string) $row['assembly_name'];
    $tail = ($name === '#' . $id || $name === '') ? '' : '  ' . $name;
    printf(
        "  #%d  %s руб.  создан %s%s\n",
        $id,
        number_format((int) $row['assembly_price'], 0, ',', ' '),
        $row['created_at'],
        $tail
    );
}
echo PHP_EOL;

if ($dryRun) {
    echo 'Режим --dry-run: ничего не удалено.' . PHP_EOL;
    $mysql->close();
    exit(0);
}

// одной транзакцией: удаление и сборки, и её записи в избранном, если они
// успели появиться между поиском и удалением
$ids = array_map(static fn($r) => (int) $r['assembly_id'], $rows);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$mysql->begin_transaction();
try {
    $stmt = db_prepare(
        $mysql,
        "DELETE f FROM favorites f JOIN assembly a ON a.assembly_id = f.assembly_id"
        . " WHERE a.assembly_id IN ($placeholders)",
        str_repeat('i', count($ids)),
        ...$ids
    );
    $stmt->execute();
    $favDeleted = $stmt->affected_rows;
    $stmt->close();

    $stmt = db_prepare(
        $mysql,
        "DELETE FROM assembly WHERE assembly_id IN ($placeholders)",
        str_repeat('i', count($ids)),
        ...$ids
    );
    $stmt->execute();
    $asmDeleted = $stmt->affected_rows;
    $stmt->close();

    $mysql->commit();
} catch (Throwable $e) {
    $mysql->rollback();
    fwrite(STDERR, 'Ошибка удаления, откат: ' . $e->getMessage() . PHP_EOL);
    $mysql->close();
    exit(1);
}

echo 'Удалено сборок: ' . $asmDeleted . PHP_EOL;
echo 'Удалено записей избранного: ' . $favDeleted . PHP_EOL;

$stmt = db_prepare($mysql, "SELECT COUNT(*) AS n FROM assembly", '');
$stmt->execute();
$left = $stmt->get_result()->fetch_assoc();
$stmt->close();
echo 'Всего сборок осталось: ' . $left['n'] . PHP_EOL;

$mysql->close();
exit(0);