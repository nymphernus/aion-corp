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

    // Показывать профиль или форму входа решает наличие строки в users,
    // а не $_SESSION['user_id']: сессия переживает удаление пользователя.
    // Сброс стоит до обработчиков POST - удалённый пользователь не
    // должен «обновлять» профиль POST-запросом.
    if (!$userProfile) {
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
        // Адрес пишется в шесть колонок; user_address не трогаем - это
        // legacy-значение из миграции.
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

    // Проверка текущего пароля обязательна: без неё любой, у кого открыта
    // сессия, заменил бы пароль, не зная прежнего.
    //
    // Порядок проверок - от самой вероятной причины: так пользователь
    // видит одну, а не четыре сразу.
    $passwordError = null;
    if (isset($_POST['changePassword'])) {
        csrf_verify();

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $pwErrors = [];

        if (!password_verify($current, (string) $userProfile['user_pass'])) {
            $pwErrors[] = 'Текущий пароль неверен';
        }

        // 8-20 - те же границы, что и при регистрации в reg.php
        if (mb_strlen($new, 'UTF-8') < 8 || mb_strlen($new, 'UTF-8') > 20) {
            $pwErrors[] = 'Новый пароль должен быть от 8 до 20 символов';
        }

        if ($new !== '' && $new === $current) {
            $pwErrors[] = 'Новый пароль совпадает с текущим';
        }

        if ($new !== $confirm) {
            $pwErrors[] = 'Пароли не совпадают';
        }

        if ($pwErrors) {
            // Ошибки показываем на карточке без редиректа: иначе текст
            // потерялся бы вместе с POST. Поля пароля остаются пустыми -
            // вводить заново не придётся.
            $passwordError = implode('. ', $pwErrors);
            unset($_POST['current_password'], $_POST['new_password'], $_POST['new_password_confirm']);
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $stmt = db_prepare(
                $mysql,
                "UPDATE `users` SET `user_pass` = ? WHERE `user_id` = ?",
                "si",
                $hash,
                (int) $userProfile['user_id']
            );
            $stmt->execute();
            $stmt->close();

            csrf_rotate();
            // section=security - ключ в $sectionMap; card-security это уже
            // результат отображения, и с ним ключ не нашёлся бы.
            header('Location: /profile.php?section=security&password_changed=1');
            exit();
        }
    }

    // Заявка на верификацию контактов живёт внутри card-security: это
    // часть безопасности аккаунта, а не отдельный раздел.
    //
    // Реальной отправки кода нет: кнопка ставит флаг заявки, а
    // подтверждает администратор вручную в модалке пользователя.
    //
    // Заявка принимается только при заполненном контакте и только один
    // раз: если флаг уже стоит, UPDATE не выполняется.
    if (isset($_POST['requestEmailVerification'])) {
        csrf_verify();

        if (!empty($userProfile['user_email'])
            && empty($userProfile['email_verified'])
            && empty($userProfile['email_verification_requested'])) {
            $stmt = db_prepare(
                $mysql,
                "UPDATE `users` SET `email_verification_requested` = 1 WHERE `user_id` = ?",
                "i",
                (int) $userProfile['user_id']
            );
            $stmt->execute();
            $stmt->close();
        }

        csrf_rotate();
        header('Location: /profile.php?section=security');
        exit();
    }

    if (isset($_POST['requestPhoneVerification'])) {
        csrf_verify();

        if (!empty($userProfile['user_number'])
            && empty($userProfile['phone_verified'])
            && empty($userProfile['phone_verification_requested'])) {
            $stmt = db_prepare(
                $mysql,
                "UPDATE `users` SET `phone_verification_requested` = 1 WHERE `user_id` = ?",
                "i",
                (int) $userProfile['user_id']
            );
            $stmt->execute();
            $stmt->close();
        }

        csrf_rotate();
        header('Location: /profile.php?section=security');
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
        $assemblyId = (int) $_POST['deleteAssembly'];
        $stmt = db_prepare($mysql, "SELECT orders.assembly_id FROM users,assembly,orders WHERE ? = orders.assembly_id AND users.user_id = ?", "ii", $assemblyId, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_array();

        $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE favorit_id = ?", "i", $_POST['favoritId']);
        $stmt->execute();

        // Саму сборку удаляем только если она не заказана и не базовая.
        // Признак базовой - флаг, а не номер: номер у сборки витрины
        // может быть любым, и под правило «больше трёх» попала бы
        // четвёртая базовая сборка - её удалил бы обычный пользователь.
        $stmt = db_prepare($mysql, "SELECT is_base FROM assembly WHERE assembly_id = ?", "i", $assemblyId);
        $stmt->execute();
        $assemblyRow = $stmt->get_result()->fetch_assoc();

        if (!isset($row[0]) && $assemblyRow !== null && (int) $assemblyRow['is_base'] === 0) {
            $stmt = db_prepare($mysql, "DELETE FROM assembly WHERE assembly_id = ?", "i", $assemblyId);
            $stmt->execute();
        }
        header('Location: /profile.php');
        exit();
    }

    // Обработчики удаления пользователя, заказа и комплектующего переехали
    // в админку. Старые формы шлют POST сюда, поэтому редиректим на
    // нужную вкладку вместо 404.
    if (isset($_POST['deleteUser']) && $isAdmin) {
        header('Location: /admin.php?tab=users');
        exit();
    }

    if (isset($_POST['editOrderStatus']) && $isAdmin && isset($_POST['orderId'])) {
        header('Location: /admin.php?tab=orders');
        exit();
    }

    if (isset($_POST['addComponent']) && $isAdmin) {
        header('Location: /admin.php?tab=components');
        exit();
    }
}
?>
<?php
$pageTitle = 'Профиль';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];

