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
    // /admin.php без раздела уводит на страницу пользователя.
    //
    // Раньше здесь стоял редирект на ?tab=users . Он был удобен
    // только до появления выбора раздела: при входе из шапки или из
    // закладки человек попадал в список пользователей без всякого
    // основания, и это выглядело как «меня куда-то перебросило».
    // Логичнее вернуть его туда, откуда он пришёл, - на /profile.php,
    // где есть и вход, и его сборки.
    header('Location: /profile.php');
    exit();
}

$allowedTabs = ['users' => true, 'orders' => true, 'components' => true, 'files' => true, 'dashboard' => true, 'settings' => true];
if (!isset($allowedTabs[$tab])) {
    http_response_code(404);
    exit('Раздел не найден');
}

// пагинация. Считаем ДО вывода HTML: header() в paginate()
// не сработает после старта вывода (headers already sent), и редирект
// с page=99 молча превратился бы в пустую таблицу.
$perPage = 10; // было 20

// фильтры и сортировка админ-таблиц.
// Условие общее для COUNT, для выборки и для ссылок пагинации.
$listWhere = '';
$listParams = [];
$listTypes = '';
$listQuery = '';  // GET-параметры для сохранения в ссылках
$listOrder = '';  // ORDER BY из белого списка

// экранирование спецсимволов LIKE, чтобы «%» не стал маской
$escapeLike = static function (string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
};

// --- Сохранение фильтров при сохранении/удалении (задача 7) ---
//
// Форма шлёт POST на /admin.php?tab=components БЕЗ фильтров в адресе,
// поэтому в $_GET на POST-запросе их нет и взять оттуда нельзя.
// Разметка вкладки кладёт текущие фильтры в скрытое поле
// return_params, отсюда же они читаются при редиректе. Один и тот же
// список ключей на обоих концах - иначе пришлось бы гадать, какие
// параметры вкладка умеет отдавать обратно.

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
    // $tab тоже должен быть в global: без него переменная видна только
    // если объявлена в той же области, а тут она живёт в includе - и
    // PHP ругался «Undefined variable $tab».
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
}

