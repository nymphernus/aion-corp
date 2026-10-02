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
    '3a' => ['specs' => 's', 'description' => 's', 'ram_type' => 's', 'form_factor' => 's'],
    '3b' => ['specs' => 's', 'description' => 's', 'frequency_mhz' => 'i'],
    '3c' => ['specs' => 's', 'description' => 's', 'rpm' => 'i'],
];

/**
 * Поля, которые дополняются, а не пишутся заново: уже заполненный
 * JSON расширяется новыми ключами, а не затирается. Для остальных
 * действует правило «непустое не трогай».
 */
const STAGE_MERGE_FIELDS = [
    '3a' => ['specs'],
    '3b' => ['specs'],
    '3c' => ['specs'],
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
 * Эшелон 3a: полные данные по процессорам.
 *
 * Данные по каждой модели из документации производителя: ядра, потоки,
 * базовая и турбо-частота, кэш, наличие встроенной графики и её
 * название. Ключ - название без суффиксов F/K/KF, они на характеристики
 * не влияют, но влияют на графику: F и KF идут без встроенной.
 *
 * boost_ghz = null там, где турбо нет вовсе (Celeron и Athlon без
 * индекса X), lit - встроенная графика, igpu - её название (null, если
 * модель графику имеет, но точное название я не готов назвать).
 *
 * У Athlon X4 950 встроенной графики нет вовсе - это урезанная версия
 * без неё, в отличие от Athlon 3000G.
 */
const CPU_CORES = [
    // Intel, начальный уровень
    'Celeron G5905' => ['cores' => 2, 'threads' => 2, 'base_ghz' => 2.9, 'boost_ghz' => null, 'cache_mb' => 3, 'lit' => true, 'igpu' => 'Intel HD Graphics 600'],
    'Celeron G6900' => ['cores' => 2, 'threads' => 2, 'base_ghz' => 3.4, 'boost_ghz' => null, 'cache_mb' => 4, 'lit' => true, 'igpu' => 'Intel UHD Graphics 710'],
    'Pentium Gold G6405' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 4.1, 'boost_ghz' => null, 'cache_mb' => 8, 'lit' => true, 'igpu' => 'Intel UHD Graphics 610'],
    'Pentium Gold G7400' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 3.7, 'boost_ghz' => null, 'cache_mb' => 8, 'lit' => true, 'igpu' => 'Intel UHD Graphics 710'],
    // Intel, 9-е поколение (LGA1200)
    'i3-10100' => ['cores' => 4, 'threads' => 8, 'base_ghz' => 3.1, 'boost_ghz' => 3.9, 'cache_mb' => 6, 'lit' => true, 'igpu' => 'Intel UHD Graphics 630'],
    'i5-10400' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.9, 'boost_ghz' => 4.3, 'cache_mb' => 12, 'lit' => true, 'igpu' => 'Intel UHD Graphics 630'],
    'i7-10700' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 2.9, 'boost_ghz' => 4.8, 'cache_mb' => 16, 'lit' => true, 'igpu' => 'Intel UHD Graphics 630'],
    'i9-10900' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.3, 'boost_ghz' => 5.0, 'cache_mb' => 25, 'lit' => true, 'igpu' => 'Intel UHD Graphics 630'],
    // Intel, 10-е поколение (LGA1200)
    'i5-10600' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.9, 'boost_ghz' => 4.8, 'cache_mb' => 12, 'lit' => true, 'igpu' => 'Intel UHD Graphics 630'],
    'i5-11400' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.6, 'boost_ghz' => 4.4, 'cache_mb' => 12, 'lit' => true, 'igpu' => 'Intel UHD Graphics 750'],
    'i5-11600' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.8, 'boost_ghz' => 4.9, 'cache_mb' => 12, 'lit' => true, 'igpu' => 'Intel UHD Graphics 750'],
    // Intel, 11-е поколение (LGA1700)
    'i3-12100' => ['cores' => 4, 'threads' => 8, 'base_ghz' => 3.3, 'boost_ghz' => 4.4, 'cache_mb' => 8, 'lit' => true, 'igpu' => 'Intel UHD Graphics 750'],
    'i5-12400' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.5, 'boost_ghz' => 4.4, 'cache_mb' => 18, 'lit' => true, 'igpu' => 'Intel UHD Graphics 730'],
    'i5-13400' => ['cores' => 10, 'threads' => 16, 'base_ghz' => 2.5, 'boost_ghz' => 4.6, 'cache_mb' => 18, 'lit' => true, 'igpu' => 'Intel UHD Graphics 770'],
    'i7-11700' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 2.5, 'boost_ghz' => 4.9, 'cache_mb' => 25, 'lit' => true, 'igpu' => 'Intel UHD Graphics 750'],
    'i9-11900' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 2.5, 'boost_ghz' => 5.2, 'cache_mb' => 36, 'lit' => true, 'igpu' => 'Intel UHD Graphics 750'],
    // Intel, 12-е поколение (LGA1700)
    'i7-12700' => ['cores' => 20, 'threads' => 28, 'base_ghz' => 2.1, 'boost_ghz' => 4.9, 'cache_mb' => 33, 'lit' => false, 'igpu' => null],
    'i9-12900' => ['cores' => 16, 'threads' => 24, 'base_ghz' => 2.5, 'boost_ghz' => 5.2, 'cache_mb' => 30, 'lit' => false, 'igpu' => null],
    // AMD, Ryzen (AM4)
    'Ryzen 5 5600' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.5, 'boost_ghz' => 4.4, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 5 7600' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.8, 'boost_ghz' => 5.1, 'cache_mb' => 38, 'lit' => true, 'igpu' => 'AMD Radeon Graphics'],
    'Ryzen 7 5700X' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.8, 'boost_ghz' => 4.6, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 3 PRO 1200' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 3.8, 'boost_ghz' => 4.0, 'cache_mb' => 8, 'lit' => true, 'igpu' => null],
    'Ryzen 3 PRO 2100GE' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 3.5, 'boost_ghz' => 4.0, 'cache_mb' => 8, 'lit' => true, 'igpu' => null],
    'Ryzen 5 3600' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.6, 'boost_ghz' => 4.2, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 5 5600G' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.9, 'boost_ghz' => 4.4, 'cache_mb' => 32, 'lit' => true, 'igpu' => 'Radeon Vega 8'],
    'Ryzen 7 3700X' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.6, 'boost_ghz' => 4.4, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 7 3800X' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.9, 'boost_ghz' => 4.4, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 7 5800X' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.8, 'boost_ghz' => 4.9, 'cache_mb' => 32, 'lit' => false, 'igpu' => null],
    'Ryzen 9 5900X' => ['cores' => 12, 'threads' => 24, 'base_ghz' => 3.7, 'boost_ghz' => 4.8, 'cache_mb' => 64, 'lit' => false, 'igpu' => null],
    'Ryzen 9 5950X' => ['cores' => 16, 'threads' => 32, 'base_ghz' => 3.4, 'boost_ghz' => 4.9, 'cache_mb' => 64, 'lit' => false, 'igpu' => null],
    // AMD, APU и Athlon (AM4)
    'A8-9600' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 3.5, 'boost_ghz' => 4.0, 'cache_mb' => 2, 'lit' => true, 'igpu' => 'Radeon R7 Graphics'],
    'A6-9500E' => ['cores' => 2, 'threads' => 2, 'base_ghz' => 3.5, 'boost_ghz' => 4.0, 'cache_mb' => 2, 'lit' => true, 'igpu' => 'Radeon R5 Graphics'],
    'Athlon X4 950' => ['cores' => 4, 'threads' => 4, 'base_ghz' => 3.8, 'boost_ghz' => null, 'cache_mb' => 2, 'lit' => false, 'igpu' => null],
    'Athlon 3000G' => ['cores' => 2, 'threads' => 4, 'base_ghz' => 3.5, 'boost_ghz' => null, 'cache_mb' => 4, 'lit' => true, 'igpu' => 'Radeon Vega 2'],
];

