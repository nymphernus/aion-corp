<?php
/**
 * Админ-панель AION CORP.
 *
 * URL: /admin.php?tab=users|orders|components
 *  - без ?tab → 302 на ?tab=users 
 *  - неизвестный tab → 404
 *  - гость / не админ (свежая группа из БД) → 302 на /profile.php
 *
 * Обработчики POST и разметка вкладок вынесены в src/admin/.
 */

require_once __DIR__ . '/modules/connect.php';
require_once __DIR__ . '/modules/pagination.php';
// Смена статуса заказа возвращает товар на склад, а остатки считает
// модуль компонентов. Без этой строки order_set_status() падал на
// assembly_demand() как Error до try - и отдавал пустую страницу
// вместо редиректа.
require_once __DIR__ . '/modules/components.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

csrf_token();

$mysql = connect();
mysqli_set_charset($mysql, 'utf8');

if (!$mysql) {
    die("Ошибка подключения к базе данных");
}

// Доступ: только залогиненный админ (группа — свежая из БД, не из сессии)
if (empty($_SESSION['user_id'])) {
    header('Location: /profile.php');
    exit();
}

$stmt = db_prepare($mysql, "SELECT user_group FROM `users` WHERE `user_id` = ?", "i", $_SESSION['user_id']);
$stmt->execute();
$adminRow = $stmt->get_result()->fetch_assoc();

if (($adminRow['user_group'] ?? '') !== 'admin') {
    header('Location: /profile.php');
    exit();
}

$isAdmin = true;

// Роутинг вкладки
$tab = $_GET['tab'] ?? '';
if ($tab === '') {
    // Без раздела возвращаем на /profile.php: оттуда пришли и оттуда
    // есть и вход, и свои сборки. Редирект в ?tab=users выглядел бы
    // как «меня куда-то перебросило».
    header('Location: /profile.php');
    exit();
}

$allowedTabs = ['users' => true, 'orders' => true, 'components' => true, 'files' => true, 'dashboard' => true, 'settings' => true, 'configurator' => true, 'assemblies' => true, 'log' => true];
if (!isset($allowedTabs[$tab])) {
    http_response_code(404);
    exit('Раздел не найден');
}

// Пагинация считается ДО вывода HTML: header() в paginate() не
// сработает после старта вывода (headers already sent), и редирект
// с page=99 превратился бы в пустую таблицу.
// Журнал - 50 строк на страницу: записи мелкие, 10 на экран превращают
// просмотр в бесконечный листание.
$perPage = $tab === 'log' ? 50 : 10;

// Условие фильтрации общее для COUNT, для выборки и для ссылок пагинации.
$listWhere = '';
$listParams = [];
$listTypes = '';
$listQuery = '';  // GET-параметры для сохранения в ссылках
$listOrder = '';  // ORDER BY из белого списка

// экранирование спецсимволов LIKE, чтобы «%» не стал маской
$escapeLike = static function (string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
};

// Фильтры при сохранении и удалении.
//
// Форма шлёт POST на /admin.php?tab=components БЕЗ фильтров в адресе,
// поэтому в $_GET на POST-запросе их нет и взять оттуда нельзя.
// Разметка вкладки кладёт текущие фильтры в скрытое поле
// return_params, отсюда же они читаются при редиректе.
$listFilterKeys = [
    'components' => ['page', 'cat', 'sock', 'q', 'sort', 'no_image'],
    'users' => ['page', 'group', 'q'],
    'orders' => ['page', 'status', 'q'],
    'files' => ['page', 'filter', 'q'],
    'settings' => [],
    'dashboard' => [],
];

/**
 * Строка фильтров из скрытого поля return_params для редиректа.
 *
 * Значение приходит от клиента, поэтому оно не подставляется в Location
 * как есть: остаются только символы, из которых может состоять пара
 * «ключ=значение». Так в заголовок не попадёт ни перевод строки, ни
 * чужой адрес.
 */
function return_params(): string
{
    // $tab тоже должен быть в global: переменная живёт в includе, а
    // функция объявлена вне его области видимости.
    global $listFilterKeys, $tab;

    $raw = (string) ($_POST['return_params'] ?? '');
    if ($raw === '') {
        return '';
    }

    $params = [];
    parse_str($raw, $params);
    $allowed = $listFilterKeys[$tab] ?? [];

    $clean = [];
    foreach ($allowed as $key) {
        if (!isset($params[$key]) || is_array($params[$key])) {
            continue;
        }
        $value = trim((string) $params[$key]);
        if ($value !== '') {
            $clean[$key] = $value;
        }
    }

    return http_build_query($clean);
}

/**
 * Адрес возврата на вкладку: активные фильтры и, при желании, код
 * ошибки. Используется всеми редиректами обработчиков, включая отказы -
 * после «файл слишком большой» фильтры тоже должны были уцелеть.
 */
function admin_list_url(string $listTab, ?string $error = null): string
{
    global $tab;

    $url = '/admin.php?tab=' . rawurlencode($listTab);

    $params = $tab === $listTab ? return_params() : '';
    if ($params !== '') {
        $url .= '&' . $params;
    }
    if ($error !== null && $error !== '') {
        $url .= '&error=' . rawurlencode($error);
    }
    return $url;
}

/**
 * Текущие фильтры вкладки для скрытого поля return_params. Источник -
 * живой GET текущей страницы (здесь это законно: страницу ещё рендерят).
 */
function admin_list_query(string $listTab): string
{
    global $listFilterKeys;

    $allowed = $listFilterKeys[$listTab] ?? [];
    $params = [];
    parse_str($_SERVER['QUERY_STRING'] ?? '', $params);

    $clean = [];
    foreach ($allowed as $key) {
        if (!isset($params[$key]) || is_array($params[$key])) {
            continue;
        }
        $value = trim((string) $params[$key]);
        if ($value !== '') {
            $clean[$key] = $value;
        }
    }

    return http_build_query($clean);
}

if ($tab === 'components') {
    $fCat = (int) ($_GET['cat'] ?? 0);
    $fSock = (int) ($_GET['sock'] ?? 0);
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if ($fQ !== '') {
        $fQ = $escapeLike($fQ);
    }

    if ($fCat > 0) {
        $listWhere .= ' AND components.category_id = ?';
        $listParams[] = $fCat;
        $listTypes .= 'i';
    }
    // сокет применяем только когда категория выбрана и
    // входит в [1, 2, 7] - скрытый select всё равно шлёт значение
    $sockRelevant = in_array($fCat, [1, 2, 7], true);
    if ($fSock > 0 && $sockRelevant) {
        $listWhere .= ' AND components.socket_id = ?';
        $listParams[] = $fSock;
        $listTypes .= 'i';
    }
    if ($fQ !== '') {
        $listWhere .= ' AND components.component_name LIKE ?';
        $listParams[] = '%' . $fQ . '%';
        $listTypes .= 's';
    }

    // только корпуса без картинки (ссылка из файлового менеджера).
    // Признак - флаг без значения: ?tab=components&cat=6&no_image=1
    if (isset($_GET['no_image'])) {
        $listWhere .= " AND (components.image IS NULL OR components.image = '')";
        // флаг остаётся в ссылках пагинации - иначе со страницы 2 он терялся
        $qs_no_image = true;
    }

    // сортировка только из белого списка, $_GET в SQL не идёт
    $sortWhitelist = [
        'price_asc' => 'components.component_price ASC',
        'price_desc' => 'components.component_price DESC',
        'amount_asc' => 'components.amount ASC',
        'amount_desc' => 'components.amount DESC',
        'name_asc' => 'components.component_name ASC',
    ];
    $sort = (string) ($_GET['sort'] ?? '');
    $listOrder = $sortWhitelist[$sort] ?? 'components.component_id ASC';

    $qs = [];
    if ($fCat > 0) {
        $qs[] = 'cat=' . $fCat;
    }
    if ($fSock > 0) {
        $qs[] = 'sock=' . $fSock;
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode((string) ($_GET['q'] ?? ''));
    }
    if (isset($sortWhitelist[$sort])) {
        $qs[] = 'sort=' . $sort;
    }
    // флаг переносится в пагинацию, если проставлен выше
    if (!empty($qs_no_image)) {
        $qs[] = 'no_image=1';
    }
    $listQuery = implode('&', $qs);
} elseif ($tab === 'orders') {
    // фильтр по статусу, поиск по покупателю, сортировка
    $fStatus = trim((string) ($_GET['status'] ?? ''));
    $fQ = trim((string) ($_GET['q'] ?? ''));
    $statusWhitelist = ['Обрабатывается', 'Собирается', 'Доставляется', 'Выполнен', 'Отменён'];
    if (in_array($fStatus, $statusWhitelist, true)) {
        $listWhere .= ' AND orders.status = ?';
        $listParams[] = $fStatus;
        $listTypes .= 's';
    }
    if ($fQ !== '') {
        $like = '%' . $escapeLike($fQ) . '%';
        $listWhere .= ' AND (users.user_name LIKE ? OR users.user_surname LIKE ? OR users.user_login LIKE ?)';
        $listParams[] = $like;
        $listParams[] = $like;
        $listParams[] = $like;
        $listTypes .= 'sss';
    }
    // сортировка убрана и из UI, и из бэкенда — порядок
    // фиксированный (свежие сверху), параметр sort больше не читается
    $listOrder = 'orders.created_at DESC, orders.order_id DESC';

    $qs = [];
    if (in_array($fStatus, $statusWhitelist, true)) {
        $qs[] = 'status=' . urlencode($fStatus);
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode($fQ);
    }
    $listQuery = implode('&', $qs);
} elseif ($tab === 'users') {
    // фильтр по группе и поиск по имени или логину
    $fGroup = trim((string) ($_GET['group'] ?? ''));
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if (in_array($fGroup, ['user', 'admin'], true)) {
        $listWhere .= ' AND users.user_group = ?';
        $listParams[] = $fGroup;
        $listTypes .= 's';
    }
    if ($fQ !== '') {
        $like = '%' . $escapeLike($fQ) . '%';
        $listWhere .= ' AND (users.user_name LIKE ? OR users.user_login LIKE ?)';
        $listParams[] = $like;
        $listParams[] = $like;
        $listTypes .= 'ss';
    }
    // сортировка убрана, порядок по умолчанию — по id
    $listOrder = 'users.user_id ASC';

    $qs = [];
    if (in_array($fGroup, ['user', 'admin'], true)) {
        $qs[] = 'group=' . $fGroup;
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode($fQ);
    }
    $listQuery = implode('&', $qs);
} elseif ($tab === 'log') {
    // Фильтры журнала действий: пользователь, действие и диапазон дат.
    // Все четыре идут параметрами (? в запросе), даты допроверяются
    // форматом - неверная дата фильтром просто не считается.
    $fLogUser = (string) ($_GET['user_login'] ?? '');
    $fLogAction = (string) ($_GET['action'] ?? '');
    $fLogFrom = (string) ($_GET['date_from'] ?? '');
    $fLogTo = (string) ($_GET['date_to'] ?? '');

    if ($fLogUser !== '') {
        $listWhere .= ' AND admin_actions.user_login = ?';
        $listParams[] = $fLogUser;
        $listTypes .= 's';
    }
    if ($fLogAction !== '') {
        $listWhere .= ' AND admin_actions.action = ?';
        $listParams[] = $fLogAction;
        $listTypes .= 's';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fLogFrom) === 1) {
        $listWhere .= ' AND admin_actions.created_at >= ?';
        $listParams[] = $fLogFrom . ' 00:00:00';
        $listTypes .= 's';
    } else {
        $fLogFrom = '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fLogTo) === 1) {
        $listWhere .= ' AND admin_actions.created_at <= ?';
        $listParams[] = $fLogTo . ' 23:59:59';
        $listTypes .= 's';
    } else {
        $fLogTo = '';
    }
    // Сортировка из плана: свежие записи сверху, id разрешает
    // равные timestamps.
    $listOrder = 'admin_actions.created_at DESC, admin_actions.action_id DESC';

    $qs = [];
    if ($fLogUser !== '') {
        $qs[] = 'user_login=' . urlencode($fLogUser);
    }
    if ($fLogAction !== '') {
        $qs[] = 'action=' . urlencode($fLogAction);
    }
    if ($fLogFrom !== '') {
        $qs[] = 'date_from=' . urlencode($fLogFrom);
    }
    if ($fLogTo !== '') {
        $qs[] = 'date_to=' . urlencode($fLogTo);
    }
    $listQuery = implode('&', $qs);
}

