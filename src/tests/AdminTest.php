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
        // 5-f-2c-1: каждый тест входит под admin заново. Лимит попыток входа
        // общий на логин, и без сброса шестой прогон подряд получал 429 -
        // это выглядело как поломка, хотя ломалась только проверка. Раньше
        // чистил вручную перед прогоном.
        $this->clearLoginAttempts('admin');
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

    /**
     * 5-f-2c-1: админ доходит до карточки безопасности прямым адресом.
     *
     * Раньше стояло условие if ($isAdmin && $activeSection !==
     * 'card-security') { $activeSection = 'card-info' }, то есть параметр для
     * админа глушился всегда, а кнопки безопасности в его ветке сайдбара нет.
     * Итог: форма есть, добраться до неё нельзя. Проверено на выводе до
     * правки - у admin карточка всегда со style="display:none;".
     */
    public function testAdminReachesSecurityCardByUrl(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/profile.php?section=security');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('id="card-security" data-section>', $page['body']);
        $this->assertStringContainsString('name="current_password"', $page['body']);
        $this->assertStringContainsString(
            'id="card-info" data-section style="display:none;"',
            $page['body']
        );
    }

    /**
     * 5-f-2c-1: админ видит в сайдбаре ссылку на безопасность, в том числе
     * на страницах админки, где карточек профиля нет вовсе.
     *
     * Старый вариант был кнопкой с data-target, и на дашборде клик по ней
     * прятал секции (их ноль), ничего не показывал и оставался на той же
     * странице.
     */
    public function testAdminSidebarLinksToSecurity(): void
    {
        $this->loginAsAdmin();

        // 6 пунктов: две ссылки на секции профиля, дашборд, аккордеон
        // панели, настройки и выход. Заказы и избранное админу не рисуются.
        $expected = [
            'Личная информация',
            'Безопасность',
            'Дашборд',
            'Панель управления',
            'Настройки сайта',
            'Выйти',
        ];

        foreach (['/profile.php', '/admin.php?tab=dashboard', '/admin.php?tab=users'] as $url) {
            $page = $this->httpGet($url);
            $this->assertSame(200, $page['code'], $url);
            $this->assertSame(
                $expected,
                $this->sidebarItems($page['body']),
                "состав пунктов сайдбара на {$url}"
            );
            $hrefs = $this->sidebarHrefs($page['body']);
            $this->assertContains(
                '/profile.php?section=security',
                $hrefs,
                "на {$url} должна быть ссылка на безопасность"
            );
            $this->assertSame(
                [],
                $this->markupInsideComments($page['body']),
                "на {$url} разметка не должна попадать внутрь HTML-комментария"
            );
        }
    }

    /**
     * 5-f-2c-1: секции, которых на странице нет, откатываются на info,
     * а не оставляют пустую страницу.
     *
     * 7: ключ verification вернулся в список. Он был заведён, пока
     * верификация была отдельной карточкой, и проверка на мусорный ключ
     * тогда была в списке. Потом карточку убрали - блок переехал внутрь
     * card-security как часть безопасности аккаунта, - и ключ стал
     * невалидным снова. То есть значение изменилось дважды, и теперь
     * тест снова его ловит.
     */
    public function testAdminUnknownSectionsFallBackToInfo(): void
    {
        $this->loginAsAdmin();

        foreach (['orders', 'fav', 'verification', 'карточка', 'email_verified'] as $key) {
            $page = $this->httpGet('/profile.php?section=' . rawurlencode($key));
            $this->assertSame(200, $page['code'], $key);
            $this->assertStringContainsString(
                'id="card-info" data-section>',
                $page['body'],
                "?section={$key} должен открыть личную информацию"
            );
        }

        // карточек заказов и избранного у админа на странице просто нет
        $page = $this->httpGet('/profile.php?section=orders');
        $this->assertStringNotContainsString('id="card-builds"', $page['body']);
    }

    /**
     * 7: блок верификации живёт внутри карточки «Безопасность», и админу
     * он тоже доступен - подтвердить собственные контакты он вправе так
     * же, как любой пользователь.
     *
     * Отдельным тестом, потому что отката на info тут не видно: блок
     * нарисован на той же странице, что и форма смены пароля, и если его
     * убрать у админа, то исчезнут только кнопки заявки - а на это нет
     * других тестов.
     */
    public function testAdminSeesVerificationInsideSecurityCard(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/profile.php?section=security');
        $this->assertSame(200, $page['code']);

        $this->assertStringContainsString('id="card-security" data-section>', $page['body']);
        $this->assertStringContainsString('Верификация контактов', $page['body']);
        $this->assertStringContainsString('card-divider', $page['body']);
        $this->assertStringContainsString('class="card-subtitle"', $page['body']);

        // обе строки на месте, и обе с «Не указан»: у админа контактов нет
        $this->assertSame(
            2,
            $this->xpathCount($page['body'], '//section[@id="card-security"]//div[@class="verify-row"]'),
            'в карточке безопасности должно быть две строки верификации'
        );
        $this->assertSame(
            2,
            substr_count($page['body'], '>Не указан</span>'),
            'у админа оба контакта пусты, значит оба бейджа «Не указан»'
        );
        $this->assertSame(
            0,
            $this->xpathCount(
                $page['body'],
                '//section[@id="card-security"]//button[@name="requestEmailVerification"]'
            ),
            'при пустом email кнопки заявки быть не должно'
        );

        // отдельной карточки больше нет
        $this->assertStringNotContainsString('id="card-verification"', $page['body']);
    }
}
