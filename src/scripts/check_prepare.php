<?php
/**
 * Сверка db_prepare: число плейсхолдеров = длина строки типов =
 * число переданных значений.
 *
 * Класс ошибок повторился трижды за одну серию правок:
 *   - 'ssis' вместо 'sisi' в миграции - иконка пресета записалась 0;
 *   - 'sisii' при четырёх колонках в UPDATE пресета;
 *   - VALUES с четырьмя плейсхолдерами при трёх колонках в INSERT ОС -
 *     Fatal error у пользователя.
 *
 * Все три раза php -l был чист: ошибка объявляется только при
 * выполнении запроса. Поэтому проверка живёт отдельно и запускается
 * вручную перед коммитом, где меняются вызовы db_prepare.
 *
 * Запуск:  php scripts/check_prepare.php [файл.php ...]
 * Код возврата: 1 при расхождении.
 *
 * Ограничение, о котором надо помнить: разбирается текст вызова, а не
 * исполняемый запрос. Вызовы с SQL в переменной ($countSql, $sql)
 * пропускаются - их плейсхолдеры считать не из чего.
 */

declare(strict_types=1);

$files = array_slice($argv, 1);
if ($files === []) {
    $files = [__DIR__ . '/..'];
}

// Каталог разворачивается в список файлов: перечислять тридцать путей
// в командной строке неудобно, а проверять надо всё дерево - ошибка
// с числом плейсхолдеров не знает границ модулей.
$expanded = [];
foreach ($files as $arg) {
    if (is_dir($arg)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arg, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            // тесты проверяют HTTP, а не текст запросов, и vendor -
            // чужая копия инструментария
            if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $expanded[] = $path;
        }
        continue;
    }
    $expanded[] = $arg;
}
$files = array_values(array_unique($expanded));

$totalChecked = 0;
$totalBad = 0;

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "нет файла: {$file}\n");
        exit(2);
    }

    $code = (string) file_get_contents($file);
    $checked = 0;
    $problems = [];

    // Однострочный вызов: всё в одной строке.
    $single = "/db_prepare\(\s*\\\$mysql,\s*(?P<q>['\"])(?<sql>[^'\"]+)(?P=q),\s*(?P<tq>['\"])(?<types>[a-z]*)(?P=tq),\s*(?<args>.*)\);\s*$/m";
    // Многострочный: SQL и типы на следующих строках.
    $multi = "/db_prepare\(\s*\\\$mysql,\s*(?P<q>['\"])(?<sql>[^'\"]+)(?P=q),\s*(?P<tq>['\"])(?<types>[a-z]*)(?P=tq),\s*(?<args>.*?)\s*\);/s";

    if (preg_match_all($single, $code, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $m) {
            if (skip_call($m['sql'])) {
                continue;
            }
            $checked++;
            $sql = $m['sql'];
            $types = $m['types'];
            $args = $m['args'];

            $q = substr_count($sql, '?');
            $t = strlen($types);
            $a = args_count($args);

            if ($q !== $t || ($a !== null && $q !== $a)) {
                $problems[] = sprintf(
                    "  '?'=%d, типы=%d ('%s'), значений=%s\n    %s",
                    $q,
                    $t,
                    $types,
                    $a === null ? 'неизвестно' : $a,
                    preg_replace('/\s+/', ' ', $sql)
                );
            }
        }
    }

    // Многострочные: вырезаем однострочные, чтобы не считать дважды
    $rest = preg_replace($single, '', $code);
    if ($rest !== null && preg_match_all($multi, $rest, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $m) {
            if (skip_call($m['sql'])) {
                continue;
            }
            $checked++;
            $sql = $m['sql'];
            $types = $m['types'];
            $args = $m['args'];

            $q = substr_count($sql, '?');
            $t = strlen($types);
            $a = args_count($args);

            if ($q !== $t || ($a !== null && $q !== $a)) {
                $problems[] = sprintf(
                    "  '?'=%d, типы=%d ('%s'), значений=%s\n    %s",
                    $q,
                    $t,
                    $types,
                    $a === null ? 'неизвестно' : $a,
                    preg_replace('/\s+/', ' ', $sql)
                );
            }
        }
    }

    $totalChecked += $checked;
    $totalBad += count($problems);

    echo basename($file) . " : проверено {$checked}\n";
    foreach ($problems as $p) {
        echo "  РАСХОЖДЕНИЕ{$p}\n";
    }
}

echo "\nвсего: {$totalChecked}, расхождений: {$totalBad}\n";
exit($totalBad > 0 ? 1 : 0);

/**
 * Вызов, который нельзя посчитать по тексту.
 *
 * SQL с интерполяцией пропускается: там "SELECT ... IN ($ph)", где
 * число плейсхолдеров подставляется переменной, и сверка дала бы
 * ложное расхождение - ноль '?' против непустой строки типов. Такие
 * запросы разбираются только вручную.
 *
 * @param string $sql
 * @return bool
 */
function skip_call(string $sql): bool
{
    return str_contains($sql, '$');
}

/**
 * Число значений, либо null, если оно неизвестно по тексту.
 *
 * null у вызовов с распаковкой (...$args): там количество
 * определяется типом переменной, а не скобками в коде. Сверка
 * плейсхолдеров с типами для таких вызовов остаётся в силе - это
 * как раз тот случай, где ошибка в строке типов невидима глазом.
 *
 * @return int|null
 */
function args_count(string $args): ?int
{
    if (str_contains($args, '...')) {
        return null;
    }

    return count_top_level_args($args);
}

/**
 * Число аргументов через запятые верхнего уровня.
 *
 * Запятая внутри скобок не разделитель: array('a', 'b') и
 * trim($x, ', ') содержат запятые, и наивный substr_count дал бы
 * завышенный счёт - то есть проверка прошла бы сломанный вызов.
 *
 * @param string $s
 * @return int
 */
function count_top_level_args(string $s): int
{
    $s = trim($s);
    if ($s === '') {
        return 0;
    }

    $count = 1;
    $level = 0;
    $len = strlen($s);

    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];

        if ($ch === "'" || $ch === '"') {
            // Пропускаем строковый литерал целиком
            $quote = $ch;
            for ($i++; $i < $len; $i++) {
                if ($s[$i] === '\\') {
                    $i++;
                    continue;
                }
                if ($s[$i] === $quote) {
                    break;
                }
            }
            continue;
        }

        if ($ch === '(' || $ch === '[') {
            $level++;
        } elseif ($ch === ')' || $ch === ']') {
            $level--;
        } elseif ($ch === ',' && $level === 0) {
            $count++;
        }
    }

    return $count;
}