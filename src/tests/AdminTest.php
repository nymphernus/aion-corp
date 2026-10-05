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
     * 8: загрузка изображения корпуса отвергает файл > 5 МБ.
     */
    public function testUploadRejectsOversized(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=components');
        $token = $this->extractCsrf($page['body']);
        
        // Создаём временный файл > 5 МБ
        $tmp = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmp, str_repeat('A', 5 * 1024 * 1024 + 1));
        
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
     * 8: удаление файла отвергает файл, привязанный к компоненту.
     */
    public function testDeleteFileRejectsUsed(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=files');
        $token = $this->extractCsrf($page['body']);
        
        // Находим используемый файл
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT image FROM components WHERE image IS NOT NULL AND category_id = 6 LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        $this->assertNotNull($row, 'в базе должен быть корпус с изображением');
        $filename = basename($row['image']);

        $response = $this->httpPost('/admin.php?tab=files', [
            'csrf_token' => $token,
            'deleteFile' => '1',
            'filename' => $filename,
        ]);

        // 302 назад на files с ошибкой used
        $this->assertSame(302, $response['code']);
        $this->assertStringContainsString('error=used', $response['location']);
    }

    /**
     * 8: удаление файла работает для неиспользуемого файла.
     */
    public function testDeleteFileWorks(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=files');
        $token = $this->extractCsrf($page['body']);
        
        // Создаём тестовый файл прямо в cases/ (volume монтирует ./src,
        // поэтому файл виден и тесту, и контейнеру по одному пути)
        $dir = dirname(__DIR__) . '/assets/images/cases/';
        $filename = 'test-delete-' . uniqid() . '.jpg';
        $path = $dir . $filename;
        file_put_contents($path, 'dummy');

        $response = $this->httpPost('/admin.php?tab=files', [
            'csrf_token' => $token,
            'deleteFile' => '1',
            'filename' => $filename,
        ]);

        // 302 назад на files без ошибки: файл не привязан, удаление прошло
        $this->assertSame(302, $response['code']);
        $this->assertStringNotContainsString('error=', $response['location']);
        $this->assertFileDoesNotExist($path);
    }
}
