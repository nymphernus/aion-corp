<?php
/**
 * Админ-панель AION CORP.
 *
 * URL: /admin.php?tab=users|orders|components
 *  - без ?tab        → 302 на ?tab=components
 *  - неизвестный tab → 404
 *  - гость / не админ (свежая группа из БД) → 302 на /profile.php
 *
 * Обработчики POST и разметка вкладок вынесены в src/admin/.
 */

require_once __DIR__ . '/modules/connect.php';
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
    header('Location: /admin.php?tab=components');
    exit();
}

$allowedTabs = ['users' => true, 'orders' => true, 'components' => true];
if (!isset($allowedTabs[$tab])) {
    http_response_code(404);
    exit('Раздел не найден');
}

// Обработчики POST (перенесено из profile.php, SQL без изменений)
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
    header('Location: /admin.php?tab=orders');
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

    header('Location: /admin.php?tab=users');
    exit();
}

$pageTitle = 'Админ-панель';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';
?>
        <div class="profile-layout">
            <aside class="profile-sidebar">
                <nav>
                    <a href="/admin.php?tab=users" class="profile-nav-item<?= $tab === 'users' ? ' active' : '' ?>">Пользователи</a>
                    <a href="/admin.php?tab=orders" class="profile-nav-item<?= $tab === 'orders' ? ' active' : '' ?>">Заказы</a>
                    <a href="/admin.php?tab=components" class="profile-nav-item<?= $tab === 'components' ? ' active' : '' ?>">Комплектующие</a>
                </nav>
                <div style="border-top:1px solid var(--border);margin:16px 0;"></div>
                <a href="/profile.php" class="btn btn--ghost">← В профиль</a>
            </aside>
            <div class="profile-content">
<?php
define('ADMIN_CONTEXT', true);
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
