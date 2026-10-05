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

    /**
     * 5-a: сессия переживает удаление пользователя из базы.
     *
     * Раньше profile.php решал, показывать профиль или форму входа, по
     * $_SESSION['user_id'], а не по наличию строки в users. Удалённый
     * пользователь получал пустой профиль: все поля «Не указано», логин без
     * имени, а сохранения уходили в ноль строк и ничем не выдавали себя -
     * страница выглядела как успешная. Теперь сессия сбрасывается, и
     * пользователь оказывается на форме входа.
     */
    public function testDeletedUserWithLiveSessionIsLoggedOut(): void
    {
        $login = $this->uniqueLogin();
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $r = $this->httpPost('/validation/reg.php', [
            'user_name' => 'Удаляемый',
            'user_login' => $login,
            'user_pass' => 'password123',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        // сессия живая, профиль на месте
        $profile = $this->httpGet('/profile.php');
        $this->assertSame(200, $profile['code']);
        $this->assertStringContainsString('profile-header-login', $profile['body']);

        // пользователя удаляют из базы, сессия остаётся
        $this->deleteTestUser($login);
        $this->assertFalse($this->userExists($login));

        $after = $this->httpGet('/profile.php');
        $this->assertSame(302, $after['code'], 'удалённый пользователь должен быть выброшен на форму входа');
        $this->assertStringContainsString('/profile.php', $after['location']);

        $form = $this->httpGet('/profile.php');
        $this->assertSame(200, $form['code']);
        $this->assertStringNotContainsString('profile-header-login', $form['body']);
        $this->assertStringNotContainsString('Не указано', $form['body']);
    }

    /**
     * 5-a: удалённый пользователь не может «сохранить» профиль POST-запросом.
     *
     * Сброс сессии стоит до обработчиков, поэтому запрос уходит в редирект и
     * профиль не перерисовывается. Раньше UPDATE уходил в ноль строк, а
     * страница выглядела как успешное сохранение.
     */
    public function testDeletedUserCannotPostProfileChanges(): void
    {
        $login = $this->uniqueLogin();
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $this->httpPost('/validation/reg.php', [
            'user_name' => 'Удаляемый',
            'user_login' => $login,
            'user_pass' => 'password123',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);

        $this->deleteTestUser($login);

        $r = $this->httpPost('/profile.php', [
            'changeName' => '1',
            'user_name' => 'Подделка',
            'csrf_token' => 'stale-token',
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('profile-header-login', $r['body']);
        $this->assertFalse($this->userExists($login));
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

    /**
     * 5-f-1: сообщение о неверном пароле должно доходить до пользователя.
     *
     * Раньше validation/auth.php клал текст в cookie error_access, а
     * profile.php читал $_SESSION['error_access'] — ключ, который не
     * заполняет никто. Ошибка просто терялась: пользователь возвращался на
     * чистую форму без единого объяснения.
     */
    public function testWrongPasswordShowsErrorMessage(): void
    {
        $login = $this->uniqueLogin('err_show_');
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $r = $this->httpPost('/validation/auth.php', [
            'user_login' => $login,
            'user_pass' => 'definitely_wrong_password',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        $after = $this->httpGet('/profile.php');
        $this->assertSame(200, $after['code']);
        $this->assertStringContainsString(
            'alert alert--error',
            $after['body'],
            'сообщение об ошибке должно показываться в плашке'
        );
        $this->assertStringContainsString('Неверный логин или пароль', $after['body']);

        // cookie одноразовая: следующий заход должен быть чистым
        $clean = $this->httpGet('/profile.php');
        $this->assertStringNotContainsString(
            'alert alert--error',
            $clean['body'],
            'сообщение не должно повторяться при следующем заходе'
        );

        $this->clearLoginAttempts($login);
    }

    /**
     * 5-f-1: без ошибки плашки быть не должно.
     *
     * Разметка была <p class="alert alert--error"> с условием внутри, поэтому
     * пустой красный блок высотой 26px висел на каждом заходе гостя.
     */
    public function testNoErrorAlertForGuestWithoutError(): void
    {
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString(
            'alert alert--error',
            $page['body'],
            'у гостя без ошибки красной плашки быть не должно'
        );
    }

    /**
     * 5-f-1: ошибка регистрации показывается в раскрытой форме регистрации.
     *
     * error_access общий для входа и регистрации, поэтому при провале
     * регистрации текст попадал в свёрнутую форму входа и оставался невидимым.
     * reg.php ставит метку error_from, по которой форма раскрывается.
     */
    public function testRegistrationErrorShowsInRegistrationForm(): void
    {
        $login = $this->uniqueLogin('err_reg_');
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $r = $this->httpPost('/validation/reg.php', [
            'user_name' => 'Тест',
            'user_login' => $login,
            'user_pass' => '123',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        $after = $this->httpGet('/profile.php');
        $this->assertStringContainsString('Пароль должен быть от 8 до 20 символов', $after['body']);
        // форма входа свёрнута, форма регистрации раскрыта
        $this->assertMatchesRegularExpression(
            '/id="login_cont"[^>]*style="display:none;/',
            $after['body'],
            'форма входа должна быть свёрнута при ошибке регистрации'
        );
        $this->assertMatchesRegularExpression(
            '/id="pass_cont"[^>]*>/',
            $after['body'],
            'форма регистрации должна быть раскрыта при ошибке регистрации'
        );
        $this->assertStringNotContainsString('id="pass_cont" style="display:none;"', $after['body']);
    }

    /**
     * 5-f-1b: ошибка входа не должна показываться в форме регистрации.
     *
     * error_from живёт 60 секунд, error_access - одну. Если регистрация
     * провалилась, страницу открыли позже, короткая cookie истекла, а
     * error_from=reg осталась одна. Следующая неудачная попытка входа
     * показывала «Неверный логин или пароль» в свёрнутой форме регистрации,
     * а форма входа уезжала. Тест кладёт в cookie-jar остаток протухшей
     * метки и проверяет, что форма входа остаётся на месте.
     */
    public function testLoginErrorStaysInLoginFormDespiteStaleMarker(): void
    {
        $page = $this->httpGet('/profile.php');

        // остаток метки от неудачной регистрации, error_access давно истёк
        $host = parse_url((string) BASE_URL, PHP_URL_HOST) ?: 'localhost';
        $line = implode("\t", [
            $host, 'FALSE', '/profile.php', 'FALSE',
            (string) (time() + 50), 'error_from', 'reg',
        ]);
        file_put_contents($this->jar, $line . "\n", FILE_APPEND);

        $r = $this->httpPost('/validation/auth.php', [
            'user_login' => 'admin',
            'user_pass' => 'definitely_wrong_password',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        $after = $this->httpGet('/profile.php');
        $this->assertStringContainsString('Неверный логин или пароль', $after['body']);
        $this->assertStringContainsString(
            'id="login_cont">',
            $after['body'],
            'ошибка входа обязана быть в форме входа, а не в свёрнутой форме регистрации'
        );
        $this->assertStringNotContainsString('id="pass_cont">', $after['body']);

        $this->clearLoginAttempts('admin');
    }

    /**
     * Зарегистрировать пользователя и войти под ним, вернуть логин.
     *
     * 5-f-2c: тесты смены пароля работают на своём пользователе, а не на
     * admin - иначе тест менял бы пароль админа и следующий прогон падал
     * бы из-за этого.
     */
    private function makeLoggedInUser(string $prefix = 'pw_test_'): string
    {
        $login = $this->uniqueLogin($prefix);
        $this->trackCleanup($login);

        $page = $this->httpGet('/profile.php');
        $r = $this->httpPost('/validation/reg.php', [
            'user_name' => 'Тестовый',
            'user_login' => $login,
            'user_pass' => 'password123',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code'], 'регистрация тестового пользователя');

        return $login;
    }

    /**
     * 5-f-2c: успешная смена пароля, новый работает, старый нет.
     */
    public function testChangePasswordWorks(): void
    {
        $login = $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $this->assertStringContainsString('id="card-security"', $page['body']);

        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'password123',
            'new_password' => 'newpass456',
            'new_password_confirm' => 'newpass456',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code'], 'успешная смена должна редиректить');

        $after = $this->httpGet('/profile.php?section=security&password_changed=1');
        $this->assertStringContainsString('alert alert--success', $after['body']);
        $this->assertStringContainsString('Пароль успешно изменён', $after['body']);

        // новый пароль входит
        $withNew = $this->loginInFreshSession($login, 'newpass456');
        $this->assertStringContainsString(
            'profile-header-login',
            $withNew['body'],
            'вход с новым паролем должен работать'
        );

        // старый - нет. Ответ на оба одинаковый (302 на форму), поэтому
        // различие видно только по содержимому профиля
        $withOld = $this->loginInFreshSession($login, 'password123');
        $this->assertStringNotContainsString(
            'profile-header-login',
            $withOld['body'],
            'старый пароль больше не должен пускать в профиль'
        );
    }

    /**
     * 5-f-2c: неверный текущий пароль отклоняется, пароль не меняется.
     */
    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $login = $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'definitely_wrong',
            'new_password' => 'newpass456',
            'new_password_confirm' => 'newpass456',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(200, $r['code'], 'при ошибке редиректа быть не должно');
        $this->assertStringContainsString('Текущий пароль неверен', $r['body']);

        // пароль прежний: вход работает
        $ok = $this->loginInFreshSession($login, 'password123');
        $this->assertStringContainsString('profile-header-login', $ok['body']);
    }

    /**
     * 5-f-2c: короткий новый пароль отклоняется.
     */
    public function testChangePasswordRejectsShort(): void
    {
        $login = $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'password123',
            'new_password' => 'short',
            'new_password_confirm' => 'short',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('Новый пароль должен быть от 8 до 20 символов', $r['body']);

        $ok = $this->loginInFreshSession($login, 'password123');
        $this->assertStringContainsString('profile-header-login', $ok['body']);
    }

    /**
     * 5-f-2c: несовпадение повтора отклоняется.
     */
    public function testChangePasswordRejectsMismatch(): void
    {
        $login = $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'password123',
            'new_password' => 'newpass456',
            'new_password_confirm' => 'otherpass789',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('Пароли не совпадают', $r['body']);

        $ok = $this->loginInFreshSession($login, 'password123');
        $this->assertStringContainsString('profile-header-login', $ok['body']);
    }

    /**
     * 5-f-2c: смена пароля без CSRF-токена отклоняется.
     */
    public function testChangePasswordRequiresCsrf(): void
    {
        $login = $this->makeLoggedInUser();

        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'password123',
            'new_password' => 'newpass456',
            'new_password_confirm' => 'newpass456',
            'csrf_token' => 'stale-token',
        ]);
        $this->assertSame(403, $r['code'], 'без токена смена пароля не должна проходить');

        $ok = $this->loginInFreshSession($login, 'password123');
        $this->assertStringContainsString('profile-header-login', $ok['body']);
    }

    /**
     * 5-f-2c: карточка безопасности не рисуется гостю.
     */
    public function testSecurityCardHiddenForGuest(): void
    {
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString('id="card-security"', $page['body']);
        $this->assertStringNotContainsString('name="current_password"', $page['body']);
    }

    /**
     * 5-f-2c-1: пункт «Безопасность» в сайдбаре - обычная ссылка с
     * ?section=, а не кнопка с JS.
     *
     * Кнопка работала только там, где секция уже нарисована. В админке
     * карточек профиля нет, поэтому клик по кнопке на дашборде прятал
     * секции (их ноль), ничего не показывал и оставался на
     * /admin.php?tab=dashboard.
     *
     * Проверяется разбором DOM, а не поиском по строке ответа: незакрытый
     * HTML-комментарий в этом же подэтапе съел обе ссылки, и в ответе
     * сервера они были, а в меню - нет.
     */
    public function testSidebarLinksToProfileSections(): void
    {
        $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            ['Личная информация', 'Безопасность', 'Мои заказы', 'Избранное', 'Выйти'],
            $this->sidebarItems($page['body']),
            'состав пунктов сайдбара обычного пользователя'
        );

        $hrefs = $this->sidebarHrefs($page['body']);
        $this->assertContains('/profile.php?section=info', $hrefs);
        $this->assertContains('/profile.php?section=security', $hrefs);
    }

    /**
     * 5-f-2c-1: на страницах профиля не должно быть разметки, которую
     * браузер считает комментарием.
     *
     * Точный regression-тест на баг с незакрытым комментарием: он не виден
     * ни в ответе сервера, ни в php -l, ни в тестах по строке ответа, но
     * элементы внутри него не работают.
     */
    public function testNoMarkupSwallowedByHtmlComments(): void
    {
        $this->makeLoggedInUser();

        foreach (['/profile.php', '/profile.php?section=security'] as $url) {
            $page = $this->httpGet($url);
            $this->assertSame(
                [],
                $this->markupInsideComments($page['body']),
                "на {$url} разметка не должна попадать внутрь HTML-комментария"
            );
        }
    }

    /**
     * 5-f-2c-1: ?section=security открывает карточку, а не прячет её.
     *
     * Проверяется именно отсутствие display:none, а не наличие id.
     * Раньше карточка в разметке была, но всегда со
     * style="display:none;" - тесты на 'id="card-security"' такой случай
     * пропускали.
     */
    public function testSecuritySectionOpensByUrl(): void
    {
        $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('id="card-security" data-section>', $page['body']);
        $this->assertStringContainsString(
            'id="card-info" data-section style="display:none;"',
            $page['body']
        );
    }

    /**
     * 5-f-2c-1: неизвестный ключ в ?section= откатывается на info.
     */
    public function testUnknownSectionFallsBackToInfo(): void
    {
        $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=bred');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('id="card-info" data-section>', $page['body']);
        $this->assertStringContainsString(
            'id="card-security" data-section style="display:none;"',
            $page['body']
        );
    }

    /**
     * 5-f-2c-1: после смены пароля редирект ведёт на section=security,
     * а не на section=card-security.
     *
     * section=card-security в $sectionMap не находился: там ключи
     * info/security/orders/fav, а card-security - уже результат отображения.
     * Страница открывалась на «Личной информации», то есть только что
     * открытая карточка безопасности тут же пряталась.
     */
    public function testPasswordChangeRedirectsToSecuritySection(): void
    {
        $login = $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $r = $this->httpPost('/profile.php', [
            'changePassword' => '1',
            'current_password' => 'password123',
            'new_password' => 'newpass456',
            'new_password_confirm' => 'newpass456',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('section=security', $r['location']);

        // и по этому адресу карточка действительно открыта
        $after = $this->httpGet('/profile.php' . strstr($r['location'], '?section'));
        $this->assertStringContainsString('id="card-security" data-section>', $after['body']);
        $this->assertStringContainsString('Пароль успешно изменён', $after['body']);
    }

    /**
     * 5-f-2c-1: заказы и избранное обычному пользователю по-прежнему
     * открываются и по ?section=, и по кнопке.
     *
     * Правка перевела на ссылки только те секции, что нужны с любой
     * страницы. Кнопки заказов и избранного оставлены как были - регресс
     * по ним ловится здесь.
     */
    public function testOrdersAndFavStillOpen(): void
    {
        $this->makeLoggedInUser();

        $orders = $this->httpGet('/profile.php?section=orders');
        $this->assertStringContainsString('id="card-builds" data-section>', $orders['body']);

        $fav = $this->httpGet('/profile.php?section=fav');
        $this->assertStringContainsString('id="card-fav" data-section>', $fav['body']);

        // кнопки на месте
        $this->assertStringContainsString('data-target="card-builds"', $orders['body']);
        $this->assertStringContainsString('data-target="card-fav"', $fav['body']);
    }

    /**
     * 5-f-4: у поля пароля в форме входа есть кнопка показа.
     *
     * Проверяется разбором DOM, а не поиском строки в ответе: именно так
     * в 5-f-2c-1 проскочил баг, когда разметка оказалась внутри
     * незакрытого HTML-комментария и в браузере её не было.
     */
    public function testLoginFormHasPasswordToggle(): void
    {
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);

        $toggle = $this->xpathAttrs(
            $page['body'],
            '//div[@id="login_cont"]//button[@data-action="toggle-password"]'
        );

        // 5-f-4: type="button" обязателен. Кнопка внутри формы по умолчанию
        // submit, и полагаться на preventDefault в обработчике нельзя: на
        // старых iOS Safari форма уходит раньше, чем обработчик отработает
        $this->assertSame('button', $toggle['type'], 'кнопка не должна быть submit');
        $this->assertSame('Показать пароль', $toggle['aria-label']);
        $this->assertSame('Показать пароль', $toggle['title']);

        // обе иконки на месте
        $this->assertSame(1, $this->xpathCount(
            $page['body'],
            '//div[@id="login_cont"]//svg[contains(@class, "password-toggle__show")]'
        ));
        $this->assertSame(1, $this->xpathCount(
            $page['body'],
            '//div[@id="login_cont"]//svg[contains(@class, "password-toggle__hide")]'
        ));

        // поле осталось полем пароля, имя на месте
        $input = $this->xpathAttrs(
            $page['body'],
            '//div[@id="login_cont"]//div[@class="password-field"]/input'
        );
        $this->assertSame('password', $input['type']);
        $this->assertSame('user_pass', $input['name']);
        $this->assertSame('auth_pass', $input['id']);
        $this->assertArrayHasKey('required', $input);
        $this->assertArrayNotHasKey(
            'value',
            $input,
            'пароль не должен попадать в HTML даже пустым значением'
        );
    }

    /**
     * 5-f-4: то же в форме регистрации, у которой своё поле.
     */
    public function testRegistrationFormHasPasswordToggle(): void
    {
        $page = $this->httpGet('/profile.php');
        $this->assertSame(200, $page['code']);

        $this->assertSame(1, $this->xpathCount(
            $page['body'],
            '//div[@id="pass_cont"]//div[@class="password-field"]/input[@id="reg_pass"]'
        ));
        $toggle = $this->xpathAttrs(
            $page['body'],
            '//div[@id="pass_cont"]//button[@data-action="toggle-password"]'
        );
        $this->assertSame('button', $toggle['type']);
        $this->assertSame('Показать пароль', $toggle['aria-label']);
    }

    /**
     * 5-f-4: три поля смены пароля, у каждого своя кнопка.
     *
     * Независимость проверяется по разметке: своя обёртка
     * .password-field и своя кнопка на каждое поле. Одна кнопка на форму
     * переключала бы все три разом, а новый пароль и его повтор удобно
     * сверять вместе - значит переключать их надо по отдельности.
     */
    public function testPasswordChangeHasIndependentToggles(): void
    {
        $this->makeLoggedInUser();

        $page = $this->httpGet('/profile.php?section=security');
        $this->assertSame(200, $page['code']);

        $this->assertSame(3, $this->xpathCount(
            $page['body'],
            '//section[@id="card-security"]//div[@class="password-field"]'
        ));
        $this->assertSame(3, $this->xpathCount(
            $page['body'],
            '//section[@id="card-security"]//button[@data-action="toggle-password"]'
        ));
        $this->assertSame(3, $this->xpathCount(
            $page['body'],
            '//section[@id="card-security"]//button[@type="button"][@data-action="toggle-password"]'
        ));

        foreach (['current_password', 'new_password', 'new_password_confirm'] as $i => $name) {
            $input = $this->xpathAttrs(
                $page['body'],
                '//section[@id="card-security"]//div[@class="password-field"]/input',
                $i
            );
            $this->assertSame($name, $input['name'], 'порядок полей смены пароля');
            $this->assertSame('password', $input['type']);
            $this->assertArrayNotHasKey('value', $input, "у поля {$name} не должно быть value");
        }

        // ограничения длины не потерялись при переносе разметки в хелпер
        $newPass = $this->xpathAttrs($page['body'], '//input[@name="new_password"]');
        $this->assertSame('8', $newPass['minlength']);
        $this->assertSame('20', $newPass['maxlength']);
        $this->assertSame('new-password', $newPass['autocomplete']);

        $current = $this->xpathAttrs($page['body'], '//input[@name="current_password"]');
        $this->assertSame('current-password', $current['autocomplete']);
    }

    /**
     * 5-f-4: у каждого поля пароля на странице своя кнопка.
     *
     * Считается по обоим состояниям страницы, потому что формы входа и
     * регистрации залогиненному не рисуются: гостю достаются две кнопки,
     * пользователю на странице безопасности - три. Сперва думали про одно
     * число пять, но это ошибка счёта, а не разметки.
     *
     * Проверяется и то, что не осталось полей вне обёртки: иначе
     * расхождение выдало бы себя только визуально, по отсутствию иконки
     * у одного из полей.
     */
    public function testEveryPasswordFieldHasItsOwnToggle(): void
    {
        $guest = $this->httpGet('/profile.php');
        $this->assertSame(200, $guest['code']);
        $this->assertSame(2, $this->xpathCount($guest['body'], '//input[@type="password"]'));
        $this->assertSame(2, $this->xpathCount($guest['body'], '//button[@data-action="toggle-password"]'));
        $this->assertSame(
            0,
            $this->xpathCount(
                $guest['body'],
                '//input[@type="password"][not(parent::div[@class="password-field"])]'
            ),
            'у поля пароля гостя должна быть обёртка с кнопкой'
        );

        $this->makeLoggedInUser();
        $page = $this->httpGet('/profile.php?section=security');
        $this->assertSame(200, $page['code']);
        $this->assertSame(3, $this->xpathCount($page['body'], '//input[@type="password"]'));
        $this->assertSame(3, $this->xpathCount($page['body'], '//button[@data-action="toggle-password"]'));
        $this->assertSame(
            0,
            $this->xpathCount(
                $page['body'],
                '//input[@type="password"][not(parent::div[@class="password-field"])]'
            ),
            'у поля пароля в профиле должна быть обёртка с кнопкой'
        );
    }
}
