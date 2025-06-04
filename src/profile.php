<?php
                            require 'modules/connect.php';
                            $mysql = connect();
                            mysqli_set_charset($mysql, 'utf8');
                            if(isset($_COOKIE['login'])){
                                $userLogin = $_COOKIE['login'];
                                $validResult = $mysql -> query("SELECT * FROM `users` WHERE `user_login` = '$userLogin'");
                                $userProfile = $validResult->fetch_assoc();
                            }
                            if(isset($_POST['changeAddress']))
                            {
                                $city = $_POST['user_city'];
                                $street = $_POST['user_street'];
                                $home = $_POST['user_home'];
                                $address = "г.$city, ул.$street, д.$home";
                                $mysql -> query("UPDATE `users` SET `user_address` = '$address' WHERE `user_login` = '$userLogin'");
                                header('Location: /profile.php');
                            }
                            if(isset($_POST['changeNumber']))
                            {
                                $number = $_POST['user_number'];
                                $mysql -> query("UPDATE `users` SET `user_number` = '$number' WHERE `user_login` = '$userLogin'");
                                header('Location: /profile.php');
                            }
                            if(isset($_POST['changeSurname']))
                            {
                                $surname = $_POST['user_surname'];
                                $mysql -> query("UPDATE `users` SET `user_surname` = '$surname' WHERE `user_login` = '$userLogin'");
                                header('Location: /profile.php');
                            }
                            if(isset($_POST['changeEmail']))
                            {
                                $email = $_POST['user_email'];
                                $mysql -> query("UPDATE `users` SET `user_email` = '$email' WHERE `user_login` = '$userLogin'");
                                header('Location: /profile.php');
                            }
                            if(isset($_POST['deleteAssembly']))
                            {
                                $sql = "SELECT orders.assembly_id FROM users,assembly,orders WHERE {$_POST['deleteAssembly']} = orders.assembly_id";
                                $row = mysqli_fetch_array($mysql->query($sql));
                                $numI = 0;
                                $favoritId = "i$numI";
                                while(!isset($_POST[$favoritId]))
                                {
                                    $numI++;
                                    $favoritId = "i$numI";
                                }
                                $mysql -> query("DELETE FROM favorites WHERE favorit_id = {$_POST[$favoritId]}");
                                if(($_POST['deleteAssembly']>3) && (!isset($row[0])))
                                {
                                    $mysql -> query("DELETE FROM assembly WHERE assembly_id = {$_POST['deleteAssembly']}");
                                }
                            }
                            if(isset($_POST['deleteUser']))
                            {
                                $numI = 0;
                                $userId = "i$numI";
                                while(!isset($_POST[$userId]))
                                {
                                    $numI++;
                                    $userId = "i$numI";
                                }
                                $mysql -> query("DELETE FROM orders WHERE user_id = {$_POST[$userId]}");
                                $mysql -> query("DELETE FROM favorites WHERE user_id = {$_POST[$userId]}");
                                $mysql -> query("DELETE FROM users WHERE user_id = {$_POST[$userId]}");
                            }
                            if(isset($_POST['editOrderStatus']))
                            {
                                $numI = 0;
                                $orderId = "i$numI";
                                while(!isset($_POST[$orderId]))
                                {
                                    $numI++;
                                    $orderId = "i$numI";
                                }
                                $mysql -> query("UPDATE orders SET status = '{$_POST[$orderId]}' WHERE order_id = {$_POST['editOrderStatus']}");
                            }
                            if(isset($_POST['addComponent']))
                            {
                                $checklast = mysqli_fetch_array($mysql->query("SELECT MAX(component_id) FROM components"));
                                $maxID = $checklast[0]+1;
                                if((isset($_POST['nm']))&&(isset($_POST['pr']))&&(isset($_POST['col']))&&(isset($_POST['cat'])))
                                {
                                    $mysql ->query("INSERT INTO `components` (`component_id`,`component_name`, `component_price`, `amount`, `category_id`) VALUE('$maxID','{$_POST['nm']}','{$_POST['pr']}','{$_POST['col']}','{$_POST['cat']}')");
                                    if(isset($_POST['tdp'])){
                                        $mysql -> query("UPDATE `components` SET `tdp` = '{$_POST['tdp']}' WHERE `component_id` = '$maxID'");
                                    }
                                    if(isset($_POST['vc'])){
                                        $mysql -> query("UPDATE `components` SET `video_core` = '{$_POST['vc']}' WHERE `component_id` = '$maxID'");
                                    }
                                    if(isset($_POST['sock'])){
                                        $mysql -> query("UPDATE `components` SET `socket_id` = '{$_POST['sock']}' WHERE `component_id` = '$maxID'");
                                    }
                                }
                                }
