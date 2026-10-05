<?php
/**
 * Общий подвал: закрытие .wrapper, <footer>, скрипты
 *
 * Переменные (опционально, задаются ДО подключения):
 *   $extraJs   — массив дополнительных JS (например ['/assets/js/scripts.js'])
 *   $settings  — site_settings; если не задан, читается здесь
 *
 * Stage 9: копирайт собирается из site_settings, а не из разметки.
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

$footerCopyright = site_setting($settings, 'site_footer_copyright', '');
$siteName = site_setting($settings, 'site_name', 'Aion Corporation');
$foundedYear = site_setting($settings, 'site_founded_year', '2022');

// Пустая настройка копирайта - не повод показывать «©» без названия.
// Год подставляется из site_founded_year, а не из date('Y'): проект
// основан один раз, и «© 2026» вместо «© 2022» уезжало бы само
// собой каждый январь.
if (trim($footerCopyright) === '') {
    $footerCopyright = '© ' . $foundedYear . ' ' . $siteName;
}

$extraJs = $extraJs ?? [];
?>
    </div><!-- /.wrapper -->
    <footer class="footer">
        <span><?= escape($footerCopyright) ?></span>
        <span>Designed by <a href="https://github.com/nymphernus">Aleksey Schumann</a></span>
    </footer>
<?php foreach ($extraJs as $js): ?>
    <script src="<?= escape(asset_url($js)) ?>"></script>
<?php endforeach; ?>
<?php
// 5-d-3: счётчик запросов к базе. Показывается только если переменная
// DEBUG_SQL_COUNT задана и непуста, в обычной работе блока нет.
if (getenv('DEBUG_SQL_COUNT') !== false && getenv('DEBUG_SQL_COUNT') !== '' && isset($GLOBALS['db_prepare_calls'])) {
    echo "\n<!-- db_prepare calls: " . (int) $GLOBALS['db_prepare_calls'] . " -->\n";
}
?>
</body>
</html>
