<?php
/**
 * Доли бюджета по категориям для трёх режимов.
 *
 * Сумма каждого набора ровно 100. Доли считаются от исходного бюджета, а не
 * от остатка: в прежней версии проценты брались от того, что не потратил
 * предыдущий компонент, из-за чего корпус получал до 56% остатка и итог
 * сильно зависел от порядка выборки.
 *
 * 3.6.3-c-2: games - вклад в видеокарту, work - в процессор, universal -
 * баланс.
 */
function cfg_percentages($preference)
{
    $sets = [
        'games' => [
            'cpu' => 15, 'gpu' => 45, 'mb' => 10, 'ram' => 8,
            'ssd' => 6, 'psu' => 6, 'case' => 5, 'cooler' => 5,
        ],
        'work' => [
            'cpu' => 30, 'gpu' => 25, 'mb' => 10, 'ram' => 10,
            'ssd' => 8, 'psu' => 5, 'case' => 7, 'cooler' => 5,
        ],
        'universal' => [
            'cpu' => 22, 'gpu' => 33, 'mb' => 10, 'ram' => 9,
            'ssd' => 7, 'psu' => 6, 'case' => 8, 'cooler' => 5,
        ],
    ];

    return isset($sets[$preference]) ? $sets[$preference] : $sets['universal'];
}

/** Выборка одной строки. Типы и параметры - как у bind_param. */
function cfg_fetch($mysql, $sql, $types = '', $params = [])
{
    $stmt = $mysql->prepare($sql);
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? $row : null;
}

/**
 * Условие наличия: товар с amount = 0 покупать нельзя.
 *
 * Раньше подбор шёл по всем компонентам категории, поэтому компонент с
 * нулевым остатком попадал в сборку: пользователь видел готовую
 * конфигурацию, а купить её не мог. Фильтр добавляется в оба запроса
 * подбора - cfg_cheapest() и cfg_pick().
 *
 * Порядок условий в $where не меняется: плейсхолдеры из $where должны
 * идти раньше добавленных параметров.
 */
define('CFG_IN_STOCK', 'amount > 0');

/**
 * Подбор с учётом наличия, а если в наличии ничего нет - любой.
 *
 * Отказ без отката означал бы пустую сборку: у категории может не оказаться
 * ни одного товара в наличии (например, каталог заполнен, но всё
 * распродано). Тогда лучше показать сборку с отсутствующим товаром и
 * пометкой, чем не собрать ничего: клиент увидит, чего не хватает.
 */
function cfg_in_stock_or_any($mysql, $sqlWithStock, $sqlAny, $types, $params)
{
    $row = cfg_fetch($mysql, $sqlWithStock, $types, $params);
    return $row ? $row : cfg_fetch($mysql, $sqlAny, $types, $params);
}

/** Самый дешёвый компонент, удовлетворяющий условию. */
function cfg_cheapest($mysql, $where, $types = '', $params = [])
{
    return cfg_in_stock_or_any(
        $mysql,
        "SELECT * FROM components WHERE $where AND " . CFG_IN_STOCK . " ORDER BY component_price ASC LIMIT 1",
        "SELECT * FROM components WHERE $where ORDER BY component_price ASC LIMIT 1",
        $types,
        $params
    );
}

/**
 * Самый дорогой компонент, но не дороже лимита.
 *
 * Если таких нет - берётся самый дешёвый из доступных: отказ от категории
 * оставил бы сборку без процессора или без платы, а лимит может оказаться
 * ниже минимума категории при малом бюджете.
 *
 * Порядок параметров важен и не переставляется. В $where уже есть свои
 * плейсхолдеры, они идут раньше, поэтому лимит цены дописывается в конец -
 * и в строке типов, и в массиве значений. Иначе socket_id получил бы
 * лимит, а component_price - номер сокета, запрос вернул бы пусто, и вместо
 * платы по сокету молча подставилась бы самая дешёвая.
 */
function cfg_pick($mysql, $limit, $where, $types = '', $params = [])
{
    $row = cfg_fetch(
        $mysql,
        "SELECT * FROM components WHERE $where AND component_price <= ? AND " . CFG_IN_STOCK
            . " ORDER BY component_price DESC LIMIT 1",
        $types . 'd',
        array_merge($params, [(float)$limit])
    );

    return $row ? $row : cfg_cheapest($mysql, $where, $types, $params);
}

/**
 * Какие платы влезают в корпус.
 *
 * Сравнивать form_factor платы и корпуса на равенство бессмысленно: корпус
 * записан как Mid-Tower, а плата как ATX или Micro-ATX, и равенство не
 * сойдётся никогда. Поэтому здесь таблица в обратную сторону - от корпуса
 * к платам, - а уже в configure() она обращается.
 *
 * Мини-корпус принимает только Mini-ITX, остальные принимают всё, что
 * меньше их самих. Для mATX Mid-Tower перечислен и ATX: точная посадка
 * зависит от конкретной модели корпуса, а лишняя плата в подборке хуже,
 * чем слишком широкий список, - такой случай просто окажется без
 * подходящего корпуса и уйдёт в откат.
 *
 * 5-e-3.
 *
 * @return array<string, string[]>
 */
