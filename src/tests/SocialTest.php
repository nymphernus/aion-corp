<?php

/**
 * Управление соцсетями в настройках.
 *
 * Набор закрывает три вещи: обычный цикл добавления-правки-удаления,
 * проверки на сервере и то, что список иконок берётся из каталога.
 *
 * Каждая проверка отправляет POST с токеном, только что взятым со
 * страницы. Токен одноразовый в рамках загрузки страницы, поэтому один
 * токен на несколько отправок не годится: вторая получила бы 403, и
 * проверка решила бы, что сервер отверг всё. Именно так выглядел первый
 * прогон этих тестов в браузере - «сохранено» при неверной ссылке
 * на самом деле означало 403.
 */
declare(strict_types=1);

final class SocialTest extends AionTestCase
{
    private const SETTINGS_URL = '/admin.php?tab=settings';

    /**
     * Строки social_links, которые создал тест. Снимок и восстановление
     * обязательны: таблица переживает прогон, и следующий тест увидел бы
     * строку, которой не должно быть.
     */
    private function socialSnapshot(): array
    {
        $rows = $this->dbRows('SELECT link_id, link_name, link_url, link_icon, sort_order, is_active FROM social_links ORDER BY link_id');
        return $rows;
    }

    private function socialRestore(array $rows): void
    {
        $mysql = $this->db();
        $mysql->query('DELETE FROM social_links');
        foreach ($rows as $row) {
            $stmt = db_prepare(
                $mysql,
                'INSERT INTO social_links (link_id, link_name, link_url, link_icon, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                'isssii',
                (int) $row['link_id'],
                (string) $row['link_name'],
                (string) $row['link_url'],
                (string) $row['link_icon'],
                (int) $row['sort_order'],
                (int) $row['is_active']
            );
            $stmt->execute();
        }
        $mysql->close();
    }

