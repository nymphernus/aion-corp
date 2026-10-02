<?php
$pageTitle = 'Интернет-магазин персональных компьютеров индивидуальной комплектации AION CORP.';
$extraCss = [];
$extraJs = ['/assets/js/slider.js', '/assets/js/scripts.js'];
require __DIR__ . '/partials/header.php';
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
                        <a class="btn btn--primary hero__cta" href="#configurator">Собрать ПК</a>
                    </div>
                </div>
            </div>
            <div class="container_pc">
                <a class="anch" name="assembly"></a>
                <div class="cont_shell cont_shell_back">
                    <div class="container_select">
                        <div class="element_select">
                            <a href="assembly.php?init=1">
                                <span class="select_image">
                                    <div class="cont_img"><img src="assets/images/img_select_1.png"></div>
                                    <div class="figure_par"></div>
                                    <div class="cont_text"><h1>EinTech</h1><p>30 000 руб.</p></div>
                                </span>
                            </a>
                        </div>
                        <div class="element_select">
                            <a href="assembly.php?init=2">
                                    <span class="select_image">
                                        <div class="cont_img"><img src="assets/images/img_select_2.png"></div>
                                        <div class="figure_par"></div>
                                        <div class="cont_text"><h1>Eternal</h1><p>105 000 руб.</p></div>
                                    </span>
                            </a>
                        </div>
                        <div class="element_select">
                            <a href="assembly.php?init=3">
                                    <span class="select_image">
                                        <div class="cont_img"><img src="assets/images/img_select_3.png"></div>
                                        <div class="figure_par"></div>
                                        <div class="cont_text"><h1>Magic Workbench</h1><p>340 000 руб.</p></div>
                                    </span>
                            </a>
                        </div>
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