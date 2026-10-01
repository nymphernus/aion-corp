<?php
/**
 * Базовый класс HTTP-тестов.
 *
 * Каждый тест работает со своим cookie-jar (изоляция сессий).
 * Созданные пользователи удаляются в tearDown по списку cleanupLogins.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

class AionTestCase extends PhpUnitTestCase
{
    protected string $jar;
    /** @var string[] */
    protected array $cleanupLogins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->jar = tempnam(sys_get_temp_dir(), 'aion_jar_');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupLogins as $login) {
            $this->deleteTestUser($login);
        }
        $this->cleanupLogins = [];
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
        parent::tearDown();
    }

    /**
     * @return array{code: int, body: string}
     */
    protected function httpGet(string $path): array
    {
        $ch = curl_init(BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }

    /**
     * @param array<string, string> $data
     * @return array{code: int, body: string}
     */
    protected function httpPost(string $path, array $data): array
    {
        $ch = curl_init(BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }

    protected function extractCsrf(string $html): string
    {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        $this->fail('CSRF-токен не найден на странице');
        return '';
    }

    protected function uniqueLogin(string $prefix = 'user_test_'): string
    {
        return substr($prefix . time() . '_' . bin2hex(random_bytes(3)), 0, 25);
    }

    protected function trackCleanup(string $login): void
    {
        $this->cleanupLogins[] = $login;
    }

    protected function deleteTestUser(string $login): void
    {
        try {
            $mysql = connect();
        } catch (Throwable) {
            return;
        }
        $stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $uid = (int) $row['user_id'];
            foreach (['orders', 'favorites'] as $t) {
                $s = db_prepare($mysql, "DELETE FROM `$t` WHERE user_id = ?", "i", $uid);
                $s->execute();
            }
            $s = db_prepare($mysql, "DELETE FROM users WHERE user_id = ?", "i", $uid);
            $s->execute();
        }
        $s = db_prepare($mysql, "DELETE FROM login_attempts WHERE login = ?", "s", $login);
        $s->execute();
        $mysql->close();
    }

    protected function userExists(string $login): bool
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", $login);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $mysql->close();
        return $exists;
    }

    protected function loginAs(string $login, string $pass): array
    {
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);
        $token = $this->extractCsrf($page['body']);
        return $this->httpPost('/validation/auth.php', [
            'user_login' => $login,
            'user_pass' => $pass,
            'csrf_token' => $token,
        ]);
    }
}
