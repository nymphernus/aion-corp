<?php
/**
 * Общий подвал: закрытие .wrapper, <footer>, скрипты
 *
 * Переменные (опционально, задаются ДО подключения):
 *   $extraJs   — массив дополнительных JS (например ['/assets/js/scripts.js'])
 *   $settings  — site_settings; если не задан, читается здесь
 *
 * копирайт собирается из site_settings, а не из разметки.
 * Это «© {site_founded_year} {site_name}».
 * $settings к этому моменту уже есть - его читает header.php, который
 * подключается раньше. Собственная попытка чтения здесь нужна только
 * для случая, когда подвал подключат без шапки.
 */

require_once __DIR__ . '/../modules/site.php';

if (!isset($settings) || !is_array($settings)) {
    require_once __DIR__ . '/../modules/connect.php';
    $footerMysql = @connect();
    $settings = ($footerMysql instanceof mysqli) ? site_settings($footerMysql) : [];
}

// Копирайт собирается из года основания и названия сайта: строка целиком
// в настройке требовала бы дублировать оба значения в тексте.
//
// © жёстко в шаблоне, а не в настройке: это символ, а не данные.
$siteName = site_setting($settings, 'site_name', 'Aion Corporation');
$foundedYear = site_setting($settings, 'site_founded_year', '2022');
$footerCopyright = '© ' . $foundedYear . ' ' . $siteName;

$extraJs = $extraJs ?? [];
?>
    </div><!-- /.wrapper -->
    <footer class="footer">
        <span><?= escape($footerCopyright) ?></span>
        <span>Designed by <a href="https://github.com/nymphernus">Aleksey Schumann</a></span>
    </footer>
<?php // Обработчик темы подключается здесь, а не постранично: кнопка
     // живёт в шапке, а шапка есть на каждой странице, тогда как
     // scripts.js в $extraJs перечисляют выборочно. ?>
    <script src="<?= escape(asset_url('/assets/js/confirm.js')) ?>"></script>
    <script src="<?= escape(asset_url('/assets/js/theme.js')) ?>"></script>
<?php foreach ($extraJs as $js): ?>
    <script src="<?= escape(asset_url($js)) ?>"></script>
<?php endforeach; ?>
<?php
// Счётчик запросов выводится только при заданной DEBUG_SQL_COUNT, в
// обычной работы блока нет.
if (getenv('DEBUG_SQL_COUNT') !== false && getenv('DEBUG_SQL_COUNT') !== '' && isset($GLOBALS['db_prepare_calls'])) {
    echo "\n<!-- db_prepare calls: " . (int) $GLOBALS['db_prepare_calls'] . " -->\n";
}
?>
</body>
</html>
