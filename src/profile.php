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
        <div class="container_profile container_profile--fluid">
            <div class="cont_profile cont_profile--plain">
                <?php if (empty($_SESSION['user_id'])): ?>
                    <?php
                    // значения предыдущей попытки (после редиректа $_POST пуст)
                    $oldLogin = $_SESSION['old_login'] ?? '';
                    $oldName = $_SESSION['old_name'] ?? '';
                    unset($_SESSION['old_login'], $_SESSION['old_name']);
                    ?>
                    <div class="auth-page">
                        <div class="auth-card card">
                        <div id="login_cont">
                            <h1>Авторизация</h1>
                            <form action="validation/auth.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="auth_login">Логин</label>
                                    <input class="input" id="auth_login" type="text" name="user_login" placeholder="Введите логин" value="<?= escape($oldLogin) ?>" required>
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
                                    <input class="input" id="reg_name" type="text" name="user_name" placeholder="Введите имя" value="<?= escape($oldName) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_login">Логин</label>
                                    <input class="input" id="reg_login" type="text" name="user_login" placeholder="Введите логин" value="<?= escape($oldLogin) ?>" required>
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
                    <?php // 3.7-f-4-1: единый сайдбар (тот же partial, что и в admin.php) ?>
                    <?php $activeTab = 'profile'; ?>
                    <?php
                    // 3.7-f-4b-5: секцию можно открыть ссылкой, чтобы из модалки
                    // пользователя в админке попасть в «Мои заказы» без кликов.
                    // Значение из GET не идёт в разметку как есть - только по белому
                    // списку, id секции берётся из массива, а не из запроса.
                    $sectionMap = ['info' => 'card-info', 'orders' => 'card-builds', 'fav' => 'card-fav'];
                    $sectionKey = (string) ($_GET['section'] ?? 'info');
                    $activeSection = $sectionMap[$sectionKey] ?? 'card-info';
                    // секции заказов и избранного рисуются только обычному
                    // пользователю; для админа параметр игнорируется, иначе
                    // ?section=orders скрыл бы единственную карточку и страница
                    // осталась бы пустой
                    if ($isAdmin) {
                        $activeSection = 'card-info';
                    }
                    // скрываем секцию, если она не выбранная
                    $sectionStyle = static function (string $id) use ($activeSection): string {
                        return $id === $activeSection ? '' : ' style="display:none;"';
                    };
                    ?>
                    <div class="userProfile" id="userProfile">
                        <div class="profile-layout">
