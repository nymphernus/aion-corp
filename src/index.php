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
        $open = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

        switch ($kind) {
            case 'gpu':
                // Плата с вентилятором и разъёмом: прямоугольник, круг, два штриха.
                return $open
                    . '<rect x="2" y="5" width="20" height="13" rx="2"/>'
                    . '<circle cx="11" cy="11.5" r="3.5"/>'
                    . '<path d="M18 9h2.5"/><path d="M18 14h2.5"/>'
                    . '</svg>';
            case 'ram':
                // Планка памяти: общая рамка и три чипа на ней.
                return $open
                    . '<rect x="2" y="8" width="20" height="8" rx="1"/>'
                    . '<rect x="5.5" y="10.5" width="3" height="3" rx="0.4"/>'
                    . '<rect x="10.5" y="10.5" width="3" height="3" rx="0.4"/>'
                    . '<rect x="15.5" y="10.5" width="3" height="3" rx="0.4"/>'
                    . '</svg>';
            case 'cpu':
            default:
                // Процессор: корпус, ядро и ножки с четырёх сторон.
                return $open
                    . '<rect x="5" y="5" width="14" height="14" rx="2"/>'
                    . '<rect x="9" y="9" width="6" height="6" rx="0.6"/>'
                    . '<path d="M9 5V2"/><path d="M15 5V2"/>'
                    . '<path d="M9 22v-3"/><path d="M15 22v-3"/>'
                    . '<path d="M5 9H2"/><path d="M5 15H2"/>'
                    . '<path d="M22 9h-3"/><path d="M22 15h-3"/>'
                    . '</svg>';
        }
    }
}
?>
            <div id="main__container">
                <div class="slider">
                <div class="slide"><img src="assets/images/main_1.jpg" alt="1"></div>
                <div class="slide"><img src="assets/images/main_2.jpg" alt="2"></div>
                <div class="slide"><img src="assets/images/main_3.jpg" alt="3"></div>
                <div class="slide"><img src="assets/images/main_4.jpg" alt="4"></div>
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
                <div class="cont_shell cont_shell_back">
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
                            <div class="element_select">
                                <a class="build-card" href="/assembly.php?init=<?= $homeId ?>">
                                    <span class="build-card__image">
                                        <?php if (!empty($homeBuild['case_image'])): ?>
                                            <img src="<?= escape($homeBuild['case_image']) ?>"
                                                 alt="<?= escape($homeBuild['case_name'] ?? $homeBuild['assembly_name']) ?>">
                                        <?php endif; ?>
                                    </span>
                                    <span class="build-card__body">
                                        <h3 class="build-card__title"><?= escape($homeBuild['assembly_name']) ?></h3>
                                        <?php if (!empty($homeSubtitles[$homeId])): ?>
                                            <span class="build-card__tag"><?= escape($homeSubtitles[$homeId]) ?></span>
                                        <?php endif; ?>
                                        <span class="build-card__specs">
                                            <?php foreach ($homeSpecLines as [$homeSpecKind, $homeSpecText]): ?>
                                                <span class="build-card__spec">
                                                    <?= build_card_icon($homeSpecKind) ?>
                                                    <span class="build-card__spec-text"><?= escape($homeSpecText) ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        </span>
                                        <span class="build-card__price"><?= number_format((int) $homeBuild['assembly_price'], 0, ',', ' ') ?>&nbsp;руб.</span>
                                    </span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="container_conf" style="background: url(assets/images/background_3.jpg) no-repeat; background-size: cover;">
                <a class="anch" name="configurator"></a>
                <div class="cont_shell" style="backdrop-filter: blur(10px); height:100%;">
                        <form id="cfg" action="assembly.php" method="post" style="transform:scale(1.1); margin-top:2%;">
                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                            <input class="price_input" type="number" name="price" id="price" placeholder="Ваш бюджет" value="" max="5000000">
                            <div class="check">
                                <p><input id="check1" name="choice_os" type="checkbox" value="1"><label for="check1">Предустановить ОС</label></p>
                                <p><input id="check2" name="choice_ssd" type="checkbox" value="1"><label for="check2">Добавить дополнительный SSD</label></p>
                                <p><input id="check3" name="choice_hdd" type="checkbox" value="1"><label for="check3">Добавить дополнительный HDD</label></p>
                                <p><input id="check4" name="choice_dvd" type="checkbox" value="1"><label for="check4">Добавить дисковод</label></p>
                            </div>
                            <div>
                            <?php if(empty($_SESSION['user_id'])):?>
                                <p style="color:red;">Вы не можете использовать конфигуратор пока не войдёте в аккаунт или не зарегистрируетесь</p><br>
                                <button class="btn_cfg" type="submit" disabled>Подобрать</button>
                            <?php else:?>
                                 <button class="btn_cfg" type="submit">Подобрать</button>
                            <?php endif;?>
                            </div>
                            
                        </form>
                </div>
            </div>



            <div class="container_about" style="background: url(assets/images/background_2.jpg) no-repeat; background-size: cover;">
                <a class="anch" name="information"></a>
                <div class="cont_shell_about">
                    <div class="about_content">
                        <div class="headline"><p>AION CORPORATION</p></div>
                        <div class="lead">
                            <p>Связь с нами</p>
                            <a href="https://github.com/nymphernus"><img src="assets/images/logo-vk.svg"></a>
                            <a href="https://github.com/nymphernus"><img src="assets/images/logo-whatsapp.svg"></a>
                            <a href="https://github.com/nymphernus"><img src="assets/images/logo-telegram.svg"></a>
                            <p>Горячая линия</p>
                            <a href="tel:+79999999999">+7 (999) 999-99-99</a>
                            <p>Почта</p>
                            <a href="mailto:mail@mail.ru">mail@mail.ru</a>
                        </div>

                    
                    </div>
                    <div class="about_map"><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d577348.4519017431!2d36.725910778778385!3d55.57995328139987!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x46b54afc73d4b0c9%3A0x3d44d6cc5757cf4c!2z0JzQvtGB0LrQstCw!5e0!3m2!1sru!2sru!4v1749035032575!5m2!1sru!2sru" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
                
                </div>
            </div>



<?php require __DIR__ . '/partials/footer.php'; ?>