// Текст ставится по флагу в адресе: успешный случай заканчивается
// редиректом, и POST там уже недоступен.
$passwordSuccess = isset($_GET['password_changed']) ? 'Пароль успешно изменён' : null;
$passwordError   = $passwordError ?? null;

// Сообщение об ошибке приходит cookie error_access, которую ставят
// validation/auth.php и validation/reg.php.
$errorMessage = trim((string) ($_COOKIE['error_access'] ?? ''));
$errorFrom = ($_COOKIE['error_from'] ?? '') === 'reg' ? 'reg' : 'auth';

// Гасим обе cookie безусловно: error_from живёт 60 секунд, error_access -
// одну. Без общего гашения короткая cookie истёк бы, метка источника
// осталась бы, и следующая ошибка показалась бы не в той форме.
$expire = [
    'expires'  => time() - 3600,
    'path'     => '/profile.php',
    'httponly' => true,
    'samesite' => 'Strict'
];
setcookie('error_access', '', $expire);
setcookie('error_from', '', $expire);

// Гашение до require header.php: setcookie() работает только пока не
// отправлен ни один байт вывода, а header.php печатает разметку.
require __DIR__ . '/partials/header.php';
?>
        <div class="container_profile container_profile--fluid">
            <div class="cont_profile cont_profile--plain">
                <?php // решение по строке из users, а не по флагу сессии.
                      // Флаг живёт дольше пользователя, строка - нет. ?>
                <?php if (!$userProfile): ?>
                    <?php
                    // значения предыдущей попытки (после редиректа $_POST пуст)
                    $oldLogin = $_SESSION['old_login'] ?? '';
                    $oldName = $_SESSION['old_name'] ?? '';
                    $oldSurname = $_SESSION['old_surname'] ?? '';
                    $oldEmail = $_SESSION['old_email'] ?? '';
                    unset(
                        $_SESSION['old_login'],
                        $_SESSION['old_name'],
                        $_SESSION['old_surname'],
                        $_SESSION['old_email']
                    );
                    $showRegForm = ($errorFrom === 'reg' && $errorMessage !== '');
                    ?>
                    <div class="auth-page">
                        <div class="auth-card card">
                        <div id="login_cont"<?= $showRegForm ? ' style="display:none;"' : '' ?>>
                            <h1>Авторизация</h1>
                            <form action="validation/auth.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="auth_login">Логин</label>
                                    <input class="input" id="auth_login" type="text" name="user_login" placeholder="Введите логин" value="<?= escape($oldLogin) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="auth_pass">Пароль</label>
