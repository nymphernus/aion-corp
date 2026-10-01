<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
require_once 'modules/configurator.php';
require_once __DIR__ . '/modules/connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

csrf_token();

// Проверка CSRF для всех POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if (isset($_POST['price'])) {
    configure($_POST['price']);
    $idA = $_COOKIE['assemblyId'];
} else if (isset($_GET['init'])) {
    $idA = (int)$_GET['init'];
} else if (isset($_GET['check-purchased'])) {
    $idA = (int)$_GET['check-purchased'];
} else if (isset($_GET['check-saved'])) {
    $idA = (int)$_GET['check-saved'];
} else if (isset($_GET['id'])) {
    $idA = (int)$_GET['id'];
} else {
    $idA = (int)($_COOKIE['assemblyId'] ?? 0);
}

require_once 'modules/connect.php';
$mysql = connect();
mysqli_set_charset($mysql, 'utf8');

// Проверяем существование сборки
$checkStmt = db_prepare($mysql, "SELECT assembly_id FROM assembly WHERE assembly_id = ?", "i", $idA);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows === 0) {
    http_response_code(404);
    header('Location: /assembly.php');
    exit();
}

// Получаем данные о сборке через prepared statements
$stmt = db_prepare($mysql, "SELECT * FROM assembly WHERE assembly_id = ?", "i", $idA);
$stmt->execute();
$assemb = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['cpu_id']);
$stmt->execute();
$cpu = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['motherboard_id']);
$stmt->execute();
$motherboard = $stmt->get_result()->fetch_assoc();

// Инициализация переменных
$gpu = null;
$ssd2 = null;
$hdd = null;
$dvd = null;

if (!empty($assemb['gpu_id'])) {
    $stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['gpu_id']);
    $stmt->execute();
    $gpu = $stmt->get_result()->fetch_assoc();
    $compId[2] = $gpu['component_id'];
} else {
    $gpu = null;
}

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['ram_id']);
$stmt->execute();
$ram = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT image, component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['case_id']);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['cooler_id']);
$stmt->execute();
$cooler = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['power_supply_id']);
$stmt->execute();
$power_supply = $stmt->get_result()->fetch_assoc();

$stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['ssd_id']);
$stmt->execute();
$ssd = $stmt->get_result()->fetch_assoc();

$compId[0] = $cpu['component_id'];
$compId[1] = $motherboard['component_id'];
$compId[3] = $ram['component_id'];
$compId[4] = $case['component_id'];
$compId[5] = $cooler['component_id'];
$compId[6] = $power_supply['component_id'];
$compId[7] = $ssd['component_id'];
$compId[8] = $assemb['assembly_price'];

if ($assemb['os']) {
    $compId[12] = $assemb['os'];
}

if ($assemb['ssd_2_id']) {
    $stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['ssd_2_id']);
    $stmt->execute();
    $ssd2 = $stmt->get_result()->fetch_assoc();
    $compId[9] = $ssd2['component_id'];
}

if ($assemb['hdd_id']) {
    $stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['hdd_id']);
    $stmt->execute();
    $hdd = $stmt->get_result()->fetch_assoc();
    $compId[10] = $hdd['component_id'];
}

if ($assemb['dvd_id']) {
    $stmt = db_prepare($mysql, "SELECT component_name, component_id FROM components WHERE component_id = ?", "i", $assemb['dvd_id']);
    $stmt->execute();
    $dvd = $stmt->get_result()->fetch_assoc();
    $compId[11] = $dvd['component_id'];
}

setcookie('arrId', serialize($compId), time() + 3600);
// TODO: убрать после Stage 3.5, если не понадобится (save/buy больше не читают arrId).

// Используем $_SESSION вместо $_COOKIE
$userId = $_SESSION['user_id'] ?? null;
$isLoggedIn = isset($_SESSION['user_id']);