?>
<html>
<head>
        <meta charset="utf-8">
        <title>Интернет-магазин персональных компьютеров индивидуальной комплектации AION CORP.</title>
        <link rel="stylesheet" href="assets/css/style.css">
        <link rel="stylesheet" href="assets/css/profile.css">
        <link rel="shortcut icon" href="assets/images/favicon.png" type="image/png">
        <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;700&display=swap" rel="stylesheet">
    </head>
    <body>
    <header class="header">
            <div class="header__inner">
                <a href="/"><div><img class=logo src="assets/images/logo.png" alt="logo"></div></a>
                <div class="nav">
                    <ul>
                        <li class="list">
                            <a href="/#assembly">
                                <span class="text">Сборки ПК</span>
                                <span class="icon"><img src="assets/images/cog-outline.svg"></span>
                            </a>
                           
                        </li>
                        <li class="list">
                            <a href="/#configurator">
                                <span class="text">Конфигуратор</span>
                                <span class="icon"><img src="assets/images/hammer-outline.svg"></span>
                            </a>
                        </li>
                        <li class="list">
                            <a href="/#information">
                                <span class="text">О нас</span>
                                <span class="icon"><img src="assets/images/link-outline.svg"></span>
                            </a>
                        </li>
                        <li class="list">
                            <a href="profile.php">
                                <span class="text"><?php if(isset($_COOKIE['user']) == false):?>
                                                    Войти<?php else:?><?=$_COOKIE['user']?><?php endif;?></span>
                                <span class="icon"><img src="assets/images/person-outline.svg"></span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="wrapper">
        
        <div class="container_profile">
                <div class="cont_profile">
                    <?php 
                        if(isset($_COOKIE['user']) == false):
                    ?>
                    <div class="authCont">
                    <div id="login_cont">
                        <form action="validation/auth.php" method="post" style="width: 100%; height: 100%;">
                            <h1>Авторизация</h1>
                            <input class="entry_field" type="text" name="user_login" placeholder="Введите логин" required><br>
                            <input class="entry_field" type="password" name="user_pass" placeholder="Введите пароль" required><br>
                            <p class="wrong_access"><?php if(isset($_COOKIE['error_access']) == true):?>
                            <?=$_COOKIE['error_access']?>
                            <?php endif;?></p>
                            <button class="btn_valid" type="submit">Войти</button>
                            <p>Нет аккаунта? <input class="toggle_btn" value="Зарегистрируйтесь" type="button" onmousedown="switchReg('pass_cont','login_cont')"></p>
                        </form>
                    </div>
                    <div id="pass_cont">
                        <form action="validation/reg.php" method="post" style="width: 100%; height: 100%;">
                            <h1>Регистрация</h1>
                            <input class="entry_field" type="text" name="user_name" placeholder="Введите имя" required><br>
                            <input class="entry_field" type="text" name="user_login" placeholder="Введите логин" required><br>
                            <input class="entry_field" type="password" name="user_pass" placeholder="Введите пароль" required><br>
                            <p class="wrong_access"><?php if(isset($_COOKIE['error_access']) == true):?>
                            <?=$_COOKIE['error_access']?>
                            <?php endif;?></p>
                            <button class="btn_valid" type="submit">Регистрация</button>
                            <p>Уже зарегистрированы? <input class="toggle_btn" value="Войдите в аккаунт" type="button" onmousedown="switchReg('pass_cont','login_cont')"></p>
                        </form>
                    </div>
                    </div>

                        <?php else:?>
                    <div class="userProfile" id="userProfile">
                        
                        <h1>Профиль</h1>
                        <div class="profileContainer">
                        <div class="userData">
                            <h2>Личная информация</h2>
                            <p>Имя: <?=$userProfile['user_name']?></p>

                            <p>Фамилия: <?=$userProfile['user_surname']?> <input class="toggle_btn" value="Изменить" type="button" onmousedown="show('surnameEdit')">
                                <div id="surnameEdit">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="text" name="user_surname" placeholder="Фамилия"><br>
                                        <button class="" name="changeSurname" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button" onmousedown="hide('surnameEdit')">
                                    </form>
                                </div></p>
                            
                            <p>Почта: <?=$userProfile['user_email']?> <input class="toggle_btn" value="Изменить" type="button" onmousedown="show('emailEdit')">
                                <div id="emailEdit">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="text" name="user_email" placeholder="Электронная почта"><br>
                                        <button class="" name="changeEmail" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button" onmousedown="hide('emailEdit')">
                                    </form>
                                </div></p>
                        
                            <p>Адрес: <?=$userProfile['user_address']?> <input class="toggle_btn" value="Изменить" type="button" onmousedown="show('addressEdit')">
                                <div id="addressEdit">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="text" name="user_city" placeholder="Город"><br>
                                        <input type="text" name="user_street" placeholder="Улица"><br>
                                        <input type="text" name="user_home" placeholder="Дом"><br>
                                        <button class="" name="changeAddress" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button" onmousedown="hide('addressEdit')">
                                    </form>
                                </div></p>
                            
                            <p>Номер телефона: <?=$userProfile['user_number']?> <input class="toggle_btn" value="Изменить" type="button" onmousedown="show('numberEdit')">
                                <div id="numberEdit">
                                    <form action="" method="post" style="width: 100%; height: 100%;">
                                        <input type="tel" name="user_number" placeholder="+7(XXX)XXX-XX-XX" required pattern="\+7\s?[\(]{0,1}[0-9][0-9]{2}[\)]{0,1}\s?\d{3}[-]{0,1}\d{2}[-]{0,1}\d{2}""><br>
                                        <button class="" name="changeNumber" type="submit">Сохранить</button>
                                        <input class="toggle_btn" value="Закрыть" type="button" onmousedown="hide('numberEdit')">
                                    </form>
                                </div></p>
                                <p><a href="validation/exit.php" class="toggle_btn" style="color:blue;">Выйти</a></p>
                        </div>

                        <div class="userOrder">
                            <?php if($userProfile['user_group'] == "admin"):?>
                                <h2>Панель управления</h2>
                                <div class="MonitorBtn"><input class="toggle_btn_monitor" value="Управление пользователями" type="button" onmousedown="switchReg('userMonitor','userProfile')"></div>
                                <div class="MonitorBtn"><input class="toggle_btn_monitor" value="Управление заказами" type="button" onmousedown="switchReg('orderMonitor','userProfile')"></div>
                                <div class="MonitorBtn"><input class="toggle_btn_monitor" value="Управление комплектующими" type="button" onmousedown="switchReg('componentMonitor','userProfile')"></div>
                            <?php else:?>
                            <div class="userAssembly">
                                <h2>Ваши конфигурации</h2>
                                <div class="favoriteTable">
                                    <h4>Сохранённые сборки</h4>
                                    <div class="contTable">
                                        <?php
                                            $sql = "SELECT assembly_name,assembly_price,assembly.assembly_id,favorites.favorit_id FROM users,assembly,favorites
                                                    WHERE assembly.assembly_id = favorites.assembly_id AND users.user_id = {$_COOKIE['uId']} AND favorites.user_id = {$_COOKIE['uId']}
                                                    ORDER BY `favorites`.`favorit_id` ASC";
                                            echo "<span class=\"assemblyTable\"><span>Название сборки</span><span>Стоимость</span><span></span></span><br><div class=\"lineSpan\"></div>";
                                            if ($result = $mysql->query($sql)) {
                                                while($row = mysqli_fetch_array($result)){
                                                    if($row['assembly_id'] > 3)
                                                    {
                                                        $row['assembly_name'] = "Сборка $row[0]";
                                                    }
                                                    $favId="i{$row['favorit_id']}";
                                                    echo "<form method=\"POST\"><span class=\"assemblyTable\"><span><a href=\"assembly.php?check-saved={$row['assembly_id']}\">{$row['assembly_name']}</a></span><span>{$row['assembly_price']}</span><span><input style=\"display:none\" name=".$favId." type=\"checkbox\" value=\"{$row['favorit_id']}\" checked><button class=\"delBtn\" name=\"deleteAssembly\" type=\"submit\" value=\"{$row['assembly_id']}\">Удалить</button></span></span><br></form>";
                                            }
                                            }
                                        ?>
                                    </div>
                                </div>
                                <div class="orderTable">
                                    <h4>Заказы</h4>
                                    <div class="contTable">
                                        <?php
                                            $sql = "SELECT assembly_name,assembly_price,assembly.assembly_id,status FROM users,assembly,orders
                                                    WHERE assembly.assembly_id = orders.assembly_id AND users.user_id = {$_COOKIE['uId']} AND orders.user_id = {$_COOKIE['uId']}
                                                    ORDER BY `orders`.`order_id` ASC";
                                            echo "<span class=\"assemblyTable\"><span>Название сборки</span><span>Стоимость</span><span>Статус</span></span><br><div class=\"lineSpan\"></div>";
                                            if ($result = $mysql->query($sql)) {
                                                while($row = mysqli_fetch_array($result)){
                                                    if($row['assembly_id'] > 3)
                                                    {
                                                        $row['assembly_name'] = "Сборка $row[0]";
                                                    }
                                                    echo "<span class=\"assemblyTable\"><span><a href=\"assembly.php?check-purchased={$row['assembly_id']}\">{$row['assembly_name']}</a></span><span>{$row['assembly_price']}</span><span>{$row['status']}</span></span><br>";
                                            }
                                            }
                                            $mysql->close();
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif;?>
                        </div>
                        </div>
                    </div>
                    <?php if($userProfile['user_group'] == "admin"):?>
                    <div class="userProfile" id="userMonitor" style="display:none;">
                        <h1>Управление пользователями</h1>
                        <input class="toggle_btn_profile" value="Вернуться в профиль" type="button" onmousedown="switchReg('userProfile','userMonitor')">
                        <div class="containerMonitor">
                                    <?php
                                        echo "<span class=\"assemblyTable\"><span>Имя</span><span>Логин</span><span>Группа</span><span style=\"width:60%\">Адрес</span><span>Номер</span><span></span></span><br><div class=\"lineSpan\"></div>";
                                        $sql = "SELECT user_id,user_name, user_login, user_group, user_address, user_number FROM users";
                                        if ($result = $mysql->query($sql)) {
                                            while($row = mysqli_fetch_array($result)){
                                                $usId="i{$row['user_id']}";
                                                echo "<form method=\"POST\"><span class=\"assemblyTable\"><span>{$row['user_name']}</span><span>{$row['user_login']}</span><span>{$row['user_group']}</span>
                                                <span style=\"width:60%\">{$row['user_address']}</span><span>{$row['user_number']}</span><span><input style=\"display:none\" name=".$usId." type=\"checkbox\" value=\"{$row['user_id']}\" checked><button class=\"delBtn\" name=\"deleteUser\" type=\"submit\">Удалить</button></span></span><br></form>";
                                        }
                                        }
                                    ?>
                        </div>
                    </div>

                    <div class="userProfile" id="orderMonitor" style="display:none;">
                        <h1>Управление заказами</h1>
                        <input class="toggle_btn_profile" value="Вернуться в профиль" type="button" onmousedown="switchReg('userProfile','orderMonitor')">
                        <div class="containerMonitor">
                        <?php
                                        echo "<span class=\"assemblyTable\"><span>Покупатель</span><span style=\"width:60%\">Адрес</span><span>Сборка</span><span>Стоимость</span><span>Статус</span>
                                        <span></span>
                                        </span><br><div class=\"lineSpan\"></div>";
                                        $sql = "SELECT user_name,user_surname,user_address,assembly_name,assembly_price,order_id,status,assembly.assembly_id FROM users,assembly,orders
                                                WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id
                                                ORDER BY `orders`.`order_id` ASC";
                                        if ($result = $mysql->query($sql)) {
                                            while($row = mysqli_fetch_array($result)){
                                                $orId="i{$row['order_id']}";
                                                if($row['assembly_id'] > 3)
                                                    {
                                                        $row['assembly_name'] = "Сборка $row[3]";
                                                    }
                                                echo "<form method=\"POST\"><span class=\"assemblyTable\"><span>{$row['user_name']} {$row['user_surname']}</span><span style=\"width:60%\">{$row['user_address']}</span>
                                                <span>{$row['assembly_name']}</span><span>{$row['assembly_price']}</span>
                                                <span><select size=\"1\" name=".$orId.">
                                                <option selected hidden value=\"{$row['status']}\">{$row['status']}</option>
                                                <option value=\"Обрабатывается\">Обрабатывается</option>
                                                <option value=\"Собирается\">Собирается</option>
                                                <option value=\"Доставляется\">Доставляется</option>
                                                <option value=\"Выполнен\">Выполнен</option>
                                                </select></span><span><button class=\"delBtn\" style=\"color:blue;\" name=\"editOrderStatus\" type=\"submit\" value=\"{$row['order_id']}\">Сохранить</button></span>
                                                </span><br></form>";
                                        }
                                        }
                                        
                                    ?>
                        </div>
                    </div>

                    <div class="userProfile" id="componentMonitor" style="display:none;">
                        <h1>Управление комплектующими</h1>
                        <br>
                        <input class="toggle_btn_profile" value="Вернуться в профиль" type="button" onmousedown="switchReg('userProfile','componentMonitor')">
                        <br>
                        <div class="containerMonitor">
                            <div class="cmpForm">
                            <h3>Добавить комплектующие</h3><br>
                            <form method="POST">
                            <span class="assemblyTable">
                                <span><input type="text" placeholder="Название" name="nm" style="width:200px;"></span>
                                <span><input type="number" placeholder="Стоимость" name="pr"></span>
                                <span><input type="number" placeholder="Количество" name="col"></span>
                                <span><input type="number" placeholder="TDP" name="tdp"></span>
                                <span><input id="tdpInp" type="checkbox" value="1" name="vc"><label for="tdpInp">Графическое ядро</label></span>
                                <?php
                                $sql = "SELECT * FROM categories ORDER BY `categories`.`category_id` ASC";
                                echo "<span><select size=\"1\" name=\"cat\"><option selected hidden disabled>Категория</option>";
                                if ($result = $mysql->query($sql)) {
                                    while($row = mysqli_fetch_array($result)){
                                echo "<option value=\"{$row['category_id']}\">{$row['category_name']}</option>";
                                    }}
                                echo "</select></span>";
                                $sql = "SELECT * FROM sockets ORDER BY `sockets`.`socket_id` ASC";
                                echo "<span><select size=\"1\" name=\"sock\"><option selected hidden disabled>Сокет</option>";
                                if ($result = $mysql->query($sql)) {
                                    while($row = mysqli_fetch_array($result)){
                                echo "<option value=\"{$row['socket_id']}\">{$row['socket_type']}</option>";
                                    }}
                                echo "</select></span>";
                                ?>
                                
                                <span><button class="delBtn" style="color:blue;" name="addComponent" type="submit">Добавить</button></span>
                                </span>
                                <br>
                            </form>
                            <br>
                            <h3>Список комплектующих</h3>
                            </div>
                        <?php
                                echo "<br><br><div class=\"lineSpan\"></div><span class=\"assemblyTable\"><span style=\"width:5%\">ID</span><span>Категория</span><span>Название</span><span style=\"width:5%\">Количество</span><span style=\"width:10%\">Стоимость</span>
                                        </span><br><div class=\"lineSpan\"></div>";
                            $sql = "SELECT * FROM components,categories WHERE components.category_id = categories.category_id ORDER BY `components`.`component_id` ASC";
                            if ($result = $mysql->query($sql)) {
                                while($row = mysqli_fetch_array($result)){
                                    echo "<span class=\"assemblyTable\"><span style=\"width:5%\">{$row['component_id']}</span><span>{$row['category_name']}</span><span>{$row['component_name']}</span><span style=\"width:5%\">{$row['amount']}</span><span style=\"width:10%\">{$row['component_price']}</span>
                                    </span><br>";
                                }
                            }
                        ?>
                        </div>
                    </div>

                    <?php endif;?>
                    <?php endif;?>
                </div>
            </div>
        </div>



        <footer class="footer">
        <span>© 2022 Aion Corporation</span>
        <span>Designed by <a href="https://vk.com/aswatthaman">Aleksey Schumann</a></span>
        </footer>
        
        
        <script src="assets/js/scripts.js"></script>
    </body>
</html>