/**
 * Эшелон 3a: материнские платы.
 *
 * Ключ - модель из столбца model (или всё название, если модель не
 * выделена). Значения: чипсет, тип памяти, форм-фактор по букве M в
 * названии, число слотов DDR и M.2 там, где оно известно точно.
 *
 * ram_slots и m2_slots заполнены выборочно: у многих плат число
 * слотов отличается от максимума чипсета, и выдавать максимум за
 * характеристику модели - значит соврать. Там, где не уверен, ключ
 * просто не пишется, и в отчёте это видно.
 *
 * ram_type: если DDR4 или DDR5 написано в названии - берётся оттуда,
 * иначе от чипсета (600-е и 690-е Intel - DDR5, остальные DDR4).
 */
const MB_TABLE = [
    'H410M-HVS R2.0' => ['chipset' => 'H410', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2, 'm2_slots' => 1],
    'H470M-HVS' => ['chipset' => 'H470', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2, 'm2_slots' => 1],
    'H510M-HDV' => ['chipset' => 'H510', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2, 'm2_slots' => 1],
    'H570M Pro4' => ['chipset' => 'H570', 'ram' => 'DDR4', 'ff' => 'Micro-ATX'],
    'Z590M Phantom Gaming 4' => ['chipset' => 'Z590', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 4],
    'Z590 PG Velocita' => ['chipset' => 'Z590', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'B560M Gaming HD' => ['chipset' => 'B560', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'Z590 UD AC' => ['chipset' => 'Z590', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'Z590 AORUS ULTRA' => ['chipset' => 'Z590', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'H610M-HDV/M.2' => ['chipset' => 'H610', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2, 'm2_slots' => 1],
    'B660M Pro RS' => ['chipset' => 'B660', 'ram' => 'DDR5', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'Z690 Phantom Gaming 4' => ['chipset' => 'Z690', 'ram' => 'DDR5', 'ff' => 'ATX', 'ram_slots' => 4],
    'Z690 Extreme' => ['chipset' => 'Z690', 'ram' => 'DDR5', 'ff' => 'ATX', 'ram_slots' => 4],
    'H610M H DDR4' => ['chipset' => 'H610', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2, 'm2_slots' => 1],
    'Z690 Gaming X DDR4' => ['chipset' => 'Z690', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'MPG Z690 EDGE WIFI DDR4' => ['chipset' => 'Z690', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'A320M-DVS R4.0' => ['chipset' => 'A320', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'PRIME A320M-K' => ['chipset' => 'A320', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'B450M-A PRO MAX' => ['chipset' => 'B450', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 4],
    'A520M-HVS' => ['chipset' => 'A520', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'B450 AORUS M' => ['chipset' => 'B450', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 4],
    'A520M Pro4' => ['chipset' => 'A520', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 2],
    'B550M AORUS ELITE' => ['chipset' => 'B550', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 4],
    'Z570 Gaming X' => ['chipset' => 'Z570', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'X570M Pro4' => ['chipset' => 'X570', 'ram' => 'DDR4', 'ff' => 'Micro-ATX', 'ram_slots' => 4],
    'X570S AERO G' => ['chipset' => 'X570S', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
    'X550 AORUS XTREME' => ['chipset' => 'Z550', 'ram' => 'DDR4', 'ff' => 'ATX', 'ram_slots' => 4],
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
        // Эшелон 2c пишет только ядра и потоки: остальные поля таблицы
        // добавляет эшелон 3a дополнением JSON.
        $out['specs'] = json_encode([
            'cores' => CPU_CORES[$key]['cores'],
            'threads' => CPU_CORES[$key]['threads'],
        ], JSON_UNESCAPED_UNICODE);
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

/**
 * Эшелон 3b: видеокарты.
 *
 * Ключ - серия в том написании, которое встречается в названиях базы,
 * совпадение ищется по самому длинному ключу, поэтому «RTX 3060 Ti»
 * выигрывает у «RTX 3060». Значения: кодовое имя кристалла и
 * рекомендуемая мощность блока питания из рекомендаций производителя.
 *
 * length_mm сюда не попадает осознанно: длина корпуса зависит от
 * конкретной модели партнёра (Palit Dual OC, Zotac AMP, KFA2 SG), а не
 * от серии, и по названию её не восстановить.
 *
 * У Radeon 550 LP кодовое имя не указано: это переименованная карта
 * предыдущего поколения, и какая именно - по названию не читается.
 */
const GPU_TABLE = [
    'GeForce 210' => ['chip' => 'GT218', 'psu' => 300],
    'GTX 210' => ['chip' => 'GT218', 'psu' => 300],
    'GT 1030' => ['chip' => 'GP108', 'psu' => 300],
    'GT 730' => ['chip' => 'GK208', 'psu' => 300],
    'GTX 750' => ['chip' => 'GK104', 'psu' => 400],
    'Radeon R7 240' => ['chip' => 'Turks', 'psu' => 400],
    'Radeon 550' => ['chip' => null, 'psu' => 400],
    'RX 550' => ['chip' => 'Lexa', 'psu' => 400],
    'RX 6500 XT' => ['chip' => 'Navi 23', 'psu' => 400],
    'RX 6600' => ['chip' => 'Navi 23', 'psu' => 500],
    'RX 6700 XT' => ['chip' => 'Navi 22', 'psu' => 650],
    'RX 6800 XT' => ['chip' => 'Navi 21', 'psu' => 700],
    'GTX 1050 Ti' => ['chip' => 'GP107', 'psu' => 400],
    'GTX 1050' => ['chip' => 'GP107', 'psu' => 400],
    'GTX 1650' => ['chip' => 'TU117', 'psu' => 400],
    'GTX 1660' => ['chip' => 'TU116', 'psu' => 450],
    'RTX 2060' => ['chip' => 'TU106', 'psu' => 500],
    'RTX 3050' => ['chip' => 'GA106', 'psu' => 450],
    'RTX 3060 Ti' => ['chip' => 'GA104', 'psu' => 600],
    'RTX 3060' => ['chip' => 'GA106', 'psu' => 550],
    'RTX 3070' => ['chip' => 'GA104', 'psu' => 650],
    'RTX 3080 Ti' => ['chip' => 'GA102', 'psu' => 750],
    'RTX 3080' => ['chip' => 'GA102', 'psu' => 750],
    'RTX 3090 Ti' => ['chip' => 'GA102', 'psu' => 850],
    'RTX 3090' => ['chip' => 'GA102', 'psu' => 800],
];

/**
 * Эшелон 3b: частота модулей памяти.
 *
 * В 15 старых комплектах в названии нет ни «MHz», ни «DDR4-3200» -
 * проверено запросом, ноль совпадений. Частота по линейке не
 * восстанавливается однозначно: те же Vengeance LPX и FURY Beast
 * выпускались на 2400, 3200, 3600 и 4000.
 *
 * Проставлена догадка 3200 МГц с разрешения пользователя: комплекты
 * на 2019-2021 год, а 3200 - самая массовая частота того времени.
 * Догадка помечается в отчёте отдельным списком, как ram_type в
 * эшелоне 2a. Тайминги CL16 и напряжение 1,35 В - штатный профиль
 * для DDR4-3200, то есть тоже следуют из этой догадки.
 */
const RAM_FREQUENCY_ASSUMED = 3200;
const RAM_TIMINGS_ASSUMED = 'CL16';
const RAM_VOLTAGE_ASSUMED = 1.35;

/**
 * Эшелон 3c: накопители.
 *
 * Ключ - фрагмент названия, по которому опознаётся модель. Значения:
 * скорости чтения и записи в МБ/с, тип NAND, ресурс в ТБ, а для
 * жёстких дисков - обороты и кэш.
 *
 * Заполнено только то, в чём нет сомнения. Отсутствующие ключи - это
 * не забывчивость: в таблице есть модели с намеренно пустыми
 * значениями, и причина перечислена в поле skipped. Список
 * неуверенных моделей печатается в отчёте dry-run отдельным разделом,
 * чтобы починить их можно было точечно, а не пересматривая все 15.
 *
 * Что осталось пустым и почему:
 *   - WD Blue M.2 - под этим названием и SATA SA510 (560 МБ/с), и
 *     NVMe SN580 (4150 МБ/с), по названию не различить;
 *   - Kingston A400 - тип NAND зависит от партии, у части A400 QLC;
 *   - Apacer AST280 и Patriot Burst Elite - точные скорости у разных
 *     артикулов отличаются, а в названии артикула нет;
 *   - ExeGate NextPro KC2000TP - то же;
 *   - GIGABYTE NVMe SSD - в названии нет модели вовсе.
 */
const SSD_TABLE = [
    '970 EVO Plus' => ['read' => 3500, 'write' => 3300, 'nand' => 'TLC', 'tbw' => 600],
    '980 PRO' => ['read' => 5100, 'write' => 5000, 'nand' => 'TLC', 'tbw' => 600],
    '980' => ['read' => 3500, 'write' => 3000, 'nand' => 'TLC', 'tbw' => 300],
    'SU650' => ['read' => 560, 'write' => 480, 'nand' => 'TLC', 'tbw' => 60],
    'SX6000 Pro' => ['read' => 3500, 'write' => 3000, 'nand' => 'TLC', 'tbw' => 300],
    'A400' => ['read' => 500, 'write' => 200, 'nand' => null, 'tbw' => 60, 'skipped' => 'тип NAND: у части партий A400 QLC'],
    'NV1' => ['read' => 3100, 'write' => 2100, 'nand' => 'TLC', 'tbw' => 160],
    'KC2000TP512' => [],
    'KC2000TP480' => [],
    'AST280' => [],
    'Burst Elite' => [],
];

const HDD_TABLE = [
    // Western Digital Blue, 3.5", SATA. Обороты 5400 у всей линейки.
    // Кэш 64 МБ у 500 ГБ и 1 ТБ; у 2 ТБ он больше, но точной цифры
    // подтвердить не берусь, поэтому оставляю пустым.
    '500gb' => ['rpm' => 5400, 'cache_mb' => 64],
    '1tb' => ['rpm' => 5400, 'cache_mb' => 64],
    '2tb' => ['rpm' => 5400, 'cache_mb' => null, 'skipped' => 'кэш у 2 ТБ: точный объём подтвердить не удалось'],
];

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
        case '3a':
            return [CAT_CPU, 2]; // процессоры и материнские платы
        case '3b':
            return [CAT_VIDEO, CAT_MEMORY]; // видеокарты и оперативная память
        case '3c':
            return [CAT_SSD, 8, 10]; // накопители и оптические приводы
        default:
            return [];
    }
}

/** Ищет модель процессора в таблице по названию целиком. */
function findCpuModel(string $name): ?string
{
    $best = null;
    $bestLen = 0;
    foreach (CPU_CORES as $candidate => $data) {
        if (strlen($candidate) > $bestLen && stripos($name, $candidate) !== false) {
            $best = $candidate;
            $bestLen = strlen($candidate);
        }
    }
    return $best;
}

/** Человеческое название сокета по его id. */
function socketName($socketId): string
{
    return [1 => 'LGA1200', 2 => 'LGA1700', 3 => 'AM4'][(int) $socketId] ?? '';
}

/** Слово «ядер» в нужном роде: 2 ядра, 5 ядер. */
function plural(int $n, string $one, string $few, string $many): string
{
    $mod100 = $n % 100;
    $mod10 = $n % 10;
    if ($mod100 >= 11 && $mod100 <= 14) {
        return $many;
    }
    if ($mod10 === 1) {
        return $one;
    }
    if ($mod10 >= 2 && $mod10 <= 4) {
        return $few;
    }
    return $many;
}

/**
 * Эшелон 3a: процессоры.
 *
 * Дополняет JSON из эшелона 2c (cores, threads) базовой и турбо
 * частотой, кэшем и встроенной графикой, плюс описание.
 * Суффикс F и KF означают «без встроенной графики», поэтому они
 * переопределяют lit из таблицы.
 */
function extractStage3aCpu(array $row): array
{
    $name = (string) $row['component_name'];
    $model = findCpuModel($name);
    if ($model === null) {
        return ['_warning' => 'модели процессора нет в таблице'];
    }
    $data = CPU_CORES[$model];

    // Суффиксы F, KF и K у настольных процессоров Intel означают «без
    // встроенной графики»: K - разблокированный множитель, и графики
    // у него тоже нет. Проверка идёт по имени без пробелов и знаков,
    // поэтому Athlon X4 950 (ATHLONX4950) под F или K не попадает.
    $plain = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
    $noGpu = str_contains($plain, 'F') || str_contains($plain, 'K');
    $lit = $data['lit'] && !$noGpu;

    $specs = [
        'base_ghz' => $data['base_ghz'],
        'cache_mb' => $data['cache_mb'],
        'lit' => $lit,
    ];
    if ($data['boost_ghz'] !== null) {
        $specs['boost_ghz'] = $data['boost_ghz'];
    }

    $cores = $data['cores'];
    $threads = $data['threads'];
    $socket = socketName($row['socket_id']);

    // Название для описания берём из component_name, а не из ключа
    // таблицы: ключ без суффиксов, и «i7-12700F» превратился бы в
    // «i7-12700» - то есть в другой процессор. У новых компонентов
    // название длиннее модели («AMD Ryzen 5 5600 3.5GHz 32MB AM4»),
    // поэтому берём ровно то, что лежит в component_name, оно для
    // старых компонентов совпадает с моделью.
    $cpuName = trim($name);
    if (stripos($cpuName, 'i3-') === 0 || stripos($cpuName, 'i5-') === 0
        || stripos($cpuName, 'i7-') === 0 || stripos($cpuName, 'i9-') === 0) {
        $title = 'Intel Core ' . $cpuName;
    } elseif ($cpuName[0] === 'R' || $cpuName[0] === 'A') {
        $title = 'AMD ' . $cpuName;
    } else {
        $title = 'Intel ' . $cpuName;
    }

    $freq = 'базовая частота ' . rtrim(rtrim(number_format($data['base_ghz'], 1, ',', ''), '0'), ',') . ' ГГц';
    if ($data['boost_ghz'] !== null) {
        $freq .= ' (турбо до ' . rtrim(rtrim(number_format($data['boost_ghz'], 1, ',', ''), '0'), ',') . ' ГГц)';
    }

    $text = $title . ' — ' . $cores . ' ' . plural($cores, 'ядро', 'ядра', 'ядер')
        . ', ' . $threads . ' ' . plural($threads, 'поток', 'потока', 'потоков')
        . ', ' . $freq . ', кэш ' . $data['cache_mb'] . ' МБ, сокет ' . $socket . '.';
    if (!$lit) {
        $text .= ' Без встроенной графики.';
    } elseif ($data['igpu'] !== null) {
        $text .= ' Встроенная графика: ' . $data['igpu'] . '.';
    } else {
        $text .= ' Со встроенной графикой.';
    }

    return ['specs' => $specs, 'description' => $text];
}

/**
 * Сокет по названию чипсета.
 *
 * Не берём socket_id из базы: в исходных данных у двух плат он неверен
 * (Gigabyte Z570 Gaming X и X550 AORUS XTREME помечены как AM4, хотя
 * это Intel LGA1200), и описание уехало бы в «для процессоров AMD».
 * Чипсет в названии модели однозначен, socket_id там верный.
 */
function socketByChipset(string $chipset): array
{
    // AMD, только AM4
    foreach (['A320', 'A520', 'B450', 'B550', 'X570'] as $amd) {
        if (str_starts_with($chipset, $amd)) {
            return ['name' => 'AM4', 'id' => 3];
        }
    }
    // Intel, только LGA1700 (600-е серии и Z690)
    foreach (['H610', 'B660', 'Z690'] as $lga1700) {
        if (str_starts_with($chipset, $lga1700)) {
            return ['name' => 'LGA1700', 'id' => 2];
        }
    }
    // остальное Intel 400-х и 500-х - LGA1200
    return ['name' => 'LGA1200', 'id' => 1];
}

/**
 * Эшелон 3a: материнские платы.
 *
 * Ключ таблицы - модель, но у части плат (Gigabyte Z590 UD AC и
 * подобные) в model нет префикса производителя, поэтому при
 * отсутствии ключа пробуем название целиком.
 */
function extractStage3aMb(array $row): array
{
    $name = (string) $row['component_name'];
    $model = (string) ($row['model'] ?? '');
    $entry = MB_TABLE[$model] ?? MB_TABLE[$name] ?? null;
    if ($entry === null) {
        return ['_warning' => 'модели платы нет в таблице'];
    }

    // DDR в названии важнее чипсета: у Z690 и B660 есть обе версии
    $ram = preg_match('/DDR\s*([45])/i', $name, $m) ? 'DDR' . $m[1] : $entry['ram'];

    $specs = ['chipset' => $entry['chipset']];
    if (isset($entry['ram_slots'])) {
        $specs['ram_slots'] = $entry['ram_slots'];
    }
    if (isset($entry['m2_slots'])) {
        $specs['m2_slots'] = $entry['m2_slots'];
    }

    $socket = socketByChipset($entry['chipset']);
    $vendor = $socket['name'] === 'AM4'
        ? 'процессоров AMD сокета AM4'
        : ($socket['name'] === 'LGA1200'
            ? 'процессоров Intel 10-го поколения'
            : 'процессоров Intel 11-го и 12-го поколения');

    $slots = isset($entry['ram_slots'])
        ? $entry['ram_slots'] . ' ' . plural($entry['ram_slots'], 'слот', 'слота', 'слотов') . ' ' . $ram
        : 'слоты памяти ' . $ram;

    $text = ((string) ($row['manufacturer'] ?? '')) . ' ' . $model
        . ' — материнская плата для ' . $vendor . ', сокет ' . $socket['name'] . '.'
        . ' Чипсет ' . $entry['chipset'] . ', ' . $slots . ', форм-фактор ' . $entry['ff'] . '.';

    $result = [
        'specs' => $specs,
        'description' => $text,
        'ram_type' => $ram,
        'form_factor' => $entry['ff'],
    ];

    // socket_id в базе не трогаем, но если он расходится с чипсетом -
    // сообщаем: такая плата никогда не подойдёт к процессору по сокету
    if ((int) $row['socket_id'] !== $socket['id']) {
        $result['_warning'] = 'socket_id в базе = ' . socketName($row['socket_id'])
            . ', по чипсету ' . $entry['chipset'] . ' ожидается ' . $socket['name']
            . ' - поле не менялось, плата не подойдёт к процессору по сокету';
    }

    return $result;
}

/** Эшелон 3a: обе категории сразу. */
function extractStage3a(array $row): array
{
    $cat = (int) $row['category_id'];
    if ($cat === CAT_CPU) {
        return extractStage3aCpu($row);
    }
    if ($cat === 2) {
        return extractStage3aMb($row);
    }
    return [];
}

/** Серия видеокарты по самому длинному совпавшему ключу. */
function matchGpuTable(string $name): ?array
{
    static $keys = null;
    if ($keys === null) {
        $keys = array_keys(GPU_TABLE);
        usort($keys, static function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });
    }
    $lower = mb_strtolower($name);
    foreach ($keys as $key) {
        if (str_contains($lower, mb_strtolower($key))) {
            return [$key, GPU_TABLE[$key]];
        }
    }
    return null;
}

/**
 * Эшелон 3b: видеокарты.
 *
 * Дополняет specs из эшелона 2d (уже заполнены capacity_gb и
 * memory_type, они остаются в JSON) кодовым именем кристалла и
 * рекомендуемой мощностью блока питания, плюс описание.
 */
function extractStage3bGpu(array $row): array
{
    $name = (string) $row['component_name'];
    $hit = matchGpuTable($name);
    if ($hit === null) {
        return ['_warning' => 'серия видеокарты не найдена'];
    }

    $specs = ['psu_req_w' => $hit[1]['psu']];
    if ($hit[1]['chip'] !== null) {
        $specs['chip'] = $hit[1]['chip'];
    }

    $capacity = $row['capacity_gb'] ?? null;
    $memory = $row['memory_type'] ?? null;
    $title = trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name));

    $text = $title . ' — видеокарта';
    if ($capacity !== null && $capacity !== '') {
        $text .= ' с ' . $capacity . ' ГБ' . ($memory ? ' ' . $memory : '');
    }
    if ($hit[1]['chip'] !== null) {
        $text .= ', кристалл ' . $hit[1]['chip'];
    }
    $text .= '. Рекомендуемый блок питания от ' . $hit[1]['psu'] . ' Вт.';

    return ['specs' => $specs, 'description' => $text];
}

/**
 * Эшелон 3b: комплекты оперативной памяти.
 *
 * Число модулей берётся из названия («4gbx4» - четыре планки по 4 ГБ),
 * частота - догадка RAM_FREQUENCY_ASSUMED, тайминги и напряжение -
 * штатный профиль для этой частоты. Всё догадочное попадает в отчёт
 * разделом «Проставлено по догадке».
 */
function extractStage3bRam(array $row): array
{
    $name = (string) $row['component_name'];

    if (!preg_match('/(\d+)\s*gb\s*x\s*(\d+)/i', $name, $m)) {
        return ['_warning' => 'число модулей не читается из названия'];
    }
    $modules = (int) $m[2];

    $specs = [
        'modules' => $modules,
        'timings' => RAM_TIMINGS_ASSUMED,
        'voltage' => RAM_VOLTAGE_ASSUMED,
    ];

    $capacity = $row['capacity_gb'] ?? null;
    $ramType = $row['ram_type'] ?? null;
    $title = trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name));

    $text = $title . ' — комплект оперативной памяти';
    if ($capacity !== null && $capacity !== '') {
        $text .= ' ' . $capacity . ' ГБ из ' . $modules . ' '
            . plural($modules, 'модуля', 'модулей', 'модулей');
    }
    if ($ramType) {
        $text .= ', ' . $ramType;
    }
    $text .= ' на ' . RAM_FREQUENCY_ASSUMED . ' МГц с таймингами '
        . RAM_TIMINGS_ASSUMED . '.';

    return [
        'specs' => $specs,
        'description' => $text,
        'frequency_mhz' => RAM_FREQUENCY_ASSUMED,
        '_assumed' => ['frequency_mhz=' . RAM_FREQUENCY_ASSUMED
            . ' (в названии нет MHz, частота догадана по линейке комплекта);'
            . ' тайминги и напряжение взяты как профиль для этой частоты'],
    ];
}

/** Эшелон 3b: обе категории сразу. */
function extractStage3b(array $row): array
{
    $cat = (int) $row['category_id'];
    if ($cat === CAT_VIDEO) {
        return extractStage3bGpu($row);
    }
    if ($cat === CAT_MEMORY) {
        return extractStage3bRam($row);
    }
    return [];
}

/**
 * Эшелон 3c: SSD.
 *
 * Скорости и ресурс берутся из SSD_TABLE, объём и интерфейс уже
 * заполнены эшелоном 2b и лежат в отдельных колонках. Если модель в
 * таблице есть, но с пустыми значениями, причина попадает в отчёт -
 * так видно, что пропуск осознанный.
 */
function extractStage3cSsd(array $row): array
{
    $name = (string) $row['component_name'];

    $hit = null;
    foreach (SSD_TABLE as $key => $data) {
        if (stripos($name, $key) !== false) {
            $hit = [$key, $data];
            break; // порядок таблицы: «980 PRO» раньше «980»
        }
    }

    $specs = [];
    $notes = [];
    if ($hit !== null) {
        $data = $hit[1];
        if (isset($data['read'])) {
            $specs['read_mbs'] = $data['read'];
            $specs['write_mbs'] = $data['write'];
        }
        if (isset($data['nand']) && $data['nand'] !== null) {
            $specs['nand'] = $data['nand'];
        }
        if (isset($data['tbw']) && $data['tbw'] !== null) {
            $specs['tbw'] = $data['tbw'];
        }
        if (isset($data['skipped'])) {
            $notes[] = $data['skipped'];
        } elseif ($specs === []) {
            $notes[] = 'скорости по этой модели не подтверждены, оставлены NULL';
        }
    } else {
        $notes[] = 'модель не опознана, только описание';
    }

    $title = trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name));
    $capacity = $row['capacity_gb'] ?? null;
    $interface = $row['interface'] ?? null;

    $text = $title . ' — твердотельный накопитель';
    if ($capacity !== null && $capacity !== '') {
        $text .= ' на ' . $capacity . ' ГБ';
    }
    if ($interface) {
        $text .= ', интерфейс ' . $interface;
    }
    $text .= '.';
    if ($specs !== []) {
        $text .= ' Скорость чтения до ' . $specs['read_mbs'] . ' МБ/с, записи до '
            . $specs['write_mbs'] . ' МБ/с';
        // тип NAND и ресурс есть не у всех: у A400 тип оставлен пустым
        if (isset($specs['nand'])) {
            $text .= ', память ' . $specs['nand'];
        }
        if (isset($specs['tbw'])) {
            $text .= ', ресурс записи ' . $specs['tbw'] . ' ТБ';
        }
        $text .= '.';
    }

    $result = ['description' => $text];
    if ($specs !== []) {
        $result['specs'] = $specs;
    }
    if ($notes !== []) {
        $result['_skipped'] = $notes;
    }
    return $result;
}

/**
 * Эшелон 3c: жёсткий диск.
 *
 * Обороты пишутся в отдельную колонку rpm - она есть и отображается
 * в админ-форме, в JSON их не дублируем. В specs идёт кэш.
 */
function extractStage3cHdd(array $row): array
{
    $name = (string) $row['component_name'];

    $hit = null;
    foreach (HDD_TABLE as $key => $data) {
        if (stripos($name, $key) !== false) {
            $hit = [$key, $data];
        }
    }
    if ($hit === null) {
        return ['description' => trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name))
            . ' — жёсткий диск.', '_skipped' => ['модель не опознана']];
    }

    $data = $hit[1];
    $specs = [];
    if ($data['cache_mb'] !== null) {
        $specs['cache_mb'] = $data['cache_mb'];
    }

    $title = trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name));
    $text = $title . ' — жёсткий диск для настольного компьютера.';
    if ($data['rpm'] !== null) {
        $text .= ' Скорость вращения ' . $data['rpm'] . ' об/мин, SATA.';
    }
    if ($data['cache_mb'] !== null) {
        $text .= ' Кэш ' . $data['cache_mb'] . ' МБ.';
    }

    $result = ['description' => $text, 'rpm' => $data['rpm']];
    if ($specs !== []) {
        $result['specs'] = $specs;
    }
    if (isset($data['skipped'])) {
        $result['_skipped'] = [$data['skipped']];
    }
    return $result;
}

