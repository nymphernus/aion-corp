<?php
/**
 * Обогащение компонентов: производитель и модель (Stage 3.7-j-1, эшелон 1).
 *
 * Заполняет manufacturer и model у старых компонентов, названия которых
 * заведены вручную: «i5-10400F», «ASRock Z690 Extreme», «4gbx2».
 * Новые 50 компонентов из seed_components.php заполнены руками и не
 * трогаются - скрипт не пишет в поле, где уже есть значение.
 *
 * Использование:
 *   php src/scripts/enrich_components.php --selftest            # тесты парсера, без БД
 *   php src/scripts/enrich_components.php --stage=1 --dry-run  # план изменений
 *   php src/scripts/enrich_components.php --stage=1            # применить
 *
 * Эшелоны 2 (specs) и 3 в этом подэтапе не реализованы: им нужны
 * поля, которых у старых компонентов ещё нет - у 15 RAM и 15 SSD
 * capacity_gb и ram_type пусты, frequency_mhz тоже. Без них шаблоны
 * description выродились бы в «Оперативная память ГБ DDR», поэтому
 * description в эшелоне 1 не трогаем вовсе.
 *
 * Как определяется производитель:
 *   1. CPU (категория 1) - по линейке, потому что бренда в названии
 *      нет: i3/i5/i7/i9, Celeron, Pentium, Core -> Intel; Ryzen,
 *      Athlon, FX, Threadripper, EPYC, A6-/A8- -> AMD. Моделью
 *      становится всё название целиком.
 *   2. Префикс Intel/AMD в начале названия - так выглядят новые
 *      компоненты («AMD Ryzen 5 5600 3.5GHz 32MB AM4»).
 *   3. Словарь брендов: поиск по n-граммам от 1 до 3 слов, регистр не
 *      важен, при совпадениях в одной позиции выигрывает самый длинный
 *      вариант. Без этого «Western» перебил бы «Western Digital»,
 *      а «be» - «be quiet!».
 *   4. Не нашлось: manufacturer остаётся NULL, модель - всё название.
 *
 * Известные ограничения:
 *   - «AMD Radeon» встречается внутри названий карт с AIB-брендом
 *     («PowerColor AMD Radeon R7 240»). Самое левое совпадение отдаёт
 *     бренд AIB, и это верно. Если появится «AMD Radeon RX 580» без
 *     AIB-бренда, производителем станет AMD - тоже верно, это видеоядро,
 *     моделью будет «Radeon RX 580».
 *   - «WD» в словаре есть, но ни одного компонента с таким названием
 *     в базе нет - правило оставлено на будущее.
 *   - Intel и AMD в общий словарь не внесены сознательно: они же
 *     встречаются внутри названий видеокарт, и в словаре им не место.
 *     Оба обрабатываются правилами 1 и 2.
 *   - model обрезается до 100 символов - длина колонки varchar(100).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

// ── Флаги ──────────────────────────────────────────────────────────────
$dryRun = in_array('--dry-run', $argv, true);
$selftest = in_array('--selftest', $argv, true);
$stage = 1;
foreach ($argv as $arg) {
    if (preg_match('/^--stage=(\d+)$/', $arg, $m)) {
        $stage = (int) $m[1];
    }
}

const CAT_CPU = 1;
const CAT_VIDEO = 3;

/**
 * Intel и AMD в словарь сознательно не внесены: они же встречаются
 * внутри названий карт вроде «PowerColor AMD Radeon R7 240», и в
 * словаре им не место. Оба обрабатываются правилами 1 и 2 выше.
 *
 * Словарь: каноническое начертание => варианты написания.
 * Варианты нужны только для написаний, которые не лечатся сравнением
 * без учёта регистра: A-Data/ADATA, ADATA/AD-ATA. Регистр (GIGABYTE
 * против Gigabyte, Powercolor против PowerColor) снимается сам.
 */
