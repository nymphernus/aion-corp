<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
require_once 'modules/configurator.php';
require_once __DIR__ . '/modules/connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if (isset($_POST['price'])) {
    // Вторым аргументом идёт os_id, а не название: цену и название
    // configure() перечитывает из configurator_os сам. Строка в форме
    // означала бы, что стоимость ОС можно подделать прямо из формы.
    configure(
        (int)$_POST['price'],
        (int)($_POST['os_id'] ?? 0)
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

$checkStmt = db_prepare($mysql, "SELECT assembly_id FROM assembly WHERE assembly_id = ?", "i", $idA);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows === 0) {
    http_response_code(404);
    header('Location: /assembly.php');
    exit();
}

$stmt = db_prepare($mysql, "SELECT * FROM assembly WHERE assembly_id = ?", "i", $idA);
$stmt->execute();
$assemb = $stmt->get_result()->fetch_assoc();

// Справочник сокетов берётся один раз на страницу: девять карточек
// компонентов используют его для подписей.
$socketTypes = socket_types($mysql);

// Компоненты тянутся целиком: витрине нужны specs, description и
// manufacturer, и любая новая колонка после этого доступна без правки
// запроса. Выборка по первичному ключу, так что SELECT * здесь дёшев.
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

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;

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
            // Что занимаем: остатки компонентов сборки. Проверка идёт
            // после проверки «уже куплен», иначе повторное нажатие
            // «Купить» списывало бы товар второй раз.
            $demand = assembly_demand($assemb);

            $shortage = stock_shortage($mysql, $demand);
            if ($shortage !== null) {
                // Молчаливый отказ хуже явного: покупатель должен
                // знать, что товара нет.
                csrf_rotate();
                header('Location: /assembly.php?id=' . $idA . '&error=out_of_stock&part=' . $shortage[0]);
                exit();
            }

            $mysql->begin_transaction();
            try {
                $stmt = db_prepare($mysql, "INSERT INTO `orders` (`user_id`,`assembly_id`,`status`) VALUES(?,?,?)", "iis", $userId, $idA, 'Обрабатывается');
                $stmt->execute();

                stock_apply($mysql, $demand, -1);

                $mysql->commit();
            } catch (StockShortage $e) {
                // Товар закончился между проверкой и списанием: другой
                // покупатель забрал его раньше. Откат убирает и заказ,
                // и списание, поэтому следов не остаётся.
                $mysql->rollback();
                error_log('Buy out of stock: ' . $e->getMessage());
                csrf_rotate();
                header('Location: /assembly.php?id=' . $idA . '&error=out_of_stock');
                exit();
            } catch (Throwable $e) {
                // Откат обязателен: заказ без списанных остатков - это
                // проданный в минус товар, остаток в каталоге есть, а
                // товара нет.
                $mysql->rollback();
                error_log('Buy failed: ' . $e->getMessage());
                csrf_rotate();
                header('Location: /assembly.php?id=' . $idA . '&error=buy_failed');
                exit();
            }
        }
        csrf_rotate();
        header('Location: /profile.php');
        exit();
    }
}

// Отказ покупки показывается на этой же странице: код в адресе,
// текст из карты. Название товара приходит отдельным числом и читается
// из базы - подставлять его в адрес нельзя.
$assemblyErrors = [
    'out_of_stock' => 'Комплектующие закончились. Напишите администратору - он пополнит остатки.',
    'buy_failed'   => 'Не удалось оформить заказ. Попробуйте ещё раз.',
];

$assemblyError = null;
$errorKey = (string) ($_GET['error'] ?? '');
if (isset($assemblyErrors[$errorKey])) {
    $assemblyError = $assemblyErrors[$errorKey];

    $partId = (int) ($_GET['part'] ?? 0);
    if ($partId > 0) {
        $part = component_by_id($mysql, $partId);
        if ($part !== null) {
            $assemblyError = 'Компонент «' . $part['component_name'] . '» закончился. '
                . 'Напишите администратору - он пополнит остатки.';
        }
    }
}
?>
<?php
$pageTitle = 'Сборка ПК';
$extraCss = ['/assets/css/configurator.css'];
$extraJs  = [];
require __DIR__ . '/partials/header.php';
?>
<?php if ($assemblyError !== null): ?>
            <div class="alert alert--error"><?= escape($assemblyError) ?></div>
<?php endif; ?>
<div class="build-layout">

            <div class="build-components">
