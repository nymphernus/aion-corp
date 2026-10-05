<?php
/**
 * GuestTest — гость без сессии.
 */

declare(strict_types=1);

final class GuestTest extends AionTestCase
{
    /**
     * Главная открывается и показывает название из site_settings.
     *
     * Раньше проверялась строка 'AION' - то есть тест был привязан к
     * написанию бренда и падал после его переименования в «Aion
     * Corporation». Теперь берётся значение из настроек, поэтому
     * переименование сайта его не ломает, а подмена хардкода на пустое
     * место по-прежнему ловится.
     */
    public function testIndexReturns200(): void
    {
        $r = $this->httpGet('/');
        $this->assertSame(200, $r['code']);

        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $siteName = site_setting(site_settings($mysql), 'site_name', '');
        $mysql->close();

        $this->assertNotSame('', $siteName, 'название сайта должно быть задано в настройках');
        $this->assertStringContainsString(
            '<h1 class="hero__title">' . escape($siteName) . '</h1>',
            $r['body'],
            'главная должна показывать название из site_settings, а не хардкод'
        );
    }

    public function testProfileShowsLoginForm(): void
    {
        $r = $this->httpGet('/profile.php');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('Авторизация', $r['body']);
    }

    public function testAssemblyTemplateReturns200(): void
    {
        $r = $this->httpGet('/assembly.php?init=1');
        $this->assertSame(200, $r['code']);
    }
}
