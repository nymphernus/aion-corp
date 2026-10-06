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

if (!class_exists('StockShortage')) {
    /**
     * Остатка не хватило.
     *
     * Отдельный класс, а не сообщение в RuntimeException: покупка,
     * у которой не хватило товара, и покупка, которая упала из-за БД, -
     * разные ситуации для посетителя. Первая возвращает его на страницу
     * сборки с объяснением, вторая - отдаёт ошибку сервера.
     */
    class StockShortage extends RuntimeException
    {
    }
}

if (!function_exists('assembly_slots')) {
    /**
     * Слоты сборки, у которых есть своя категория компонентов.
     *
     * Один список на разметку формы админки, обработчик сохранения и
     * подсказки. Имя поля в форме и ключ в обработчике - это
     * `comp_{category_id}` и колонка одновременно, и разойтись они
     * могут только в двух разных файлах, молча потеряв компонент.
     *
     * dvd_id и ssd_2_id сюда не входят: категорий 10 и 11 в базе нет,
     * выбирать для них нечего, и слот остаётся NULL. В
     * assembly_demand() они по-прежнему учитываются - там вопрос
     * другой: сколько склада занимает уже существующая сборка.
     *
     * @return array<string, int> колонка assembly => category_id
     */
    function assembly_slots(): array
    {
        return [
            'cpu_id' => 1,
            'motherboard_id' => 2,
            'gpu_id' => 3,
            'ram_id' => 4,
            'power_supply_id' => 5,
            'case_id' => 6,
            'cooler_id' => 7,
            'hdd_id' => 8,
            'ssd_id' => 9,
        ];
    }
}

if (!function_exists('assembly_required_slots')) {
    /**
     * Слоты, без которых сборка не имеет смысла.
     *
     * Корпус обязателен не только по смыслу: картинка карточки на
     * главной берётся именно из него, и без корпуса витрина осталась
     * бы с пустой рамкой.
     *
     * @return string[]
     */
    function assembly_required_slots(): array
    {
        return ['cpu_id', 'case_id'];
    }
}

if (!function_exists('assembly_demand')) {
    /**
     * Сколько единиц каждого компонента занимает сборка.
     *
     * Ключ - component_id, значение - количество, то есть карта, а не
     * список. Список с повторами списал бы одну единицу вместо двух,
     * если один компонент стоит в двух слотах: остаток завышался бы
     * на каждую такую сборку, и товар уходил бы в минус.
     *
     * Колонки перечислены явно. Обход всех значений строки assembly
     * записал бы в компоненты assembly_price и os - там цена сборки и
     * название операционной системы, а не идентификаторы.
     *
     * @param array<string, mixed> $assemb Строка таблицы assembly
     * @return array<int, int> component_id => количество
     */
    function assembly_demand(array $assemb): array
    {
        $slots = [
            'cpu_id',
            'motherboard_id',
            'gpu_id',
            'ram_id',
            'case_id',
            'cooler_id',
            'power_supply_id',
            'ssd_id',
            'ssd_2_id',
            'hdd_id',
            'dvd_id',
        ];

        $need = [];
        foreach ($slots as $slot) {
            $id = (int) ($assemb[$slot] ?? 0);
            if ($id > 0) {
                $need[$id] = ($need[$id] ?? 0) + 1;
            }
        }

        return $need;
    }
}

if (!function_exists('stock_shortage')) {
    /**
     * Первый компонент, которого не хватает: [id, название] либо null.
     *
     * Одна выборка на всю сборку, а не запрос на каждый слот: слотов
     * до одиннадцати, а проверка нужна до того, как что-то записано.
     * Название возвращается для сообщения - отказ «закончилось» без
     * указания какого товара ничем не помогает покупателю.
     *
     * Отсутствующая в таблице строка считается нехваткой с пустым
     * названием: списание по ней и так ничего не изменит, но сделка
     * с таким товаром проходить не должна.
     *
     * @param array<int, int> $need
     * @return array{0: int, 1: string}|null
     */
    function stock_shortage(mysqli $mysql, array $need): ?array
    {
        if (!$need) {
            return null;
        }

        $ids = array_keys($need);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = db_prepare(
            $mysql,
            "SELECT component_id, component_name, amount FROM components WHERE component_id IN ($ph)",
            $types,
            ...$ids
        );
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['component_id']] = $row;
        }

        foreach ($need as $id => $count) {
            $row = $byId[$id] ?? null;
            $have = $row === null ? 0 : (int) $row['amount'];
            if ($have < $count) {
                return [$id, $row === null ? '' : (string) $row['component_name']];
            }
        }

        return null;
    }
}