<?php
// Карточки компонентов собираются списком: компонента может не быть,
// и тогда карточка просто не выводится. Второй элемент - ключ иконки
// из modules/icons.php, а не файл: по файлам карточки памяти и SSD
// ссылались на configurator-4, одна картинка на два компонента.
$buildCards = [
    ['Процессор', 'cpu', $cpu],
    ['Материнская плата', 'mb', $motherboard],
    ['Видеокарта', 'gpu', $gpu],
    ['Оперативная память', 'ram', $ram],
    ['Блок питания', 'psu', $power_supply],
    ['Корпус', 'case', $case],
    ['Кулер', 'cooler', $cooler],
    ['Накопитель SSD', 'ssd', $ssd],
    ['Накопитель SSD 2', 'ssd', $ssd2],
    ['Жёсткий диск', 'hdd', $hdd],
];

foreach ($buildCards as $buildCard) {
    [$label, $icon, $component] = $buildCard;
    if (!$component) {
        continue;
    }

    $pairs = component_specs($component, $socketTypes);
    $brief = component_brief($component, $pairs);
    $description = trim((string) ($component['description'] ?? ''));
    // Ключ незнакомый - иконки не будет, и вместо битого src нужна
    // заглушка. 28px, а не 36: duotone в 48x48 держит детали и в 24px,
    // но в 36 иконка занимала бы две трети рамки 56x56 и читалась как
    // картинка ради картинки. 28 - верхняя граница, на которой подложка
    // и сплошная деталь ещё различимы.
    $iconSvg = icon($icon, 28, 'comp-card__icon-svg');
    ?>
                <div class="comp-card">
                    <div class="comp-card__icon">
<?= $iconSvg !== '' ? $iconSvg : '<span class="comp-card__icon-missing"></span>' ?>
                    </div>
                    <div class="comp-card__body">
                        <div class="comp-card__category"><?= escape($label) ?></div>
                        <div class="comp-card__name"><?= escape($component['component_name'] ?? '') ?></div>
                        <?php if ($brief !== ''): ?>
                            <div class="comp-card__brief"><?= escape($brief) ?></div>
                        <?php endif; ?>
                        <?php if ($description !== '' || $pairs): ?>
                            <details class="comp-card__details">
                                <summary>Подробнее</summary>
                                <div class="comp-card__full">
                                    <?php if ($description !== ''): ?>
                                        <p class="comp-card__description"><?= escape($description) ?></p>
                                    <?php endif; ?>
                                    <?php if ($pairs): ?>
                                        <dl class="comp-specs">
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
                </div>
    <?php
}

// Операционная система - не компонент, строки в components у неё нет,
// поэтому карточка своя. Надбавку за неё уже добавил конфигуратор.
if (!empty($assemb['os'])) {
    ?>
                <div class="comp-card">
                    <div class="comp-card__icon">
<?= icon('os', 28, 'comp-card__icon-svg') ?>
                    </div>
                    <div class="comp-card__body">
                        <div class="comp-card__category">Операционная система</div>
                        <div class="comp-card__name"><?= escape($assemb['os']) ?></div>
                    </div>
                </div>
    <?php
}
?>
            </div>

            <aside class="build-summary">
                <div class="build-summary__image">
                    <img src="<?= escape($case['image'] ?? '') ?>" alt="">
                </div>

                <h1 class="build-summary__title">
                    <?php // признак базовой сборки - флаг is_base, а не номер:
                          // номер у сборки витрины может быть любым.
                          if ((int) ($assemb['is_base'] ?? 0) === 1) {
                        echo escape($assemb['assembly_name']);
                    } else {
                        echo 'Сборка ' . escape($assemb['assembly_name']);
                    } ?>
                </h1>
                <div class="build-summary__number">
                    Сборка №<?= (int)$assemb['assembly_id'] ?>
                </div>

                <div class="build-summary__price">
                    <?= number_format($assemb['assembly_price'], 0, '.', ' ') ?> ₽
                </div>

                <div class="build-summary__actions">
                    <?php if (!$isLoggedIn): ?>
                        <button type="button" class="btn btn--primary" disabled>Сохранить</button>
                        <button type="button" class="btn btn--primary" disabled>Купить</button>
                        <p class="build-summary__hint">Войдите, чтобы сохранить или купить</p>
                    <?php elseif (isset($_GET['check-purchased'])): ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                            <button type="submit" name="save" class="btn btn--secondary">Сохранить</button>
                            <button type="submit" name="buy" class="btn btn--primary" disabled>Купить</button>
                        </form>
                    <?php elseif (isset($_GET['check-saved'])): ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                            <button type="submit" name="save" class="btn btn--secondary" disabled>Сохранить</button>
                            <button type="submit" name="buy" class="btn btn--primary">Купить</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                            <button type="submit" name="save" class="btn btn--secondary">Сохранить</button>
                            <button type="submit" name="buy" class="btn btn--primary">Купить</button>
                        </form>
                    <?php endif; ?>
                </div>
            </aside>

        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php $mysql->close(); ?>
