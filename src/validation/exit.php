<?php
    setcookie('user', '', time() - 3600, "/");
    setcookie('login', '', time() - 3600, "/");
    setcookie('uId', '', time() - 3600, "/");
    header('Location: /profile.php');
    exit();
?>