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

        // 7 пунктов: три ссылки на секции профиля, дашборд, аккордеон
        // панели, настройки и выход. «Избранное» теперь и у админа, у
        // него карточка есть: сборки он сохраняет кнопкой «Сохранить»,
        // и без вкладки удалять их было нечем. Заказов по-прежнему нет:
        // заказы удаляются через панель управления.
        $expected = [
            'Личная информация',
            'Безопасность',
            'Избранное',
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
     *
     * 8: ключи orders и fav исключены из мусорных: orders есть в
     * $sectionMap (это настоящая секция card-builds), a fav стала
     * настоящей - card-fav теперь рисуется и админу. Проверка откатов
     * ловит только ключи, которые секцию открыть не могут; ключи,
     * которые открывают, проверяются соседним assert-группой ниже.
     */
    public function testAdminUnknownSectionsFallBackToInfo(): void
    {
        $this->loginAsAdmin();

        foreach (['verification', 'карточка', 'email_verified'] as $key) {
            $page = $this->httpGet('/profile.php?section=' . rawurlencode($key));
            $this->assertSame(200, $page['code'], $key);
            $this->assertStringContainsString(
                'id="card-info" data-section>',
                $page['body'],
                "?section={$key} должен открыть личную информацию"
            );
        }

        // секция fav теперь настоящая: card-fav у админа отрисован и открывается
        $page = $this->httpGet('/profile.php?section=fav');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('id="card-fav" data-section>', $page['body'], 'у админа должна открыться карточка избранного');

        // карточек заказов у админа на странице по-прежнему нет
        $page = $this->httpGet('/profile.php?section=orders');
        $this->assertStringNotContainsString('id="card-builds"', $page['body']);
        $this->assertStringContainsString(
            'id="card-info" data-section>',
            $page['body'],
            'section=orders откатывается на info — у админа нет card-builds'
        );
    }

    /**
     * 7: у админа блок верификации внутри карточки «Безопасность».
     *
     * Отдельным тестом, потому что отката на info тут не видно: блок
     * нарисован на той же странице, что и форма смены пароля, и если его
     * убрать, то исчезнут только кнопки заявки - а на это не было бы
     * ни одного теста.
     *
     * Проверка пустых контактов сделана по каждой строке отдельно, а не
     * счётом бейджей «Не указан». Первый вариант считал, что у админа
     * оба контакта пусты, и падал, как только админу завели email: тест
     * зависел от данных пользователя, а не от разметки.
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
        $this->assertSame(
            2,
            $this->xpathCount($page['body'], '//section[@id="card-security"]//div[@class="verify-row"]'),
            'в карточке безопасности должно быть две строки верификации'
        );

        // отдельной карточки больше нет
        $this->assertStringNotContainsString('id="card-verification"', $page['body']);

        // Через .//, а не прямым потомком: verify-row__value лежит внутри
        // verify-row__info, то есть на уровень глубже. С прямым
        // предикатом запрос молча возвращал ноль строк, и сравнение двух
        // нулей проходило - то есть проверка не проверяла ничего.
        // строка без контакта: бейдж «Не указан» и ноль кнопок заявки
        $emptyRows = $this->xpathCount(
            $page['body'],
            '//section[@id="card-security"]//div[@class="verify-row"]'
            . '[.//div[@class="verify-row__value"][normalize-space(text())="Не указан"]]'
        );
        $this->assertSame(
            0,
            $this->xpathCount(
                $page['body'],
                '//section[@id="card-security"]//div[@class="verify-row"]'
                . '[.//div[@class="verify-row__value"][normalize-space(text())="Не указан"]]//button'
            ),
            'при пустом контакте кнопки заявки быть не должно'
        );
        $this->assertSame(
            $emptyRows,
            $this->xpathCount(
                $page['body'],
                '//section[@id="card-security"]//div[@class="verify-row"]'
                . '[div[@class="verify-row__status"]/span[normalize-space(text())="Не указан"]]'
            ),
            'пустому контакту соответствует бейдж «Не указан»'
        );
    }
    /**
     *
     * Прямой INSERT, а не регистрация через форму: полей email и телефона
     * в форме регистрации нет, а нужны все четыре сочетания состояний -
     * «нет контакта», «есть заявка», «подтверждён».
     *
     * @param array<string, mixed> $extra дополнительные колонки users
     */
    private function makeUserWithContacts(string $prefix, array $extra = []): string
    {
        $login = $this->uniqueLogin($prefix);
        $this->trackCleanup($login);

        $cols = [
            'user_name' => 'Проверка',
            'user_login' => $login,
            'user_pass' => password_hash('password123', PASSWORD_BCRYPT),
            'user_group' => 'user',
        ];
        foreach ($extra as $name => $value) {
            $cols[$name] = $value;
        }

        $names = array_keys($cols);
        $placeholders = array_fill(0, count($names), '?');
        $types = str_repeat('s', count($names));

        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO users (" . implode(', ', array_map(static fn($n) => "`$n`", $names)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')',
            $types,
            ...array_values($cols)
        );
        $stmt->execute();
        $mysql->close();

        return $login;
    }

    /**
     * 7: прочитать флаги верификации пользователя прямо из базы.
     *
     * @return array{email_verified: string, email_verification_requested: string, phone_verified: string, phone_verification_requested: string}
     */
    private function verificationFlags(string $login): array
    {
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            "SELECT email_verified, email_verification_requested, phone_verified, phone_verification_requested
               FROM users WHERE user_login = ?",
            "s",
            $login
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();

        $this->assertIsArray($row, 'пользователь должен существовать');
        return $row;
    }

    /**
     * 7: админ подтверждает email, когда заявка есть.
     *
     * Полный цикл: пользователь оставил заявку, админ нажал «Подтвердить».
     */
    public function testAdminCanApproveEmailWhenRequested(): void
    {
        $this->loginAsAdmin();

        $login = $this->makeUserWithContacts('ver_ok_', [
            'user_email' => 'ver_ok@test.local',
            'email_verification_requested' => 1,
        ]);

        $before = $this->verificationFlags($login);
        $this->assertSame('0', (string) $before['email_verified']);
        $this->assertSame('1', (string) $before['email_verification_requested']);

        $page = $this->httpGet('/admin.php?tab=users');
        $r = $this->httpPost('/admin.php?tab=users', [
            'approveEmail' => '1',
            'userId' => (string) $this->userId($login),
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code'], 'подтверждение должно редиректить');

        $after = $this->verificationFlags($login);
        $this->assertSame('1', (string) $after['email_verified'], 'email должен стать подтверждённым');
        $this->assertSame(
            '0',
            (string) $after['email_verification_requested'],
            'заявка должна сниматься вместе с подтверждением'
        );
    }

    /**
     * 7: прямой POST без заявки не подтверждает ничего.
     *
     * Второй уровень защиты. В интерфейсе кнопка без заявки disabled, но
     * disabled - это атрибут в браузере, а не в протоколе: форму можно
     * отправить руками. Условие email_verification_requested = 1 стоит в
     * WHERE, поэтому UPDATE затрагивает ноль строк.
     */
    public function testAdminCannotApproveEmailWithoutRequest(): void
    {
        $this->loginAsAdmin();

        $login = $this->makeUserWithContacts('ver_no_', [
            'user_email' => 'ver_no@test.local',
        ]);

        $page = $this->httpGet('/admin.php?tab=users');
        $r = $this->httpPost('/admin.php?tab=users', [
            'approveEmail' => '1',
            'userId' => (string) $this->userId($login),
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        // 302, а не 403: обработчик отработал и честно сделал ноль строк.
        // Различие видно только в базе, поэтому проверяем именно её.
        $this->assertSame(302, $r['code']);

        $flags = $this->verificationFlags($login);
        $this->assertSame('0', (string) $flags['email_verified'], 'без заявки подтверждать нельзя');
        $this->assertSame('0', (string) $flags['email_verification_requested']);
    }

    /**
     * 7: то же для телефона, и тоже без заявки.
     */
    public function testAdminCannotApprovePhoneWithoutRequest(): void
    {
        $this->loginAsAdmin();

        $login = $this->makeUserWithContacts('ver_nop_', [
            'user_number' => '+79990001122',
        ]);

        $page = $this->httpGet('/admin.php?tab=users');
        $r = $this->httpPost('/admin.php?tab=users', [
            'approvePhone' => '1',
            'userId' => (string) $this->userId($login),
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        $flags = $this->verificationFlags($login);
        $this->assertSame('0', (string) $flags['phone_verified'], 'без заявки подтверждать нельзя');
        $this->assertSame('0', (string) $flags['phone_verification_requested']);
    }

    /**
     * 7: подтверждение требует CSRF-токена.
     */
    public function testApproveVerificationRequiresCsrf(): void
    {
        $this->loginAsAdmin();

        $login = $this->makeUserWithContacts('ver_csrf_', [
            'user_email' => 'ver_csrf@test.local',
            'email_verification_requested' => 1,
        ]);

        $r = $this->httpPost('/admin.php?tab=users', [
            'approveEmail' => '1',
            'userId' => (string) $this->userId($login),
            'csrf_token' => 'stale-token',
        ]);
        $this->assertSame(403, $r['code']);

        $flags = $this->verificationFlags($login);
        $this->assertSame('0', (string) $flags['email_verified'], 'без токена подтверждать нельзя');
    }

    /**
     * 7: обычный пользователь не может подтвердить контакт.
     *
     * Форма approveEmailForm живёт в админке, но обработчик закрыт
     * $isAdmin, поэтому прямой POST от пользователя ничего не меняет.
     */
    public function testRegularUserCannotApproveVerification(): void
    {
        $login = $this->makeUserWithContacts('ver_reg_', [
            'user_email' => 'ver_reg@test.local',
            'email_verification_requested' => 1,
        ]);

        $this->loginAs($login, 'password123');
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);

        $r = $this->httpPost('/admin.php?tab=users', [
            'approveEmail' => '1',
            'userId' => (string) $this->userId($login),
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertContains($r['code'], [302, 403], 'пользователь не должен получить подтверждение');

        $flags = $this->verificationFlags($login);
        $this->assertSame('0', (string) $flags['email_verified'], 'пользователь не может подтвердить сам');
    }

    /**
     * 7: в модалке пользователя есть блок верификации с обеими строками.
     *
     * Проверяется разбором DOM, а не поиском строки в ответе: незакрытый
     * HTML-комментарий не виден в ответе, но элементы внутри него не
     * работают - такая ошибка уже была с сайдбаром.
     */
    public function testUserModalHasVerificationBlock(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $page['code']);

        $this->assertSame(2, $this->xpathCount($page['body'], '//div[@class="verify-admin-row"]'));
        $this->assertSame(1, $this->xpathCount($page['body'], '//h3[normalize-space()="Верификация"]'));

        // кнопки объявлены type="button": внутри формы модалки кнопка
        // без типа отправила бы форму редактирования пользователя
        foreach (['adminApproveEmailBtn', 'adminApprovePhoneBtn'] as $id) {
            $attrs = $this->xpathAttrs($page['body'], "//button[@id='{$id}']");
            $this->assertSame('button', $attrs['type'], "кнопка {$id} не должна быть submit");
            $this->assertArrayHasKey('disabled', $attrs, "кнопка {$id} должна быть изначально выключена");
        }

        // формы лежат вне dialog: вложенных форм не бывает
        foreach (['approveEmailForm', 'approvePhoneForm'] as $id) {
            $attrs = $this->xpathAttrs($page['body'], "//form[@id='{$id}']");
            $this->assertSame('/admin.php?tab=users', $attrs['action']);
            $this->assertArrayHasKey('hidden', $attrs);
            $this->assertSame(0, $this->xpathCount(
                $page['body'],
                "//form[@id='{$id}']/ancestor::dialog"
            ), "форма {$id} не должна лежать внутри dialog");
        }

        // Флаги доезжают до строк таблицы: без них кнопка всегда была бы
        // disabled и блок выглядел бы работающим, но ничего не делал.
        //
        // Проверяется «каждая строка», а не число строк: на вкладке
        // пользователей столько же людей, сколько строк, и жёсткое
        // число завязывало бы тест на состав базы. Первый вариант ждал
        // ровно одну и падал, когда в базе стало трое пользователей.
        $allRows = $this->xpathCount($page['body'], '//tr[@data-row]');
        $this->assertGreaterThan(0, $allRows, 'в таблице пользователей должны быть строки');
        $this->assertSame(
            $allRows,
            $this->xpathCount(
                $page['body'],
                "//tr[@data-row][contains(@data-row, 'email_verification_requested')]"
                . "[contains(@data-row, 'phone_verification_requested')]"
            ),
            'флаги верификации должны быть в data-row каждого пользователя'
        );
    }

    /**
     * 7: кнопки подтверждения - иконки, а не текстовые кнопки.
     *
     * Текстовая «Подтвердить» вместе с бейджем «Заявка от пользователя»
     * не влезала в колонку строки верификации и выходила за рамку
     * модалки: измерялось 829px правого края кнопки при 760px правого
     * края диалога, то есть на 69px наружу.
     *
     * Иконка даёт 32x32 вместо 103x44 и укладывается. Проверяются все
     * свойства, по которым видно, что это именно иконка: класс,
     * подпись, aria-подпись, наличие глифа и отсутствие текста внутри.
     */
    public function testApproveButtonsAreIcons(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $page['code']);

        $dom = $this->loadDom($page['body']);
        $xpath = new DOMXPath($dom);

        foreach ([
            'adminApproveEmailBtn' => 'Подтвердить email',
            'adminApprovePhoneBtn' => 'Подтвердить телефон',
        ] as $id => $label) {
            $nodes = $xpath->query("//button[@id='{$id}']");
            $this->assertSame(1, $nodes->length, "кнопка {$id} должна быть одна");

            /** @var DOMElement $button */
            $button = $nodes->item(0);

            $this->assertStringContainsString(
                'btn-icon',
                $button->getAttribute('class'),
                "кнопка {$id} должна быть иконкой .btn-icon"
            );
            $this->assertStringContainsString(
                'btn-icon--success',
                $button->getAttribute('class'),
                "кнопка {$id} должна быть зелёной"
            );
            $this->assertSame($label, $button->getAttribute('title'), "у кнопки {$id} должен быть title");
            $this->assertSame($label, $button->getAttribute('aria-label'), "у кнопки {$id} должен быть aria-label");

            // глиф-галочка внутри
            $this->assertSame(
                1,
                $xpath->query("//button[@id='{$id}']//polyline")->length,
                "в кнопке {$id} должен быть глиф"
            );

            // текста внутри быть не должно: иконка без подписи, только
            // title и aria-label. Пока был текст, он и выдавливал строку.
            $this->assertSame(
                '',
                trim(preg_replace('/\s+/u', ' ', $button->textContent)),
                "в кнопке {$id} не должно быть текста"
            );
        }
    }

    /**
     * 7: user_id пользователя - нужно для POST в тестах выше.
     */
    private function userId(string $login): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();

        $this->assertIsArray($row, 'пользователь должен существовать');
        return (int) $row['user_id'];
    }

    /**
     * 7: модалка разложена в две колонки, по смыслу секций.
     *
     * Слева личные данные (Основные данные, Адрес), справа аккаунт и
     * верификация (Права, Контакты, Верификация). Проверяется порядок и
     * состав, а не только наличие обёртки: раскладку легко сломать,
     * переставив колонки местами, и по числу блоков это не видно.
     *
     * Заголовок и кнопки формы лежат вне сетки, иначе раскладка на две
     * колонки растянула бы и их.
     */
    public function testUserModalIsTwoColumnsByMeaning(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=users');
        $this->assertSame(200, $page['code']);
        $html = $page['body'];

        $this->assertSame(1, $this->xpathCount($html, '//div[@class="user-modal-grid"]'));
        $this->assertSame(2, $this->xpathCount($html, '//div[@class="user-modal-col"]'));

        $left = $this->xpathCount(
            $html,
            '//div[@class="user-modal-col"][1]//h3[normalize-space()="Основные данные"]'
        );
        $right = $this->xpathCount(
            $html,
            '//div[@class="user-modal-col"][2]//h3[normalize-space()="Верификация"]'
        );
        $this->assertSame(1, $left, 'Основные данные должны быть в левой колонке');
        $this->assertSame(1, $right, 'Верификация должна быть в правой колонке');

        // в правой колонке порядок Права -> Контакты -> Верификация
        $this->assertSame(
            ['Права', 'Контакты', 'Верификация'],
            $this->modalColumnHeadings($html, 2),
            'состав правой колонки'
        );
        $this->assertSame(
            ['Основные данные', 'Адрес'],
            $this->modalColumnHeadings($html, 1),
            'состав левой колонки'
        );

        // заголовок и действия вне сетки
        $this->assertSame(0, $this->xpathCount($html, '//div[@class="user-modal-grid"]//h2'));
        $this->assertSame(0, $this->xpathCount($html, '//div[@class="user-modal-grid"]//div[@class="modal-actions"]'));
        $this->assertSame(
            1,
            $this->xpathCount($html, '//form[contains(@class, "modal-form--user")]/div[@class="modal-actions"]')
        );

        // старой раскладки на самой форме больше нет
        $this->assertSame(0, $this->xpathCount($html, '//dialog[@id="editUserModal"]//form[@class="modal-form"]'), 'старой раскладки на форме быть не должно');
    }

    /**
     * 7: заголовки секций колонки по порядку.
     *
     * @return string[]
     */
    private function modalColumnHeadings(string $html, int $index): array
    {
        $dom = $this->loadDom($html);
        $xp = new DOMXPath($dom);
        $nodes = $xp->query('//div[@class="user-modal-col"][' . $index . ']//h3');
        $this->assertInstanceOf(DOMNodeList::class, $nodes);

        $headings = [];
        foreach ($nodes as $node) {
            $headings[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }
        return $headings;
    }

    /**
     * 7: бейдж без текста не рисуется.
     *
     * Баг из скриншота пользователя: в состоянии «контакта нет» рядом с
     * серым курсивным «Не указан» стояла пустая серая таблетка. Бейдж там
     * намеренно без текста - ставить «Не подтверждён» нельзя, ведь
     * подтверждать нечего, - но пустой элемент с padding 4px 10px, фоном и
     * радиусом всё равно рисует плашку 20x8.
     *
     * Проверяется правило в CSS, а не только разметка: баг жил именно в
     * стилях, и правка разметки его бы не убрала.
     */
    public function testEmptyBadgeHasNoStyles(): void
    {
        $css = $this->readAsset('/assets/css/base.css');

        $this->assertMatchesRegularExpression(
            '/\.badge:empty\s*\{[^}]*display:\s*none/',
            $css,
            'пустой бейдж должен скрываться правилом .badge:empty'
        );

        // и он должен быть в списке правил, а не в несуществующем файле
        $this->assertStringContainsString('.badge:empty', $css);

        // бейджи в модалке объявлены пустыми, без пробела: :empty не ловит
        // пробельный текст, и правило перестало бы работать
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=users');

        foreach (['adminUserEmailStatus', 'adminUserPhoneStatus'] as $id) {
            $attrs = $this->xpathAttrs($page['body'], "//span[@id='{$id}']");
            $this->assertSame('badge', $attrs['class'], "{$id} должен быть без модификаторов");
            $this->assertSame(
                0,
                $this->xpathCount($page['body'], "//span[@id='{$id}']/text()[normalize-space()]"),
                "{$id} в разметке должен быть пустым"
            );
        }
    }

    /**
     * 7: содержимое файла ассета.
     */
    private function readAsset(string $relative): string
    {
        $path = dirname(__DIR__) . $relative;
        $this->assertFileExists($path, "файл {$relative} должен существовать");
        return (string) file_get_contents($path);
    }

    /**
     * 8: загрузка изображения корпуса отвергает не-изображение.
     */
    public function testUploadRejectsNonImage(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=components');
        $token = $this->extractCsrf($page['body']);
        
        // Создаём временный PHP-файл
        $tmp = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmp, '<?php echo "hack";');
        
        $response = $this->httpPostMultipart('/admin.php?tab=components', [
            'csrf_token' => $token,
            'addComponent' => '1',
            'editComponentId' => '0',
            'nm' => 'Test Case',
            'pr' => '1000',
            'col' => '10',
            'cat' => '6',  // Корпус
            'image_file' => [
                'name' => 'hack.php',
                'type' => 'text/x-php',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
            ],
        ]);

        // 302 на вкладку components с ошибкой mime
        $this->assertSame(302, $response['code']);
        $this->assertStringContainsString('error=mime', $response['location']);
    }

    /**
     * 8: загрузка изображения корпуса отвергает файл больше 10 МБ.
     *
     * Лимит поднят до 10 МБ (Stage 8-финал). Файл делаем настоящим JPEG
     * чуть больше лимита: иначе сначала сработает не наша проверка размера,
     * а finfo/getimagesize или INI-лимит, и тест проверял бы не то.
     */
    public function testUploadRejectsOversized(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=components');
        $token = $this->extractCsrf($page['body']);

        // настоящий JPEG 10 МБ + 1 байт: GD откроет его, но наш
        // размерный лимит сработает раньше конвертации
        $tmp = tempnam(sys_get_temp_dir(), 'test');
        $im = imagecreatetruecolor(200, 200);
        imagejpeg($im, $tmp, 100);
        imagedestroy($im);
        // добиваем до > 10 МБ полезной нагрузкой после EOI-маркера:
        // finfo/getimagesize читают заголовок, декодер тоже переживёт
        $handle = fopen($tmp, 'ab');
        fwrite($handle, str_repeat('A', 10 * 1024 * 1024));
        fclose($handle);

        $response = $this->httpPostMultipart('/admin.php?tab=components', [
            'csrf_token' => $token,
            'addComponent' => '1',
            'editComponentId' => '0',
            'nm' => 'Test Case',
            'pr' => '1000',
            'col' => '10',
            'cat' => '6',  // Корпус
            'image_file' => [
                'name' => 'large.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
            ],
        ]);

        // 302 на вкладку components с ошибкой размера
        $this->assertSame(302, $response['code']);
        $this->assertStringContainsString('error=size', $response['location']);
    }

    /**
     * 8: файловый менеджер показывает файлы и сводные счётчики.
     *
     * Типы статусных бейджей зависят от состава данных (после чистки PNG
     * все файлы привязаны), поэтому проверяем только живую разметку карточек
     * и работы сводки, а механику фильтра - по появлению кнопки сброса.
     */
    public function testFileManagerShowsFiles(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=files');

        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('Изображения', $page['body']);
        $this->assertStringContainsString('всего файлов', $page['body']);
        $this->assertStringContainsString('file-card', $page['body']);

        // на карточках есть статусные бейджи хотя бы одного вида
        $hasBadge = str_contains($page['body'], 'badge--success')
                 || str_contains($page['body'], 'badge--warning');
        $this->assertTrue($hasBadge, 'карточки файлов должны нести статусный бейдж');

        // сводка показывает счётчик неиспользуемых (число, может быть 0)
        $this->assertMatchesRegularExpression(
            '/files-stat__label">не используется/u',
            $page['body'],
            'в сводке должен быть блок «не используется»'
        );

        // при активном фильтре появляется кнопка сброса
        $p2 = $this->httpGet('/admin.php?tab=files&filter=used');
        $this->assertSame(200, $p2['code']);
        $this->assertStringContainsString('>Сбросить</a>', $p2['body']);
    }

    /**
     * 8: удаление файла снимает привязки.
     *
     * Багрепорт: «после удаления изображения привязка всё ещё остаётся,
     * хотя должна полностью пропадать, и корпус должен отображаться как
     * непривязанный». Причина была в обработчике: он делал unlink() и
     * компоненты.image не трогал, поэтому в базе оставался путь в
     * несуществующий файл, превью было битым, а счётчик «привязано к
     * компонентам» врал.
     *
     * Здесь проверяется полный сценарий на двух корпусах с общим
     * файлом: файл удалён с диска, оба корпуса получили image = NULL,
     * в редиректе видно число снятых привязок.
     *
     * Фикстура своя: боевые картинки тест не трогает.
     */
    public function testDeleteFileClearsBindings(): void
    {
        $this->loginAsAdmin();

        $casesDir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-unlink-' . uniqid() . '.png';
        $path = $casesDir . $filename;
        file_put_contents($path, 'X');

        // два корпуса на одном файле - чтобы проверить, что снимаются все
        $ids = [];
        $mysql = connect();
        foreach (['Test Unlink A', 'Test Unlink B'] as $name) {
            $stmt = db_prepare($mysql, "INSERT INTO components (component_name, category_id, component_price, amount, image) VALUES (?, 6, 1000, 1, ?)", "ss", $name, 'assets/images/cases/' . $filename);
            $stmt->execute();
            $ids[] = (int) $stmt->insert_id;
        }
        $mysql->close();

        try {
            $page = $this->httpGet('/admin.php?tab=files');
            $response = $this->httpPost('/admin.php?tab=files', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'deleteFile' => '1',
                'filename' => $filename,
            ]);

            $this->assertSame(302, $response['code']);
            $this->assertStringNotContainsString('error=', $response['location']);
            $this->assertStringContainsString(
                'unlinked=2',
                $response['location'],
                'редирект должен сообщать, сколько привязок снято'
            );
            $this->assertFileDoesNotExist($path, 'файл должен исчезнуть с диска');

            $mysql = connect();
            $check = db_prepare($mysql, "SELECT component_id, image FROM components WHERE component_id IN (?, ?)", "ii", $ids[0], $ids[1]);
            $check->execute();
            $rows = $check->get_result()->fetch_all(MYSQLI_ASSOC);
            $mysql->close();

            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                $this->assertNull(
                    $row['image'],
                    'привязка должна пропасть: корпус должен стать непривязанным'
                );
            }

            // файл больше не должен показываться как используемый
            $page2 = $this->httpGet('/admin.php?tab=files');
            $this->assertStringNotContainsString($filename, $page2['body'], 'файл не должен остаться в списке');
        } finally {
            $mysql = connect();
            foreach ($ids as $id) {
                db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $id)->execute();
            }
            $mysql->close();
            @unlink($path);
        }
    }

    /**
     * 8: удаление файла, которого уже нет на диске, тоже снимает привязки.
     *
     * Случай реальный: файл мог быть снесён вручную или прошлым
     * удалением, а привязки остались. Без очистки корпус навсегда числился
     * бы привязанным к пустоте.
     */
    public function testDeleteFileClearsBindingsWhenFileAlreadyGone(): void
    {
        $this->loginAsAdmin();

        $casesDir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-gone-' . uniqid() . '.png';
        // файла на диске нет - только привязка в базе

        $mysql = connect();
        $stmt = db_prepare($mysql, "INSERT INTO components (component_name, category_id, component_price, amount, image) VALUES (?, 6, 1000, 1, ?)", "ss", 'Test Gone Case', 'assets/images/cases/' . $filename);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $mysql->close();

        try {
            $this->assertFileDoesNotExist($casesDir . $filename);

            $page = $this->httpGet('/admin.php?tab=files');
            $r = $this->httpPost('/admin.php?tab=files', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'deleteFile' => '1',
                'filename' => $filename,
            ]);
            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString('unlinked=1', $r['location']);

            $mysql = connect();
            $check = db_prepare($mysql, "SELECT image FROM components WHERE component_id = ?", "i", $id);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $mysql->close();
            $this->assertNull($row['image'], 'привязка к несуществующему файлу должна сниматься');
        } finally {
            $mysql = connect();
            db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $id)->execute();
            $mysql->close();
            @unlink($casesDir . $filename);
        }
    }

    /**
     * 8: карточка файла предупреждает о привязях до удаления.
     *
     * Без этого модалка сообщала бы «файл удалится» и не дала бы админу
     * понять, что у конкретных корпусов сломается картинка.
     */
    public function testFileCardCarriesUsedByList(): void
    {
        $this->loginAsAdmin();

        $casesDir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-card-' . uniqid() . '.png';
        $path = $casesDir . $filename;
        file_put_contents($path, 'X');

        $mysql = connect();
        $ins = db_prepare($mysql, "INSERT INTO components (component_name, category_id, component_price, amount, image) VALUES (?, 6, 1000, 1, ?)", "ss", 'Test Card Case', 'assets/images/cases/' . $filename);
        $ins->execute();
        $componentId = (int) $ins->insert_id;
        $mysql->close();

        try {
            $page = $this->httpGet('/admin.php?tab=files');
            $this->assertSame(200, $page['code']);

            // кнопка удаления этой карточки несёт used=1 и имя корпуса
            $this->assertMatchesRegularExpression(
                '/data-file="' . preg_quote($filename, '/') . '"[^>]*data-used="1"/u',
                $page['body'],
                'кнопка удаления привязанного файла должна быть помечена data-used="1"'
            );
            $this->assertStringContainsString(
                'Test Card Case',
                $page['body'],
                'модалка должна знать имя компонента, у которого сломается картинка'
            );
        } finally {
            $mysql = connect();
            $del = db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $componentId);
            $del->execute();
            $mysql->close();
            @unlink($path);
        }
    }

    /**
     * 8: неиспользуемый файл удаляется напрямую, одним POST.
     */
    public function testDeleteFileWorks(): void
    {
        $this->loginAsAdmin();

        $dir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-delete-' . uniqid() . '.png';
        $path = $dir . $filename;
        file_put_contents($path, 'dummy');

        try {
            $page = $this->httpGet('/admin.php?tab=files');
            $r = $this->httpPost('/admin.php?tab=files', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'deleteFile' => '1',
                'filename' => $filename,
            ]);
            $this->assertSame(302, $r['code']);
            $this->assertStringNotContainsString('error=', $r['location']);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 8-финал: полный цикл удаления через живой HTTP.
     *
     * Файл кладётся в cases/ через общий том, удаляется POSTом с
     * deleteFile=1 (как это делает скрытая форма). CSRF берётся заново
     * перед POST: обработчик его ротирует.
     */
    public function testDeleteFileCycle(): void
    {
        $this->loginAsAdmin();

        $casesDir = dirname(__DIR__) . '/assets/images/cases/';

        $act = function (string $file): array {
            $page = $this->httpGet('/admin.php?tab=files');
            return $this->httpPost('/admin.php?tab=files', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'deleteFile' => '1',
                'filename' => $file,
            ]);
        };

        // 1) orphan удаляется одним действием
        $name = 'test-del-' . uniqid() . '.png';
        file_put_contents($casesDir . $name, str_repeat('X', 100));
        $r = $act($name);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('error=', $r['location']);
        $this->assertFileDoesNotExist($casesDir . $name);

        // 2) несуществующий файл - тихий редирект, без ошибки
        $r = $act($name);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('error=', $r['location']);

        // 3) traversal: basename() должен срезать путь и не дать удалить
        //    файл за пределами cases/
        $outside = dirname($casesDir) . 'test-outside-' . uniqid() . '.png';
        file_put_contents($outside, 'X');

        try {
            $r = $act('../' . basename($outside));
            $this->assertSame(302, $r['code']);
            $this->assertFileExists($outside, 'файл вне cases/ удалять нельзя');
        } finally {
            @unlink($outside);
        }
    }

    /**
     * 8: карточка файла без меню - привязка кликом по картинке,
     * удаление отдельной иконкой.
     */
    public function testFileCardHasNoKebabMenu(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=files');
        $this->assertSame(200, $page['code']);

        // привязка и удаление на месте
        $this->assertMatchesRegularExpression(
            '/data-action="attach-file"/u',
            $page['body'],
            'клик по картинке должен привязывать файл'
        );
        $this->assertMatchesRegularExpression(
            '/data-action="delete-file"/u',
            $page['body'],
            'иконка удаления должна быть на карточке'
        );

        // kebab-меню убрано вместе с копированием URL и архивом
        $this->assertStringNotContainsString('toggle-file-menu', $page['body']);
        $this->assertStringNotContainsString('file-card__dropdown', $page['body']);
        $this->assertStringNotContainsString('data-action="copy-file-url"', $page['body']);
        $this->assertStringNotContainsString('data-action="archive-file"', $page['body']);
        $this->assertStringNotContainsString('data-action="restore-file"', $page['body']);
        $this->assertStringNotContainsString('Скопировать URL', $page['body']);
        $this->assertStringNotContainsString('>Архив<', $page['body']);
        $this->assertStringNotContainsString('в архиве', $page['body']);
        $this->assertStringNotContainsString('need_archive', $page['body']);
    }

    /**
     * 7: сохранение компонента возвращает на ту же страницу списка.
     *
     * Раньше редирект был жёстко '/admin.php?tab=components', поэтому
     * после сохранения вся таблица сбрасывалась к исходному виду: фильтр
     * по категории, поиск и сортировка пропадали. Фильтры передаются в
     * POST-поле return_params, а собираются обратно по белому списку.
     *
     * Проверяется и то, что редирект не ломается (никаких Warning и
     * «headers already sent»): переменная адреса возврата обязана быть
     * объявлена до строки с header().
     */
    public function testSaveComponentKeepsListFilters(): void
    {
        $this->loginAsAdmin();

        // берём реальный корпус из списка с фильтром
        $list = $this->httpGet('/admin.php?tab=components&cat=6&sort=price_asc');
        $this->assertSame(200, $list['code']);
        $this->assertMatchesRegularExpression('/data-component=/u', $list['body'], 'список корпусов должен быть непустым');

        preg_match("/data-component='([^']+)'/u", $list['body'], $m);
        $row = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($row);

        // разметка обязана нести текущие фильтры в скрытом поле
        $this->assertStringContainsString(
            'name="return_params" value="cat=6&amp;sort=price_asc"',
            $list['body'],
            'форма сохранения должна передавать активные фильтры'
        );

        // сохраняем как есть (те же значения) - проверяем адрес возврата
        $page = $this->httpGet('/admin.php?tab=components&cat=6&sort=price_asc');
        $r = $this->httpPost('/admin.php?tab=components', [
            'csrf_token' => $this->extractCsrf($page['body']),
            'addComponent' => '1',
            'editComponentId' => (string) $row['id'],
            'return_params' => 'cat=6&sort=price_asc',
            'nm' => (string) $row['name'],
            'pr' => (string) $row['price'],
            'col' => (string) $row['amount'],
            'cat' => '6',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('tab=components', $r['location']);
        $this->assertStringContainsString(
            'cat=6',
            $r['location'],
            'фильтр по категории должен сохраниться после сохранения'
        );
        $this->assertStringContainsString(
            'sort=price_asc',
            $r['location'],
            'сортировка должна сохраниться после сохранения'
        );
        $this->assertStringNotContainsString(
            'Warning',
            $r['body'],
            'никаких предупреждений в ответе быть не должно'
        );
    }

    /**
     * 5: пакетная загрузка на вкладке «Изображения».
     *
     * Кнопка «Загрузить изображения» отправляет пачку файлов одним
     * POST. Проверяются три вещи: уникальные файлы сохранились, дубль по
     * содержимому не плодит копию, а не-картинка отклонена с
     * сообщением. Имя на диске берётся из имени файла, поэтому ищутся
     * по slug, а не по uniqid.
     */
    public function testBatchUploadSavesFiles(): void
    {
        $this->loginAsAdmin();

        $casesDir = dirname(__DIR__) . '/assets/images/cases/';
        $tag = 'batchprobe' . substr((string) uniqid(), -6);

        // три картинки с разным содержимым и один мусорный файл
        $paths = [];
        $payload = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = sys_get_temp_dir() . "/{$tag}-{$i}.png";
            $im = imagecreatetruecolor(80, 80);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagefilledrectangle($im, 20, 20, 60, 60, imagecolorallocate($im, $i * 80, 200, 40));
            imagepng($im, $path);
            imagedestroy($im);
            $paths[] = $path;
            $payload[] = ['name' => basename($path), 'type' => 'image/png', 'tmp_name' => $path];
        }
        // дубль первого файла - должен отсеяться дедупликацией
        $payload[] = ['name' => $tag . '-copy.png', 'type' => 'image/png', 'tmp_name' => $paths[0]];

        $bad = sys_get_temp_dir() . "/{$tag}-bad.png";
        file_put_contents($bad, 'not an image');
        $payload[] = ['name' => $tag . '-bad.png', 'type' => 'image/png', 'tmp_name' => $bad];

        try {
            $page = $this->httpGet('/admin.php?tab=files');
            $this->assertSame(200, $page['code']);

            $r = $this->httpPostMultipart('/admin.php?tab=files', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'batchUpload' => '1',
                'files' => $payload,
            ]);

            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString(
                'uploaded=3',
                $r['location'],
                'три уникальных файла должны сохраниться, дубль отсеяться'
            );
            $this->assertStringContainsString('skipped=2', $r['location'], 'дубль и мусор должны быть отклонены');

            // на диске ровно три файла, все .png
            $created = glob($casesDir . $tag . '*.png') ?: [];
            $this->assertCount(3, $created, 'на диске должно появиться ровно три файла');
            foreach ($created as $file) {
                $this->assertStringEndsWith('.png', $file, 'JPG на входе обязан сохраниться как PNG');
                $this->assertFileExists($file);
            }
            $this->assertFileDoesNotExist(
                $casesDir . $tag . '-bad.png',
                'файл не-картинка не должен появляться в каталоге'
            );
        } finally {
            // Уборка по тегу, а не по списку из тела теста: если тест
            // упал на проверке Location, до присваивания $created дело
            // не дошло бы и файлы остались бы в каталоге. А остатки
            // ломают следующий прогон - картинки у него одинаковые, и
            // дедупликация назвала бы их дублями.
            foreach (glob($casesDir . $tag . '*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (array_merge($paths, [$bad]) as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Stage 9: загрузка логотипа и favicon из формы настроек.
     *
     * Проверяется полный путь: файл уходит в branding/, уменьшается до
     * 400px по ширине с сохранением пропорций, а site_settings получает
     * новый путь с версией. Отдельно favicon- SVG: он кладётся как есть
     * (GD конвертирует в растр, а растр под именем .svg браузер не
     * нарисует) и при этом сбрасывает png-ключ - иначе браузер
     * продолжал бы показывать старую иконку.
     *
     * Настройки восстанавливаются в finally.
     */
    public function testBrandingUploadSavesLogoAndFavicon(): void
    {
        $this->loginAsAdmin();

        require_once dirname(__DIR__) . '/modules/site.php';

        $mysql = connect();
        $before = [];
        // Все ключи брендинга, а не только картинки: обработчик
        // сохраняет весь белый список, поэтому тест обязан вернуть всё,
        // что задевает.
        foreach (['site_logo_url', 'site_favicon_url', 'site_favicon_png_url', 'site_name'] as $key) {
            $st = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", $key);
            $st->execute();
            $before[$key] = (string) ($st->get_result()->fetch_row()[0] ?? '');
        }
        $mysql->close();

        $brandingDir = dirname(__DIR__) . '/assets/images/branding';

        // логотип 900x300 - шире лимита, значит должен уменьшиться
        $logo = sys_get_temp_dir() . '/brand-test-logo.png';
        $im = imagecreatetruecolor(900, 300);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 40, 110, 260, 190, imagecolorallocate($im, 30, 90, 200));
        imagepng($im, $logo);
        imagedestroy($im);

        $favicon = sys_get_temp_dir() . '/brand-test-favicon.svg';
        file_put_contents(
            $favicon,
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" fill="#1e40af"/></svg>'
        );

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $this->assertSame(200, $page['code']);
            // форма обязана быть multipart, иначе файлы не приедут
            $this->assertStringContainsString(
                'enctype="multipart/form-data"',
                $page['body'],
                'форма настроек должна принимать файлы'
            );
            $this->assertStringContainsString('name="branding_logo"', $page['body']);
            $this->assertStringContainsString('name="branding_favicon"', $page['body']);

            $r = $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'site_name' => 'TEST CORP',
                'site_logo_url' => $before['site_logo_url'],
                'site_favicon_url' => $before['site_favicon_url'],
                'site_favicon_png_url' => $before['site_favicon_png_url'],
                'branding_logo' => ['name' => 'brand-test-logo.png', 'type' => 'image/png', 'tmp_name' => $logo],
            ]);
            $this->assertSame(302, $r['code']);

            $mysql = connect();
            $st = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", 'site_logo_url');
            $st->execute();
            $logoUrl = (string) $st->get_result()->fetch_row()[0];
            $st2 = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", 'site_name');
            $st2->execute();
            $nameAfter = (string) $st2->get_result()->fetch_row()[0];
            $mysql->close();

            $this->assertSame('TEST CORP', $nameAfter, 'название должно сохраниться');
            $this->assertStringContainsString('/assets/images/branding/logo.png', $logoUrl);
            $this->assertStringContainsString('v=', $logoUrl, 'в URL нужна версия, иначе браузер покажет старый файл из кеша');

            // файл на диске и он уменьшен
            $savedLogo = dirname(__DIR__) . '/assets/images/branding/logo.png';
            $this->assertFileExists($savedLogo);
            $info = getimagesize($savedLogo);
            $this->assertNotFalse($info);
            $this->assertSame(400, (int) $info[0], 'логотип должен уменьшиться до 400px по ширине');
            $this->assertSame(
                133,
                (int) $info[1],
                'пропорции должны сохраниться: 900x300 -> 400x133'
            );

            // теперь favicon-файл в формате svg.
            // Форму отправляем полной: обработчик сохраняет все ключи из
            // белого списка, а отсутствующие поля приравниваются к пустым.
            // Частичная отправка затёрла бы остальные настройки.
            $page2 = $this->httpGet('/admin.php?tab=settings');
            $r2 = $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page2['body']),
                'saveSettings' => '1',
                'site_name' => 'Aion Corporation',
                'site_logo_url' => $logoUrl,
                'site_favicon_url' => $before['site_favicon_url'],
                'site_favicon_png_url' => $before['site_favicon_png_url'],
                'branding_favicon' => ['name' => 'brand-test-favicon.svg', 'type' => 'image/svg+xml', 'tmp_name' => $favicon],
            ]);
            $this->assertSame(302, $r2['code']);

            $mysql = connect();
            $st = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", 'site_favicon_url');
            $st->execute();
            $favUrl = (string) $st->get_result()->fetch_row()[0];
            $mysql->close();

            $this->assertStringContainsString(
                'favicon.svg',
                $favUrl,
                'загруженный svg должен занять svg-ключ'
            );
            $this->assertStringContainsString(
                '<svg',
                (string) @file_get_contents(dirname(__DIR__) . '/assets/images/branding/favicon.svg'),
                'svg-файл должен быть сохранён как есть, а не перекодирован GD'
            );
        } finally {
            $mysql = connect();
            foreach ($before as $key => $value) {
                db_prepare($mysql, "UPDATE site_settings SET setting_value = ? WHERE setting_key = ?", "ss", $value, $key)->execute();
            }
            $mysql->close();
            @unlink($logo);
            @unlink($favicon);
            @unlink($brandingDir . '/logo.png');
            @unlink($brandingDir . '/favicon.svg');
        }
    }

    /**
     * 4: модалка привязки показывает ВСЕ корпуса, а не только те, у
     * которых картинки нет.
     *
     * Список без картинок не давал возможности ЗАМЕНИть картинку: корпус
     * с картинкой в него не попадал вовсе. Поэтому в списке все корпуса,
     * а у уже привязанного кнопка «Заменить» и подпись с текущим файлом.
     */
    public function testAttachModalListsAllCases(): void
    {
        $this->loginAsAdmin();

        $mysql = connect();
        $countStmt = db_prepare($mysql, "SELECT COUNT(*) FROM components WHERE category_id = 6", "");
        $countStmt->execute();
        $totalCases = (int) $countStmt->get_result()->fetch_row()[0];
        $mysql->close();

        $page = $this->httpGet('/admin.php?tab=files');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            $totalCases,
            preg_match_all('/class="attach-case-row"/u', $page['body']),
            'в модалке должны быть все корпуса, а не только без картинки'
        );
        $this->assertMatchesRegularExpression(
            '/id="attachCaseSearch"/u',
            $page['body'],
            'в модалке должен быть поиск по названию'
        );

        // у корпуса с картинкой - «Заменить», у корпуса без - «Привязать».
        // Проверяется фикстурой: свой корпус с картинкой и свой без.
        $casesDir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-attach-' . uniqid() . '.png';
        file_put_contents($casesDir . $filename, 'X');
        $ins = db_prepare($mysql = connect(),
            "INSERT INTO components (component_name, category_id, component_price, amount, image) VALUES (?, 6, 1000, 1, ?)",
            "ss", 'Test Attach With', 'assets/images/cases/' . $filename);
        $ins->execute();
        $withId = (int) $ins->insert_id;
        $mysql->close();

        $ins2 = db_prepare($mysql = connect(),
            "INSERT INTO components (component_name, category_id, component_price, amount) VALUES (?, 6, 1000, 1)",
            "s", 'Test Attach Without');
        $ins2->execute();
        $withoutId = (int) $ins2->insert_id;
        $mysql->close();

        try {
            $page2 = $this->httpGet('/admin.php?tab=files');
            $this->assertMatchesRegularExpression(
                '/Test Attach With.*?Заменить/su',
                $page2['body'],
                'у корпуса с картинкой должна быть кнопка «Заменить»'
            );
            $this->assertMatchesRegularExpression(
                '/Test Attach Without.*?Привязать/su',
                $page2['body'],
                'у корпуса без картинки должна быть кнопка «Привязать»'
            );
        } finally {
            $mysql = connect();
            db_prepare($mysql, "DELETE FROM components WHERE component_id IN (?, ?)", "ii", $withId, $withoutId)->execute();
            $mysql->close();
            @unlink($casesDir . $filename);
        }
    }

    /**
     * 7: return_params не может подсунуть в Location что угодно.
     *
     * Строка приходит от клиента, поэтому собирать из неё адрес как есть
     * нельзя: иначе в редирект уехал бы чужой хост. Проверяются два
     * отсева - неизвестный ключ и попытка дописать свой параметр.
     */
    public function testReturnParamsRejectsForeignKeys(): void
    {
        $this->loginAsAdmin();

        $list = $this->httpGet('/admin.php?tab=components&cat=6');
        preg_match("/data-component='([^']+)'/u", $list['body'], $m);
        $row = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);

        $page = $this->httpGet('/admin.php?tab=components&cat=6');
        $r = $this->httpPost('/admin.php?tab=components', [
            'csrf_token' => $this->extractCsrf($page['body']),
            'addComponent' => '1',
            'editComponentId' => (string) $row['id'],
            // посторонние ключи и попытка открытого редиректа
            'return_params' => 'cat=6&evil=1&next=https://example.com',
            'nm' => (string) $row['name'],
            'pr' => (string) $row['price'],
            'col' => (string) $row['amount'],
            'cat' => '6',
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('cat=6', $r['location']);
        $this->assertStringNotContainsString('evil', $r['location'], 'неизвестный ключ не должен попадать в адрес');
        $this->assertStringNotContainsString('example.com', $r['location'], 'чужой хост не должен попадать в Location');

        // httpPost возвращает абсолютный URL (Location разворачивается
        // клиентом), поэтому проверяется именно путь, а не хост
        $path = (string) parse_url($r['location'], PHP_URL_PATH);
        $this->assertSame('/admin.php', $path, 'редирект должен вести на admin.php');
    }
}
