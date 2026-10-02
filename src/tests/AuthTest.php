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
}
