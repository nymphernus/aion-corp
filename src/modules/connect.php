<?php
function connect()
{
    $dbhost = "db";  // "127.0.0.1"
    $dbuser = "admin";
    $dbpass = "1234";
    $dbname = "aion_bd";
    $mysql = new mysqli($dbhost,$dbuser,$dbpass,$dbname);
    return $mysql;
}
?>