// Блок с COUNT и paginate() нужен только таблицам со списком. У
// dashboard, settings, configurator и assemblies пагинации нет: это
// формы на пару экранов и по одной строке на карточку главной.
// Их ключей в $countSql тоже нет, а попытка сослаться на отсутствующий
// ключ уронила бы db_prepare() на типе аргумента.
if ($tab !== 'dashboard' && $tab !== 'settings' && $tab !== 'configurator' && $tab !== 'assemblies') {
    $countSql = [
        'components' => 'SELECT COUNT(*) FROM components WHERE 1=1' . $listWhere,
    'files' => 'SELECT 0',  // файлы считаются в _tab_files.php
        'users' => 'SELECT COUNT(*) FROM users WHERE 1=1' . $listWhere,
        'orders' => 'SELECT COUNT(*) FROM users,assembly,orders
                     WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id'
                     . $listWhere,
        'log' => 'SELECT COUNT(*) FROM admin_actions WHERE 1=1' . $listWhere,
    ][$tab];
    if ($listParams === []) {
        $stmt = db_prepare($mysql, $countSql, '');
    } else {
        $stmt = db_prepare($mysql, $countSql, $listTypes, ...$listParams);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    // [$page, $pages, $offset] доступны во всех вкладках через общий scope
    [$page, $pages, $offset] = paginate($tab, $total, $perPage, $listQuery);
}

// Обработчики POST (перенесено из profile.php, SQL без изменений)
if ($isAdmin && isset($_POST['deleteComponent'])) {
    csrf_verify();
    // FK assembly.*_id → components.component_id (11 колонок, NO ACTION).
    // Без проверки MySQL выдал бы 23000 пользователю, поэтому считаем
    // использования заранее и отказываем с понятным сообщением.
    $delId = (int) ($_POST['deleteComponentId'] ?? 0);

    if ($delId > 0) {
        $stmt = db_prepare($mysql,
            "SELECT COUNT(*) FROM assembly WHERE
             cpu_id = ? OR motherboard_id = ? OR gpu_id = ? OR ram_id = ?
             OR case_id = ? OR cooler_id = ? OR power_supply_id = ? OR ssd_id = ?
             OR ssd_2_id = ? OR hdd_id = ? OR dvd_id = ?",
            'iiiiiiiiiii', ...array_fill(0, 11, $delId));
        $stmt->execute();
        $usedCount = (int) $stmt->get_result()->fetch_row()[0];

        if ($usedCount > 0) {
            csrf_rotate();
            // фильтры сохраняются и здесь: отказ удаления тоже должен
            // вернуть админа на ту же страницу, откуда он пришёл
            header('Location: ' . admin_list_url('components', 'used') . '&count=' . $usedCount);
            exit();
        }

        // Имя фиксируется ДО удаления: в журнале остаётся копия,
        // читаемая и после пропажи строки.
        $nameStmt = db_prepare($mysql, "SELECT component_name FROM components WHERE component_id = ?", "i", $delId);
        $nameStmt->execute();
        $delName = (string) ($nameStmt->get_result()->fetch_row()[0] ?? '');

        $stmt = db_prepare($mysql, "DELETE FROM `components` WHERE `component_id` = ?", "i", $delId);
        $stmt->execute();
        admin_log($mysql, 'component.delete', 'component', $delId, ['name' => $delName]);
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('components'));
    exit();
}

if ($isAdmin && isset($_POST['addComponent'])) {
    csrf_verify();

    // Текущие фильтры лежат в POST-поле return_params, которое кладёт
    // разметка вкладки: на POST-запросе в $_GET есть только tab=.
    // Ни одного вывода до header() здесь быть не должно - любое echo
    // или даже пробел раньше дают «headers already sent».
    $location = '/admin.php?tab=components';
    $returnParams = return_params();
    if ($returnParams !== '') {
        $location .= '&' . $returnParams;
    }

    // Пустой editComponentId = INSERT, заполненный = UPDATE
    $editId = (int) ($_POST['editComponentId'] ?? 0);
    $name = $_POST['nm'] ?? '';
    $price = (int) ($_POST['pr'] ?? 0);
    $amount = (int) ($_POST['col'] ?? 0);
    $categoryId = (int) ($_POST['cat'] ?? 0);
    
    // Изображение корпуса, только для category_id = 6 (Корпус).
    $newImagePath = null;
    if ($categoryId === 6) {
        $stmt = db_prepare($mysql, "SELECT image FROM components WHERE component_id = ?", "i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $newImagePath = $row['image'] ?? null;

        // Клиент чистит файловый input при выборе из загруженных, но
        // сервер всё равно решает сам: file вытесняет url, url вытесняет
        // простой сброс флага (иначе open+cancel сохранил бы stale-выбор).
        if (!empty($_FILES['image_file']['name'])) {
            $imageSelectedUrl = '';
        } else {
            $imageSelectedUrl = trim((string) ($_POST['image_selected_url'] ?? ''));
        }

        // Путь строго в cases/ и файл физически есть. Формат хранения -
        // без ведущего слеша, как у всех существующих записей.
        if (!empty($imageSelectedUrl)) {
            $pickUrl = (string) $imageSelectedUrl;
            if (strpos($pickUrl, 'assets/images/cases/') === 0
                && strpos($pickUrl, '..') === false
                && is_file(__DIR__ . '/assets/images/cases/' . basename($pickUrl))) {
                $newImagePath = $pickUrl;
            }
        }
        // Отвязка: только когда нет ни файла, ни выбора из пикера
        elseif (!empty($_POST['removeImage']) && $_POST['removeImage'] === '1') {
            $newImagePath = null;
        }

        // Проверки, конвертация в PNG и дедупликация по MD5 живут в
        // store_case_image(): файлы грузятся ещё и пачкой с вкладки
        // «Изображения», и две копии правил разошлись бы.
        if (!empty($_FILES['image_file']['name'])) {
            $stored = store_case_image(
                $_FILES['image_file'],
                __DIR__ . '/assets/images/cases/',
                // slug из имени компонента, а не из файла: на диске
                // всегда итоговое slug.ext
                slugify_image_name((string) $name)
            );

            if (!$stored['ok']) {
                header('Location: ' . $location . '&error=' . rawurlencode((string) $stored['error']));
                exit();
            }
            $newImagePath = 'assets/images/cases/' . basename((string) $stored['path']);
        }
    }

    // Разрешённые поля по категориям. Скрытые input всё равно уходят в
    // $_POST (залипший tdp от «Процессора» после переключения на «ОЗУ»),
    // поэтому сервер режет всё вне маппинга.
    $fieldMap = [
        1  => ['socket_id', 'tdp', 'frequency_mhz', 'video_core'],
        2  => ['socket_id', 'form_factor', 'ram_type'],
        3  => ['capacity_gb', 'memory_type', 'tdp', 'wattage'],
        4  => ['ram_type', 'capacity_gb', 'frequency_mhz'],
        5  => ['wattage', 'form_factor'],
        6  => ['form_factor'],
        7  => ['cooler_type', 'tdp', 'socket_id'],
        8  => ['capacity_gb', 'interface', 'rpm', 'form_factor'],
        9  => ['capacity_gb', 'interface', 'form_factor'],
        10 => ['interface'],
    ];

    if (!empty($name) && !empty($price) && !empty($amount) && !empty($categoryId)) {
        $fields = [
            'component_name' => $name,
            'component_price' => $price,
            'amount' => $amount,
            'category_id' => $categoryId,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'manufacturer' => trim($_POST['manufacturer'] ?? '') ?: null,
            'model' => trim($_POST['model'] ?? '') ?: null,
            'image' => ($categoryId === 6) ? $newImagePath : null,
            'socket_id' => null,
            'tdp' => null,
            'frequency_mhz' => null,
            'video_core' => null,
            'ram_type' => null,
            'capacity_gb' => null,
            'memory_type' => null,
            'wattage' => null,
            'interface' => null,
            'form_factor' => null,
            'rpm' => null,
            'cooler_type' => null,
        ];

        foreach ($fieldMap[$categoryId] ?? [] as $f) {
            if ($f === 'socket_id') {
                // select шлёт id из таблицы sockets
                $fields['socket_id'] = ($_POST['socket'] ?? '') !== '' ? (int) $_POST['socket'] : null;
            } elseif ($f === 'video_core') {
                $fields['video_core'] = isset($_POST['video_core']) ? 1 : 0;
            } elseif (($_POST[$f] ?? '') !== '') {
                $fields[$f] = $_POST[$f];
            }
        }

        // Типы выводятся из набора колонок: i для числовых, s для остальных.
        $intCols = ['component_price', 'amount', 'category_id', 'socket_id', 'tdp',
            'frequency_mhz', 'video_core', 'capacity_gb', 'wattage', 'rpm'];
        $cols = array_keys($fields);
        $types = '';
        foreach ($cols as $c) {
            $types .= in_array($c, $intCols, true) ? 'i' : 's';
        }
        if ($editId > 0) {
            // UPDATE всех 19 колонок — при смене категории поля,
            // не входящие в новый маппинг, обнуляются ($fields = null)
            $sql = "UPDATE `components` SET `" . implode('`=?,`', $cols) . '`=?'
                . " WHERE `component_id`=?";
            // PHP не даёт позиционный аргумент после ... — id дописываем в массив
            $args = array_values($fields);
            $args[] = $editId;
            $stmt = db_prepare($mysql, $sql, $types . 'i', ...$args);
        } else {
            $sql = "INSERT INTO `components` (`" . implode('`,`', $cols) . '`) VALUES('
                . implode(',', array_fill(0, count($cols), '?')) . ')';
            $stmt = db_prepare($mysql, $sql, $types, ...array_values($fields));
        }
        $stmt->execute();
        admin_log(
            $mysql,
            $editId > 0 ? 'component.update' : 'component.create',
            'component',
            $editId > 0 ? $editId : (int) $mysql->insert_id,
            ['name' => (string) $name]
        );
        csrf_rotate();
    }
    header('Location: ' . $location);
    exit();
}

// --- Файловый менеджер: загрузка пачки файлов ---
// Кнопка «Загрузить изображения» на вкладке «Изображения»: файлы
// приходят в $_FILES['files'] по одному, привязки к корпусам на этом
// этапе нет - сначала просто попадают в каталог и в сетку, корпус
// выбирается отдельно кликом по карточке.
if ($isAdmin && isset($_POST['batchUpload'])) {
    csrf_verify();

    $targetDir = __DIR__ . '/assets/images/cases/';
    $saved = 0;
    $skipped = 0;
    $errors = [];

    if (!empty($_FILES['files']['name'][0])) {
        $count = count($_FILES['files']['name']);
        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name' => (string) $_FILES['files']['name'][$i],
                'type' => (string) $_FILES['files']['type'][$i],
                'tmp_name' => (string) $_FILES['files']['tmp_name'][$i],
                'error' => (int) $_FILES['files']['error'][$i],
                'size' => (int) $_FILES['files']['size'][$i],
            ];

            // Имя на диске из имени файла, а не из uniqid(): «upload-68f3c1a2»
            // админу ничего не говорит, а по корпусу он узнаёт.
            $stored = store_case_image($file, $targetDir, slugify_image_name($file['name']));

            if ($stored['ok'] && $stored['duplicate']) {
                // Дубль: на диске ничего не добавилось, в «загружено» он не идёт.
                $skipped++;
                $errors[] = $file['name'] . ' (дубль)';
            } elseif ($stored['ok']) {
                $saved++;
            } else {
                $skipped++;
                $errors[] = $file['name'] . ' (' . $stored['error'] . ')';
            }
        }
    }

    if ($saved > 0) {
        admin_log($mysql, 'file.batch_upload', 'file', null, ['count' => $saved]);
    }

    csrf_rotate();
    // Итог в query: uploaded=сколько сохранилось, skipped/bad - что
    // отклонено и почему. Список имён обрезан, иначе 30 файлов
    // превратят адресную строку в мусор.
    $location = admin_list_url('files');
    $location .= '&uploaded=' . $saved;
    if ($skipped > 0) {
        $location .= '&skipped=' . $skipped;
        $location .= '&bad=' . rawurlencode(implode(', ', array_slice($errors, 0, 5)));
    }
    header('Location: ' . $location);
    exit();
}

