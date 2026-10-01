<?php
/**
 * AdminTest — админ-операции и их защита.
 */

declare(strict_types=1);

final class AdminTest extends AionTestCase
{
    private function loginAsAdmin(): string
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
        $page = $this->httpGet('/profile.php');
        return $this->extractCsrf($page['body']);
    }

    private function createVictim(): array
    {
        $login = $this->uniqueLogin('victim_');
        $this->trackCleanup($login);
        $mysql = connect();
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)",
            "ssss",
            'Victim',
            $login,
            $hash,
            'user'
        );
        $stmt->execute();
        $id = $mysql->insert_id;
        $mysql->close();
        return [$login, $id];
    }

    public function testAdminPanelVisibleForAdmin(): void
    {
        $this->loginAsAdmin();
        // 3.7-f-4c-6: раньше тест искал «Управление пользователями» в
        // /profile.php, но это был заголовок мёртвого блока userMonitor с
        // display:none — админка туда не переехала и панели там не было.
        // Проверяем настоящую страницу админки и заодно то, что мёртвый
        // блок не вернулся.
        $admin = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $admin['code']);
        $this->assertStringContainsString('Управление пользователями', $admin['body']);

        $profile = $this->httpGet('/profile.php');
        $this->assertSame(200, $profile['code']);
        $this->assertStringNotContainsString('userMonitor', $profile['body']);
    }

    public function testNonAdminCannotDeleteUser(): void
    {
        [$victimLogin, $victimId] = $this->createVictim();

        // Логин обычным пользователем
        $page = $this->httpGet('/profile.php');
        $token = $this->extractCsrf($page['body']);
        $r = $this->httpPost('/validation/auth.php', [
            'user_login' => $victimLogin,
            'user_pass' => 'password123',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $r['code']);

        // Попытка админ-операции
        $page2 = $this->httpGet('/profile.php');
        $token2 = $this->extractCsrf($page2['body']);
        $del = $this->httpPost('/profile.php', [
            'deleteUser' => '1',
            'userId' => (string) $victimId,
            'csrf_token' => $token2,
        ]);
        // Обработчик требует $isAdmin — жертва должна выжить
        $this->assertTrue($this->userExists($victimLogin));
        $this->assertContains($del['code'], [200, 302, 403]);
    }

    public function testAdminDeleteUserWorks(): void
    {
        [$victimLogin, $victimId] = $this->createVictim();
        $this->loginAsAdmin();

        // Обработчик deleteUser перенесён в admin.php (ШАГ 4)
        $page = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $page['code']);
        $token = $this->extractCsrf($page['body']);

        $del = $this->httpPost('/admin.php?tab=users', [
            'deleteUser' => '1',
            'userId' => (string) $victimId,
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $del['code']);
        $this->assertFalse($this->userExists($victimLogin));
    }

    public function testAdminAddComponentWorks(): void
    {
        $token = $this->loginAsAdmin();
        $name = 'TestComp ' . substr(bin2hex(random_bytes(4)), 0, 8);

        // Обработчик addComponent перенесён в admin.php (ШАГ 2)
        $page = $this->httpGet('/admin.php?tab=components');
        $this->assertSame(200, $page['code']);
        $token = $this->extractCsrf($page['body']);

        $add = $this->httpPost('/admin.php?tab=components', [
            'addComponent' => '1',
            'nm' => $name,
            'pr' => '1000',
            'col' => '5',
            'cat' => '4',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $add['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT component_id FROM components WHERE component_name = ?", "s", $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $this->assertNotEmpty($row, 'Компонент не создан');
        $cid = (int) $row['component_id'];
        $s = db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $cid);
        $s->execute();
        $mysql->close();
    }
}
