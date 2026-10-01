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
    $name = $_POST['nm'] ?? '';
    $price = $_POST['pr'] ?? 0;
    $amount = $_POST['col'] ?? 0;
    $categoryId = $_POST['cat'] ?? 0;
    $tdp = $_POST['tdp'] ?? null;
    $videoCore = $_POST['vc'] ?? null;
    $socketId = $_POST['sock'] ?? null;

    // AUTO_INCREMENT выдаёт id сам — MAX(id)+1 был гонкой (Stage 3.7)
    if (!empty($name) && !empty($price) && !empty($amount) && !empty($categoryId)) {
        $stmt = db_prepare($mysql, "INSERT INTO `components` (`component_name`, `component_price`, `amount`, `category_id`) VALUES(?,?,?,?)", "siii", $name, $price, $amount, $categoryId);
        $stmt->execute();
        $newId = $mysql->insert_id;

        if ($tdp !== null) {
            $stmt = db_prepare($mysql, "UPDATE `components` SET `tdp` = ? WHERE `component_id` = ?", "ii", $tdp, $newId);
            $stmt->execute();
        }

        if ($videoCore !== null) {
            $stmt = db_prepare($mysql, "UPDATE `components` SET `video_core` = ? WHERE `component_id` = ?", "si", $videoCore, $newId);
            $stmt->execute();
        }

        if ($socketId !== null) {
            $stmt = db_prepare($mysql, "UPDATE `components` SET `socket_id` = ? WHERE `component_id` = ?", "ii", $socketId, $newId);
            $stmt->execute();
        }
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
