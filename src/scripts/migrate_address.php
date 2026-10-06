<?php
/**
 * Разбивка users.user_address на отдельные поля .
 *
 * Миграция заполняет user_region / user_city / user_street / user_house /
 * user_apartment / user_postal_code по эвристикам разбора строки адреса.
 * Само user_address не меняется и остаётся как есть: это и legacy-значение,
 * и запасной вариант, если разбор ничего не дал.
 *
 * Использование:
 *   php src/scripts/migrate_address.php --selftest   # проверка разбора на примерах
 *   php src/scripts/migrate_address.php --dry-run    # план по реальным данным
 *   php src/scripts/migrate_address.php              # применить
 *   php src/scripts/migrate_address.php --overwrite  # перезаписать заполненные поля
 *
 * Решения по разбору:
 *   - маркер типа отбрасывается, регистр сохраняется: «г.Москва» -> Москва,
 *     «ул.Победы» -> Победы, «д.15» -> 15, «кв.7» -> 7. В форме у полей свои
 *     подписи, а разные написания («ул.» / «улица») в данных разъедутся
 *   - регион по префиксу («Республика Татарстан», «Край ...») и по суффиксу
 *     («Московская обл.»)
 *   - город: «г.», «г », «город», «с.», «пос.», «деревня»
 *   - улица: «ул.», «улица», «просп.», «пр-т», «пр.», «пер.», «бульв.», «б-р»,
 *     «ш.», «шоссе», «наб.», «набережная»
 *   - дом: «д.», «дом», «вл.», «влд»; «стр.» и «корп.» дописываются в дом
 *     («д. 1», «стр. 2» -> «1 стр. 2»), это части одного дома
 *   - квартира: «кв.», «квартира» (без «к.» - это корпус)
 *   - индекс: шесть цифр отдельной частью
 *   - часть без маркеров считается городом, если не больше трёх слов и нет
 *     цифр - в живых адресах «г.» часто опускают. Более длинный мусор
 *     («просто текст без адреса») в город не попадает и остаётся
 *     неразобранным
 *   - что не распознано - в новые поля не пишется, остаётся в user_address
 */

require_once __DIR__ . '/../modules/connect.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

$dryRun = in_array('--dry-run', $argv, true);
$overwrite = in_array('--overwrite', $argv, true);
$selftest = in_array('--selftest', $argv, true);

// колонки и их предельная длина в БД
const ADDRESS_FIELDS = [
    'user_region' => 100,
    'user_city' => 100,
    'user_street' => 150,
    'user_house' => 20,
    'user_apartment' => 20,
    'user_postal_code' => 10,
];

/**
 * Разбор одного адреса. Значения берутся из исходной строки (регистр
 * сохраняется), а совпадение ищется без учёта регистра.
 *
 * @return array{fields: array<string, ?string>, unparsed: string[]}
 */
