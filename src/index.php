<?php
$pageTitle = 'Интернет-магазин персональных компьютеров индивидуальной комплектации AION CORP.';
$extraCss = [];
$extraJs = ['/assets/js/slider.js', '/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';

// 3.6.3-b-2: карточки сборок наполняются из таблицы assembly.
// До этого три карточки были вписаны в разметку руками, вместе с ценой,
// фотографией корпуса и заголовком, и стоили в базе 30000, 105000 и
// 340000 - расхождение с базой было возможно в любую сторону и ничем не
// проверялось.
//
// Только базовые сборки 1-3: у них в базе есть осмысленные имена, а
// пользовательские сборки конфигуратора появляются позже и на главной не
// выводятся (см. соглашение о префиксе «Сборка » в assembly.php).
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
        WHERE a.assembly_id IN (1, 2, 3)
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
 * 3.6.3-b-3: контурные иконки в духе Feather, viewBox 24 и отрисовка
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

// 5-f-2: контакты и снимок карты для секции внизу страницы. Читаются
// одним запросом вместе с остальными настройками и кэшируются в static.
$homeSettings = site_settings($mysqlHome);

$contactPhone     = site_setting($homeSettings, 'contact_phone');
$contactEmail     = site_setting($homeSettings, 'contact_email');
$contactVk        = site_setting($homeSettings, 'contact_vk');
$contactTelegram  = site_setting($homeSettings, 'contact_telegram');
$contactWhatsapp  = site_setting($homeSettings, 'contact_whatsapp');
$mapSnapshot      = site_setting($homeSettings, 'map_snapshot_url');
$mapAddress       = site_setting($homeSettings, 'map_address_text');
$mapLat           = site_setting($homeSettings, 'map_lat', '55.7558');
$mapLng           = site_setting($homeSettings, 'map_lng', '37.6173');
$mapZoom          = site_setting($homeSettings, 'map_zoom', '15');
?>
            <div id="main__container">
                <div class="slider">
                <div class="slide"><img src="assets/images/main_1.webp" alt="1"></div>
                <div class="slide"><img src="assets/images/main_2.webp" alt="2"></div>
                <div class="slide"><img src="assets/images/main_3.webp" alt="3"></div>
                <div class="slide"><img src="assets/images/main_4.webp" alt="4"></div>
                </div>
                <!-- 3.6.3-a: было .slider__text - матовый блок фиксированного размера
                     (500x200) с position: relative и top: 50%, из-за чего он
                     уезжал вниз на половину высоты экрана. Теперь это .hero:
                     текст по центру поверх затемнения фотографии, см.
                     #main__container::after в style.css. -->
                <div class="hero">
                    <div class="hero__content">
                        <h1 class="hero__title">AION CORPORATION</h1>
                        <p class="hero__lead">Уникальные компьютеры для игр, стриминга, работы с графикой, видео и большими объёмами данных</p>
                        <a class="btn hero__button" href="#configurator">Собрать ПК</a>
                    </div>
                </div>
            </div>
            <div class="container_pc">
                <a class="anch" name="assembly"></a>
                <div class="container_select">
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
            </div>

<section class="cfg" id="configurator">
                <section class="cfg__container">
                    <header class="cfg__header">
                        <h2 class="cfg__title">Соберите свой ПК</h2>
                        <p class="cfg__subtitle">Выберите готовое решение или укажите бюджет</p>
                    </header>

                    <form method="post" action="assembly.php" class="cfg__form">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

                        <div class="cfg__presets">
                            <button type="button" class="cfg-preset" data-budget="20000" data-pref="universal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="3" width="20" height="14" rx="2"></rect>
                                    <line x1="8" y1="21" x2="16" y2="21"></line>
                                    <line x1="12" y1="17" x2="12" y2="21"></line>
                                </svg>
                                <span class="cfg-preset__name">Офис</span>
                                <span class="cfg-preset__price">от 20 000 ₽</span>
                            </button>
                            <button type="button" class="cfg-preset" data-budget="100000" data-pref="games">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <line x1="6" y1="12" x2="10" y2="12"></line>
                                    <line x1="8" y1="10" x2="8" y2="14"></line>
                                    <line x1="15" y1="13" x2="15.01" y2="13"></line>
                                    <line x1="18" y1="11" x2="18.01" y2="11"></line>
                                    <path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"></path>
                                </svg>
                                <span class="cfg-preset__name">Игры</span>
                                <span class="cfg-preset__price">от 100 000 ₽</span>
                            </button>
                            <button type="button" class="cfg-preset" data-budget="250000" data-pref="work">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <line x1="18" y1="20" x2="18" y2="10"></line>
                                    <line x1="12" y1="20" x2="12" y2="4"></line>
                                    <line x1="6" y1="20" x2="6" y2="14"></line>
                                </svg>
                                <span class="cfg-preset__name">Работа</span>
                                <span class="cfg-preset__price">от 250 000 ₽</span>
                            </button>
                            <button type="button" class="cfg-preset" data-budget="500000" data-pref="universal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                                </svg>
                                <span class="cfg-preset__name">Максимум</span>
                                <span class="cfg-preset__price">от 500 000 ₽</span>
                            </button>
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

<div class="cfg__prefs">
                            <h3 class="cfg-prefs__title">Что важнее?</h3>
                            <div class="cfg-prefs__row">
                                <label class="cfg-pref">
                                    <input type="radio" name="preference" value="games" checked>
                                    <span class="cfg-pref__box">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <line x1="6" y1="12" x2="10" y2="12"></line>
                                            <line x1="8" y1="10" x2="8" y2="14"></line>
                                            <line x1="15" y1="13" x2="15.01" y2="13"></line>
                                            <line x1="18" y1="11" x2="18.01" y2="11"></line>
                                            <path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"></path>
                                        </svg>
                                        <span>Игры</span>
                                    </span>
                                </label>
                                <label class="cfg-pref">
                                    <input type="radio" name="preference" value="work">
                                    <span class="cfg-pref__box">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <line x1="18" y1="20" x2="18" y2="10"></line>
                                            <line x1="12" y1="20" x2="12" y2="4"></line>
                                            <line x1="6" y1="20" x2="6" y2="14"></line>
                                        </svg>
                                        <span>Работа</span>
                                    </span>
                                </label>
                                <label class="cfg-pref">
                                    <input type="radio" name="preference" value="universal">
                                    <span class="cfg-pref__box">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 3v18"></path>
                                            <path d="M5 7h14"></path>
                                            <path d="M5 7l-3 6a4 4 0 0 0 6 0L5 7z"></path>
                                            <path d="M19 7l-3 6a4 4 0 0 0 6 0L19 7z"></path>
                                        </svg>
                                        <span>Универсал</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="cfg__os">
                            <h3 class="cfg-os__title">Операционная система</h3>
                            <div class="cfg-os__row">
                                <label class="cfg-os-item">
                                    <input type="radio" name="choice_os" value="windows">
                                    <span class="cfg-os-item__box">Windows</span>
                                </label>
                                <label class="cfg-os-item">
                                    <input type="radio" name="choice_os" value="linux">
                                    <span class="cfg-os-item__box">Linux</span>
                                </label>
                                <label class="cfg-os-item">
                                    <input type="radio" name="choice_os" value="none" checked>
                                    <span class="cfg-os-item__box">Без ОС</span>
                                </label>
                            </div>
                        </div>
                    </form>
                </section>
            </section>


<!--
                5-f-2: контакты и карта берутся из site_settings, который
                правит админ на вкладке «Настройки сайта».

                Карта - это снимок, сделанный один раз в админке, а не живой
                iframe: посетитель не грузит ни тайлы, ни Leaflet, внешних
                запросов к OpenStreetMap при открытии главной нет вообще.
                Если снимка ещё нет, показывается заглушка: так честнее,
                чем пустой серый прямоугольник.

                Клик по снимку открывает ту же точку в OpenStreetMap.
            -->
            <div class="container_about">
                <a class="anch" name="information"></a>
                <div class="cont_shell_about">
                    <div class="about_content">
                        <h2 class="contacts__title">Свяжитесь с нами</h2>

                        <div class="contacts__list">
                            <div class="contact-item">
                                <span class="contact-item__label">Телефон</span>
<?php if ($contactPhone !== ''): ?>
                                <a class="contact-item__value" href="tel:<?= escape(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= escape($contactPhone) ?></a>
<?php endif; ?>
                            </div>
                            <div class="contact-item">
                                <span class="contact-item__label">Email</span>
<?php if ($contactEmail !== ''): ?>
                                <a class="contact-item__value" href="mailto:<?= escape($contactEmail) ?>"><?= escape($contactEmail) ?></a>
<?php endif; ?>
                            </div>
<?php if ($mapAddress !== ''): ?>
                            <div class="contact-item">
                                <span class="contact-item__label">Мы на карте</span>
                                <span class="contact-item__value"><?= escape($mapAddress) ?></span>
                            </div>
<?php endif; ?>
                        </div>

<?php
// Иконку соцсети рисуем, только если адрес непустой. Пустая ссылка это
// отсутствие иконки, а не иконка, ведущая в никуда.
$socials = array_filter([
    ['label' => 'VK',       'url' => $contactVk,       'icon' => '/assets/images/logo-vk.svg'],
    ['label' => 'Telegram', 'url' => $contactTelegram, 'icon' => '/assets/images/logo-telegram.svg'],
    ['label' => 'WhatsApp', 'url' => $contactWhatsapp, 'icon' => '/assets/images/logo-whatsapp.svg'],
], static function ($item) {
    return $item['url'] !== '';
});
?>
<?php if ($socials): ?>
                        <div class="contacts__socials">
<?php foreach ($socials as $social): ?>
                            <a class="social-icon" href="<?= escape($social['url']) ?>"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= escape($social['label']) ?>">
                                <img src="<?= escape(asset_url($social['icon'])) ?>" alt="<?= escape($social['label']) ?>">
                            </a>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                    </div>

                    <div class="about_map">
<?php if ($mapSnapshot !== ''): ?>
                        <a class="site-map-link"
                           href="https://www.openstreetmap.org/?mlat=<?= escape(urlencode($mapLat)) ?>&amp;mlon=<?= escape(urlencode($mapLng)) ?>#map=<?= escape(urlencode($mapZoom)) ?>/<?= escape(urlencode($mapLat)) ?>/<?= escape(urlencode($mapLng)) ?>"
                           target="_blank" rel="noopener noreferrer"
                           title="Открыть карту в OpenStreetMap">
                            <img class="site-map-img" src="<?= escape($mapSnapshot) ?>"
                                 alt="Мы на карте - <?= escape($mapAddress) ?>" loading="lazy">
                        </a>
<?php else: ?>
                        <div class="site-map-placeholder">Карта пока не настроена</div>
<?php endif; ?>
                    </div>
                </div>
            </div>



<?php require __DIR__ . '/partials/footer.php'; ?>