if (!function_exists('stock_apply')) {
    /**
     * Списать или вернуть единицы компонентов по карте потребностей.
     *
     * $delta = -1 при покупке, +1 при возврате.
     *
     * Проверка остатка стоит внутри UPDATE, а не только до транзакции:
     * два запроса, пришедших одновременно, предварительную проверку
     * проходят оба, и без условия в самом запросе второй списал бы в
     * минус. Ноль затронутых строк означает нехватку - вызывающий
     * откатывает транзакцию, и заказ не остаётся.
     *
     * @param array<int, int> $need
     * @throws StockShortage когда остатка не хватило
     */
    function stock_apply(mysqli $mysql, array $need, int $delta): void
    {
        foreach ($need as $id => $count) {
            if ($count < 1) {
                continue;
            }

            if ($delta < 0) {
                $stmt = db_prepare(
                    $mysql,
                    "UPDATE components SET amount = amount - ? WHERE component_id = ? AND amount >= ?",
                    'iii',
                    $count,
                    $id,
                    $count
                );
            } else {
                $stmt = db_prepare(
                    $mysql,
                    "UPDATE components SET amount = amount + ? WHERE component_id = ?",
                    'ii',
                    $count,
                    $id
                );
            }

            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($delta < 0 && $affected !== 1) {
                throw new StockShortage('Недостаточно компонента, id ' . $id);
            }
        }
    }
}

if (!function_exists('cleanup_orphan_assemblies')) {
    /**
     * Удалить осиротевшие сборки (is_base = 0, без заказов и избранного).
     *
     * Логика вынесена из cleanup_orphans.php в модуль по трём причинам:
     *   1. Тест HomeAssembliesTest проверяет уборку напрямую, без
     *      запуска отдельного процесса - shell_exec с docker compose
     *      не работает ни из контейнера, ни с хоста.
     *   2. Скрипт и тест проверяют одну и ту же функцию: копия логики
     *      в скрипте означала бы, что тест проверяет не тот код, что
     *      работает в бою при старте контейнера.
     *   3. Порог считается в SQL через DATE_SUB, а не в PHP: разница
     *      часовых поясов между PHP и MySQL сдвигала бы порог на часы.
     *
     * Возвращает число удалённых сборок.
     */
    function cleanup_orphan_assemblies(mysqli $mysql, int $hours = 1): int
    {
        $stmt = db_prepare($mysql, "SELECT a.assembly_id FROM assembly a
            LEFT JOIN favorites f ON f.assembly_id = a.assembly_id
            LEFT JOIN orders o ON o.assembly_id = a.assembly_id
            WHERE a.is_base = 0
              AND f.favorit_id IS NULL
              AND o.order_id IS NULL
              AND a.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)", 'i', $hours);
        $stmt->execute();
        $ids = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $ids[] = (int) $row['assembly_id'];
        }
        $stmt->close();

        if ($ids === []) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        // Транзакция: удаление и сборки, и её записей в избранном.
        // Записи могут появиться между выборкой и удалением - без
        // транзакции сборка удалилась бы, а строка избранного осталась.
        $mysql->begin_transaction();
        try {
            $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE assembly_id IN ($ph)", $types, ...$ids);
            $stmt->execute();
            $stmt->close();

            $stmt = db_prepare($mysql, "DELETE FROM assembly WHERE assembly_id IN ($ph)", $types, ...$ids);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            $mysql->commit();
            return $deleted;
        } catch (Throwable $e) {
            $mysql->rollback();
            error_log('cleanup_orphan_assemblies failed: ' . $e->getMessage());
            return 0;
        }
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