// Удаление файла с менеджера. Привязки к нему обнуляются: иначе в
// components.image остаётся путь в несуществующий файл, корпус
// числится привязанным, а превью в модалке битое. basename режет path
// traversal, поэтому ../../index.php ищется как index.php внутри cases/.
$filename = isset($_POST['deleteFile']) ? basename((string) ($_POST['filename'] ?? '')) : '';

if ($isAdmin && $filename !== '') {
    csrf_verify();

    $path = __DIR__ . '/assets/images/cases/' . $filename;

    // Привязки ищутся по имени файла, а не по полному пути: в базе путь
    // может лежать и со слешем, и без.
    $bound = db_prepare($mysql,
        "SELECT component_id, image FROM components
         WHERE image IS NOT NULL
           AND image LIKE '%assets/images/cases/%'
           AND SUBSTRING_INDEX(image, '/', -1) = ?",
        "s", $filename);
    $bound->execute();
    $bindings = $bound->get_result()->fetch_all(MYSQLI_ASSOC);
    $affected = count($bindings);

    // Файл снимается с диска ПЕРВЫМ, привязки обнуляются потом. Если
    // unlink не сработал, привязки не тронуты; обратный порядок оставил бы
    // корпус без картинки при картинке на диске.
    $removed = false;
    if (is_file($path)) {
        $removed = unlink($path);
        if (!$removed) {
            header('Location: /admin.php?tab=files&error=undelete');
            exit;
        }
    }

    // Привязки обнуляются, даже если файла уже не было на диске: иначе
    // корпус навсегда числился бы привязанным к пустоте.
    foreach ($bindings as $b) {
        $clear = db_prepare($mysql, "UPDATE components SET image = NULL WHERE component_id = ?", "i", $b['component_id']);
        $clear->execute();
    }

    admin_log($mysql, 'file.delete', 'file', null, ['name' => $filename]);

    csrf_rotate();
    header('Location: /admin.php?tab=files&unlinked=' . $affected . ($removed ? '&deleted=1' : ''));
    exit;
}

// Привязка файла к корпусу из файлового менеджера.
if ($isAdmin && isset($_POST['attachFile'])) {
    csrf_verify();

    $fileUrl = trim((string) ($_POST['fileUrl'] ?? ''));
    $caseId = (int) ($_POST['caseId'] ?? 0);
    $attached = false;

    // Путь строго в cases/, без traversal, файл есть на диске. Привязка
    // возможна только к категории 6 - условие прямо в UPDATE.
    if (strpos($fileUrl, 'assets/images/cases/') === 0
        && strpos($fileUrl, '..') === false
        && $caseId > 0
        && is_file(__DIR__ . '/assets/images/cases/' . basename($fileUrl))) {
        $stmt = db_prepare($mysql,
            "UPDATE components SET image = ?
             WHERE component_id = ? AND category_id = 6",
            "si", $fileUrl, $caseId);
        $stmt->execute();
        $attached = $stmt->affected_rows > 0;
        $stmt->close();
    }

    // Логируется только успешная привязка: отклонённая проверка путей
    // - не действие, а отказ, и журнал от них не заполняется.
    if ($attached) {
        admin_log($mysql, 'file.attach', 'component', $caseId, ['file' => $fileUrl]);
    }

    csrf_rotate();
    header('Location: /admin.php?tab=files');
    exit;
}

/**
 * Статус отменённого заказа.
 *
 * Именно по нему считается, возвращать товар на склад или нет.
 * Строкой, а не константой: значение попадает в таблицу orders и
 * разбирается на странице заказов.
 */
define('ORDER_STATUS_CANCELLED', 'Отменён');

if (!function_exists('order_statuses')) {
    /**
     * Статусы, которые админ может выставить заказу.
     *
     * Список один на оба обработчика: у модалки и у кнопок в строках
     * таблицы. Иначе POST мог бы поставить заказу произвольный статус.
     */
    function order_statuses(): array
    {
        return ['Обрабатывается', 'Собирается', 'Доставляется', 'Выполнен', ORDER_STATUS_CANCELLED];
    }
}

