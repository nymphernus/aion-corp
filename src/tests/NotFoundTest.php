<?php

/**
 * Страница «не найдено».
 *
 * Отдельно от остальных наборов: проверяет не поведение приложения, а то,
 * что Apache отдаёт вместо своей служебной страницы. Служебная страница
 * выглядит как чужой сервер и пугает посетителя, поэтому и подменяется.
 */
declare(strict_types=1);

final class NotFoundTest extends AionTestCase
{
    /**
     * Несуществующий адрес отдаёт 404, а не 200 и не 500.
     *
     * Отдельно от вида страницы проверяется и сам код: страница с текстом
     * «страница не найдена» под кодом 200 выглядит для посетителя и для
     * поисковика как рабочая, и битая ссылка осталась бы битой дальше.
     */
    public function testMissingUrlReturnsNotFound(): void
    {
        $page = $this->httpGet('/no-such-page-' . __CLASS__);

        $this->assertSame(404, $page['code'], 'несуществующий адрес должен отдавать 404');
        $this->assertStringNotContainsString(
            'Warning',
            $page['body'],
            'страница ошибки не должна ломаться сама: код 404 с Warning означает '
            . 'неработающую подмену, а читатель увидит оба сообщения сразу'
        );
    }

    /**
     * Страница выглядит как сайт, а не как сообщение сервера.
     *
     * Проверяются признаки каркаса: он подключает хедер и футер. Иначе
     * Apache-страница или пустой ответ выглядели бы одинаково, а разница
     * только в HTTP-коде.
     */
    public function testNotFoundPageUsesSiteLayout(): void
    {
        $page = $this->httpGet('/no-such-page-' . __CLASS__);
        $this->assertSame(404, $page['code']);

        $this->assertStringContainsString(
            'Страница не найдена',
            $page['body'],
            'страница должна объяснять, что произошло'
        );
        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//header'),
            'должен подключаться хедер сайта'
        );
        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//footer'),
            'должен подключаться подвал сайта'
        );
        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//section[contains(@class, "error-page")]//a[@href="/"]'),
            'нужен выход на главную'
        );
    }

    /**
     * Подмена настроена в .htaccess, а не только в самой странице.
     *
     * ErrorDocument отвечает за то, что Apache вообще до неё дойдёт.
     * Потерянная строка в .htaccess не сломала бы страницу 404.php как
     * таковую - она тихо перестала бы показываться, и заметить это можно
     * было бы только вручную.
     */
    public function testHtaccessPointsToNotFoundPage(): void
    {
        $htaccess = dirname(__DIR__) . '/.htaccess';
        $this->assertFileExists($htaccess, 'нет файла .htaccess');

        $this->assertMatchesRegularExpression(
            '#^\s*ErrorDocument\s+404\s+/404\.php\s*$#mi',
            (string) file_get_contents($htaccess),
            'Apache должен отдавать /404.php на несуществующий адрес'
        );
        $this->assertFileExists(dirname(__DIR__) . '/404.php', 'нет файла 404.php');
    }

    /**
     * Ссылки со страницы ведут на существующие адреса.
     *
     * Страница ошибки с битыми ссылками отправляет посетителя за ту же
     * ошибкой: с 404 ведущей на 404 он дальше не выйдет.
     */
    public function testNotFoundPageLinksResolve(): void
    {
        $page = $this->httpGet('/no-such-page-' . __CLASS__);
        $this->assertSame(404, $page['code']);

        $hrefs = [];
        $doc = new DOMDocument();
        @$doc->loadHTML($page['body']);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if ($href !== '' && $href[0] === '/') {
                $hrefs[$href] = true;
            }
        }

        $this->assertNotEmpty($hrefs, 'на странице должны быть ссылки');

        foreach (array_keys($hrefs) as $href) {
            // Якорь серверу не отправляется, проверяется сама страница.
            $target = explode('#', $href)[0];
            $check = $this->httpGet($target);
            $this->assertSame(
                200,
                $check['code'],
                "ссылка «{$href}» ведёт на несуществующий адрес"
            );
        }
    }
}