if (isset($_POST['save']) && $isLoggedIn) {
    $stmt = db_prepare($mysql, "SELECT favorites.assembly_id FROM favorites WHERE assembly_id = ? AND user_id = ?", "ii", $idA, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_array();

    if (!isset($row[0])) {
        $stmt = db_prepare($mysql, "INSERT INTO `favorites` (`user_id`,`assembly_id`) VALUES(?,?)", "ii", $userId, $idA);
        $stmt->execute();
    }
    csrf_rotate();
    header('Location: /profile.php');
    exit();
}

if (isset($_POST['buy'])) {
    if (!isset($_GET['check-saved']) && $isLoggedIn) {
        $stmt = db_prepare($mysql, "SELECT orders.assembly_id FROM orders WHERE orders.assembly_id = ? AND orders.user_id = ?", "ii", $idA, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_array();

        if (!isset($row[0])) {
            // Используем AUTO_INCREMENT вместо MAX()+1
            $stmt = db_prepare($mysql, "INSERT INTO `orders` (`user_id`,`assembly_id`,`status`) VALUES(?,?,?)", "iis", $userId, $idA, 'Обрабатывается');
            $stmt->execute();
        }
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }
}
?>
<?php
$pageTitle = 'Сборка ПК';
$extraCss = ['/assets/css/configurator.css'];
$extraJs  = [];
require __DIR__ . '/partials/header.php';
?>
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
                            <p><?= escape($cpu['component_name'] ?? '') ?></p>
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
                            <p><?= escape($motherboard['component_name'] ?? '') ?></p>
                        </div>
                    </div>

                    <?php if ($assemb['gpu_id']): ?>
                        <div class="kp">
                            <div class="img_kp"><img src="assets/images/cfg-icons/configurator-3.png"></div>
                            <div class="text_kp">
                                <h3>Видеокарта</h3>
                                <p>Видеокарта – это устройство отвечающее за поддержку и быстродействие игрового процесса.
                                    Основой видеокарты есть графический чип, чем выше мощность тем лучше.</p>
                            </div>
                            <div class="arr_kp">
                                <p><?= escape($gpu['component_name'] ?? '') ?></p>
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
                            <p><?= escape($ram['component_name'] ?? '') ?></p>
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
                            <p><?= escape($power_supply['component_name'] ?? '') ?></p>
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
                            <p><?= escape($case['component_name'] ?? '') ?></p>
                        </div>
                    </div>

                    <div class="kp">
                        <div class="img_kp"><img src="assets/images/cfg-icons/configurator-7.png"></div>
                        <div class="text_kp">
                            <h3>Кулер</h3>
                            <p>Кулер – радиатор с прикреплёном вентилятором предназначенный для охлаждения процессора.
                                Показатель теплоотвода (TDP) кулера не должен быть меньше показателя тепловыделения
                                (TDP) процессора.</p>
                        </div>
                        <div class="arr_kp">
                            <p><?= escape($cooler['component_name'] ?? '') ?></p>
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
                            <p><?= escape($ssd['component_name'] ?? '') ?></p>
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
                                <p><?= escape($ssd2['component_name'] ?? '') ?></p>
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
                                <p><?= escape($hdd['component_name'] ?? '') ?></p>
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
                                <p><?= escape($dvd['component_name'] ?? '') ?></p>
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
                                <p><?= escape($assemb['os'] ?? '') ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>


                <div class="about_assembly">
                    <div class="dv">
                        <h1>
                            <?php if ($idA <= 3) {
                                echo escape($assemb['assembly_name']);
                            } else {
                                echo "Сборка " . escape($assemb['assembly_name']);
                            }
                            ?>
                        </h1>
                    </div>
                    <div class="assemblyInf">
                        <p>Стоимость - <font style="color:rgb(200, 11, 11);font-weight: bold;;">
                                <?= escape($assemb['assembly_price']) ?>руб.</font>
                        </p>
                        <p>Номер сборки - <?= escape($assemb['assembly_id']) ?></p>
                    </div>
                    <div class="assembly_img"><img class="caseImg" src="<?= escape($case['image'] ?? '') ?>" alt="нет изображения">
                    </div>
                    <?php if (!$isLoggedIn): ?>
                        <form>
                            <div class="dv"><input name="save" type="submit" value="Сохранить" disabled></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить" disabled></div>
                        </form>
                    <?php elseif (isset($_GET['check-purchased'])): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <div class="dv"><input name="save" type="submit" value="Сохранить"></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить" disabled></div>
                        </form>
                    <?php elseif (isset($_GET['check-saved'])): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <div class="dv"><input name="save" type="submit" value="Сохранить" disabled></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить"></div>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <div class="dv"><input name="save" type="submit" value="Сохранить"></div>
                            <div class="dv"><input name="buy" type="submit" value="Купить"></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php $mysql->close(); ?>
