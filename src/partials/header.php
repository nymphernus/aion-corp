<?php
/**
 * Общий HTML-каркас: <head>, шапка сайта, открытие .wrapper
 *
 * Переменные (опционально, задаются ДО подключения):
 *   $pageTitle — заголовок вкладки (по умолчанию 'AION CORP')
 *   $extraCss  — массив дополнительных CSS (например ['/assets/css/profile.css'])
 *   $bodyClass — класс для <body> (по умолчанию '')
 *
 * Бизнес-логики и SQL здесь нет — только чтение $_SESSION.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../modules/connect.php';

$pageTitle = $pageTitle ?? 'AION CORP';
$extraCss = $extraCss ?? [];
$bodyClass = $bodyClass ?? '';
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title><?= escape($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= escape(asset_url('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= escape(asset_url('/assets/css/style.css')) ?>">
<?php foreach ($extraCss as $css): ?>
    <link rel="stylesheet" href="<?= escape(asset_url($css)) ?>">
<?php endforeach; ?>
    <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;700&display=swap" rel="stylesheet">
</head>
<body<?= $bodyClass !== '' ? ' class="' . escape($bodyClass) . '"' : '' ?>>
    <header class="header">
        <div class="header__inner">
            <a href="/"><div><img class="logo" src="/assets/images/logo.png" alt="logo"></div></a>
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
                        <a href="/#information">
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
    <div class="wrapper">