function cfg_case_board_compat()
{
    return [
        'Mini-ITX'       => ['Mini-ITX'],
        'Micro-ATX'      => ['Micro-ATX', 'Mini-ITX'],
        'mATX Mid-Tower' => ['Micro-ATX', 'Mini-ITX', 'ATX'],
        'Mid-Tower'      => ['ATX', 'Micro-ATX', 'Mini-ITX'],
        'ATX Mid-Tower'  => ['ATX', 'Micro-ATX', 'Mini-ITX'],
        'ATX Full-Tower' => ['ATX', 'Micro-ATX', 'Mini-ITX'],
    ];
}

/**
 * Типы корпусов, в которые влезает плата указанного форм-фактора.
 *
 * @return string[]
 */
function cfg_case_types_for_board($boardFormFactor)
{
    if ($boardFormFactor === null || $boardFormFactor === '') {
        return [];
    }

    $types = [];
    foreach (cfg_case_board_compat() as $caseType => $boards) {
        if (in_array($boardFormFactor, $boards, true)) {
            $types[] = $caseType;
        }
    }

    return $types;
}

/**
 * Сборка компьютера по бюджету и приоритету.
 *
 * Бюджет тратится только на железо. ОС добавляется сверху: windows
 * стоит 11000, linux бесплатна, none - пусто. Прежний код вычитал из
 * бюджета 11000 за Windows, и эти деньги уходили в распределение по
 * категориям вместо фиксированной надбавки.
 *
 * Порядок подбора: процессор задаёт сокет для материнской платы, кулер
 * подбирается по его тепловой мощности.
 */
