<?php
/**
 * Общий HTML-каркас: <head>, шапка сайта, открытие .wrapper
 *
 * Переменные (опционально, задаются ДО подключения):
 *   $pageTitle — заголовок вкладки; если не задан, берётся site_name
 *   $extraCss  — массив дополнительных CSS (например ['/assets/css/profile.css'])
 *   $bodyClass — класс для <body> (по умолчанию '')
 *   $settings  — готовый массив site_settings; если не задан, читается здесь
 *
 * название, логотип и favicon берутся из site_settings, а не из
 * разметки. Настройки читаются здесь, а не пробрасываются из каждой
 * страницы: страниц четыре, и в одной из них поле забыли бы. Один
 * запрос на страницу, остальные значения отдаёт кэш внутри
 * site_settings().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../modules/connect.php';
require_once __DIR__ . '/../modules/site.php';

// Настройки читаются до любого вывода. Если БД недоступна, connect()
// вернёт не mysqli - тогда подставляем штатные значения, чтобы страница
// отдалась целиком, а не белым экраном из-за fatal в шапке.
if (!isset($settings) || !is_array($settings)) {
    $brandMysql = @connect();
    $settings = ($brandMysql instanceof mysqli) ? site_settings($brandMysql) : [];
}

$siteName = site_setting($settings, 'site_name', 'Aion Corporation');
$siteDescription = site_setting($settings, 'site_description', '');
$siteLogo = site_setting($settings, 'site_logo_url', '/assets/images/logo.png');
// одна иконка, PNG. Ключ site_favicon_url (svg) удалён
// миграцией, а путь по умолчанию ведёт в branding, где иконку и
// делает генератор.
$siteFavicon = site_setting($settings, 'site_favicon_png_url', '/assets/images/branding/favicon.png');

$extraCss = $extraCss ?? [];
$bodyClass = $bodyClass ?? '';
$isLoggedIn = isset($_SESSION['user_id']);

// Тема оформления выбирается cookie aion_theme и приходит атрибутом
// на <html>. Так вспышки белого нет вообще: атрибут есть в том же
// HTML, что и стили, и браузер ни разу не рисует светлую страницу.
//
// Инлайн-скрипт перед <link> сделал бы то же, но CSP в .htaccess
// запрещает script-src без 'unsafe-inline' и без nonce, а ослаблять
// его ради одного скрипта не стоит.
//
// Пустое значение - намеренный случай: cookie ещё нет, и тему
// решает prefers-color-scheme в base.css. Проверка на строгое
// 'light'/'dark' нужна и для этого, и на случай подделки cookie:
// иначе в атрибут попало бы что угодно из значения вида
// aion_theme=x" onclick="...
$themeCookie = $_COOKIE['aion_theme'] ?? '';
$htmlTheme = ($themeCookie === 'light' || $themeCookie === 'dark') ? $themeCookie : '';
?>
<!DOCTYPE html>
<html lang="ru"<?= $htmlTheme !== '' ? ' data-theme="' . escape($htmlTheme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
<?php // Заголовок вкладки. Если страница задала свой, он дополняется
      // названием сайта («Админ-панель — Aion Corporation»). Без этого
      // каждая страница должна была бы знать название компании, и смена
      // бренда ломала бы их все.
      //
      // Дублирования нет в трёх случаях: заголовок пуст (главная),
      // заголовок совпадает с названием, либо название уже упоминается в
      // нём — иначе выходило бы «Aion Corporation — Aion Corporation».
      if ($pageTitle === null || trim((string) $pageTitle) === '') {
          $fullTitle = $siteName;
      } elseif (trim((string) $pageTitle) === $siteName || str_contains((string) $pageTitle, $siteName)) {
          $fullTitle = (string) $pageTitle;
      } else {
          $fullTitle = $pageTitle . ' — ' . $siteName;
      } ?>
    <title><?= escape($fullTitle) ?></title>
<?php // БАГ 4: описание из site_settings до этого нигде не выводилось, и
      // поле «Описание» в админке было мёртвым. Идёт и в description
      // для поисковиков, и в подпись hero на главной. ?>
<?php if ($siteDescription !== ''): ?>
    <meta name="description" content="<?= escape($siteDescription) ?>">
<?php endif; ?>
    <link rel="stylesheet" href="<?= escape(asset_url('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= escape(asset_url('/assets/css/style.css')) ?>">
<?php foreach ($extraCss as $css): ?>
    <link rel="stylesheet" href="<?= escape(asset_url($css)) ?>">
<?php endforeach; ?>
    <!-- иконка одна и только PNG.

         Раньше их было две - svg для современных браузеров и png для
         старых, - и это стоило отдельного ключа настройки, отдельной
         логики сброса («загрузил png - сбрось svg, иначе старый кандидат
         продолжит показываться») и вечного риска, что показывается не
         та картинка. Теперь иконку рисует сайт из site_settings, и
         кандидат ровно один.

         rel="shortcut icon" тоже не нужен: он значил то же самое, а в
         HTML5 правильный способ - просто rel="icon". Короткое имя живёт
         в rel ещё с IE, где требовалось указать его для всех прочих
         ссылок на иконку. -->
    <link rel="icon" href="<?= escape($siteFavicon) ?>" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;700&display=swap" rel="stylesheet">
</head>
<body<?= $bodyClass !== '' ? ' class="' . escape($bodyClass) . '"' : '' ?>>
    <header class="header">
        <div class="header__inner">
            <a href="/"><div><img class="logo" src="<?= escape($siteLogo) ?>" alt="<?= escape($siteName) ?>"></div></a>
            <button type="button" class="nav-burger" data-action="menu" aria-label="Меню"><span></span><span></span><span></span></button>
            <div class="nav">
                <ul>
                    <li class="list">
                        <a href="/#assembly">
                            <span class="text">Сборки ПК</span>
                            <span class="icon"><img src="/assets/images/cog-outline.svg"></span>
                        </a>
                    </li>
                    <li class="list">
                        <a href="/#configurator">
                            <span class="text">Конфигуратор</span>
                            <span class="icon"><img src="/assets/images/hammer-outline.svg"></span>
                        </a>
                    </li>
                    <li class="list">
                        <a href="/#contacts">
                            <span class="text">О нас</span>
                            <span class="icon"><img src="/assets/images/link-outline.svg"></span>
                        </a>
                    </li>
                    <li class="list">
                        <a href="/profile.php">
                            <span class="text"><?php if (!$isLoggedIn): ?>Войти<?php else: ?><?= escape($_SESSION['user_name'] ?? '') ?><?php endif; ?></span>
                            <span class="icon"><img src="/assets/images/person-outline.svg"></span>
                        </a>
                    </li>
                </ul>
                <!-- Переключатель темы. Обе иконки в разметке, видимость
                     задаёт CSS по data-theme: в светлой показывается луна
                     (что будет, если нажать), в тёмной - солнце. Так
                     иконка остаётся верной при первом заходе, когда
                     атрибута нет и тему задала система. -->
                <button type="button" class="theme-toggle" data-action="toggle-theme"
                        aria-label="Переключить тему" title="Переключить тему">
                    <svg class="theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path>
                    </svg>
                    <svg class="theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                </button>
            </div>
        </div>
    </header>
<?php
// единая модалка подтверждения нужна и админке, и профилю
// (удаление заказа/комплектующего/пользователя, избранного, выход),
// поэтому подключается здесь - на каждой странице
require __DIR__ . '/confirm-modal.php';
?>
    <div class="wrapper">
