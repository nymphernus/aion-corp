<html>
    <head>
        <meta charset="utf-8">
        <title>Интернет-магазин персональных компьютеров индивидуальной комплектации AION CORP.</title>
        <link rel="stylesheet" href="assets/css/style.css">
        <link rel="shortcut icon" href="assets/images/favicon.png" type="image/png">
        <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;700&display=swap" rel="stylesheet">
    </head>
    <body>
        <header class="header">
            <div class="header__inner">
                <a href="/"><div><img class=logo src="assets/images/logo.png" alt="logo"></div></a>
                <div class="nav">
                    <ul>
                        <li class="list">
                            <a href="#assembly">
                                <span class="text">Сборки ПК</span>
                                <span class="icon"><img src="assets/images/cog-outline.svg"></span>
                            </a>
                           
                        </li>
                        <li class="list">
                            <a href="#configurator">
                                <span class="text">Конфигуратор</span>
                                <span class="icon"><img src="assets/images/hammer-outline.svg"></span>
                            </a>
                        </li>
                        <li class="list">
                            <a href="#information">
                                <span class="text">О нас</span>
                                <span class="icon"><img src="assets/images/link-outline.svg"></span>
                            </a>
                        </li>
                        <li class="list">
                            <a href="profile.php">
                                <span class="text"><?php if(isset($_COOKIE['user']) == false):?>
                                                    Войти<?php else:?><?=$_COOKIE['user']?><?php endif;?></span>
                                <span class="icon"><img src="assets/images/person-outline.svg"></span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="wrapper">
            <div id="main__container">
                <div class="slider">
                <div class="slide"><img src="assets/images/main_1.jpg" alt="1"></div>
                <div class="slide"><img src="assets/images/main_2.jpg" alt="2"></div>
                <div class="slide"><img src="assets/images/main_3.jpg" alt="3"></div>
                <div class="slide"><img src="assets/images/main_4.jpg" alt="4"></div>
                </div>
                <div class="slider__text">
                    <h1>AION CORPORATION</h1>
                    <p>Уникальные компьютеры для игр, стриминга, работы с графикой, видео и большими объёмами данных</p>
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
                            <input class="price_input" type="number" name="price" id="price" placeholder="Ваш бюджет" value="" max="5000000">
                            <div class="check">
                                <p><input id="check1" name="choice_os" type="checkbox" value="1"><label for="check1">Предустановить ОС</label></p>
                                <p><input id="check2" name="choice_ssd" type="checkbox" value="1"><label for="check2">Добавить дополнительный SSD</label></p>
                                <p><input id="check3" name="choice_hdd" type="checkbox" value="1"><label for="check3">Добавить дополнительный HDD</label></p>
                                <p><input id="check4" name="choice_dvd" type="checkbox" value="1"><label for="check4">Добавить дисковод</label></p>
                            </div>
                            <div>
                            <?php if(isset($_COOKIE['user']) == false):?>
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
                            <a href="https://vk.com/im?media=&sel=-173280979"><img src="assets/images/logo-vk.svg"></a>
                            <a href="https://wa.me/+79614113718"><img src="assets/images/logo-whatsapp.svg"></a>
                            <a href="https://t.me/ashwatthaman"><img src="assets/images/logo-telegram.svg"></a>
                            <p>Горячая линия</p>
                            <a href="tel:+79897179356">+7 (989) 717-93-56</a>
                            <p>Почта</p>
                            <a href="mailto:aleksey.schumann@gmail.com">aleksey.schumann@gmail.com</a>
                        </div>

                    
                    </div>
                    <div class="about_map"><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d782.2128626799754!2d39.691026742637334!3d47.2530574158968!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x40e3b842e81ee769%3A0xcc371a668740b596!2z0YPQuy4g0JPQsNC60LrQtdC70Y8sIDEwINC60L7RgNC_0YPRgSAxLCDQoNC-0YHRgtC-0LIt0L3QsC3QlNC-0L3Rgywg0KDQvtGB0YLQvtCy0YHQutCw0Y8g0L7QsdC7LiwgMzQ0MDc5!5e0!3m2!1sru!2sru!4v1652767851420!5m2!1sru!2sru" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
                
                </div>
            </div>



        </div>



        <footer class="footer">
        <span>© 2022 Aion Corporation</span>
        <span>Designed by <a href="https://vk.com/aswatthaman">Aleksey Schumann</a></span>
        </footer>
        <script src="assets/js/slider.js"></script>
        <script src="assets/js/scripts.js"></script>
    </body>
</html>