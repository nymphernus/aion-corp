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
    // 3.6.3-c-2: бюджет на железо, приоритет распределения и выбор ОС.
    // ОС добавляется к цене сверх бюджета, сам configure() это учитывает.
    // preference проверяем на строку: в массив приведённое значение дало бы
    // предупреждение при приведении к строке, а не тихий откат на universal.
    configure(
        (int)$_POST['price'],
        (isset($_POST['preference']) && is_string($_POST['preference'])) ? $_POST['preference'] : 'universal',
        (isset($_POST['choice_os']) && is_string($_POST['choice_os'])) ? $_POST['choice_os'] : 'none'
    );
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
require_once 'modules/components.php';
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

// 5-c: справочник сокетов из таблицы sockets, один раз на страницу. Раньше
// имена сокетов были захардкожены в конфигураторе тремя значениями, а в
// базе их семь, включая LGA1851 и AM5.
$socketTypes = socket_types($mysql);

// Компоненты тянутся целиком, а не по два поля: витрине нужны specs,
// description и manufacturer, и любая новая колонка после этого доступна без
// правки запроса. Выборка по первичному ключу, так что SELECT * здесь дёшев.
// component_by_id() сама вернёт null на пустой или нулевой id, поэтому
// проверки на непустоту не нужны.
$cpu          = component_by_id($mysql, $assemb['cpu_id']);
$motherboard  = component_by_id($mysql, $assemb['motherboard_id']);
$ram          = component_by_id($mysql, $assemb['ram_id']);
$case         = component_by_id($mysql, $assemb['case_id']);
$cooler       = component_by_id($mysql, $assemb['cooler_id']);
$power_supply = component_by_id($mysql, $assemb['power_supply_id']);
$ssd          = component_by_id($mysql, $assemb['ssd_id']);
$gpu          = component_by_id($mysql, $assemb['gpu_id']);

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

if ($gpu) {
    $compId[2] = $gpu['component_id'];
}

// второй накопитель, жёсткий диск и привод есть не в каждой сборке
$ssd2 = component_by_id($mysql, $assemb['ssd_2_id']);
$hdd  = component_by_id($mysql, $assemb['hdd_id']);
$dvd  = component_by_id($mysql, $assemb['dvd_id']);

if ($ssd2) {
    $compId[9] = $ssd2['component_id'];
}

if ($hdd) {
    $compId[10] = $hdd['component_id'];
}

if ($dvd) {
    $compId[11] = $dvd['component_id'];
}

setcookie('arrId', serialize($compId), time() + 3600);
// TODO: убрать после Stage 3.5, если не понадобится (save/buy больше не читают arrId).

// 5-c: блок компонента одинаков для всех карточек - название, короткая
// строка и раскрывающиеся подробности. Двенадцать копий разметки
// разъехались бы при первой же правке, поэтому рисуется один раз здесь.
$renderComponent = static function (?array $component, array $socketTypes): void {
    if (!$component) {
        return;
    }

    $pairs = component_specs($component, $socketTypes);
    $brief = component_brief($component, $pairs);
    $description = trim((string) ($component['description'] ?? ''));

    if ($brief === '' && $description === '' && !$pairs) {
        // показывать нечего: ни описания, ни характеристик
        return;
    }
    ?>
    <div class="kp-component">
        <span class="kp-component__name"><?= escape($component['component_name'] ?? '') ?></span>
        <?php if ($brief !== ''): ?>
            <div class="kp-component__brief"><?= escape($brief) ?></div>
        <?php endif; ?>
        <?php if ($description !== '' || $pairs): ?>
            <details class="kp-component__details">
                <summary>Подробнее</summary>
                <div class="kp-component__full">
                    <?php if ($description !== ''): ?>
                        <p><?= escape($description) ?></p>
                    <?php endif; ?>
                    <?php if ($pairs): ?>
                        <dl class="kp-specs">
                            <?php foreach ($pairs as $pair): ?>
                                <dt><?= escape($pair[0]) ?></dt>
                                <dd><?= escape($pair[1]) ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
    <?php
};

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
                        <?php $renderComponent($cpu, $socketTypes); ?>
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
                        <?php $renderComponent($motherboard, $socketTypes); ?>
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
                            <?php $renderComponent($gpu, $socketTypes); ?>
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
                        <?php $renderComponent($ram, $socketTypes); ?>
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
                        <?php $renderComponent($power_supply, $socketTypes); ?>
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
                        <?php $renderComponent($case, $socketTypes); ?>
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
                        <?php $renderComponent($cooler, $socketTypes); ?>
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
                        <?php $renderComponent($ssd, $socketTypes); ?>
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
                            <?php $renderComponent($ssd2, $socketTypes); ?>
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
                            <?php $renderComponent($hdd, $socketTypes); ?>
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
                            <?php $renderComponent($dvd, $socketTypes); ?>
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