<?php password_field('user_pass', 'auth_pass', 'current-password', 'Введите пароль', null, null, true, 36); ?>
                                </div>
                                <?php // Плашка рисуется только когда есть что показать: условие
                                      // внутри <p> давало пустую красную полосу. ?>
                                <?php if ($errorFrom === 'auth' && $errorMessage !== ''): ?>
                                <div class="alert alert--error"><?= escape($errorMessage) ?></div>
                                <?php endif; ?>
                                <button class="btn btn--primary" type="submit">Войти</button>
                                <div class="auth-switch">
                                    Нет аккаунта?
                                    <button type="button" class="btn btn--ghost btn--sm" data-action="switch" data-a="pass_cont" data-b="login_cont">Зарегистрируйтесь</button>
                                </div>
                            </form>
                        </div>
                        <div id="pass_cont"<?= $showRegForm ? '' : ' style="display:none;"' ?>>
                            <h1>Регистрация</h1>
                            <form action="validation/reg.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <div class="form-group">
                                    <label class="form-label" for="reg_name">Имя</label>
                                    <input class="input" id="reg_name" type="text" name="user_name" placeholder="Введите имя"
                                           minlength="3" maxlength="20" required autocomplete="given-name"
                                           value="<?= escape($oldName) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_surname">Фамилия</label>
                                    <input class="input" id="reg_surname" type="text" name="user_surname" placeholder="Необязательно"
                                           maxlength="30" autocomplete="family-name"
                                           value="<?= escape($oldSurname) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_login">Логин</label>
                                    <input class="input" id="reg_login" type="text" name="user_login" placeholder="Введите логин"
                                           minlength="3" maxlength="25" required autocomplete="username"
                                           value="<?= escape($oldLogin) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_email">Email</label>
                                    <input class="input" id="reg_email" type="email" name="user_email" placeholder="Для уведомлений о заказе"
                                           maxlength="100" required autocomplete="email"
                                           value="<?= escape($oldEmail) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="reg_pass">Пароль</label>