const BRAND_DICTIONARY = [
    'A-Data' => ['A-Data', 'ADATA'],
    'AFOX' => ['AFOX'],
    'Accord' => ['Accord'],
    'ASRock' => ['ASRock'],
    'ASUS' => ['ASUS'],
    'AeroCool' => ['AeroCool'],
    'Apacer' => ['Apacer'],
    'Cougar' => ['Cougar'],
    'Corsair' => ['Corsair'],
    'Cooler Master' => ['Cooler Master'],
    'Crucial' => ['Crucial'],
    'DeepCool' => ['DeepCool'],
    'EVGA' => ['EVGA'],
    'ExeGate' => ['ExeGate'],
    'Fractal Design' => ['Fractal Design'],
    'G.Skill' => ['G.Skill'],
    'Gainward' => ['Gainward'],
    'GiNZZU' => ['GiNZZU'],
    'Gigabyte' => ['Gigabyte'],
    'GOODRAM' => ['GOODRAM'],
    'HIPER' => ['HIPER'],
    'HyperX' => ['HyperX'],
    'ID-Cooling' => ['ID-Cooling'],
    'Inno3D' => ['Inno3D'],
    'KFA2' => ['KFA2'],
    'Kingston' => ['Kingston'],
    'Lexar' => ['Lexar'],
    'LG' => ['LG'],
    'Leadtek' => ['Leadtek'],
    'MONTECH' => ['MONTECH'],
    'MSI' => ['MSI'],
    'Manli' => ['Manli'],
    'NZXT' => ['NZXT'],
    'Netac' => ['Netac'],
    'Noctua' => ['Noctua'],
    'PNY' => ['PNY'],
    'Palit' => ['Palit'],
    'Patriot' => ['Patriot'],
    'PowerColor' => ['PowerColor'],
    'Sapphire' => ['Sapphire'],
    'Samsung' => ['Samsung'],
    'Seagate' => ['Seagate'],
    'Silicon Power' => ['Silicon Power'],
    'Sunbow' => ['Sunbow'],
    'TeamGroup' => ['TeamGroup'],
    'Thermaltake' => ['Thermaltake'],
    'Western Digital' => ['Western Digital'],
    // тремя написаниями: с восклицательным знаком (так в базе), без него
    // и слитно - разбор идёт по n-граммам, «be quiet!» длиннее и выигрывает
    'be quiet!' => ['be quiet!', 'be quiet', 'beQuiet'],
    'WD' => ['WD'],
    'Xilence' => ['Xilence'],
    'ZALMAN' => ['ZALMAN'],
    'Zotac' => ['Zotac'],
];

/** Линейки CPU: brand => список регулярок по началу названия. */
const CPU_LINES = [
    'Intel' => [
        '/^i[3579][-\s]/i',          // i5-10400F
        '/^i[3579]\d/i',             // i5 10400
        '/^Celeron\b/i',
        '/^Pentium\b/i',
        '/^Core\s+(i[3579]|Ultra|2nd|3rd|4th|5th|6th)/i',
    ],
    'AMD' => [
        '/^Ryzen\b/i',
        '/^Threadripper\b/i',
        '/^Athlon\b/i',
        '/^FX[- ]?\d/i',
        '/^EPYC\b/i',
        '/^A[1-9][0-9]?-\d/i',       // A6-9500E, A8-9600 - линейка APU AMD
    ],
];

/**
 * Убирает лишние пробелы и обрезает края.
 * Названия из базы местами хранятся с двойными пробелами
 * («Corsair iCUE H150i  RGB PRO XT»), из-за чего n-граммы
 * не совпали бы с словарём.
 */
function normalizeName(string $name): string
{
    $clean = preg_replace('/\s+/u', ' ', trim($name));
    return $clean ?? trim($name);
}

/**
 * Ищет бренд по словарю.
 * Возвращает ['brand' => каноническое, 'pos' => смещение, 'len' => длина]
 * или null. При нескольких совпадениях в одной позиции побеждает
 * самое длинное - для этого варианты отсортированы по убыванию длины,
 * а PCRE выбирает первое сработавшее альтернативное.
 */