if (!function_exists('order_set_status')) {
    /**
     * Сменить статус заказа вместе со складом.
     *
     * Отмена возвращает единицы всех компонентов сборки, снятие
     * отмены списывает их обратно. Статус читается до UPDATE: старый
     * статус и есть признак перехода, и он же делает операцию
     * идемпотентной. Форму можно перезагрузить или отправить дважды,
     * и без этой проверки каждый повторный переход в «Отменён»
     * вернул бы товар ещё на единицу.
     *
     * Возврат из обеих сторон идёт в одной транзакции со сменой
     * статуса: заказ без возврата - это товар, который числится
     * проданным и лежит на складе одновременно.
     *
     * @return bool Статус применён (правда также при неизменном статусе)
     */
    function order_set_status(mysqli $mysql, int $orderId, string $newStatus): bool
    {
        if ($orderId <= 0 || !in_array($newStatus, order_statuses(), true)) {
            return false;
        }

        $stmt = db_prepare($mysql, "SELECT status, assembly_id FROM orders WHERE order_id = ?", 'i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order === null) {
            return false;
        }

        $oldStatus = (string) $order['status'];
        if ($oldStatus === $newStatus) {
            // повторная отправка той же формы: перехода не было
            return true;
        }

        $wasCancelled = $oldStatus === ORDER_STATUS_CANCELLED;
        $nowCancelled = $newStatus === ORDER_STATUS_CANCELLED;

        $delta = 0;
        if (!$wasCancelled && $nowCancelled) {
            $delta = 1;
        } elseif ($wasCancelled && !$nowCancelled) {
            $delta = -1;
        }

        $demand = [];
        if ($delta !== 0) {
            $stmt = db_prepare($mysql, "SELECT * FROM assembly WHERE assembly_id = ?", 'i', (int) $order['assembly_id']);
            $stmt->execute();
            $assemb = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $demand = $assemb === null ? [] : assembly_demand($assemb);

            if ($delta < 0 && stock_shortage($mysql, $demand) !== null) {
                // Снятие отмены не должно упираться в остаток: пока
                // заказ стоял отменённым, товар могли купить. Статус
                // применяем, а списание пропускаем - иначе админ не смог
                // бы вернуть заказ в работу из-за чужой покупки.
                error_log('order ' . $orderId . ': снятие отмены, товара на складе уже нет');
                $demand = [];
            }
        }

        $mysql->begin_transaction();
        try {
            $stmt = db_prepare($mysql, "UPDATE orders SET status = ? WHERE order_id = ?", 'si', $newStatus, $orderId);
            $stmt->execute();
            $stmt->close();

            if ($demand !== []) {
                stock_apply($mysql, $demand, $delta);
            }

            $mysql->commit();
        } catch (Throwable $e) {
            $mysql->rollback();
            error_log('order status change failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}

if ($isAdmin && isset($_POST['editOrderStatus'])) {
    csrf_verify();
    // Номер заказа приходит значением кнопки, а не отдельным полем.
    $orderId = (int) ($_POST['editOrderStatus'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');

    // Старый статус читается до смены - в details журнала он
    // показывает переход from -> to, а не только итог.
    $oldStmt = db_prepare($mysql, "SELECT status FROM orders WHERE order_id = ?", 'i', $orderId);
    $oldStmt->execute();
    $oldStatus = (string) ($oldStmt->get_result()->fetch_row()[0] ?? '');

    if (order_set_status($mysql, $orderId, $status) && $oldStatus !== $status) {
        admin_log($mysql, 'order.status_change', 'order', $orderId, ['from' => $oldStatus, 'to' => $status]);
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

// Контакты и текстовые настройки вкладки «Настройки сайта». Координаты
    // и снимок карты сюда не попадают: их пишет отдельный обработчик
    // saveMapSnapshot, который проверяет PNG.
    //
    // useFaviconVariant означает «сделать только это», поэтому saveSettings
    // пропускается. Гард стоит здесь, а не только порядком блоков: без него
    // saveSettings успевает отредиректить и выти.
    if ($isAdmin && isset($_POST['saveSettings']) && !isset($_POST['useFaviconVariant'])) {
    csrf_verify();

    // Ошибки показываются на странице после редиректа, а не роняют
    // обработку: часть настроек к этому моменту уже может быть записана.
    $errors = [];

    // Белый список: в запрос идут только значения, ключ берётся отсюда.
    $allowed = [
        'contact_phone', 'contact_email',
        'map_address_text',
        'site_name', 'site_description', 'site_founded_year', 'site_home_title',
        'site_logo_url', 'site_favicon_png_url',
        'favicon_letter', 'favicon_bg', 'favicon_text', 'favicon_auto_color',
        // Служебный флаг, см. ветку favicon_upload ниже: только код его
        // пишет, из формы он не приходит.
        'favicon_is_custom',
    ];

    $values = [];
    foreach ($allowed as $key) {
        // Отсутствие поля означает «не менялось», явная пустая строка -
        // «очистить». Иначе любая частичная форма затирала бы остальные
        // настройки пустыми.
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $raw = (string) $_POST[$key];
        // trim убирает случайные пробелы по краям, но не внутренние:
        // телефон и адрес пишутся как человек их ввёл.
        $values[$key] = trim($raw);
    }

    // Пути к картинкам бренда - только внутри сайта. Внешний URL попадает
    // в src логотипа и favicon, то есть в админку на каждой странице.
    // Принимается и абсолютный путь, и относительный без слеша (так
    // хранятся картинки корпусов).
    foreach (['site_logo_url', 'site_favicon_png_url'] as $imgKey) {
        $value = $values[$imgKey] ?? '';
        if ($value === '') {
            continue;
        }
        $normalized = '/' . ltrim($value, '/');
        if (strpos($normalized, '//') === 0
            || strpos($normalized, '/../') !== false
            || preg_match('#^[a-z]+:#i', $normalized) === 1
        ) {
            $values[$imgKey] = '';
            continue;
        }
        $values[$imgKey] = $normalized;
    }

    // Логотип приходит файлом в эту же форму и проходит проверку путей
    // раньше - значит, значение надо подменить здесь, новым.
    $logo = branding_store($_FILES['branding_logo'] ?? null, 'logo', 'site_logo_url');
    if ($logo['url'] !== '') {
        $values['site_logo_url'] = $logo['url'];
    }

    // Загруженный файл важнее сгенерированного. Загрузка и генератор пишут
    // в ОДИН файл assets/images/branding/favicon.png, поэтому
    // favicon_is_custom хранит единственное, что известно о происхождении.
    // Без него обычное сохранение стирало бы загруженную иконку буквой:
    // форма шлёт favicon_letter и favicon_bg всегда, независимо от того,
    // трогал их админ.
    $faviconUpload = store_favicon_upload($_FILES['favicon_upload'] ?? null);
    if ($faviconUpload['ok']) {
        $values['site_favicon_png_url'] = $faviconUpload['url'];
        $values['favicon_is_custom'] = '1';

        // Буква и цвета сохраняются и при загрузке файла, хотя картинка их
        // игнорирует: без этого «Вернуть сгенерированную» нарисовала бы
        // иконку по старым значениям.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        if (favicon_letter_error($letter) === '') {
            $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
            $values['favicon_letter'] = $letter;
            $values['favicon_bg'] = favicon_normalize_bg((string) ($_POST['favicon_bg'] ?? ''));
            $values['favicon_auto_color'] = $auto ? '1' : '0';
            $values['favicon_text'] = $auto ? '' : favicon_normalize_hex((string) ($_POST['favicon_text'] ?? ''), '#000000');
        }
    } elseif ($faviconUpload['error'] !== null) {
        // Файл не подошёл. Генератор намеренно не запускается: иначе админ
        // получил бы иконку с буквой и потерял прежнюю, ничего не поняв.
        $errors[] = $faviconUpload['error'];
    } elseif ((array_key_exists('favicon_letter', $_POST) || array_key_exists('favicon_bg', $_POST))
        && !(favicon_is_custom_now($mysql) && favicon_file_exists())) {
        // Иконки нет или она не своя, и блок иконки в форме трогали -
        // перегенерируем. Блок не отправляли - оставляем иконку как была.
        // При загруженной иконке буква и цвета относятся к картинки,
        // которой сейчас нет, и рисовать её значит тихо выбросить то,
        // что админ загрузил; возвращает букву явная кнопка.
        // favicon_file_exists() - исключение: метка живёт в базе, файл на
        // диске, и они могут разойтись. Тогда терять нечего, а без файла
        // иконка исчезла бы молча.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        $letterError = favicon_letter_error($letter);
        if ($letterError !== '') {
            $errors[] = $letterError;
        } else {
            // При авто цвете хранится пустая строка, а не прежний ручной:
            // иначе после снятия галочки пикер показал бы его же, а не
            // последнее введённое значение.
            //
            // Ручной цвет нормализуется ДО генерации: при пустом поле PNG
            // иначе рисовался бы с авто-контрастом, а в настройку писался
            // бы '#000000', и файл расходился бы с формой.
            $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
            if ($auto) {
                $text = 'auto';
            } else {
                $manual = (string) ($_POST['favicon_text'] ?? '');
                $text = preg_match('/^#[0-9a-fA-F]{6}$/', $manual) === 1 ? $manual : '#000000';
            }

            $path = generate_favicon($letter, (string) ($_POST['favicon_bg'] ?? '#C99CFF'), $text);
            if ($path === null) {
                $errors[] = 'favicon_generate';
            } else {
                $values['site_favicon_png_url'] = $path;
                // Буква и цвета пишутся здесь, а не только из белого списка:
                // там они прошли бы trim без проверки, и в поле цвета
                // попал бы мусор, который input type=color не покажет.
                $values['favicon_letter'] = $letter;
                $bg = (string) ($_POST['favicon_bg'] ?? '');
                $values['favicon_bg'] = preg_match('/^#[0-9a-fA-F]{6}$/', $bg) === 1 ? $bg : '#C99CFF';
                $values['favicon_auto_color'] = $auto ? '1' : '0';
                $values['favicon_text'] = $auto ? '' : $text;
            }
        }
    } elseif (
        favicon_is_custom_now($mysql)
        && favicon_file_exists()
        && (array_key_exists('favicon_letter', $_POST) || array_key_exists('favicon_bg', $_POST))
    ) {
        // Иконка загружена, буква или цвета поменяны. Файл не трогаем, но
        // настройки сохраняем: они понадобятся генератору, когда админ
        // вернётся к букве кнопкой переключения варианта.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        if (favicon_letter_error($letter) === '') {
            $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
            $values['favicon_letter'] = $letter;
            $values['favicon_bg'] = favicon_normalize_bg((string) ($_POST['favicon_bg'] ?? ''));
            $values['favicon_auto_color'] = $auto ? '1' : '0';
            // При авто ручной цвет не пишется: иначе после снятия галочки
            // пикер показал бы его вместо последнего введённого.
            $values['favicon_text'] = $auto ? '' : favicon_normalize_hex((string) ($_POST['favicon_text'] ?? ''), '#000000');
        }
    }

    // Название пустым быть не должно: заголовок вкладки и подпись в шапке
    // останутся без текста.
    if (array_key_exists('site_name', $values) && $values['site_name'] === '') {
        $values['site_name'] = 'Aion Corporation';
    }
    // Год основания: 4 цифры, мусор попал бы прямо в подвал.
    if (array_key_exists('site_founded_year', $values)) {
        $year = $values['site_founded_year'];
        if ($year === '' || preg_match('/^\d{4}$/', $year) !== 1) {
            $values['site_founded_year'] = '2022';
        }
    }
    // Без ссылки отдаются штатные файлы проекта, иначе шапка осталась бы
    // без картинки после неудачной загрузки.
    if (array_key_exists('site_logo_url', $values) && $values['site_logo_url'] === '') {
        $values['site_logo_url'] = '/assets/images/logo.png';
    }
    if (array_key_exists('site_favicon_png_url', $values) && $values['site_favicon_png_url'] === '') {
        $values['site_favicon_png_url'] = '/assets/images/branding/favicon.png';
    }

    site_setting_save($mysql, $values);

    if ($values !== []) {
        // keys - какие ключи реально ушли в базу: $values собирается
        // только из присланных полей, отсутствие в списке = не менялся.
        admin_log($mysql, 'settings.update', 'settings', null, ['keys' => array_keys($values)]);
    }
    if ($logo['url'] !== '' || $faviconUpload['ok']) {
        admin_log($mysql, 'settings.branding_update', 'settings');
    }

    csrf_rotate();
    $location = '/admin.php?tab=settings';
    if ($errors !== []) {
        $location .= '&bad=' . rawurlencode(implode(', ', $errors));
    }
    header('Location: ' . $location);
    exit();
}

// Предпросмотр favicon: ответ здесь не страница, а поток PNG для поля
// формы до нажатия «Сохранить». Файл не пишется - generate_favicon()
// перезаписал бы текущую иконку, и последующее «Сохранить» ничего бы
// не изменило. Рисуем в память тем же кодом, которым сохраняем, тогда
// предпросмотр совпадает с результатом побайтово.
if ($isAdmin && isset($_POST['preview_favicon'])) {
    csrf_verify();

    $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
    $letterError = favicon_letter_error($letter);
    if ($letterError !== '') {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit($letterError);
    }

    // Авто-цвет буквы - та же логика, что в saveSettings.
    $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
    $text = $auto ? 'auto' : (string) ($_POST['favicon_text'] ?? '');

    $bytes = favicon_png_bytes(
        $letter,
        (string) ($_POST['favicon_bg'] ?? '#C99CFF'),
        $text
    );
    if ($bytes === null) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('favicon_generate');
    }

    // no-store обязателен: иначе браузер закэширует превью и второй
    // предпросмотр с другой буквой показал бы прежнюю картинку.
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo $bytes;
    exit();
}

// Переключение между двумя вариантами иконки.
//
// Отдельное действие, а не следствие «Сохранить»: пока активна загруженная
// картинка, генератор молчит, иначе он затирал бы её при каждом
// сохранении. Копирование варианта, а не перерисовка: PNG уже лежит рядом.
//
// Значение сверяется со списком, а не идёт в имя файла: строка из POST
// не должна становиться частью пути.
if ($isAdmin && isset($_POST['useFaviconVariant'])) {
    csrf_verify();

    require_once __DIR__ . '/modules/site.php';

    $which = (string) ($_POST['useFaviconVariant'] ?? '');
    if ($which !== 'generated' && $which !== 'custom') {
        csrf_rotate();
        header('Location: /admin.php?tab=settings&bad=favicon_variant');
        exit();
    }

    $url = favicon_activate($which);
    if ($url !== null) {
        site_setting_save($mysql, [
            'site_favicon_png_url' => $url,
            'favicon_is_custom' => $which === 'custom' ? '1' : '0',
        ]);
        // variant - какой из двух вариантов включён: настройка меняется
        // и здесь, и в saveSettings, и без variant в журнале эти
        // переходы неразличимы.
        admin_log($mysql, 'settings.favicon_variant', 'settings', null, ['variant' => $which]);
    }

    // Ротация обязательна: без неё следующий «Сохранить» получил бы 403,
    // и ошибка выглядела бы как «страница сломалась».
    csrf_rotate();

    header('Location: /admin.php?tab=settings&' . ($url !== null
        ? 'saved=1'
        : 'bad=favicon_variant'));
    exit();
}
// Конфигуратор: пресеты бюджета и операционные системы.
//
// Два независимых обработчика (presetAction и osAction), а не один с
// полем-типом: наборы полей и проверки у сущностей разные, и общий
// обработчик разросся бы в ветки «а что мы вообще правим».

/**
 * Проверка строки пресета из POST. Возвращает список кодов ошибок.
 *
 * @param array<string, mixed> $post
 * @return string[]
 */
function cfg_bad_preset(array $post): array
{
    $bad = [];

    // Галочка не присылается, когда снята: отсутствие поля и есть «нет».
    $name = trim((string) ($post['preset_name'] ?? ''));
    $budget = (int) ($post['preset_budget'] ?? 0);
    $icon = trim((string) ($post['preset_icon'] ?? ''));
    $active = !empty($post['is_active']) ? 1 : 0;

    if ($name === '') {
        $bad[] = 'preset_name';
    } elseif (mb_strlen($name, 'UTF-8') > 50) {
        $bad[] = 'preset_name_long';
    }

    // Нижняя граница 1000, а не 1: конфигуратор подбирает железо на
    // проценты от бюджета, и при тысяче рублей не набирается даже
    // процессор - сборка выходит пустой и деньги списаны в никуда.
    if ($budget < 1000 || $budget > 10000000) {
        $bad[] = 'preset_budget';
    }

    // Иконка сверяется с белым списком, а не ищется в массиве: строка
    // из POST не должна попасть в вывод разметки как есть. Без
    // проверки в пресет записался бы ключ вида '"><script>'.
    if (!array_key_exists($icon, cfg_preset_icons())) {
        $bad[] = 'preset_icon';
        $icon = 'monitor';
    }

    return $bad;
}

if ($isAdmin && isset($_POST['presetAction'])) {
    csrf_verify();

    $presetAction = (string) $_POST['presetAction'];
    $presetId = (int) ($_POST['presetId'] ?? 0);

    if ($presetAction === 'delete') {
        if ($presetId > 0) {
            // Имя до удаления - в журнале остаётся читаемая копия.
            $nameStmt = db_prepare($mysql, 'SELECT preset_name FROM configurator_presets WHERE preset_id = ?', 'i', $presetId);
            $nameStmt->execute();
            $delName = (string) ($nameStmt->get_result()->fetch_row()[0] ?? '');

            $stmt = db_prepare($mysql, 'DELETE FROM configurator_presets WHERE preset_id = ?', 'i', $presetId);
            $stmt->execute();
            admin_log($mysql, 'preset.delete', 'preset', $presetId, ['name' => $delName]);
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&saved=1');
        } else {
            // presetId = 0 означал бы «удалить строку, которой нет»
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&bad=preset_not_found');
        }
        exit();
    }

    if ($presetAction !== 'save') {
        csrf_rotate();
        header('Location: /admin.php?tab=configurator&bad=preset_action');
        exit();
    }

    $presetBad = cfg_bad_preset($_POST);
    if ($presetBad !== []) {
        csrf_rotate();
        header('Location: /admin.php?tab=configurator&bad=' . implode(',', array_unique($presetBad)));
        exit();
    }

    $name = trim((string) $_POST['preset_name']);
    $budget = (int) $_POST['preset_budget'];
    $icon = trim((string) $_POST['preset_icon']);
    $active = !empty($_POST['is_active']) ? 1 : 0;

    // Редактирование несуществующей строки сообщает об ошибке, а не
    // молча создаёт новую: иначе рассинхронизация между id в форме и
    // строкой в таблице выглядела бы как «изменения не сохранились».
    // Флаг фиксируется ДО ветвления: в INSERT-ветви $presetId
    // перезаписывается insert_id, и по нему создание не отличить.
    $presetIsNew = $presetId <= 0;

    if ($presetId > 0) {
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM configurator_presets WHERE preset_id = ?', 'i', $presetId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&bad=preset_not_found');
            exit();
        }

        $stmt = db_prepare(
            $mysql,
            'UPDATE configurator_presets
                SET preset_name = ?, preset_budget = ?, preset_icon = ?, is_active = ?
              WHERE preset_id = ?',
            'sisii',
            $name,
            $budget,
            $icon,
            $active,
            $presetId
        );
        $stmt->execute();
    } else {
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO configurator_presets (preset_name, preset_budget, preset_icon, is_active)
             VALUES (?, ?, ?, ?)',
            'sisi',
            $name,
            $budget,
            $icon,
            $active
        );
        $stmt->execute();
        $presetId = (int) $mysql->insert_id;
    }

    admin_log(
        $mysql,
        $presetIsNew ? 'preset.create' : 'preset.update',
        'preset',
        $presetId,
        ['name' => $name]
    );

    csrf_rotate();
    header('Location: /admin.php?tab=configurator&saved=1');
    exit();
}

/**
 * Проверка строки ОС из POST. Возвращает список кодов ошибок.
 *
 * @param array<string, mixed> $post
 * @return string[]
 */
function cfg_bad_os(array $post): array
{
    $bad = [];

    $name = trim((string) ($post['os_name'] ?? ''));
    // Отрицательная цена осмысленна как «минус от бюджета» только
    // теоретически: под неё нет ни одной проверки ниже по коду, и
    // сборка уехала бы в минус. Поэтому ноль - минимум.
    $price = (int) ($post['os_price'] ?? 0);

    if ($name === '') {
        $bad[] = 'os_name';
    } elseif (mb_strlen($name, 'UTF-8') > 100) {
        $bad[] = 'os_name_long';
    }

    if ($price < 0 || $price > 1000000) {
        $bad[] = 'os_price';
    }

    return $bad;
}

if ($isAdmin && isset($_POST['osAction'])) {
    csrf_verify();

    $osAction = (string) $_POST['osAction'];
    $osId = (int) ($_POST['osId'] ?? 0);

    if ($osAction === 'delete') {
        if ($osId > 0) {
            // Имя до удаления - в журнале остаётся читаемая копия.
            $nameStmt = db_prepare($mysql, 'SELECT os_name FROM configurator_os WHERE os_id = ?', 'i', $osId);
            $nameStmt->execute();
            $delName = (string) ($nameStmt->get_result()->fetch_row()[0] ?? '');

            $stmt = db_prepare($mysql, 'DELETE FROM configurator_os WHERE os_id = ?', 'i', $osId);
            $stmt->execute();
            admin_log($mysql, 'os.delete', 'os', $osId, ['name' => $delName]);
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&saved=1');
        } else {
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&bad=os_not_found');
        }
        exit();
    }

    if ($osAction !== 'save') {
        csrf_rotate();
        header('Location: /admin.php?tab=configurator&bad=os_action');
        exit();
    }

    $osBad = cfg_bad_os($_POST);
    if ($osBad !== []) {
        csrf_rotate();
        header('Location: /admin.php?tab=configurator&bad=' . implode(',', array_unique($osBad)));
        exit();
    }

    $name = trim((string) $_POST['os_name']);
    $price = (int) $_POST['os_price'];
    $active = !empty($_POST['is_active']) ? 1 : 0;

    // Тот же приём, что у пресетов: флаг до ветвления, INSERT
    // перезапишет $osId через insert_id.
    $osIsNew = $osId <= 0;

    if ($osId > 0) {
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM configurator_os WHERE os_id = ?', 'i', $osId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=configurator&bad=os_not_found');
            exit();
        }

        $stmt = db_prepare(
            $mysql,
            'UPDATE configurator_os
                SET os_name = ?, os_price = ?, is_active = ?
              WHERE os_id = ?',
            'siii',
            $name,
            $price,
            $active,
            $osId
        );
        $stmt->execute();
    } else {
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO configurator_os (os_name, os_price, is_active)
             VALUES (?, ?, ?)',
            'sii',
            $name,
            $price,
            $active
        );
        $stmt->execute();
        $osId = (int) $mysql->insert_id;
    }

    admin_log(
        $mysql,
        $osIsNew ? 'os.create' : 'os.update',
        'os',
        $osId,
        ['name' => $name]
    );

    csrf_rotate();
    header('Location: /admin.php?tab=configurator&saved=1');
    exit();
}

