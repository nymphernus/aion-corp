<?php
/**
 * AuthTest — логин, регистрация, rate-limit.
 */

declare(strict_types=1);

final class AuthTest extends AionTestCase
{
    public function testWrongPasswordRedirectsWithoutSession(): void
    {
        $page = $this->httpGet('/profile.php');
        $token = $this->extractCsrf($page['body']);

        $r = $this->httpPost('/validation/auth.php', [
            'user_login' => 'admin',
            'user_pass' => 'definitely_wrong_password',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $r['code']);

        $profile = $this->httpGet('/profile.php');
        $this->assertSame(200, $profile['code']);
        $this->assertStringNotContainsString('Выйти', $profile['body']);
        $this->clearLoginAttempts('admin');
    }

    public function testValidLoginCreatesSession(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');

        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);

        $profile = $this->httpGet('/profile.php');
        $this->assertSame(200, $profile['code']);
        $this->assertStringContainsString('Выйти', $profile['body']);
    }

    public function testRateLimitBlocksAfterSixAttempts(): void
    {
        $login = $this->uniqueLogin('rl_test_');
        $this->trackCleanup($login);

        $codes = [];
        $lastBody = '';
        for ($i = 0; $i < 6; $i++) {
            $page = $this->httpGet('/profile.php');
            $token = $this->extractCsrf($page['body']);
            $r = $this->httpPost('/validation/auth.php', [
                'user_login' => $login,
                'user_pass' => 'wrong_password_1',
                'csrf_token' => $token,
            ]);
            $codes[] = $r['code'];
            $lastBody = $r['body'];
        }

        $this->assertSame([302, 302, 302, 302, 302, 429], $codes);
        $this->assertStringContainsString('Слишком много попыток', $lastBody);
    }

    public function testValidRegistrationCreatesUser(): void
    {
        $login = $this->uniqueLogin();
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $token = $this->extractCsrf($page['body']);

        $r = $this->httpPost('/validation/reg.php', [
            'user_name' => 'Tester',
            'user_login' => $login,
            'user_pass' => 'password123',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertTrue($this->userExists($login));
    }

    public function testShortPasswordDoesNotCreateUser(): void
    {
        $login = $this->uniqueLogin();
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $token = $this->extractCsrf($page['body']);

        $r = $this->httpPost('/validation/reg.php', [
            'user_name' => 'Tester',
            'user_login' => $login,
            'user_pass' => 'short',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertFalse($this->userExists($login));
    }
}
