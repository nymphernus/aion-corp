<?php
// Заголовок главной хранится в site_settings, а не в коде: он виден
// в заголовке вкладки, и править его из админки удобнее, чем искать
// строку в index.php.
//
// Настройки приходится читать здесь, а не пользоваться site_setting()
// строкой ниже: header.php подключается после $pageTitle и загружает
// настройки сам. Подключаем модули и заполняем $settings заранее -
// header.php увидит её и не пойдёт в баду второй раз (там ровно эта
// проверка isset на строке выше). Если БД недоступна, подставляется
// пустой массив и остаются штатные значения: страница должна отдаться
// целиком, а не белым экраном.
require_once __DIR__ . '/modules/connect.php';
require_once __DIR__ . '/modules/site.php';

$brandMysql = @connect();
$settings = ($brandMysql instanceof mysqli) ? site_settings($brandMysql) : [];

// бренд убран из заголовка. Короткий уточняющий текст без
// названия компании, а само название подставляет header.php из
// site_settings - так переименование сайта не требует правки кода.
// Строка собирается как «уточнение — site_name», иначе главная была бы
// единственной страницей без названия в заголовке.
$pageTitle = site_setting(
    $settings,
    'site_home_title',
    'Интернет-магазин персональных компьютеров индивидуальной комплектации'
);
$extraCss = [];
$extraJs = ['/assets/js/slider.js', '/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';

// карточки сборок наполняются из таблицы assembly.
// До этого три карточки были вписаны в разметку руками, вместе с ценой,
// фотографией корпуса и заголовком, и стоили в базе 30000, 105000 и
// 340000 - расхождение с базой было возможно в любую сторону и ничем не
// проверялось.
//
// Только базовые сборки: у них в базе есть осмысленные имена, а
// пользовательские сборки конфигуратора появляются позже и на главной не
// выводятся (см. соглашение о префиксе «Сборка » в assembly.php).
// Признак базовой - флаг is_base, а не номер: номер у сборки витрины
// может быть любым, и под правило «первые три» попадала бы четвёртая
// базовая сборка, созданная админом.
//
// Соединение берётся отдельно от header.php: там подключается сам
// connect.php, но соединение там не заводится.
$mysqlHome = connect();
mysqli_set_charset($mysqlHome, 'utf8');
$homeBuilds = [];
try {
    $stmtHome = db_prepare($mysqlHome, "
        SELECT a.assembly_id, a.assembly_name, a.assembly_price,
               cpu.component_name AS cpu_name,
               gpu.component_name AS gpu_name,
               ram.component_name  AS ram_name,
               ram.capacity_gb     AS ram_gb,
               ram.frequency_mhz   AS ram_mhz,
               ram.ram_type        AS ram_type,
               cs.component_name   AS case_name,
               cs.image            AS case_image
        FROM assembly a
        LEFT JOIN components cpu ON cpu.component_id = a.cpu_id
        LEFT JOIN components gpu ON gpu.component_id = a.gpu_id
        LEFT JOIN components ram ON ram.component_id = a.ram_id
        LEFT JOIN components cs  ON cs.component_id  = a.case_id
        WHERE a.is_base = 1
        ORDER BY a.assembly_id
    ");
    $stmtHome->execute();
    $resHome = $stmtHome->get_result();
    while ($rowHome = $resHome->fetch_assoc()) {
        $homeBuilds[] = $rowHome;
    }
    $stmtHome->close();
} catch (RuntimeException $e) {
    // главная не должна падать из-за карточек: логируем и показываем пустоту
    error_log('Home builds query failed: ' . $e->getMessage());
    $homeBuilds = [];
}

// Подписи к сборкам. В базе их нет, и придумывать их по названию нельзя,
// поэтому здесь то, что сборки действительно собой представляют:
// 1 - i3-10100F без видеокарты, бюджетная офисная машина;
// 2 - Ryzen 5 5600G с Radeon RX 6500 XT, бюджетный игровой комплект;
// Короткие метки под названием: по одному слову, без описания.
// Подробности всё равно раскрываются в списке комплектующих, а длинный
// текст под заголовком карточку перегружает.
$homeSubtitles = [
    1 => 'Офис',
    2 => 'Игры',
    3 => 'Про',
];

/**
 * Иконка комплектующего для карточки сборки.
 *
 * контурные иконки в духе Feather, viewBox 24 и отрисовка
 * в 16px через CSS. Раньше вместо иконок были синие точки списка - на
 * такой мелкий размер это читалось как школьный маркированный список.
 * Цвет и прозрачность задаёт CSS (currentColor + opacity), здесь только
 * форма, поэтому под общий стиль интерфейса их красить не нужно.
 */
if (!function_exists('build_card_icon')) {
    function build_card_icon(string $kind): string
    {
        $open = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

        switch ($kind) {
            case 'gpu':
                // Прямоугольник с вентилятором и двумя штрихами разъёма.
                return $open
                    . '<rect x="2" y="6" width="20" height="12" rx="2"></rect>'
                    . '<circle cx="8" cy="12" r="2"></circle>'
                    . '<path d="M14 10h6M14 14h6"></path>'
                    . '</svg>';
            case 'ram':
                // Планка памяти: рамка и ножки сверху и снизу. В спецификации
                // у этого пути был лишний закрывающий </rect> - убран, иначе
                // разметка невалидна и иконка не рисуется.
                return $open
                    . '<rect x="2" y="8" width="20" height="8" rx="1"></rect>'
                    . '<path d="M6 8V6M10 8V6M14 8V6M18 8V6M6 16v2M10 16v2M14 16v2M18 16v2"></path>'
                    . '</svg>';
            case 'cpu':
            default:
                // Процессор: корпус, ядро и ножки с четырёх сторон.
                return $open
                    . '<rect x="4" y="4" width="16" height="16" rx="2"></rect>'
                    . '<rect x="9" y="9" width="6" height="6"></rect>'
                    . '<path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"></path>'
                    . '</svg>';
        }
    }
}

// контакты и снимок карты для секции внизу страницы. Читаются
// одним запросом вместе с остальными настройками и кэшируются в static.
$homeSettings = site_settings($mysqlHome);

$contactPhone     = site_setting($homeSettings, 'contact_phone');
$contactEmail     = site_setting($homeSettings, 'contact_email');
$mapSnapshot      = site_setting($homeSettings, 'map_snapshot_url');
$mapAddress       = site_setting($homeSettings, 'map_address_text');
$mapLat           = site_setting($homeSettings, 'map_lat', '55.7558');
$mapLng           = site_setting($homeSettings, 'map_lng', '37.6173');
$mapZoom          = site_setting($homeSettings, 'map_zoom', '15');

// Иконки соцсетей берутся из таблицы social_links.
//
// Раньше здесь стоял массив из трёх элементов, собранный из ключей
// contact_vk, contact_telegram и contact_whatsapp. Чтобы добавить
// четвёртую площадку, приходилось заводить четвёртый ключ в
// site_settings и дописывать строку в коде. Теперь строки читаются из
// базы, и добавление соцсети - это запись в таблицу, а не правка PHP.
//
// Пустая ссылка и выключенная строка не выводятся: в таблице они
// остаются, чтобы админ мог их заполнить или включить обратно.
//
// Ключи ответа совпадают с тем, что было в массиве: разметка ниже
// использует $social['label'], $social['url'] и $social['icon'].
//
// Запрос идёт здесь, а не в разметке, по той же причине, что и раньше:
// при ошибке PHP в разметке страница отдавалась бы с кодом 200 и
// надписью Warning посреди секции.
$socials = [];
$stmtSocial = db_prepare(
    $mysqlHome,
    "SELECT link_name, link_url, link_icon
       FROM social_links
      WHERE is_active = 1 AND link_url != ''
      ORDER BY link_id ASC",
    ''
);
$stmtSocial->execute();
$resultSocial = $stmtSocial->get_result();
while ($row = $resultSocial->fetch_assoc()) {
    $socials[] = [
        'label' => $row['link_name'],
        'url' => $row['link_url'],
        'icon' => $row['link_icon'],
    ];
}
$resultSocial->free();

// Пресеты бюджета и операционные системы конфигуратора.
//
// Раньше четыре кнопки пресетов стояли в разметке с инлайновыми svg,
// а операционные системы - тремя radio с подписями Windows, Linux и
// «Без ОС», и цена 11000 была зашита в configurator.php. Поменять
// бюджет пресета или стоимость ОС было нельзя, не правя PHP.
//
// Выключенные строки не выводятся, но остаются в таблице: админ может
// вернуть их включёнными. Порядок - по добавлению (id), а не по
// sort_order: поля «Порядок» в админке нет.
$presets = [];
$stmtPresets = db_prepare(
    $mysqlHome,
    "SELECT preset_name, preset_budget, preset_icon
       FROM configurator_presets
      WHERE is_active = 1
      ORDER BY preset_id ASC",
    ''
);
$stmtPresets->execute();
$resultPresets = $stmtPresets->get_result();
while ($row = $resultPresets->fetch_assoc()) {
    $presets[] = $row;
}
$resultPresets->free();

$osList = [];
$stmtOs = db_prepare(
    $mysqlHome,
    "SELECT os_id, os_name, os_price
       FROM configurator_os
      WHERE is_active = 1
      ORDER BY os_id ASC",
    ''
);
$stmtOs->execute();
$resultOs = $stmtOs->get_result();
while ($row = $resultOs->fetch_assoc()) {
    $osList[] = $row;
}
$resultOs->free();

// Ни одной строки быть не должно. Без заглушки форма осталась бы с
// пустым списком и кнопкой «Подобрать», которая собрала бы сборку с
// нулевым бюджетом.
if ($presets === []) {
    $presets = [['preset_name' => 'Стандарт', 'preset_budget' => 50000, 'preset_icon' => 'monitor']];
}
if ($osList === []) {
    $osList = [['os_id' => 0, 'os_name' => 'Без ОС', 'os_price' => 0]];
}
?>
            <div id="main__container">
                <div class="slider">
                <div class="slide"><img src="assets/images/main_1.webp" alt="1"></div>
                <div class="slide"><img src="assets/images/main_2.webp" alt="2"></div>
                <div class="slide"><img src="assets/images/main_3.webp" alt="3"></div>
                <div class="slide"><img src="assets/images/main_4.webp" alt="4"></div>
                </div>
                <!-- было .slider__text - матовый блок фиксированного размера
                     (500x200) с position: relative и top: 50%, из-за чего он
                     уезжал вниз на половину высоты экрана. Теперь это .hero:
                     текст по центру поверх затемнения фотографии, см.
                     #main__container::after в style.css. -->
                <div class="hero">
                    <div class="hero__content">
                        <?php // название и описание из site_settings.
                              // $siteName и $siteDescription определены в
                              // header.php, подключённом выше, и доступны здесь
                              // как обычные переменные. ?>
                        <h1 class="hero__title"><?= escape($siteName) ?></h1>
<?php if ($siteDescription !== ''): ?>
                        <p class="hero__lead"><?= escape($siteDescription) ?></p>
<?php endif; ?>
                        <a class="btn hero__button" href="#configurator">Собрать ПК</a>
                    </div>
                </div>
            </div>
            <!-- 8: якорь блока сборок. Раньше здесь был <a class="anch" name="assembly">.
                 name - устаревший атрибут, его находят только браузер при
                 переходе по адресу, и ни getElementById, ни
                 scrollIntoView. Из-за этого ссылка «Сборки ПК» работала
                 лишь как обычный переход, а плавный скролл без смены
                 URL был невозможен. Теперь id на самом блоке, и
                 scroll-margin-top (style.css) опускает его под шапку. -->
            <?php // Блок сборок скрывается целиком, если базовых сборок нет:
                  // пустая секция с заголовком «Сборки ПК» выглядела бы
                  // как сбой вывода, а не как отсутствие товара. ?>
            <?php if (!empty($homeBuilds)): ?>
            <div class="container_pc" id="assembly">
                <div class="builds-slider-container<?= count($homeBuilds) > 3 ? ' is-slider' : '' ?>">
                    <button type="button" class="builds-slider__nav builds-slider__nav--prev"
                            data-action="scroll-builds" data-direction="-1"
                            aria-label="Предыдущая сборка"
                            hidden>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>

                    <div class="container_select builds-slider" id="buildsSlider"
                         data-count="<?= count($homeBuilds) ?>">
                        <?php foreach ($homeBuilds as $homeBuild): ?>
                            <?php
                            // Строка памяти собирается из колонок, а не из названия:
                            // в названиях лежит «4gbx2», из которого объём и тип
                            // не прочитать. Если колонки пусты, показываем название
                            // как есть.
                            $homeRamLine = '';
                            if (!empty($homeBuild['ram_gb'])) {
                                $homeRamLine = (int) $homeBuild['ram_gb'] . ' ГБ';
                                if (!empty($homeBuild['ram_type'])) {
                                    $homeRamLine .= ' ' . $homeBuild['ram_type'];
                                    if (!empty($homeBuild['ram_mhz'])) {
                                        $homeRamLine .= '-' . (int) $homeBuild['ram_mhz'];
                                    }
                                }
                            } elseif (!empty($homeBuild['ram_name'])) {
                                $homeRamLine = $homeBuild['ram_name'];
                            }

                            // Строки комплектующих с типом, по типу подбирается
                            // иконка. Строка выводится только если комплектующее
                            // действительно стоит в сборке: у сборки 1 дискретной
                            // видеокарты нет.
                            $homeSpecLines = [];
                            if (!empty($homeBuild['cpu_name'])) {
                                $homeSpecLines[] = ['cpu', $homeBuild['cpu_name']];
                            }
                            if (!empty($homeBuild['gpu_name'])) {
                                $homeSpecLines[] = ['gpu', $homeBuild['gpu_name']];
                            }
                            if ($homeRamLine !== '') {
                                $homeSpecLines[] = ['ram', $homeRamLine];
                            }

                            $homeId = (int) $homeBuild['assembly_id'];
                            ?>
                            <a class="build" href="/assembly.php?init=<?= $homeId ?>">
                                <span class="build__accent"></span>
                                <div class="build__image">
                                    <?php if (!empty($homeSubtitles[$homeId])): ?>
                                        <span class="build__tag"><?= escape($homeSubtitles[$homeId]) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($homeBuild['case_image'])): ?>
                                        <img src="<?= escape($homeBuild['case_image']) ?>"
                                             alt="<?= escape($homeBuild['assembly_name']) ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="build__body">
                                    <h3 class="build__title"><?= escape($homeBuild['assembly_name']) ?></h3>
                                    <ul class="build__specs">
                                        <?php foreach ($homeSpecLines as [$homeSpecKind, $homeSpecText]): ?>
                                            <li>
                                                <?= build_card_icon($homeSpecKind) ?>
                                                <span><?= escape($homeSpecText) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="build__bottom">
                                        <span class="build__price"><?= number_format((int) $homeBuild['assembly_price'], 0, ',', ' ') ?>&nbsp;&#8381;</span>
                                        <span class="build__cta">&#8594;</span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="builds-slider__nav builds-slider__nav--next"
                            data-action="scroll-builds" data-direction="1"
                            aria-label="Следующая сборка"
                            hidden>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endif; ?>

<section class="cfg" id="configurator">
                <section class="cfg__container">
                    <header class="cfg__header">
                        <h2 class="cfg__title">Соберите свой ПК</h2>
                        <p class="cfg__subtitle">Выберите готовое решение или укажите бюджет</p>
                    </header>

                    <form method="post" action="assembly.php" class="cfg__form">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

                        <div class="cfg__presets">
    <?php foreach ($presets as $preset): ?>
                            <button type="button" class="cfg-preset"
                                    data-budget="<?= (int) $preset['preset_budget'] ?>">
                                <?= render_preset_icon((string) $preset['preset_icon'], 28) ?>
                                <span class="cfg-preset__name"><?= escape((string) $preset['preset_name']) ?></span>
                                <span class="cfg-preset__price">от <?= number_format((int) $preset['preset_budget'], 0, '.', ' ') ?> ₽</span>
                            </button>
    <?php endforeach; ?>
                        </div>

                        <div class="cfg__divider">
                            <span>или укажите точный бюджет</span>
                        </div>

                        <div class="cfg__budget">
                            <div class="cfg-budget">
                                <input type="number"
                                       name="price"
                                       id="cfgPrice"
                                       class="cfg-budget__input"
                                       placeholder="50 000"
                                       min="10000"
                                       max="10000000"
                                       step="1000"
                                       required>
                                <span class="cfg-budget__currency">&#8381;</span>
                            </div>
                            <?php if (empty($_SESSION['user_id'])): ?>
                                <button type="submit" class="cfg__submit" disabled>Подобрать &rarr;</button>
                                <p class="cfg__gate">Войдите или зарегистрируйтесь, чтобы подобрать сборку</p>
                            <?php else: ?>
                                <button type="submit" class="cfg__submit">Подобрать &rarr;</button>
                            <?php endif; ?>
                        </div>

                        <div class="cfg__os">
                            <label class="cfg-os__title" for="cfgOs">Операционная система</label>
                            <!--
                                Список вместо трёх radio. Радиокнопки требовали
                                правки и в разметке, и в CSS, и в обработчике
                                при каждой новой ОС, а подписи в них были
                                короче названий из базы («Windows» против
                                «Windows 10 Home»).

                                value - os_id, а не название и не строка:
                                configure() перечитывает строку из базы и
                                берёт цену оттуда. Строка в POST
                                означала бы, что цену можно подделать
                                прямо из формы.
                            -->
                            <select class="cfg-os__select" name="os_id" id="cfgOs">
    <?php foreach ($osList as $cfgOsRow): ?>
                                <option value="<?= (int) $cfgOsRow['os_id'] ?>">
                                    <?= escape((string) $cfgOsRow['os_name']) ?>
                                    <?php if ((int) $cfgOsRow['os_price'] > 0): ?>
                                        (+<?= number_format((int) $cfgOsRow['os_price'], 0, '.', ' ') ?> ₽)
                                    <?php endif; ?>
                                </option>
    <?php endforeach; ?>
                            </select>
                            <p class="cfg-os__hint">
                                Стоимость ОС добавляется к цене сборки сверх бюджета.
                            </p>
                        </div>
                    </form>
                </section>
            </section>


<!--
                контакты и карта берутся из site_settings, который
                правит админ на вкладке «Настройки сайта».

                Карта - это снимок, сделанный один раз в админке, а не живой
                iframe: посетитель не грузит ни тайлы, ни Leaflet, внешних
                запросов к OpenStreetMap при открытии главной нет вообще.
                Если снимка ещё нет, показывается заглушка: так честнее,
                чем пустой серый прямоугольник.

                Клик по снимку открывает ту же точку в OpenStreetMap.
            -->
<!--
                секция контактов. Разметка переписана целиком.

                Прежняя опиралась на .container_about с width:100vw,
                height:75vh и float внутри - блок не мог расти по высоте,
                карта висела на .about_map с розовым градиентом и
                анимацией. Здесь обычная сетка: две колонки, высота по
                содержимому.

                Данные те же, что и выше: контакты и снимок карты из
                site_settings, который правит админка.
            -->
            <section class="contacts" id="contacts">
                <div class="contacts__container">
                    <div class="contacts__info">
                        <h2 class="page-title">Свяжитесь с нами</h2>

                        <div class="contact-item">
                            <div class="contact-item__label">Телефон</div>
<?php if ($contactPhone !== ''): ?>
                            <a href="tel:<?= escape(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"
                               class="contact-item__value"><?= escape($contactPhone) ?></a>
<?php endif; ?>
                        </div>

                        <div class="contact-item">
                            <div class="contact-item__label">Email</div>
<?php if ($contactEmail !== ''): ?>
                            <a href="mailto:<?= escape($contactEmail) ?>"
                               class="contact-item__value"><?= escape($contactEmail) ?></a>
<?php endif; ?>
                        </div>

<?php if ($mapAddress !== ''): ?>
                        <div class="contact-item">
                            <div class="contact-item__label">Мы на карте</div>
                            <div class="contact-item__value"><?= escape($mapAddress) ?></div>
                        </div>
<?php endif; ?>

<?php // Пустая ссылка и выключенная строка не попадают в выборку на
      // сервере, поэтому здесь не нужен ни array_filter, ни проверка
      // внутри цикла. Пустой $socials означает, что блока не будет. ?>
<?php if ($socials): ?>
                        <div class="contacts__socials">
<?php foreach ($socials as $social): ?>
                            <a href="<?= escape($social['url']) ?>" target="_blank"
                               rel="noopener noreferrer" class="social-icon"
                               title="<?= escape($social['label']) ?>">
                                <img src="<?= escape(asset_url($social['icon'])) ?>"
                                     alt="<?= escape($social['label']) ?>">
                            </a>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                    </div>

                    <div class="contacts__map">
<?php if ($mapSnapshot !== ''): ?>
                        <a href="https://www.openstreetmap.org/?mlat=<?= escape(urlencode($mapLat)) ?>&amp;mlon=<?= escape(urlencode($mapLng)) ?>#map=<?= escape(urlencode($mapZoom)) ?>/<?= escape(urlencode($mapLat)) ?>/<?= escape(urlencode($mapLng)) ?>"
                           target="_blank" rel="noopener noreferrer" class="map-link"
                           title="Открыть карту в OpenStreetMap">
                            <img src="<?= escape($mapSnapshot) ?>" loading="lazy"
                                 alt="Мы на карте - <?= escape($mapAddress) ?>">
                        </a>
<?php else: ?>
                        <div class="map-placeholder">Карта не настроена</div>
<?php endif; ?>
                    </div>
                </div>
            </section>



<?php require __DIR__ . '/partials/footer.php'; ?>