    /** @return array<int, array<string, mixed>> */
    private function dbRows(string $sql): array
    {
        $mysql = $this->db();
        $stmt = db_prepare($mysql, $sql, '');
        $stmt->execute();
        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = $row;
        }
        $mysql->close();
        return $rows;
    }

    /**
     * Строки social_links по названию.
     *
     * Отдельный помощник, потому что почти все проверки ищут именно одну
     * строку по имени, а db_prepare требует типы и значения: варианта
     * «запрос без параметров» у него нет, и молча забытый параметр
     * выглядел бы как пустой результат.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsByName(string $name): array
    {
        $mysql = $this->db();
        $stmt = db_prepare($mysql, 'SELECT * FROM social_links WHERE link_name = ?', 's', $name);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = $row;
        }
        $mysql->close();
        return $rows;
    }

    private function db()
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        return connect();
    }

    private function loginAdmin(): string
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code'], 'вход администратора должен редиректить');
        return $this->extractCsrf($this->httpGet('/profile.php')['body']);
    }

    /**
     * Отправка формы соцсети.
     *
     * Токен берётся заново на каждый вызов - см. шапку класса.
     *
     * @param array<string, string> $fields
     * @return array{code: int, body: string, location: string}
     */
    private function postSocial(array $fields, string $action = 'save', string $linkId = '0'): array
    {
        $page = $this->httpGet(self::SETTINGS_URL);
        $this->assertSame(200, $page['code']);

        $data = ['socialAction' => $action, 'linkId' => $linkId];
        foreach ($fields as $k => $v) {
            $data[$k] = $v;
        }

        return $this->httpPost(self::SETTINGS_URL, $data + ['csrf_token' => $this->extractCsrf($page['body'])]);
    }

    /**
     * Код ошибки из заголовка Location, либо '' при успешном сохранении.
     *
     * Именно заголовок, а не тело ответа и не конечный адрес: httpPost
     * за редиректом не следует, а страница с сообщением об ошибке
     * появится только после следующего GET.
     */
    private function badCode(array $response): string
    {
        if (preg_match('#bad=([^&]+)#', (string) $response['location'], $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * Добавление строки: она появляется в таблице с введёнными значениями.
     */
    public function testAddSocialCreatesRow(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => 'Telegram',
                'link_url' => 'https://t.me/aioncorp',
                'link_icon' => '/assets/images/social/telegram.svg',
                'sort_order' => '20',
                'is_active' => '1',
            ]);
            $this->assertSame(302, $r['code'], 'сохранение соцсети должно редиректить');
            $this->assertSame('', $this->badCode($r), 'ошибок быть не должно');

            $page = $this->httpGet(self::SETTINGS_URL);
            $this->assertSame(200, $page['code']);
            $this->assertStringContainsString('Telegram', $page['body']);
            $this->assertStringContainsString('https://t.me/aioncorp', $page['body']);

            $found = $this->rowsByName('Telegram');
            $this->assertCount(1, $found, 'строка должна появиться в таблице ровно одна');
            $this->assertSame('https://t.me/aioncorp', (string) $found[0]['link_url']);
            $this->assertSame('/assets/images/social/telegram.svg', (string) $found[0]['link_icon']);
            $this->assertSame(20, (int) $found[0]['sort_order']);
            $this->assertSame(1, (int) $found[0]['is_active']);
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Редактирование меняет строку, а не добавляет вторую.
     *
     * link_id из формы проверяется на существование: без такой проверки
     * правка несуществующей строки выглядела бы как «изменения не
     * сохранились».
     */
    public function testEditSocialUpdatesRow(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $add = $this->postSocial([
                'link_name' => 'Telegram',
                'link_url' => 'https://t.me/aioncorp',
                'link_icon' => '/assets/images/social/telegram.svg',
                'sort_order' => '20',
            ]);
            $this->assertSame(302, $add['code']);

            $rows = $this->rowsByName('Telegram');
            $this->assertCount(1, $rows, 'после добавления должна быть ровно одна такая строка');
            $id = (string) $rows[0]['link_id'];

            $edit = $this->postSocial([
                'link_name' => 'Telegram-канал',
                'link_url' => 'https://t.me/aioncorpnew',
                'link_icon' => '/assets/images/social/viber.svg',
                'sort_order' => '5',
            ], 'save', $id);
            $this->assertSame(302, $edit['code'], 'правка должна редиректить');

            $this->assertCount(0, $this->rowsByName('Telegram'), 'старое название должно исчезнуть');

            $rows = $this->rowsByName('Telegram-канал');
            $this->assertCount(1, $rows, 'новая строка должна быть одна, а не две');
            $this->assertSame((int) $id, (int) $rows[0]['link_id'], 'правка обязана менять ту же строку');
            $this->assertSame('https://t.me/aioncorpnew', (string) $rows[0]['link_url']);
            $this->assertSame('/assets/images/social/viber.svg', (string) $rows[0]['link_icon']);
            $this->assertSame(5, (int) $rows[0]['sort_order']);
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Правка несуществующей строки не создаёт новую.
     */
    public function testEditMissingRowReportsError(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();
        $before = count($snap);

        try {
            $r = $this->postSocial([
                'link_name' => 'Призрак',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '1',
            ], 'save', '999999');

            $this->assertSame('social_not_found', $this->badCode($r), 'нужна ошибка social_not_found');
            $this->assertCount($before, $this->socialSnapshot(), 'строк столько же - ничего не создано');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Удаление убирает строку.
     */
    public function testDeleteRemovesRow(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $this->postSocial([
                'link_name' => 'Лишняя',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '90',
            ]);
            $this->assertCount(
                count($snap) + 1,
                $this->socialSnapshot(),
                'строка должна добавиться перед удалением'
            );

            $rows = $this->rowsByName('Лишняя');
            $id = (string) $rows[0]['link_id'];

            $r = $this->postSocial([], 'delete', $id);
            $this->assertSame(302, $r['code']);
            $this->assertCount(0, $this->rowsByName('Лишняя'));

            $this->assertSame(
                count($snap),
                count($this->socialSnapshot()),
                'после удаления должно остаться столько же строк, сколько было'
            );
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Ссылки проверяются сервером.
     *
     * Главный случай - javascript:. Без проверки такой адрес исполнился бы
     * по клику в блоке соцсетей: тот же XSS, но через админку.
     *
     * @dataProvider badUrls
     */
    public function testUrlIsValidated(string $url, string $expectedBad): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => 'Проверка ссылки',
                'link_url' => $url,
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '1',
            ]);

            $this->assertSame(
                $expectedBad,
                $this->badCode($r),
                'ссылка ' . var_export($url, true) . ' должна быть отвергнута с кодом ' . $expectedBad
            );
            $this->assertCount(count($snap), $this->socialSnapshot(), 'строка не должна была появиться');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badUrls(): array
    {
        return [
            'javascript:' => ['javascript:alert(1)', 'social_url'],
            'пусто' => ['', 'social_url'],
            'протокол data:' => ['data:text/html,<script>alert(1)</script>', 'social_url'],
            'нет протокола' => ['vk.com/aioncorp', 'social_url'],
            'ftp:' => ['ftp://example.org/x', 'social_url'],
            'длиннее 255' => ['https://example.org/' . str_repeat('x', 260), 'social_url_long'],
        ];
    }

    /**
     * Название проверяется сервером.
     *
     * @dataProvider badNames
     */
    public function testNameIsValidated(string $name, string $expectedBad): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => $name,
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '1',
            ]);

            $this->assertSame($expectedBad, $this->badCode($r), 'название должно быть отвергнуто');
            $this->assertCount(count($snap), $this->socialSnapshot(), 'строка не должна была появиться');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badNames(): array
    {
        return [
            'пустое' => ['', 'social_name'],
            'из пробелов' => ['   ', 'social_name'],
            'длиннее 50' => [str_repeat('Я', 60), 'social_name_long'],
        ];
    }

    /**
     * Путь иконки сверяется с белым списком каталогов.
     *
     * link_icon попадает в атрибут src на главной, поэтому значение из
     * POST не должно уметь указать ни на что, кроме каталога иконок:
     * иначе в ссылку можно было бы записать /etc/passwd или php-файл.
     *
     * @dataProvider badIcons
     */
    public function testIconPathIsRestricted(string $icon, string $expectedBad): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => 'Проверка иконки',
                'link_url' => 'https://example.org',
                'link_icon' => $icon,
                'sort_order' => '1',
            ]);

            $this->assertSame($expectedBad, $this->badCode($r), 'иконка ' . $icon . ' должна быть отвергнута');
            $this->assertCount(count($snap), $this->socialSnapshot(), 'строка не должна была появиться');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badIcons(): array
    {
        return [
            'пусто' => ['', 'social_icon'],
            'вне каталога' => ['/etc/passwd', 'social_icon'],
            'обход вверх' => ['/assets/uploads/social/../../../evil.svg', 'social_icon'],
            'чужое расширение' => ['/assets/images/social/evil.php', 'social_icon'],
            'png в каталоге иконок' => ['/assets/images/social/evil.png', 'social_icon'],
            'за пределами assets' => ['https://example.org/icon.svg', 'social_icon'],
        ];
    }

    /**
     * Иконка из uploads/social проходит: загруженный админом файл лежит
     * именно там, и проверка не должна его отвергать.
     */
    public function testUploadedIconPathIsAccepted(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => 'Своя иконка',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/uploads/social/icon-abc123.png',
                'sort_order' => '1',
            ]);

            $this->assertSame('', $this->badCode($r), 'путь в uploads/social должен приниматься');
            $this->assertNotEmpty($this->rowsByName('Своя иконка'));
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Неизвестное значение socialAction отвергается.
     *
     * Иначе строка вида socialAction=drop молча ничего не делала бы, а
     * админ решил бы, что кнопка сломалась.
     */
    public function testUnknownActionIsRejected(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $r = $this->postSocial([
                'link_name' => 'Мусор',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '1',
            ], 'drop');

            $this->assertSame('social_action', $this->badCode($r));
            $this->assertCount(count($snap), $this->socialSnapshot());
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Список иконок собирается из каталога, а не из кода.
     *
     * Если бы файлы перечислялись в коде, проверка была бы на том, что
     * список совпадает с каталогом - то есть проверяла бы саму себя.
     * Здесь сравниваются два независимых источника: содержимое каталога
     * на диске и выведенная разметка.
     */
    public function testIconPickerListsDirectoryFiles(): void
    {
        $this->loginAdmin();
        $page = $this->httpGet(self::SETTINGS_URL);
        $this->assertSame(200, $page['code']);

        $dir = dirname(__DIR__) . '/assets/images/social';
        $onDisk = [];
        foreach ((array) scandir($dir) as $file) {
            if (str_ends_with((string) $file, '.svg')) {
                $onDisk[] = '/assets/images/social/' . $file;
            }
        }
        $this->assertNotEmpty($onDisk, 'в каталоге должны быть иконки, иначе проверка бессмысленна');

        preg_match_all(
            '#<input type="radio" name="link_icon"\s+value="([^"]+)"#',
            $page['body'],
            $m
        );
        $inHtml = $m[1];

        sort($onDisk);
        sort($inHtml);
        $this->assertSame(
            $onDisk,
            $inHtml,
            'список иконок в форме должен совпадать с содержимым каталога'
        );
    }

    /**
     * Выключенная соцсеть остаётся в таблице, но помечена как скрытая.
     *
     * is_active = 0 не удаляет строку: вернуться к ней можно было бы
     * только через правку руками в базе.
     */
    public function testInactiveRowIsMarkedHidden(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            // Галочка is_active не присылается, когда снята, - поэтому её
            // отсутствие и есть «выключено».
            $r = $this->postSocial([
                'link_name' => 'Выключенная',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '70',
            ]);
            $this->assertSame(302, $r['code']);

            $rows = $this->rowsByName('Выключенная');
            $this->assertCount(1, $rows);
            $this->assertSame(0, (int) $rows[0]['is_active'], 'без галочки строка должна быть выключена');

            $page = $this->httpGet(self::SETTINGS_URL);
            $this->assertMatchesRegularExpression(
                '#Выключенная.*?скрыта#su',
                $page['body'],
                'в таблице должно стоять «скрыта», а не «показ»'
            );
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Порядок упорядочивает строки так же, как на главной.
     */
    public function testRowsAreOrderedBySortOrderThenId(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $this->postSocial([
                'link_name' => 'Поздняя',
                'link_url' => 'https://example.org/b',
                'link_icon' => '/assets/images/social/viber.svg',
                'sort_order' => '99',
            ]);
            $this->postSocial([
                'link_name' => 'Ранняя',
                'link_url' => 'https://example.org/a',
                'link_icon' => '/assets/images/social/ok.svg',
                'sort_order' => '1',
            ]);

            $page = $this->httpGet(self::SETTINGS_URL);
            $early = strpos($page['body'], 'Ранняя');
            $late = strpos($page['body'], 'Поздняя');
            $this->assertNotFalse($early, 'обе строки должны быть в таблице');
            $this->assertNotFalse($late);
            $this->assertLessThan($late, $early, 'меньший порядок должен идти выше');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Сортировка подбирается с границами.
     *
     * Без этого sort_order = -5 или 99999 сохранился бы как есть, и
     * порядок вывода поехал бы.
     */
    public function testSortOrderIsClamped(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();

        try {
            $this->postSocial([
                'link_name' => 'Отрицательная',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '-5',
            ]);
            $this->postSocial([
                'link_name' => 'Огромная',
                'link_url' => 'https://example.org',
                'link_icon' => '/assets/images/social/vk.svg',
                'sort_order' => '99999',
            ]);

            $rows = $this->rowsByName('Отрицательная');
            $this->assertSame(0, (int) $rows[0]['sort_order'], 'отрицательный порядок должен стать нулём');
            $rows = $this->rowsByName('Огромная');
            $this->assertSame(999, (int) $rows[0]['sort_order'], 'порядок должен упереться в 999');
        } finally {
            $this->socialRestore($snap);
        }
    }

    /**
     * Без токена POST отвергается.
     *
     * Карточка соцсетей - отдельная форма, и её токен должен работать не
     * хуже общего. Без проверки любой сторонний сайт мог бы править
     * соцсети от имени админа.
     */
    public function testSaveWithoutCsrfIsRejected(): void
    {
        $this->loginAdmin();
        $snap = $this->socialSnapshot();
        $before = count($snap);

        $r = $this->httpPost(self::SETTINGS_URL, [
            'csrf_token' => 'подделка',
            'socialAction' => 'save',
            'linkId' => '0',
            'link_name' => 'Без токена',
            'link_url' => 'https://example.org',
            'link_icon' => '/assets/images/social/vk.svg',
            'sort_order' => '1',
        ]);

        $this->assertSame(403, $r['code'], 'POST без верного токена должен давать 403');
        $this->assertCount($before, $this->socialSnapshot(), 'строка не должна была появиться');
    }

    /**
     * Карточка соцсетей на месте и показывает существующие строки.
     */
    public function testCardRendersExistingRows(): void
    {
        $this->loginAdmin();
        $page = $this->httpGet(self::SETTINGS_URL);
        $this->assertSame(200, $page['code']);

        $this->assertStringContainsString('Соцсети', $page['body']);
        $this->assertStringContainsString('data-action="add-social"', $page['body']);
        $this->assertStringContainsString('id="socialModal"', $page['body']);
        $this->assertStringContainsString('id="deleteSocialForm"', $page['body']);

        $shown = preg_match_all('#<tr data-link-id="#', $page['body']);
        $this->assertSame(
            count($this->socialSnapshot()),
            $shown,
            'в таблице должно быть столько строк, сколько в базе'
        );
    }
}
