<?php
/**
 * FaviconTest — генератор иконки и загрузка своей.
 *
 * Раньше здесь проверялся favicon.svg, нарисованный вручную. Теперь
 * иконку делает сайт из site_settings: буква и цвет задаются в админке,
 * файл перезаписывается при сохранении, а второй формат и второй ключ
 * настройки больше не нужны.
 *
 * Проверки идут от того, что видит браузер (link в head, отдаваемый
 * файл, байты предпросмотра), а не от вызовов функций: ошибка в том,
 * что в head объявлена не та иконка или файл не с тем MIME, снаружи
 * выглядит как «иконка есть», и заметить её в панели вкладок почти
 * невозможно.
 */

declare(strict_types=1);

final class FaviconTest extends AionTestCase
{
    /** Куда кладёт иконку генератор и что лежит в site_settings. */
    private const GENERATED = '/assets/images/branding/favicon.png';

    /**
     * Все три файла иконки.
     *
     * Тесты и перезаписывают настройки, и создают файлы, а файлы в git
     * не положить. Раньше файл был один, теперь три, и забытый
     * favicon-custom.png пережил бы прогон и показался бы кнопкой
     * следующему тесту.
     */
    private const ICON_FILES = [
        '/assets/images/branding/favicon.png',
        '/assets/images/branding/favicon-generated.png',
        '/assets/images/branding/favicon-custom.png',
    ];

    /** Ключи, которые меняют тесты. */
    private const TOUCHED = ['favicon_letter', 'favicon_bg', 'favicon_text', 'favicon_auto_color', 'site_favicon_png_url', 'favicon_is_custom'];

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
     * Снимок настроек и файла иконки: тесты и перезаписывают настройки,
     * и создают файл, а файл в git не положить.
     *
     * @return array{settings: array<string,string>, file: ?string}
     */
    private function snapshot(): array
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $settings = [];
        foreach (self::TOUCHED as $key) {
            $st = db_prepare($mysql, "SELECT setting_value FROM site_settings WHERE setting_key = ?", "s", $key);
            $st->execute();
            $settings[$key] = (string) ($st->get_result()->fetch_row()[0] ?? '');
        }
        $mysql->close();

        $path = dirname(__DIR__) . self::GENERATED;
        $file = is_file($path) ? (string) file_get_contents($path) : null;

        $files = [];
        foreach (self::ICON_FILES as $rel) {
            $p = dirname(__DIR__) . $rel;
            $files[$rel] = is_file($p) ? (string) file_get_contents($p) : null;
        }

