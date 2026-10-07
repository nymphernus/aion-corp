<?php
/**
 * Вкладка «Журнал» админ-панели: действия администраторов.
 *
 * Подключается только из admin.php (admin.php?tab=log).
 * Прямой запрос к файлу → 404.
 *
 * Фильтры и пагинация (50 строк) считает admin.php в своей ветке
 * $tab === 'log': $listWhere/$listParams/$listTypes/$listOrder/$offset
 * приходят оттуда же, как у остальных таблиц. Здесь - только выборка
 * и вывод.
 *
 * Записи старше 90 дней удаляет scripts/migrate.php при старте.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}

// Человекочитаемые коды действий. Ключи - точь-в-точь то, что пишет
// admin_log(): неизвестный код не исчезает, а показывается как есть,
// поэтому добавление нового действия не требует правки этого списка
// заранее.
$actionLabels = [
    'auth.login_success'       => 'Вход в админку',
    'auth.logout'              => 'Выход',
    'component.create'         => 'Создан компонент',
    'component.update'         => 'Изменён компонент',
    'component.delete'         => 'Удалён компонент',
    'assembly.create'          => 'Создана сборка',
    'assembly.update'          => 'Изменена сборка',
    'assembly.delete'          => 'Удалена сборка',
    'order.status_change'      => 'Смена статуса заказа',
    'order.delete'             => 'Удалён заказ',
    'user.update'              => 'Изменён пользователь',
    'user.delete'              => 'Удалён пользователь',
    'user.approve_email'       => 'Подтверждён email',
    'user.approve_phone'       => 'Подтверждён телефон',
    'settings.update'          => 'Изменены настройки',
    'settings.map_update'      => 'Обновлена карта',
    'settings.branding_update' => 'Изменён брендинг',
    'settings.favicon_variant' => 'Сменён вариант favicon',
    'social.create'            => 'Добавлена соцсеть',
    'social.update'            => 'Изменена соцсеть',
    'social.delete'            => 'Удалена соцсеть',
    'preset.create'            => 'Создан пресет',
    'preset.update'            => 'Изменён пресет',
    'preset.delete'            => 'Удалён пресет',
    'os.create'                => 'Создана ОС',
    'os.update'                => 'Изменена ОС',
    'os.delete'                => 'Удалена ОС',
    'file.delete'              => 'Удалён файл',
    'file.attach'              => 'Файл привязан к корпусу',
    'file.batch_upload'        => 'Загружены файлы',
];

// Кто вообще встречался в журнале - для списка в фильтре.
$logUsers = [];
$usersStmt = $mysql->query('SELECT DISTINCT user_login FROM admin_actions ORDER BY user_login');
if ($usersStmt) {
    while ($u = $usersStmt->fetch_row()) {
        $logUsers[] = (string) $u[0];
    }
}

$currentLogUser = (string) ($_GET['user_login'] ?? '');
$currentLogAction = (string) ($_GET['action'] ?? '');
?>
                <section class="card admin-panel">
                    <h1 class="page-title">Журнал действий</h1>

                    <form method="get" class="admin-filters">
                        <input type="hidden" name="tab" value="log">

                        <select name="user_login" class="input">
                            <option value="">Все пользователи</option>
<?php foreach ($logUsers as $u): ?>
                            <option value="<?= escape($u) ?>"<?= $currentLogUser === $u ? ' selected' : '' ?>><?= escape($u) ?></option>
<?php endforeach; ?>
                        </select>

                        <select name="action" class="input">
                            <option value="">Все действия</option>
<?php foreach ($actionLabels as $code => $label): ?>
                            <option value="<?= escape($code) ?>"<?= $currentLogAction === $code ? ' selected' : '' ?>><?= escape($label) ?></option>
<?php endforeach; ?>
                        </select>

                        <input type="date" name="date_from" class="input"
                               value="<?= escape((string) ($_GET['date_from'] ?? '')) ?>">
                        <input type="date" name="date_to" class="input"
                               value="<?= escape((string) ($_GET['date_to'] ?? '')) ?>">

                        <button type="submit" class="btn btn--primary">Применить</button>

<?php if ($currentLogUser !== '' || $currentLogAction !== ''
    || (string) ($_GET['date_from'] ?? '') !== '' || (string) ($_GET['date_to'] ?? '') !== ''): ?>
                        <a href="?tab=log" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
                    </form>
<?php
                    // $page/$pages/$offset/$total/$perPage и $listWhere
                    // считает admin.php - тот же контракт, что у users.
                    $sql = "SELECT action_id, user_id, user_login, action, entity_type,
                                   entity_id, details, ip, created_at
                            FROM admin_actions WHERE 1=1" . $listWhere . "
                            ORDER BY {$listOrder} LIMIT ? OFFSET ?";
                    if ($listParams === []) {
                        $stmt = db_prepare($mysql, $sql, 'ii', $perPage, $offset);
                    } else {
                        $stmt = db_prepare($mysql, $sql, $listTypes . 'ii', ...array_merge($listParams, [$perPage, $offset]));
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();

                    echo "<div class=\"table-wrap\"><table class=\"table\">
                        <thead><tr>
                            <th style=\"width:140px\">Время</th>
                            <th style=\"width:140px\">Кто</th>
                            <th>Действие</th>
                            <th>Объект</th>
                            <th>Детали</th>
                            <th style=\"width:120px\">IP</th>
                        </tr></thead><tbody>";

                    $logCount = 0;
                    if ($result) {
                        while ($row = $result->fetch_array()) {
                            $logCount++;

                            $when = date('d.m.Y H:i', strtotime((string) $row['created_at']));

                            // Объект: тип и номер, «—» если не заполнено.
                            $entity = (string) ($row['entity_type'] ?? '');
                            if ($entity === '') {
                                $entity = '—';
                            } elseif (!empty($row['entity_id'])) {
                                $entity .= ' #' . (int) $row['entity_id'];
                            }

                            // Детали - JSON вида {"name":"..."}: пары
                            // «ключ: значение» через ·, длинное - в многоточие.
                            $det = '—';
                            if (!empty($row['details'])) {
                                $decoded = json_decode((string) $row['details'], true);
                                if (is_array($decoded)) {
                                    $parts = [];
                                    foreach ($decoded as $k => $v) {
                                        if (is_array($v)) {
                                            $v = implode(', ', $v);
                                        }
                                        $parts[] = $k . ': ' . (string) $v;
                                    }
                                    $det = mb_strimwidth(implode(' · ', $parts), 0, 80, '…');
                                }
                            }

                            $code = (string) $row['action'];
                            $label = $actionLabels[$code] ?? $code;

                            echo '<tr>'
                                . '<td>' . escape($when) . '</td>'
                                . '<td>' . escape((string) $row['user_login']) . '</td>'
                                . '<td>' . escape($label) . '</td>'
                                . '<td>' . escape($entity) . '</td>'
                                . '<td>' . escape($det) . '</td>'
                                . '<td>' . escape((string) $row['ip']) . '</td>'
                                . '</tr>';
                        }
                    }
                    echo '</tbody></table></div>';

                    if ($logCount === 0) {
                        // profile.css, а не extra-empty из configurator.css:
                        // тот на вкладке журнала не подключён.
                        echo '<div class="profile-empty">Записей нет</div>';
                    }

                    echo render_pagination('log', $page, $pages, $listQuery);
?>
                </section>
