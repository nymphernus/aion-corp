<?php
/**
 * Вкладка «Дашборд» админ-панели (Stage 3.7-g).
 *
 * Подключается только из admin.php (admin.php?tab=dashboard).
 * Прямой запрос к файлу → 404.
 *
 * Четыре блока метрик: пользователи, заказы, компоненты, выручка.
 * Все запросы - скалярные, без параметров; db_prepare вызывается с
 * пустым списком типов, как и требуется для остальных запросов панели.
 *
 * Метрики «новых пользователей за 7 дней» нет: колонка
 * user_regdate добавлена только в 3.7-g-6 и сейчас содержит дату
 * ALTER у всех существующих пользователей, так что считать по ней
 * «новых» бессмысленно - через пару недель данных хватит.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}

// Скалярный запрос: COUNT/SUM без входных параметров
$scalar = static function (mysqli $mysql, string $sql): int {
    $stmt = db_prepare($mysql, $sql, '');
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
};

// ── Пользователи ──────────────────────────────────────────────────
$usersTotal = $scalar($mysql, "SELECT COUNT(*) FROM users");
$usersAdmins = $scalar($mysql, "SELECT COUNT(*) FROM users WHERE user_group = 'admin'");

// ── Заказы ─────────────────────────────────────────────────────────
$ordersTotal = $scalar($mysql, "SELECT COUNT(*) FROM orders");
$ordersActive = $scalar($mysql, "SELECT COUNT(*) FROM orders WHERE status IN ('Обрабатывается', 'Доставляется')");
$ordersDone = $scalar($mysql, "SELECT COUNT(*) FROM orders WHERE status = 'Выполнен'");
$ordersCanceled = $scalar($mysql, "SELECT COUNT(*) FROM orders WHERE status = 'Отменён'");

// ── Компоненты ─────────────────────────────────────────────────────
$componentsTotal = $scalar($mysql, "SELECT COUNT(*) FROM components");
$componentsLow = $scalar($mysql, "SELECT COUNT(*) FROM components WHERE amount < 5");
$componentsOut = $scalar($mysql, "SELECT COUNT(*) FROM components WHERE amount = 0");

// ── Выручка: только выполненные заказы ─────────────────────────────
$revenueMonth = $scalar($mysql, "SELECT COALESCE(SUM(a.assembly_price), 0)
                                FROM orders o
                                JOIN assembly a ON a.assembly_id = o.assembly_id
                                WHERE o.status = 'Выполнен'
                                  AND o.created_at >= NOW() - INTERVAL 30 DAY");
$revenueAll = $scalar($mysql, "SELECT COALESCE(SUM(a.assembly_price), 0)
                              FROM orders o
                              JOIN assembly a ON a.assembly_id = o.assembly_id
                              WHERE o.status = 'Выполнен'");

$money = static fn (int $value): string => number_format($value, 0, ',', "\u{202F}") . ' ₽';

// ── Последние 5 заказов ───────────────────────────────────────────
// Колонки те же, что и в таблице заказов: data-row собирается общим
// adminOrderRowData(), поэтому клик по строке открывает ту же модалку
$lastOrders = [];
$stmt = db_prepare($mysql, "SELECT o.order_id, o.status, o.created_at, o.assembly_id AS asm_id,
                                   a.assembly_name, a.assembly_price,
                                   users.user_id AS buyer_id, users.user_name, users.user_surname,
                                   users.user_login, users.user_group, users.user_email, users.user_number,
                                   users.user_postal_code, users.user_region, users.user_city,
                                   users.user_street, users.user_house, users.user_apartment,
                                   users.user_regdate,
                                   users.email_verified, users.email_verification_requested,
                                   users.phone_verified, users.phone_verification_requested
                            FROM orders o
                            JOIN users ON users.user_id = o.user_id
                            JOIN assembly a ON a.assembly_id = o.assembly_id
                            ORDER BY o.created_at DESC, o.order_id DESC
                            LIMIT 5", '');
$stmt->execute();
$lastOrdersResult = $stmt->get_result();
while ($row = $lastOrdersResult->fetch_assoc()) {
    // то же правило, что и в таблице заказов: сборки с id > 3 - это
    // пользовательские, им приписывается «Сборка »
    if ($row['asm_id'] > 3) {
        $row['assembly_name'] = 'Сборка ' . ($row['assembly_name'] ?? '');
    }
    $row['data'] = json_encode(adminOrderRowData($row));
    $lastOrders[] = $row;
}

// ── Последние 5 зарегистрированных ─────────────────────────────────
// 3.7-g-6: колонка user_regdate добавлена в этой же версии, до неё
// «последние» определялись по максимальному user_id
$recentUsers = [];
$stmt = db_prepare($mysql, "SELECT user_id, user_name, user_login, user_group, user_regdate
                            FROM users
                            ORDER BY user_regdate DESC, user_id DESC
                            LIMIT 5", '');
$stmt->execute();
$recentUsersResult = $stmt->get_result();
while ($row = $recentUsersResult->fetch_assoc()) {
    $recentUsers[] = $row;
}

// ── Заканчивается на складе ───────────────────────────────────────
// Порог 10, а не 5: при 5 почти весь каталог попадал бы в блок
$lowStock = [];
$stmt = db_prepare($mysql, "SELECT component_id, component_name, amount
                            FROM components
                            WHERE amount < 10
                            ORDER BY amount ASC, component_id DESC
                            LIMIT 5", '');
$stmt->execute();
$lowStockResult = $stmt->get_result();
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStock[] = $row;
}

// ── Заказы по дням за 30 дней ─────────────────────────────────────
// SQL отдаёт только дни с заказами, а массив растягивается в PHP до
// всех 30 дней: иначе график состоял бы из одной полоски и не читался
$days = [];
$stmt = db_prepare($mysql, "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
                            FROM orders
                            WHERE created_at >= NOW() - INTERVAL 30 DAY
                            GROUP BY DATE(created_at)", '');
$stmt->execute();
$daysResult = $stmt->get_result();
$byDay = [];
while ($row = $daysResult->fetch_assoc()) {
    $byDay[$row['day']] = (int) $row['cnt'];
}

$chartDays = 30;
$maxOrders = max(1, max($byDay ?: [0]));
for ($i = $chartDays - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $count = $byDay[$date] ?? 0;
    $days[$date] = [
        'count' => $count,
        // пустые дни рисуем полоской в 4px, иначе график выглядит дырявым
        'height' => $count === 0 ? 2 : max(4, (int) round($count / $maxOrders * 100)),
        'label' => date('d.m', strtotime($date)),
    ];
}
$daysWithOrders = count($byDay);
?>
                <h1 class="page-title">Дашборд</h1>

                <div class="dashboard-grid">
                    <div class="card dashboard-card">
                        <h3>Пользователи</h3>
                        <div class="dashboard-metric">
                            <span class="dashboard-value"><?= $usersTotal ?></span>
                            <span class="dashboard-label">всего</span>
                        </div>
                        <div class="dashboard-sub"><?= $usersAdmins ?> с правами администратора</div>
                    </div>

                    <div class="card dashboard-card">
                        <h3>Заказы</h3>
                        <div class="dashboard-metric">
                            <span class="dashboard-value"><?= $ordersTotal ?></span>
                            <span class="dashboard-label">всего</span>
                        </div>
                        <div class="dashboard-sub">в работе: <?= $ordersActive ?></div>
                        <div class="dashboard-sub">выполнено: <?= $ordersDone ?></div>
                        <div class="dashboard-sub">отменено: <?= $ordersCanceled ?></div>
                    </div>

                    <div class="card dashboard-card">
                        <h3>Компоненты</h3>
                        <div class="dashboard-metric">
                            <span class="dashboard-value"><?= $componentsTotal ?></span>
                            <span class="dashboard-label">всего</span>
                        </div>
                        <div class="dashboard-sub">заканчивается (меньше 5): <?= $componentsLow ?></div>
                        <div class="dashboard-sub">нет в наличии: <?= $componentsOut ?></div>
                    </div>

                    <div class="card dashboard-card">
                        <h3>Выручка</h3>
                        <div class="dashboard-metric">
                            <span class="dashboard-value"><?= escape($money($revenueMonth)) ?></span>
                            <span class="dashboard-label">за 30 дней</span>
                        </div>
                        <div class="dashboard-sub">за всё время: <?= escape($money($revenueAll)) ?></div>
                        <div class="dashboard-sub">считаются только выполненные заказы</div>
                    </div>
                </div>


                <div class="dashboard-grid-2">
                    <!-- Последние заказы: клик по строке открывает ту же
                         модалку, что и в таблице заказов - data-row собран
                         общим adminOrderRowData() -->
                    <div class="card">
                        <h3>Последние заказы</h3>
<?php if ($lastOrders === []): ?>
                        <div class="profile-empty">Заказов пока нет</div>
<?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr>
                                    <th>№</th>
                                    <th>Покупатель</th>
                                    <th>Сборка</th>
                                    <th>Стоимость</th>
                                    <th>Статус</th>
                                    <th>Дата</th>
                                </tr></thead>
                                <tbody>
<?php
    foreach ($lastOrders as $row):
        $statusCls = match ($row['status'] ?? '') {
            'Выполнен' => 'badge--success',
            'Отменён' => 'badge--error',
            default => 'badge--warning',
        };
?>
                                    <tr class="row-link" data-row='<?= escape($row['data']) ?>'>
                                        <td><?= (int) $row['order_id'] ?></td>
                                        <td><?= escape(trim(($row['user_name'] ?? '') . ' ' . ($row['user_surname'] ?? ''))) ?></td>
                                        <td><?= escape((string) ($row['assembly_name'] ?? '')) ?></td>
                                        <td><?= escape((string) ($row['assembly_price'] ?? '')) ?> руб.</td>
                                        <td><span class="badge <?= $statusCls ?>"><?= escape((string) ($row['status'] ?? '')) ?></span></td>
                                        <td><?= escape(date('d.m.Y H:i', strtotime((string) $row['created_at']))) ?></td>
                                    </tr>
<?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
<?php endif; ?>
                    </div>

                    <!-- 3.7-g-6: сортировка по дате регистрации, раньше
                         её не существовало и блок шёл по user_id -->
                    <div class="card">
                        <h3>Последние 5 пользователей</h3>
<?php if ($recentUsers === []): ?>
                        <div class="profile-empty">Пользователей пока нет</div>
<?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Имя</th><th>Логин</th><th>Группа</th><th>Дата</th></tr></thead>
                                <tbody>
<?php foreach ($recentUsers as $row): ?>
                                    <tr>
                                        <td><?= escape((string) $row['user_name']) ?></td>
                                        <td><?= escape((string) $row['user_login']) ?></td>
                                        <td>
                                            <span class="badge <?= $row['user_group'] === 'admin' ? 'badge--success' : 'badge--warning' ?>">
                                                <?= $row['user_group'] === 'admin' ? 'Администратор' : 'Пользователь' ?>
                                            </span>
                                        </td>
                                        <td><?= escape(date('d.m.Y', strtotime((string) $row['user_regdate']))) ?></td>
                                    </tr>
<?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
<?php endif; ?>
                    </div>
                </div>

                <div class="dashboard-grid-2">
                    <!-- 3.7-g-5: заменил «Топ-5 покупателей». Порог 10:
                         при 5 в блок попадала бы почти весь каталог -->
                    <div class="card">
                        <h3>Заканчивается на складе</h3>
                        <div class="dashboard-sub" style="margin-bottom:12px;">осталось меньше 10 штук</div>
<?php if ($lowStock === []): ?>
                        <div class="dashboard-empty-ok">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Все позиции в наличии
                        </div>
<?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Название</th><th>Количество</th></tr></thead>
                                <tbody>
<?php foreach ($lowStock as $row): ?>
                                    <tr>
                                        <td><?= escape((string) $row['component_name']) ?></td>
                                        <td>
                                            <span class="badge <?= (int) $row['amount'] < 5 ? 'badge--error' : 'badge--warning' ?>">
                                                <?= (int) $row['amount'] ?> шт.
                                            </span>
                                        </td>
                                    </tr>
<?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
<?php endif; ?>
                    </div>

                    <div class="card">
                        <h3>Заказы за 30 дней</h3>
                        <div class="dashboard-sub" style="margin-bottom:12px;">дней с заказами: <?= $daysWithOrders ?>, максимум за день: <?= $maxOrders ?></div>
                        <div class="dashboard-chart" role="img" aria-label="Заказы по дням за последние 30 дней">
<?php foreach ($days as $date => $day): ?>
                            <div class="dashboard-bar-wrap" title="<?= escape($day['label'] . ': ' . $day['count']) ?>">
                                <div class="dashboard-bar" style="height: <?= (int) $day['height'] ?>%;"></div>
                            </div>
<?php endforeach; ?>
                        </div>
                        <div class="dashboard-chart-axis">
                            <span><?= escape(reset($days)['label']) ?></span>
                            <span><?= escape(end($days)['label']) ?></span>
                        </div>
                    </div>
                </div>