function parseAddress(string $raw): array
{
    $fields = array_fill_keys(array_keys(ADDRESS_FIELDS), null);
    $unparsed = [];

    $raw = trim($raw);
    if ($raw === '') {
        return ['fields' => $fields, 'unparsed' => []];
    }

    // схлопываем пробелы и режем по запятой
    $raw = (string) preg_replace('/\s+/u', ' ', $raw);
    $parts = preg_split('/\s*,\s*/u', $raw);

    $cityTaken = false;

    foreach ($parts as $index => $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }

        // индекс: ровно шесть цифр
        if (preg_match('/^\d{6}$/u', $part)) {
            $fields['user_postal_code'] = $part;
            continue;
        }

        // регион в начале: «Республика Татарстан», «Край ...», «Обл. ...»
        if (preg_match('/^(?:республика|респ\.?|область|обл\.?|край)\s+(.+)$/iu', $part, $m)) {
            if ($fields['user_region'] === null) {
                $fields['user_region'] = cut($m[1], ADDRESS_FIELDS['user_region']);
                continue;
            }
        }

        // регион в конце: «Московская обл.»
        if (preg_match('/^(.+?)\s+(?:область|обл\.?|край|республика|респ\.?|ао)\.?$/iu', $part, $m)) {
            if ($fields['user_region'] === null) {
                $fields['user_region'] = cut($m[1], ADDRESS_FIELDS['user_region']);
                continue;
            }
        }

        // квартира раньше дома: в «кв.» нет ничего общего с «д.», но порядок
        // важен для «д. 15 к. 2», где к. относится к дому
        if (preg_match('/^(?:квартира|кв\.?)\s*(.+)$/iu', $part, $m)) {
            if ($fields['user_apartment'] === null) {
                $fields['user_apartment'] = cut($m[1], ADDRESS_FIELDS['user_apartment']);
                continue;
            }
        }

        // город с маркером
        if (preg_match('/^(?:город|городок|поселок|пос\.|деревня|с\.|г\.?|г)\s*(.+)$/iu', $part, $m)) {
            if ($fields['user_city'] === null) {
                $fields['user_city'] = cut($m[1], ADDRESS_FIELDS['user_city']);
                $cityTaken = true;
                continue;
            }
        }

        // улица
        if (preg_match('/^(?:улица|ул\.?|проспект|просп\.?|пр-т|пр\.|переулок|пер\.?|бульвар|бульв\.|б-р|шоссе|ш\.|набережная|наб\.?)\s*(.+)$/iu', $part, $m)) {
            if ($fields['user_street'] === null) {
                $fields['user_street'] = cut($m[1], ADDRESS_FIELDS['user_street']);
                continue;
            }
        }

        // улица с маркером в конце: «Невский пр-т», «Ленина ул.»
        if (preg_match('/^(.+?)\s+(?:улица|ул\.?|проспект|просп\.?|пр-т|переулок|пер\.?|бульвар|бульв\.|б-р|шоссе|набережная|наб\.?)$/iu', $part, $m)) {
            if ($fields['user_street'] === null) {
                $fields['user_street'] = cut($m[1], ADDRESS_FIELDS['user_street']);
                continue;
            }
        }

        // дом, включая «д. 15 к. 2»
        if (preg_match('/^(?:дом|влд|вл\.?|стр\.?|корп\.?|д\.?)\s*(.+)$/iu', $part, $m)) {
            if ($fields['user_house'] === null) {
                $fields['user_house'] = cut($m[1], ADDRESS_FIELDS['user_house']);
                continue;
            }
            // «д. 1», «стр. 2» - части одного дома, дописываем
            if (mb_strlen((string) $fields['user_house'], 'UTF-8') + 1 + mb_strlen($part, 'UTF-8')
                <= ADDRESS_FIELDS['user_house']) {
                $fields['user_house'] .= ' ' . $part;
                continue;
            }
            $unparsed[] = $part;
            continue;
        }

        // часть без маркеров - город, но только если это похоже на название:
        // не больше трёх слов и без цифр. Мусор в город не попадает.
        if (!$cityTaken && $fields['user_city'] === null
            && preg_match('/^[\p{L}\-.\s]+$/u', $part)
            && count(explode(' ', $part)) <= 3) {
            $fields['user_city'] = cut($part, ADDRESS_FIELDS['user_city']);
            $cityTaken = true;
            continue;
        }

        $unparsed[] = $part;
    }

    return ['fields' => $fields, 'unparsed' => $unparsed];
}

/**
 * Обрезка по длине колонки, чтобы в MyISAM не было тихой обрезки.
 */
function cut(string $value, int $limit): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, $limit, 'UTF-8');
}

/**
 * Проверка разбора на синтетических адресах: в базе всего один реальный
 * адрес, и «успешный» прогон на нём ничего не доказывает.
 */
function runSelfTest(): int
{
    $cases = [
        [
            'in' => 'г.Москва, ул.Победы, д.15',
            'want' => ['user_city' => 'Москва', 'user_street' => 'Победы', 'user_house' => '15'],
        ],
        [
            'in' => 'Московская обл., г.Химки, ул. Победы, д. 15 к. 2, 123456',
            'want' => [
                'user_region' => 'Московская',
                'user_city' => 'Химки',
                'user_street' => 'Победы',
                'user_house' => '15 к. 2',
                'user_postal_code' => '123456',
            ],
        ],
        [
            // маркер снимается, как и в «ул.Победы» -> Победы
            'in' => 'Санкт-Петербург, Невский пр-т, д. 28',
            'want' => ['user_city' => 'Санкт-Петербург', 'user_street' => 'Невский', 'user_house' => '28'],
        ],
        [
            'in' => 'ул. Победы, д.15',
            'want' => ['user_street' => 'Победы', 'user_house' => '15'],
        ],
        [
            'in' => 'Квартира 5, дом 2, улица Лесная, Казань',
            'want' => [
                'user_city' => 'Казань',
                'user_street' => 'Лесная',
                'user_house' => '2',
                'user_apartment' => '5',
            ],
        ],
        [
            'in' => 'Москва, ул. Тверская, д. 1, стр. 2, кв. 55, 125009',
            'want' => [
                'user_city' => 'Москва',
                'user_street' => 'Тверская',
                'user_house' => '1 стр. 2',
                'user_apartment' => '55',
                'user_postal_code' => '125009',
            ],
        ],
        [
            'in' => 'просто текст без адреса',
            'want' => [],
        ],
        [
            'in' => 'Республика Татарстан, г. Казань, ул. Баумана, д. 10, кв. 4',
            'want' => [
                'user_region' => 'Татарстан',
                'user_city' => 'Казань',
                'user_street' => 'Баумана',
                'user_house' => '10',
                'user_apartment' => '4',
            ],
        ],
    ];

    $failures = 0;
    foreach ($cases as $case) {
        $got = parseAddress($case['in']);
        $gotValues = array_filter($got['fields'], static fn ($v) => $v !== null && $v !== '');
        $want = $case['want'];
        $ok = count($gotValues) === count($want);
        if ($ok) {
            foreach ($want as $k => $v) {
                if (($gotValues[$k] ?? null) !== $v) {
                    $ok = false;
                    break;
                }
            }
        }
        if (!$ok) {
            $failures++;
        }
        printf("[%s] %s\n", $ok ? ' ok ' : 'FAIL', $case['in']);
        if (!$ok) {
            echo '        ждали:   ' . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
            echo '        получили: ' . json_encode($gotValues, JSON_UNESCAPED_UNICODE) . "\n";
            if ($got['unparsed']) {
                echo '        не разобрано: ' . implode(' | ', $got['unparsed']) . "\n";
            }
        }
    }

    printf("\n=== self-test ===\nслучаев: %d, провалено: %d\n", count($cases), $failures);
    return $failures === 0 ? 0 : 1;
}