// Сборки витрины: добавление, правка и удаление. Отдельная форма с
// полем assemblyAction по той же причине, что у пресетов и соцсетей:
// набор полев свой (девять списков комплектующих), и общая форма
// означала бы девять скрытых полей с одним id в общей модалке.

/**
 * Проверка сборки из POST. Возвращает список кодов ошибок.
 *
 * @param array<string, mixed> $post
 * @return string[]
 */
function cfg_bad_assembly(array $post): array
{
    $bad = [];

    $name = trim((string) ($post['assembly_name'] ?? ''));
    // Два символа - граница, а не опечатка: одна буква не имя, а в
    // списке на главной выглядит как сбой вывода.
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
        $bad[] = 'name';
    }

    // Колонка varchar(30), и обрезка на уровне базы молча отрезала бы
    // лишнее - админ увидел бы не то, что вводил.
    $tag = trim((string) ($post['assembly_tag'] ?? ''));
    if (mb_strlen($tag, 'UTF-8') > 30) {
        $bad[] = 'tag';
    }

    if ((int) ($post['assembly_price'] ?? 0) < 0 || (int) ($post['assembly_price'] ?? 0) > 10000000) {
        $bad[] = 'price';
    }

    foreach (assembly_required_slots() as $slot) {
        $categoryId = assembly_slots()[$slot] ?? 0;
        if ((int) ($post['comp_' . $categoryId] ?? 0) <= 0) {
            $bad[] = $slot === 'cpu_id' ? 'cpu_required' : 'case_required';
        }
    }

    return $bad;
}

