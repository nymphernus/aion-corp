<?php
/**
 * GuestTest — гость без сессии.
 */

declare(strict_types=1);

final class GuestTest extends AionTestCase
{
    public function testIndexReturns200(): void
    {
        $r = $this->httpGet('/');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('AION', $r['body']);
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
