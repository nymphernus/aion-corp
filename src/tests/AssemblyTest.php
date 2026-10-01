<?php
/**
 * AssemblyTest — конфигуратор, save/buy (требует логина).
 */

declare(strict_types=1);

final class AssemblyTest extends AionTestCase
{
    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    private function freshToken(string $path): string
    {
        $page = $this->httpGet($path);
        $this->assertSame(200, $page['code']);
        return $this->extractCsrf($page['body']);
    }

    private function adminId(): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", 'admin');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        $this->assertNotEmpty($row);
        return (int) $row['user_id'];
    }

    public function testConfiguratorSaveBuyFlow(): void
    {
        $this->loginAsAdmin();
        $uid = $this->adminId();

        // 1. Конфигуратор → новая сборка N > 3 (assemblyId выдаётся cookie)
        $token = $this->freshToken('/');
        $cfg = $this->httpPost('/assembly.php', [
            'price' => '200000',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $cfg['code']);

        // 2. Открываем созданную сборку, запоминаем N
        $page = $this->httpGet('/assembly.php');
        $this->assertSame(200, $page['code']);
        $this->assertMatchesRegularExpression('/Номер сборки - (\d+)/', $page['body']);
        preg_match('/Номер сборки - (\d+)/', $page['body'], $m);
        $n = (int) $m[1];
        $this->assertGreaterThan(3, $n, 'Конфигуратор не создал пользовательскую сборку');

        // 3. save → 302, запись в favorites
        $t2 = $this->extractCsrf($page['body']);
        $save = $this->httpPost('/assembly.php', ['save' => '1', 'csrf_token' => $t2]);
        $this->assertSame(302, $save['code']);
        $this->assertSame(1, $this->countRows('favorites', $uid, $n));

        // 4. Повторный save → 302, без дубликата
        $t3 = $this->freshToken('/assembly.php');
        $save2 = $this->httpPost('/assembly.php', ['save' => '1', 'csrf_token' => $t3]);
        $this->assertSame(302, $save2['code']);
        $this->assertSame(1, $this->countRows('favorites', $uid, $n));

        // 5. buy → 302, запись в orders
        $t4 = $this->freshToken('/assembly.php');
        $buy = $this->httpPost('/assembly.php', ['buy' => '1', 'csrf_token' => $t4]);
        $this->assertSame(302, $buy['code']);
        $this->assertSame(1, $this->countRows('orders', $uid, $n));

        // Чистим созданные связи (сборку оставляем — она часть каталога)
        $this->deleteLink('favorites', $uid, $n);
        $this->deleteLink('orders', $uid, $n);
    }

    private function countRows(string $table, int $uid, int $assemblyId): int
    {
        $allowed = ['favorites' => true, 'orders' => true];
        $this->assertArrayHasKey($table, $allowed);
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT COUNT(*) AS c FROM `$table` WHERE user_id = ? AND assembly_id = ?", "ii", $uid, $assemblyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        return (int) ($row['c'] ?? 0);
    }

    private function deleteLink(string $table, int $uid, int $assemblyId): void
    {
        $allowed = ['favorites' => true, 'orders' => true];
        $this->assertArrayHasKey($table, $allowed);
        $mysql = connect();
        $stmt = db_prepare($mysql, "DELETE FROM `$table` WHERE user_id = ? AND assembly_id = ?", "ii", $uid, $assemblyId);
        $stmt->execute();
        $mysql->close();
    }
}