/**
 * Проверить, что каждый компонент попал в слот своей категории.
 *
 * Без неё в слот корпуса можно положить процессор: форма шлёт любой
 * component_id, обработчик верит, а картинка на главной берётся из
 * case_id - то есть с картинкой процессора. Проверка по всем слотам
 * одной выборкой, а не девятью запросами.
 *
 * $parts - компоненты по именам колонок, как их читает обработчик.
 * Ожидаемая категория берётся из assembly_slots(), а не из ключа
 * массива: там колонка 'cpu_id', и сравнение с category_id из базы
 * отвергало бы любую сборку.
 *
 * @param array<string, int> $parts колонка assembly => component_id
 */
function cfg_assembly_parts_in_category(mysqli $mysql, array $parts): bool
{
    $slots = assembly_slots();

    // component_id => category_id, в которой он должен стоять
    $expected = [];
    foreach ($parts as $column => $componentId) {
        if ($componentId > 0) {
            $expected[$componentId] = $slots[$column] ?? 0;
        }
    }

    if ($expected === []) {
        return true;
    }

    $ids = array_keys($expected);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db_prepare(
        $mysql,
        "SELECT component_id, category_id FROM components WHERE component_id IN ($ph)",
        str_repeat('i', count($ids)),
        ...$ids
    );
    $stmt->execute();

    $found = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $found[(int) $row['component_id']] = (int) $row['category_id'];
    }
    $stmt->close();

    foreach ($expected as $componentId => $categoryId) {
        // Компонента нет в таблице - тоже отказ: внешний ключ его не примет.
        // Сюда же попадает один компонент в двух слотах: последняя
        // категория не совпадёт с первой, и сборка будет отвергнута.
        if (!isset($found[$componentId]) || $found[$componentId] !== $categoryId) {
            return false;
        }
    }

    return true;
}

if ($isAdmin && isset($_POST['assemblyAction'])) {
    csrf_verify();

    $assemblyAction = (string) $_POST['assemblyAction'];
    $assemblyId = (int) ($_POST['assemblyId'] ?? 0);
    $asmSlots = assembly_slots();

    if ($assemblyAction === 'delete') {
        if ($assemblyId <= 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=assemblies&bad=not_found');
            exit();
        }

        // Сборку из заказа или из избранного удалять нельзя: на неё ссылается
        // внешний ключ, и MySQL ответил бы кодом 1451 без объяснения.
        $stmt = db_prepare(
            $mysql,
            'SELECT (SELECT COUNT(*) FROM orders WHERE assembly_id = ?) AS o,
                    (SELECT COUNT(*) FROM favorites WHERE assembly_id = ?) AS f',
            'ii',
            $assemblyId,
            $assemblyId
        );
        $stmt->execute();
        $usage = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) ($usage['o'] ?? 0) > 0 || (int) ($usage['f'] ?? 0) > 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=assemblies&error=used');
            exit();
        }

        // Имя до удаления - в журнале остаётся читаемая копия.
        $nameStmt = db_prepare($mysql, 'SELECT assembly_name FROM assembly WHERE assembly_id = ?', 'i', $assemblyId);
        $nameStmt->execute();
        $delName = (string) ($nameStmt->get_result()->fetch_row()[0] ?? '');

        $stmt = db_prepare($mysql, 'DELETE FROM assembly WHERE assembly_id = ?', 'i', $assemblyId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted > 0) {
            admin_log($mysql, 'assembly.delete', 'assembly', $assemblyId, ['name' => $delName]);
        }

        csrf_rotate();
        header('Location: /admin.php?tab=assemblies&' . ($deleted > 0 ? 'saved=1' : 'bad=not_found'));
        exit();
    }

    if ($assemblyAction !== 'save') {
        csrf_rotate();
        header('Location: /admin.php?tab=assemblies&bad=action');
        exit();
    }

    $assemblyBad = cfg_bad_assembly($_POST);
    if ($assemblyBad !== []) {
        csrf_rotate();
        header('Location: /admin.php?tab=assemblies&bad=' . implode(',', array_unique($assemblyBad)));
        exit();
    }

    // Состав собирается по списку слотов: имя поля в форме и колонка в
    // базе связаны одним списком assembly_slots(), и разойтись они
    // могут только в двух разных файлах.
    $assemblyName = trim((string) $_POST['assembly_name']);
    $assemblyTag = trim((string) ($_POST['assembly_tag'] ?? ''));
    $assemblyTag = $assemblyTag === '' ? null : $assemblyTag;
    $assemblyPrice = (int) $_POST['assembly_price'];
    $assemblyParts = [];
    foreach ($asmSlots as $column => $categoryId) {
        $assemblyParts[$column] = (int) ($_POST['comp_' . $categoryId] ?? 0);
    }

    // Операционная система приходит id, а пишется названием: колонка
    // assembly.os - varchar, и такое же значение туда кладёт
    // конфигуратор. Id в колонке смешал бы два формата, а читают
    // assembly.os ещё страница сборки и карточка на главной.
    // Название перечитывается из базы, а не берётся из формы: цену и
    // видимость решает админ во вкладке конфигуратора, и форма не
    // должна иметь возможность подставить выключенную или удалённую ОС.
    $assemblyOsName = null;
    $assemblyOsId = (int) ($_POST['os_id'] ?? 0);
    if ($assemblyOsId > 0) {
        $stmt = db_prepare(
            $mysql,
            'SELECT os_name FROM configurator_os WHERE os_id = ? AND is_active = 1',
            'i',
            $assemblyOsId
        );
        $stmt->execute();
        $osRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($osRow === null) {
            csrf_rotate();
            header('Location: /admin.php?tab=assemblies&bad=os_not_found');
            exit();
        }
        $assemblyOsName = (string) $osRow['os_name'];
    }

    if (!cfg_assembly_parts_in_category($mysql, $assemblyParts)) {
        csrf_rotate();
        header('Location: /admin.php?tab=assemblies&bad=comp_category');
        exit();
    }

    if ($assemblyId > 0) {
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM assembly WHERE assembly_id = ?', 'i', $assemblyId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=assemblies&bad=not_found');
            exit();
        }
    }

    // Ноль в слоте - «не выбрано», пишется как NULL: колонки nullable, и
    // сборка без видеокарты обычное дело. Ноль означал бы компонент с
    // id = 0, которого нет, и внешний ключ его не принял бы.
    $partValues = [];
    $partTypes = '';
    foreach ($asmSlots as $column => $categoryId) {
        $partValues[] = $assemblyParts[$column] > 0 ? $assemblyParts[$column] : null;
        $partTypes .= 'i';
    }

    $partSet = implode(', ', array_map(static fn(string $c): string => "`$c` = ?", array_keys($asmSlots)));

    // Флаг до ветвления: INSERT ниже перезапишет $assemblyId через
    // insert_id, и по нему создание не отличить.
    $assemblyIsNew = $assemblyId <= 0;

    if ($assemblyId > 0) {
        // Значения собираются в один массив: в PHP нельзя передать
        // позиционный аргумент после распаковки, а номер сборки идёт
        // последним - после девяти слотов и ОС.
        $stmt = db_prepare(
            $mysql,
            "UPDATE assembly
                SET assembly_name = ?, assembly_tag = ?, assembly_price = ?, os = ?, $partSet, is_base = 1
              WHERE assembly_id = ?",
            'ssis' . $partTypes . 'i',
            ...array_merge([$assemblyName, $assemblyTag, $assemblyPrice, $assemblyOsName], $partValues, [$assemblyId])
        );
        $stmt->execute();
    } else {
        $partCols = implode(', ', array_map(static fn(string $c): string => "`$c`", array_keys($asmSlots)));
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO assembly (assembly_name, assembly_tag, assembly_price, os, $partCols, is_base)
             VALUES (?, ?, ?, ?, " . implode(', ', array_fill(0, count($partValues), '?')) . ', 1)',
            'ssis' . $partTypes,
            ...array_merge([$assemblyName, $assemblyTag, $assemblyPrice, $assemblyOsName], $partValues)
        );
        $stmt->execute();
        $assemblyId = (int) $mysql->insert_id;
    }

    admin_log(
        $mysql,
        $assemblyIsNew ? 'assembly.create' : 'assembly.update',
        'assembly',
        $assemblyId,
        ['name' => $assemblyName]
    );

    csrf_rotate();
    header('Location: /admin.php?tab=assemblies&saved=1');
    exit();
}

