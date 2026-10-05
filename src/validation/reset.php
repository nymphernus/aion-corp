<?php
/**
 * Страница сброса пароля
 *
 * Заглушка для legacy-пользователей с устаревшими хешами.
 * В продакшене здесь должна быть форма сброса пароля.
 *
 * Страница самостоятельная: шапку не подключает, поэтому настройки
 * сайта берутся напрямую. Название остаётся в разметке только как
 * запасной вариант на случай недоступной базы.
 */
require_once __DIR__ . '/../modules/site.php';

$resetSiteName = 'AION CORP';
$resetMysql = @connect();
if ($resetMysql instanceof mysqli) {
    $resetSettings = site_settings($resetMysql);
    $resetName = site_setting($resetSettings, 'site_name', '');
    if ($resetName !== '') {
        $resetSiteName = $resetName;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Сброс пароля — <?= escape($resetSiteName) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="wrapper">
        <div class="container_profile">
            <div class="cont_profile">
                <h1>Сброс пароля</h1>
                <p>Ваш пароль хранится в устаревшем формате и требует сброса.</p>
                <p>Для сброса пароля обратитесь к администратору.</p>
                <p><a href="/profile.php">Вернуться на страницу входа</a></p>
            </div>
        </div>
    </div>
</body>
</html>
