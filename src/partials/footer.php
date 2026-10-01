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
</body>
</html>