<?php require __DIR__ . '/partials/profile-sidebar.php'; ?>
                            <div class="profile-content">
                            <section class="card" id="card-info" data-section<?= $sectionStyle('card-info') ?>>
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
                            <!-- 3.7-f-4: промежуточная админ-карточка удалена —
     в сайдбаре ссылка на /admin.php, внутри админки свой сайдбар с вкладками -->
                            <?php if (!$isAdmin): ?>
                            <section class="card" id="card-fav" data-section<?= $sectionStyle('card-fav') ?>>
                                <h2>Избранное</h2>
                                            <!-- 3.7-f-4b-1: обёртка contTable заменена на .table-wrap -->
                                            <div class="table-wrap">
                                                <?php
                                                // 3.7-f-4b-2: без лишнего FROM users - фильтр идёт
                                                // по favorites.user_id, порядок DESC
                                                $sql = "SELECT a.assembly_name, a.assembly_price, a.assembly_id, f.favorit_id
                                                        FROM favorites f
                                                        JOIN assembly a ON a.assembly_id = f.assembly_id
                                                        WHERE f.user_id = ?
                                                        ORDER BY f.favorit_id DESC";
                                                $stmt = db_prepare($mysql, $sql, "i", $_SESSION['user_id']);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                $favRows = [];
                                                while ($row = $result->fetch_array()) {
                                                    $favRows[] = $row;
                                                }

                                                if (empty($favRows)) {
                                                    echo '<div class="profile-empty">Пока нет избранного</div>';
                                                } else {
                                                echo '<table class="table"><thead><tr><th>Название сборки</th><th>Стоимость</th><th></th></tr></thead><tbody>';

                                                foreach ($favRows as $row) {
                                                    // 3.7-f-4b-2: явное поле вместо хрупкого $row[0]
                                                    $favName = $row['assembly_name'] ?? '';
                                                    if (($row['assembly_id'] ?? 0) > 3) {
                                                        $favName = "Сборка " . $favName;
                                                    }
                                                    // вся строка - ссылка на просмотр сборки
                                                    echo "<tr class=\"row-link\" data-href=\"/assembly.php?check-saved={$row['assembly_id']}\">"
                                                        . "<td>" . htmlspecialchars($favName) . "</td>"
                                                        . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                                        . "<td><form method=\"POST\">"
                                                        . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape(csrf_token()) . "\">"
                                                        . "<input name=\"favoritId\" type=\"hidden\" value=\"{$row['favorit_id']}\">"
                                                        . "<button class=\"btn btn--ghost btn--sm row-btn-danger\" name=\"deleteAssembly\" type=\"submit\" value=\"{$row['assembly_id']}\" title=\"Убрать из избранного\">"
                                                        . "<img src=\"/assets/images/trash-outline.svg\" alt=\"Удалить\" width=\"18\" height=\"18\">"
                                                        . "</button>"
                                                        . "</form></td>"
                                                        . "</tr>";
                                                }
                                                echo '</tbody></table>';
                                                }
                                                ?>
                                            </div>
                            </section>
                            <section class="card" id="card-builds" data-section<?= $sectionStyle('card-builds') ?>>
                                            <h2>Мои заказы</h2>
                                            <!-- 3.7-f-4b-1: обёртка contTable заменена на .table-wrap -->
                                            <div class="table-wrap">
                                                <?php
                                                // 3.7-f-4b-3: «Мои сборки» -> «Мои заказы».
                                                // Таблица и раньше брала заказы из orders, но без
                                                // номера и даты, а название сборки вело на её
                                                // страницу. Теперь это список заказов, а детали
                                                // открываются read-only модалкой.
                                                // Проверка SHOW COLUMNS на status убрана: admin.php
                                                // и так требует orders.created_at, поэтому такая
                                                // «защита» лишь прятала бы ошибку.
                                                $sql = "SELECT o.order_id, o.status, o.created_at,
                                                               a.assembly_name, a.assembly_price, o.assembly_id
                                                        FROM orders o
                                                        JOIN assembly a ON a.assembly_id = o.assembly_id
                                                        WHERE o.user_id = ?
                                                        ORDER BY o.created_at DESC";
                                                $stmt = db_prepare($mysql, $sql, "i", $_SESSION['user_id']);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                $ordRows = [];
                                                while ($row = $result->fetch_array()) {
                                                    $ordRows[] = $row;
                                                }

                                                if (empty($ordRows)) {
                                                    echo '<div class="profile-empty">Пока нет заказов</div>';
                                                } else {
                                                echo '<table class="table"><thead><tr><th>№</th><th>Сборка</th><th>Стоимость</th><th>Статус</th><th>Дата</th></tr></thead><tbody>';

                                                foreach ($ordRows as $row) {
                                                    // 3.7-f-4b-3: явное поле вместо хрупкого $row[0]
                                                    $ordAsmName = $row['assembly_name'] ?? '';
                                                    if (($row['assembly_id'] ?? 0) > 3) {
                                                        $ordAsmName = "Сборка " . $ordAsmName;
                                                    }
                                                    $statusCls = (($row['status'] ?? '') === 'Выполнен') ? 'badge--success' : 'badge--warning';
                                                    $ordCreated = ($row['created_at'] ?? '')
                                                        ? date('d.m.Y H:i', strtotime((string) $row['created_at']))
                                                        : '';
                                                    $ordData = json_encode([
                                                        'modal' => 'user-order',
                                                        'order_id' => $row['order_id'],
                                                        'assembly_id' => $row['assembly_id'],
                                                        'assembly_name' => $ordAsmName,
                                                        'price' => $row['assembly_price'],
                                                        'status' => $row['status'],
                                                        'created_at' => $ordCreated,
                                                    ], JSON_UNESCAPED_UNICODE);
                                                    echo "<tr class=\"row-link\" data-row='" . escape($ordData) . "'>"
                                                        . "<td>" . htmlspecialchars((string) $row['order_id']) . "</td>"
                                                        . "<td>" . htmlspecialchars($ordAsmName) . "</td>"
                                                        . "<td>" . htmlspecialchars((string) ($row['assembly_price'] ?? '')) . " руб.</td>"
                                                        . "<td><span class=\"badge " . $statusCls . "\">" . htmlspecialchars($row['status'] ?? '') . "</span></td>"
                                                        . "<td>" . htmlspecialchars($ordCreated) . "</td>"
                                                        . "</tr>";
                                                }
                                                echo '</tbody></table>';
                                                }
                                                ?>
                                            </div>

                                            <!--
                                                3.7-f-4b-3: просмотр заказа только для чтения.
                                                Ни формы, ни кнопок сохранения - одна кнопка
                                                «Закрыть». Все поля заполняет JS из data-row
                                                строки, поэтому модалка ничего не отправляет.
                                            -->
                                            <dialog id="userOrderModal" class="modal">
                                                <div class="modal-form">
                                                    <h2>Заказ №<span id="userOrderNumber"></span></h2>

                                                    <div class="modal-section">
                                                        <h3>Сборка</h3>
                                                        <div class="form-group">
                                                            <label class="form-label">Название</label>
                                                            <!-- 3.7-f-4c-5: название открывает сборку в
                                                                 новой вкладке, как в editOrderModal -->
                                                            <a href="#" id="userOrderAssemblyLink" target="_blank" rel="noopener"
                                                               class="btn btn--secondary btn--sm"><span id="userOrderAssembly"></span></a>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label">Стоимость</label>
                                                            <div id="userOrderPrice"></div>
                                                        </div>
                                                    </div>

                                                    <div class="modal-section">
                                                        <h3>Заказ</h3>
                                                        <div class="form-group">
                                                            <label class="form-label">Статус</label>
                                                            <div><span class="badge" id="userOrderStatus"></span></div>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label">Создан</label>
                                                            <div id="userOrderCreated"></div>
                                                        </div>
                                                    </div>

                                                    <div class="modal-actions">
                                                        <div class="modal-actions-right">
                                                            <button type="button" class="btn btn--secondary" data-action="close-modal">Закрыть</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </dialog>
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