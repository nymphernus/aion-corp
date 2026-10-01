<?php
/**
 * Постраничная навигация админ-таблиц (Stage 3.7-f-5).
 *
 * Единый паттерн для /admin.php?tab=users|orders|components:
 *   ?tab=X&page=N — N по умолчанию 1, по 20 строк на страницу.
 *
 * paginate() считает страницы и уводит на последнюю, если номер
 * за пределами диапазона (page=99 -> редирект, а не пустая таблица).
 */

if (!function_exists('paginate')) {
    /**
     * @return array{0:int,1:int,2:int} [page, pages, offset]
     */
    function paginate(string $tab, int $total, int $perPage = 20): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = (int) ($_GET['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $pages) {
            header('Location: /admin.php?tab=' . urlencode($tab) . '&page=' . $pages);
            exit();
        }
        return [$page, $pages, ($page - 1) * $perPage];
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Навигация под таблицей. На одной странице ничего не выводим.
     */
    function render_pagination(string $tab, int $page, int $pages): string
    {
        if ($pages < 2) {
            return '';
        }
        $q = '/admin.php?tab=' . urlencode($tab) . '&page=';
        $out = '<nav class="pagination">';
        if ($page > 1) {
            $out .= '<a href="' . $q . ($page - 1) . '" class="pagination__item pagination__prev" rel="prev">←</a>';
        }
        for ($i = 1; $i <= $pages; $i++) {
            $isActive = ($i === $page);
            $out .= '<a href="' . $q . $i . '" class="pagination__item'
                . ($isActive ? ' is-active' : '') . '"'
                . ($isActive ? ' aria-current="page"' : '') . '>' . $i . '</a>';
        }
        if ($page < $pages) {
            $out .= '<a href="' . $q . ($page + 1) . '" class="pagination__item pagination__next" rel="next">→</a>';
        }
        return $out . '</nav>';
    }
}