<?php
/**
 * PHPUnit bootstrap для HTTP-тестов AION CORP.
 *
 * Запуск из корня src (смонтирован в /var/www/html):
 *   docker compose exec php php /var/www/html/tests/phpunit.phar -c /var/www/html/tests/phpunit.xml --testdox
 *
 * Установка phpunit.phar (вариант B, без composer в проде):
 *   docker compose exec php curl -sL -o /var/www/html/tests/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
 */

declare(strict_types=1);

define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8080');

require_once __DIR__ . '/../modules/connect.php';
require_once __DIR__ . '/TestCase.php';
