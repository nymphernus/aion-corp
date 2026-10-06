/* Переключатель темы.
 *
 * Отдельный файл, а не часть scripts.js, потому что кнопка живёт в
 * шапке, а шапка есть на каждой странице. scripts.js при этом
 * подключают постранично: на главной, в профиле и админке, но не на
 * странице сборки. Из-за этого обработчик темы там просто не
 * загружался, хотя кнопка нарисована.
 *
 * Правильность выбора проверяется строго: cookie читает PHP и ставит
 * data-theme на <html> только для значений light и dark, поэтому
 * здесь того же достаточно - «dark» означает тёмную, всё остальное
 * (включая отсутствие атрибута) означает светлую.
 */
(function() {
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action="toggle-theme"]');
        if (!btn) return;
        e.preventDefault();

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const next = isDark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);

        // год - достаточно, чтобы не пришлось выбирать заново.
        // SameSite=Lax: cookie не уходит на сторонние запросы, но
        // ходит по своему сайту, куда и нужен.
        document.cookie = 'aion_theme=' + next
            + '; path=/; max-age=31536000; SameSite=Lax';
    });
})();