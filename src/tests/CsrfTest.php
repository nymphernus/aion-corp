<?php
/**
 * CsrfTest — CSRF-защита POST-эндпоинтов.
 */

declare(strict_types=1);

final class CsrfTest extends AionTestCase
{
    public function testPostWithoutTokenIs403(): void
    {
        $this->httpGet('/assembly.php?init=1'); // прогрев сессии
        $r = $this->httpPost('/assembly.php?init=1', ['save' => '1']);
        $this->assertSame(403, $r['code']);
    }

    public function testPostWithForeignTokenIs403(): void
    {
        $this->httpGet('/assembly.php?init=1');
        $r = $this->httpPost('/assembly.php?init=1', [
            'save' => '1',
            'csrf_token' => str_repeat('a', 64),
        ]);
        $this->assertSame(403, $r['code']);
    }

    public function testPostWithValidTokenPassesCsrf(): void
    {
        // Гостю рендерится форма без токена (кнопки disabled),
        // поэтому валидный сценарий — под логином (дубликат favorites невозможен)
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->loginAs('admin', (string) $adminPass);

        $page = $this->httpGet('/assembly.php?init=1');
        $token = $this->extractCsrf($page['body']);

        $r = $this->httpPost('/assembly.php?init=1', [
            'save' => '1',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $r['code']);
    }

    public function testAuthWithoutTokenIs403(): void
    {
        $this->httpGet('/profile.php');
        $r = $this->httpPost('/validation/auth.php', [
            'user_login' => 'admin',
            'user_pass' => 'x',
        ]);
        $this->assertSame(403, $r['code']);
    }
}
