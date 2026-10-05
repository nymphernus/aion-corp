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
 * Stage 9: название, логотип и favicon берутся из site_settings, а не из
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

$siteName = site_setting($settings, 'site_name', 'AION CORP');
$siteNameFull = site_setting($settings, 'site_name_full', $siteName);
$siteDescription = site_setting($settings, 'site_description', '');
$siteLogo = site_setting($settings, 'site_logo_url', '/assets/images/logo.png');
$siteFavicon = site_setting($settings, 'site_favicon_url', '/assets/images/favicon.svg');
$siteFaviconPng = site_setting($settings, 'site_favicon_png_url', '/assets/images/favicon.png');

$extraCss = $extraCss ?? [];
$bodyClass = $bodyClass ?? '';
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
<?php // Заголовок вкладки. Если страница задала свой, он дополняется
      // названием сайта («Админ-панель — AION CORP»). Без этого каждая
      // страница должна была бы знать название компании, и смена бренда
      // ломала бы их все. Сам бренд при этом не дублируется, если он уже
      // есть в заголовке страницы.
      if ($pageTitle === null || $pageTitle === '') {
          $fullTitle = $siteName;
      } elseif ($pageTitle === $siteName || str_contains($pageTitle, $siteName)) {
          $fullTitle = $pageTitle;
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
    <!-- 7.5: svg-фавикон, png остаётся запасным вариантом.

         Порядок важен: браузер берёт первый rel="icon", который
         поддерживает. Старые не знают svg и пропустят его, новые
         возьмут svg и не будут трогать тяжёлый png. Ссылка на png
         объявлена как alternate icon - это не запасной вариант на
         случай ошибки, а объявление второго кандидата; без неё
         старый браузер показал бы иконку по умолчанию.

         rel="shortcut icon" убран: он значил то же самое, а в HTML5
         правильный способ - просто rel="icon". Короткое имя живёт в
         rel ещё с IE, где требовалось указать его для всех прочих
         ссылок на иконку. -->
    <link rel="icon" href="<?= escape($siteFavicon) ?>" type="image/svg+xml">
    <link rel="alternate icon" href="<?= escape($siteFaviconPng) ?>" type="image/png">
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
            </div>
        </div>
    </header>
<?php
// 3.7-g-4: единая модалка подтверждения нужна и админке, и профилю
// (удаление заказа/комплектующего/пользователя, избранного, выход),
// поэтому подключается здесь - на каждой странице
require __DIR__ . '/confirm-modal.php';
?>
    <div class="wrapper">
