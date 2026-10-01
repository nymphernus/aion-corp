<?php
/**
 * LogoutTest — выход очищает сессию.
 */

declare(strict_types=1);

final class LogoutTest extends AionTestCase
{
    public function testLogoutClearsSession(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);

        $before = $this->httpGet('/profile.php');
        $this->assertStringContainsString('Выйти', $before['body']);

        $out = $this->httpGet('/validation/exit.php');
        $this->assertSame(302, $out['code']);

        $after = $this->httpGet('/profile.php');
        $this->assertSame(200, $after['code']);
        $this->assertStringNotContainsString('Выйти', $after['body']);
        $this->assertStringContainsString('Авторизация', $after['body']);
    }
}
