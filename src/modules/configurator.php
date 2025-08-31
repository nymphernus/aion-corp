<?php
function configure($budget) {
    require 'modules/connect.php';
    $mysql = connect();
    mysqli_set_charset($mysql, 'utf8');
    
    $stmt = $mysql->prepare("SELECT MAX(assembly_id) FROM assembly");
    $stmt->execute();
    $checklast = $stmt->get_result()->fetch_array();
    $maxID = ($checklast[0] ?? 0) + 1;
    $name = "#$maxID";
    $budget_whole = (int)$budget;
    
    $os = null;
    $dvd = null;
    $ssd2 = null;
    $hdd = null;
    
    if(isset($_POST['choice_os']) && $_POST['choice_os'] == '1') {
        $budget -= 11000;
        $os = "Windows 10 Home";
    }
    
    if(isset($_POST['choice_dvd']) && $_POST['choice_dvd'] == '1') {
        $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 10 ORDER BY `components`.`component_price` ASC LIMIT 1");
        $stmt->execute();
        $dvd = $stmt->get_result()->fetch_assoc();
        if ($dvd) {
            $budget -= $dvd['component_price'];
        }
    }
    
    if(isset($_POST['choice_ssd']) && $_POST['choice_ssd'] == '1') {
        $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 9 AND component_price = 3999 ORDER BY `components`.`component_price` ASC LIMIT 1");
        $stmt->execute();
        $ssd2 = $stmt->get_result()->fetch_assoc();
        if ($ssd2) {
            $budget -= $ssd2['component_price'];
        }
    }
    
    if(isset($_POST['choice_hdd']) && $_POST['choice_hdd'] == '1') {
        $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 8 AND component_price = 3999 ORDER BY `components`.`component_price` ASC LIMIT 1");
        $stmt->execute();
        $hdd = $stmt->get_result()->fetch_assoc();
        if ($hdd) {
            $budget -= $hdd['component_price'];
        }
    }
    
    if($budget <= 22499) {
        $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 1 AND video_core = 1 ORDER BY `components`.`component_price` ASC LIMIT 1");
        $stmt->execute();
        $cpu = $stmt->get_result()->fetch_assoc();
        
        if ($cpu) {
            $budget_whole = $cpu['component_price'];
            
            $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 2 AND socket_id = ? ORDER BY `components`.`component_price` ASC LIMIT 1");
            $stmt->bind_param("i", $cpu['socket_id']);
            $stmt->execute();
            $motherboard = $stmt->get_result()->fetch_assoc();
            
            if ($motherboard) {
                $budget_whole += $motherboard['component_price'];
                
                $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 4 ORDER BY `components`.`component_price` ASC LIMIT 1");
                $stmt->execute();
                $ram = $stmt->get_result()->fetch_assoc();
                
                if ($ram) {
                    $budget_whole += $ram['component_price'];
                    
                    $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 5 ORDER BY `components`.`component_price` ASC LIMIT 1");
                    $stmt->execute();
                    $power_supply = $stmt->get_result()->fetch_assoc();
                    
                    if ($power_supply) {
                        $budget_whole += $power_supply['component_price'];
                        
                        $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 6 ORDER BY `components`.`component_price` ASC LIMIT 1");
                        $stmt->execute();
                        $case = $stmt->get_result()->fetch_assoc();
                        
                        if ($case) {
                            $budget_whole += $case['component_price'];
                            
                            $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 7 AND tdp > ? AND tdp < ? ORDER BY `components`.`component_price` ASC LIMIT 1");
                            $min_tdp = $cpu['tdp'];
                            $max_tdp = $cpu['tdp'] + 40;
                            $stmt->bind_param("ii", $min_tdp, $max_tdp);
                            $stmt->execute();
                            $cooler = $stmt->get_result()->fetch_assoc();
                            
                            if ($cooler) {
                                $budget_whole += $cooler['component_price'];
                                
                                $stmt = $mysql->prepare("SELECT * FROM components WHERE category_id = 9 ORDER BY `components`.`component_price` ASC LIMIT 1");
                                $stmt->execute();
                                $ssd = $stmt->get_result()->fetch_assoc();
                                
                                if ($ssd) {
                                    $budget_whole += $ssd['component_price'];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    else if($budget <= 75000) {
        $cpu_budget = ($budget/100)*35;
        $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 1 AND video_core = 1 ORDER BY `components`.`component_price` DESC LIMIT 1");
        $stmt->bind_param("d", $cpu_budget);
        $stmt->execute();
        $cpu = $stmt->get_result()->fetch_assoc();
        
        if ($cpu) {
            $budget = $budget - $cpu['component_price'];
            
            $motherboard_budget = ($budget/100)*32;
            $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 2 AND socket_id = ? ORDER BY `components`.`component_price` DESC LIMIT 1");
            $stmt->bind_param("di", $motherboard_budget, $cpu['socket_id']);
            $stmt->execute();
            $motherboard = $stmt->get_result()->fetch_assoc();
            
            if ($motherboard) {
                $budget = $budget - $motherboard['component_price'];
                
                $ram_budget = ($budget/100)*25;
                $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 4 ORDER BY `components`.`component_price` DESC LIMIT 1");
                $stmt->bind_param("d", $ram_budget);
                $stmt->execute();
                $ram = $stmt->get_result()->fetch_assoc();
                
                if ($ram) {
                    $budget = $budget - $ram['component_price'];
                    
                    $psu_budget = ($budget/100)*30;
                    $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 5 ORDER BY `components`.`component_price` DESC LIMIT 1");
                    $stmt->bind_param("d", $psu_budget);
                    $stmt->execute();
                    $power_supply = $stmt->get_result()->fetch_assoc();
                    
                    if ($power_supply) {
                        $budget = $budget - $power_supply['component_price'];
                        
                        $case_budget = ($budget/100)*48;
                        $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 6 ORDER BY `components`.`component_price` DESC LIMIT 1");
                        $stmt->bind_param("d", $case_budget);
                        $stmt->execute();
                        $case = $stmt->get_result()->fetch_assoc();
                        
                        if ($case) {
                            $budget = $budget - $case['component_price'];
                            
                            $cooler_budget = ($budget/100)*35;
                            $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 7 AND tdp > ? AND tdp < ? ORDER BY `components`.`component_price` DESC LIMIT 1");
                            $min_tdp = $cpu['tdp'];
                            $max_tdp = $cpu['tdp'] + 40;
                            $stmt->bind_param("dii", $cooler_budget, $min_tdp, $max_tdp);
                            $stmt->execute();
                            $cooler = $stmt->get_result()->fetch_assoc();
                            
                            if ($cooler) {
                                $budget = $budget - $cooler['component_price'];
                                
                                $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 9 ORDER BY `components`.`component_price` DESC LIMIT 1");
                                $stmt->bind_param("d", $budget);
                                $stmt->execute();
                                $ssd = $stmt->get_result()->fetch_assoc();
                                
                                if ($ssd) {
                                    $budget = $budget - $ssd['component_price'];
                                    $budget_whole -= $budget;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    else {
        $cpu_bud = ($budget/100)*30;
        if($cpu_bud >= 40000) {
            $cpu_bud = ($cpu_bud/100)*60;
        }
        
        $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 1 ORDER BY `components`.`component_price` DESC LIMIT 1");
        $stmt->bind_param("d", $cpu_bud);
        $stmt->execute();
        $cpu = $stmt->get_result()->fetch_assoc();
        
        if ($cpu) {
            $budget = $budget - $cpu['component_price'];
            
            $gpu_budget = ($budget/100)*48;
            $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 3 ORDER BY `components`.`component_price` DESC LIMIT 1");
            $stmt->bind_param("d", $gpu_budget);
            $stmt->execute();
            $gpu = $stmt->get_result()->fetch_assoc();
            
            if ($gpu) {
                $budget = $budget - $gpu['component_price'];
                
                $motherboard_budget = ($budget/100)*24;
                $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 2 AND socket_id = ? ORDER BY `components`.`component_price` DESC LIMIT 1");
                $stmt->bind_param("di", $motherboard_budget, $cpu['socket_id']);
                $stmt->execute();
                $motherboard = $stmt->get_result()->fetch_assoc();
                
                if ($motherboard) {
                    $budget = $budget - $motherboard['component_price'];
                    
                    $ram_budget = ($budget/100)*34;
                    $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 4 ORDER BY `components`.`component_price` DESC LIMIT 1");
                    $stmt->bind_param("d", $ram_budget);
                    $stmt->execute();
                    $ram = $stmt->get_result()->fetch_assoc();
                    
                    if ($ram) {
                        $budget = $budget - $ram['component_price'];
                        
                        $psu_budget = ($budget/100)*32;
                        $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 5 ORDER BY `components`.`component_price` DESC LIMIT 1");
                        $stmt->bind_param("d", $psu_budget);
                        $stmt->execute();
                        $power_supply = $stmt->get_result()->fetch_assoc();
                        
                        if ($power_supply) {
                            $budget = $budget - $power_supply['component_price'];
                            
                            $case_budget = ($budget/100)*56;
                            $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 6 ORDER BY `components`.`component_price` DESC LIMIT 1");
                            $stmt->bind_param("d", $case_budget);
                            $stmt->execute();
                            $case = $stmt->get_result()->fetch_assoc();
                            
                            if ($case) {
                                $budget = $budget - $case['component_price'];
                                
                                $cooler_budget = ($budget/100)*35;
                                $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 7 AND tdp > ? ORDER BY `components`.`component_price` DESC LIMIT 1");
                                $stmt->bind_param("di", $cooler_budget, $cpu['tdp']);
                                $stmt->execute();
                                $cooler = $stmt->get_result()->fetch_assoc();
                                
                                if ($cooler) {
                                    $budget = $budget - $cooler['component_price'];
                                    
                                    $stmt = $mysql->prepare("SELECT * FROM components WHERE component_price <= ? AND category_id = 9 ORDER BY `components`.`component_price` DESC LIMIT 1");
                                    $stmt->bind_param("d", $budget);
                                    $stmt->execute();
                                    $ssd = $stmt->get_result()->fetch_assoc();
                                    
                                    if ($ssd) {
                                        $budget = $budget - $ssd['component_price'];
                                        $budget_whole -= $budget;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    if (isset($cpu, $motherboard, $ram, $power_supply, $case, $cooler, $ssd)) {
        $stmt = $mysql->prepare("INSERT INTO `assembly` (`assembly_id`,`assembly_name`, `cpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `assembly_price`) VALUES(?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("isiiiiiiii", 
            $maxID, $name,
            $cpu['component_id'], $motherboard['component_id'], $ram['component_id'],
            $case['component_id'], $cooler['component_id'], $power_supply['component_id'],
            $ssd['component_id'], $budget_whole
        );
        $stmt->execute();
        
        if(isset($gpu)) {
            $stmt = $mysql->prepare("UPDATE `assembly` SET `gpu_id` = ? WHERE `assembly_id` = ?");
            $stmt->bind_param("ii", $gpu['component_id'], $maxID);
            $stmt->execute();
        }
        
        if(isset($os)) {
            $stmt = $mysql->prepare("UPDATE `assembly` SET `os` = ? WHERE `assembly_id` = ?");
            $stmt->bind_param("si", $os, $maxID);
            $stmt->execute();
        }
        
        if(isset($ssd2)) {
            $stmt = $mysql->prepare("UPDATE `assembly` SET `ssd_2_id` = ? WHERE `assembly_id` = ?");
            $stmt->bind_param("ii", $ssd2['component_id'], $maxID);
            $stmt->execute();
        }
        
        if(isset($hdd)) {
            $stmt = $mysql->prepare("UPDATE `assembly` SET `hdd_id` = ? WHERE `assembly_id` = ?");
            $stmt->bind_param("ii", $hdd['component_id'], $maxID);
            $stmt->execute();
        }
        
        if(isset($dvd)) {
            $stmt = $mysql->prepare("UPDATE `assembly` SET `dvd_id` = ? WHERE `assembly_id` = ?");
            $stmt->bind_param("ii", $dvd['component_id'], $maxID);
            $stmt->execute();
        }
        
        setcookie('assemblyId', $maxID, time() + 3600 * 2, "/", "", true, true);
    }
    
    $mysql->close();
    header('Location: /assembly.php');
    exit();
}

function upload($aID) {
    setcookie('assemblyId', (int)$aID, time() + 3600 * 2, "/", "", true, true);
    header('Location: /assembly.php');
    exit();
}
?>