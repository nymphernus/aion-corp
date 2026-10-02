<?php
/**
 * Общие помощники по комплектующим: выборка, справочник сокетов, разбор
 * колонки specs в человеческий вид.
 *
 * Модуль нужен двум страницам. assembly.php показывает характеристики на
 * витрине, configurator.php сверяет сокет кулера с сокетом процессора.
 * Раньше карта сокетов была захардкожена в configurator.php и покрывала
 * только три сокета из семи, а в базе есть таблица sockets - теперь
 * источник один, и LGA1851 с AM5 тоже видны.
 */

if (!function_exists('socket_types')) {
    /**
     * Справочник сокетов из базы: [socket_id => socket_type].
     *
     * Загружается один раз на страницу, в базе семь строк.
     *
     * @return array<int, string>
     */
    function socket_types(mysqli $mysql): array
    {
        $stmt = db_prepare($mysql, "SELECT socket_id, socket_type FROM sockets ORDER BY socket_id", '');
        $stmt->execute();
        $result = $stmt->get_result();

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['socket_id']] = (string) $row['socket_type'];
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('component_by_id')) {
    /**
     * Полная строка компонента по его id, либо null.
     *
     * Берутся все колонки, а не только component_name: странице витрины
     * нужны specs, description, manufacturer и прочие, и любая новая
     * колонка после этого станет доступна без правки запроса.
     * Выборка по первичному ключу, так что SELECT * здесь дешёв.
     */
    function component_by_id(mysqli $mysql, $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $stmt = db_prepare($mysql, "SELECT * FROM components WHERE component_id = ?", 'i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $row : null;
    }
}

if (!function_exists('spec_value')) {
    /**
     * Приводит одно значение из specs или колонки к строке для показа.
     *
     * null, пустая строка, ноль и пустой массив превращаются в null - таких
     * характеристик просто нет, и выводить их не нужно. Массивы склеиваются
     * через точку, иначе потерялись бы mobo и sockets, у которых по
     * несколько значений. Булевы читаются как «Есть» и «Нет».
     */
    function spec_value($value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Есть' : 'Нет';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = spec_value($item);
                if ($text !== null) {
                    $parts[] = $text;
                }
            }
            return $parts ? implode(' · ', $parts) : null;
        }
        if (is_numeric($value)) {
            $float = (float) $value;
            if ($float === 0.0) {
                return null;
            }
            // дробные значения у напряжения и частоты терять нельзя,
            // целые удобнее с разделителем тысяч
            if ($float === floor($float)) {
                return number_format($float, 0, ',', ' ');
            }
            return rtrim(rtrim(number_format($float, 2, ',', ' '), '0'), ',');
        }

        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }
}