if ($selftest) {
    exit(runSelfTest());
}

$mysql = connect();
$sel = array_map(static fn ($c) => "`$c`", array_keys(ADDRESS_FIELDS));
$stmt = $mysql->query('SELECT user_id, user_login, user_address, ' . implode(', ', $sel)
    . ' FROM users ORDER BY user_id');
if (!$stmt) {
    exit('Ошибка выборки: ' . $mysql->error . "\n");
}

$planned = 0;
$skipped = 0;
$errors = 0;
$empty = 0;
// $mysql->query() уже отдаёт mysqli_result, get_result() тут не нужен
$result = $stmt;

$labels = [
    'user_region' => 'регион  ',
    'user_city' => 'город   ',
    'user_street' => 'улица   ',
    'user_house' => 'дом     ',
    'user_apartment' => 'квартира',
    'user_postal_code' => 'индекс  ',
];

while ($row = $result->fetch_assoc()) {
    $address = trim((string) ($row['user_address'] ?? ''));
    $userId = (int) $row['user_id'];
    $login = (string) $row['user_login'];

    echo "[{$userId}] {$login}\n";

    if ($address === '') {
        echo "    user_address: нет, пропускаем\n\n";
        $empty++;
        continue;
    }
    echo "    user_address: {$address}\n";

    // уже заполнено - не перетираем без --overwrite
    $filled = 0;
    foreach (array_keys(ADDRESS_FIELDS) as $col) {
        if (($row[$col] ?? null) !== null && $row[$col] !== '') {
            $filled++;
        }
    }
    if ($filled === count(ADDRESS_FIELDS) && !$overwrite) {
        echo "    все новые поля уже заполнены, пропускаем (или --overwrite)\n\n";
        $skipped++;
        continue;
    }

    $parsed = parseAddress($address);

    // Заполняем только пустые поля. Иначе повторный запуск скрипта затирал бы
    // адрес, отредактированный вручную через админку: разбор legacy-строки
    // вернул бы старое значение. С --overwrite перезаписываем всё.
    $toWrite = [];
    $kept = [];
    foreach ($parsed['fields'] as $col => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $current = trim((string) ($row[$col] ?? ''));
        if ($current !== '' && !$overwrite) {
            if ($current !== $value) {
                $kept[$col] = $current;
            }
            continue;
        }
        $toWrite[$col] = $value;
    }

    foreach (ADDRESS_FIELDS as $col => $_) {
        printf("    %s: %s\n", $labels[$col], $toWrite[$col] ?? $kept[$col] ?? '-');
    }
    if ($kept) {
        $names = array_map(static fn ($c) => str_replace('user_', '', $c), array_keys($kept));
        echo '    оставлено как есть (заполнено вручную): ' . implode(', ', $names) . "\n";
    }
    if ($parsed['unparsed']) {
        echo '    не разобрано: ' . implode(' | ', $parsed['unparsed']) . "\n";
    }

    if ($toWrite === []) {
        echo "    записывать нечего\n\n";
        $skipped++;
        continue;
    }

    $planned++;

    if ($dryRun) {
        echo "    dry-run: без записи\n\n";
        continue;
    }

    // пишем только распознанные пустые поля, остальные не трогаем
    $cols = array_keys($toWrite);
    $set = implode(', ', array_map(static fn ($c) => "`$c` = ?", $cols));
    $types = str_repeat('s', count($cols) + 1);

    try {
        $params = array_values($toWrite);
        $params[] = $userId;
        $upd = db_prepare($mysql, "UPDATE users SET {$set} WHERE user_id = ?", $types, ...$params);
        $upd->execute();
        echo '    записано полей: ' . count($cols) . "\n\n";
    } catch (Throwable $e) {
        echo '    ОШИБКА: ' . $e->getMessage() . "\n\n";
        $errors++;
    }
}

echo "\n=== итог ===\n";
echo 'режим: ' . ($dryRun ? 'dry-run (без записи)' : 'запись') . "\n";
echo "будет обновлено пользователей: $planned\n";
echo "пропущено: $skipped\n";
echo "без адреса: $empty\n";
echo "ошибок: $errors\n";

$mysql->close();