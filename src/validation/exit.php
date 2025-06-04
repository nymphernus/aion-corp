<?php
    setcookie('user', $user['user_name'], time() - 3600 * 2, "/");
    setcookie('login', $user['user_group'], time() - 3600 * 2, "/");
    header('Location: /profile.php');
?>