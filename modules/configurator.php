<?php
function configure($budget)
{
    require 'modules/connect.php';
    $mysql = connect();
    mysqli_set_charset($mysql, 'utf8');
    $checklast = mysqli_fetch_array($mysql->query("SELECT MAX(assembly_id) FROM assembly"));
    $maxID = $checklast[0]+1;
    $name = "#$maxID";
    $budget_whole = intval($budget);

    if(isset($_POST['choice_os']) && $_POST['choice_os'] == '1'){
        $budget -= 11000;
        $os = "Windows 10 Home";
        }
    if(isset($_POST['choice_dvd']) && $_POST['choice_dvd'] == '1'){
        $dvd = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 10 ORDER BY `components`.`component_price` ASC"));
        $budget -= $dvd['component_price'];
        }
    if(isset($_POST['choice_ssd']) && $_POST['choice_ssd'] == '1'){
        $ssd2 = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 9 AND component_price = 3999 ORDER BY `components`.`component_price` ASC"));
        $budget -= $ssd2['component_price'];
        }
    if(isset($_POST['choice_hdd']) && $_POST['choice_hdd'] == '1'){
        $hdd = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 8 AND component_price = 3999 ORDER BY `components`.`component_price` ASC"));
        $budget -= $hdd['component_price'];
        }
    // Минимальная сборка
if($budget <= 22499){
    $cpu = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 1 AND video_core = 1 ORDER BY `components`.`component_price` ASC"));
    $budget_whole = $cpu['component_price'];
    $motherboard = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 2 AND socket_id = {$cpu['socket_id']} ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $motherboard['component_price'];
    $ram = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 4 ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $ram['component_price'];
    $power_supply = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 5 ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $power_supply['component_price'];
    $case = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 6 ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $case['component_price'];
    $cooler = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 7 AND tdp > {$cpu['tdp']} AND tdp < {$cpu['tdp']}+40 ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $cooler['component_price'];
    $ssd = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE category_id = 9 ORDER BY `components`.`component_price` ASC"));
    $budget_whole += $ssd['component_price'];
}
    // Сборки без видеокарты
else if($budget <= 75000){
    $cpu = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*35 AND category_id = 1 AND video_core = 1 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$cpu['component_price'];
    $motherboard = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*32 AND category_id = 2 AND socket_id = {$cpu['socket_id']} ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$motherboard['component_price'];
    $ram = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*25 AND category_id = 4 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$ram['component_price'];
    $power_supply = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*30 AND category_id = 5 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$power_supply['component_price'];
    $case = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*48 AND category_id = 6 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$case['component_price'];
    $cooler = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*35 AND category_id = 7 AND tdp > {$cpu['tdp']} AND tdp < {$cpu['tdp']}+40 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$cooler['component_price'];
    $ssd = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= $budget AND category_id = 9 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$ssd['component_price'];
    $budget_whole -= $budget;
}
    // Сборки с видеокартой
else{
    $cpu_bud = ($budget/100)*30;
    if($cpu_bud >= 40000){
        $cpu_bud = ($cpu_bud/100)*60;
    }
    $cpu = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= $cpu_bud AND category_id = 1 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$cpu['component_price'];
    $gpu = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*48 AND category_id = 3 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$gpu['component_price'];
    $motherboard = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*24 AND category_id = 2 AND socket_id = {$cpu['socket_id']} ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$motherboard['component_price'];
    $ram = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*34 AND category_id = 4 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$ram['component_price'];
    $power_supply = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*32 AND category_id = 5 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$power_supply['component_price'];
    $case = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*56 AND category_id = 6 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$case['component_price'];
    $cooler = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= ($budget/100)*35 AND category_id = 7 AND tdp > {$cpu['tdp']} ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$cooler['component_price'];
    $ssd = mysqli_fetch_array($mysql->query("SELECT * FROM components WHERE component_price <= $budget AND category_id = 9 ORDER BY `components`.`component_price` DESC"));
    $budget = $budget-$ssd['component_price'];
    $budget_whole -= $budget;
}
    $mysql -> query("INSERT INTO `assembly` (`assembly_id`,`assembly_name`, `cpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `assembly_price`)
    VALUE('$maxID','$name','$cpu[0]','$motherboard[0]','$ram[0]','$case[0]','$cooler[0]','$power_supply[0]','$ssd[0]','$budget_whole')");
    if(isset($gpu)){
        $mysql -> query("UPDATE `assembly` SET `gpu_id` = '$gpu[0]' WHERE `assembly_id` = '$maxID'");
    }
    if(isset($os))
    {
        $mysql -> query("UPDATE `assembly` SET `os` = '$os' WHERE `assembly_id` = '$maxID'");
    }
    if(isset($ssd2)){
        $mysql -> query("UPDATE `assembly` SET `ssd_2_id` = '$ssd2[0]' WHERE `assembly_id` = '$maxID'");
    }
    if(isset($hdd)){
        $mysql -> query("UPDATE `assembly` SET `hdd_id` = '$hdd[0]' WHERE `assembly_id` = '$maxID'");
    }
    if(isset($dvd)){
        $mysql -> query("UPDATE `assembly` SET `dvd_id` = '$dvd[0]' WHERE `assembly_id` = '$maxID'");
    }

    setcookie('assemblyId', $maxID, time() + 3600 * 2, "/");
    $mysql -> close();
    header('Location: /assembly.php');
}
function upload($aID)
{
    setcookie('assemblyId', $aID, time() + 3600 * 2, "/");
    header('Location: /assembly.php');
}
?>