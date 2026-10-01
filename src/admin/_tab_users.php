<?php
/**
 * Вкладка «Пользователи» админ-панели.
 *
 * Подключается только из admin.php (admin.php?tab=users).
 * Прямой запрос к файлу → 404.
 *
 * 3.7-f-3: переведено с legacy-разметки .assemblyTable на .table из
 * base.css — таблица тянется на всю ширину карточки.
 * Имена POST-полей (csrf_token, userId, deleteUser) не менялись.
 */

if (!defined('ADMIN_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
                <section class="card admin-panel">
                    <h2>Управление пользователями</h2>

                    <!-- 3.7-f-4-3: фильтр по группе и поиск по имени/логину
                         (3.7-f-4b-4: сортировка убрана) -->
                    <form method="get" class="admin-filters">
                        <input type="hidden" name="tab" value="users">

                        <select name="group" class="input">
<?php
    $groupOptions = ['' => 'Все группы', 'user' => 'Пользователи', 'admin' => 'Администраторы'];
    $curGroup = (string) ($_GET['group'] ?? '');
    foreach ($groupOptions as $val => $label):
?>
                            <option value="<?= escape($val) ?>"<?= $curGroup === $val ? ' selected' : '' ?>><?= escape($label) ?></option>
<?php endforeach; ?>
                        </select>

                        <input type="search" name="q" class="input" placeholder="Поиск по имени или логину..."
                               value="<?= escape((string) ($_GET['q'] ?? '')) ?>">

                        <button type="submit" class="btn btn--primary">Применить</button>

<?php if (trim((string) ($_GET['group'] ?? '')) !== '' || trim((string) ($_GET['q'] ?? '')) !== ''): ?>
                        <a href="?tab=users" class="btn btn--ghost">Сбросить</a>
<?php endif; ?>
                    </form>
<?php
                    // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php
                    // 3.7-f-3-11: user_email добавлен для модалки пользователя
                    // 3.7-f-4-3: $listWhere/$listOrder приходят из admin.php
                    // 3.7-i-2: шесть адресных колонок нужны модалке
                    $sql = "SELECT user_id, user_name, user_surname, user_login, user_group,
                                   user_address, user_number, user_email,
                                   user_postal_code, user_region, user_city,
                                   user_street, user_house, user_apartment
                            FROM users WHERE 1=1" . $listWhere . "
                            ORDER BY {$listOrder} LIMIT ? OFFSET ?";
                    if ($listParams === []) {
                        $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                    } else {
                        $stmt = db_prepare($mysql, $sql, $listTypes . "ii", ...array_merge($listParams, [$perPage, $offset]));
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();

                    echo "<div class=\"table-wrap\"><table class=\"table\">
                        <thead><tr>
                            <th>Имя</th>
                            <th>Логин</th>
                            <th>Группа</th>
                            <th>Адрес</th>
                            <th>Номер</th>
                        </tr></thead><tbody>";
                    if ($result) {
                        while ($row = $result->fetch_array()) {
                            // 3.7-i-3: адрес берём из разбитых полей, а не из
                        // legacy-строки: иначе после правки города таблица
                        // продолжала бы показывать старый user_address.
                        // Fallback на legacy оставлен для пользователей,
                        // у которых новые поля ещё не заполнены.
                        $addr = trim((string) ($row['user_city'] ?? ''));
                        if ($addr === '') {
                            $legacy = trim((string) ($row['user_address'] ?? ''));
                            $addr = $legacy !== '' ? explode(',', $legacy)[0] : '';
                        }
                        $shortAddress = $addr !== '' ? $addr : 'Не указан';

                        // 3.7-h-2: данные строки для модалки пользователя
                        $rowData = json_encode([
                            'modal' => 'user',
                            'id' => $row['user_id'],
                            'name' => $row['user_name'],
                            'surname' => $row['user_surname'],
                            'login' => $row['user_login'],
                            'group' => $row['user_group'],
                            'address' => $row['user_address'],
                            'number' => $row['user_number'],
                            'email' => $row['user_email'],
                            // 3.7-i-2: адрес разбит на поля, address остаётся
                            // legacy-строкой для показа в модалке
                            'postal_code' => $row['user_postal_code'],
                            'region' => $row['user_region'],
                            'city' => $row['user_city'],
                            'street' => $row['user_street'],
                            'house' => $row['user_house'],
                            'apartment' => $row['user_apartment'],
                        ]);
                        echo "<tr data-row='" . escape($rowData) . "'>"
                                . "<td>" . htmlspecialchars($row['user_name'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_login'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_group'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($shortAddress) . "</td>"
                                . "<td>" . htmlspecialchars($row['user_number'] ?? '') . "</td>"
                                . "</tr>";
                        }
                    }
                    echo "</tbody></table></div>";
                    echo render_pagination('users', $page, $pages, $listQuery);
?>
                </section>

                <!-- 3.7-h-2: ошибки валидации при сохранении профиля -->
<?php if (isset($_GET['error'])): ?>
<?php
                    $userErrors = [
                        'name' => 'Имя должно быть от 2 до 20 символов.',
                        // 3.7-f-4c-3
                        'surname' => 'Фамилия не должна быть длиннее 30 символов.',
                        // 3.7-i-2: адресные поля
                        'city' => 'Город не должен быть длиннее 100 символов.',
                        'region' => 'Регион не должен быть длиннее 100 символов.',
                        'street' => 'Улица не должна быть длиннее 150 символов.',
                        'house' => 'Дом не должен быть длиннее 20 символов.',
                        'apartment' => 'Квартира не должна быть длиннее 20 символов.',
                        'postal' => 'Индекс должен состоять из 5-10 цифр.',
                        'group' => 'Недопустимая группа.',
                        'phone' => 'Телефон не соответствует формату +7 XXX XXX-XX-XX.',
                        'self-demote' => 'Нельзя снять права администратора с самого себя.',
                        'missing' => 'Пользователь не найден.',
                    ];
?>
                <div class="alert alert--error"><?= escape($userErrors[$_GET['error']] ?? 'Не удалось сохранить изменения.') ?></div>
<?php endif; ?>

                <!-- 3.7-f-4-2: модалки пользователя и подтверждения удаления
                     вынесены в partials/admin-user-modal.php и подключаются из admin.php,
                     чтобы быть доступными и на вкладке заказов -->
