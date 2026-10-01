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

        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['editOrderStatus']) && $isAdmin && isset($_POST['orderId'])) {
        csrf_verify();
        $status = $_POST['status'] ?? '';
        $orderId = $_POST['editOrderStatus'];

        $stmt = $mysql->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt->bind_param("si", $status, $orderId);
        $stmt->execute();

        header('Location: /profile.php');
        exit();
    }

    if (isset($_POST['addComponent']) && $isAdmin) {
        csrf_verify();
        $name = $_POST['nm'] ?? '';
        $price = $_POST['pr'] ?? 0;
        $amount = $_POST['col'] ?? 0;
        $categoryId = $_POST['cat'] ?? 0;
        $tdp = $_POST['tdp'] ?? null;
        $videoCore = $_POST['vc'] ?? null;
        $socketId = $_POST['sock'] ?? null;

        $stmt = $mysql->prepare("SELECT MAX(component_id) FROM components");
        $stmt->execute();
        $result = $stmt->get_result();
        $checklast = $result->fetch_array();
        $maxID = ($checklast[0] ?? 0) + 1;

        if (!empty($name) && !empty($price) && !empty($amount) && !empty($categoryId)) {
            $stmt = $mysql->prepare("INSERT INTO `components` (`component_id`,`component_name`, `component_price`, `amount`, `category_id`) VALUES(?,?,?,?,?)");
            $stmt->bind_param("isiii", $maxID, $name, $price, $amount, $categoryId);
            $stmt->execute();

            if ($tdp !== null) {
                $stmt = $mysql->prepare("UPDATE `components` SET `tdp` = ? WHERE `component_id` = ?");
                $stmt->bind_param("ii", $tdp, $maxID);
                $stmt->execute();
            }

            if ($videoCore !== null) {
                $stmt = $mysql->prepare("UPDATE `components` SET `video_core` = ? WHERE `component_id` = ?");
                $stmt->bind_param("si", $videoCore, $maxID);
                $stmt->execute();
            }

            if ($socketId !== null) {
                $stmt = $mysql->prepare("UPDATE `components` SET `socket_id` = ? WHERE `component_id` = ?");
                $stmt->bind_param("ii", $socketId, $maxID);
                $stmt->execute();
            }
        }
        header('Location: /profile.php');
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
            <div class="cont_profile">
                <?php if (empty($_SESSION['user_id'])): ?>
                    <div class="authCont">
                        <div id="login_cont">
                            <form action="validation/auth.php" method="post" style="width: 100%; height: 100%;">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <h1>Авторизация</h1>
                                <input class="entry_field" type="text" name="user_login" placeholder="Введите логин"
                                    required><br>
                                <input class="entry_field" type="password" name="user_pass" placeholder="Введите пароль"
                                    required><br>
                                <p class="wrong_access"><?php if (isset($_SESSION['error_access'])): ?>
                                        <?= escape($_SESSION['error_access'] ?? '') ?>
                                    <?php endif; ?>
                                </p>
                                <button class="btn_valid" type="submit">Войти</button>
                                <p>Нет аккаунта? <input class="toggle_btn" value="Зарегистрируйтесь" type="button"
                                        data-action="switch" data-a="pass_cont" data-b="login_cont"></p>
                            </form>
                        </div>
                        <div id="pass_cont">
                            <form action="validation/reg.php" method="post" style="width: 100%; height: 100%;">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <h1>Регистрация</h1>
                                <input class="entry_field" type="text" name="user_name" placeholder="Введите имя"
                                    required><br>
                                <input class="entry_field" type="text" name="user_login" placeholder="Введите логин"
                                    required><br>
                                <input class="entry_field" type="password" name="user_pass" placeholder="Введите пароль"
                                    required><br>
                                <p class="wrong_access"><?php if (isset($_SESSION['error_access'])): ?>
                                        <?= escape($_SESSION['error_access'] ?? '') ?>
                                    <?php endif; ?>
                                </p>
                                <button class="btn_valid" type="submit">Регистрация</button>
                                <p>Уже зарегистрированы? <input class="toggle_btn" value="Войдите в аккаунт" type="button"
                                        data-action="switch" data-a="pass_cont" data-b="login_cont"></p>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="userProfile" id="userProfile">
                        <h1>Профиль</h1>
                        <div class="profileContainer">
                            <div class="userData">
                                <h2>Личная информация</h2>
                                <p>Имя: <?= htmlspecialchars($userProfile['user_name'] ?? '') ?></p>
                                <p>Фамилия: <?= htmlspecialchars($userProfile['user_surname'] ?? '') ?>
                                    <input class="toggle_btn" value="Изменить" type="button"
                                        data-action="show" data-id="surnameEdit">
                                <div id="surnameEdit" style="display:none;">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="text" name="user_surname" placeholder="Фамилия"><br>
                                        <button class="" name="changeSurname" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button"
                                            data-action="hide" data-id="surnameEdit">
                                    </form>
                                </div>
                                </p>
                                <p>Почта: <?= htmlspecialchars($userProfile['user_email'] ?? '') ?>
                                    <input class="toggle_btn" value="Изменить" type="button"
                                        data-action="show" data-id="emailEdit">
                                <div id="emailEdit" style="display:none;">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="text" name="user_email" placeholder="Электронная почта"><br>
                                        <button class="" name="changeEmail" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button"
                                            data-action="hide" data-id="emailEdit">
                                    </form>
                                </div>
                                </p>
                                <p>Адрес: <?= htmlspecialchars($userProfile['user_address'] ?? '') ?>
                                    <input class="toggle_btn" value="Изменить" type="button"
                                        data-action="show" data-id="addressEdit">
                                <div id="addressEdit" style="display:none;">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="text" name="user_city" placeholder="Город"><br>
                                        <input type="text" name="user_street" placeholder="Улица"><br>
                                        <input type="text" name="user_home" placeholder="Дом"><br>
                                        <button class="" name="changeAddress" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button"
                                            data-action="hide" data-id="addressEdit">
                                    </form>
                                </div>
                                </p>
                                <p>Номер телефона: <?= htmlspecialchars($userProfile['user_number'] ?? '') ?>
                                    <input class="toggle_btn" value="Изменить" type="button"
                                        data-action="show" data-id="numberEdit">
                                <div id="numberEdit" style="display:none;">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="tel" name="user_number" placeholder="+7(XXX)XXX-XX-XX" required
                                            pattern="\+7\s?[\(]{0,1}[0-9][0-9]{2}[\)]{0,1}\s?\d{3}[-]{0,1}\d{2}[-]{0,1}\d{2}"><br>
                                        <button class="" name="changeNumber" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button"
                                            data-action="hide" data-id="numberEdit">
                                    </form>
                                </div>
                                </p>
                                <p><a href="validation/exit.php" class="toggle_btn" style="color:blue;">Выйти</a></p>
                            </div>

                            <div class="userOrder">
                                <?php if ($isAdmin): ?>
                                    <h2>Панель управления</h2>
                                    <div class="MonitorBtn">
                                        <input class="toggle_btn_monitor" value="Управление пользователями" type="button"
                                            data-action="switch" data-a="userMonitor" data-b="userProfile">
                                    </div>
                                    <div class="MonitorBtn">
                                        <input class="toggle_btn_monitor" value="Управление заказами" type="button"
                                            data-action="switch" data-a="orderMonitor" data-b="userProfile">
                                    </div>
                                    <div class="MonitorBtn">
                                        <input class="toggle_btn_monitor" value="Управление комплектующими" type="button"
                                            data-action="switch" data-a="componentMonitor" data-b="userProfile">
                                    </div>
                                <?php else: ?>
                                    <div class="userAssembly">
                                        <h2>Ваши конфигурации</h2>
                                        <div class="favoriteTable">
                                            <h4>Сохранённые сборки</h4>
                                            <div class="contTable">
                                                <?php
                                                $sql = "SELECT assembly_name,assembly_price,assembly.assembly_id,favorites.favorit_id FROM users,assembly,favorites
                                                        WHERE assembly.assembly_id = favorites.assembly_id AND users.user_id = ? AND favorites.user_id = ?
                                                        ORDER BY `favorites`.`favorit_id` ASC";
                                                $stmt = $mysql->prepare($sql);
                                                $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                echo "<span class=\"assemblyTable\"><span>Название сборки</span><span>Стоимость</span><span></span></span><br><div class=\"lineSpan\"></div>";

                                                while ($row = $result->fetch_array()) {
                                                    if ($row['assembly_id'] > 3) {
                                                        $row['assembly_name'] = "Сборка " . ($row[0] ?? '');
                                                    }
                                                    echo "<form method=\"POST\">
                                                            <input type=\"hidden\" name=\"csrf_token\" value=\"" . escape(csrf_token()) . "\">
                                                            <span class=\"assemblyTable\">
                                                                <span><a href=\"assembly.php?check-saved={$row['assembly_id']}\">" . htmlspecialchars($row['assembly_name'] ?? '') . "</a></span>
                                                                <span>" . htmlspecialchars($row['assembly_price'] ?? '') . "</span>
                                                                <span>
                                                                    <input style=\"display:none\" name=\"favoritId\" type=\"hidden\" value=\"{$row['favorit_id']}\">
                                                                    <button class=\"delBtn\" name=\"deleteAssembly\" type=\"submit\" value=\"{$row['assembly_id']}\">Удалить</button>
                                                                </span>
                                                            </span>
                                                            <br>
                                                          </form>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="orderTable">
                                            <h4>Заказы</h4>
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

                                                    echo "<span class=\"assemblyTable\"><span>Название сборки</span><span>Стоимость</span><span>Статус</span></span><br><div class=\"lineSpan\"></div>";

                                                    while ($row = $result->fetch_array()) {
                                                        if ($row['assembly_id'] > 3) {
                                                            $row['assembly_name'] = "Сборка " . ($row[0] ?? '');
                                                        }
                                                        echo "<span class=\"assemblyTable\">
                                                                <span><a href=\"assembly.php?check-purchased={$row['assembly_id']}\">" . htmlspecialchars($row['assembly_name'] ?? '') . "</a></span>
                                                                <span>" . htmlspecialchars($row['assembly_price'] ?? '') . "</span>
                                                                <span>" . htmlspecialchars($row['status'] ?? '') . "</span>
                                                              </span><br>";
                                                    }
                                                } else {
                                                    echo "<p>Статус заказов временно недоступен</p>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                        <div class="userProfile" id="userMonitor" style="display:none;">
                            <h1>Управление пользователями</h1>
                            <input class="toggle_btn_profile" value="Вернуться в профиль" type="button"
                                data-action="switch" data-a="userProfile" data-b="userMonitor">
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

                        <div class="userProfile" id="orderMonitor" style="display:none;">
                            <h1>Управление заказами</h1>
                            <input class="toggle_btn_profile" value="Вернуться в профиль" type="button"
                                data-action="switch" data-a="userProfile" data-b="orderMonitor">
                            <div class="containerMonitor">
                                <?php
                                echo "<span class=\"assemblyTable\">
                                        <span>Покупатель</span>
                                        <span style=\"width:60%\">Адрес</span>
                                        <span>Сборка</span>
                                        <span>Стоимость</span>
                                        <span>Статус</span>
                                        <span></span>
                                      </span><br><div class=\"lineSpan\"></div>";

                                $checkSql = "SHOW COLUMNS FROM orders LIKE 'status'";
                                $checkStmt = $mysql->prepare($checkSql);
                                $checkStmt->execute();
                                $checkResult = $checkStmt->get_result();

                                if ($checkResult && $checkResult->num_rows > 0) {
                                    $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,assembly.assembly_id FROM users,assembly,orders
                                            WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id
                                            ORDER BY `orders`.`order_id` ASC";
                                    $stmt = $mysql->prepare($sql);
                                    $stmt->execute();
                                    $result = $stmt->get_result();

                                    if ($result) {
                                        while ($row = $result->fetch_array()) {
                                            if ($row['assembly_id'] > 3) {
                                                $row['assembly_name'] = "Сборка " . ($row['assembly_name'] ?? '');
                                            }
                                            echo "<form method=\"POST\">
                                                    <input type=\"hidden\" name=\"csrf_token\" value=\"" . escape($_SESSION['csrf_token']) . "\">
                                                    <span class=\"assemblyTable\">
                                                        <span>" . htmlspecialchars(($row['user_name'] ?? '') . " " . ($row['user_surname'] ?? '')) . "</span>
                                                        <span style=\"width:60%\">" . htmlspecialchars($row['user_address'] ?? '') . "</span>
                                                        <span>" . htmlspecialchars($row['assembly_name'] ?? '') . "</span>
                                                        <span>" . htmlspecialchars($row['assembly_price'] ?? '') . "</span>
                                                        <span>
                                                            <select size=\"1\" name=\"status\">
                                                                <option " . ((($row['status'] ?? '') == 'Обрабатывается') ? 'selected' : '') . " value=\"Обрабатывается\">Обрабатывается</option>
                                                                <option " . ((($row['status'] ?? '') == 'Собирается') ? 'selected' : '') . " value=\"Собирается\">Собирается</option>
                                                                <option " . ((($row['status'] ?? '') == 'Доставляется') ? 'selected' : '') . " value=\"Доставляется\">Доставляется</option>
                                                                <option " . ((($row['status'] ?? '') == 'Выполнен') ? 'selected' : '') . " value=\"Выполнен\">Выполнен</option>
                                                            </select>
                                                        </span>
                                                        <span>
                                                            <input type=\"hidden\" name=\"orderId\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">
                                                            <button class=\"delBtn\" style=\"color:blue;\" name=\"editOrderStatus\" type=\"submit\" value=\"" . htmlspecialchars($row['order_id'] ?? '') . "\">Сохранить</button>
                                                        </span>
                                                    </span>
                                                    <br>
                                                  </form>";
                                        }
                                    }
                                } else {
                                    echo "<p>Столбец status отсутствует в таблице orders</p>";
                                }
                                ?>
                            </div>
                        </div>

                        <div class="userProfile" id="componentMonitor" style="display:none;">
                            <h1>Управление комплектующими</h1>
                            <br>
                            <input class="toggle_btn_profile" value="Вернуться в профиль" type="button"
                                data-action="switch" data-a="userProfile" data-b="componentMonitor">
                            <br>
                            <div class="containerMonitor">
                                <div class="cmpForm">
                                    <h3>Добавить комплектующие</h3><br>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <span class="assemblyTable">
                                            <span><input type="text" placeholder="Название" name="nm"
                                                    style="width:200px;"></span>
                                            <span><input type="number" placeholder="Стоимость" name="pr"></span>
                                            <span><input type="number" placeholder="Количество" name="col"></span>
                                            <span><input id="tdpInp" type="checkbox" value="1" name="vc"><label
                                                    for="tdpInp">Графическое ядро</label></span>
                                            <?php
                                            $sql = "SELECT * FROM categories ORDER BY `categories`.`category_id` ASC";
                                            echo "<span><select size=\"1\" name=\"cat\"><option selected hidden disabled>Категория</option>";
                                            $stmt = $mysql->prepare($sql);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result) {
                                                while ($row = $result->fetch_array()) {
                                                    echo "<option value=\"" . htmlspecialchars($row['category_id'] ?? '') . "\">" . htmlspecialchars($row['category_name'] ?? '') . "</option>";
                                                }
                                            }
                                            echo "</select></span>";

                                            $sql = "SELECT * FROM sockets ORDER BY `sockets`.`socket_id` ASC";
                                            echo "<span><select size=\"1\" name=\"sock\"><option selected hidden disabled>Сокет</option>";
                                            $stmt = $mysql->prepare($sql);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result) {
                                                while ($row = $result->fetch_array()) {
                                                    echo "<option value=\"" . htmlspecialchars($row['socket_id'] ?? '') . "\">" . htmlspecialchars($row['socket_type'] ?? '') . "</option>";
                                                }
                                            }
                                            echo "</select></span>";
                                            ?>
                                            <span><input type="number" placeholder="TDP" name="tdp"></span>
                                            <span><button class="delBtn" style="color:blue;" name="addComponent"
                                                    type="submit">Добавить</button></span>
                                        </span>
                                        <br>
                                    </form>
                                    <br>
                                    <h3>Список комплектующих</h3>
                                </div>
                                <?php
                                echo "<br><br><div class=\"lineSpan\"></div>
                                      <span class=\"assemblyTable\">
                                        <span style=\"width:5%\">ID</span>
                                        <span>Категория</span>
                                        <span>Название</span>
                                        <span style=\"width:5%\">Количество</span>
                                        <span style=\"width:10%\">Стоимость</span>
                                      </span><br><div class=\"lineSpan\"></div>";

                                $sql = "SELECT * FROM components,categories WHERE components.category_id = categories.category_id ORDER BY `components`.`component_id` ASC";
                                $stmt = $mysql->prepare($sql);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result) {
                                    while ($row = $result->fetch_array()) {
                                        echo "<span class=\"assemblyTable\">
                                                <span style=\"width:5%\">" . htmlspecialchars($row['component_id'] ?? '') . "</span>
                                                <span>" . htmlspecialchars($row['category_name'] ?? '') . "</span>
                                                <span>" . htmlspecialchars($row['component_name'] ?? '') . "</span>
                                                <span style=\"width:5%\">" . htmlspecialchars($row['amount'] ?? '') . "</span>
                                                <span style=\"width:10%\">" . htmlspecialchars($row['component_price'] ?? '') . "</span>
                                              </span><br>";
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