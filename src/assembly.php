<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
require_once 'modules/configurator.php';
if (isset($_POST['price'])) {
    configure($_POST['price']);
    $idA = $_COOKIE['assemblyId'];
} else if (isset($_GET['init'])) {
    $idA = $_GET['init'];
} else if (isset($_GET['check-purchased'])) {
    $idA = $_GET['check-purchased'];
} else if (isset($_GET['check-saved'])) {
    $idA = $_GET['check-saved'];
} else {
    $idA = $_COOKIE['assemblyId'];
}
require 'modules/connect.php';
$mysql = connect();
mysqli_set_charset($mysql, 'utf8');
$assemb = mysqli_fetch_array($mysql->query("SELECT * FROM assembly,components WHERE assembly_id = $idA"));
$cpu = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = cpu_id"));
$motherboard = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = motherboard_id"));
if (isset($assemb['gpu_id'])) {
    $gpu = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = gpu_id"));
    $compId[2] = $gpu[1];
}
$ram = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = ram_id"));
$case = mysqli_fetch_array($mysql->query("SELECT components.image,components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = case_id"));
$cooler = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = cooler_id"));
$power_supply = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = power_supply_id"));
$ssd = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = ssd_id"));
$compId[0] = $cpu[1];
$compId[1] = $motherboard[1];
$compId[3] = $ram[1];
$compId[4] = $case[2];
$compId[5] = $cooler[1];
$compId[6] = $power_supply[1];
$compId[7] = $ssd[1];
$compId[8] = $assemb[14];
if (isset($assemb['os'])) {
    $compId[12] = $assemb['os'];
}
if (isset($assemb['ssd_2_id'])) {
    $ssd2 = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = ssd_2_id"));
    $compId[9] = $ssd2[1];
}
if (isset($assemb['hdd_id'])) {
    $hdd = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = hdd_id"));
    $compId[10] = $hdd[1];
}
if (isset($assemb['dvd_id'])) {
    $dvd = mysqli_fetch_array($mysql->query("SELECT components.component_name,components.component_id FROM assembly,components WHERE assembly_id = $idA  AND component_id = dvd_id"));
    $compId[11] = $dvd[1];
}
setcookie('arrId', serialize($compId), time() + 3600);
if (($idA > 3) && ((!isset($_GET['check-purchased'])) && (!isset($_GET['check-saved'])))) {
    $mysql->query("DELETE FROM assembly WHERE assembly_id = $idA");
}
if (isset($_POST['save']) && isset($_COOKIE['user']) && isset($_COOKIE['uId'])) {
    if (!isset($_GET['check-purchased'])) {
        $compId2 = unserialize($_COOKIE['arrId'] ?? '', ["allowed_classes" => false]);

        $stmt = $mysql->prepare("SELECT favorites.assembly_id FROM favorites WHERE assembly_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $idA, $_COOKIE['uId']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_array();

        if (!isset($row[0])) {
            $stmt = $mysql->prepare("SELECT MAX(favorit_id) FROM favorites");
            $stmt->execute();
            $checklast = $stmt->get_result()->fetch_array();
            $maxID = ($checklast[0] ?? 0) + 1;

            if ($idA > 3 && is_array($compId2)) {
                $name = "#$idA";
                $stmt = $mysql->prepare("INSERT INTO `assembly` (`assembly_id`,`assembly_name`, `cpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `assembly_price`) VALUES(?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param(
                    "isiiiiiiii",
                    $idA,
                    $name,
                    $compId2[0],
                    $compId2[1],
                    $compId2[3],
                    $compId2[4],
                    $compId2[5],
                    $compId2[6],
                    $compId2[7],
                    $compId2[8]
                );
                $stmt->execute();

                if (isset($gpu) && isset($compId2[2])) {
                    $stmt = $mysql->prepare("UPDATE `assembly` SET `gpu_id` = ? WHERE `assembly_id` = ?");
                    $stmt->bind_param("ii", $compId2[2], $idA);
                    $stmt->execute();
                }
                if (isset($compId2[12])) {
                    $stmt = $mysql->prepare("UPDATE `assembly` SET `os` = ? WHERE `assembly_id` = ?");
                    $stmt->bind_param("si", $compId2[12], $idA);
                    $stmt->execute();
                }
                if (isset($ssd2) && isset($compId2[9])) {
                    $stmt = $mysql->prepare("UPDATE `assembly` SET `ssd_2_id` = ? WHERE `assembly_id` = ?");
                    $stmt->bind_param("ii", $compId2[9], $idA);
                    $stmt->execute();
                }
                if (isset($hdd) && isset($compId2[10])) {
                    $stmt = $mysql->prepare("UPDATE `assembly` SET `hdd_id` = ? WHERE `assembly_id` = ?");
                    $stmt->bind_param("ii", $compId2[10], $idA);
                    $stmt->execute();
                }
                if (isset($dvd) && isset($compId2[11])) {
                    $stmt = $mysql->prepare("UPDATE `assembly` SET `dvd_id` = ? WHERE `assembly_id` = ?");
                    $stmt->bind_param("ii", $compId2[11], $idA);
                    $stmt->execute();
                }
            }

            $stmt = $mysql->prepare("INSERT INTO `favorites` (`favorit_id`,`user_id`,`assembly_id`) VALUES(?,?,?)");
            $stmt->bind_param("iii", $maxID, $_COOKIE['uId'], $idA);
            $stmt->execute();
        }
    }
    header('Location: /profile.php');
    exit();
}
if (isset($_POST['buy'])) {
    if (!isset($_GET['check-saved']) && isset($_COOKIE['user']) && isset($_COOKIE['uId'])) {
        $checklast = mysqli_fetch_array($mysql->query("SELECT MAX(order_id) FROM orders"));
        $maxID = $checklast[0] + 1;
        $compId2 = unserialize($_COOKIE['arrId'], ["allowed_classes" => false]);

        $sql = "SELECT orders.assembly_id FROM users,assembly,orders WHERE $idA = orders.assembly_id AND orders.user_id = $_COOKIE[uId]";
        $row = mysqli_fetch_array($mysql->query($sql));

        if (!isset($row[0])) {
            if ($idA > 3 && !isset($_GET['check-purchased'])) {
                $name = "#$idA";
                $mysql->query("INSERT INTO `assembly` (`assembly_id`,`assembly_name`, `cpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `assembly_price`)
                VALUE($idA,'$name','$compId2[0]','$compId2[1]','$compId2[3]','$compId2[4]','$compId2[5]','$compId2[6]','$compId2[7]','$compId2[8]')");
                if (isset($gpu) && isset($compId2[2])) {
                    $mysql->query("UPDATE `assembly` SET `gpu_id` = '$compId2[2]' WHERE `assembly_id` = '$idA'");
                }
                if (isset($compId2[12])) {
                    $mysql->query("UPDATE `assembly` SET `os` = '$compId2[12]' WHERE `assembly_id` = '$idA'");
                }
                if (isset($ssd2) && isset($compId2[9])) {
                    $mysql->query("UPDATE `assembly` SET `ssd_2_id` = '$compId2[9]' WHERE `assembly_id` = '$idA'");
                }
                if (isset($hdd) && isset($compId2[10])) {
                    $mysql->query("UPDATE `assembly` SET `hdd_id` = '$compId2[10]' WHERE `assembly_id` = '$idA'");
                }
                if (isset($dvd) && isset($compId2[11])) {
                    $mysql->query("UPDATE `assembly` SET `dvd_id` = '$compId2[11]' WHERE `assembly_id` = '$idA'");
                }
            }

            $mysql->query("INSERT INTO `orders` (`order_id`,`user_id`,`assembly_id`,`status`) VALUE('$maxID','$_COOKIE[uId]','$idA','Обрабатывается')");
        }
        header('Location: /profile.php');
        exit();
    }
}
?>
<html>

<head>
    <meta charset="utf-8">
    <title>Интернет-магазин персональных компьютеров индивидуальной комплектации AION CORP.</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/configurator.css">
    <link rel="shortcut icon" href="assets/images/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;700&display=swap" rel="stylesheet">
</head>

<body>
    <header class="header">
        <div class="header__inner">
            <a href="/">
                <div><img class=logo src="assets/images/logo.png" alt="logo"></div>
            </a>
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
                            <span class="text"><?php if (isset($_COOKIE['user']) == false): ?>
                                    Войти<?php else: ?><?= $_COOKIE['user'] ?><?php endif; ?></span>
                            <span class="icon"><img src="assets/images/person-outline.svg"></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </header>
    <div class="wrapper">
        <div class="container_configurator">
            <div class="shell_cfg">
                <div class="components">
                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-1.png"></div>
                        <div class="text_kp">
                            <h3>Процессор</h3>
                            <p>Процессор – сердце компьютера. Чем выше частота тем быстрее обрабатываются данные,
                                а количество ядер позволяет распределить нагрузку и повысить быстродействие всей
                                системы.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $cpu['component_name'] ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-2.png"></div>
                        <div class="text_kp">
                            <h3>Материнская плата</h3>
                            <p>Материнская плата – основа компьютера. На плату как конструктор собираются остальные
                                комплектующие.
                                Материнская плата не отвечает за быстродействие компьютера, но отвечает за функционал.
                            </p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $motherboard['component_name'] ?></p>
                        </div>
                    </div>

                    <?php if (isset($assemb['gpu_id'])): ?>
                        <div class="kp">
                            <div class="img_kp"><img src="assets/images/cfg-icons/configurator-3.png"></div>
                            <div class="text_kp">
                                <h3>Видеокарта</h3>
                                <p>Видеокарта – это устройство отвечающее за поддержку и быстродействие игрового процесса.
                                    Основой видеокарты есть графический чип, чем выше мощность тем лучше.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= $gpu['component_name'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-4.png"></div>
                        <div class="text_kp">
                            <h3>Оперативная память</h3>
                            <p>Оперативная память – отвечает за то, с каким объемом данных в данный момент времени может
                                работать процессор. Чем ее больше, тем быстрее работает компьютер.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $ram['component_name'] ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-5.png"></div>
                        <div class="text_kp">
                            <h3>Блок питания</h3>
                            <p>Блок питания обеспечивает током все компоненты и противостоит всем перегрузкам и скачкам
                                сети.
                                Мощность блока питания выбирается всегда с запасом, так он дольше прослужит без пиковых
                                нагрузок.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $power_supply['component_name'] ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-6.png"></div>
                        <div class="text_kp">
                            <h3>Корпус</h3>
                            <p>Корпус – не маловажная составляющая системного блока. Толщина стенок определяют прочность
                                и шума-изоляцию. Размер влияет на охлаждение внутренних компонентов.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $case['component_name'] ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-7.png"></div>
                        <div class="text_kp">
                            <h3>Кулер</h3>
                            <p>Кулер – радиатор с прикреплённым вентилятором предназначенный для охлаждения процессора.
                                Показатель теплоотвода (TDP) кулера не должен быть меньше показателя тепловыделения
                                (TDP) процессора.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $cooler['component_name'] ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-9.png"></div>
                        <div class="text_kp">
                            <h3>Накопитель SSD</h3>
                            <p>Твердотельный накопитель – это скоростное устройство для хранения данных. Его скорость
                                работы в несколько раз быстрее обычного жесткого диска.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= $ssd['component_name'] ?></p>
                        </div>
                    </div>

                    <?php if (isset($compId[9])): ?>
                        <div class="kp">
                            <div class="img_kp"><img src="assets/images/cfg-icons/configurator-9.png"></div>
                            <div class="text_kp">
                                <h3>Накопитель SSD 2</h3>
                                <p>Твердотельный накопитель – это скоростное устройство для хранения данных. Его скорость
                                    работы в несколько раз быстрее обычного жесткого диска.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= $ssd2['component_name'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($compId[10])): ?>
                        <div class="kp">
                            <div class="img_kp"><img src="assets/images/cfg-icons/configurator-8.png"></div>
                            <div class="text_kp">
                                <h3>Накопитель HDD</h3>
                                <p>Жесткий диск – устройство для хранения данных, характеризуется объемом и скоростью
                                    (чтение/запись) чем больше номинальный объем тем больше данных поместится.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= $hdd['component_name'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($compId[11])): ?>
                        <div class="kp">
                            <div class="img_kp"><img src="assets/images/cfg-icons/configurator-10.png"></div>
                            <div class="text_kp">
                                <h3>Оптический привод</h3>
                                <p>Оптический привод – устройство чтения и записи CD/DVD дисков.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= $dvd['component_name'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($compId[12])): ?>
                        <div class="kp">
                            <div class="img_kp"><img style="width: 85%; margin-left:10px;"
                                    src="assets/images/cfg-icons/configurator-11.png"></div>
                            <div class="text_kp">
                                <h3>Операционная система</h3>
                                <p>Операционная система – это комплекс взаимосвязанных программ, предназначенных для
                                    управления ресурсами вычислительного устройства и организации взаимодействия с
                                    пользователем.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= $assemb['os'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>


                <div class="about_assembly">
                    <div class="dv">
                        <h1>
                            <?php if ($idA <= 3) {
                                echo $assemb['assembly_name'];
                            } else {
                                echo "Сборка {$assemb['assembly_name']}";
                            }
                            ?>
                        </h1>
                    </div>
                    <div class="assemblyInf">
                        <p>Стоимость - <font style="color:rgb(200, 11, 11);font-weight: bold;;">
                                <?= $assemb['assembly_price'] ?>руб.</font>
                        </p>
                        <p>Номер сборки - <?= $assemb['assembly_id'] ?></p>
                    </div>
                    <div class="assembly_img"><img class="caseImg" src="<?= $case['image'] ?>" alt="нет изображения">
                    </div>
                    <?php if (isset($_COOKIE['user']) == false): ?>
                        <form>
                            <div class="dv"><input name="save" type="submit" value="Сохранить" disabled></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить" disabled></div>
                        </form>
                    <?php elseif (isset($_GET['check-purchased'])): ?>
                        <form method="post">
                            <div class="dv"><input name="save" type="submit" value="Сохранить"></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить" disabled></div>
                        </form>
                    <?php elseif (isset($_GET['check-saved'])): ?>
                        <form method="post">
                            <div class="dv"><input name="save" type="submit" value="Сохранить" disabled></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить"></div>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <div class="dv"><input name="save" type="submit" value="Сохранить"></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить"></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <footer class="footer">
        <span>© 2022 Aion Corporation</span>
        <span>Designed by <a href="https://github.com/nymphernus">Aleksey Schumann</a></span>
    </footer>
</body>

</html>

<?php
if (isset($mysql)) {
    $mysql->close();
}
?>