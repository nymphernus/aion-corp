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
<?php
                    // 3.7-f-5: $page/$pages/$offset/$total/$perPage считает admin.php
                    // 3.7-h-2: user_email добавлен для модалки пользователя
                    $sql = "SELECT user_id, user_name, user_surname, user_login, user_group,
                                   user_address, user_number, user_email
                            FROM users ORDER BY user_id ASC LIMIT ? OFFSET ?";
                    $stmt = db_prepare($mysql, $sql, "ii", $perPage, $offset);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    echo "<div class=\"table-wrap\"><table class=\"table\">
                        <thead><tr>
                            <th>Имя</th>
                            <th>Логин</th>
                            <th>Группа</th>
                            <th>Адрес</th>
                            <th>Номер</th>
                            <th></th>
                        </tr></thead><tbody>";
                    if ($result) {
                        while ($row = $result->fetch_array()) {
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
                        ]);
                        echo "<tr data-row='" . escape($rowData) . "'>"
                                . "<td>" . htmlspecialchars($row['user_name'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_login'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_group'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_address'] ?? '') . "</td>"
                                . "<td>" . htmlspecialchars($row['user_number'] ?? '') . "</td>"
                                . "<td><form method=\"POST\" class=\"row-form\">"
                                . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">"
                                . "<input name=\"userId\" type=\"hidden\" value=\"" . htmlspecialchars($row['user_id'] ?? '') . "\">"
                                . "<button class=\"delBtn\" name=\"deleteUser\" type=\"submit\">Удалить</button>"
                                . "</form></td>"
                                . "</tr>";
                        }
                    }
                    echo "</tbody></table></div>";
                    echo render_pagination('users', $page, $pages);
?>
                </section>

                <!-- 3.7-h-2: ошибки валидации при сохранении профиля -->
<?php if (isset($_GET['error'])): ?>
<?php
                    $userErrors = [
                        'name' => 'Имя должно быть от 2 до 20 символов.',
                        'group' => 'Недопустимая группа.',
                        'phone' => 'Телефон не соответствует формату +7 XXX XXX-XX-XX.',
                        'self-demote' => 'Нельзя снять права администратора с самого себя.',
                        'missing' => 'Пользователь не найден.',
                    ];
?>
                <div class="alert alert--error"><?= escape($userErrors[$_GET['error']] ?? 'Не удалось сохранить изменения.') ?></div>
<?php endif; ?>

                <!--
                    3.7-h-2: модалка пользователя — имя, группа, контакты, адрес.
                    Логин только для чтения. Отправляет name="editUser"
                    (новый обработчик в admin.php), deleteUser не тронут.
                -->
                <dialog id="editUserModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=users">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="editUserId" id="editUserId" value="">

                        <h2>Пользователь: <span id="editUserLogin"></span></h2>

                        <div class="modal-section">
                            <h3>Основные данные</h3>
                            <div class="form-group">
                                <label class="form-label" for="editUserName">Имя</label>
                                <input class="input" name="user_name" id="editUserName" maxlength="20" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="editUserNameRO">Логин (не изменяется)</label>
                                <input class="input" id="editUserNameRO" disabled>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Права</h3>
                            <div class="form-group">
                                <label class="form-label" for="editUserGroup">Группа</label>
                                <select class="input" name="user_group" id="editUserGroup">
                                    <option value="user">Пользователь</option>
                                    <option value="admin">Администратор</option>
                                </select>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Адрес</h3>
                            <!-- 3.7-i: одно поле будет разбито на город/улицу/дом/индекс -->
                            <div class="form-group">
                                <label class="form-label" for="editUserAddress">Адрес</label>
                                <input class="input" name="user_address" id="editUserAddress" maxlength="1000">
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Контакты</h3>
                            <div class="form-group">
                                <label class="form-label" for="editUserNumber">Телефон</label>
                                <input class="input" name="user_number" id="editUserNumber" maxlength="13"
                                       pattern="\+7\s?[\(]{0,1}\d{3}[\)]{0,1}\s?\d{3}[\-]{0,1}\d{2}[\-]{0,1}\d{2}">
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn btn--danger" id="editUserDeleteBtn" data-action="open-delete-user-modal">Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="editUser" class="btn btn--primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                </dialog>

                <!-- 3.7-h-2: подтверждение удаления пользователя -->
                <dialog id="deleteUserModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=users">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="userId" id="deleteUserId" value="">

                        <h2>Удалить пользователя?</h2>
                        <p id="deleteUserName" style="color: var(--text-secondary); margin-bottom: 24px;"></p>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="deleteUser" class="btn btn--danger">Удалить</button>
                            </div>
                        </div>
                    </form>
                </dialog>