function configure($budget, $preference = 'universal', $osChoice = 'none')
{
    require_once 'modules/connect.php';
    require_once 'modules/components.php';
    $mysql = connect();
    mysqli_set_charset($mysql, 'utf8');

    $budget = (int)$budget;
    $pct = cfg_percentages((string)$preference);
    $limit = [];
    foreach ($pct as $part => $share) {
        $limit[$part] = (int)round($budget * $share / 100);
    }

    // ОС - надбавка сверх бюджета, на распределение по железу не влияет
    $os = null;
    $osPrice = 0;
    if ($osChoice === 'windows') {
        $osPrice = 11000;
        $os = 'Windows 10 Home';
    } elseif ($osChoice === 'linux') {
        $os = 'Ubuntu 24.04 LTS';
    }

    $stmt = db_prepare($mysql, "SELECT MAX(assembly_id) FROM assembly");
    $stmt->execute();
    $checklast = $stmt->get_result()->fetch_array();
    $maxID = ($checklast[0] ?? 0) + 1;
    $name = "#$maxID";

    // компоненты заполняются по ходу подбора; инициализация нужна, чтобы при
    // отсутствии процессора не обращаться к необъявленным переменным
    $cpu = null;
    $motherboard = null;
    $ram = null;
    $power_supply = null;
    $case = null;
    $cooler = null;
    $ssd = null;
    $gpu = null;

    if ($budget <= 22499) {
        // Дешёвая сборка: самые доступные комплектующие, дискретной
        // видеокарты нет. Поэтому процессор обязан быть со встроенным
        // видео - иначе машина вообще ничего не выведет на монитор.
        $cpu = cfg_cheapest($mysql, 'category_id = 1 AND video_core = 1');
        if (!$cpu) {
            $cpu = cfg_cheapest($mysql, 'category_id = 1');
        }
    } else {
        $cpu = cfg_pick($mysql, $limit['cpu'], 'category_id = 1');
        $gpu = cfg_pick($mysql, $limit['gpu'], 'category_id = 3');
    }

    if ($cpu) {
        $motherboard = cfg_pick(
            $mysql,
            $limit['mb'],
            'category_id = 2 AND socket_id = ?',
            'i',
            [$cpu['socket_id']]
        );

        // 5-e-3: память должна совпадать с платой по типу. Раньше
        // ограничения не было, и в сборку попадало DDR4 к плате DDR5.
        // Если у платы тип не заполнен, фильтр не применяется.
        $ramWhere = 'category_id = 4';
        $ramTypes = '';
        $ramParams = [];
        $mbRamType = $motherboard['ram_type'] ?? null;
        if ($mbRamType !== null && $mbRamType !== '') {
            $ramWhere .= ' AND ram_type = ?';
            $ramTypes = 's';
            $ramParams = [$mbRamType];
        }
        $ram = cfg_pick($mysql, $limit['ram'], $ramWhere, $ramTypes, $ramParams);

        $power_supply = cfg_pick($mysql, $limit['psu'], 'category_id = 5');

        // 5-e-3: корпус должен принимать форм-фактор платы.
        $caseWhere = 'category_id = 6';
        $caseTypes = '';
        $caseParams = [];
        $caseCandidates = cfg_case_types_for_board($motherboard['form_factor'] ?? null);
        if ($caseCandidates) {
            $casePlaceholders = implode(',', array_fill(0, count($caseCandidates), '?'));
            $caseWhere .= " AND form_factor IN ($casePlaceholders)";
            $caseTypes = str_repeat('s', count($caseCandidates));
            $caseParams = $caseCandidates;
        }
        $case = cfg_pick($mysql, $limit['case'], $caseWhere, $caseTypes, $caseParams);
        if (!$case && $caseCandidates) {
            // под эту плату корпусов в подборке нет - берём любой, иначе
            // сборка из-за одного корпуса просто не сохранилась бы
            $case = cfg_pick($mysql, $limit['case'], 'category_id = 6');
        }

        $ssd = cfg_pick($mysql, $limit['ssd'], 'category_id = 9');

        // кулер - по тепловой мощности процессора и по сокету.
        // Сокет проверяется мягко: подходит кулер, у которого socket_id совпал
        // с процессором или чей specs.sockets называет нужный сокет. Кулера
        // без данных о сокете не отбрасываем - их 23 из 28, и отказ от них
        // оставил бы часть сборок вовсе без кулера.
        $tdpWhere = 'category_id = 7';
        $tdpTypes = '';
        $tdpParams = [];
        if (!empty($cpu['tdp'])) {
            if ($budget <= 22499) {
                $tdpWhere .= ' AND tdp > ? AND tdp < ?';
                $tdpTypes = 'ii';
                $tdpParams = [(int)$cpu['tdp'], (int)$cpu['tdp'] + 40];
            } else {
                $tdpWhere .= ' AND tdp > ?';
                $tdpTypes = 'i';
                $tdpParams = [(int)$cpu['tdp']];
            }
        }

        // 5-c: имена сокетов берутся из таблицы sockets, а не из карты в коде.
// Карта покрывала три сокета из семи, и для LGA1851 с AM5 фильтр по
// сокету просто не применялся.
$socketMap = socket_types($mysql);
$socketName = $socketMap[(int)$cpu['socket_id']] ?? '';
        $cooler = null;
        if ($socketName !== '') {
            $socketWhere = $tdpWhere
                . ' AND (socket_id = ? OR specs IS NULL'
                . " OR NOT JSON_CONTAINS_PATH(specs, 'one', '\$.sockets')"
                . " OR JSON_CONTAINS(specs->'\$.sockets', ?))";
            $cooler = cfg_pick(
                $mysql,
                $limit['cooler'],
                $socketWhere,
                $tdpTypes . 'is',
                array_merge($tdpParams, [(int)$cpu['socket_id'], '"' . $socketName . '"'])
            );
        }

        if (!$cooler && $tdpWhere !== 'category_id = 7') {
            // под этот сокет и мощность кулеров нет - берём по одной мощности,
            // иначе сборка из-за одного кулера просто не сохранилась бы
            $cooler = cfg_pick($mysql, $limit['cooler'], $tdpWhere, $tdpTypes, $tdpParams);
        }
        if (!$cooler) {
            $cooler = cfg_cheapest($mysql, 'category_id = 7');
        }
    }

    // Итог: сумма железа плюс надбавка за ОС
    $budget_whole = $osPrice;
    foreach (array($cpu, $motherboard, $ram, $power_supply, $case, $cooler, $ssd, $gpu) as $part) {
        if ($part) {
            $budget_whole += (int)$part['component_price'];
        }
    }

    if (isset($cpu, $motherboard, $ram, $power_supply, $case, $cooler, $ssd)) {
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO `assembly` (`assembly_id`,`assembly_name`, `cpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `assembly_price`) VALUES(?,?,?,?,?,?,?,?,?,?)",
            "isiiiiiiii",
            $maxID,
            $name,
            $cpu['component_id'],
            $motherboard['component_id'],
            $ram['component_id'],
            $case['component_id'],
            $cooler['component_id'],
            $power_supply['component_id'],
            $ssd['component_id'],
            $budget_whole
        );
        $stmt->execute();

        if (isset($gpu)) {
            $stmt = db_prepare($mysql, "UPDATE `assembly` SET `gpu_id` = ? WHERE `assembly_id` = ?", "ii", $gpu['component_id'], $maxID);
            $stmt->execute();
        }

        if (isset($os)) {
            $stmt = db_prepare($mysql, "UPDATE `assembly` SET `os` = ? WHERE `assembly_id` = ?", "si", $os, $maxID);
            $stmt->execute();
        }

        setcookie('assemblyId', $maxID, time() + 3600 * 2, "/", "", true, true);
    }

    $mysql->close();
    header('Location: /assembly.php');
    exit();
}

function upload($aID) {
    setcookie('assemblyId', (int)$aID, time() + 3600 * 2, "/", "", true, true);
    header('Location: /assembly.php');
    exit();
}
?>
