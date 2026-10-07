<?php
/**
 * AdminSidebarTest — аккордеон «Панель управления» в админке.
 *
 * Отдельный файл, а не AdminTest: проверка касается разметки сайдбара,
 * а не прав администратора, и перебор всех вкладок аккордеона даёт
 * слишком много HTTP-запросов, чтобы прятать это в общий набор.
 */

declare(strict_types=1);

final class AdminSidebarTest extends AionTestCase
{
    /**
     * Аккордеон открыт на каждой своей вкладке.
     *
     * Симптом: после перехода на «Конфигуратор» или «Сборки» список
     * подпунктов сворачивался, и активный пункт оказывался внутри
     * закрытого блока - то есть на экране не было видно, где вы
     * находитесь.
     *
     * Перебираются не конкретные вкладки, а все ссылки из блока: список
     * подпунктов и признак «раздел открыт» берутся из одного массива, но
     * раньше они были двумя списками, и забытый ключ закрывал аккордеон
     * ровно на той вкладке, которую только что добавили в меню. Тест с
     * захардкоженными configurator/assemblies такой случай пропустил бы.
     */
    public function testAccordionOpenOnEveryOwnTab(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $page['code']);

        $dom = $this->loadDom($page['body']);
        $xpath = new DOMXPath($dom);

        $links = $xpath->query('//details[contains(@class, "profile-nav-group")]//a[@href]');
        $this->assertGreaterThan(0, $links->length, 'в аккордеоне должны быть ссылки на разделы');

        $checked = 0;
        foreach ($links as $link) {
            /** @var DOMElement $link */
            $href = (string) $link->getAttribute('href');
            $label = trim((string) $link->textContent);

            $tab = $this->httpGet($href);
            $this->assertSame(200, $tab['code'], "вкладка $href недоступна");

            $this->assertSame(
                1,
                $this->xpathCount($tab['body'], '//details[contains(@class, "profile-nav-group")][@open]'),
                "на вкладке «{$label}» ({$href}) аккордеон должен быть раскрыт: подпункт «{$label}» иначе не виден"
            );

            // и подсвечен именно тот пункт, по которому пришли
            $this->assertSame(
                1,
                $this->xpathCount($tab['body'], '//a[contains(@class, "profile-nav-subitem") and contains(@class, "active")]'),
                "на вкладке «{$label}» должен подсвечиваться активный подпункт"
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'ни одной ссылки аккордеона не проверено');
    }

    /**
     * «Дашборд» и «Настройки сайта» лежат вне аккордеона и не открывают
     * его.
     *
     * Обратная сторона предыдущего правила. Если добавить их в общий
     * список, заголовок «Панель управления» начал бы подсвечиваться на
     * страницах, которые ему не принадлежат.
     */
    public function testOutsideItemsDoNotOpenAccordion(): void
    {
        $this->loginAsAdmin();

        foreach (['/admin.php?tab=dashboard', '/admin.php?tab=settings'] as $href) {
            $page = $this->httpGet($href);
            $this->assertSame(200, $page['code']);

            $this->assertSame(
                0,
                $this->xpathCount($page['body'], '//details[contains(@class, "profile-nav-group")][@open]'),
                "на странице {$href} аккордеон не должен быть раскрыт"
            );
        }
    }

    /**
     * В исходнике сайдбара нет второго списка вкладок.
     *
     * Проверка на исходник, а не на страницу: баг жил именно в том, что
     * признак «раздел открыт» вычислялся по отдельному массиву, который
     * ни во что не был связан с меню. Тесты страницы видят лишь
     * следствие, а признак виден только в коде.
     */
    public function testNoSecondTabListInSource(): void
    {
        $file = dirname(__DIR__) . '/partials/profile-sidebar.php';
        $this->assertFileExists($file);

        $src = (string) file_get_contents($file);

        $this->assertStringNotContainsString(
            '$adminTabs',
            $src,
            'отдельный список вкладок рядом с меню рассинхронизируется с ним же'
        );
        $this->assertMatchesRegularExpression(
            '/\$isAdminSection\s*=\s*isset\(\$adminLinks/',
            $src,
            'признак «раздел открыт» должен выводиться из списка ссылок'
        );
    }

    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }
}