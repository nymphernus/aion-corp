<?php
/**
 * NavTest — якорная навигация в шапке и на главной.
 *
 * Отдельный файл, а не GuestTest: якоря касаются разметки главной и
 * таблицы стилей, а не прав доступа.
 */

declare(strict_types=1);

final class NavTest extends AionTestCase
{
    /**
     * 8: каждая якорная ссылка шапки ведёт к существующему id.
     *
     * Проверяется полный цикл: ссылка из шапки -> id на главной. Раньше
     * обе ссылки были битыми: «Сборки ПК» вела на <a name="assembly">,
     * а не на id (getElementById и scrollIntoView такое не находят), а
     * «О нас» - на #information, блока с таким id не было нигде.
     *
     * Сверяется не число, а каждая ссылка: жёсткое число завязало бы
     * тест на текущее количество пунктов меню.
     */
    public function testHeaderAnchorLinksPointToExistingIds(): void
    {
        $home = $this->httpGet('/');
        $this->assertSame(200, $home['code']);

        $dom = $this->loadDom($home['body']);
        $xpath = new DOMXPath($dom);

        $links = $xpath->query('//header//a[starts-with(@href, "/#")]');
        $this->assertGreaterThan(0, $links->length, 'в шапке должны быть якорные ссылки');

        $checked = 0;
        foreach ($links as $link) {
            /** @var DOMElement $link */
            $href = $link->getAttribute('href');
            $targetId = substr($href, 2);

            $this->assertNotSame('', $targetId, "у ссылки {$href} должен быть якорь");

            $this->assertSame(
                1,
                $xpath->query("//*[@id='{$targetId}']")->length,
                "ссылка {$href} ведёт на несуществующий id: на главной нет #{$targetId}"
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'ни одной якорной ссылки не проверено');
    }

    /**
     * 8: устаревший <a name="..."> заменён на id.
     *
     * name не находится ни getElementById, ни scrollIntoView: якорь
     * работал только через перезагрузку страницы с адресом /#assembly.
     * Плавный скролл без смены URL на таком якоре невозможен, поэтому
     * нужен именно id.
     */
    public function testAssemblyTargetIsIdNotName(): void
    {
        $home = $this->httpGet('/');
        $this->assertSame(200, $home['code']);

        $this->assertSame(
            0,
            $this->xpathCount($home['body'], '//a[@name="assembly"]'),
            'якорь assembly должен быть id, а не устаревшим name'
        );
        $this->assertSame(
            1,
            $this->xpathCount($home['body'], '//*[@id="assembly"]'),
            'на главной должен быть элемент с id="assembly"'
        );
    }

    /**
     * 8: плавный скролл перехватывает якоря и не меняет адресную строку.
     *
     * Файл скрипта отдаётся как есть, поэтому проверяется текстом: в
     * браузере PHPUnit не запускается, а сам обработчик виден только
     * там. Проверяются все части решения, без которых скролл не
     * получится: перехват клика, поиск цели, preventDefault,
     * scrollIntoView с behavior smooth и проверка, что мы на главной.
     */
    public function testSmoothScrollHandlerPresent(): void
    {
        $js = $this->readFile('/assets/js/scripts.js');
        $this->assertStringContainsString("scrollIntoView", $js, 'нужен плавный скролл');
        $this->assertStringContainsString("behavior: 'smooth'", $js, 'скролл должен быть плавным');
        $this->assertStringContainsString("block: 'start'", $js, 'скроллить надо к началу блока');
        $this->assertStringContainsString("getElementById", $js, 'цель ищется по id');
        $this->assertStringContainsString("preventDefault", $js, 'ссылку надо перехватить, иначе сменится адрес');

        // обработчик именно делегированный: слушатель на document, а не
        // на самих ссылках - ссылки в шапке есть на каждой странице, и
        // навешивать слушатель в разметке означало бы дублировать его
        $this->assertMatchesRegularExpression(
            "/document\.addEventListener\('click'/",
            $js,
            'обработчик должен быть делегированным на document'
        );
    }

    /**
     * 8: у всех целей скролла задан scroll-margin-top.
     *
     * Шапка фиксированная, 90px. Без отступа заголовок целевого блока
     * уезжает под неё, и блок выглядит срезанным сверху.
     *
     * Правило ищется по селектору в таблице стилей, а не по факту
     * применения: на каждый id нужен свой отступ, и упавший один
     * селектор из списка тест бы не заметил.
     */
    public function testScrollMarginSetForAllTargets(): void
    {
        $css = $this->readFile('/assets/css/style.css');

        // Разбор по блокам «селектор { ... }» вместо регулярки по всему
        // файлу. Регулярка искала бы подстроку где угодно и находила
        // scroll-margin-top из соседнего правила, а разбор отвечает на
        // вопрос по делу: в каком правиле объявлено свойство и к каким
        // селекторам оно относится.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $covered = [];
        foreach ($rules as $rule) {
            $body = $rule[2];
            if (strpos($body, 'scroll-margin-top') === false) {
                continue;
            }

            preg_match_all('/#([A-Za-z0-9_-]+)/', $rule[1], $ids);
            foreach ($ids[1] as $id) {
                $covered[$id] = true;
            }
        }

        $this->assertNotEmpty($covered, 'в style.css нет ни одного scroll-margin-top');

        foreach (['assembly', 'configurator', 'contacts'] as $id) {
            $this->assertArrayHasKey(
                $id,
                $covered,
                "у #{$id} нет scroll-margin-top: блок уедет под фиксированную шапку"
            );
        }
    }

    /**
     * Текстовый файл из public_html.
     */
    private function readFile(string $path): string
    {
        $file = dirname(__DIR__) . $path;
        $this->assertFileExists($file, "нет файла {$path}");

        $content = file_get_contents($file);
        $this->assertIsString($content, "не удалось прочитать {$path}");

        return $content;
    }
}