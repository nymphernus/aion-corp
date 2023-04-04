<?php
    $name = $_POST['user_name'];
    $login = $_POST['user_login'];
    $pass = $_POST['user_pass'];
    $group = 'user';

    if(mb_strlen($login)<5 || mb_strlen($login)>25)
    {
        if(mb_strlen($name)<3 || mb_strlen($name)>20)
        {
            if(mb_strlen($pass)<4 || mb_strlen($pass)>20)
            {
                setcookie('error_access',"Имя, Логин и Пароль слишком короткие",time()+1,"/profile.php");
            }
            else
            {
                setcookie('error_access',"Имя и Логин слишком короткие",time()+1,"/profile.php");
            }
        }
        else if(mb_strlen($pass)<4 || mb_strlen($pass)>20)
        {
            setcookie('error_access',"Логин и Пароль слишком короткие",time()+1,"/profile.php");
        }
        else
        {
            setcookie('error_access',"Логин слишком короткий",time()+1,"/profile.php");
        }
    }
    else if(mb_strlen($name)<3 || mb_strlen($name)>20)
    {
        if(mb_strlen($pass)<4 || mb_strlen($pass)>20)
        {
            setcookie('error_access',"Имя и Пароль слишком короткие",time()+1,"/profile.php");
        }
        else
        {
            setcookie('error_access',"Имя слишком короткое",time()+1,"/profile.php");
        }
    }
    else if(mb_strlen($pass)<4 || mb_strlen($pass)>20)
    {
        setcookie('error_access',"Пароль слишком короткий",time()+1,"/profile.php");
    }
    else
    {
        $pass = md5($pass."i0b1tzvc7");
    
        require '../modules/connect.php';
        $mysql = connect();
        mysqli_set_charset($mysql, 'utf8');

        $result = $mysql -> query("SELECT * FROM `users` WHERE `user_login` = '$login'");
        $user = $result->fetch_assoc();
        if($login == $user['user_login'])
        {
            setcookie('error_access',"Такой пользователь уже существует",time()+1,"/profile.php");
            $mysql -> close();
        }
        else
        {
            $mysql -> query("INSERT INTO `users` (`user_name`, `user_login`, `user_pass`, `user_group`) VALUE('$name','$login','$pass','$group')");
            $mysql -> close();
        }
    }
    header('Location: /profile.php');
?>