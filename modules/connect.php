<?php
function connect()
{
    $dbhost = "127.0.0.1";
    $dbuser = "root";       //u1697528_rifk7
    $dbpass = "";           //byepeflyk1404
    $dbname = "aion-bd";        //u1697528_aion-bd
    $mysql = new mysqli($dbhost,$dbuser,$dbpass,$dbname);
    return $mysql;
}
?>