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
$stage = '1';
foreach ($argv as $arg) {
    if (preg_match('/^--stage=([0-9]+[a-d]?)$/', $arg, $m)) {
        $stage = $m[1];
    }
}

const CAT_CPU = 1;
const CAT_VIDEO = 3;
const CAT_MEMORY = 4;
const CAT_SSD = 9;

/**
 * Граница старых компонентов. Новые 50 из seed_components.php заняли
 * id 193..242, между 188 и 192 ничего нет, поэтому «меньше 190» и
 * «меньше 193» одно и то же.
 *
 * Фильтр по id нужен вместе с правилом «не перезаписывать»: у новых
 * компонентов часть полей законно NULL (ram_type у видеокарт,
 * capacity_gb у материнских плат), и проверка «поле пустое» для них
 * сработала бы, то есть правка старого парсера могла бы испортить
 * новые строки.
 */
const LEGACY_MAX_ID = 190;

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
 * Колонки, которые заполняет каждый этап, и их типы для bind_param.
 * Ключ этапа => [колонка => тип]. Всё остальное скрипт не трогает.
 */
const STAGE_FIELDS = [
    '1' => ['manufacturer' => 's', 'model' => 's'],
    '2a' => ['ram_type' => 's', 'capacity_gb' => 'i', 'frequency_mhz' => 'i'],
    '2b' => ['capacity_gb' => 'i', 'interface' => 's', 'form_factor' => 's'],
    '2c' => ['specs' => 's', 'frequency_mhz' => 'i'],
    '2d' => ['capacity_gb' => 'i', 'memory_type' => 's'],
];

/**
 * Эшелон 2a: предполагаемый тип памяти для модулей без маркера.
 *
 * В 15 старых модулях в названии нет ни «DDR», ни «MHz» - проверено
 * запросом, 0 совпадений. По линейке модели тип не восстанавливается
 * однозначно: FURY Beast, XPG SPECTRIX и TRIDENT Z выпускались и в
 * DDR4, и в DDR5, а год выпуска в названии отсутствует. Решение
 * принято пользователем: проставить DDR4 всем, потому что все 15
 * линеек выпущены до появления DDR5 в рознице (2019-2021).
 * Это догадка, а не разбор: она помечена в плане как assumed.
 * Если появится модуль DDR5-вида, поле придётся поправить руками.
 */
const RAM_TYPE_ASSUMED = 'DDR4';

/** Границы правдоподобия объёма памяти, ГБ. */
const RAM_CAPACITY_MIN = 2;
const RAM_CAPACITY_MAX = 256;

/**
 * Эшелон 2c: ядра и потоки по конкретным моделям.
 *
 * Таблица, а не правило по линейке: правило «i7 -> 8/16» ошибается на
 * i7-12700F (20/28), «i9 -> 8-16/16-32» на i9-12900 (16/24),
 * «Pentium Gold -> 2/2» на G6405 и G7400 (4/4), «Ryzen 3 -> 4/8» на
 * Ryzen 3 PRO (4/4, у PRO отключён SMT). Ключ - название без
 * суффиксов F/K/KF, они на число ядер не влияют.
 */