/**
 * Эшелон 3c: оптический привод.
 *
 * Только описание: для приводов из названия читается тип (DVD-RW) и
 * модель, а скорости и наличие M-ARC из названия не восстановить.
 */
function extractStage3cDvd(array $row): array
{
    $name = (string) $row['component_name'];
    $title = trim(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? $name));
    $kind = stripos($name, 'DVD') !== false ? 'DVD и CD' : 'CD';
    $rw = (stripos($name, 'R') !== false && stripos($name, 'RW') !== false)
        ? 'для чтения и записи' : 'для чтения';

    return [
        'description' => $title . ' — оптический привод ' . $rw . ' ' . $kind . '.',
    ];
}

/** Эшелон 3c: три категории сразу. */
function extractStage3c(array $row): array
{
    switch ((int) $row['category_id']) {
        case CAT_SSD:
            return extractStage3cSsd($row);
        case 8:
            return extractStage3cHdd($row);
        case 10:
            return extractStage3cDvd($row);
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
        case '3a':
            $candidates = extractStage3a($row);
            break;
        case '3b':
            $candidates = extractStage3b($row);
            break;
        case '3c':
            $candidates = extractStage3c($row);
            break;
        default:
            return ['set' => [], 'notes' => [], 'warning' => null];
    }

    $set = [];
    $merge = STAGE_MERGE_FIELDS[$stage] ?? [];
    foreach (STAGE_FIELDS[$stage] as $column => $type) {
        if (!array_key_exists($column, $candidates) || $candidates[$column] === null) {
            continue; // не нашли - оставляем NULL, ничего не выдумываем
        }
        $current = $row[$column] ?? null;

        if (in_array($column, $merge, true)) {
            // Дополняем существующий JSON, а не заменяем: cores и threads
            // из эшелона 2c должны уцелеть
            if ($current === null || $current === '') {
                $set[$column] = json_encode($candidates[$column], JSON_UNESCAPED_UNICODE);
            } elseif (is_array($candidates[$column])) {
                $existing = json_decode((string) $current, true);
                if (!is_array($existing)) {
                    $existing = [];
                }
                // порядок не меняется: слева старые ключи, справа новые
                $merged = json_encode(
                    $existing + $candidates[$column],
                    JSON_UNESCAPED_UNICODE
                );
                // MySQL переставляет ключи в JSON при хранении, поэтому
                // сравнивать надо по содержимому, а не по строкам
                if (json_decode((string) $current, true) === $existing + $candidates[$column]) {
                    continue; // дополнять нечего
                }
                $set[$column] = $merged;
            }
            continue;
        }

        if ($current !== null && $current !== '') {
            continue; // правило 1: непустое поле не трогаем
        }
        $set[$column] = $candidates[$column];
    }

    if (isset($candidates['_assumed'])) {
        $notes = $candidates['_assumed'];
    }
    if (isset($candidates['_skipped'])) {
        foreach ($candidates['_skipped'] as $reason) {
            $notes[] = 'не заполнено: ' . $reason;
        }
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

    echo "\n--- Эшелон 3a: процессоры и платы ---\n";
    $stage3aCases = [
        // CPU: суффикс F снимает встроенную графику, но ядра те же
        ['i5-10400F', ['component_name' => 'i5-10400F', 'category_id' => 1, 'socket_id' => 1, 'specs' => '{"cores":6,"threads":12}']],
        ['i5-10400', ['component_name' => 'i5-10400', 'category_id' => 1, 'socket_id' => 1, 'specs' => '{"cores":6,"threads":12}']],
        ['i7-12700F', ['component_name' => 'i7-12700F', 'category_id' => 1, 'socket_id' => 2, 'specs' => '{"cores":20,"threads":28}']],
        // K тоже без встроенной графики, как F
        ['i5-11600K', ['component_name' => 'i5-11600K', 'category_id' => 1, 'socket_id' => 1, 'specs' => '{"cores":6,"threads":12}']],
        // Athlon X4 950 содержит X, но не F и не K - графика должна остаться
        ['Athlon X4 950', ['component_name' => 'Athlon X4 950', 'category_id' => 1, 'socket_id' => 3, 'specs' => '{"cores":4,"threads":4}']],
        ['Ryzen 5 5600G', ['component_name' => 'Ryzen 5 5600G', 'category_id' => 1, 'socket_id' => 3, 'specs' => '{"cores":6,"threads":12}']],
        ['Athlon X4 950', ['component_name' => 'Athlon X4 950', 'category_id' => 1, 'socket_id' => 3, 'specs' => '{"cores":4,"threads":4}']],
        ['Athlon X4 950', ['component_name' => 'Athlon X4 950', 'category_id' => 1, 'socket_id' => 3, 'specs' => '{"cores":4,"threads":4}']],
        // MB: DDR в названии важнее чипсета
        ['ASRock H470M-HVS', ['component_name' => 'ASRock H470M-HVS', 'category_id' => 2, 'socket_id' => 1, 'model' => 'H470M-HVS', 'manufacturer' => 'ASRock']],
        ['Gigabyte Z690 Gaming X DDR4', ['component_name' => 'Gigabyte Z690 Gaming X DDR4', 'category_id' => 2, 'socket_id' => 2, 'model' => 'Z690 Gaming X DDR4', 'manufacturer' => 'Gigabyte']],
        ['ASRock Z690 Extreme', ['component_name' => 'ASRock Z690 Extreme', 'category_id' => 2, 'socket_id' => 2, 'model' => 'Z690 Extreme', 'manufacturer' => 'ASRock']],
        ['MSI MPG Z690 EDGE WIFI DDR4', ['component_name' => 'MSI MPG Z690 EDGE WIFI DDR4', 'category_id' => 2, 'socket_id' => 2, 'model' => 'MPG Z690 EDGE WIFI DDR4', 'manufacturer' => 'MSI']],
    ];
    foreach ($stage3aCases as [$name, $row]) {
        $got = extractStage3a($row);
        $specs = isset($got['specs'])
            ? (is_string($got['specs']) ? $got['specs'] : json_encode($got['specs'], JSON_UNESCAPED_UNICODE))
            : null;
        $totalSpec++;
        $merged = collectUpdates('3a', $row + ['specs' => $row['specs'] ?? null, 'ram_type' => null, 'form_factor' => null, 'description' => null]);
        $shown = ['specs' => $specs] + array_diff_key($got, ['specs' => 1]);
        if (isset($merged['set']) && $merged['set'] !== []) {
            $ok = true;
            // cores и threads из 2c обязаны уцелеть после дополнения
            if ($specs !== null && $row['category_id'] === 1) {
                $after = json_decode((string) $merged['set']['specs'], true);
                $before = json_decode((string) $row['specs'], true);
                foreach ($before as $k => $v) {
                    if (!array_key_exists($k, $after) || $after[$k] !== $v) {
                        $ok = false;
                    }
                }
            }
            if ($ok) {
                echo "  ok   " . pad($name, 42) . ' ' . mb_substr(json_encode($shown, JSON_UNESCAPED_UNICODE), 0, 150) . "\n";
                continue;
            }
            echo "  FAIL " . pad($name, 42) . " дополнение потеряло ключи из 2c\n";
            echo '       ' . json_encode($merged['set'], JSON_UNESCAPED_UNICODE) . "\n";
            $failed++;
            continue;
        }
        $failed++;
        echo "  FAIL " . pad($name, 42) . " пустое обновление\n";
        echo '       ' . json_encode($shown, JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n--- Эшелон 3b: видеокарты и память ---\n";
    // У видеокарт и памяти specs до эшелона 3b были пустыми: объём и тип
    // памяти лежат в отдельных колонках, а не в JSON. Поэтому в
    // фикстурах specs = null, а отдельный кейс проверяет слияние.
    $stage3bCases = [
        // RTX 3060 Ti не должен проиграть RTX 3060
        ['Palit GeForce RTX 3060 Ti DUAL OC V1 (LHR)', ['component_name' => 'Palit GeForce RTX 3060 Ti DUAL OC V1 (LHR)', 'category_id' => 3, 'manufacturer' => 'Palit', 'model' => 'GeForce RTX 3060 Ti DUAL OC V1 (LHR)', 'capacity_gb' => 8, 'memory_type' => 'GDDR6', 'specs' => null]],
        ['GIGABYTE GeForce RTX 3060 EAGLE OC (LHR)', ['component_name' => 'GIGABYTE GeForce RTX 3060 EAGLE OC (LHR)', 'category_id' => 3, 'manufacturer' => 'Gigabyte', 'model' => 'GeForce RTX 3060 EAGLE OC (LHR)', 'capacity_gb' => 12, 'memory_type' => 'GDDR6', 'specs' => null]],
        ['MSI GeForce 210', ['component_name' => 'MSI GeForce 210', 'category_id' => 3, 'manufacturer' => 'MSI', 'model' => 'GeForce 210', 'capacity_gb' => 1, 'memory_type' => 'GDDR3', 'specs' => null]],
        // у Radeon 550 LP кода кристалла нет - в JSON только рекомендация
        ['PowerColor AMD Radeon 550 LP', ['component_name' => 'PowerColor AMD Radeon 550 LP', 'category_id' => 3, 'manufacturer' => 'PowerColor', 'model' => 'AMD Radeon 550 LP', 'capacity_gb' => 2, 'memory_type' => 'GDDR5', 'specs' => null]],
        ['Kingston FURY Beast Black 4gbx4', ['component_name' => 'Kingston FURY Beast Black 4gbx4', 'category_id' => 4, 'manufacturer' => 'Kingston', 'model' => 'FURY Beast Black 4gbx4', 'capacity_gb' => 16, 'ram_type' => 'DDR4', 'specs' => null]],
        // слияние: чужой ключ в specs должен уцелеть
        ['A-Data XPG GAMMIX D20 8gbx2 + чужой ключ', ['component_name' => 'A-Data XPG GAMMIX D20 8gbx2', 'category_id' => 4, 'manufacturer' => 'A-Data', 'model' => 'XPG GAMMIX D20 8gbx2', 'capacity_gb' => 16, 'ram_type' => 'DDR4', 'specs' => '{"rgb":true}']],
    ];
    foreach ($stage3bCases as [$name, $row]) {
        $row += ['description' => null, 'frequency_mhz' => null, 'form_factor' => null];
        $got = extractStage3b($row);
        $totalSpec++;
        $merged = collectUpdates('3b', $row);
        if (isset($merged['set']) && $merged['set'] !== []) {
            // для кейса на слияние проверяем, что чужой ключ уцелел
            $ok = true;
            if (($row['specs'] ?? null) !== null) {
                $after = json_decode((string) $merged['set']['specs'], true);
                $before = json_decode((string) $row['specs'], true);
                foreach ($before as $k => $v) {
                    if (!array_key_exists($k, $after) || $after[$k] !== $v) {
                        $ok = false;
                    }
                }
            }
            if ($ok) {
                echo "  ok   " . pad($name, 42) . ' ' . mb_substr(json_encode(array_diff_key($merged['set'], ['description' => 1]), JSON_UNESCAPED_UNICODE), 0, 110) . "\n";
                continue;
            }
            $failed++;
            echo "  FAIL " . pad($name, 42) . " слияние потеряло ключи\n";
            echo '       ' . json_encode($merged['set'], JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
        $failed++;
        echo "  FAIL " . pad($name, 42) . " пустое обновление\n";
        echo '       ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n--- Эшелон 3c: накопители и приводы ---\n";
    $stage3cCases = [
        // 980 PRO должен опознаться раньше 980
        ['Samsung 980 PRO M.2 1tb', ['component_name' => 'Samsung 980 PRO M.2 1tb', 'category_id' => 9, 'manufacturer' => 'Samsung', 'model' => '980 PRO M.2 1tb', 'capacity_gb' => 1000, 'interface' => 'M.2', 'specs' => null]],
        ['Samsung 980 M.2 500gb', ['component_name' => 'Samsung 980 M.2 500gb', 'category_id' => 9, 'manufacturer' => 'Samsung', 'model' => '980 M.2 500gb', 'capacity_gb' => 500, 'interface' => 'M.2', 'specs' => null]],
        // модель есть в таблице, но значения пустые - это осознанный пропуск
        ['ExeGate NextPro KC2000TP480 M.2 480gb', ['component_name' => 'ExeGate NextPro KC2000TP480 M.2 480gb', 'category_id' => 9, 'manufacturer' => 'ExeGate', 'model' => 'NextPro KC2000TP480 M.2 480gb', 'capacity_gb' => 480, 'interface' => 'M.2', 'specs' => null]],
        ['Western Digital Blue 1tb', ['component_name' => 'Western Digital Blue 1tb', 'category_id' => 8, 'manufacturer' => 'Western Digital', 'model' => 'Blue 1tb', 'capacity_gb' => 1000, 'specs' => null]],
        ['DVD-RW LG GH24NSD5', ['component_name' => 'DVD-RW LG GH24NSD5', 'category_id' => 10, 'manufacturer' => 'LG', 'model' => 'GH24NSD5', 'specs' => null]],
    ];
    foreach ($stage3cCases as [$name, $row]) {
        $row += ['description' => null, 'rpm' => null];
        $got = extractStage3c($row);
        $totalSpec++;
        $merged = collectUpdates('3c', $row);
        $wantSpecs = !isset($got['specs']) ? 'без specs' : json_encode($got['specs'], JSON_UNESCAPED_UNICODE);
        $ok = isset($merged['set']) && !empty($merged['set']);
        if ($ok) {
            echo "  ok   " . pad($name, 42) . ' ' . pad((string) $wantSpecs, 58) . ' rpm=' . var_export($merged['set']['rpm'] ?? null, true) . "\n";
            continue;
        }
        $failed++;
        echo "  FAIL " . pad($name, 42) . " пустое обновление\n";
        echo '       ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
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
    '3a' => 'CPU и материнские платы: specs, description, ram_type, form_factor',
    '3b' => 'GPU и RAM: specs, description, frequency_mhz',
    '3c' => 'SSD, HDD и DVD: specs, description, rpm',
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
                                   socket_id, tdp, video_core, description, ram_type,
                                   capacity_gb, frequency_mhz, `interface`,
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

foreach ($plan as $planRow) {
    $pairs = [];
    foreach ($planRow['set'] as $column => $value) {
        $text = is_string($value) ? $value : (string) $value;
        // Описание и JSON длинные, в одну строку не влезают - обрезаем
        if (mb_strlen($text) > 64) {
            $text = mb_substr($text, 0, 61) . '...';
        }
        $pairs[] = $column . '=' . $text;
    }
    echo '  ' . pad((string) $planRow['id'], 6)
        . 'cat ' . pad((string) $planRow['cat'], 4)
        . pad($planRow['name'], 46)
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