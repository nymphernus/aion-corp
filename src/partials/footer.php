<?php
/**
 * Общий подвал: закрытие .wrapper, <footer>, скрипты
 *
 * Переменные (опционально, задаются ДО подключения):
 *   $extraJs — массив дополнительных JS (например ['/assets/js/scripts.js'])
 *
 * Логики здесь нет — только foreach для скриптов.
 */

$extraJs = $extraJs ?? [];
?>
    </div><!-- /.wrapper -->
    <footer class="footer">
        <span>© 2022 Aion Corporation</span>
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