        return ['settings' => $settings, 'file' => $file, 'files' => $files];
    }

    /**
     * @param array{settings: array<string,string>, file: ?string, files: array<string,?string>} $snap
     */
    private function restore(array $snap): void
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        site_setting_save($mysql, $snap['settings']);
        $mysql->close();

        foreach ($snap['files'] ?? [] as $rel => $bytes) {
            $p = dirname(__DIR__) . $rel;
            if ($bytes === null) {
                @unlink($p);
            } else {
                @file_put_contents($p, $bytes);
            }
        }
    }

    private function saveSettings(string $token, array $values): array
    {
        return $this->httpPost('/admin.php?tab=settings', $values + [
            'csrf_token' => $token,
            'saveSettings' => '1',
        ]);
    }

    /**
     * Ставит метку «иконка сгенерирована» прямо в базе.
     *
     * Тест, который проверяет поведение генератора, не должен зависеть от
     * того, что осталось в базе от предыдущих прогонов: метка
     * favicon_is_custom меняет ветку в saveSettings, и её чужое значение
     * превращало бы проверку в лотерею.
     */
    private function markGeneratedFavicon(): void
    {
        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        site_setting_save($mysql, ['favicon_is_custom' => '0']);
        $mysql->close();
    }

    /**
     * Свежие значения из БД, без кэша.
     *
     * site_settings() кэширует результат в static на весь процесс, и в
     * PHPUnit это кэш на все тесты сразу: чтение после HTTP-сохранения
     * возвращало состояние до сохранения, и тесты врали. Для проверок
     * после записи значения читаются напрямую.
     *
     * @return array<string, string>
     */
    private function freshSettings(): array
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

    // ------------------------------------------------------------------
    // Что видит браузер
    // ------------------------------------------------------------------

    /**
     * В head одна иконка, PNG, и её href совпадает с настройкой.
     *
     * Проверяется отсутствие не только svg-типа, но и второго кандидата:
     * при двух link rel="icon" браузер берёт первую поддерживаемую, и
     * смена настройки молча ничего бы не меняла - старая иконка
     * осталась бы в вкладке.
     */
    public function testHeadHasExactlyOnePngIcon(): void
    {
        $page = $this->httpGet('/');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//link[@rel="icon"]'),
            'в head должен быть ровно один link rel="icon"'
        );
        $this->assertSame(
            0,
            $this->xpathCount($page['body'], '//link[@rel="alternate icon"]'),
            'второй кандидат-иконка больше не нужен'
        );
        $this->assertSame(
            0,
            $this->xpathCount($page['body'], '//link[@rel="icon"][@type="image/svg+xml"]'),
            'svg-иконки больше нет'
        );

        $icon = $this->xpathAttrs($page['body'], '//link[@rel="icon"]');
        $this->assertSame('image/png', $icon['type'] ?? '', 'иконка объявлена как image/png');

        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $setting = site_setting(site_settings($mysql), 'site_favicon_png_url', '');
        $mysql->close();
        $this->assertNotSame('', $setting, 'site_favicon_png_url должен быть задан');

        // href может отличаться от настройки на параметр версии (?v=),
        // поэтому сравнивается только путь.
        $href = (string) ($icon['href'] ?? '');
        $this->assertStringStartsWith($setting, $href, 'href иконки должен совпадать с настройкой');
    }

    /**
     * Файл иконки отдаётся как PNG 128x128 и существует по настройке.
     *
     * 128, а не 64: на экранах с удвоенной плотностью пикселей вкладка
     * берёт иконку из файла как есть, и половинный размер выглядел бы
     * мыльным.
     *
     * MIME проверяется по ответу, а не по расширению: с кодом 200 и
     * чужим Content-Type браузер просто не показал бы иконку.
     */
    public function testFaviconFileIsPng128(): void
    {
        $page = $this->httpGet('/');
        $href = $this->xpathAttrs($page['body'], '//link[@rel="icon"]')['href'] ?? '';
        $path = (string) parse_url((string) $href, PHP_URL_PATH);
        $this->assertNotSame('', $path, 'не удалось получить путь иконки из head');

        $icon = $this->httpGet($path);
        $this->assertSame(200, $icon['code'], 'иконка должна отдаваться');
        $this->assertStringContainsString(
            'image/png',
            (string) ($icon['type'] ?? ''),
            'иконка должна отдаваться как image/png'
        );
        $this->assertStringStartsWith("\x89PNG", $icon['body'], 'файл должен начинаться с сигнатуры PNG');

        $info = getimagesizefromstring($icon['body']);
        $this->assertNotFalse($info, 'PNG не читается');
        $this->assertSame(128, (int) $info[0], 'ширина иконки 128px (Retina)');
        $this->assertSame(128, (int) $info[1], 'высота иконки 128px (Retina)');
    }

    /**
     * Углы прозрачные: иконка скруглённая, а не белый квадрат.
     *
     * Проверяется альфа-канал, а не цвет: скругление рисуется
     * прозрачностью, и если альфа пропала, иконка стала бы квадратом с
     * белыми углами - на светлых вкладках это почти незаметно, и
     * заметить можно было бы только на тёмной теме.
     */
    public function testFaviconCornersAreTransparent(): void
    {
        $path = dirname(__DIR__) . self::GENERATED;
        if (!is_file($path)) {
            $this->markTestSkipped('файл иконки не создан - прогоните миграцию или сохраните настройки');
        }
        require_once dirname(__DIR__) . '/modules/image.php';
        $this->assertTrue(
            has_alpha_channel($path),
            'у иконки должен быть альфа-канал, иначе скруглённые углы станут белыми'
        );
    }

    /**
     * Ни svg-файла, ни ключа site_favicon_url не осталось.
     *
     * Ключ проверяется и в БД, и в коде: пока он есть в белом списке
     * saveSettings, в site_settings снова появится строка, и две
     * настройки иконки опять разъедутся.
     */
    public function testSvgFaviconIsGoneCompletely(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__) . '/assets/images/favicon.svg',
            'файл favicon.svg должен быть удалён'
        );

        require_once dirname(__DIR__) . '/modules/site.php';
        $mysql = connect();
        $st = db_prepare($mysql, "SELECT COUNT(*) FROM site_settings WHERE setting_key = 'site_favicon_url'", "");
        $st->execute();
        $leftovers = (int) $st->get_result()->fetch_row()[0];
        $mysql->close();

        $this->assertSame(0, $leftovers, 'ключ site_favicon_url должен быть удалён из site_settings');

        // В коде упоминания остаются только в миграции, которая ключ
        // удаляет.
        foreach (['/partials/header.php', '/admin/_tab_settings.php', '/admin.php'] as $rel) {
            $code = php_strip_whitespace(dirname(__DIR__) . $rel);
            $this->assertStringNotContainsString(
                'site_favicon_url',
                $code,
                'в ' . $rel . ' не должно быть site_favicon_url'
            );
        }
    }

    /**
     * Старый rel="shortcut icon" не используется.
     *
     * Наследие IE: при двух ссылках на иконку браузер мог выбрать
     * устаревшую.
     */
    public function testNoLegacyShortcutIcon(): void
    {
        $page = $this->httpGet('/');
        $this->assertSame(
            0,
            $this->xpathCount($page['body'], '//link[@rel="shortcut icon"]'),
            'rel="shortcut icon" нужно убрать в пользу rel="icon"'
        );
    }

    // ------------------------------------------------------------------
    // Предпросмотр: рисует, но не пишет
    // ------------------------------------------------------------------

    /**
     * Предпросмотр отдаёт PNG и не трогает файл на диске.
     *
     * Неписание проверяется сравнением содержимого файла до и после: если
     * бы предпросмотр писал на диск, он бы перезаписал иконку ещё до
     * нажатия «Сохранить», и отмена эксперимента была бы невозможна.
     */
    public function testPreviewReturnsPngWithoutWriting(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        try {
            $before = $snap['file'];

            $r = $this->httpPost('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']),
                'preview_favicon' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_text' => '#ffffff',
            ]);

            $this->assertSame(200, $r['code'], 'предпросмотр должен отвечать 200');
            $this->assertStringStartsWith("\x89PNG", $r['body'], 'предпросмотр должен отдавать PNG');

            $info = getimagesizefromstring($r['body']);
            $this->assertNotFalse($info);
            $this->assertSame(128, (int) $info[0]);

            // В ответе не должно быть редиректа на страницу: иначе это
            // не предпросмотр, а обычное сохранение под видом превью.
            $this->assertSame('', $r['location'], 'предпросмотр не должен редиректить');

            $path = dirname(__DIR__) . self::GENERATED;
            $after = is_file($path) ? (string) file_get_contents($path) : null;
            $this->assertSame(
                $before,
                $after,
                'предпросмотр обязан только показать картинку и не записывать файл'
            );
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Цвет из предпросмотра именно тот, что запрошен.
     *
     * Считается пиксель в центре: буква там может и оказаться, поэтому
     * берётся точка у левого-нижнего края подложки - она заведомо
     * залита цветом фона и не закрашивается глифом.
     */
    public function testPreviewUsesRequestedColor(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        try {
            $token = $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']);
            $r = $this->httpPost('/admin.php?tab=settings', [
                'csrf_token' => $token,
                'preview_favicon' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_text' => '#ffffff',
            ]);

            $this->assertSame(200, $r['code']);
            // 12px от левого-нижнего угла: скругление там ещё не началось
            // (радиус 24px при размере 128).
            $color = $this->pngPixelColor($r['body'], 12, 116);
            $this->assertSame(
                ['r' => 0xEF, 'g' => 0x44, 'b' => 0x44],
                $color,
                'фон иконки должен быть запрошенным #ef4444'
            );
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Пустая буква и две буквы отклоняются с кодом ошибки, кириллица рисуется.
     *
     * Кириллица проходит через TTF-шрифт : DejaVuSans-Bold
     * умеет любые глифы, и «Ж» на иконке - рабочая буква, а не отказ.
     * Без шрифта кириллица не рисуется (встроенный шрифт GD - ASCII), и
     * тогда отказ остаётся: тихая заглушка «?» выглядела бы рабочей
     * иконкой и молча осталась бы после смены буквы.
     */
    public function testPreviewLetterValidation(): void
    {
        $this->loginAsAdmin();
        $token = $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']);

        foreach ([['', 'favicon_letter_empty'], ['AB', 'favicon_letter_unsupported']] as [$letter, $code]) {
            $r = $this->httpPost('/admin.php?tab=settings', [
                'csrf_token' => $token,
                'preview_favicon' => '1',
                'favicon_letter' => $letter,
                'favicon_bg' => '#C99CFF',
            ]);

            $this->assertSame(422, $r['code'], 'буква «' . $letter . '» должна отклоняться');
            $this->assertSame($code, trim($r['body']), 'в теле должен быть код ошибки');
        }

        // Кириллица: PNG, если шрифт на месте.
        $r = $this->httpPost('/admin.php?tab=settings', [
            'csrf_token' => $token,
            'preview_favicon' => '1',
            'favicon_letter' => 'Ж',
            'favicon_bg' => '#C99CFF',
        ]);
        if (favicon_font_path() !== null) {
            $this->assertSame(200, $r['code'], 'кириллица должна рисоваться через TTF-шрифт');
            $this->assertStringStartsWith("\x89PNG", $r['body']);
        } else {
            $this->assertSame(422, $r['code'], 'без шрифта кириллица не рисуется');
            $this->assertSame('favicon_letter_unsupported', trim($r['body']));
        }
    }

    /**
     * Предпросмотр требует CSRF: иначе через стороннюю страницу можно
     * было бы заставить сервер рисовать картинки.
     */
    public function testPreviewRequiresCsrf(): void
    {
        $this->loginAsAdmin();
        $r = $this->httpPost('/admin.php?tab=settings', [
            'csrf_token' => 'неправильный токен',
            'preview_favicon' => '1',
            'favicon_letter' => 'M',
            'favicon_bg' => '#ef4444',
            'favicon_text' => '#ffffff',
        ]);

        $this->assertNotSame(200, $r['code'], 'предпросмотр без верного CSRF не должен работать');
    }

    // ------------------------------------------------------------------
    // Сохранение: генерация и загрузка
    // ------------------------------------------------------------------

    /**
     * Сохранение перерисовывает иконку и запоминает букву с цветом.
     *
     * Перерисовка идёт всегда, а не только при смене буквы: файл могли
     * удалить с диска, и иконка тогда просто исчезла бы из вкладки.
     *
     * Тест выставляет favicon_is_custom = 0 перед собой, а не полагается
     * на состояние базы. Проверка касается поведения для СГЕНЕРИРОВАННОЙ
     * иконки, и если в базе останется метка загруженной, перерисовки не
     * будет - тест падал бы не из-за своей правки, а из-за мусора,
     * оставшегося после чужого прогона.
     */
    public function testSaveRegeneratesIconOnDisk(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();
        $this->markGeneratedFavicon();

        try {
            $token = $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']);

            $r = $this->saveSettings($token, [
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_text' => '#ffffff',
            ]);
            $this->assertSame(302, $r['code'], 'сохранение должно редиректить');
            $this->assertStringNotContainsString('bad=', $r['location'], 'сохранение прошло без ошибок');

            // Настройки читаются мимо кэша site_settings(): его static
            // переживает весь процесс PHPUnit и вернул бы значения до
            // сохранения.
            $settings = $this->freshSettings();

            $this->assertSame('M', $settings['favicon_letter'], 'буква должна сохраниться');
            $this->assertSame('#ef4444', $settings['favicon_bg'], 'фон должен сохраниться');
            $this->assertSame('#ffffff', $settings['favicon_text'], 'цвет буквы должен сохраниться');
            $this->assertSame('0', $settings['favicon_auto_color'], 'ручной цвет должен записаться как выключенное авто');
            $this->assertSame(
                self::GENERATED,
                $settings['site_favicon_png_url'],
                'иконка должна лежать в branding'
            );

            // Файл совпадает с эталоном - теми же байтами, что рисует
            // favicon_png_bytes для той же буквы и цветов. Сравнение
            // «отличается от прежнего» не годится: буква и цвета могли
            // не меняться, и перерисованная иконка совпала бы с
            // прежней байт в байт.
            require_once dirname(__DIR__) . '/modules/image.php';
            $expected = favicon_png_bytes('M', '#ef4444', '#ffffff');
            $this->assertNotFalse($expected, 'эталон должен строиться');
            $this->assertSame(
                $expected,
                file_get_contents(dirname(__DIR__) . self::GENERATED),
                'файл должен быть перерисован из сохранённых буквы и цветов'
            );

            $info = getimagesize(dirname(__DIR__) . self::GENERATED);
            $this->assertNotFalse($info);
            $this->assertSame(128, (int) $info[0]);
            $this->assertSame(128, (int) $info[1]);
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Авто-цвет буквы: галочка сохраняется, цвет вычисляется по фону.
     *
     * Тёмный фон (#1E3A8A) должен дать белую букву, светлый (#C99CFF) -
     * чёрную. Ручной цвет при включённом авто не сохраняется: пикер
     * выключен, и его значение - мусор, который затёрся бы дефолтом при
     * следующем сохранении.
     */
    public function testAutoColorPickTextByContrast(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        try {
            $token = $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']);

            // Тёмный фон -> белая буква.
            $this->saveSettings($token, [
                'favicon_letter' => 'A',
                'favicon_bg' => '#1E3A8A',
                'favicon_auto_color' => '1',
                'favicon_text' => '#123456',
            ]);
            $settings = $this->freshSettings();
            $this->assertSame('1', $settings['favicon_auto_color'], 'галочка должна сохраниться');
            $this->assertSame('', $settings['favicon_text'], 'ручной цвет при авто должен очищаться');

            $expected = favicon_png_bytes('A', '#1E3A8A', 'auto');
            $this->assertSame(
                $expected,
                file_get_contents(dirname(__DIR__) . self::GENERATED),
                'при авто буква рисуется вычисленным цветом, а не ручным'
            );
            // Авто по яркости BT.601: тёмный фон -> белый текст.
            $this->assertSame('#ffffff', favicon_text_color_auto('#1E3A8A'));
            $this->assertSame('#000000', favicon_text_color_auto('#C99CFF'));
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Мусорный цвет не попадает в настройку: иначе в поле цвета
     * оказалось бы значение, которое input type=color не покажет.
     */
    public function testInvalidColorFallsBackToDefault(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        try {
            $token = $this->extractCsrf($this->httpGet('/admin.php?tab=settings')['body']);
            $r = $this->saveSettings($token, [
                'favicon_letter' => 'A',
                'favicon_bg' => 'не-цвет',
                'favicon_text' => 'тоже не цвет',
            ]);
            $this->assertSame(302, $r['code']);

            // Читается мимо кэша site_settings(): static-кэш переживает
            // весь процесс PHPUnit.
            $settings = $this->freshSettings();

            $this->assertMatchesRegularExpression(
                '/^#[0-9a-fA-F]{6}$/',
                $settings['favicon_bg'],
                'в настройке должен остаться цвет в формате #rrggbb'
            );
            $this->assertSame('#C99CFF', $settings['favicon_bg'], 'некорректный фон должен заменяться дефолтным');
            $this->assertSame(
                '#000000',
                $settings['favicon_text'],
                'некорректный цвет буквы должен заменяться дефолтным (чёрный, как в оригинальном SVG)'
            );
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Загруженная иконка уменьшается до квадрата 64x64 и становится PNG.
     *
     * JPG на входе - самая частая замена картинки, и без приведения к
     * квадрату круг превратился бы в овал: картинка вписывается по
     * меньшей стороне и центрируется.
     */
    public function testUploadBecomesSquarePng(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $jpg = sys_get_temp_dir() . '/favicon-upload-test.jpg';
        $im = imagecreatetruecolor(120, 40);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x11, 0x99, 0x55));
        imagejpeg($im, $jpg, 92);
        imagedestroy($im);

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $r = $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'A',
                'favicon_bg' => '#7C3AED',
                'favicon_upload' => [
                    'name' => 'favicon-upload-test.jpg',
                    'type' => 'image/jpeg',
                    'tmp_name' => $jpg,
                ],
            ]);
            $this->assertSame(302, $r['code']);
            $this->assertStringNotContainsString('bad=', $r['location'], 'загрузка должна пройти без ошибок');

            $path = dirname(__DIR__) . self::GENERATED;
            $this->assertFileExists($path);

            $info = getimagesize($path);
            $this->assertNotFalse($info);
            $this->assertSame(128, (int) $info[0], 'загруженная иконка должна быть 128px (Retina)');
            $this->assertSame(128, (int) $info[1], 'загруженная иконка должна быть квадратной');
            $this->assertSame('image/png', (string) ($info['mime'] ?? ''), 'на выходе всегда PNG');

            // Пиксель из загруженной картинки: зелёный, который был в JPG.
            // Допуск ±1 на канал: JPEG хранит картинку с потерями, и
            // ресемплинг добавляет ещё немного - цвет 0x11 0x99 0x55
            // на выходе даёт 18/153/86. Требовать точного равенства -
            // значит привязать тест к конкретной реализации GD.
            $color = $this->pngPixelColor((string) file_get_contents($path), 64, 64);
            foreach (['r' => 0x11, 'g' => 0x99, 'b' => 0x55] as $ch => $want) {
                $this->assertLessThanOrEqual(
                    1,
                    abs($color[$ch] - $want),
                    $ch . ': должна сохраниться картинка из файла (±1 на JPEG), а не сгенерированная буква'
                );
            }
        } finally {
            @unlink($jpg);
            $this->restore($snap);
        }
    }

    /**
     * Загруженная иконка важнее сгенерированной: если бы файла не было,
     * генератор тут же переписал бы её своей буквой.
     *
     * Форма одна на оба действия, и порядок в обработчике задаёт
     * приоритет: сначала файл, потом генератор.
     */
    public function testUploadBeatsGenerator(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-upload-beats.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0x00, 0xFF));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_upload' => [
                    'name' => 'favicon-upload-beats.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);

            $color = $this->pngPixelColor(
                (string) file_get_contents(dirname(__DIR__) . self::GENERATED),
                64,
                64
            );
            $this->assertSame(
                ['r' => 0x00, 'g' => 0x00, 'b' => 0xFF],
                $color,
                'загруженная картинка должна остаться, а не замениться сгенерированной'
            );
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }

    /**
     * Обычное сохранение настроек не должно затирать загруженную иконку.
     *
     * Форма всегда отправляет favicon_letter и favicon_bg - они в ней есть
     * всегда, независимо от того, трогал их админ или нет. Поэтому второе
     * сохранение (например, после правки телефона) доходило до генератора и
     * перерисовывало иконку буквой, а загруженный PNG тихо пропадал. Пользователь
     * видел, что всё сохранилось, и не понимал, куда делась его картинка.
     *
     * Именно этот сценарий и оправдывает favicon_is_custom: без него
     * нечего отличать «иконку сгенерировали» от «иконку загрузили».
     */
    public function testSavingOtherSettingsKeepsCustomIcon(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-keep-custom.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0x00, 0xFF));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            // первый раз загружаем свою синюю иконку
            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_upload' => [
                    'name' => 'favicon-keep-custom.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);

            $path = dirname(__DIR__) . self::GENERATED;
            $this->assertSame(
                ['r' => 0x00, 'g' => 0x00, 'b' => 0xFF],
                $this->pngPixelColor((string) file_get_contents($path), 64, 64),
                'загрузка должна была записать синюю иконку'
            );

            // второй раз сохраняем настройки, файла не прикладывая -
            // ровно так, как это делает форма при правке контактов
            $page2 = $this->httpGet('/admin.php?tab=settings');
            $this->saveSettings($this->extractCsrf($page2['body']), [
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'contact_phone' => '+7 (999) 123-45-67',
            ]);

            $this->assertSame(
                ['r' => 0x00, 'g' => 0x00, 'b' => 0xFF],
                $this->pngPixelColor((string) file_get_contents($path), 64, 64),
                'повторное сохранение настроек не должно перетирать загруженную иконку'
            );
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }

    /**
 * Загрузка помечает иконку как загруженную.
 *
     * Метка нужна генератору, чтобы не перерисовывать файл при следующих
     * сохранениях, и форме, чтобы показать кнопку возврата. Без неё
     * «своя» и «нарисованная» иконки неразличимы - они лежат в одном файле.
     */
    public function testUploadSetsCustomFlag(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-custom-flag.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0xFF, 0x00));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_upload' => [
                    'name' => 'favicon-custom-flag.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);

            $this->assertSame('1', $this->freshSettings()['favicon_is_custom']);
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }

/**
     * Генератор держит свою копию иконки.
     *
     * Без неё переключатель не показал бы кнопку «Загруженная»: файла
     * просто нет, и копировать было бы нечего.
     */
    public function testGenerateCreatesGeneratedFile(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();
        $this->markGeneratedFavicon();

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $r = $this->saveSettings($this->extractCsrf($page['body']), [
                'favicon_letter' => 'Q',
                'favicon_bg' => '#0EA5E9',
                'favicon_text' => '#ffffff',
            ]);
            $this->assertSame(302, $r['code']);

            require_once dirname(__DIR__) . '/modules/image.php';
            $this->assertTrue(
                favicon_has_generated(),
                'после генерации должен появиться favicon-generated.png'
            );
            $this->assertSame(
                (string) file_get_contents(favicon_path_generated()),
                (string) file_get_contents(favicon_path_active()),
                'активная иконка должна быть копией сгенерированной'
            );
        } finally {
            $this->restore($snap);
        }
    }

    /**
     * Загрузка держит свою копию иконки.
     *
     * Главное здесь - что копия не пропадает. Раньше загрузка
     * перезаписывала сгенерированный файл, и вернуться к букве было уже
     * нечем. Теперь загруженная лежит отдельно.
     */
    public function testUploadCreatesCustomFile(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-upload-variant.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0xFF, 0x00));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_upload' => [
                    'name' => 'favicon-upload-variant.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);

            require_once dirname(__DIR__) . '/modules/image.php';
            $this->assertTrue(favicon_has_custom(), 'после загрузки должен появиться favicon-custom.png');
            $this->assertSame('1', $this->freshSettings()['favicon_is_custom']);
            $this->assertSame(
                ['r' => 0x00, 'g' => 0xFF, 'b' => 0x00],
                $this->pngPixelColor((string) file_get_contents(favicon_path_active()), 64, 64),
                'активной должна стать загруженная картинка'
            );
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }

    /**
     * Переключение на сгенерированную не уничтожает загруженную.
     *
     * Ровно то, ради чего затевались три файла: раньше возврат к букве
     * означал, что загруженная картинка стёрта навсегда.
     */
    public function testSwitchToGeneratedKeepsCustom(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-switch-generated.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0xFF, 0x00));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            require_once dirname(__DIR__) . '/modules/image.php';

            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_text' => '#ffffff',
                'favicon_upload' => [
                    'name' => 'favicon-switch-generated.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);
            $this->assertTrue(favicon_has_generated(), 'нужен сгенерированный вариант до переключения');

            $page2 = $this->httpGet('/admin.php?tab=settings');
            $r = $this->saveSettings($this->extractCsrf($page2['body']), [
                'useFaviconVariant' => 'generated',
            ]);

            $this->assertSame(302, $r['code']);
            $this->assertSame('0', $this->freshSettings()['favicon_is_custom']);
            $this->assertSame(
                (string) file_get_contents(favicon_path_generated()),
                (string) file_get_contents(favicon_path_active()),
                'активной должна стать сгенерированная иконка'
            );
            $this->assertSame(
                ['r' => 0x00, 'g' => 0xFF, 'b' => 0x00],
                $this->pngPixelColor((string) file_get_contents(favicon_path_custom()), 64, 64),
                'загруженная картинка должна остаться на месте после переключения'
            );
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }

    /**
     * Переключение на загруженную не уничтожает сгенерированную.
     *
     * Обратное направление: без него буква была бы одноразовой —
     * загрузил картинку, вернулся к букве, и назад уже некуда.
     */
    public function testSwitchToCustomKeepsGenerated(): void
    {
        $this->loginAsAdmin();
        $snap = $this->snapshot();

        $png = sys_get_temp_dir() . '/favicon-switch-custom.png';
        $im = imagecreatetruecolor(128, 128);
        imagefill($im, 0, 0, imagecolorallocate($im, 0x00, 0x00, 0xFF));
        imagepng($im, $png);
        imagedestroy($im);

        try {
            require_once dirname(__DIR__) . '/modules/image.php';

            $page = $this->httpGet('/admin.php?tab=settings');
            $this->httpPostMultipart('/admin.php?tab=settings', [
                'csrf_token' => $this->extractCsrf($page['body']),
                'saveSettings' => '1',
                'favicon_letter' => 'M',
                'favicon_bg' => '#ef4444',
                'favicon_text' => '#ffffff',
                'favicon_upload' => [
                    'name' => 'favicon-switch-custom.png',
                    'type' => 'image/png',
                    'tmp_name' => $png,
                ],
            ]);

            $page2 = $this->httpGet('/admin.php?tab=settings');
            $this->saveSettings($this->extractCsrf($page2['body']), [
                'useFaviconVariant' => 'generated',
            ]);
            $this->assertSame('0', $this->freshSettings()['favicon_is_custom']);

            $page3 = $this->httpGet('/admin.php?tab=settings');
            $r = $this->saveSettings($this->extractCsrf($page3['body']), [
                'useFaviconVariant' => 'custom',
            ]);

            $this->assertSame(302, $r['code']);
            $this->assertSame('1', $this->freshSettings()['favicon_is_custom']);
            $this->assertSame(
                (string) file_get_contents(favicon_path_custom()),
                (string) file_get_contents(favicon_path_active()),
                'активной должна стать загруженная картинка'
            );
            $this->assertNotSame(
                (string) file_get_contents(favicon_path_custom()),
                (string) file_get_contents(favicon_path_generated()),
                'тест бессмысленен, если оба варианта совпали'
            );
        } finally {
            @unlink($png);
            $this->restore($snap);
        }
    }


    /**
     * Код ошибки переводится в текст на странице.
     *
     * В адрес попадает внутренний код, а админ читает страницу: без
     * словаря он увидел бы branding_mime.
     */
    public function testErrorCodeIsTranslatedOnPage(): void
    {
        $this->loginAsAdmin();
        $r = $this->httpGet('/admin.php?tab=settings&bad=favicon_letter_unsupported');
        $this->assertSame(200, $r['code']);

        $this->assertStringContainsString(
            'загрузите свою картинку',
            $r['body'],
            'код ошибки должен превратиться в понятный текст'
        );
        $this->assertStringNotContainsString(
            'favicon_letter_unsupported',
            $r['body'],
            'внутренний код не должен показываться админу'
        );
    }

    // ------------------------------------------------------------------
    // Разбор PNG без GD
    // ------------------------------------------------------------------

    /**
     * Цвет пикселя PNG вручную.
     *
     * GD в тестах доступен, но разбор написан без него намеренно:
     * getimagesize даёт размер, а цвет пикселя нет, а проверять надо
     * именно цвет - файл может быть нужного размера и при этом
     * содержать не ту картинку.
     *
     * @return array{r:int,g:int,b:int}
     */
    private function pngPixelColor(string $binary, int $x, int $y): array
    {
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($binary, 0, 8), 'это должен быть png');

        $offset = 8;
        $header = '';
        $data = '';
        $width = 0;
        $depth = 0;
        $colorType = 0;

        while ($offset < strlen($binary)) {
            $length = unpack('N', substr($binary, $offset, 4))[1];
            $type = substr($binary, $offset + 4, 4);
            $body = substr($binary, $offset + 8, $length);

            if ($type === 'IHDR') {
                $width = unpack('N', substr($body, 0, 4))[1];
                $depth = ord($body[8]);
                $colorType = ord($body[9]);
            } elseif ($type === 'IDAT') {
                $data .= $body;
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $length;
        }

        $this->assertSame(8, $depth, 'разбирается только 8 бит на канал');
        $this->assertContains($colorType, [2, 6], 'разбираются только truecolor: rgb (2) или rgba (6)');

        $raw = (string) zlib_decode($data);
        $channels = $colorType === 6 ? 4 : 3;
        $stride = $width * $channels;

        // Строки в PNG отфильтрованы, тип может быть любым из пяти, а
        // пропустить их нельзя: Up и Sub смотрят на предыдущую строку и
        // соседние байты.
        $previous = str_repeat("\x00", $stride);
        for ($row = 0; $row <= $y; $row++) {
            $rowStart = $row * ($stride + 1);
            $filter = ord($raw[$rowStart]);
            $line = substr($raw, $rowStart + 1, $stride);
            $this->assertLessThan(5, $filter, "неизвестный фильтр строки {$row}");

            $out = '';
            for ($i = 0; $i < $stride; $i++) {
                $value = ord($line[$i]);
                $left = $i >= $channels ? ord($out[$i - $channels]) : 0;
                $up = ord($previous[$i]);
                $upLeft = $i >= $channels ? ord($previous[$i - $channels]) : 0;

                $result = match ($filter) {
                    0 => $value,
                    1 => $value + $left,
                    2 => $value + $up,
                    3 => $value + intdiv($left + $up, 2),
                    default => $value + $this->paeth($left, $up, $upLeft),
                };

                $out .= chr($result & 0xFF);
            }

            $previous = $out;
        }

        $i = $x * $channels;

        return [
            'r' => ord($previous[$i]),
            'g' => ord($previous[$i + 1]),
            'b' => ord($previous[$i + 2]),
        ];
    }

    /**
     * Предиктор Paeth из спецификации PNG.
     */
    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }
}