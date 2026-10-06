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
     * @return array{code: int, body: string, location: string}
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
        // Content-Type нужен для проверки статики: svg отданный как
        // text/plain или octet-stream отклонили бы и не отрисовали,
        // а по коду ответа это не видно - он тот же 200.
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        // адрес из Location нужен, чтобы отличить редирект на форму входа от
        // любого другого: CURLOPT_FOLLOWLOCATION не включён, поэтому при 302
        // тело ответа пустое
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'location' => $location, 'type' => $type];
    }

    /**
     * @param array<string, string> $data
     * @return array{code: int, body: string, location: string}
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
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'location' => $location];
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

    protected function clearLoginAttempts(string $login): void
    {
        try {
            $mysql = connect();
        } catch (Throwable) {
            return;
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

    /**
     * Значение одной колонки users по логину.
     *
     * Нужна для проверок регистрации: «пользователь создан» этого не
     * доказывает - фамилия или email могут не записаться, и тест обязан
     * видеть разницу. $column подставляется в запрос как есть, поэтому
     * зовётся только литералами из тестов, а не пользовательским вводом.
     */
    protected function userField(string $login, string $column): ?string
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT `{$column}` FROM users WHERE user_login = ?", 's', $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();

        if (!$row) {
            return null;
        }

        return $row[$column] === null ? null : (string) $row[$column];
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

    /**
     * список пунктов сайдбара, как их видит браузер.
     *
     * Именно через DOM, а не поиском по строке ответа. Причина конкретная:
     * в 5-f-2c-1 комментарий в сайдбаре был закрыт приёмом из CSS, и в
     * ответе сервера строки с пунктами «Личная информация» и
     * «Безопасность» присутствовали, а браузер съедал их вместе с
     * комментарием - то есть в меню их не было. Любая проверка вида
     * assertStringContainsString('href="/profile.php?section=..."') такой
     * случай проходит, потому что ищет в исходнике ответа.
     *
     * Текст внутри HTML-комментария не является элементом, поэтому в DOM
     * такие пункты просто отсутствуют.
     *
     * @return string[] подписи пунктов в порядке документа
     */
    protected function sidebarItems(string $html): array
    {
        [$nav, $xp] = $this->sidebarNode($html);
        $nodes = $xp->query(
            './/a[contains(@class, "profile-nav-item")]'
            . '|.//button[contains(@class, "profile-nav-item")]'
            . '|.//summary[contains(@class, "profile-nav-item")]',
            $nav
        );
        $items = [];
        foreach ($nodes as $node) {
            $items[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }
        return $items;
    }

    /**
     * href пунктов сайдбара, тем же разбором DOM.
     *
     * @return string[]
     */
    protected function sidebarHrefs(string $html): array
    {
        [$nav, $xp] = $this->sidebarNode($html);
        $nodes = $xp->query('.//*[@href]', $nav);
        $hrefs = [];
        foreach ($nodes as $node) {
            $hrefs[] = $node->getAttribute('href');
        }
        return $hrefs;
    }

    /**
     * куски разметки, которые браузер считает комментарием.
     *
     * Ловит класс ошибок с незакрытым HTML-комментарием: если внутри
     * комментария оказалась ссылка или блок, они не работают, хотя в ответе
     * сервера присутствуют.
     *
     * Проверка узкая и по форме, а не по смыслу текста. Ловим открывающий
     * тег с атрибутами или закрывающий тег - то есть кусок разметки.
     * Упоминание тега в тексте пропускаем: комментарий в
     * profile.php пишет про <use>, в admin-order-modal.php - про
     * <a>, в admin-user-modal.php - про name="editUser". Всё это
     * закрыто правильно и ничего не ломает. Ломает именно съеденный
     * элемент вида <a href="...">текст</a>.
     *
     * @return string[] посторонние фрагменты, которые на странице быть не должны
     */
    protected function markupInsideComments(string $html): array
    {
        $dom = $this->loadDom($html);

        $xp = new DOMXPath($dom);
        $found = [];
        // У DOMDocument нет getComments(), комментарии берутся запросом //comment()
        foreach ($xp->query('//comment()') as $comment) {
            $text = (string) preg_replace('/\s+/u', ' ', $comment->textContent);
            if (preg_match('/<[a-zA-Z][a-zA-Z0-9]*\s+[a-zA-Z-]+="|<\/[a-zA-Z]/', $text)) {
                $found[] = substr($text, 0, 120);
            }
        }
        return $found;
    }

    /**
     * количество элементов по XPath.
     *
     * Разбор через DOM, а не поиск по строке ответа - с той же причиной,
     * что и в sidebarItems.
     */
    protected function xpathCount(string $html, string $expr): int
    {
        $dom = $this->loadDom($html);
        $nodes = (new DOMXPath($dom))->query($expr);
        $this->assertInstanceOf(DOMNodeList::class, $nodes);
        return $nodes->length;
    }

    /**
     * атрибуты элемента, найденного по XPath.
     *
     * @return array<string, string> имя атрибута => значение
     */
    protected function xpathAttrs(string $html, string $expr, int $index = 0): array
    {
        $dom = $this->loadDom($html);
        $nodes = (new DOMXPath($dom))->query($expr);
        $this->assertInstanceOf(DOMNodeList::class, $nodes);
        $this->assertGreaterThan($index, $nodes->length, "не найден элемент по запросу {$expr}");
        $node = $nodes->item($index);
        $this->assertInstanceOf(DOMElement::class, $node);

        $attrs = [];
        foreach ($node->attributes as $attr) {
            $attrs[$attr->name] = $attr->value;
        }
        return $attrs;
    }

    /**
     * разбор страницы с корректной кодировкой.
     *
     * Префикс с объявлением обязателен: без него loadHTML читает ответ как
     * ISO-8859-1 и подписи вроде «Показать пароль» портятся, но XPath по
     * ASCII-атрибутам продолжает работать - то есть поломка проскочила бы
     * молча.
     */
    protected function loadDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    /**
     * разобрать страницу и отдать узел сайдбара вместе с XPath.
     *
     * Отдаётся именно узел, а не XPath: запрос нужно ограничить сайдбаром,
     * иначе под него попадёт любая другая навигация на странице.
     *
     * @return array{0: DOMNode, 1: DOMXPath}
     */
    private function sidebarNode(string $html): array
    {
        $dom = $this->loadDom($html);

        $xp = new DOMXPath($dom);
        $nav = $xp->query('//nav[contains(@class, "profile-nav")]');
        $this->assertGreaterThan(0, $nav->length, 'сайдбар не найден на странице');
        $node = $nav->item(0);
        $this->assertInstanceOf(DOMNode::class, $node);
        return [$node, $xp];
    }

    /**
     * войти в чистой сессии и вернуть страницу профиля.
     *
     * Отдельная сессия нужна, чтобы проверить, что вход работает, а не то,
     * что тестовый пользователь ещё не выходил: loginAs переиспользовал бы
     * текущий cookie-jar, где сессия уже активна, и показал бы профиль даже
     * с неверным паролем. Здесь jar пересоздаётся.
     *
     * @return array{code: int, body: string, location: string}
     */
    protected function loginInFreshSession(string $login, string $pass): array
    {
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
        $this->jar = tempnam(sys_get_temp_dir(), 'aion_jar_');
        $this->clearLoginAttempts($login);

        $this->loginAs($login, $pass);

        return $this->httpGet('/profile.php');
    }

    /**
     * Чистая сессия без входа - «гость».
     *
     * Нужна там, где тест проверяет страницу формы входа или регистрации:
     * успешная регистрация автологинит пользователя, и следующая же
     * выборка отдаёт профиль вместо формы, где живёт текст ошибки.
     */
    protected function guestSession(): void
    {
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
        $this->jar = tempnam(sys_get_temp_dir(), 'aion_jar_');
    }

    /**
     * POST-запрос с multipart/form-data.
     */
    protected function httpPostMultipart(string $url, array $data): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://localhost:8080' . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar);
        
        $boundary = uniqid();
        $headers = [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $body = '';
        foreach ($data as $key => $value) {
            // Файл может прийти как один ['name','type','tmp_name'] или как
            // список таких массивов под ключом files[] (batch-загрузка).
            $isFileList = is_array($value) && isset($value[0]) && is_array($value[0]);
            $parts = $isFileList ? $value : [$value];

            // Список файлов обязан уходить в multipart как name="files[]":
            // с name="files" PHP не соберёт массив $_FILES['files'], а
            // запишет один файл, и цикл по count($_FILES['files']['name'])
            // будет считать символы в имени.
            $partName = $isFileList ? $key . '[]' : $key;

            foreach ($parts as $part) {
                $isFile = is_array($part) && isset($part['tmp_name']);
                if ($isFile) {
                    $body .= "--{$boundary}\r\n";
                    $body .= "Content-Disposition: form-data; name=\"{$partName}\"; filename=\"{$part['name']}\"\r\n";
                    $body .= "Content-Type: {$part['type']}\r\n\r\n";
                    $body .= file_get_contents($part['tmp_name']) . "\r\n";
                } else {
                    $body .= "--{$boundary}\r\n";
                    $body .= "Content-Disposition: form-data; name=\"{$partName}\"\r\n\r\n";
                    $body .= $part . "\r\n";
                }
            }
        }
        $body .= "--{$boundary}--\r\n";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        
        $response = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        return ['code' => $code, 'body' => $response, 'location' => $location];
    }


}
