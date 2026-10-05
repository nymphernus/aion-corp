<?php
/**
 * BrandingTest — Stage 9, подэтап 4/4: шаблонизация бренда.
 *
 * Проверяется ровно то, ради чего Stage 9 и делался: смена настроек
 * меняет страницы. До шаблонизации каждая страница содержала название и
 * год в разметке, и тест, ищущий «MyShop» после его сохранения, на
 * старом коде падал бы.
 *
 * Настройки читаются из site_settings, а не из демо-данных: тест
 * работает с той БД, которая поднята, и не завязан на seed. Всё, что
 * меняет, восстанавливается в finally.
 */

declare(strict_types=1);

final class BrandingTest extends AionTestCase
{
    /** Ключи, которые тесты меняют: снимок + восстановление. */
    private const TOUCHED = [
        'site_name',
        'site_founded_year',
        'site_description',
        'site_logo_url',
        'site_favicon_url',
        'site_favicon_png_url',
        'site_footer_copyright',
    ];

    /**
     * Вход администратором.
     *
     * Копия loginAsAdmin из AdminTest: там метод приватный, а логины и
     * пароль админа нужны нескольким наборам тестов. Перед входом
     * чищаются попытки входа - иначе после нескольких прогонов подряд
     * срабатывает ограничение и вход возвращает 429.
     */
    private function loginAsAdmin(): string
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
        $page = $this->httpGet('/profile.php');
        return $this->extractCsrf($page['body']);
    }

    /**
     * @return array<string, string> значения настроек на момент вызова
     */
    private function snapshot(): array
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $out = [];
        foreach (self::TOUCHED as $key) {
            $st = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", $key);
            $st->execute();
            $out[$key] = (string) ($st->get_result()->fetch_row()[0] ?? '');
        }
        $mysql->close();
        return $out;
    }

    /**
     * @param array<string, string> $values
     */
    private function restore(array $values): void
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        site_setting_save($mysql, $values);
        $mysql->close();
    }

    /**
     * Сохранить настройки через форму админки.
     *
     * @param array<string, string> $values
     */
    private function saveSettings(array $values): void
    {
        $page = $this->httpGet('/admin.php?tab=settings');
        $this->assertSame(200, $page['code']);
        $r = $this->httpPost('/admin.php?tab=settings', $values + [
            'csrf_token' => $this->extractCsrf($page['body']),
            'saveSettings' => '1',
        ]);
        $this->assertSame(302, $r['code'], 'сохранение настроек должно редиректить');
    }

    /**
     * @return string текст между <title> и </title>
     */
    private function titleOf(string $html): string
    {
        $this->assertSame(1, preg_match('#<title>(.*?)</title>#su', $html, $m), 'на странице должен быть один <title>');
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Смена site_name видна на всех страницах.
     *
     * Ключевой тест шаблонизации. Проверяются четыре страницы с разными
     * $pageTitle: главная (свой заголовок), профиль, админка и сброс
     * пароля. На старом коде, где название было в разметке каждой
     * страницы, после сохранения «MyShop» не появилось бы нигде.
     */
    public function testSiteNameShowsOnEveryPage(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_name' => 'MyShop']);

            // Название должно везде. Страницы делятся на два списка намеренно:
            // шапку и подвал подключают не все. reset.php - самостоятельная
            // страница (её докблок говорит, что шапку она не подключает),
            // поэтому логотипа и подвала на ней нет, а название в
            // заголовке вкладке есть. Объединённый список заставлял бы
            // либо требовать шапку там, где её нет по устройству, либо
            // не проверять подвал на страницах с шапкой.
            $withChrome = [
                '/' => 'главная',
                '/profile.php' => 'профиль',
                '/admin.php?tab=users' => 'админка',
            ];
            $pages = $withChrome + ['/validation/reset.php' => 'сброс пароля'];

            foreach ($pages as $path => $label) {
                $r = $this->httpGet($path);
                $this->assertSame(200, $r['code'], $label . ': ожидался 200');

                // Заголовок вкладки дополняется названием - это есть
                // на всех страницах, включая самостоятельные.
                $this->assertStringEndsWith(
                    'MyShop',
                    $this->titleOf($r['body']),
                    $label . ': название должно быть в заголовке вкладки'
                );
                $this->assertStringNotContainsString(
                    $before['site_name'],
                    $this->titleOf($r['body']),
                    $label . ': старое название не должно остаться в заголовке'
                );

                if (!isset($withChrome[$path])) {
                    continue;
                }

                // Подпись логотипа в шапке.
                $this->assertSame(
                    1,
                    preg_match('/<img class="logo" src="[^"]*" alt="MyShop">/', $r['body']),
                    $label . ': alt логотипа должен содержать название'
                );

                // Подвал: пустой site_footer_copyright собирается как
                // «© {год} {название}».
                $this->assertStringContainsString(
                    '© ' . $before['site_founded_year'] . ' MyShop',
                    $r['body'],
                    $label . ': подвал должен содержать новое название'
                );
            }

            // Главная: hero собирается из site_name, а не из разметки.
            $home = $this->httpGet('/');
            $this->assertStringContainsString(
                '<h1 class="hero__title">MyShop</h1>',
                $home['body'],
                'hero на главной должен показывать site_name'
            );

            // meta description не должен содержать старого названия.
            $this->assertStringNotContainsString(
                $before['site_name'],
                $this->metaDescriptionOf($home['body']),
                'meta description не должен зависеть от названия'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Смена site_logo_url меняет src в шапке.
     */
    public function testSiteLogoUrlChangesHeaderSrc(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_logo_url' => '/assets/images/branding/logo.png']);

            $r = $this->httpGet('/');
            $this->assertSame(200, $r['code']);
            $this->assertSame(
                1,
                preg_match(
                    '#<img class="logo" src="' . preg_quote('/assets/images/branding/logo.png', '#') . '"#',
                    $r['body']
                ),
                'src логотипа в шапке должен совпадать с site_logo_url'
            );

            // Админка использует ту же шапку - значит и там.
            $adm = $this->httpGet('/admin.php?tab=users');
            $this->assertStringContainsString(
                'src="/assets/images/branding/logo.png"',
                $adm['body'],
                'админка использует ту же шапку и должна видеть тот же логотип'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Пустое site_logo_url не оставляет шапку без картинки.
     *
     * Пустая ссылка означала бы <img src=""> - браузер перезапрашивает
     * саму страницу и показывает сломанную картинку. Обработчик
     * подставляет штатный файл.
     */
    public function testEmptySiteLogoUrlFallsBackToDefault(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_logo_url' => '']);

            $r = $this->httpGet('/');
            $this->assertSame(200, $r['code']);
            $this->assertStringNotContainsString(
                'class="logo" src=""',
                $r['body'],
                'пустой src оставил бы шапку без логотипа'
            );
            $this->assertSame(
                1,
                preg_match('#<img class="logo" src="[^"]+"#', $r['body']),
                'в шапке должен остаться логотип'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Год основания выводится в подвале.
     *
     * Отдельно от проверки пустого копирайта: раньше там стоял
     * date('Y'), и «© 2022» превратилось бы в «© 2026» само собой в
     * январе. Тест меняет год и ждёт именно его.
     */
    public function testFoundedYearAppearsInFooter(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            // Мусор в поле не должен попасть в подвал.
            $this->saveSettings(['site_founded_year' => '20xz']);

            $r = $this->httpGet('/');
            $this->assertSame(200, $r['code']);
            $this->assertStringContainsString(
                '© 2022 ' . $before['site_name'],
                $r['body'],
                'некорректный год должен заменяться дефолтным, а не печататься как есть'
            );

            $this->saveSettings(['site_founded_year' => '2020']);

            $r2 = $this->httpGet('/');
            $this->assertStringContainsString(
                '© 2020 ' . $before['site_name'],
                $r2['body'],
                'год основания должен выводиться в подвале'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Явный site_footer_copyright выигрывает у собранного.
     */
    public function testExplicitFooterCopyrightWins(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_footer_copyright' => '© 1999 Ручной копирайт']);

            $r = $this->httpGet('/');
            $this->assertSame(200, $r['code']);
            $this->assertStringContainsString(
                '© 1999 Ручной копирайт',
                $r['body'],
                'явный копирайт должен показываться как есть, без подстановки года'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Заголовок вкладки не дублируется, когда он совпадает с названием.
     *
     * Проверка подменой site_name = 'Админ-панель': заголовок админки
     * становится равен названию, и без защиты вышло бы
     * «Админ-панель — Админ-панель».
     */
    public function testTitleDoesNotDuplicateSiteName(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_name' => 'Админ-панель']);

            $adm = $this->httpGet('/admin.php?tab=users');
            $this->assertSame(200, $adm['code']);
            $this->assertSame(
                'Админ-панель',
                $this->titleOf($adm['body']),
                'заголовок не должен удваивать название'
            );

            // У другой страницы заголовок свой, поэтому название дописывается.
            $profile = $this->httpGet('/profile.php');
            $this->assertStringEndsWith(
                '— Админ-панель',
                $this->titleOf($profile['body']),
                'у страницы со своим заголовком название дописывается'
            );

            // Название, уже упомянутое внутри заголовка, не дублируется.
            $this->saveSettings(['site_name' => 'Профиль']);
            $profile2 = $this->httpGet('/profile.php');
            $this->assertSame(
                'Профиль',
                $this->titleOf($profile2['body']),
                'название внутри заголовка не должно повторяться'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * Пустое site_name не оставляет заголовок вкладки пустым.
     *
     * Название не должно быть пустым: иначе заголовок вкладки станет
     * « — » или вовсе без текста, а alt логотипа — пустым. Обработчик
     * подставляет дефолт.
     *
     * Первым шагом сохраняется заведомо другое название. Без этого
     * проверка проходила бы вхолостую: на коде, где site_name вообще
     * не в белом списке, отправка пустой строки просто ничего не
     * меняла, заголовок оставался захардкоженным и непустым — а тест
     * требовал ровно «непустой заголовок». С явным «пробным» названием
     * видно и что значение пишется, и что пустое заменяется дефолтом.
     */
    public function testEmptySiteNameFallsBackToDefault(): void
    {
        $this->loginAsAdmin();
        $before = $this->snapshot();

        try {
            $this->saveSettings(['site_name' => 'Пробное название']);

            $probe = $this->httpGet('/');
            $this->assertSame(200, $probe['code']);
            $this->assertStringEndsWith(
                'Пробное название',
                $this->titleOf($probe['body']),
                'site_name должен записываться: иначе проверка пустого значения бессмысленна'
            );

            $this->saveSettings(['site_name' => '']);

            $r = $this->httpGet('/');
            $this->assertSame(200, $r['code']);

            $title = $this->titleOf($r['body']);
            $this->assertNotSame('', $title, 'заголовок вкладки не должен быть пустым');
            $this->assertStringNotContainsString('— —', $title, 'заголовок не должен состоять из одного разделителя');
            $this->assertStringNotContainsString(
                'Пробное название',
                $title,
                'пустое site_name должно заменяться дефолтом, а не оставаться прежним'
            );
            $this->assertStringContainsString(
                'Aion Corporation',
                $title,
                'дефолтное название должно попасть в заголовок'
            );
            $this->assertSame(
                1,
                preg_match('/<img class="logo" src="[^"]*" alt="[^"]+">/', $r['body']),
                'alt логотипа не должен быть пустым'
            );
        } finally {
            $this->restore($before);
        }
    }

    /**
     * site_name_full больше не существует: одно название вместо двух.
     *
     * Проверяется и в БД, и в коде: ключ удалён миграцией, а в шаблонах
     * и в белом списке saveSettings его быть не должно. Иначе бренд
     * снова разъедется на два поля.
     */
    public function testSingleSiteNameKeyOnly(): void
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $st = db_prepare($mysql, "SELECT COUNT(*) FROM site_settings WHERE setting_key = 'site_name_full'", "");
        $st->execute();
        $leftovers = (int) $st->get_result()->fetch_row()[0];
        $mysql->close();

        $this->assertSame(
            0,
            $leftovers,
            'ключ site_name_full должен быть удалён из site_settings'
        );

        // В коде ключ остался только в одноразовой миграции, которая его
        // читает и удаляет. В шаблонах, админке и модулях его быть не должно.
        $root = dirname(__DIR__);
        $files = [
            '/partials/header.php',
            '/partials/footer.php',
            '/admin/_tab_settings.php',
            '/admin.php',
            '/index.php',
            '/validation/reset.php',
        ];
        foreach ($files as $rel) {
            $path = $root . $rel;
            $this->assertFileExists($path);

            // Именно код, без комментариев: в admin.php осталась
            // историческая заметка о том, что ключ убран, и искать её
            // здесь нельзя - иначе проверка запрещала бы объяснять
            // причину правки.
            $code = php_strip_whitespace($path);
            $this->assertStringNotContainsString(
                'site_name_full',
                $code,
                'в ' . $rel . ' не должно быть site_name_full'
            );
        }
    }

    /**
     * Поле года основания есть в админке и проверяется на сервере.
     *
     * pattern=\d{4} в разметке подсказывает формат, но мусор может
     * прийти мимо браузера (curl, другой клиент), поэтому серверная
     * проверка обязана быть.
     */
    public function testFoundedYearFieldPresentWithPattern(): void
    {
        $this->loginAsAdmin();
        $page = $this->httpGet('/admin.php?tab=settings');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            1,
            preg_match('#<input class="input" name="site_founded_year"[^>]*>#', $page['body'], $m),
            'в настройках должно быть поле года основания'
        );
        $this->assertStringContainsString('pattern="\d{4}"', $m[0], 'у поля должен быть pattern на 4 цифры');

        // Одно поле названия вместо двух.
        $this->assertSame(
            1,
            preg_match_all('#<input class="input" name="site_name"#', $page['body']),
            'должно быть ровно одно поле site_name'
        );
        $this->assertSame(
            0,
            preg_match_all('#name="site_name_full"#', $page['body']),
            'поля site_name_full больше не должно быть'
        );
    }

    /**
     * @return string содержимое meta description, '' если его нет
     */
    private function metaDescriptionOf(string $html): string
    {
        if (preg_match('#<meta name="description" content="([^"]*)"#', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
}