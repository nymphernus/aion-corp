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
 * Метрики «новых пользователей за 7 дней» нет: в users нет колонки
 * с датой регистрации (user_regdate), а добавлять её ради одного
 * графика не хочется - users вообще без дат, кроме заказов.
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
?>
                <h1>Дашборд</h1>

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