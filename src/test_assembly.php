<?php
$_SERVER["REQUEST_METHOD"]="POST";
$_POST["save"]="1";
$_SERVER["REMOTE_ADDR"]="127.0.0.1";
$_COOKIE=[];
$_SESSION=[];
chdir("/var/www/html");
include "assembly.php";