// Соцсети: сохранение и удаление строк social_links. Отдельная форма с
// полем socialAction, а не часть формы настроек: свой multipart, а
// вложенные формы в HTML недопустимы.
if ($isAdmin && isset($_POST['socialAction'])) {
    csrf_verify();

    $socialAction = (string) $_POST['socialAction'];
    $socialLinkId = (int) ($_POST['linkId'] ?? 0);

    // Ошибки копятся и показываются разом: одна неудачная отправка не
    // должна оставлять админа с одним из десяти жалоб подряд.
    $socialBad = [];

    if ($socialAction === 'delete') {
        if ($socialLinkId > 0) {
            // Имя до удаления - в журнале остаётся читаемая копия.
            $nameStmt = db_prepare($mysql, 'SELECT link_name FROM social_links WHERE link_id = ?', 'i', $socialLinkId);
            $nameStmt->execute();
            $delName = (string) ($nameStmt->get_result()->fetch_row()[0] ?? '');

            $stmt = db_prepare($mysql, 'DELETE FROM social_links WHERE link_id = ?', 'i', $socialLinkId);
            $stmt->execute();
            admin_log($mysql, 'social.delete', 'social', $socialLinkId, ['name' => $delName]);
        } else {
            // linkId = 0 - «удалить строку, которой нет».
            $socialBad[] = 'social_not_found';
        }

        if ($socialBad === []) {
            csrf_rotate();
            header('Location: /admin.php?tab=settings&saved=1');
            exit();
        }

        csrf_rotate();
        header('Location: /admin.php?tab=settings&bad=' . implode(',', $socialBad));
        exit();
    }

    if ($socialAction !== 'save') {
        csrf_rotate();
        header('Location: /admin.php?tab=settings&bad=social_action');
        exit();
    }

    require_once __DIR__ . '/modules/image.php';

    $socialName = trim((string) ($_POST['link_name'] ?? ''));
    $socialUrl = trim((string) ($_POST['link_url'] ?? ''));
    // Галочка не присылается, когда снята: отсутствие поля и есть «нет».
    $socialActive = !empty($_POST['is_active']) ? 1 : 0;

    if ($socialName === '') {
        $socialBad[] = 'social_name';
    } elseif (mb_strlen($socialName, 'UTF-8') > 50) {
        $socialBad[] = 'social_name_long';
    }

    // Схема http(s) обязательна. Без проверки в поле «Ссылка» попал бы
    // javascript:alert(1), и он исполнился бы по клику в блоке
    // соцсетей - это тот же XSS, только через админку.
    if ($socialUrl === '' || preg_match('#^https?://#i', $socialUrl) !== 1) {
        $socialBad[] = 'social_url';
    } elseif (mb_strlen($socialUrl, 'UTF-8') > 255) {
        $socialBad[] = 'social_url_long';
    }

    // --- иконка ---
    //
    // Сначала загруженный файл, потом выбор из списка: загруженная
    // иконка должна перекрывать предустановленную, иначе админ
    // загрузил бы файл и удивился, что сохранилась старая.
    $socialIcon = '';

    $socialUpload = $_FILES['link_icon_upload'] ?? null;
    if (is_array($socialUpload) && ($socialUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $socialIcon = social_icon_store_upload($socialUpload);
        if ($socialIcon === '') {
            $socialBad[] = 'social_icon_upload';
        }
    }

    if ($socialIcon === '') {
        $socialIcon = trim((string) ($_POST['link_icon'] ?? ''));
        // Путь сверяется с белым списком каталогов, а не ищется на диске:
        // иначе в link_icon можно было бы записать /etc/passwd, и он
        // пошёл бы дальше в атрибут src на главной.
        if (preg_match('#^/assets/(images/social/[a-z0-9_.-]+\.svg|uploads/social/[a-z0-9_.-]+\.(?:svg|png|webp))$#i', $socialIcon) !== 1) {
            $socialIcon = '';
            $socialBad[] = ($socialBad === [] ? 'social_icon' : 'social_icon_unsafe');
        }
    }

    if ($socialIcon === '') {
        $socialBad[] = 'social_icon';
    }

    if ($socialBad !== []) {
        csrf_rotate();
        header('Location: /admin.php?tab=settings&bad=' . implode(',', array_unique($socialBad)));
        exit();
    }

    // Правка несуществующей строки сообщает об ошибке, а не молча создаёт
    // новую: иначе рассинхронизация между id в форме и строкой в таблице
    // выглядела бы как «изменения не сохранились».
    // Флаг до ветвления: INSERT ниже перезапишет id через insert_id.
    $socialIsNew = $socialLinkId <= 0;

    if ($socialLinkId > 0) {
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM social_links WHERE link_id = ?', 'i', $socialLinkId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=settings&bad=social_not_found');
            exit();
        }

        $stmt = db_prepare(
            $mysql,
            'UPDATE social_links
                SET link_name = ?, link_url = ?, link_icon = ?, is_active = ?
              WHERE link_id = ?',
            'sssii',
            $socialName,
            $socialUrl,
            $socialIcon,
            $socialActive,
            $socialLinkId
        );
        $stmt->execute();
    } else {
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO social_links (link_name, link_url, link_icon, is_active)
             VALUES (?, ?, ?, ?)',
            'sssi',
            $socialName,
            $socialUrl,
            $socialIcon,
            $socialActive
        );
        $stmt->execute();
        $socialLinkId = (int) $mysql->insert_id;
    }

    admin_log(
        $mysql,
        $socialIsNew ? 'social.create' : 'social.update',
        'social',
        $socialLinkId,
        ['name' => $socialName]
    );

    // Ротация обязательна: токен одноразовый в рамках загрузки страницы,
    // и без неё следующая отправка получила бы 403.
    csrf_rotate();
    header('Location: /admin.php?tab=settings&saved=1');
    exit();
}

// Загруженная иконка соцсети -> путь от корня сайта.
//
// Отдельная функция, а не тело обработчика: ею же пользуется проверка.
//
// Имя файла генерируется, а не берётся из формы: имя из POST может
// содержать «../» и записать файл вне uploads.
if (!function_exists('social_icon_store_upload')) {
    /**
     * @param array|null $file элемент из $_FILES
     * @return string путь вида /assets/uploads/social/xxx.svg или '' при ошибке
     */
    function social_icon_store_upload(?array $file): string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return '';
        }

        // 500 КБ: иконка рисуется в 24x24, крупнее бессмысленно, а лимит
        // защищает uploads от случайно загруженного архива.
        if (filesize($tmp) > 512000) {
            return '';
        }

        require_once __DIR__ . '/image.php';
        $mime = detect_image_mime($tmp);
        $extByMime = [
            'image/svg+xml' => 'svg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extByMime[$mime])) {
            return '';
        }
        $ext = $extByMime[$mime];

        $dir = __DIR__ . '/../assets/uploads/social';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        $bytes = (string) @file_get_contents($tmp);

        if ($ext === 'svg') {
            $bytes = social_icon_sanitize_svg($bytes);
            if ($bytes === '') {
                return '';
            }
        }

        $name = 'icon-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $bytes) === false) {
            return '';
        }

        return '/assets/uploads/social/' . $name;
    }
}

// Удаление из SVG всего, что умеет выполняться.
//
// Файл кладётся в uploads и отдаётся с того же домена. Через <img> скрипт
// внутри не исполнится, но при прямом открытии адреса браузер выполнит
// его от имени того, кто открыл, - а файл доступен всем.
if (!function_exists('social_icon_sanitize_svg')) {
    function social_icon_sanitize_svg(string $svg): string
    {
        if ($svg === '' || stripos($svg, '<svg') === false) {
            return '';
        }

        $patterns = [
            '#<script\b.*?</script>#is',
            '#<foreignObject\b.*?</foreignObject>#is',
            '#<(iframe|object|embed|use|set|animate)\b[^>]*/?>#is',
            '#\son[a-z]+\s*=\s*"[^"]*"#i',
            "#\son[a-z]+\s*=\s*'[^']*'#i",
            '#javascript\s*:#i',
        ];
        foreach ($patterns as $pattern) {
            $svg = (string) preg_replace($pattern, '', $svg);
        }

        return trim($svg);
    }
}


// Приём снимка карты: в POST приходит data:image/png;base64,... из
// html2canvas. Данные из браузера, поэтому проверяется всё, на что можно
// опереться: форма префикса, результат base64_decode, сигнатура PNG и
// размер. Файл пишется под фиксированным именем - имя из POST не
// используется принципиально.
if ($isAdmin && isset($_POST['saveMapSnapshot'])) {
    csrf_verify();

    $dataUrl = (string) ($_POST['map_snapshot'] ?? '');
    $address = trim((string) ($_POST['map_address_text'] ?? ''));
    $lat = (float) ($_POST['map_lat'] ?? 0);
    $lng = (float) ($_POST['map_lng'] ?? 0);

    // Проверки до любого вывода: header() после начала вывода не сработает.
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        http_response_code(400);
        exit('Invalid image format');
    }

    $binary = base64_decode($m[1], true);
    if ($binary === false || $binary === '') {
        http_response_code(400);
        exit('Invalid base64 payload');
    }

    // Снимок делается с scale:2 в контейнере примерно 760x400, то есть
    // около 6 МБ. Потолок 8 МБ не мешает нормальному снимку и не даёт
    // телу POST выесть память сервера.
    if (strlen($binary) > 8 * 1024 * 1024) {
        http_response_code(400);
        exit('Image too large');
    }

    // base64 можно подделать, а вот эти восемь байт обязаны стоять в
    // начале настоящего PNG.
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        http_response_code(400);
        exit('Not a PNG');
    }

    // За пределами Земли таких координат не бывает, а мусор в базе не нужен.
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        http_response_code(400);
        exit('Invalid coordinates');
    }

    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('Upload directory is not available');
    }

    $path = $dir . '/site-map.png';
    // Пишем во временный файл и переименовываем: частичная запись оставила
    // бы битую картинку, а rename в пределах каталога атомарен.
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $binary, LOCK_EX) === false) {
        @unlink($tmp);
        http_response_code(500);
        exit('Failed to write snapshot');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        http_response_code(500);
        exit('Failed to replace snapshot');
    }
    @chmod($path, 0644);

    // Версия в URL - иначе браузер покажет старый снимок из кеша.
    $version = (string) @filemtime($path);

    site_setting_save($mysql, [
        'map_snapshot_url' => '/assets/uploads/site-map.png?v=' . $version,
        'map_address_text' => $address,
        'map_lat'          => (string) $lat,
        'map_lng'          => (string) $lng,
        // Масштаб закрепляем за снимком: 15 - то, чем снимали, иначе
        // настройка расходилась бы с картинкой.
        'map_zoom'         => '15',
    ]);

    admin_log($mysql, 'settings.map_update', 'settings');

    csrf_rotate();
    header('Location: /admin.php?tab=settings');
    exit();
}

