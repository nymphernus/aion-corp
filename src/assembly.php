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

// Второй накопитель и жёсткий диск есть не в каждой сборке. Привод
// не читается: категория «Привод» снята в Stage 8, и заполнить слот
// dvd_id больше нечем.
$ssd2 = component_by_id($mysql, $assemb['ssd_2_id']);
$hdd  = component_by_id($mysql, $assemb['hdd_id']);

// Признак базовой сборки - флаг is_base, а не номер: номер у сборки
// витрины может быть любым. Только пользовательским сборкам доступен
// подбор допкомпонентов.
$isBase = ((int) ($assemb['is_base'] ?? 0)) === 1;

/**
 * Компоненты категории для селекта допкомпонента.
 *
 * Текущий выбор попадает в список независимо от остатка: при amount = 0
 * он выпал бы из выборки, значение селекта сбросилось бы молча, а в
 * NOT NULL-колонку ушёл бы ноль - ровно тот случай, что закрыт
 * проверкой cfg_assembly_parts_in_category в админке.
 *
 * @return array<int, array<string, mixed>>
 */
function extra_slot_options(mysqli $mysql, int $categoryId, int $currentId): array
{
    $stmt = db_prepare(
        $mysql,
        'SELECT component_id, component_name, component_price
           FROM components
          WHERE category_id = ? AND (amount > 0 OR component_id = ?)
          ORDER BY component_price ASC, component_name ASC',
        'ii',
        $categoryId,
        $currentId
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// Списки и цены нужны только пользовательским сборкам: у базовой допы
// выбирать нечем, а запросы на странице витрины лишние.
$extraHddList = [];
$extraSsdList = [];
$extraOldPrice = ['ssd_2_id' => 0, 'hdd_id' => 0];
if (!$isBase) {
    $extraSsdList = extra_slot_options($mysql, 9, (int) ($assemb['ssd_2_id'] ?? 0));
    $extraHddList = extra_slot_options($mysql, 8, (int) ($assemb['hdd_id'] ?? 0));

    $extraOldPrice['ssd_2_id'] = (int) ($ssd2['component_price'] ?? 0);
    $extraOldPrice['hdd_id'] = (int) ($hdd['component_price'] ?? 0);
}

/**
 * Применить выбор допкомпонентов к пользовательской сборке.
 *
 * Вызывается до обработчиков save и buy, а не внутри них: $assemb
 * читает и assembly_demand(), и проверка остатков, и списание. Запись
 * в базу без обновления $assemb означала бы, что покупатель видит одну
 * цену, а склад списывает другую.
 *
 * Цена пересчитывается дельтой, а не суммой с нуля: assembly_price у
 * пользовательской сборки равна сумме восьми основных компонентов плюс
 * цена ОС. Пересчёт «из компонентов» потерял бы ОС и поднял бы цену
 * вслед за правкой прайса у магазина.
 *
 * @param array<string, mixed> $assemb изменяется на месте
 * @param array<string, mixed>|null $ssd2 изменяется на месте
 * @param array<string, mixed>|null $hdd  изменяется на месте
 */
function apply_extra_slots(mysqli $mysql, array &$assemb, ?array &$ssd2, ?array &$hdd): void
{
    global $extraOldPrice;

    $slots = ['ssd_2_id' => 9, 'hdd_id' => 8];

    $posted = false;
    foreach ($slots as $slot => $categoryId) {
        if (array_key_exists('extra_' . $slot, $_POST)) {
            $posted = true;
        }
    }
    if (!$posted) {
        return;
    }

    $selected = [];
    foreach ($slots as $slot => $categoryId) {
        $id = (int) ($_POST['extra_' . $slot] ?? 0);

        if ($id > 0) {
            $stmt = db_prepare(
                $mysql,
                'SELECT component_id, component_name, component_price
                   FROM components
                  WHERE component_id = ? AND category_id = ?',
                'ii',
                $id,
                $categoryId
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Чужой компонент отклоняем целиком, а не молча выкидываем:
            // иначе форма сохранилась бы с не тем, что человек выбрал.
            if ($row === null) {
                csrf_rotate();
                header('Location: /assembly.php?id=' . (int) $assemb['assembly_id'] . '&error=extra_part');
                exit();
            }
            $selected[$slot] = $row;
        } else {
            $selected[$slot] = null;
        }
    }

    $delta = 0;
    foreach ($slots as $slot => $categoryId) {
        $delta += (int) ($selected[$slot]['component_price'] ?? 0)
            - (int) ($extraOldPrice[$slot] ?? 0);
    }

    $newPrice = max(0, (int) $assemb['assembly_price'] + $delta);

    // NULLIF пишет настоящий NULL, а не ноль: колонки nullable, и нулевой
    // component_id в других местах читать нельзя.
    $stmt = db_prepare(
        $mysql,
        'UPDATE assembly
            SET ssd_2_id = NULLIF(?, 0), hdd_id = NULLIF(?, 0), assembly_price = ?
          WHERE assembly_id = ?',
        'iiii',
        (int) ($selected['ssd_2_id']['component_id'] ?? 0),
        (int) ($selected['hdd_id']['component_id'] ?? 0),
        $newPrice,
        (int) $assemb['assembly_id']
    );
    $stmt->execute();
    $stmt->close();

    $ssd2 = $selected['ssd_2_id'];
    $hdd = $selected['hdd_id'];

    $assemb['ssd_2_id'] = $selected['ssd_2_id'] === null
        ? null
        : (int) $selected['ssd_2_id']['component_id'];
    $assemb['hdd_id'] = $selected['hdd_id'] === null
        ? null
        : (int) $selected['hdd_id']['component_id'];
    $assemb['assembly_price'] = $newPrice;

    $extraOldPrice['ssd_2_id'] = (int) ($selected['ssd_2_id']['component_price'] ?? 0);
    $extraOldPrice['hdd_id'] = (int) ($selected['hdd_id']['component_price'] ?? 0);
}

// Запрос приходит только с форм пользовательской сборки: у базовой секции
// с выбором допов на странице нет, и пустой POST означал бы, что прислали
// руками.
if (!$isBase && $_SERVER['REQUEST_METHOD'] === 'POST') {
    apply_extra_slots($mysql, $assemb, $ssd2, $hdd);
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
    // Остаёмся на странице сборки: после «Сохранить» человек обычно
    // сразу нажимает «Купить», и уход в профиль обрывал бы это одним
    // лишним шагом назад.
    header('Location: /assembly.php?id=' . $idA . '&saved=1');
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
        // В профиль сразу на заказы: заказ только что создан, и открывать
        // страницу ради этого - лишний клик.
        header('Location: /profile.php?section=orders');
        exit();
    }
}

// Отказ покупки показывается на этой же странице: код в адресе,
// текст из карты. Название товара приходит отдельным числом и читается
// из базы - подставлять его в адрес нельзя.
$assemblyErrors = [
    'out_of_stock' => 'Комплектующие закончились. Напишите администратору - он пополнит остатки.',
    'buy_failed'   => 'Не удалось оформить заказ. Попробуйте ещё раз.',
    'extra_part'   => 'Выбранный допкомпонент не подходит к этой позиции. Обновите страницу и выберите заново.',
];

$assemblyError = null;
$assemblyOk = isset($_GET['saved']) && $_GET['saved'] === '1'
    ? 'Сборка сохранена в избранное'
    : null;
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
$extraJs  = ['/assets/js/assembly-extra.js'];

// Скрытые поля для всех трёх форм собираются один раз: селекты допов
// лежат в левой колонке, а кнопки «Сохранить» и «Купить» - в правой, и
// поле одной колонки не может оказаться внутри формы другой. JS
// синхронизирует значения перед отправкой.
$extraHidden = '';
if (!$isBase) {
    $extraHidden = '<input type="hidden" name="extra_ssd_2_id" id="extraSsd2Hidden" value="'
        . (int) ($assemb['ssd_2_id'] ?? 0) . '">'
        . '<input type="hidden" name="extra_hdd_id" id="extraHddHidden" value="'
        . (int) ($assemb['hdd_id'] ?? 0) . '">';
}

require __DIR__ . '/partials/header.php';
?>
<?php if ($assemblyError !== null): ?>
            <div class="alert alert--error"><?= escape($assemblyError) ?></div>
<?php elseif ($assemblyOk !== null): ?>
            <div class="alert alert--success"><?= escape($assemblyOk) ?></div>
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

// Допкомпоненты доступны только пользовательской сборке: у базовой витрины
// состав задан админом, и покупатель не должен его менять.
if (!$isBase):
    // База для пересчёта на клиенте: текущая цена минус нынешние допы.
    // JS складывает из неё новые допы, а сервер делает то же дельтой,
    // поэтому цифра на экране и в базе сходятся.
    $extraBasePrice = (int) $assemb['assembly_price']
        - $extraOldPrice['ssd_2_id']
        - $extraOldPrice['hdd_id'];

    // Списки целиком в атрибуте: политика безопасности запрещает инлайн-скрипты
    // без nonce, а отдельный запрос за списком означал бы второй поход в базу
    // при каждом открытии модалки. Подписи табов берём отсюда же.
    $extraSlots = [
        'ssd_2_id' => ['label' => 'Дополнительный SSD', 'items' => $extraSsdList],
        'hdd_id'   => ['label' => 'Жёсткий диск', 'items' => $extraHddList],
    ];
?>
                <section class="extra-components"
                         id="extraComponents"
                         data-base-price="<?= $extraBasePrice ?>"
                         data-slots="<?= escape((string) json_encode($extraSlots, JSON_UNESCAPED_UNICODE)) ?>">
                    <div class="extra-components__header">
                        <h2 class="extra-components__title">Дополнительные компоненты</h2>
                        <button type="button" class="btn btn--secondary btn--sm"
                                data-action="open-extra-picker">
                            + Добавить
                        </button>
                    </div>

                    <p class="extra-components__hint">
                        Цена пересчитывается сразу. Изменения применяются, когда вы
                        сохраните сборку в избранное или купите её.
                    </p>

                    <div class="extra-items" id="extraItems"></div>

                    <p class="extra-empty" id="extraEmpty">
                        Дополнительные компоненты не выбраны
                    </p>
                </section>

                <dialog id="extraPickerModal" class="modal modal--wide">
                    <div class="modal-form">
                        <h2>Добавить дополнительный компонент</h2>

                        <div class="extra-picker-tabs" id="extraPickerTabs">
                            <button type="button" class="extra-picker-tab is-active"
                                    data-action="extra-picker-tab" data-slot="ssd_2_id">
                                Дополнительный SSD
                            </button>
                            <button type="button" class="extra-picker-tab"
                                    data-action="extra-picker-tab" data-slot="hdd_id">
                                Жёсткий диск
                            </button>
                        </div>

                        <div class="extra-picker-list" id="extraPickerList"></div>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary"
                                        data-action="close-extra-picker">Закрыть</button>
                            </div>
                        </div>
                    </div>
                </dialog>
<?php endif; ?>
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

                <div class="build-summary__price" id="buildPrice">
                    <?= number_format($assemb['assembly_price'], 0, '.', ' ') ?> ₽
                </div>

                <div class="build-summary__actions">
                    <?php // Кнопки не submit, а обычные: перед отправкой показывается
                           // подтверждение, и браузер не должен уводить со страницы раньше,
                           // чем человек на неё ответит. ?>
                    <?php if (!$isLoggedIn): ?>
                        <button type="button" class="btn btn--secondary" disabled>Сохранить</button>
                        <button type="button" class="btn btn--primary" disabled>Купить</button>
                        <p class="build-summary__hint">Войдите, чтобы сохранить или купить</p>
                    <?php elseif (isset($_GET['check-purchased'])): ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><?= $extraHidden ?>
                            <button type="button" class="btn btn--secondary" data-action="confirm-save">Сохранить</button>
                            <button type="button" class="btn btn--primary" disabled>Купить</button>
                        </form>
                    <?php elseif (isset($_GET['check-saved'])): ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><?= $extraHidden ?>
                            <button type="button" class="btn btn--secondary" disabled>Сохранить</button>
                            <button type="button" class="btn btn--primary" data-action="confirm-buy">Купить</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="build-summary__form">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>"><?= $extraHidden ?>
                            <button type="button" class="btn btn--secondary" data-action="confirm-save">Сохранить</button>
                            <button type="button" class="btn btn--primary" data-action="confirm-buy">Купить</button>
                        </form>
                    <?php endif; ?>
                </div>
            </aside>

        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php $mysql->close(); ?>
