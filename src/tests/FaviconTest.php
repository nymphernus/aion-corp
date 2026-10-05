<?php
/**
 * FaviconTest — фавикон (Stage 7.5).
 */

declare(strict_types=1);

final class FaviconTest extends AionTestCase
{
    /**
     * Файл, который должен отдаваться как картинка.
     */
    private const SVG = '/assets/images/favicon.svg';

    /**
     * 7.5: svg-фавикон есть, отдаётся как svg и в разметке объявлен.
     *
     * Проверяется содержимое ответа, а не только код ответа: файл с
     * опечаткой в MIME отдался бы с кодом 200, и иконка в браузере
     * просто не появилась бы.
     */
    public function testFaviconSvgIsServedAsSvg(): void
    {
        $page = $this->httpGet('/');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//link[@rel="icon"][@type="image/svg+xml"]'),
            'в head должен быть link rel="icon" на svg'
        );
        $this->assertSame(
            '/assets/images/favicon.svg',
            $this->xpathAttrs($page['body'], '//link[@rel="icon"][@type="image/svg+xml"]')['href'],
            'link должен указывать на favicon.svg'
        );

        // запасной вариант для старых браузеров
        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//link[@rel="alternate icon"][@type="image/png"]'),
            'png должен остаться как запасная иконка'
        );

        // сам файл
        $icon = $this->httpGet(self::SVG);
        $this->assertSame(200, $icon['code'], 'favicon.svg должен отдаваться');
        $this->assertStringContainsString('image/svg+xml', (string) ($icon['type'] ?? ''), 'svg должен отдаваться как image/svg+xml');
        $this->assertStringContainsString('<svg', $icon['body'], 'favicon.svg должен быть svg');
    }

    /**
     * 7.5: фавикон рисуется, а не пустой или обрезанный.
     *
     * Проверяется состав файла: у favicon.svg есть подложка и буква.
     * Файл из одной заливки или из подложки без буквы выглядел бы как
     * цветной квадрат, и заметить это в панели вкладок почти невозможно
     * - квадрат 16px.
     */
    public function testFaviconHasBackgroundAndLetter(): void
    {
        $icon = $this->httpGet(self::SVG);

        $this->assertSame(
            1,
            preg_match('/<svg[^>]*viewBox="0 0 32 32"/', $icon['body']),
            'у favicon должен быть viewBox 0 0 32 32'
        );

        $dom = $this->loadDom($icon['body']);
        $xpath = new DOMXPath($dom);

        // подложка: залитый прямоугольник во весь квадрат
        $bg = $xpath->query('//svg/rect[@width="32"][@height="32"][@fill]');
        $this->assertSame(1, $bg->length, 'должна быть подложка во весь квадрат с заливкой');

        // буква: минимум две ножки и перекладина
        $this->assertGreaterThanOrEqual(
            2,
            $xpath->query('//svg/path')->length,
            'буква должна быть нарисована ножками'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $xpath->query('//svg/rect[@y]')->length,
            'у буквы должна быть перекладина'
        );
    }

    /**
     * 7.5: цвет фавикона совпадает с логотипом.
     *
     * В logo.png заливка плоская, #CF8BFF. Если бы фавикон уехал в
     * градиент из #A020F0 в #FF1493, он был бы другого оттенка, чем
     * логотип в шапке, и это расхождение видно на любой странице -
     * иконка и логотип рядом.
     */
    public function testFaviconColorMatchesLogo(): void
    {
        $logo = $this->readBinary('/assets/images/logo.png');
        $icon = $this->httpGet(self::SVG);

        $this->assertMatchesRegularExpression(
            '/#CF8BFF/i',
            $icon['body'],
            'фавикон должен быть фиолетовым #CF8BFF как логотип'
        );

        // цвет реально есть в логотипе: читаем пиксели квадрата.
        // GD в контейнере нет, поэтому PNG разбирается вручную, и
        // достаточно одного замера - заливка плоская по всему квадрату.
        $color = $this->pngPixelColor($logo, 60, 300);
        $this->assertSame(
            ['r' => 0xCF, 'g' => 0x8B, 'b' => 0xFF],
            $color,
            'заливка логотипа должна быть #CF8BFF'
        );
    }

    /**
     * 7.5: старый rel="shortcut icon" больше не используется.
     *
     * shortcut icon - наследие IE. В HTML5 правильно rel="icon", и
     * оставление старого рядом с новым давало бы две ссылки на один
     * файл, из-за чего браузер мог выбрать устаревшую.
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

    /**
     * Двоичный файл из public_html.
     */
    private function readBinary(string $path): string
    {
        $file = dirname(__DIR__) . $path;
        $this->assertFileExists($file, "нет файла {$path}");

        $content = file_get_contents($file);
        $this->assertIsString($content, "не удалось прочитать {$path}");

        return $content;
    }

    /**
     * Цвет пикселя PNG без GD.
     *
     * Разбираются только те сегменты, что реально нужны: IHDR для
     * размера и IDAT, распакованный zlib-ом. Второй IDAT (если есть)
     * склеивается с первым - png с большим изображением разбивает их
     * на части.
     *
     * Поддержаны два типа цвета: 2 (rgb, три канала) и 6 (rgba, четыре).
     * logo.png оказался rgba: альфа-канал просто отбрасывается, на
     * замере внутри непрозрачного квадрата она всегда 255.
     *
     * @return array{r:int,g:int,b:int}
     */
    private function pngPixelColor(string $binary, int $x, int $y): array
    {
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($binary, 0, 8), 'это должен быть png');

        $offset = 8;
        $header = '';
        $data = '';
        $offsetX = 0;
        $offsetY = 0;

        while ($offset < strlen($binary)) {
            $length = unpack('N', substr($binary, $offset, 4))[1];
            $type = substr($binary, $offset + 4, 4);
            $body = substr($binary, $offset + 8, $length);

            if ($type === 'IHDR') {
                $offsetX = unpack('N', substr($body, 0, 4))[1];
                $offsetY = unpack('N', substr($body, 4, 4))[1];
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
        $this->assertContains(
            $colorType,
            [2, 6],
            'разбираются только truecolor: rgb (2) или rgba (6)'
        );

        $raw = (string) zlib_decode($data);
        $channels = $colorType === 6 ? 4 : 3;
        $stride = $offsetX * $channels;

        // Строки в PNG отфильтрованы, и тип может быть любым из пяти: у
        // logo.png на строке 300 стоял filter 2 (Up), поэтому замер без
        // разбора фильтров вернул бы не тот цвет. Разворачиваются все
        // строки до нужной - Up и Sub смотрят на предыдущую строку и на
        // соседние байты, поэтому пропустить их нельзя.
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