// Смена статуса из модалки заказа. Логика общая с обработчиком
// editOrderStatus выше: обе формы обязаны возвращать товар на склад,
// иначе отмена через одну из них оставляла бы заказ списанным.
if ($isAdmin && isset($_POST['editOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');

    // Тот же приём, что в editOrderStatus: from/to до смены.
    $oldStmt = db_prepare($mysql, "SELECT status FROM orders WHERE order_id = ?", 'i', $orderId);
    $oldStmt->execute();
    $oldStatus = (string) ($oldStmt->get_result()->fetch_row()[0] ?? '');

    if (order_set_status($mysql, $orderId, $status) && $oldStatus !== $status) {
        admin_log($mysql, 'order.status_change', 'order', $orderId, ['from' => $oldStatus, 'to' => $status]);
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

// Редактирование профиля пользователя из модалки. Имя 2-20 символов
// (колонка varchar(20)), группа из белого списка. Разжаловать себя нельзя:
// иначе админ теряет доступ к панели.
if ($isAdmin && isset($_POST['editUser'])) {
    csrf_verify();
    $editUserId = (int) ($_POST['editUserId'] ?? 0);
    $editName = trim($_POST['user_name'] ?? '');
    // Фамилия не обязательна, но в varchar(30) длинное значение не влезет,
    // поэтому длина проверяется всё равно.
    $editSurname = trim($_POST['user_surname'] ?? '');
    $editGroup = $_POST['user_group'] ?? '';
    // user_address не обновляется: это legacy-строка из миграции.
    $editPostal = trim($_POST['user_postal_code'] ?? '');
    $editRegion = trim($_POST['user_region'] ?? '');
    $editCity = trim($_POST['user_city'] ?? '');
    $editStreet = trim($_POST['user_street'] ?? '');
    $editHouse = trim($_POST['user_house'] ?? '');
    $editApartment = trim($_POST['user_apartment'] ?? '');
    $editPhone = trim($_POST['user_number'] ?? '');

    // Ошибки возвращаем на ту же вкладку с сообщением.
    $fail = static function (string $code): void {
        header('Location: ' . admin_list_url('users', $code));
        exit();
    };

    $nameLen = mb_strlen($editName, 'UTF-8');
    if ($nameLen < 2 || $nameLen > 20) {
        $fail('name');
    }
    if (!in_array($editGroup, ['user', 'admin'], true)) {
        $fail('group');
    }
    if (mb_strlen($editSurname, 'UTF-8') > 30) {
        $fail('surname');
    }
    // Адресные поля необязательны: у части пользователей адреса нет
    // вовсе, и пустое значение пишется в NULL.
    if ($editCity !== '' && mb_strlen($editCity, 'UTF-8') > 100) {
        $fail('city');
    }
    if ($editRegion !== '' && mb_strlen($editRegion, 'UTF-8') > 100) {
        $fail('region');
    }
    if ($editStreet !== '' && mb_strlen($editStreet, 'UTF-8') > 150) {
        $fail('street');
    }
    if (mb_strlen($editHouse, 'UTF-8') > 20) {
        $fail('house');
    }
    if (mb_strlen($editApartment, 'UTF-8') > 20) {
        $fail('apartment');
    }
    // Индекс: 5-10 цифр, пустое значение допустимо.
    if ($editPostal !== '' && !preg_match('/^\d{5,10}$/', $editPostal)) {
        $fail('postal');
    }
    if ($editUserId > 0 && $editUserId === (int) ($_SESSION['user_id'] ?? 0) && $editGroup !== 'admin') {
        $fail('self-demote');
    }
    if ($editPhone !== '' && !preg_match('/^\+7\s?[\(]{0,1}\d{3}[\)]{0,1}\s?\d{3}[\-]{0,1}\d{2}[\-]{0,1}\d{2}$/', $editPhone)) {
        $fail('phone');
    }

    if ($editUserId > 0) {
        // Существующий ли пользователь: иначе UPDATE молча затронет 0 строк.
        // Логин читается здесь же - он идёт в журнал, а не только проверка.
        $check = db_prepare($mysql, "SELECT user_login FROM users WHERE user_id = ?", "i", $editUserId);
        $check->execute();
        $checkRow = $check->get_result()->fetch_assoc();
        if (!$checkRow) {
            $fail('missing');
        }
        $editLogin = (string) ($checkRow['user_login'] ?? '');

        // user_address в UPDATE не участвует: legacy остаётся как есть.
        $stmt = db_prepare($mysql, "UPDATE users SET user_name = ?, user_surname = ?, user_group = ?,
                                       user_postal_code = ?, user_region = ?, user_city = ?, user_street = ?,
                                       user_house = ?, user_apartment = ?, user_number = ?
                                       WHERE user_id = ?", "ssssssssssi",
                        $editName,
                        $editSurname !== '' ? $editSurname : null,
                        $editGroup,
                        $editPostal !== '' ? $editPostal : null,
                        $editRegion !== '' ? $editRegion : null,
                        $editCity !== '' ? $editCity : null,
                        $editStreet !== '' ? $editStreet : null,
                        $editHouse !== '' ? $editHouse : null,
                        $editApartment !== '' ? $editApartment : null,
                        $editPhone !== '' ? $editPhone : null,
                        $editUserId);
        $stmt->execute();
        admin_log($mysql, 'user.update', 'user', $editUserId, ['login' => $editLogin]);
    } else {
        $fail('missing');
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

// Подтверждение верификации контактов администратором.
//
// requested = 1 в WHERE - двойная защита: кнопка в модалке активна
// только при заявке, но это интерфейс, и прямой POST обязан быть отбит.
// После UPDATE заявка снимается, иначе повторное нажатие снова дало бы
// UPDATE, а в модалке остался бы бейдж.
if ($isAdmin && isset($_POST['approveEmail'])) {
    csrf_verify();
    $userId = (int) ($_POST['userId'] ?? 0);

    if ($userId > 0) {
        // Логин для журнала читается до UPDATE: он нужен в details,
        // а после approve строка уже выглядит по-другому.
        $who = db_prepare($mysql, "SELECT user_login FROM users WHERE user_id = ?", "i", $userId);
        $who->execute();
        $whoLogin = (string) ($who->get_result()->fetch_row()[0] ?? '');

        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `email_verified` = 1, `email_verification_requested` = 0
              WHERE `user_id` = ? AND `email_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
        // 0 строк = заявки не было: прямой POST без запроса не должен
        // оставлять в журнале несуществующее подтверждение.
        if ($stmt->affected_rows > 0) {
            admin_log($mysql, 'user.approve_email', 'user', $userId, ['login' => $whoLogin]);
        }
        $stmt->close();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

if ($isAdmin && isset($_POST['approvePhone'])) {
    csrf_verify();
    $userId = (int) ($_POST['userId'] ?? 0);

    if ($userId > 0) {
        // Логин для журнала читается до UPDATE - как в approveEmail.
        $who = db_prepare($mysql, "SELECT user_login FROM users WHERE user_id = ?", "i", $userId);
        $who->execute();
        $whoLogin = (string) ($who->get_result()->fetch_row()[0] ?? '');

        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `phone_verified` = 1, `phone_verification_requested` = 0
              WHERE `user_id` = ? AND `phone_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            admin_log($mysql, 'user.approve_phone', 'user', $userId, ['login' => $whoLogin]);
        }
        $stmt->close();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

// Удаление заказа. Проверок использования нет: на orders ссылается
// только сам заказ, других таблиц с FK на неё нет.
if ($isAdmin && isset($_POST['deleteOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);

    if ($orderId > 0) {
        // Проверка существования: DELETE молча затрагивает 0 строк.
        $check = db_prepare($mysql, "SELECT order_id FROM orders WHERE order_id = ?", "i", $orderId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            header('Location: ' . admin_list_url('orders', 'missing-order'));
            exit();
        }

        $stmt = db_prepare($mysql, "DELETE FROM orders WHERE order_id = ?", "i", $orderId);
        $stmt->execute();
        admin_log($mysql, 'order.delete', 'order', $orderId, ['id' => $orderId]);
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

if ($isAdmin && isset($_POST['deleteUser'])) {
    csrf_verify();
    $userId = $_POST['userId'] ?? 0;

    // Логин читается до удаления: после DELETE копии нигде не остаётся.
    $who = db_prepare($mysql, "SELECT user_login FROM users WHERE user_id = ?", "i", $userId);
    $who->execute();
    $delLogin = (string) ($who->get_result()->fetch_row()[0] ?? '');

    $stmt = db_prepare($mysql, "DELETE FROM orders WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM users WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    admin_log($mysql, 'user.delete', 'user', (int) $userId, ['login' => $delLogin]);

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

$pageTitle = 'Админ-панель';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];

// Leaflet весит около 150 КБ и нужен только на вкладке настроек: на
// главной карта - статичный <img>. Подключение здесь, до header.php:
// вкладки включаются уже после вывода <head>.
if ($tab === 'settings') {
    $extraCss[] = '/assets/vendor/leaflet/leaflet.css';
    $extraJs[]  = '/assets/vendor/leaflet/leaflet.js';
    $extraJs[]  = '/assets/js/admin-settings.js';
}
// Скрипты вкладок конфигуратора и сборок - отдельные файлы, потому что
// admin-settings.js подключается выше и только на настройках.
if ($tab === 'configurator') {
    $extraJs[] = '/assets/js/admin-configurator.js';
}
if ($tab === 'assemblies') {
    $extraJs[] = '/assets/js/admin-assemblies.js';
}
require __DIR__ . '/partials/header.php';
?>
        <div class="profile-layout">
<?php // тот же сайдбар, что и в profile.php ?>
<?php $activeTab = $tab; ?>
<?php require __DIR__ . '/partials/profile-sidebar.php'; ?>
            <div class="profile-content">
<?php
define('ADMIN_CONTEXT', true);
// Модалка пользователя нужна на всех вкладках: из модалки заказа
// можно перейти к покупателю.
require __DIR__ . '/partials/admin-user-modal.php';
require __DIR__ . '/partials/admin-order-modal.php';
// Общий контракт data-row для обеих таблиц с заказами.
require_once __DIR__ . '/admin/_order_row_data.php';

if ($tab === 'dashboard') {
    require __DIR__ . '/admin/_tab_dashboard.php';
} elseif ($tab === 'components') {
    require __DIR__ . '/admin/_tab_components.php';
} elseif ($tab === 'files') {
    require __DIR__ . '/admin/_tab_files.php';
} elseif ($tab === 'orders') {
    require __DIR__ . '/admin/_tab_orders.php';
} elseif ($tab === 'users') {
    require __DIR__ . '/admin/_tab_users.php';
} elseif ($tab === 'configurator') {
    require __DIR__ . '/admin/_tab_configurator.php';
} elseif ($tab === 'assemblies') {
    require __DIR__ . '/admin/_tab_assemblies.php';
} elseif ($tab === 'settings') {
    // Настройки читаются один раз: они уходят и в форму, и в модалку
    // снимка карты.
    $settings = site_settings($mysql);
    require __DIR__ . '/admin/_tab_settings.php';
} elseif ($tab === 'log') {
    require __DIR__ . '/admin/_tab_log.php';
} else {
?>
                <section class="card">
                    <h2>Админ-панель</h2>
                    <p style="color:var(--text-secondary);">Выберите раздел</p>
                </section>
<?php
}
?>
            </div>
        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<!-- Скрытая форма удаления файла: JS подставляет имя и отправляет. -->
<form id="deleteFileForm" method="post" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="filename" value="">
    <input type="hidden" name="deleteFile" value="1">
</form>

<?php $mysql->close(); ?>