// дашборду пагинация и счётчик строк не нужны, поэтому весь блок
// с COUNT и paginate() для него пропускается. Иначе пришлось бы держать
// в $countSql фиктивную запись ради значения, которое никто не читает.
// то же для settings - это форма на пару экранов, а не таблица.
// Без этой правки вкладка падала: в $countSql нет ключа settings,
// $countSql приходил null, и db_prepare() умирал на типе аргумента.
if ($tab !== 'dashboard' && $tab !== 'settings') {
    $countSql = [
        'components' => 'SELECT COUNT(*) FROM components WHERE 1=1' . $listWhere,
    'files' => 'SELECT 0',  // файлы считаются в _tab_files.php
        'users' => 'SELECT COUNT(*) FROM users WHERE 1=1' . $listWhere,
        'orders' => 'SELECT COUNT(*) FROM users,assembly,orders
                     WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id'
                     . $listWhere,
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

        $stmt = db_prepare($mysql, "DELETE FROM `components` WHERE `component_id` = ?", "i", $delId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('components'));
    exit();
}

if ($isAdmin && isset($_POST['addComponent'])) {
    csrf_verify();

    // Адрес возврата: текущие фильтры лежат в POST-поле return_params,
    // которое кладёт разметка вкладки. Из $_GET их взять нельзя - на
    // POST-запросе там только tab=components.
    //
    // Ни одного вывода до header() здесь нет: любое echo или даже
    // пробел раньше приводили к «headers already sent».
    $location = '/admin.php?tab=components';
    $returnParams = return_params();
    if ($returnParams !== '') {
        $location .= '&' . $returnParams;
    }

    // пустой editComponentId = INSERT, заполненный = UPDATE
    $editId = (int) ($_POST['editComponentId'] ?? 0);
    $name = $_POST['nm'] ?? '';
    $price = (int) ($_POST['pr'] ?? 0);
    $amount = (int) ($_POST['col'] ?? 0);
    $categoryId = (int) ($_POST['cat'] ?? 0);
    
    // --- Изображение корпуса  ---
    // Только для category_id = 6 (Корпус). Приоритет по БЛОКУ 3:
    // 1) image_selected_url - выбран существующий файл;
    // 2) image_file - загружен новый;
    // 3) removeImage=1 - отвязать.
    $newImagePath = null;
    if ($categoryId === 6) {
        // Существующий путь (при edit)
        $stmt = db_prepare($mysql, "SELECT image FROM components WHERE component_id = ?", "i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $newImagePath = $row['image'] ?? null;

        // При выборе из загруженных клиент чистит файловый input, но
        // сервер всё равно решает сам: file вытесняет url, url вытесняет
        // простой сброс флага (иначе последний open+cancel сохранил бы
        // stale-выбор).
        if (!empty($_FILES['image_file']['name'])) {
            $imageSelectedUrl = '';
        } else {
            $imageSelectedUrl = trim((string) ($_POST['image_selected_url'] ?? ''));
        }

        // 1) Выбран существующий файл: валидация - путь строго в cases/ и
        // файл физически есть. Формат хранения - без ведущего слеша, как
        // в init.sql у всех существующих записей.
        if (!empty($imageSelectedUrl)) {
            $pickUrl = (string) $imageSelectedUrl;
            if (strpos($pickUrl, 'assets/images/cases/') === 0
                && strpos($pickUrl, '..') === false
                && is_file(__DIR__ . '/assets/images/cases/' . basename($pickUrl))) {
                $newImagePath = $pickUrl;
            }
        }
        // 3) Отвязка: только когда нет ни файла, ни выбора из пикера
        elseif (!empty($_POST['removeImage']) && $_POST['removeImage'] === '1') {
            $newImagePath = null;
        }
        
        // Загрузка нового изображения: проверки, конвертация в PNG и
        // дедупликация по MD5 живут в store_case_image(). Общий хелпер
        // нужен потому, что файлы грузятся ещё и пачкой с вкладки
        // «Изображения», и две копии правил рано или поздно разошлись бы.
        if (!empty($_FILES['image_file']['name'])) {
            $stored = store_case_image(
                $_FILES['image_file'],
                __DIR__ . '/assets/images/cases/',
                // slug из имени компонента (не файла!) - имя на диске
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

    // разрешённые поля по категориям. Скрытые input всё равно
    // уходят в $_POST (залипший tdp от «Процессора» после переключения
    // на «ОЗУ»), поэтому сервер обязан резать всё вне маппинга.
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
                // select шлёт socket_id, значение — id из таблицы sockets
                $fields['socket_id'] = ($_POST['socket'] ?? '') !== '' ? (int) $_POST['socket'] : null;
            } elseif ($f === 'video_core') {
                $fields['video_core'] = isset($_POST['video_core']) ? 1 : 0;
            } elseif (($_POST[$f] ?? '') !== '') {
                $fields[$f] = $_POST[$f];
            }
        }

        // типы выводятся из набора колонок (i для числовых, s для остальных)
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

            // Имя файла на диске берётся из имени загруженного файла, а не
            // из uniqid(): админ узнаёт корпус по имени, «upload-68f3c1a2»
            // ничего не говорит. slugify_image_name() отсекает мусор.
            $stored = store_case_image($file, $targetDir, slugify_image_name($file['name']));

            if ($stored['ok'] && $stored['duplicate']) {
                // Такой файл уже был в каталоге: новый удалён, путь
                // указывает на существующий. В «загружено» он не идёт -
                // на диске ничего не добавилось.
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

    csrf_rotate();
    // Итог - в query: uploaded=сколько сохранилось, skipped/bad - что
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

// --- Файловый менеджер: удаление ---
// Файл удаляется с диска, а привязки к нему обнуляются: иначе в
// components.image остаётся путь в несуществующий файл, корпус
// числится привязанным, превью в модалке битое, а счётчик
// «привязано к компонентам» врёт. basename режет path traversal,
// поэтому filename вида ../../index.php превращается в index.php и
// ищется внутри каталога cases/.
$filename = isset($_POST['deleteFile']) ? basename((string) ($_POST['filename'] ?? '')) : '';

if ($isAdmin && $filename !== '') {
    csrf_verify();

    $path = __DIR__ . '/assets/images/cases/' . $filename;

    // Привязки ищутся по имени файла, а не по полному пути: в базе путь
    // может лежать и со слешем, и без. Так же считается и «используется»
    // в списке файлов.
    $bound = db_prepare($mysql,
        "SELECT component_id, image FROM components
         WHERE image IS NOT NULL
           AND image LIKE '%assets/images/cases/%'
           AND SUBSTRING_INDEX(image, '/', -1) = ?",
        "s", $filename);
    $bound->execute();
    $bindings = $bound->get_result()->fetch_all(MYSQLI_ASSOC);
    $affected = count($bindings);

    // Файл снимается с диска ПЕРВЫМ, и только потом обнуляются привязки.
    // Так порядок неважен для целостности: если unlink не сработал,
    // привязки не тронуты и состояние осталось прежним. Обратный порядок
    // оставил бы корпус без картинки при картинке на диске, если бы файл
    // не удалился.
    $removed = false;
    if (is_file($path)) {
        $removed = unlink($path);
        if (!$removed) {
            header('Location: /admin.php?tab=files&error=undelete');
            exit;
        }
    }

    // Обнуляем привязки, даже если файла уже не было на диске: иначе
    // корпус навсегда числился бы привязанным к пустоте - ровно то
    // состояние, которое здесь чинится.
    foreach ($bindings as $b) {
        $clear = db_prepare($mysql, "UPDATE components SET image = NULL WHERE component_id = ?", "i", $b['component_id']);
        $clear->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=files&unlinked=' . $affected . ($removed ? '&deleted=1' : ''));
    exit;
}

// --- БЛОК 4: привязка файла к корпусу из файлового менеджера ---
if ($isAdmin && isset($_POST['attachFile'])) {
    csrf_verify();

    $fileUrl = trim((string) ($_POST['fileUrl'] ?? ''));
    $caseId = (int) ($_POST['caseId'] ?? 0);

    // Валидация: путь строго в cases/, без traversal, файл есть на диске.
    // Привязка возможна только к категории 6 - условие прямо в UPDATE.
    if (strpos($fileUrl, 'assets/images/cases/') === 0
        && strpos($fileUrl, '..') === false
        && $caseId > 0
        && is_file(__DIR__ . '/assets/images/cases/' . basename($fileUrl))) {
        $stmt = db_prepare($mysql,
            "UPDATE components SET image = ?
             WHERE component_id = ? AND category_id = 6",
            "si", $fileUrl, $caseId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=files');
    exit;
}

if ($isAdmin && isset($_POST['editOrderStatus'])) {
    csrf_verify();
    $status = $_POST['status'] ?? '';
    $orderId = $_POST['editOrderStatus'];

    $stmt = db_prepare($mysql, "UPDATE orders SET status = ? WHERE order_id = ?", "si", $status, $orderId);
    $stmt->execute();
    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

// сохранение контактов и текстовых настроек из формы вкладки
// «Настройки сайта». Координаты и снимок карты сюда не попадают: их
// пишет отдельный обработчик saveMapSnapshot, который проверяет PNG.
// saveSettings пропускается, если пришёл removeCustomFavicon: кнопка
    // «Вернуть сгенерированную» означает «сделать только это». Проверка
    // стоит именно здесь, а не только порядком блоков ниже: обработчик
    // возврата расположен после preview_favicon, и без гарда он оказался
    // бы недостижим - saveSettings успевает отредиректить и выйти.
    if ($isAdmin && isset($_POST['saveSettings']) && !isset($_POST['removeCustomFavicon'])) {
    csrf_verify();

    // Ошибки показываются на странице после редиректа, а не роняют
    // обработку: часть настроек к этому моменту уже может быть записана.
    $errors = [];

    // Белый список: ключи из POST не должны попадать в запрос как есть.
    // Здесь только значения, ключ берётся из этого списка.
    // добавились ключи брендинга - по тому же правилу, что и
    // контакты: правится только то, что перечислено здесь.
    // site_name_full и site_footer_copyright больше нет: два названия
    // были лишними, а копирайт целиком требовал дублировать в тексте
    // год и название, которые задаются рядом. Подвал собирается как
    // «© {site_founded_year} {site_name}».
    $allowed = [
        'contact_phone', 'contact_email',
        'contact_vk', 'contact_telegram', 'contact_whatsapp',
        'map_address_text',
        'site_name', 'site_description', 'site_founded_year',
        'site_logo_url', 'site_favicon_png_url',
        'favicon_letter', 'favicon_bg', 'favicon_text', 'favicon_auto_color',
        // Служебный флаг, см. ветку favicon_upload ниже: только код его
        // пишет, из формы он не приходит.
        'favicon_is_custom',
    ];

    $values = [];
    foreach ($allowed as $key) {
        // Поля, которых нет в POST, не трогаются. Иначе любая частичная
        // форма затирала бы остальные настройки пустыми: отсутствие поля
        // означает «не менялось», а явная пустая строка - «очистить».
        // На практике это всплыло в тесте, отправлявшем только ключи
        // брендинга, и обнулило site_description.
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $raw = (string) $_POST[$key];
        // trim убирает случайные пробелы по краям, но не трогает
        // внутренние: телефон и адрес пишутся как человек их ввёл
        $values[$key] = trim($raw);
    }

    // Ссылки принимаются только как http(s). Иначе через javascript:
    // можно было бы заставить админа кликнуть по иконке соцсети и
    // выполнить произвольный скрипт. Пустая строка допустима - значит
    // иконку не показываем вовсе.
    foreach (['contact_vk', 'contact_telegram', 'contact_whatsapp'] as $linkKey) {
        if (($values[$linkKey] ?? '') === '') {
            continue;
        }
        if (!filter_var($values[$linkKey] ?? '', FILTER_VALIDATE_URL)) {
            $values[$linkKey] = '';
        } elseif (!preg_match('#^https?://#i', $values[$linkKey] ?? '')) {
            $values[$linkKey] = '';
        }
    }

    // Пути к картинкам бренда - только внутри сайта. Внешний URL здесь
    // опасен: значение попадает в src логотипа и favicon, то есть в
    // админку на каждой странице. Принимается и абсолютный путь, и
    // относительный без слеша (в бате так хранятся картинки корпусов).
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

    // Логотип и favicon приходят файлами в эту же форму. Обработка
    // идёт ДО проверки путей выше: при успешной загрузке значение
    // site_logo_url должно быть новым, а не тем, что было в hidden-поле.
    $logo = branding_store($_FILES['branding_logo'] ?? null, 'logo', 'site_logo_url');
    if ($logo['url'] !== '') {
        $values['site_logo_url'] = $logo['url'];
    }

    // favicon - только PNG, и два способа его получить.
    //
    // Загруженный файл важнее сгенерированного: если админ выбрал свою
    // иконку, генератор не должен тут же переписать её своей буквой.
    //
    // Загрузка и генератор пишут в ОДИН И ТОТ ЖЕ файл
    // assets/images/branding/favicon.png - второй копии нет. Поэтому
    // favicon_is_custom хранит единственное, что известно о происхождении
    // файла, и без него обычное сохранение настроек стирало загруженную
    // иконку. Проверено замером пикселя (64,64): после второго «Сохранить»
    // он менялся с синего 0,0,255 (загруженный PNG) на 111,31,31
    // (нарисованная буква). Форма отправляет favicon_letter и favicon_bg
    // всегда - они в ней есть независимо от того, трогал их админ, - так
    // что до генератора доходило любое сохранение, включая правку
    // телефона в соседней карточке.
    $faviconUpload = store_favicon_upload($_FILES['favicon_upload'] ?? null);
    if ($faviconUpload['ok']) {
        $values['site_favicon_png_url'] = $faviconUpload['url'];
        $values['favicon_is_custom'] = '1';

        // Буква и цвета из формы сохраняются и при загрузке файла, хотя
        // картинка их игнорирует. Без этого они остались бы от прошлого
        // раза, и кнопка «Вернуть сгенерированную» нарисовала бы иконку
        // по старым значениям: админ только что выбрал M и красный фон,
        // загрузил свою картинку, потом вернулся к букве - и получил A.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        if (favicon_letter_error($letter) === '') {
            $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
            $values['favicon_letter'] = $letter;
            $values['favicon_bg'] = favicon_normalize_bg((string) ($_POST['favicon_bg'] ?? ''));
            $values['favicon_auto_color'] = $auto ? '1' : '0';
            $values['favicon_text'] = $auto ? '' : favicon_normalize_hex((string) ($_POST['favicon_text'] ?? ''), '#000000');
        }
    } elseif ($faviconUpload['error'] !== null) {
        // Загрузка была, но файл не подошёл. Генератор здесь не
        // запускается намеренно: иначе админ загрузил бы негодную
        // картинку, получил бы вместо неё иконку с буквой и потерял бы
        // прежнюю, ничего не поняв.
        $errors[] = $faviconUpload['error'];
    } elseif ((array_key_exists('favicon_letter', $_POST) || array_key_exists('favicon_bg', $_POST))
        && !(favicon_is_custom_now($mysql) && favicon_file_exists())) {
        // Файла нет, иконка не загружена и блок иконки в форме трогали -
        // значит иконку надо перегенерировать. Первое условие важно: при
        // сохранении формы, где блок иконки не отправляли вовсе, иконка
        // остаётся как была.
        //
        // Второе - тоже: при загруженной иконке буква и цвета относятся к
        // картинки, которой сейчас нет, и рисовать её значит тихо выбросить
        // то, что админ загрузил. Перерисовывает файл только явная кнопка
        // removeCustomFavicon.
        //
        // Исключение из исключения - favicon_file_exists(). Метка живёт в
        // базе, а файл на диске, и они могут разойтись: файл удалили
        // вручную или перезаписали генератором. Тогда терять нечего, а без
        // файла иконка в вкладке исчезла бы молча.
        //
        // Перегенерация идёт всегда, а не только при смене буквы: файл
        // могли удалить с диска, и тогда иконка в вкладке исчезла бы
        // молча.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        $letterError = favicon_letter_error($letter);
        if ($letterError !== '') {
            $errors[] = $letterError;
        } else {
            // Авто-цвет буквы: галочка в форме. В настройке он хранится как
            // '1'/'0', а цвет буквы при авто - пустая строка: ручной
            // цвет при включённом авто потерялся бы, и после снятия
            // галочки пикер показывал бы его же, а не последнее
            // введённое значение.
            //
            // Ручной цвет нормализуется ДО генерации, а не после: иначе
            // при пустом поле PNG рисовался бы с авто-контрастом, а в
            // настройку писался бы '#000000', и файл расходился бы с
            // тем, что форма покажет при следующем открытии.
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
                // Буква и цвета сохраняются здесь, а не только из
                // белого списка: там они прошли бы trim без проверки, и
                // в поле цвета попал бы мусор, который input type=color
                // не покажет.
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
        // Иконка загружена, а букву или цвета поменяли. Файл не трогаем -
        // см. условие ветки выше, - но сами настройки сохраняем: они
        // понадобятся генератору, когда админ вернётся к букве кнопкой
        // removeCustomFavicon. Иначе он получил бы иконку по старым
        // цветам и не понял бы почему.
        $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
        if (favicon_letter_error($letter) === '') {
            $auto = ($_POST['favicon_auto_color'] ?? '') === '1';
            $values['favicon_letter'] = $letter;
            $values['favicon_bg'] = favicon_normalize_bg((string) ($_POST['favicon_bg'] ?? ''));
            $values['favicon_auto_color'] = $auto ? '1' : '0';
            // При авто ручной цвет не пишется: иначе после снятия
            // галочки пикер показал бы его вместо последнего введённого.
            $values['favicon_text'] = $auto ? '' : favicon_normalize_hex((string) ($_POST['favicon_text'] ?? ''), '#000000');
        }
    }

    // Название сайта не должно быть пустым: иначе заголовок вкладки и
    // подпись в шапке останутся без текста. Подставляется дефолт.
    if (array_key_exists('site_name', $values) && $values['site_name'] === '') {
        $values['site_name'] = 'Aion Corporation';
    }
    // Год основания: 4 цифры. Мусор в это поле попал бы прямо в подвал.
    if (array_key_exists('site_founded_year', $values)) {
        $year = $values['site_founded_year'];
        if ($year === '' || preg_match('/^\d{4}$/', $year) !== 1) {
            $values['site_founded_year'] = '2022';
        }
    }
    // Логотип и фавикон: без ссылки отдаются штатные файлы проекта,
    // иначе шапка осталась бы без картинки после неудачной загрузки.
    if (array_key_exists('site_logo_url', $values) && $values['site_logo_url'] === '') {
        $values['site_logo_url'] = '/assets/images/logo.png';
    }
    if (array_key_exists('site_favicon_png_url', $values) && $values['site_favicon_png_url'] === '') {
        $values['site_favicon_png_url'] = '/assets/images/branding/favicon.png';
    }

    site_setting_save($mysql, $values);

    csrf_rotate();
    $location = '/admin.php?tab=settings';
    if ($errors !== []) {
        $location .= '&bad=' . rawurlencode(implode(', ', $errors));
    }
    header('Location: ' . $location);
    exit();
}

// предпросмотр favicon. Отдельный обработчик, потому что
// ответ здесь - не страница, а поток PNG: он показывается прямо в
// поле формы, до нажатия «Сохранить».
//
// Файл не пишется: generate_favicon() создал бы branding/favicon.png
// поверх текущей иконки, и «Сохранить» после предпросмотра уже ничего
// не изменил бы. Поэтому рисуем в память тем же кодом, которым потом
// сохраняем, - тогда предпросмотр совпадает с результатом побайтово.
if ($isAdmin && isset($_POST['preview_favicon'])) {
    csrf_verify();

    $letter = trim((string) ($_POST['favicon_letter'] ?? ''));
    $letterError = favicon_letter_error($letter);
    if ($letterError !== '') {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit($letterError);
    }

    // Авто-цветбуквы - та же логика, что в saveSettings.
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

// Вернуть сгенерированную иконку после загрузки своей.
//
// Отдельное действие, а не следствие обычного «Сохранить»: пока файл
// загружен, генератор молчит (см. ветку saveSettings), иначе он
// затирал бы загруженную картинку при каждом сохранении. Явная кнопка
// остаётся единственным способом вернуться к букве.
//
// Источник правды для буквы и цветов - сохранённые настройки, а не POST.
// «Вернуть сгенерированную» читается как «вернуть ту, что настроена», а
// не «придумать новую из текущего состояния формы»: поля могли быть не
// тронуты, но могли быть изменены без сохранения.
if ($isAdmin && isset($_POST['removeCustomFavicon'])) {
    csrf_verify();

    require_once __DIR__ . '/modules/site.php';

    $st = db_prepare(
        $mysql,
        "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('favicon_letter', 'favicon_bg', 'favicon_text', 'favicon_auto_color')",
        ''
    );
    $st->execute();
    $saved = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $saved[(string) $row['setting_key']] = (string) $row['setting_value'];
    }

    $letter = trim($saved['favicon_letter'] ?? 'A');
    // Пустая буква в настройках возможна только при испорченных данных, но
    // проверка всё равно нужна: generate_favicon() вернул бы null, файл бы
    // не тронулся, а флаг снялся бы - и интерфейс соврал бы, показав
    // «Вернуть сгенерированную» у неверной иконки.
    if (favicon_letter_error($letter) !== '') {
        $letter = 'A';
    }

    $auto = ($saved['favicon_auto_color'] ?? '0') === '1';
    $text = $auto ? 'auto' : favicon_normalize_hex($saved['favicon_text'] ?? '', '#000000');

    $path = generate_favicon($letter, favicon_normalize_bg($saved['favicon_bg'] ?? ''), $text);
    if ($path !== null) {
        site_setting_save($mysql, [
            'site_favicon_png_url' => $path,
            'favicon_is_custom' => '0',
        ]);
    }

    // Токен одноразовый в рамках загрузки страницы, а форма ниже получит
    // страницу заново: без ротации следующий «Сохранить» получил бы 403 по
    // CSRF-причине, ничего не объясняющей.
    csrf_rotate();

    header('Location: /admin.php?tab=settings&' . ($path !== null ? 'saved=1' : 'bad=favicon_generate'));
    exit();
}

// приём снимка карты с админской страницы.
//
// В POST приходит data:image/png;base64,... из html2canvas. Данные
// приходят из браузера, поэтому проверяем всё, на что можно опереться:
// форму префикса, результат base64_decode, сигнатуру PNG и размер.
// Файл пишется под фиксированным именем - имя из POST не используется
// принципиально, иначе через имя можно было бы записать что угодно
// в любой каталог.
if ($isAdmin && isset($_POST['saveMapSnapshot'])) {
    csrf_verify();

    $dataUrl = (string) ($_POST['map_snapshot'] ?? '');
    $address = trim((string) ($_POST['map_address_text'] ?? ''));
    $lat = (float) ($_POST['map_lat'] ?? 0);
    $lng = (float) ($_POST['map_lng'] ?? 0);

    // этот обработчик может завершиться ошибкой до вывода, а header() после
    // начала вывода не сработает, поэтому проверки идут до любого echo
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        http_response_code(400);
        exit('Invalid image format');
    }

    $binary = base64_decode($m[1], true);
    if ($binary === false || $binary === '') {
        http_response_code(400);
        exit('Invalid base64 payload');
    }

    // снимок делается с scale:2, контейнер примерно 760x400, то есть около
    // 6 МБ. Потолок 8 МБ: он не мешает нормальному снимку и не даёт телу
    // POST выесть память сервера
    if (strlen($binary) > 8 * 1024 * 1024) {
        http_response_code(400);
        exit('Image too large');
    }

    // база64 в принципе можно подделать, а вот эти восемь байт обязаны
    // стоять в начале настоящего PNG
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        http_response_code(400);
        exit('Not a PNG');
    }

    // координаты приходят из тех же данных браузера, но диапазон проверяем:
    // за пределами Земли их не бывает, а мусор в базе не нужен
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
    // бы на главном битую картинку, а rename в пределах каталога атомарен
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

    // Версия в URL - иначе браузер будет показывать старый снимок из кеша.
    // mtime меняется при каждой перезаписи, поэтому ссылка всегда новая.
    $version = (string) @filemtime($path);

    site_setting_save($mysql, [
        'map_snapshot_url' => '/assets/uploads/site-map.png?v=' . $version,
        'map_address_text' => $address,
        'map_lat'          => (string) $lat,
        'map_lng'          => (string) $lng,
        // Масштаб закрепляем за снимком: он дискретен, 15 это то, чем
        // снимали. Иначе настройка расходилась бы с картинкой
        'map_zoom'         => '15',
    ]);

    csrf_rotate();
    header('Location: /admin.php?tab=settings');
    exit();
}

// смена статуса из модалки заказа. Существующий editOrderStatus
// (кнопки в строках таблицы) не меняем — здесь свой обработчик с
// валидацией статуса по белому списку.
if ($isAdmin && isset($_POST['editOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['Обрабатывается', 'Собирается', 'Доставляется', 'Выполнен', 'Отменён'];

    if ($orderId > 0 && in_array($status, $allowed, true)) {
        $stmt = db_prepare($mysql, "UPDATE `orders` SET `status` = ? WHERE `order_id` = ?", "si", $status, $orderId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

// редактирование профиля пользователя из модалки.
// Валидация: user_name 2-20 символов (колонка varchar(20)), группа из
// белого списка, телефон по маске проекта. Разжаловать себя нельзя —
// иначе админ теряет доступ к панели.
if ($isAdmin && isset($_POST['editUser'])) {
    csrf_verify();
    $editUserId = (int) ($_POST['editUserId'] ?? 0);
    $editName = trim($_POST['user_name'] ?? '');
    // фамилия не обязательна, но слишком длинное значение
    // в varchar(30) не влезет, поэтому длина всё равно проверяется
    $editSurname = trim($_POST['user_surname'] ?? '');
    $editGroup = $_POST['user_group'] ?? '';
    // адрес разбит на поля. user_address больше не обновляется -
    // это legacy-строка, её значение остаётся как было при миграции.
    $editPostal = trim($_POST['user_postal_code'] ?? '');
    $editRegion = trim($_POST['user_region'] ?? '');
    $editCity = trim($_POST['user_city'] ?? '');
    $editStreet = trim($_POST['user_street'] ?? '');
    $editHouse = trim($_POST['user_house'] ?? '');
    $editApartment = trim($_POST['user_apartment'] ?? '');
    $editPhone = trim($_POST['user_number'] ?? '');

    // ошибки возвращаем на ту же вкладку с сообщением
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
    // адресные поля. Все необязательны: у части пользователей
    // адреса нет вовсе, и пустое значение пишется в NULL.
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
    // индекс: 5-10 цифр, пустое значение допустимо
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
        // существующий пользователь? (иначе UPDATE молча затронет 0 строк)
        $check = db_prepare($mysql, "SELECT user_id FROM users WHERE user_id = ?", "i", $editUserId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $fail('missing');
        }

        // user_address в UPDATE не участвует - legacy остаётся как есть
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
    } else {
        $fail('missing');
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

// удаление заказа. На orders ссылается только сам заказ,
// других таблиц с FK на orders нет (проверено: единственный FK у orders -
// assembly_id, то есть от заказа к сборке, а не наоборот), поэтому
// удалять можно без проверок использования.

// 7: подтверждение верификации контактов администратором.
//
// Двойная защита. Кнопка в модалке активна только при заявке, но это
// интерфейс: прямой POST без заявки обязан быть отбит здесь, поэтому
// requested = 1 стоит в WHERE. Без этого условия любой, кто открыл
// сессию админа, подтвердил бы любой контакт одной отправкой формы.
//
// После UPDATE заявка снимается: verified = 1 и requested = 0.
// Иначе повторное нажатие снова дало бы UPDATE, хотя суть уже сделана,
// и в модалке остался бы бейдж «Заявка от пользователя».
//
// userId приводится к int и проверяется на ноль: в WHERE подставляется
// параметр, но пустая строка в int дала бы 0 и UPDATE по user_id = 0 -
// обновление ни одной строки, но лишний поход в базу и редирект с
// сообщением об успехе.
if ($isAdmin && isset($_POST['approveEmail'])) {
    csrf_verify();
    $userId = (int) ($_POST['userId'] ?? 0);

    if ($userId > 0) {
        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `email_verified` = 1, `email_verification_requested` = 0
              WHERE `user_id` = ? AND `email_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
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
        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `phone_verified` = 1, `phone_verification_requested` = 0
              WHERE `user_id` = ? AND `phone_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

if ($isAdmin && isset($_POST['deleteOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);

    if ($orderId > 0) {
        // проверка существования: UPDATE/DELETE молча затрагивают 0 строк
        $check = db_prepare($mysql, "SELECT order_id FROM orders WHERE order_id = ?", "i", $orderId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            header('Location: ' . admin_list_url('orders', 'missing-order'));
            exit();
        }

        $stmt = db_prepare($mysql, "DELETE FROM orders WHERE order_id = ?", "i", $orderId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: ' . admin_list_url('orders'));
    exit();
}

if ($isAdmin && isset($_POST['deleteUser'])) {
    csrf_verify();
    $userId = $_POST['userId'] ?? 0;

    $stmt = db_prepare($mysql, "DELETE FROM orders WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM users WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    csrf_rotate();
    header('Location: ' . admin_list_url('users'));
    exit();
}

$pageTitle = 'Админ-панель';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];

// карта администрируется только на вкладке настроек, а на главной
// это статичный <img>. Leaflet весит около 150 КБ, и тащить его на каждую
// страницу админки незачем. Подключается здесь, до header.php: вкладки
// включаются уже после вывода <head>.
// html2canvas в задании предполагался для снимка карты, но с Leaflet он не
// работает (проверено, снимок выходил пустым), поэтому карта собирается
// вручную в admin-settings.js и библиотека нигде не используется.
if ($tab === 'settings') {
    $extraCss[] = '/assets/vendor/leaflet/leaflet.css';
    $extraJs[]  = '/assets/vendor/leaflet/leaflet.js';
    $extraJs[]  = '/assets/js/admin-settings.js';
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
// модалки пользователя нужны на всех вкладках - из модалки
// заказа можно перейти к покупателю
require __DIR__ . '/partials/admin-user-modal.php';
// модалка заказа нужна и таблице заказов, и дашборду
require __DIR__ . '/partials/admin-order-modal.php';
// общий контракт data-row для обеих таблиц с заказами
require_once __DIR__ . '/admin/_order_row_data.php';

// дашборд - первая вкладка в роутинге и первый пункт сайдбара
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
} elseif ($tab === 'settings') {
    // настройки читаются один раз на страницу и уходят и в форму,
    // и в модалку снимка карты
    $settings = site_settings($mysql);
    require __DIR__ . '/admin/_tab_settings.php';
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

<!-- Скрытая форма удаления файла. deleteFile всегда равен 1 - JS только
     подставляет имя файла и отправляет форму. -->
<form id="deleteFileForm" method="post" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="filename" value="">
    <input type="hidden" name="deleteFile" value="1">
</form>

<?php $mysql->close(); ?>
