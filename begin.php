<?php
    $name = 'Администратор';
    $login = 'admin';
    $pass = 'admin';
    $group = 'admin';
    $pass = md5($pass."i0b1tzvc7");

    require 'modules/connect.php';
    $mysql = connect();
    mysqli_set_charset($mysql, 'utf8');

    $result = $mysql -> query("SELECT * FROM `users` WHERE `user_login` = '$login'");
    $user = $result->fetch_assoc();
    if($login == $user['user_login'])
    {
        echo "Администратор уже создан";
        $mysql -> close();
        echo "<br><a href='/index.php'>На главную</a>";
        exit();
    }
    else
    {
        $mysql -> query("INSERT INTO `users` (`user_name`, `user_login`, `user_pass`, `user_group`) VALUE('$name','$login','$pass','$group')");
        $mysql -> close();
    }

    header('Location: /profile.php');
?>