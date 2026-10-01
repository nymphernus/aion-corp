<?php
require_once __DIR__ . '/modules/connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

csrf_token();

$mysql = connect();
mysqli_set_charset($mysql, 'utf8');

if (!$mysql) {
    die("Ошибка подключения к базе данных");
}

$userProfile = null;
if (isset($_SESSION['user_login'])) {
    $userLogin = $_SESSION['user_login'];
    $stmt = $mysql->prepare("SELECT * FROM `users` WHERE `user_login` = ?");
    $stmt->bind_param("s", $userLogin);
    $stmt->execute();
    $validResult = $stmt->get_result();
    $userProfile = $validResult->fetch_assoc();
}

$isAdmin = ($userProfile['user_group'] ?? '') === 'admin';

if ($userProfile) {
    if (isset($_POST['changeName']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $name = trim($_POST['user_name'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            header('Location: /profile.php');
            exit();
        }

        $stmt = $mysql->prepare("UPDATE `users` SET `user_name` = ? WHERE `user_login` = ?");
        $stmt->bind_param("ss", $name, $_SESSION['user_login']);
        $stmt->execute();
        $_SESSION['user_name'] = $name;
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeAddress']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $city = $_POST['user_city'] ?? '';
        $street = $_POST['user_street'] ?? '';
        $home = $_POST['user_home'] ?? '';
        $address = "г.$city, ул.$street, д.$home";

        $stmt = $mysql->prepare("UPDATE `users` SET `user_address` = ? WHERE `user_login` = ?");
        $stmt->bind_param("ss", $address, $_SESSION['user_login']);
        $stmt->execute();
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeNumber']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $number = $_POST['user_number'] ?? '';

        $stmt = $mysql->prepare("UPDATE `users` SET `user_number` = ? WHERE `user_login` = ?");
        $stmt->bind_param("ss", $number, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeSurname']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $surname = $_POST['user_surname'] ?? '';

        $stmt = $mysql->prepare("UPDATE `users` SET `user_surname` = ? WHERE `user_login` = ?");
        $stmt->bind_param("ss", $surname, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeEmail']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $email = $_POST['user_email'] ?? '';

        $stmt = $mysql->prepare("UPDATE `users` SET `user_email` = ? WHERE `user_login` = ?");
        $stmt->bind_param("ss", $email, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['deleteAssembly']) && isset($_POST['favoritId'])) {
        csrf_verify();
        $stmt = $mysql->prepare("SELECT orders.assembly_id FROM users,assembly,orders WHERE ? = orders.assembly_id AND users.user_id = ?");
        $stmt->bind_param("ii", $_POST['deleteAssembly'], $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_array();

        $stmt = $mysql->prepare("DELETE FROM favorites WHERE favorit_id = ?");
        $stmt->bind_param("i", $_POST['favoritId']);
        $stmt->execute();

        if (($_POST['deleteAssembly'] > 3) && (!isset($row[0]))) {
            $stmt = $mysql->prepare("DELETE FROM assembly WHERE assembly_id = ?");
            $stmt->bind_param("i", $_POST['deleteAssembly']);
            $stmt->execute();
        }
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['deleteUser']) && $isAdmin) {
        // Перенесено в admin.php?tab=users (ШАГ 4)
        header('Location: /admin.php?tab=users');
        exit();
    }

    if (isset($_POST['editOrderStatus']) && $isAdmin && isset($_POST['orderId'])) {
        // Перенесено в admin.php?tab=orders (ШАГ 3)
        header('Location: /admin.php?tab=orders');
        exit();
    }

    if (isset($_POST['addComponent']) && $isAdmin) {
        // Перенесено в admin.php?tab=components (ШАГ 2)
        header('Location: /admin.php?tab=components');
        exit();
    }
}
?>
<?php
$pageTitle = 'Профиль';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';
?>
        <div class="container_profile">
            <div class="cont_profile cont_profile--plain">
                <?php if (empty($_SESSION['user_id'])): ?>
                    <div class="auth-page">
                        <div class="auth-card card">
                        <div id="login_cont">
                            <h1>Авторизация</h1>
                            <form action="validation/auth.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="auth_login">Логин</label>
                                    <input class="input" id="auth_login" type="text" name="user_login" placeholder="Введите логин" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="auth_pass">Пароль</label>
                                    <input class="input" id="auth_pass" type="password" name="user_pass" placeholder="Введите пароль" required>
                                </div>
                                <p class="alert alert--error" style="margin-bottom:0;"><?php if (isset($_SESSION['error_access'])): ?>
                                        <?= escape($_SESSION['error_access'] ?? '') ?>
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn--primary" type="submit">Войти</button>
                                <div class="auth-switch">
                                    Нет аккаунта?
                                    <button type="button" class="btn btn--ghost btn--sm" data-action="switch" data-a="pass_cont" data-b="login_cont">Зарегистрируйтесь</button>
                                </div>
                            </form>
                        </div>
                        <div id="pass_cont" style="display:none;">
                            <h1>Регистрация</h1>
                            <form action="validation/reg.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="reg_name">Имя</label>
                                    <input class="input" id="reg_name" type="text" name="user_name" placeholder="Введите имя" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_login">Логин</label>
                                    <input class="input" id="reg_login" type="text" name="user_login" placeholder="Введите логин" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_pass">Пароль</label>
                                    <input class="input" id="reg_pass" type="password" name="user_pass" placeholder="Минимум 8 символов" required>
                                </div>
                                <p class="alert alert--error" style="margin-bottom:0;"><?php if (isset($_SESSION['error_access'])): ?>
                                        <?= escape($_SESSION['error_access'] ?? '') ?>
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn--primary" type="submit">Регистрация</button>
                                <div class="auth-switch">
                                    Уже зарегистрированы?
                                    <button type="button" class="btn btn--ghost btn--sm" data-action="switch" data-a="pass_cont" data-b="login_cont">Войдите в аккаунт</button>
                                </div>
                            </form>
                        </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="userProfile" id="userProfile">
                        <div class="profile-layout">
                            <aside class="profile-sidebar">
                                <div class="profile-user">
                                    <div class="profile-avatar"><?= escape(mb_substr($userProfile['user_name'] ?? '?', 0, 1, 'UTF-8')) ?></div>
                                    <div>
                                        <div style="font-size:16px;font-weight:600;"><?= escape($userProfile['user_name'] ?? '') ?></div>
                                        <div style="font-size:13px;color:var(--text-secondary);"><?= escape($userProfile['user_login'] ?? '') ?></div>
                                    </div>
                                </div>
                                <nav>
                                    <button type="button" class="profile-nav-item active" data-action="switch" data-target="card-info">Личная информация</button>
                                    <?php if (!$isAdmin): ?>
                                    <button type="button" class="profile-nav-item" data-action="switch" data-target="card-builds">Мои сборки</button>
                                    <button type="button" class="profile-nav-item" data-action="switch" data-target="card-fav">Избранное</button>
                                    <?php else: ?>
                                    <button type="button" class="profile-nav-item" data-action="switch" data-target="card-admin">Панель управления</button>
                                    <?php endif; ?>
                                </nav>
                                <div style="border-top:1px solid var(--border);margin:16px 0;"></div>
                                <a href="validation/exit.php" class="btn btn--ghost" style="color:var(--error);">Выйти</a>
                            </aside>
                            <div class="profile-content">
                            <section class="card" id="card-info" data-section>
                                <h2>Личная информация</h2>
                                <div class="profile-field" data-field="name">
                                    <div class="profile-field-label">Имя</div>
                                    <div class="profile-field-value"><?= htmlspecialchars($userProfile['user_name'] ?? '') ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_name" placeholder="Имя" value="<?= escape($userProfile['user_name'] ?? '') ?>">
                                        <button class="btn btn--primary btn--sm" name="changeName" type="submit">Сохранить</button>
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="cancel-edit">Отмена</button>
                                    </form>
                                    <button class="btn btn--ghost btn--sm" type="button" data-action="edit">Изменить</button>
                                </div>
                                <div class="profile-field" data-field="surname">
                                    <div class="profile-field-label">Фамилия</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_surname']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_surname']) ? escape($userProfile['user_surname']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_surname" placeholder="Фамилия" value="<?= escape($userProfile['user_surname'] ?? '') ?>">
                                        <button class="btn btn--primary btn--sm" name="changeSurname" type="submit">Сохранить</button>
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="cancel-edit">Отмена</button>
                                    </form>
                                    <button class="btn btn--ghost btn--sm" type="button" data-action="edit">Изменить</button>
                                </div>
                                <div class="profile-field" data-field="email">
                                    <div class="profile-field-label">Почта</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_email']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_email']) ? escape($userProfile['user_email']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_email" placeholder="Электронная почта" value="<?= escape($userProfile['user_email'] ?? '') ?>">
                                        <button class="btn btn--primary btn--sm" name="changeEmail" type="submit">Сохранить</button>
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="cancel-edit">Отмена</button>
                                    </form>
                                    <button class="btn btn--ghost btn--sm" type="button" data-action="edit">Изменить</button>
                                </div>
                                <div class="profile-field" data-field="address">
                                    <div class="profile-field-label">Адрес</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_address']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_address']) ? escape($userProfile['user_address']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit profile-field-edit--stack" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_city" placeholder="Город">
                                        <input class="input" type="text" name="user_street" placeholder="Улица">
                                        <input class="input" type="text" name="user_home" placeholder="Дом">
                                        <div class="edit-form-actions">
                                        <button class="btn btn--primary btn--sm" name="changeAddress" type="submit">Сохранить</button>
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="cancel-edit">Отмена</button>
                                        </div>
                                    </form>
                                    <button class="btn btn--ghost btn--sm" type="button" data-action="edit">Изменить</button>
                                </div>
                                <div class="profile-field" data-field="number">
                                    <div class="profile-field-label">Телефон</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_number']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_number']) ? escape($userProfile['user_number']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="tel" name="user_number" placeholder="+7(XXX)XXX-XX-XX" required
                                            pattern="\+7\s?[\(]{0,1}[0-9][0-9]{2}[\)]{0,1}\s?\d{3}[-]{0,1}\d{2}[-]{0,1}\d{2}" value="<?= escape($userProfile['user_number'] ?? '') ?>">
                                        <button class="btn btn--primary btn--sm" name="changeNumber" type="submit">Сохранить</button>
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="cancel-edit">Отмена</button>
                                    </form>
                                    <button class="btn btn--ghost btn--sm" type="button" data-action="edit">Изменить</button>
                                </div>
                            </section>
                            <?php if ($isAdmin): ?>
                            <section class="card" id="card-admin" data-section style="display:none;">
                                <h2>Панель управления</h2>
                                <div class="admin-grid">
                                    <a href="/admin.php?tab=users" class="btn btn--secondary" style="text-decoration:none;">Управление пользователями</a>
                                    <a href="/admin.php?tab=orders" class="btn btn--secondary" style="text-decoration:none;">Управление заказами</a>
                                    <a href="/admin.php?tab=components" class="btn btn--secondary" style="text-decoration:none;">Управление комплектующими</a>
                                </div>
                            </section>
                            <?php else: ?>
                            <section class="card" id="card-fav" data-section style="display:none;">
                                <h2>Избранное</h2>
                                            <div class="contTable">
                                                <?php
                                                $sql = "SELECT assembly_name,assembly_price,assembly.assembly_id,favorites.favorit_id FROM users,assembly,favorites
                                                        WHERE assembly.assembly_id = favorites.assembly_id AND users.user_id = ? AND favorites.user_id = ?
                                                        ORDER BY `favorites`.`favorit_id` ASC";
                                                $stmt = $mysql->prepare($sql);
                                                $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                $favRows = [];
                                                while ($row = $result->fetch_array()) {
                                                    $favRows[] = $row;
                                                }

                                                if (empty($favRows)) {
                                                    echo '<div class="profile-empty">Пока нет избранного</div>';
                                                } else {
                                                echo '<div class="table-wrap"><table class="table"><thead><tr><th>Название сборки</th><th>Стоимость</th><th></th></tr></thead><tbody>';

                                                foreach ($favRows as $row) {
                                                    if ($row['assembly_id'] > 3) {
                                                        $row['assembly_name'] = "Сборка " . ($row[0] ?? '');
                                                    }
                                                    echo "<tr>"
                                                        . "<td><a href=\"assembly.php?check-saved={$row['assembly_id']}\">" . htmlspecialchars($row['assembly_name'] ?? '') . "</a></td>"
                                                        . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                                        . "<td><form method=\"POST\">"
                                                        . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape(csrf_token()) . "\">"
                                                        . "<input style=\"display:none\" name=\"favoritId\" type=\"hidden\" value=\"{$row['favorit_id']}\">"
                                                        . "<button class=\"btn btn--ghost btn--sm\" name=\"deleteAssembly\" type=\"submit\" value=\"{$row['assembly_id']}\">Удалить</button>"
                                                        . "</form></td>"
                                                        . "</tr>";
                                                }
                                                echo '</tbody></table></div>';
                                                }
                                                ?>
                                            </div>
                            </section>
                            <section class="card" id="card-builds" data-section style="display:none;">
                                            <h2>Мои сборки</h2>
                                            <div class="contTable">
                                                <?php
                                                $checkSql = "SHOW COLUMNS FROM orders LIKE 'status'";
                                                $checkStmt = $mysql->prepare($checkSql);
                                                $checkStmt->execute();
                                                $checkResult = $checkStmt->get_result();

                                                if ($checkResult && $checkResult->num_rows > 0) {
                                                    $sql = "SELECT assembly_name,assembly_price,assembly.assembly_id,status FROM users,assembly,orders
                                                            WHERE assembly.assembly_id = orders.assembly_id AND users.user_id = ? AND orders.user_id = ?
                                                            ORDER BY `orders`.`order_id` ASC";
                                                    $stmt = $mysql->prepare($sql);
                                                    $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();

                                                    $ordRows = [];
                                                    while ($row = $result->fetch_array()) {
                                                        $ordRows[] = $row;
                                                    }

                                                    if (empty($ordRows)) {
                                                        echo '<div class="profile-empty">Пока нет сборок</div>';
                                                    } else {
                                                    echo '<div class="table-wrap"><table class="table"><thead><tr><th>Название сборки</th><th>Стоимость</th><th>Статус</th></tr></thead><tbody>';

                                                    foreach ($ordRows as $row) {
                                                        if ($row['assembly_id'] > 3) {
                                                            $row['assembly_name'] = "Сборка " . ($row[0] ?? '');
                                                        }
                                                        $statusCls = (($row['status'] ?? '') === 'Выполнен') ? 'badge--success' : 'badge--warning';
                                                        echo "<tr>"
                                                            . "<td><a href=\"assembly.php?check-purchased={$row['assembly_id']}\">" . htmlspecialchars($row['assembly_name'] ?? '') . "</a></td>"
                                                            . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                                            . "<td><span class=\"badge " . $statusCls . "\">" . htmlspecialchars($row['status'] ?? '') . "</span></td>"
                                                            . "</tr>";
                                                    }
                                                    echo '</tbody></table></div>';
                                                    }
                                                } else {
                                                    echo "<p>Статус заказов временно недоступен</p>";
                                                }
                                                ?>
                                            </div>
                            </section>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                        <div class="userProfile" id="userMonitor" style="display:none;">
                            <h1>Управление пользователями</h1>
                            <div class="containerMonitor">
                                <?php
                                echo "<span class=\"assemblyTable\"><span>Имя</span><span>Логин</span><span>Группа</span><span style=\"width:60%\">Адрес</span><span>Номер</span><span></span></span><br><div class=\"lineSpan\"></div>";
                                $sql = "SELECT user_id,user_name, user_login, user_group, user_address, user_number FROM users";
                                $stmt = $mysql->prepare($sql);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        echo "<form method=\"POST\">
                                                <input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">
                                                <span class=\"assemblyTable\">
                                                    <span>" . htmlspecialchars($row['user_name'] ?? '') . "</span>
                                                    <span>" . htmlspecialchars($row['user_login'] ?? '') . "</span>
                                                    <span>" . htmlspecialchars($row['user_group'] ?? '') . "</span>
                                                    <span style=\"width:60%\">" . htmlspecialchars($row['user_address'] ?? '') . "</span>
                                                    <span>" . htmlspecialchars($row['user_number'] ?? '') . "</span>
                                                    <span>
                                                        <input style=\"display:none\" name=\"userId\" type=\"hidden\" value=\"" . htmlspecialchars($row['user_id'] ?? '') . "\">
                                                        <button class=\"delBtn\" name=\"deleteUser\" type=\"submit\">Удалить</button>
                                                    </span>
                                                </span>
                                                <br>
                                              </form>";
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php $mysql->close(); ?>