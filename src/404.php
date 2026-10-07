<?php
/**
 * Страница «не найдено» с дизайном сайта.
 *
 * Подключается Apache через ErrorDocument, а не открывается напрямую:
 * http_response_code заново выставляет 404 и на случай прямого захода.
 */
http_response_code(404);

$pageTitle = 'Страница не найдена';

require __DIR__ . '/partials/header.php';
?>

<section class="error-page">
    <div class="error-page__code">404</div>
    <h1 class="error-page__title">Страница не найдена</h1>
    <p class="error-page__text">
        Такого адреса на сайте нет. Возможно, сборка или товар удалены
        либо в ссылке опечатка.
    </p>
    <div class="error-page__actions">
        <a href="/" class="btn btn--primary">На главную</a>
        <a href="/#configurator" class="btn btn--secondary">Собрать ПК</a>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>