const CPU_CORES = [
    // Intel: 9-е поколение
    'i3-10100' => [4, 8], 'i5-10400' => [6, 12], 'i7-10700' => [8, 16], 'i9-10900' => [8, 16],
    // Intel: 10-е поколение
    'i5-10600' => [6, 12], 'i3-12100' => [4, 8], 'i5-12400' => [6, 12],
    'i7-12700' => [20, 28], 'i9-12900' => [16, 24],
    // Intel: 11-е поколение
    'i5-11400' => [6, 12], 'i5-11600' => [6, 12],
    'i7-11700' => [8, 16], 'i9-11900' => [8, 16],
    // Intel: 12-14-е поколение, нужны для названий нового вида
    'i3-13100' => [4, 8], 'i5-13400' => [10, 16], 'i7-13700' => [16, 24], 'i9-13900' => [24, 32],
    // Intel: начальный уровень
    'Celeron G5905' => [2, 2], 'Celeron G6900' => [2, 2],
    'Pentium Gold G6405' => [4, 4], 'Pentium Gold G7400' => [4, 4],
    // AMD: Ryzen
    'Ryzen 3 PRO 1200' => [4, 4], 'Ryzen 3 PRO 2100GE' => [4, 4],
    'Ryzen 5 3600' => [6, 12], 'Ryzen 5 5600G' => [6, 12], 'Ryzen 5 5600' => [6, 12],
    'Ryzen 5 5600X' => [6, 12], 'Ryzen 5 7600' => [6, 12],
    'Ryzen 7 3700X' => [8, 16], 'Ryzen 7 3800X' => [8, 16], 'Ryzen 7 5800X' => [8, 16],
    'Ryzen 7 5700X' => [8, 16], 'Ryzen 7 7700X' => [8, 16],
    'Ryzen 9 5900X' => [12, 24], 'Ryzen 9 5950X' => [16, 32], 'Ryzen 9 7900X' => [12, 24],
    // AMD: APU и Athlon
    'A8-9600' => [4, 4], 'A6-9500E' => [2, 2],
    'Athlon X4 950' => [4, 4], 'Athlon 3000G' => [2, 4],
];

/**
 * Эшелон 2d: объём видеопамяти и тип по серии.
 *
 * Ключ - самый длинный совпавший кусок названия, поэтому проверка идёт
 * по убыванию длины ключа: «RTX 3080 Ti» должен выиграть у «RTX 3080».
 * Второй элемент - объём в ГБ, третий - тип памяти.
 *
 * memory_type: у RTX 3080, 3080 Ti, 3090 и 3090 Ti память GDDR6X,
 * а не GDDR6, как получилось бы по правилу «все 30xx - GDDR6».
 * У карт, где тип зависит от конкретной ревизии (GT 730, GT 1030,
 * бывает и DDR3, и GDDR5), тип не пишется - вместо него NULL.
 *
 * Объём, явно написанный в названии («RTX 2060 D6 6G», «GT 1030 D4 2G»),
 * важнее словаря и читается первым.
 */
const GPU_SERIES = [
    'RTX 4090' => [24, 'GDDR6X'], 'RTX 4080' => [16, 'GDDR6X'],
    'RTX 4070 Ti' => [12, 'GDDR6X'], 'RTX 4070' => [12, 'GDDR6X'],
    'RTX 4060 Ti' => [8, 'GDDR6'], 'RTX 4060' => [8, 'GDDR6'],
    'RTX 3090 Ti' => [24, 'GDDR6X'], 'RTX 3090' => [24, 'GDDR6X'],
    'RTX 3080 Ti' => [12, 'GDDR6X'], 'RTX 3080' => [10, 'GDDR6X'],
    'RTX 3070 Ti' => [8, 'GDDR6'], 'RTX 3070' => [8, 'GDDR6'],
    'RTX 3060 Ti' => [8, 'GDDR6'], 'RTX 3060' => [12, 'GDDR6'],
    'RTX 3050' => [8, 'GDDR6'],
    'RTX 2080 Ti' => [11, 'GDDR6'], 'RTX 2080' => [8, 'GDDR6'],
    'RTX 2070' => [8, 'GDDR6'], 'RTX 2060' => [6, 'GDDR6'],
    'GTX 1660 Ti' => [6, 'GDDR5'], 'GTX 1660 SUPER' => [6, 'GDDR5'], 'GTX 1660' => [6, 'GDDR5'],
    'GTX 1650' => [4, 'GDDR5'], 'GTX 1050 Ti' => [4, 'GDDR5'], 'GTX 1050' => [2, 'GDDR5'],
    'GTX 750' => [2, 'GDDR5'], 'GTX 210' => [1, 'GDDR3'],
    // В названиях базы «GTX» пропущено: «MSI GeForce 210», поэтому
    // ключ дублируется без префикса
    'GeForce 210' => [1, 'GDDR3'],
    // У GT 730 и GT 1030 тип памяти зависит от ревизии (DDR3 или GDDR5),
    // в названии это не читается, поэтому объём пишем, тип - нет
    'GT 1030' => [2, null], 'GT 730' => [2, null],
    'RX 6900 XT' => [16, 'GDDR6'], 'RX 6800 XT' => [16, 'GDDR6'], 'RX 6700 XT' => [12, 'GDDR6'],
    'RX 6600' => [8, 'GDDR6'], 'RX 6500 XT' => [4, 'GDDR6'], 'RX 550' => [4, 'GDDR5'],
    'RX 7 370' => [8, 'GDDR6'], 'RX 590' => [8, 'GDDR6'],
    'Radeon 550' => [2, 'GDDR5'], 'Radeon R7 240' => [2, 'GDDR3'],
];