if (!function_exists('component_specs')) {
    /**
     * Характеристики компонента как упорядоченный список пар
     * «подпись => значение», готовый к выводу в dl.
     *
     * Порядок задан явно: сначала то, что лежит в отдельных колонках и
     * потому надёжно, потом то, что лежит в specs. Ключи specs в базе
     * английские и разные для каждой категории, поэтому набор подписей
     * задан таблицей по category_id, а не обходом всех ключей: иначе
     * посетитель увидел бы lit: false и e_core: true.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $socketTypes
     * @return array<int, array{0: string, 1: string}>
     */
    function component_specs(array $row, array $socketTypes = []): array
    {
        $category = (int) ($row['category_id'] ?? 0);

        $specs = json_decode((string) ($row['specs'] ?? ''), true);
        if (!is_array($specs)) {
            $specs = [];
        }

        // характеристики смотрим сначала в specs, потом в колонках:
        // колонка и ключ specs с одним именем не должны драться
        $values = $row;
        foreach ($specs as $key => $value) {
            $values[$key] = $value;
        }

        if (!empty($row['socket_id']) && isset($socketTypes[(int) $row['socket_id']])) {
            $values['socket_type'] = $socketTypes[(int) $row['socket_id']];
        }

        // частота процессора: в specs она двумя числами, база и буст, и показывать
        // их одной строкой понятнее, чем двумя. Буст есть не у всех
        // процессоров, поэтому строка собирается из того, что нашлось
        if (isset($values['base_ghz'])) {
            $ghz = spec_value($values['base_ghz']);
            $boost = spec_value($values['boost_ghz'] ?? null);
            if ($ghz !== null) {
                $values['frequency'] = $ghz . ($boost !== null ? ' / ' . $boost : '') . ' ГГц';
            }
            // колонка frequency_mhz при наличии specs не нужна: там та же
            // частота в другой единице, показывать обе бессмысленно
            unset($values['frequency_mhz']);
        }

        $rules = [
            1 => [
                ['socket_type', 'Сокет', null],
                ['tdp', 'TDP', ' Вт'],
                ['cores', 'Ядра', null],
                ['threads', 'Потоки', null],
                ['frequency', 'Частота', null],
                ['frequency_mhz', 'Частота', ' МГц'],
                ['cache_mb', 'Кэш', ' МБ'],
                ['e_core', 'Энергоядра', null],
                ['lit', 'Встроенная графика', null],
            ],
            2 => [
                ['socket_type', 'Сокет', null],
                ['chipset', 'Чипсет', null],
                ['form_factor', 'Форм-фактор', null],
                ['ram_type', 'Тип памяти', null],
                ['ram_slots', 'Слоты DIMM', null],
                ['m2_slots', 'Слоты M.2', null],
                ['sata_ports', 'Порты SATA', null],
                ['wifi', 'Wi-Fi', null],
            ],
            3 => [
                ['chip', 'Чип', null],
                ['memory_type', 'Тип памяти', null],
                ['capacity_gb', 'Память', ' ГБ'],
                ['wattage', 'Потребление', ' Вт'],
                ['streams', 'Потоковых ядер', null],
                ['length_mm', 'Длина', ' мм'],
                ['connector', 'Разъёмы питания', null],
                ['psu_req_w', 'Рекомендуемый БП', ' Вт'],
            ],
            4 => [
                ['ram_type', 'Тип', null],
                ['capacity_gb', 'Объём', ' ГБ'],
                ['modules', 'Модулей', null],
                ['frequency_mhz', 'Частота', ' МГц'],
                ['timings', 'Тайминги', null],
                ['voltage', 'Напряжение', ' В'],
                ['ecc', 'ECC', null],
                ['rgb', 'Подсветка', null],
            ],
            5 => [
                ['wattage', 'Мощность', ' Вт'],
                ['cert', 'Сертификат', null],
                ['modular', 'Модульность', null],
                ['form_factor', 'Форм-фактор', null],
                ['fan_mm', 'Вентилятор', ' мм'],
                ['connectors', 'Разъёмы', null],
            ],
            6 => [
                ['form_factor', 'Форм-фактор', null],
                ['mobo', 'Совместимые платы', null],
                ['psu_form', 'Форм-фактор БП', null],
                ['max_gpu_mm', 'Макс. длина видеокарты', ' мм'],
                ['max_cooler_mm', 'Макс. высота кулера', ' мм'],
            ],
            7 => [
                ['cooler_type', 'Тип', null],
                ['sockets', 'Сокеты', null],
                ['radiator_mm', 'Радиатор', ' мм'],
                ['height_mm', 'Высота', ' мм'],
                ['heatpipes', 'Трубки теплоотвода', null],
                ['rpm_max', 'Обороты', ' об/мин'],
                ['rpm', 'Обороты', ' об/мин'],
                ['noise_db', 'Шум', ' дБ'],
            ],
            8 => [
                ['capacity_tb', 'Объём', ' ТБ'],
                ['capacity_gb', 'Объём', ' ГБ'],
                ['form_factor', 'Форм-фактор', null],
                ['interface', 'Интерфейс', null],
                ['rpm', 'Обороты', ' об/мин'],
                ['cache_mb', 'Кэш', ' МБ'],
                ['surveillance', 'Для видеонаблюдения', null],
            ],
            9 => [
                ['capacity_gb', 'Объём', ' ГБ'],
                ['form_factor', 'Форм-фактор', null],
                ['interface', 'Интерфейс', null],
                ['nand', 'NAND', null],
                ['read_mbs', 'Чтение', ' МБ/с'],
                ['write_mbs', 'Запись', ' МБ/с'],
                ['dram', 'DRAM', null],
                ['tbw', 'Ресурс записи', ' ТБ'],
            ],
        ];

        $pairs = [];
        foreach ($rules[$category] ?? [] as $rule) {
            [$key, $label, $unit] = $rule;

            // одна характеристика не должна попасть в список дважды:
            // объём у HDD описан и в терабайтах, и в гигабайтах
            $already = false;
            foreach ($pairs as $pair) {
                if ($pair[0] === $label) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }

            $text = spec_value($values[$key] ?? null);
            if ($text === null) {
                continue;
            }

            // единица дописывается всегда, когда она задана в правиле: все
            // ключи с единицами числовые по своей природе, а строковые
            // характеристики - chipset, ram_type, connector - единиц не имеют
            $pairs[] = [$label, $text . ($unit !== null ? $unit : '')];
        }

        // у накопителей интерфейс и форм-фактор часто одно и то же значение,
        // «M.2» и «M.2»: дважды одно и то же выводить незачем. Сравнение
        // только по значению этих двух подписей, а не по всем попарно:
        // иначе у процессора «Ядра: 4» и «Потоки: 4» считались бы дублем
        if ($category === 9 || $category === 8) {
            $formFactor = null;
            foreach ($pairs as $pair) {
                if ($pair[0] === 'Форм-фактор') {
                    $formFactor = $pair[1];
                    break;
                }
            }
            if ($formFactor !== null) {
                $pairs = array_values(array_filter($pairs, static function (array $pair) use ($formFactor) {
                    return !($pair[0] === 'Интерфейс' && $pair[1] === $formFactor);
                }));
            }
        }

        return $pairs;
    }
}

if (!function_exists('component_brief')) {
    /**
     * Короткая строка под названием компонента: производитель и первые
     * две характеристики через точку.
     *
     * @param array<string, mixed> $row
     * @param array<int, array{0: string, 1: string}> $pairs
     */
    function component_brief(array $row, array $pairs): string
    {
        // без характеристик строка состояла бы из одного производителя, а
        // название компонента и так стоит прямо над ней. Так бывает у
        // четырёх корпусов, у которых нет ни specs, ни форм-фактора.
        if (!$pairs) {
            return '';
        }

        $parts = [];

        $manufacturer = trim((string) ($row['manufacturer'] ?? ''));
        if ($manufacturer !== '') {
            $parts[] = $manufacturer;
        }

        foreach (array_slice($pairs, 0, 2) as $pair) {
            $value = $pair[1];
            // длинные списки, как сокеты кулера, в короткую строку не идут
            if (mb_strlen($value) > 28) {
                continue;
            }
            $parts[] = $value;
        }

        return implode(' · ', $parts);
    }
}