<?php // Сервер проверяет ту же границу, что и minlength/maxlength. ?>
<?php password_field('user_pass', 'reg_pass', 'new-password', 'Минимум 8 символов', 8, 20, true, 36); ?>
                                </div>
                                <?php if ($errorFrom === 'reg' && $errorMessage !== ''): ?>
                                <div class="alert alert--error"><?= escape($errorMessage) ?></div>
                                <?php endif; ?>
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
                    <?php // единый сайдбар (тот же partial, что и в admin.php) ?>
                    <?php $activeTab = 'profile'; ?>
                    <?php
                    // Значение из GET не идёт в разметку как есть - id секции берётся
                    // из массива по белому списку.
                    $sectionMap = [
                        'info'     => 'card-info',
                        'orders'   => 'card-builds',
                        'fav'      => 'card-fav',
                        'security' => 'card-security',
                    ];
                    $sectionKey = (string) ($_GET['section'] ?? 'info');
                    $activeSection = $sectionMap[$sectionKey] ?? 'card-info';

                    // Какие секции реально есть на странице: заказы только обычному
                    // пользователю, а карточка безопасности - обоим.
                    //
                    // Сверка с GET идёт после заполнения списка: иначе isset()
                    // смотрел бы на ещё не заданную переменную и
                    // $activeSection откатывался бы в card-info всегда.
                    $sectionExists = [
                        'card-info' => true,
                        'card-security' => true,
                        // Избранное у обеих ролей: админ сохраняет сборки
                        // кнопкой «Сохранить», и без этой карточки удалять
                        // их ему нечем.
                        'card-fav' => true,
                    ];
                    if (!$isAdmin) {
                        $sectionExists['card-builds'] = true;
                    }
                    // Показывать секцию, которой на странице нет, нельзя -
                    // страница осталась бы пустой.
                    if (!isset($sectionExists[$activeSection])) {
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
// Спрайт иконок: один символ на страницу вместо копии <svg> в каждой кнопке.
$profileInitial = mb_strtoupper(mb_substr((string) ($userProfile['user_name'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8');
$profileFullName = trim(($userProfile['user_name'] ?? '') . ' ' . ($userProfile['user_surname'] ?? ''));
?>
                                <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
                                    <symbol id="icon-edit" viewBox="0 0 512 512">
                                        <rect x="150" y="96" width="216" height="88" rx="12" fill="none" stroke="currentColor" stroke-width="32" transform="rotate(-45 258 140)"></rect>
                                    <!-- галочка и крестик для inline-edit,
                                         viewBox 24 как у Feather-иконок -->
                                    <symbol id="icon-check" viewBox="0 0 24 24">
                                        <polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
                                    </symbol>
                                    <symbol id="icon-close" viewBox="0 0 24 24">
                                        <line x1="18" y1="6" x2="6" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
                                        <line x1="6" y1="6" x2="18" y2="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line>
                                    </symbol>
                                </svg>

                                <!-- шапка профиля вместо голого h2 -->
                                <div class="profile-header">
                                    <div class="profile-header-avatar"><?= escape($profileInitial) ?></div>
                                    <div class="profile-header-info">
                                        <h2><?= escape($profileFullName !== '' ? $profileFullName : (string) ($userProfile['user_login'] ?? '')) ?></h2>
                                        <div class="profile-header-login">@<?= escape((string) ($userProfile['user_login'] ?? '')) ?></div>
                                        <!-- бейдж роли и дата регистрации
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
// Тот же приём, что в админке: код ошибки в GET, текст из карты.
// Так сообщение видит и залогиненный пользователь, а не только форма входа.
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
                                <!-- поля в две колонки, каждое своей плиткой -->
                                <div class="profile-fields">
                                <div class="profile-field" data-field="name">
                                    <div class="profile-field-label">Имя</div>
                                    <div class="profile-field-value"><?= htmlspecialchars($userProfile['user_name'] ?? '') ?></div>
                                    <form class="profile-field-edit" method="post" action="">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input class="input" type="text" name="user_name" placeholder="Имя" value="<?= escape($userProfile['user_name'] ?? '') ?>">
                                        <!-- Текстовая кнопка сжимала инпут до 78px, иконка - нет. -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeName" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
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
                                        <!-- Текстовая кнопка сжимала инпут до 78px, иконка - нет. -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeSurname" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
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
                                        <!-- Текстовая кнопка сжимала инпут до 78px, иконка - нет. -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeEmail" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                <div class="profile-field" data-field="address">
<?php
                                // Новые поля - единственный источник правды: fallback на legacy
                                // user_address воскресил бы очищенный
                                // пользователем адрес из старой строки.
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
                                        <!-- Текстовая кнопка сжимала инпут до 78px, иконка - нет. -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeAddress" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                        </div>
                                    </form>
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
                                        <!-- Текстовая кнопка сжимала инпут до 78px, иконка - нет. -->
                                        <button type="submit" class="btn-icon btn-icon--success" name="changeNumber" title="Сохранить" aria-label="Сохранить">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-check"></use></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon--muted" data-action="cancel-edit" title="Отмена" aria-label="Отмена">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                    <button type="button" class="btn-icon" data-action="edit" title="Изменить" aria-label="Изменить">
                                                        <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false"><use href="#icon-edit"></use></svg>
                                                    </button>
                                </div>
                                </div>
                            </section>

                            <!--
                                карточка безопасности. Секция своя,
                                а не блок внутри «Личной информации»: смена
                                пароля не про анкету, и смешивать их в одной
                                карточке значило бы держать поля пароля на
                                одном экране с кнопкой редактирования имени.

                                Видна и админу тоже - пароль у администратора
                                такой же пользовательский.
                            -->
                            <section class="card" id="card-security" data-section<?= $sectionStyle('card-security') ?>>
                                <h2 class="page-title">Безопасность</h2>

                                <?php // Плашки рисуются только когда есть что показать:
                                      // пустой alert--error висел бы на каждом заходе. ?>
                                <?php if (!empty($passwordError)): ?>
                                    <div class="alert alert--error"><?= escape($passwordError) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($passwordSuccess)): ?>
                                    <div class="alert alert--success"><?= escape($passwordSuccess) ?></div>
                                <?php endif; ?>

                                <form method="post" class="password-form">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

                                    <div class="form-group">
                                        <label class="form-label" for="currentPassword">Текущий пароль</label>
<?php password_field('current_password', 'currentPassword', 'current-password', '', null, null, true, 40); ?>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label" for="newPassword">Новый пароль</label>
<?php password_field('new_password', 'newPassword', 'new-password', '', 8, 20, true, 44); ?>
                                            <p class="form-hint">От 8 до 20 символов</p>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="newPasswordConfirm">Повторите новый пароль</label>
<?php password_field('new_password_confirm', 'newPasswordConfirm', 'new-password', '', 8, 20, true, 44); ?>
                                        </div>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" name="changePassword" class="btn btn--primary">
                                            Изменить пароль
                                        </button>
                                    </div>
                                </form>
<?php
// Верификация контактов - часть безопасности аккаунта, поэтому блок
// живёт в card-security, а не в отдельной карточке.
//
// Три состояния на контакт: подтверждён, ждёт подтверждения, не
// подтверждён. Четвёртое - «Не указан» - это не состояние верификации,
// а отсутствие самого контакта, поэтому оно проверяется первым:
// иначе предлагалось бы подтвердить то, чего нет.
//
// Формы заявки без action намеренно: браузер отправит POST на текущий
// адрес, то есть на /profile.php, где эти обработчики и живут.
$hasEmail = !empty($userProfile['user_email']);
$hasPhone = !empty($userProfile['user_number']);
?>
                                    <hr class="card-divider">

                                    <h3 class="card-subtitle">Верификация контактов</h3>
                                    <p class="form-hint">Подтвердите email и телефон — администратор верифицирует аккаунт после вашей заявки</p>

                                    <div class="verify-row">
                                        <div class="verify-row__info">
                                            <div class="verify-row__label">Email</div>
                                            <div class="verify-row__value"><?= $hasEmail ? escape($userProfile['user_email']) : 'Не указан' ?></div>
                                        </div>
                                        <div class="verify-row__status">
<?php if (!$hasEmail): ?>
                                            <span class="badge">Не указан</span>
<?php elseif (!empty($userProfile['email_verified'])): ?>
                                            <span class="badge badge--success">Подтверждён</span>
<?php elseif (!empty($userProfile['email_verification_requested'])): ?>
                                            <span class="badge badge--warning">Ожидает подтверждения</span>
<?php else: ?>
                                            <span class="badge">Не подтверждён</span>
                                            <form method="post" class="verify-form">
                                                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                                <button type="submit" name="requestEmailVerification" class="btn btn--primary btn--sm">
                                                    Подтвердить email
                                                </button>
                                            </form>
<?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="verify-row">
                                        <div class="verify-row__info">
                                            <div class="verify-row__label">Телефон</div>
                                            <div class="verify-row__value"><?= $hasPhone ? escape($userProfile['user_number']) : 'Не указан' ?></div>
                                        </div>
                                        <div class="verify-row__status">
<?php if (!$hasPhone): ?>
                                            <span class="badge">Не указан</span>
<?php elseif (!empty($userProfile['phone_verified'])): ?>
                                            <span class="badge badge--success">Подтверждён</span>
<?php elseif (!empty($userProfile['phone_verification_requested'])): ?>
                                            <span class="badge badge--warning">Ожидает подтверждения</span>
<?php else: ?>
                                            <span class="badge">Не подтверждён</span>
                                            <form method="post" class="verify-form">
                                                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                                <button type="submit" name="requestPhoneVerification" class="btn btn--primary btn--sm">
                                                    Подтвердить телефон
                                                </button>
                                            </form>
<?php endif; ?>
                                        </div>
                                    </div>
                            </section>
                            <!-- Карточка рисуется и админу: он сохраняет сборки кнопкой
                                 «Сохранить» на странице сборки, и без неё удалить их ему нечем. -->
                            <section class="card" id="card-fav" data-section<?= $sectionStyle('card-fav') ?>>
                                <h2 class="page-title">Избранное</h2>
                                            <!-- обёртка contTable заменена на .table-wrap -->
                                            <div class="table-wrap">
                                                <?php
                                                // Фильтр идёт по favorites.user_id, лишний FROM users не нужен.
                                                $sql = "SELECT a.assembly_name, a.assembly_price, a.assembly_id, a.is_base, f.favorit_id
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
                                                    $favName = $row['assembly_name'] ?? '';
                                                    // Префикс «Сборка » - только для результатов
                                                    // конфигуратора: у них имя вроде «#42»,
                                                    // и без префикса оно ни о чём не говорит.
                                                    if ((int) ($row['is_base'] ?? 0) === 0) {
                                                        $favName = "Сборка " . $favName;
                                                    }
                                                    // вся строка - ссылка на просмотр сборки
                                                    echo "<tr class=\"row-link\" data-href=\"/assembly.php?check-saved={$row['assembly_id']}\">"
                                                        . "<td>" . htmlspecialchars($favName) . "</td>"
                                                        . "<td>" . htmlspecialchars($row['assembly_price'] ?? '') . "</td>"
                                                        . "<td><form method=\"POST\">"
                                                        . "<input type=\"hidden\" name=\"csrf_token\" value=\"" . escape(csrf_token()) . "\">"
                                                        . "<input name=\"favoritId\" type=\"hidden\" value=\"{$row['favorit_id']}\">"
                                                        // подтверждение перед удалением.
                                                        // deleteAssembly дублируется скрытым input:
                                                        // форму отправляет form.submit(), а он не
                                                        // включает имя нажатой submit-кнопки.
                                                        . "<input type=\"hidden\" name=\"deleteAssembly\" value=\"{$row['assembly_id']}\">"
                                                        . "<button class=\"btn btn--ghost btn--sm row-btn-danger\" type=\"submit\" data-action=\"delete-favorite\" data-name=\"" . htmlspecialchars($favName, ENT_QUOTES) . "\" title=\"Убрать из избранного\">"
                                                        // Инлайн-SVG, а не <img>: currentColor внутри отдельного
                                                        // документа не наследуется от страницы и резолвится в
                                                        // чёрный - на тёмной теме иконка пропадала.
                                                        . "<svg width=\"18\" height=\"18\" viewBox=\"0 0 512 512\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"32\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\">"
                                                        . "<path d=\"M112 112h288\"></path>"
                                                        . "<path d=\"M200 112v-16a16 16 0 0 1 16-16h80a16 16 0 0 1 16 16v16\"></path>"
                                                        . "<path d=\"M400 112l-24 288a32 32 0 0 1-32 29H168a32 32 0 0 1-32-29L112 112\"></path>"
                                                        . "<path d=\"M216 208v144M296 208v144\"></path>"
                                                        . "</svg>"
                                                        . "</button>"
                                                        . "</form></td>"
                                                        . "</tr>";
                                                }
                                                echo '</tbody></table>';
                                                }
                                                ?>
                                            </div>
                            </section>
                            <?php if (!$isAdmin): ?>
                            <section class="card" id="card-builds" data-section<?= $sectionStyle('card-builds') ?>>
                                            <h2 class="page-title">Мои заказы</h2>
                                            <!-- обёртка contTable заменена на .table-wrap -->
                                            <div class="table-wrap">
                                                <?php
                                                // Таблица заказов с номером и датой; детали открываются
                                                // read-only модалкой. Проверять наличие orders.status
                                                // через SHOW COLUMNS незачем: admin.php и так требует
                                                // orders.created_at, такая «защита» лишь прятала бы
                                                // ошибку.
                                                $sql = "SELECT o.order_id, o.status, o.created_at,
                                                               a.assembly_name, a.assembly_price, a.is_base, o.assembly_id
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
                                                // Пользователю номер заказа не нужен; в модалке он
// // показывается - там помогает сослаться на заказ при поддержке.
                                                echo '<table class="table"><thead><tr><th>Сборка</th><th>Стоимость</th><th>Статус</th><th>Дата</th></tr></thead><tbody>';

                                                foreach ($ordRows as $row) {
                                                    $ordAsmName = $row['assembly_name'] ?? '';
                                                    // Тот же признак, что и в избранном - флаг is_base
                                                    if ((int) ($row['is_base'] ?? 0) === 0) {
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
                                                        // колонки с номером заказа больше нет
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
                                                Модалка только для чтения: ни формы, ни кнопок
                                                сохранения. Поля заполняет JS из data-row строки.
                                            -->
                                            <dialog id="userOrderModal" class="modal">
                                                <div class="modal-form">
                                                    <h2>Заказ №<span id="userOrderNumber"></span></h2>

                                                    <div class="modal-section">
                                                        <h3>Сборка</h3>
                                                        <div class="form-group">
                                                            <label class="form-label">Название</label>
                                                            <!-- название открывает сборку в
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