/** Объём из названия вида «... D6 6G», «... D4 2G» - цифра с G. */
function parseExplicitGpuCapacity(string $name): ?int
{
    if (preg_match('/(\d+)\s*G\b/i', $name, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** Серия видеокарты по самому длинному совпавшему ключу. */
function matchGpuSeries(string $name): ?array
{
    static $keys = null;
    if ($keys === null) {
        $keys = array_keys(GPU_SERIES);
        // длинные ключи первыми: «RTX 3080 Ti» должен выиграть у «RTX 3080»
        usort($keys, static function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });
    }
    $lower = mb_strtolower($name);
    foreach ($keys as $key) {
        if (str_contains($lower, mb_strtolower($key))) {
            return [$key, GPU_SERIES[$key]];
        }
    }
    return null;
}

/**
 * Эшелон 2a: оперативная память.
 * Формат объёма в данных - «4gbx2», то есть объём, потом количество
 * модулей. Варианта «2x8GB» в базе нет ни разу.
 */
function extractStage2a(string $name): array
{
    $out = [];

    if (preg_match('/(\d+)\s*gb\s*x\s*(\d+)/i', $name, $m)) {
        $gb = (int) $m[1] * (int) $m[2];
    } elseif (preg_match('/(\d+)\s*gb\b/i', $name, $m)) {
        $gb = (int) $m[1];
    } else {
        $gb = null;
    }

    // Объём вне правдоподобных границ не пишем: лучше NULL, чем мусор
    if ($gb !== null && ($gb < RAM_CAPACITY_MIN || $gb > RAM_CAPACITY_MAX)) {
        $out['_warning'] = "объём $gb ГБ вне диапазона " . RAM_CAPACITY_MIN . '-' . RAM_CAPACITY_MAX . ', оставлен NULL';
        $gb = null;
    }
    if ($gb !== null) {
        $out['capacity_gb'] = $gb;
    }

    // Частота: сначала «DDR4-3200», затем «3200MHz». В старых модулях
    // нет ни того, ни другого, поэтому поле остаётся пустым
    if (preg_match('/DDR\s*\d\s*-?\s*(\d{3,5})/i', $name, $m)) {
        $out['frequency_mhz'] = (int) $m[1];
    } elseif (preg_match('/(\d{3,5})\s*MHz/i', $name, $m)) {
        $out['frequency_mhz'] = (int) $m[1];
    }

    // Тип: из названия или по допущению RAM_TYPE_ASSUMED
    if (preg_match('/DDR\s*([0-9])/i', $name, $m)) {
        $out['ram_type'] = 'DDR' . $m[1];
    } else {
        $out['ram_type'] = RAM_TYPE_ASSUMED;
        $out['_assumed'][] = 'ram_type=' . RAM_TYPE_ASSUMED . ' (в названии нет DDR)';
    }

    return $out;
}

/** Эшелон 2b: SSD и накопители. */
function extractStage2b(string $name): array
{
    $out = [];

    if (preg_match('/(\d+)\s*tb\b/i', $name, $m)) {
        // десятичные терабайты, как у новых компонентов: 4TB -> 4000 ГБ
        $out['capacity_gb'] = (int) $m[1] * 1000;
    } elseif (preg_match('/(\d+)\s*gb\b/i', $name, $m)) {
        $out['capacity_gb'] = (int) $m[1];
    }

    if (preg_match('/NVMe/i', $name)) {
        $out['interface'] = 'M.2 NVMe';
    } elseif (preg_match('/M\.2/i', $name)) {
        $out['interface'] = 'M.2';
    } elseif (preg_match('/SATA/i', $name)) {
        $out['interface'] = 'SATA III';
    }

    // form_factor пишем «M.2», а не «M.2 2280»: у четырёх уже
    // заполненных накопителей стоит «M.2», а 2280 в названии нет
    if (preg_match('/M\.2/i', $name)) {
        $out['form_factor'] = 'M.2';
    } elseif (preg_match('/3\.5/i', $name)) {
        $out['form_factor'] = '3.5"';
    } elseif (preg_match('/2\.5/i', $name)) {
        $out['form_factor'] = '2.5"';
    }

    return $out;
}

/**
 * Эшелон 2c: процессоры - cores и threads по таблице моделей.
 *
 * Модель ищется как самое длинное вхождение ключа таблицы, а не
 * точное совпадение всей строки: у старых компонентов название bare
 * («i5-10400»), а у новых модель сидит в середине («AMD Ryzen 5 5600
 * 3.5GHz 32MB AM4»). Суффиксы F/K/KF на число ядер не влияют, поэтому
 * отдельная их обработка не нужна - ключ короче и найдётся сам.
 */
function extractStage2c(string $name): array
{
    $out = [];

    $key = null;
    $bestLen = 0;
    foreach (CPU_CORES as $candidate => $pair) {
        if (strlen($candidate) > $bestLen && stripos($name, $candidate) !== false) {
            $key = $candidate;
            $bestLen = strlen($candidate);
        }
    }

    if ($key !== null) {
        [$cores, $threads] = CPU_CORES[$key];
        $out['specs'] = json_encode(['cores' => $cores, 'threads' => $threads], JSON_UNESCAPED_UNICODE);
    } else {
        $out['_warning'] = 'модели нет в таблице cores/threads';
    }

    // Базовая частота есть только в названиях новых компонентов
    // («AMD Ryzen 5 5600 3.5GHz 32MB AM4»), у старых её нет
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*GHz/i', $name, $m)) {
        $out['frequency_mhz'] = (int) round((float) str_replace(',', '.', $m[1]) * 1000);
    }

    return $out;
}

/** Эшелон 2d: видеокарты - объём и тип памяти по серии. */
function extractStage2d(string $name): array
{
    $out = [];

    $hit = matchGpuSeries($name);
    $explicit = parseExplicitGpuCapacity($name);

    if ($explicit !== null) {
        // «RTX 2060 D6 6G» - объём написан, он важнее словаря
        $out['capacity_gb'] = $explicit;
    } elseif ($hit !== null) {
        $out['capacity_gb'] = $hit[1][0];
    } else {
        $out['_warning'] = 'серия не распознана';
    }

    if ($hit !== null && $hit[1][1] !== null) {
        $out['memory_type'] = $hit[1][1];
    }

    return $out;
}

/** Категории, которые заполняет этап. Для эшелона 1 - все. */
function stageCategories(string $stage): array
{
    switch ($stage) {
        case '2a':
            return [CAT_MEMORY];
        case '2b':
            return [CAT_SSD, 8]; // SSD и жёсткие диски
        case '2c':
            return [CAT_CPU];
        case '2d':
            return [CAT_VIDEO];
        default:
            return [];
    }
}

/**
 * Собирает поля к записи для одного компонента.
 *
 * Возвращает ['set' => [колонка => значение], 'notes' => [...],
 * 'warning' => строка|null]. Пустой 'set' означает, что писать нечего.
 * Непустые поля не трогаются (правило 1), а NULL-поля, которые
 * разобрать не удалось, остаются NULL - ничего не выдумывается.
 */
function collectUpdates(string $stage, array $row): array
{
    $name = (string) $row['component_name'];
    $cat = (int) $row['category_id'];

    if (!in_array($cat, stageCategories($stage), true) && $stage !== '1') {
        return ['set' => [], 'notes' => [], 'warning' => null];
    }

    $notes = [];
    $warning = null;

    switch ($stage) {
        case '1':
            $parsed = parseComponent($name, $cat);
            $candidates = [
                'manufacturer' => $parsed['manufacturer'],
                'model' => $parsed['model'],
            ];
            break;
        case '2a':
            $candidates = extractStage2a($name);
            break;
        case '2b':
            $candidates = extractStage2b($name);
            break;
        case '2c':
            $candidates = extractStage2c($name);
            break;
        case '2d':
            $candidates = extractStage2d($name);
            break;
        default:
            return ['set' => [], 'notes' => [], 'warning' => null];
    }

    $set = [];
    foreach (STAGE_FIELDS[$stage] as $column => $type) {
        if (!array_key_exists($column, $candidates) || $candidates[$column] === null) {
            continue; // не нашли - оставляем NULL, ничего не выдумываем
        }
        $current = $row[$column] ?? null;
        if ($current !== null && $current !== '') {
            continue; // правило 1: непустое поле не трогаем
        }
        $set[$column] = $candidates[$column];
    }

    if (isset($candidates['_assumed'])) {
        $notes = $candidates['_assumed'];
    }
    if (isset($candidates['_warning'])) {
        $warning = $candidates['_warning'];
    }

    return ['set' => $set, 'notes' => $notes, 'warning' => $warning];
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

    // ── Эшелон 2: разбор полей по категориям ───────────────────────────
    // Формат кейса: [название, функция-экстрактор, ожидаемые поля]
    $specCases = [
        // 2a RAM: объём «4gbx2» - это объём, потом количество модулей
        ['Patriot Signature Line 4gbx2', 'extractStage2a', ['capacity_gb' => 8, 'ram_type' => 'DDR4']],
        ['Goodram Iridium 4gbx2', 'extractStage2a', ['capacity_gb' => 8, 'ram_type' => 'DDR4']],
        ['Kingston FURY Beast Black 4gbx4', 'extractStage2a', ['capacity_gb' => 16, 'ram_type' => 'DDR4']],
        ['A-Data XPG Spectrix D60G RGB 16gbx2', 'extractStage2a', ['capacity_gb' => 32, 'ram_type' => 'DDR4']],
        ['G.Skill TRIDENT Z Neo 32gbx2', 'extractStage2a', ['capacity_gb' => 64, 'ram_type' => 'DDR4']],
        // явный DDR в названии читается, а не подставляется догадка
        ['Corsair Vengeance LPX 8gbx2 DDR4-3200', 'extractStage2a', ['capacity_gb' => 16, 'ram_type' => 'DDR4', 'frequency_mhz' => 3200]],
        ['Corsair Vengeance 32GB (2x16) DDR5-5600', 'extractStage2a', ['capacity_gb' => 32, 'ram_type' => 'DDR5']],
        // объём без количества модулей
        ['TeamGroup DDR4 8GB', 'extractStage2a', ['capacity_gb' => 8, 'ram_type' => 'DDR4']],
        // 2b SSD: терабайты десятичные, как у новых компонентов
        ['Samsung 970 EVO Plus M.2 500gb', 'extractStage2b', ['capacity_gb' => 500, 'interface' => 'M.2', 'form_factor' => 'M.2']],
        ['Western Digital Blue M.2 1tb', 'extractStage2b', ['capacity_gb' => 1000, 'interface' => 'M.2', 'form_factor' => 'M.2']],
        ['Western Digital Blue M.2 2tb', 'extractStage2b', ['capacity_gb' => 2000, 'interface' => 'M.2', 'form_factor' => 'M.2']],
        ['GIGABYTE NVMe SSD M.2 256gb', 'extractStage2b', ['capacity_gb' => 256, 'interface' => 'M.2 NVMe', 'form_factor' => 'M.2']],
        // без M.2 в названии интерфейс и форм-фактор остаются пустыми
        ['Patriot Burst Elite 480gb', 'extractStage2b', ['capacity_gb' => 480]],
        ['Samsung 870 EVO 1TB 2.5" SATA III', 'extractStage2b', ['capacity_gb' => 1000, 'interface' => 'SATA III', 'form_factor' => '2.5"']],
        // 2c CPU: таблица по модели, суффиксы F/K/KF отбрасываются
        ['i5-10400', 'extractStage2c', ['specs' => '{"cores":6,"threads":12}']],
        ['i5-10400F', 'extractStage2c', ['specs' => '{"cores":6,"threads":12}']],
        ['i5-10600KF', 'extractStage2c', ['specs' => '{"cores":6,"threads":12}']],
        ['i3-12100F', 'extractStage2c', ['specs' => '{"cores":4,"threads":8}']],
        // здесь правило по линейке «i7 -> 8/16» соврало бы
        ['i7-12700F', 'extractStage2c', ['specs' => '{"cores":20,"threads":28}']],
        ['i9-12900F', 'extractStage2c', ['specs' => '{"cores":16,"threads":24}']],
        // «Pentium Gold -> 2/2» соврало бы
        ['Pentium Gold G6405', 'extractStage2c', ['specs' => '{"cores":4,"threads":4}']],
        ['Celeron G5905', 'extractStage2c', ['specs' => '{"cores":2,"threads":2}']],
        // у Ryzen 3 PRO SMT отключён: 4/4, а не 4/8
        ['Ryzen 3 PRO 1200', 'extractStage2c', ['specs' => '{"cores":4,"threads":4}']],
        ['Ryzen 9 5950X', 'extractStage2c', ['specs' => '{"cores":16,"threads":32}']],
        ['Athlon 3000G', 'extractStage2c', ['specs' => '{"cores":2,"threads":4}']],
        ['A6-9500E', 'extractStage2c', ['specs' => '{"cores":2,"threads":2}']],
        // частота читается из названия нового вида
        ['AMD Ryzen 5 5600 3.5GHz 32MB AM4', 'extractStage2c', ['specs' => '{"cores":6,"threads":12}', 'frequency_mhz' => 3500]],
        // модели нет в таблице - cores остаются NULL, но это не провал
        ['i7-14700K', 'extractStage2c', []],
        // 2d GPU: объём из названия важнее словаря
        ['GIGABYTE GeForce RTX 2060 D6 6G (rev. 2.0)', 'extractStage2d', ['capacity_gb' => 6, 'memory_type' => 'GDDR6']],
        ['GIGABYTE GeForce GT 1030 Low Profile D4 2G', 'extractStage2d', ['capacity_gb' => 2]],
        // «RTX 3080 Ti» не должен проиграть «RTX 3080»
        ['KFA2 GeForce RTX 3080 Ti SG', 'extractStage2d', ['capacity_gb' => 12, 'memory_type' => 'GDDR6X']],
        ['GIGABYTE GeForce RTX 3080 GAMING OC', 'extractStage2d', ['capacity_gb' => 10, 'memory_type' => 'GDDR6X']],
        ['Palit GeForce RTX 3090 GamingPro', 'extractStage2d', ['capacity_gb' => 24, 'memory_type' => 'GDDR6X']],
        ['Palit GeForce RTX 3060 Ti DUAL OC V1 (LHR)', 'extractStage2d', ['capacity_gb' => 8, 'memory_type' => 'GDDR6']],
        ['GIGABYTE GeForce RTX 3060 EAGLE OC (LHR)', 'extractStage2d', ['capacity_gb' => 12, 'memory_type' => 'GDDR6']],
        ['Palit GeForce GTX 1660 SUPER STORMX', 'extractStage2d', ['capacity_gb' => 6, 'memory_type' => 'GDDR5']],
        ['ASUS GeForce GTX 1650 PHOENIX OC', 'extractStage2d', ['capacity_gb' => 4, 'memory_type' => 'GDDR5']],
        // AMD Radeon - серии в исходном словаре не было
        ['PowerColor Red Devil AMD Radeon RX 6800 XT', 'extractStage2d', ['capacity_gb' => 16, 'memory_type' => 'GDDR6']],
        ['ASRock AMD Radeon RX 6600 Challenger D', 'extractStage2d', ['capacity_gb' => 8, 'memory_type' => 'GDDR6']],
        ['PowerColor AMD Radeon R7 240', 'extractStage2d', ['capacity_gb' => 2, 'memory_type' => 'GDDR3']],
        ['MSI GeForce 210', 'extractStage2d', ['capacity_gb' => 1, 'memory_type' => 'GDDR3']],
        // GT 730 бывает и DDR3, и GDDR5 - объём пишем, тип оставляем пустым
        ['GIGABYTE GeForce GT 730', 'extractStage2d', ['capacity_gb' => 2]],
    ];

    echo "\n--- Эшелон 2: поля по категориям ---\n";
    $totalSpec = 0;
    foreach ($specCases as [$name, $fn, $want]) {
        $got = $fn($name);
        unset($got['_warning'], $got['_assumed']);
        // сверяем только ожидаемые ключи: экстрактор может отдать больше
        $ok = true;
        foreach ($want as $column => $value) {
            if (!isset($got[$column]) || (string) $got[$column] !== (string) $value) {
                $ok = false;
            }
        }
        if ($ok) {
            echo "  ok   " . pad($name, 46) . ' → ' . pad($fn, 17) . ' ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
            $totalSpec++;
            continue;
        }
        $failed++;
        echo "  FAIL " . pad($name, 46) . "\n";
        echo '       ждали:  ' . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
        echo '       получили: ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
        $totalSpec++;
    }

    $total = count($cases) + $totalSpec;
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

if (!isset(STAGE_FIELDS[$stage])) {
    echo "Неизвестный этап: --stage=$stage\n";
    echo 'Доступны этапы: ' . implode(', ', array_keys(STAGE_FIELDS)) . "\n";
    exit(1);
}

$stageTitles = [
    '1' => 'manufacturer + model',
    '2a' => 'RAM: ram_type, capacity_gb, frequency_mhz',
    '2b' => 'SSD и HDD: capacity_gb, interface, form_factor',
    '2c' => 'CPU: specs, frequency_mhz',
    '2d' => 'GPU: capacity_gb, memory_type',
];

$catNames = [
    1 => 'Процессор', 2 => 'Материнская плата', 3 => 'Видеокарта', 4 => 'Оперативная память',
    5 => 'Блок питания', 6 => 'Корпус', 7 => 'Кулер', 8 => 'Жёсткий диск',
    9 => 'SSD', 10 => 'Оптический привод',
];

$mysql = connect();

echo "=== Эшелон $stage: {$stageTitles[$stage]} ===\n";
echo ($dryRun ? 'Режим: dry-run, база не меняется' : 'Режим: запись в базу') . "\n";
echo 'Поля этапа: ' . implode(', ', array_keys(STAGE_FIELDS[$stage])) . "\n";
echo 'Категории: ' . (stageCategories($stage) === []
        ? 'все'
        : implode(', ', array_map(static function ($c) use ($catNames) {
            return $c . ' (' . ($catNames[$c] ?? '?') . ')';
        }, stageCategories($stage)))) . "\n";
if ($stage === '1') {
    echo 'Словарь брендов: ' . count(BRAND_DICTIONARY) . " начертаний\n";
}
if ($stage === '2a') {
    echo 'Догадка о типе памяти: ' . RAM_TYPE_ASSUMED . " (в названиях нет «DDR»)\n";
}
echo "\n";

// `interface` - зарезервированное слово MySQL, отсюда обратные кавычки
$stmt = db_prepare($mysql, "SELECT component_id, category_id, component_name, manufacturer, `model`,
                                   ram_type, capacity_gb, frequency_mhz, `interface`,
                                   form_factor, memory_type, specs
                            FROM components
                            ORDER BY category_id, component_id", '');
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// План считаем целиком до записи: видно и что изменится, и что нет
$plan = [];
$inScope = 0;
$nothingToDo = 0;
$skippedNew = 0;
$warnings = [];
$assumed = [];
foreach ($all as $row) {
    if ((int) $row['component_id'] >= LEGACY_MAX_ID) {
        $skippedNew++;
        continue; // новые 50 компонентов не трогаем никогда
    }
    $cat = (int) $row['category_id'];
    if ($stage !== '1' && !in_array($cat, stageCategories($stage), true)) {
        continue;
    }
    $inScope++;
    $result = collectUpdates($stage, $row);
    if ($result['set'] === []) {
        $nothingToDo++;
        if ($result['warning'] !== null) {
            $warnings[] = '  ' . $row['component_id'] . '  ' . $row['component_name'] . ' — ' . $result['warning'];
        }
        continue;
    }
    if ($result['warning'] !== null) {
        $warnings[] = '  ' . $row['component_id'] . '  ' . $row['component_name'] . ' — ' . $result['warning'];
    }
    foreach ($result['notes'] as $note) {
        $assumed[] = '  ' . $row['component_id'] . '  ' . $row['component_name'] . ' — ' . $note;
    }
    $plan[] = [
        'id' => (int) $row['component_id'],
        'cat' => $cat,
        'name' => (string) $row['component_name'],
        'set' => $result['set'],
    ];
}

foreach ($plan as $p) {
    $pairs = [];
    foreach ($p['set'] as $column => $value) {
        $pairs[] = $column . '=' . (is_string($value) ? $value : (string) $value);
    }
    echo '  ' . pad((string) $p['id'], 6)
        . 'cat ' . pad((string) $p['cat'], 4)
        . pad($p['name'], 46)
        . ' → ' . implode(', ', $pairs) . "\n";
}

echo "\n=== Сводка ===\n";
// Выравнивание через pad(), а не printf: printf считает байты,
// а в названиях колонок кириллица и разъезжается
echo '  ' . pad('в выборке этапа', 26) . $inScope . "\n";
echo '  ' . pad('к записи', 26) . count($plan) . "\n";
echo '  ' . pad('писать нечего', 26) . $nothingToDo . "\n";
echo '  ' . pad('пропущено как новые', 26) . $skippedNew . "\n";

$perColumn = [];
foreach ($plan as $p) {
    foreach ($p['set'] as $column => $value) {
        $perColumn[$column] = ($perColumn[$column] ?? 0) + 1;
    }
}
foreach (STAGE_FIELDS[$stage] as $column => $type) {
    $filled = $perColumn[$column] ?? 0;
    $pct = $inScope > 0 ? round(100 * $filled / $inScope) : 0;
    echo '  ' . pad($column, 26) . pad("$filled из $inScope", 14) . $pct . "%\n";
}

if ($assumed !== []) {
    echo "\n=== Проставлено по догадке (" . count($assumed) . ") ===\n";
    echo "Это не разбор названия. Значения помечены, чтобы можно было перепроверить.\n";
    foreach ($assumed as $line) {
        echo $line . "\n";
    }
}

if ($warnings !== []) {
    echo "\n=== Не удалось разобрать (" . count($warnings) . ") ===\n";
    foreach ($warnings as $line) {
        echo $line . "\n";
    }
}

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
        $sets = [];
        $params = [];
        $types = '';
        foreach ($p['set'] as $column => $value) {
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value;
            $types .= STAGE_FIELDS[$stage][$column];
        }
        // Последним идёт component_id, он целочисленный
        $params[] = $p['id'];
        $types .= 'i';
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