function matchBrand(string $name): ?array
{
    static $pattern = null;
    static $owner = null;

    if ($pattern === null) {
        $owner = [];
        $variants = [];
        foreach (BRAND_DICTIONARY as $brand => $spellings) {
            foreach ($spellings as $spelling) {
                $key = mb_strtolower($spelling);
                $variants[$key] = true;
                $owner[$key] = $brand;
            }
        }
        $keys = array_keys($variants);
        usort($keys, static function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });
        $quoted = array_map(static function ($key) {
            return preg_quote($key, '/');
        }, $keys);
        // Модификатор i обязателен: варианты словаря приведены к нижнему
        // регистру, а применяется паттерн к исходному названию, где бренд
        // может быть написан как угодно (GIGABYTE, Gigabyte, Powercolor).
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', $quoted) . ')(?![\p{L}\p{N}])/iu';
    }

    if (!preg_match($pattern, $name, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $hit = mb_strtolower($m[0][0]);

    return [
        'brand' => $owner[$hit] ?? $hit,
        'pos' => $m[0][1],
        'len' => mb_strlen($m[0][0]),
    ];
}

/**
 * Разбирает название в пару производитель + модель.
 * Модель обрезается по длине колонки (varchar(100)).
 */
function parseComponent(string $rawName, int $categoryId): array
{
    $name = normalizeName($rawName);

    // 1. CPU: бренда в названии нет, определяем по линейке
    if ($categoryId === CAT_CPU) {
        foreach (CPU_LINES as $brand => $patterns) {
            foreach ($patterns as $re) {
                if (preg_match($re, $name)) {
                    // модель - всё название: «i5-10400F», «Ryzen 5 3600»
                    return ['manufacturer' => $brand, 'model' => mb_substr($name, 0, 100)];
                }
            }
        }
    }

    // 2. Явный префикс бренда: «AMD Ryzen 5 5600 ...», «Intel Core i5-...»
    if (preg_match('/^(Intel|AMD)\s+(.+)$/i', $name, $m)) {
        return [
            'manufacturer' => strtoupper($m[1]) === 'AMD' ? 'AMD' : 'Intel',
            'model' => mb_substr(trim($m[2]), 0, 100),
        ];
    }

    // 3. Словарь брендов
    $hit = matchBrand($name);
    if ($hit !== null) {
        $rest = trim(substr($name, $hit['pos'] + $hit['len']));
        // после бренда может остаться разделитель: «be quiet! SYSTEM...»,
        // «ASRock, Z690» и т.п.
        $rest = trim(preg_replace('/^[\s\-_,;:.]+/u', '', $rest));
        return [
            'manufacturer' => $hit['brand'],
            // бренд без остатка - моделью становится всё название,
            // иначе в колонку уехал бы пустой вид
            'model' => mb_substr($rest !== '' ? $rest : $name, 0, 100),
        ];
    }

    // 4. Ничего не нашли: производитель неизвестен
    return ['manufacturer' => null, 'model' => mb_substr($name, 0, 100)];
}

/** Выравнивание по символам, а не по байтам (для кириллицы). */
function pad(string $text, int $width): string
{
    $diff = $width - mb_strlen($text);
    return $diff > 0 ? $text . str_repeat(' ', $diff) : $text;
}

/**
 * Тесты парсера на именах, которые встречаются в базе.
 * Ходят по всем скрытым ловушкам: многословные бренды, регистр,
 * префикс перед брендом, AMD внутри названия карты, CPU без бренда.
 */
function runSelftest(): int
{
    $cases = [
        // [название, категория, ожидаемый производитель, ожидаемая модель]
        ['Samsung 970 EVO Plus M.2 500gb', CAT_VIDEO, 'Samsung', '970 EVO Plus M.2 500gb'],
        ['ASRock Z690 Extreme', 2, 'ASRock', 'Z690 Extreme'],
        ['i5-10400F', CAT_CPU, 'Intel', 'i5-10400F'],
        ['Celeron G5905', CAT_CPU, 'Intel', 'Celeron G5905'],
        ['Pentium Gold G6405', CAT_CPU, 'Intel', 'Pentium Gold G6405'],
        ['Ryzen 5 3600', CAT_CPU, 'AMD', 'Ryzen 5 3600'],
        ['Athlon 3000G', CAT_CPU, 'AMD', 'Athlon 3000G'],
        ['A8-9600', CAT_CPU, 'AMD', 'A8-9600'],
        ['A6-9500E', CAT_CPU, 'AMD', 'A6-9500E'],
        ['Palit GeForce RTX 3060 Ti DUAL OC V1 (LHR)', CAT_VIDEO, 'Palit', 'GeForce RTX 3060 Ti DUAL OC V1 (LHR)'],
        // многословные бренды: без приоритета длины «Western» перебил бы
        // «Western Digital», а «be» - «be quiet!»
        ['Western Digital Blue 500gb', 8, 'Western Digital', 'Blue 500gb'],
        ['Western Digital Blue M.2 2tb', 9, 'Western Digital', 'Blue M.2 2tb'],
        ['be quiet! SYSTEM POWER 9 600W', 5, 'be quiet!', 'SYSTEM POWER 9 600W'],
        ['be quiet! DARK ROCK 4', 7, 'be quiet!', 'DARK ROCK 4'],
        ['Cooler Master MasterLiquid ML360 RGB TR4 Edition', 7, 'Cooler Master', 'MasterLiquid ML360 RGB TR4 Edition'],
        ['ID-Cooling AURAFLOW X 360 SNOW', 7, 'ID-Cooling', 'AURAFLOW X 360 SNOW'],
        // бренд не первым словом
        ['DVD-RW LG GH24NSD5', 10, 'LG', 'GH24NSD5'],
        // AMD внутри названия карты: бренд AIB должен выиграть
        ['PowerColor AMD Radeon R7 240', CAT_VIDEO, 'PowerColor', 'AMD Radeon R7 240'],
        ['ASRock AMD Radeon RX 6600 Challenger D', CAT_VIDEO, 'ASRock', 'AMD Radeon RX 6600 Challenger D'],
        // разный регистр написания одного и того же бренда
        ['GIGABYTE AORUS P1200W', 5, 'Gigabyte', 'AORUS P1200W'],
        ['Powercolor AMD Radeon RX 6700 XT Fighter', CAT_VIDEO, 'PowerColor', 'AMD Radeon RX 6700 XT Fighter'],
        ['DEEPCOOL AK620', 7, 'DeepCool', 'AK620'],
        ['ZOTAC GAMING GeForce RTX 3060 Ti AMP LHR White Edition', CAT_VIDEO, 'Zotac', 'GAMING GeForce RTX 3060 Ti AMP LHR White Edition'],
        ['ZALMAN i3 Edge', 6, 'ZALMAN', 'i3 Edge'],
        ['Goodram Iridium 4gbx2', 4, 'GOODRAM', 'Iridium 4gbx2'],
        ['Aerocool Cylon White', 6, 'AeroCool', 'Cylon White'],
        // написание через дефис и точку
        ['A-Data XPG Spectrix D60G RGB 16gbx2', 4, 'A-Data', 'XPG Spectrix D60G RGB 16gbx2'],
        ['G.Skill TRIDENT Z NEO 32gbx2', 4, 'G.Skill', 'TRIDENT Z NEO 32gbx2'],
        // бренд, которого нет в списке, и его соседи
        ['ExeGate NextPro+ KC2000TP512 M.2 512gb', 9, 'ExeGate', 'NextPro+ KC2000TP512 M.2 512gb'],
        ['MONTECH FIGHTER 500', 6, 'MONTECH', 'FIGHTER 500'],
        ['Xilence Performance A+ LiQuRizer LQ240.W.ARGB', 7, 'Xilence', 'Performance A+ LiQuRizer LQ240.W.ARGB'],
        ['GiNZZU B185 White', 6, 'GiNZZU', 'B185 White'],
        ['Accord K-16', 6, 'Accord', 'K-16'],
        // ASUS не должен путаться с ASRock, наоборот
        ['ASUS PRIME A320M-K', 2, 'ASUS', 'PRIME A320M-K'],
        ['AFOX GTX 750', CAT_VIDEO, 'AFOX', 'GTX 750'],
        ['Noctua NH-U9DX i4', 7, 'Noctua', 'NH-U9DX i4'],
        // префикс Intel/AMD в начале (стиль новых компонентов)
        ['AMD Ryzen 5 5600 3.5GHz 32MB AM4', CAT_CPU, 'AMD', 'Ryzen 5 5600 3.5GHz 32MB AM4'],
        ['Intel Core i5-12400F 2.5GHz 18MB LGA1700', CAT_CPU, 'Intel', 'Core i5-12400F 2.5GHz 18MB LGA1700'],
        // неизвестный бренд: производитель NULL, модель - всё название
        ['Фигня Какая-то 3000', 2, null, 'Фигня Какая-то 3000'],
        // двойные пробелы в данных не должны ломать разбор
        ['Corsair iCUE H150i  RGB PRO XT', 7, 'Corsair', 'iCUE H150i RGB PRO XT'],
        // карта без AIB-бренда
        ['AMD Radeon RX 580', CAT_VIDEO, 'AMD', 'Radeon RX 580'],
    ];

    $failed = 0;
    foreach ($cases as [$name, $cat, $wantBrand, $wantModel]) {
        $got = parseComponent($name, $cat);
        if ($got['manufacturer'] === $wantBrand && $got['model'] === $wantModel) {
            echo "  ok   " . pad($name, 52) . " → " . pad((string) $got['manufacturer'], 14) . " / " . $got['model'] . "\n";
            continue;
        }
        $failed++;
        echo "  FAIL " . pad($name, 52) . "\n";
        echo "       ждали:  " . pad((string) $wantBrand, 14) . " / " . $wantModel . "\n";
        echo "       получили: " . pad((string) $got['manufacturer'], 14) . " / " . $got['model'] . "\n";
    }

    $total = count($cases);
    echo "\n=== Итог selftest: " . ($total - $failed) . "/" . $total . " ===\n";
    if ($failed > 0) {
        echo "Словарь или правила разбора надо править, БД не трогать.\n";
    }

    return $failed;
}

// Тесты парсера не ходят в базу - подключаем connect.php после них.
if ($selftest) {
    echo "=== Selftest: разбор названий (без БД) ===\n\n";
    exit(runSelftest() > 0 ? 1 : 0);
}

require_once __DIR__ . '/../modules/connect.php';

if ($stage !== 1) {
    echo "Эшелон $stage в этом подэтапе не реализован.\n";
    echo "Сейчас доступен только --stage=1 (manufacturer + model).\n";
    echo "Эшелон 2 (specs) требует заполнения capacity_gb, ram_type,\n";
    echo "frequency_mhz и interface - у старых компонентов они пусты.\n";
    exit(1);
}

$mysql = connect();

echo "=== Эшелон 1: manufacturer + model ===\n";
echo ($dryRun ? "Режим: dry-run, база не меняется" : "Режим: запись в базу") . "\n";
echo "Словарь брендов: " . count(BRAND_DICTIONARY) . " начертаний\n\n";

$stmt = db_prepare($mysql, "SELECT component_id, category_id, component_name, manufacturer, model
                            FROM components
                            ORDER BY category_id, component_id", '');
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Считаем план до записи - так видно, что именно изменится
$plan = [];
foreach ($all as $row) {
    $hasMfr = $row['manufacturer'] !== null && $row['manufacturer'] !== '';
    $hasModel = $row['model'] !== null && $row['model'] !== '';
    if ($hasMfr && $hasModel) {
        continue; // заполнено - не трогаем (новые 50 компонентов и правки руками)
    }
    $parsed = parseComponent((string) $row['component_name'], (int) $row['category_id']);
    $newMfr = $hasMfr ? $row['manufacturer'] : $parsed['manufacturer'];
    $newModel = $hasModel ? $row['model'] : $parsed['model'];
    if ($newMfr === $row['manufacturer'] && $newModel === $row['model']) {
        continue; // нечего менять
    }
    $plan[] = [
        'id' => (int) $row['component_id'],
        'cat' => (int) $row['category_id'],
        'name' => (string) $row['component_name'],
        'old_mfr' => $row['manufacturer'],
        'old_model' => $row['model'],
        'new_mfr' => $newMfr,
        'new_model' => $newModel,
    ];
}

$skipped = count($all) - count($plan);

$catNames = [
    1 => 'Процессор', 2 => 'Материнская плата', 3 => 'Видеокарта', 4 => 'Оперативная память',
    5 => 'Блок питания', 6 => 'Корпус', 7 => 'Кулер', 8 => 'Жёсткий диск',
    9 => 'SSD', 10 => 'Оптический привод',
];

foreach ($plan as $p) {
    printf(
        "  %4d  cat %-2d %s%s → %s / %s\n",
        $p['id'],
        $p['cat'],
        pad($catNames[$p['cat']] ?? '?', 19),
        pad($p['name'], 46),
        pad((string) ($p['new_mfr'] ?? '—'), 14),
        (string) ($p['new_model'] ?? '—')
    );
}

// Сводка по категориям и список неопознанных
$byCat = [];
$unknown = [];
foreach ($plan as $p) {
    $c = $p['cat'];
    if (!isset($byCat[$c])) {
        $byCat[$c] = ['total' => 0, 'mfr' => 0, 'null_model' => 0];
    }
    $byCat[$c]['total']++;
    if ($p['new_mfr'] !== null && $p['new_mfr'] !== '') {
        $byCat[$c]['mfr']++;
    }
    if ($p['new_model'] === null || $p['new_model'] === '') {
        $byCat[$c]['null_model']++;
    }
    if ($p['new_mfr'] === null || $p['new_mfr'] === '') {
        $unknown[] = $p;
    }
}

echo "\n=== Сводка по категориям ===\n";
// Выравнивание через pad(), а не printf: printf считает байты,
// а в названиях категорий кириллица и колонки разъезжаются.
echo "  " . pad('cat', 4) . pad('категория', 22) . pad('к записи', 11) . "с mfr\n";
foreach ($byCat as $c => $s) {
    echo '  ' . pad((string) $c, 4) . pad($catNames[$c] ?? '?', 22) . pad((string) $s['total'], 11) . $s['mfr'] . "\n";
}

if ($unknown !== []) {
    echo "\n=== Производитель не распознан (" . count($unknown) . ") ===\n";
    foreach ($unknown as $p) {
        echo "  " . $p['id'] . "  " . $p['name'] . "\n";
    }
} else {
    echo "\nПроизводитель не распознан ни у одного компонента.\n";
}

$nullModel = 0;
foreach ($plan as $p) {
    if ($p['new_model'] === null || $p['new_model'] === '') {
        $nullModel++;
    }
}
echo "Пустая модель: $nullModel\n";
echo "Уже заполнено, пропущено: $skipped\n";
echo "К записи: " . count($plan) . "\n";

if ($dryRun) {
    echo "\nDry-run: база не менялась. Уберите --dry-run, чтобы применить.\n";
    $mysql->close();
    exit(0);
}

// ── Запись ─────────────────────────────────────────────────────────────
$mysql->begin_transaction();
$updated = 0;
$errors = 0;
try {
    foreach ($plan as $p) {
        // SET собирается из тех полей, которые действительно меняются
        $sets = [];
        $params = [];
        if ($p['new_mfr'] !== $p['old_mfr']) {
            $sets[] = 'manufacturer = ?';
            $params[] = $p['new_mfr'];
        }
        if ($p['new_model'] !== $p['old_model']) {
            $sets[] = '`model` = ?';
            $params[] = $p['new_model'];
        }
        if ($sets === []) {
            continue;
        }
        // Типы считаем после добавления id: сначала идут строковые
        // значения полей, последним - целочисленный component_id.
        $params[] = $p['id'];
        $types = str_repeat('s', count($params) - 1) . 'i';
        $sql = 'UPDATE components SET ' . implode(', ', $sets) . ' WHERE component_id = ?';
        $upd = db_prepare($mysql, $sql, $types, ...$params);
        $upd->execute();
        $updated += $upd->affected_rows;
        if ($upd->affected_rows === 0) {
            echo "  без изменений: {$p['id']} {$p['name']}\n";
        }
    }
    $mysql->commit();
} catch (Throwable $e) {
    $mysql->rollback();
    $errors++;
    echo "\nОТКАТ ТРАНЗАКЦИИ: " . $e->getMessage() . "\n";
}

$mysql->close();

echo "\n=== Итог ===\n";
echo "Обновлено строк: $updated\n";
echo "Ошибок: $errors\n";
exit($errors > 0 ? 1 : 0);