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
$userLogin = $_SESSION['user_login'] ?? null;
if ($userLogin) {
    $stmt = db_prepare($mysql, "SELECT * FROM `users` WHERE `user_login` = ?", "s", $userLogin);
    $stmt->execute();
    $validResult = $stmt->get_result();
    $userProfile = $validResult->fetch_assoc();

    // 5-a: сессия переживает удаление пользователя из базы. Раньше страница
    // решала, показывать профиль или форму входа, по $_SESSION['user_id'],
    // а не по наличию строки в users. Из-за этого удалённый пользователь
    // видел пустой профиль: все поля «Не указано», логин без имени, и любое
    // сохранение уходило в ноль строк, ничем не выдавая себя. Теперь такой
    // сессии просто не существует - чистим её и отправляем на форму входа.
    // Сброс стоит до обработчиков POST: удалённый пользователь не должен
    // «обновлять» профиль и POST-запросом.
    if (!$userProfile) {
        // 5-b: тот же сброс, что и в validation/exit.php, вынесен в
        // общий logout_user()
        logout_user();
        header('Location: /profile.php');
        exit();
    }
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

        $stmt = db_prepare($mysql, "UPDATE `users` SET `user_name` = ? WHERE `user_login` = ?", "ss", $name, $_SESSION['user_login']);
        $stmt->execute();
        $_SESSION['user_name'] = $name;
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeAddress']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        // 3.7-i-3: адрес разбит на поля. Раньше отсюда собиралась строка
        // «г.$city, ул.$street, д.$home» в user_address, причём без
        // проверок, а поле называлось user_home - такой колонки нет.
        // Теперь пишем в шесть колонок, user_address не трогаем: это
        // legacy-значение из миграции 3.7-i-1.
        $addr = [
            'postal_code' => trim($_POST['user_postal_code'] ?? ''),
            'region' => trim($_POST['user_region'] ?? ''),
            'city' => trim($_POST['user_city'] ?? ''),
            'street' => trim($_POST['user_street'] ?? ''),
            'house' => trim($_POST['user_house'] ?? ''),
            'apartment' => trim($_POST['user_apartment'] ?? ''),
        ];

        // предельные длины из схемы: users - MyISAM, лишнее обрезалось бы
        // молча, поэтому проверяем до записи
        $addrLimits = [
            'postal_code' => 10,
            'region' => 100,
            'city' => 100,
            'street' => 150,
            'house' => 20,
            'apartment' => 20,
        ];
        foreach ($addrLimits as $key => $limit) {
            if (mb_strlen($addr[$key], 'UTF-8') > $limit) {
                header('Location: /profile.php?error=' . $key);
                exit();
            }
        }
        // индекс: 5-10 цифр, пустое значение допустимо
        if ($addr['postal_code'] !== '' && !preg_match('/^\d{5,10}$/', $addr['postal_code'])) {
            header('Location: /profile.php?error=postal_code');
            exit();
        }

        $params = [];
        foreach ($addr as $value) {
            $params[] = $value !== '' ? $value : null;
        }
        $params[] = $_SESSION['user_login'];

        $stmt = db_prepare($mysql, "UPDATE `users` SET
                                        user_postal_code = ?, user_region = ?, user_city = ?,
                                        user_street = ?, user_house = ?, user_apartment = ?
                                     WHERE `user_login` = ?", "sssssss", ...$params);
        $stmt->execute();
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeNumber']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $number = $_POST['user_number'] ?? '';

        $stmt = db_prepare($mysql, "UPDATE `users` SET `user_number` = ? WHERE `user_login` = ?", "ss", $number, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeSurname']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $surname = $_POST['user_surname'] ?? '';

        $stmt = db_prepare($mysql, "UPDATE `users` SET `user_surname` = ? WHERE `user_login` = ?", "ss", $surname, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['changeEmail']) && isset($_SESSION['user_login'])) {
        csrf_verify();
        $email = $_POST['user_email'] ?? '';

        $stmt = db_prepare($mysql, "UPDATE `users` SET `user_email` = ? WHERE `user_login` = ?", "ss", $email, $_SESSION['user_login']);
        $stmt->execute();
        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['deleteAssembly']) && isset($_POST['favoritId'])) {
        csrf_verify();
        $stmt = db_prepare($mysql, "SELECT orders.assembly_id FROM users,assembly,orders WHERE ? = orders.assembly_id AND users.user_id = ?", "ii", $_POST['deleteAssembly'], $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_array();

        $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE favorit_id = ?", "i", $_POST['favoritId']);
        $stmt->execute();

        if (($_POST['deleteAssembly'] > 3) && (!isset($row[0]))) {
            $stmt = db_prepare($mysql, "DELETE FROM assembly WHERE assembly_id = ?", "i", $_POST['deleteAssembly']);
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
                <?php // 5-a: решение по строке из users, а не по флагу сессии.
                      // Флаг живёт дольше пользователя, строка - нет. ?>
                <?php if (!$userProfile): ?>
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
<?php
// 3.7-i-6: спрайт иконок для кнопок редактирования. Один символ на
// страницу вместо пяти одинаковых <svg> внутри каждой кнопки
$profileInitial = mb_strtoupper(mb_substr((string) ($userProfile['user_name'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8');
$profileFullName = trim(($userProfile['user_name'] ?? '') . ' ' . ($userProfile['user_surname'] ?? ''));
?>
                                <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
                                    <symbol id="icon-edit" viewBox="0 0 512 512">
                                        <rect x="150" y="96" width="216" height="88" rx="12" fill="none" stroke="currentColor" stroke-width="32" transform="rotate(-45 258 140)"></rect>
                                    <!-- 3.7-g-5: галочка и крестик для inline-edit,
                                         viewBox 24 как у Feather-иконок -->
                                    <symbol id="icon-check" viewBox="0 0 24 24">
                                        <polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
                                    </symbol>
                                    <symbol id="icon-close" viewBox="0 0 24 24">
                                        <line x1="18" y1="6" x2="6" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
                                        <line x1="6" y1="6" x2="18" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
                                    </symbol>
                                </svg>

                                <!-- 3.7-i-6: шапка профиля вместо голого h2 -->
                                <div class="profile-header">
                                    <div class="profile-header-avatar"><?= escape($profileInitial) ?></div>
                                    <div class="profile-header-info">
                                        <h2><?= escape($profileFullName !== '' ? $profileFullName : (string) ($userProfile['user_login'] ?? '')) ?></h2>
                                        <div class="profile-header-login">@<?= escape((string) ($userProfile['user_login'] ?? '')) ?></div>
                                        <!-- 3.7-g-6: бейдж роли и дата регистрации
                                             в одну строку, раньше бейдж стоял сам -->
                                        <div class="profile-header-meta">
                                            <span class="badge <?= $isAdmin ? 'badge--success' : 'badge--warning' ?>">
                                                <?= $isAdmin ? 'Администратор' : 'Пользователь' ?>
                                            </span>
                                            <span class="profile-header-since">
                                                С нами с <?= escape(date('d.m.Y', strtotime((string) ($userProfile['user_regdate'] ?? 'now')))) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
<?php
// 3.7-i-3: раньше сообщения об ошибке показывались только на карточке
// входа (там $_SESSION['error_access']), поэтому залогиненный пользователь
// о неудачном сохранении адреса не узнавал. Тот же приём, что в админке:
// код ошибки в GET, текст из карты.
$profileErrors = [
    'postal_code' => 'Индекс должен состоять из 5-10 цифр.',
    'region' => 'Регион не должен быть длиннее 100 символов.',
    'city' => 'Город не должен быть длиннее 100 символов.',
    'street' => 'Улица не должна быть длиннее 150 символов.',
    'house' => 'Дом не должен быть длиннее 20 символов.',
    'apartment' => 'Квартира не должна быть длиннее 20 символов.',
];
if (isset($_GET['error']) && isset($profileErrors[$_GET['error']])):
?>
                                <div class="alert alert--error"><?= escape($profileErrors[$_GET['error']]) ?></div>
<?php endif; ?>
                                <!-- 3.7-i-6: поля в две колонки, каждое своей плиткой -->
                                <div class="profile-fields">
                                <div class="profile-field" data-field="name">
                                    <div class="profile-field-label">Имя</div>
                                    <div class="profile-field-value"><?= htmlspecialchars($userProfile['user_name'] ?? '') ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_name" placeholder="Имя" value="<?= escape($userProfile['user_name'] ?? '') ?>">
                                        <!-- 3.7-g-5: текстовые кнопки занимали строку и
                                             сжимали инпут до 78px, теперь иконки -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeName" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <!-- 3.7-i-6: иконка-карандаш вместо текста «Изменить».
                                         Спрайт один на страницу, символ берётся через <use> -->
                                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                <div class="profile-field" data-field="surname">
                                    <div class="profile-field-label">Фамилия</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_surname']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_surname']) ? escape($userProfile['user_surname']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_surname" placeholder="Фамилия" value="<?= escape($userProfile['user_surname'] ?? '') ?>">
                                        <!-- 3.7-g-5: текстовые кнопки занимали строку и
                                             сжимали инпут до 78px, теперь иконки -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeSurname" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <!-- 3.7-i-6: иконка-карандаш вместо текста «Изменить».
                                         Спрайт один на страницу, символ берётся через <use> -->
                                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                <div class="profile-field" data-field="email">
                                    <div class="profile-field-label">Почта</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_email']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_email']) ? escape($userProfile['user_email']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_email" placeholder="Электронная почта" value="<?= escape($userProfile['user_email'] ?? '') ?>">
                                        <!-- 3.7-g-5: текстовые кнопки занимали строку и
                                             сжимали инпут до 78px, теперь иконки -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeEmail" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <!-- 3.7-i-6: иконка-карандаш вместо текста «Изменить».
                                         Спрайт один на страницу, символ берётся через <use> -->
                                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                <div class="profile-field" data-field="address">
<?php
                                // 3.7-i-3: адрес собирается из шести полей.
                                // 3.7-i-2/FIX-2: fallback на legacy user_address
                                // убран - новые поля единственный источник
                                // правды, иначе очищенный пользователем адрес
                                // воскресал бы из старой строки.
                                $addrParts = array_filter([
                                    $userProfile['user_postal_code'] ?? '',
                                    $userProfile['user_region'] ?? '',
                                    $userProfile['user_city'] ?? '',
                                    $userProfile['user_street'] ?? '',
                                    !empty($userProfile['user_house']) ? 'д. ' . $userProfile['user_house'] : '',
                                    !empty($userProfile['user_apartment']) ? 'кв. ' . $userProfile['user_apartment'] : '',
                                ]);
                                $fullAddress = implode(', ', $addrParts);
?>
                                    <div class="profile-field-label">Адрес</div>
                                    <div class="profile-field-value"<?= $fullAddress === '' ? ' data-empty' : '' ?>><?= $fullAddress !== '' ? escape($fullAddress) : 'Не указано' ?></div>
                                    <form class="profile-field-edit profile-field-edit--stack" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_postal_code" placeholder="Индекс" maxlength="10"
                                               inputmode="numeric" value="<?= escape($userProfile['user_postal_code'] ?? '') ?>">
                                        <input class="input" type="text" name="user_region" placeholder="Регион" maxlength="100"
                                               value="<?= escape($userProfile['user_region'] ?? '') ?>">
                                        <input class="input" type="text" name="user_city" placeholder="Город" maxlength="100"
                                               value="<?= escape($userProfile['user_city'] ?? '') ?>">
                                        <input class="input" type="text" name="user_street" placeholder="Улица" maxlength="150"
                                               value="<?= escape($userProfile['user_street'] ?? '') ?>">
                                        <input class="input" type="text" name="user_house" placeholder="Дом" maxlength="20"
                                               value="<?= escape($userProfile['user_house'] ?? '') ?>">
                                        <input class="input" type="text" name="user_apartment" placeholder="Квартира" maxlength="20"
                                               value="<?= escape($userProfile['user_apartment'] ?? '') ?>">
                                        <div class="edit-form-actions">
                                        <!-- 3.7-g-5: текстовые кнопки занимали строку и
                                             сжимали инпут до 78px, теперь иконки -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeAddress" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                        </div>
                                    </form>
                                    <!-- 3.7-i-6: иконка-карандаш вместо текста «Изменить».
                                         Спрайт один на страницу, символ берётся через <use> -->
                                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                <div class="profile-field" data-field="number">
                                    <div class="profile-field-label">Телефон</div>
                                    <div class="profile-field-value"<?= empty($userProfile['user_number']) ? ' data-empty' : '' ?>><?= !empty($userProfile['user_number']) ? escape($userProfile['user_number']) : 'Не указано' ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="tel" name="user_number" placeholder="+7(XXX)XXX-XX-XX" required
                                            pattern="\+7\s?[\(]{0,1}[0-9][0-9]{2}[\)]{0,1}\s?\d{3}[-]{0,1}\d{2}[-]{0,1}\d{2}" value="<?= escape($userProfile['user_number'] ?? '') ?>">
                                        <!-- 3.7-g-5: текстовые кнопки занимали строку и
                                             сжимали инпут до 78px, теперь иконки -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeNumber" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <!-- 3.7-i-6: иконка-карандаш вместо текста «Изменить».
                                         Спрайт один на страницу, символ берётся через <use> -->
                                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                </div>
                            </section>
                            <!-- 3.7-f-4: промежуточная админ-карточка удалена —
     в сайдбаре ссылка на /admin.php, внутри админки свой сайдбар с вкладками -->
                            <?php if (!$isAdmin): ?>
                            <section class="card" id="card-fav" data-section<?= $sectionStyle('card-fav') ?>>
                                <h2 class="page-title">Избранное</h2>
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
                                                        // 3.7-g-4: подтверждение перед удалением.
                                                        // deleteAssembly дублируется скрытым input:
                                                        // форму отправляет form.submit(), а он не
                                                        // включает имя нажатой submit-кнопки.
                                                        . "<input type=\"hidden\" name=\"deleteAssembly\" value=\"{$row['assembly_id']}\">"
                                                        . "<button class=\"btn btn--ghost btn--sm row-btn-danger\" type=\"submit\" data-action=\"delete-favorite\" data-name=\"" . htmlspecialchars($favName, ENT_QUOTES) . "\" title=\"Убрать из избранного\">"
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
                                            <h2 class="page-title">Мои заказы</h2>
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
                                                // 3.7-g-5: колонка «№» убрана, пользователю номер заказа не нужен.
// В модалке он по-прежнему показывается - там он помогает сослаться
// на заказ при поддержке.
                                                echo '<table class="table"><thead><tr><th>Сборка</th><th>Стоимость</th><th>Статус</th><th>Дата</th></tr></thead><tbody>';

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
                                                        // 3.7-g-5: колонки с номером заказа больше нет
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

                <?php endif; ?>
            </div>
        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php $mysql->close(); ?>
