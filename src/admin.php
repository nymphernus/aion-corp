<?php
/**
 * Админ-панель AION CORP.
 *
 * URL: /admin.php?tab=users|orders|components
 *  - без ?tab        → 302 на ?tab=users (3.7-f-3)
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

$stmt = $mysql->prepare("SELECT user_group FROM `users` WHERE `user_id` = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
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
    // 3.7-f-3: вход из профиля ведёт сразу на список пользователей
    header('Location: /admin.php?tab=users');
    exit();
}

$allowedTabs = ['users' => true, 'orders' => true, 'components' => true];
if (!isset($allowedTabs[$tab])) {
    http_response_code(404);
    exit('Раздел не найден');
}

// 3.7-f-5: пагинация. Считаем ДО вывода HTML: header() в paginate()
// не сработает после старта вывода (headers already sent), и редирект
// с page=99 молча превратился бы в пустую таблицу.
$perPage = 10; // 3.7-f-2-2: было 20

// 3.7-f-2-2: фильтры таблицы комплектующих (категория / сокет / поиск).
// Условие общее для COUNT, для выборки и для ссылок пагинации.
$compWhere = '';
$compParams = [];
$compTypes = '';
$compQuery = ''; // строка GET-параметров для сохранения в ссылках

if ($tab === 'components') {
    $fCat = (int) ($_GET['cat'] ?? 0);
    $fSock = (int) ($_GET['sock'] ?? 0);
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if ($fQ !== '') {
        // экранируем спецсимволы LIKE, чтобы «%» не превращался в маску
        $fQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fQ);
    }

    if ($fCat > 0) {
        $compWhere .= ' AND components.category_id = ?';
        $compParams[] = $fCat;
        $compTypes .= 'i';
    }
    // 3.7-f-3-10: сокет применяем только когда категория выбрана и
    // входит в [1, 2, 7] - скрытый select всё равно шлёт значение
    $sockRelevant = in_array($fCat, [1, 2, 7], true);
    if ($fSock > 0 && $sockRelevant) {
        $compWhere .= ' AND components.socket_id = ?';
        $compParams[] = $fSock;
        $compTypes .= 'i';
    }
    if ($fQ !== '') {
        $compWhere .= ' AND components.component_name LIKE ?';
        $compParams[] = '%' . $fQ . '%';
        $compTypes .= 's';
    }

    // 3.7-f-3-11: сортировка только из белого списка, $_GET в SQL не идёт
    $sortWhitelist = [
        'price_asc' => 'components.component_price ASC',
        'price_desc' => 'components.component_price DESC',
        'amount_asc' => 'components.amount ASC',
        'amount_desc' => 'components.amount DESC',
        'name_asc' => 'components.component_name ASC',
    ];
    $sort = (string) ($_GET['sort'] ?? '');
    $orderBy = $sortWhitelist[$sort] ?? 'components.component_id ASC';

    // строка GET-параметров для сохранения в ссылках пагинации
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
    $compQuery = implode('&', $qs);
}

$countSql = [
    'components' => 'SELECT COUNT(*) FROM components WHERE 1=1' . $compWhere,
    'users' => 'SELECT COUNT(*) FROM users',
    'orders' => 'SELECT COUNT(*) FROM users,assembly,orders
                 WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id',
][$tab];
if ($compParams === []) {
    $stmt = db_prepare($mysql, $countSql, '');
} else {
    $stmt = db_prepare($mysql, $countSql, $compTypes, ...$compParams);
}
$stmt->execute();
$total = (int) $stmt->get_result()->fetch_row()[0];
// [$page, $pages, $offset] доступны во всех вкладках через общий scope
[$page, $pages, $offset] = paginate($tab, $total, $perPage, $compQuery);

// Обработчики POST (перенесено из profile.php, SQL без изменений)
if ($isAdmin && isset($_POST['deleteComponent'])) {
    csrf_verify();
    // 3.7-e: FK assembly.*_id → components.component_id (11 колонок, NO ACTION).
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
            header('Location: /admin.php?tab=components&error=used&count=' . $usedCount);
            exit();
        }

        $stmt = db_prepare($mysql, "DELETE FROM `components` WHERE `component_id` = ?", "i", $delId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=components');
    exit();
}

if ($isAdmin && isset($_POST['addComponent'])) {
    csrf_verify();
    // 3.7-d: пустой editComponentId = INSERT, заполненный = UPDATE
    $editId = (int) ($_POST['editComponentId'] ?? 0);
    $name = $_POST['nm'] ?? '';
    $price = (int) ($_POST['pr'] ?? 0);
    $amount = (int) ($_POST['col'] ?? 0);
    $categoryId = (int) ($_POST['cat'] ?? 0);

    // 3.7-c: разрешённые поля по категориям. Скрытые input всё равно
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

        // 3.7-c: типы выводятся из набора колонок (i для числовых, s для остальных)
        $intCols = ['component_price', 'amount', 'category_id', 'socket_id', 'tdp',
            'frequency_mhz', 'video_core', 'capacity_gb', 'wattage', 'rpm'];
        $cols = array_keys($fields);
        $types = '';
        foreach ($cols as $c) {
            $types .= in_array($c, $intCols, true) ? 'i' : 's';
        }
        if ($editId > 0) {
            // 3.7-d: UPDATE всех 19 колонок — при смене категории поля,
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
    header('Location: /admin.php?tab=components');
    exit();
}

if ($isAdmin && isset($_POST['editOrderStatus'])) {
    csrf_verify();
    $status = $_POST['status'] ?? '';
    $orderId = $_POST['editOrderStatus'];

    $stmt = $mysql->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
    $stmt->bind_param("si", $status, $orderId);
    $stmt->execute();
    csrf_rotate();
    header('Location: /admin.php?tab=orders');
    exit();
}

// 3.7-h-1: смена статуса из модалки заказа. Существующий editOrderStatus
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
    header('Location: /admin.php?tab=orders');
    exit();
}

// 3.7-h-2: редактирование профиля пользователя из модалки.
// Валидация: user_name 2-20 символов (колонка varchar(20)), группа из
// белого списка, телефон по маске проекта. Разжаловать себя нельзя —
// иначе админ теряет доступ к панели.
if ($isAdmin && isset($_POST['editUser'])) {
    csrf_verify();
    $editUserId = (int) ($_POST['editUserId'] ?? 0);
    $editName = trim($_POST['user_name'] ?? '');
    $editGroup = $_POST['user_group'] ?? '';
    $editAddress = trim($_POST['user_address'] ?? '');
    $editPhone = trim($_POST['user_number'] ?? '');

    // ошибки возвращаем на ту же вкладку с сообщением
    $fail = static function (string $code): void {
        header('Location: /admin.php?tab=users&error=' . urlencode($code));
        exit();
    };

    $nameLen = mb_strlen($editName, 'UTF-8');
    if ($nameLen < 2 || $nameLen > 20) {
        $fail('name');
    }
    if (!in_array($editGroup, ['user', 'admin'], true)) {
        $fail('group');
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

        $stmt = db_prepare($mysql, "UPDATE users SET user_name = ?, user_group = ?, user_address = ?, user_number = ? WHERE user_id = ?", "ssssi", $editName, $editGroup, $editAddress !== '' ? $editAddress : null, $editPhone !== '' ? $editPhone : null, $editUserId);
        $stmt->execute();
    } else {
        $fail('missing');
    }

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

if ($isAdmin && isset($_POST['deleteUser'])) {
    csrf_verify();
    $userId = $_POST['userId'] ?? 0;

    $stmt = $mysql->prepare("DELETE FROM orders WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $stmt = $mysql->prepare("DELETE FROM favorites WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $stmt = $mysql->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

$pageTitle = 'Админ-панель';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';
?>
        <div class="profile-layout">
<?php // 3.7-f-4-1: тот же сайдбар, что и в profile.php ?>
<?php $activeTab = $tab; ?>
<?php require __DIR__ . '/partials/profile-sidebar.php'; ?>
            <div class="profile-content">
<?php
define('ADMIN_CONTEXT', true);
// 3.7-f-4-2: модалки пользователя нужны на всех вкладках - из модалки
// заказа можно перейти к покупателю
require __DIR__ . '/partials/admin-user-modal.php';

if ($tab === 'components') {
    require __DIR__ . '/admin/_tab_components.php';
} elseif ($tab === 'orders') {
    require __DIR__ . '/admin/_tab_orders.php';
} elseif ($tab === 'users') {
    require __DIR__ . '/admin/_tab_users.php';
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

<?php $mysql->close(); ?>
