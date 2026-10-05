<?php
/**
 * ImageTest — приведение загруженной картинки к формату хранения (Stage 8).
 *
 * Правило одно: итоговый формат решает ПРОЗРАЧНОСТЬ, а не формат входа.
 * Анимация GIF сохраняется байтово.
 *
 * Регрессия, которую закрывает этот файл: прозрачность проверялась только
 * для image/png, поэтому прозрачный webp молча уезжал в JPEG вместе со
 * своей альфой - так потерялись все 17 картинок корпусов при миграции
 * webp -> jpg. Тест строит прозрачный webp и требует .png на выходе.
 */

declare(strict_types=1);

final class ImageTest extends AionTestCase
{
    /** Каталог для временных файлов теста: /tmp внутри контейнера. */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/modules/connect.php';

        $this->tmpDir = sys_get_temp_dir() . '/aion-image-test-' . uniqid();
        // process_uploaded_image не создаёт каталог: в бою cases/ всегда
        // на месте, а тесту каталог нужен свой
        mkdir($this->tmpDir . '/out', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Картинка с прозрачным фоном и непрозрачным квадратом в центре.
     * Прозрачная область большая, поэтому любая эвристика по выборке
     * пикселей её найдёт - проверяется именно решение по альфе, а не
     * качество детектора.
     */
    private function makeTransparentPng(string $path): void
    {
        $im = imagecreatetruecolor(60, 60);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 20, 20, 40, 40, imagecolorallocate($im, 0, 200, 0));
        imagepng($im, $path);
        imagedestroy($im);
    }

    private function makeOpaquePng(string $path): void
    {
        $im = imagecreatetruecolor(60, 60);
        imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, 30, 60, 90));
        imagepng($im, $path);
        imagedestroy($im);
    }

    /**
     * Прозрачность PNG сохраняется: на выходе .png, и альфа на месте.
     */
    public function testPngWithAlphaStaysPng(): void
    {
        $src = $this->tmpDir . '/alpha.png';
        $this->makeTransparentPng($src);

        $this->assertTrue(has_alpha_channel($src), 'исходный PNG должен определяться как прозрачный');

        $result = process_uploaded_image($src, $this->tmpDir . '/out/', 'alpha-case');
        $this->assertNotNull($result, 'обработчик должен вернуть результат');
        $this->assertSame('png', $result['ext']);
        $this->assertStringEndsWith('.png', $result['path']);
        $this->assertTrue(
            has_alpha_channel($result['path']),
            'сохранённый PNG обязан сохранить прозрачность'
        );
    }

    /**
     * Прозрачность webp - тоже PNG. Это и есть регрессия: раньше такая
     * картинка становилась .jpg, и прозрачный фон заливался белым.
     */
    public function testWebpWithAlphaBecomesPng(): void
    {
        $src = $this->tmpDir . '/alpha.webp';
        $im = imagecreatetruecolor(60, 60);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 20, 20, 40, 40, imagecolorallocate($im, 200, 0, 0));
        imagewebp($im, $src);
        imagedestroy($im);

        $this->assertSame('image/webp', detect_image_mime($src));
        $this->assertTrue(has_alpha_channel($src), 'webp должен определяться как прозрачный');

        $result = process_uploaded_image($src, $this->tmpDir . '/out/', 'alpha-webp');
        $this->assertNotNull($result, 'обработчик должен вернуть результат');
        $this->assertSame('png', $result['ext'], 'прозрачный webp обязан сохраниться как PNG');
        $this->assertTrue(
            has_alpha_channel($result['path']),
            'сохранённый PNG обязан сохранить прозрачность'
        );
    }

    /**
     * Непрозрачная картинка - JPEG, независимо от формата входа.
     * Контроль к двум тестам выше: если детектор начнёт врать в другую
     * сторону, прозрачный фон потеряется уже не там.
     */
    public function testOpaqueImagesBecomeJpg(): void
    {
        $png = $this->tmpDir . '/opaque.png';
        $this->makeOpaquePng($png);
        $this->assertFalse(has_alpha_channel($png), 'непрозрачный PNG не должен считаться прозрачным');

        $fromPng = process_uploaded_image($png, $this->tmpDir . '/out/', 'opaque-png');
        $this->assertNotNull($fromPng);
        $this->assertSame('jpg', $fromPng['ext']);

        $webp = $this->tmpDir . '/opaque.webp';
        $im = imagecreatetruecolor(60, 60);
        imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, 200, 30, 30));
        imagewebp($im, $webp);
        imagedestroy($im);

        $fromWebp = process_uploaded_image($webp, $this->tmpDir . '/out/', 'opaque-webp');
        $this->assertNotNull($fromWebp);
        $this->assertSame('jpg', $fromWebp['ext']);
    }

    /**
     * GIF-анимация сохраняется байтово: GD не умеет собирать анимацию
     * обратно, поэтому файл копируется как есть.
     *
     * Анимация собирается вручную: imagegif() пишет ровно один кадр, а у
     * is_animated_gif() контракт - считать блоки Graphic Control
     * Extension, которых в анимации два.
     */
    public function testAnimatedGifKeptAsIs(): void
    {
        $src = $this->tmpDir . '/anim.gif';
        $im = imagecreatetruecolor(20, 20);
        imagefilledrectangle($im, 0, 0, 19, 19, imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagegif($im);
        $static = (string) ob_get_clean();
        imagedestroy($im);

        $gce = "\x21\xF9\x04\x00\x00\x00\x00\x00";
        $packed = ord($static[10]);
        $pos = 13;
        if (($packed & 0x80) !== 0) {
            $pos += 3 * (2 ** (($packed & 0x07) + 1));   // глобальная палитра
        }
        while ($pos < strlen($static) && $static[$pos] === "\x21") {
            $pos += 2;                                   // маркер + label
            while ($pos < strlen($static) && ord($static[$pos]) !== 0) {
                $pos += ord($static[$pos]) + 1;          // подблоки: длина + данные
            }
            $pos++;
        }
        $first = strpos($static, "\x2C", $pos);
        $this->assertNotFalse($first, 'в однокадровом GIF должен быть image descriptor');

        $trailer = strrpos($static, "\x3B");
        $frame = substr($static, (int) $first, (int) $trailer - (int) $first);
        file_put_contents(
            $src,
            substr($static, 0, (int) $first) . $gce . $frame . $gce . $frame . "\x3B"
        );

        $this->assertTrue(is_animated_gif($src), 'файл с двумя кадрами должен считаться анимацией');
        $this->assertTrue(
            @imagecreatefromgif($src) !== false,
            'собранная анимация обязана читаться GD'
        );

        $result = process_uploaded_image($src, $this->tmpDir . '/out/', 'anim');
        $this->assertNotNull($result);
        $this->assertSame('gif', $result['ext']);
        $this->assertSame(
            filesize($src),
            filesize($result['path']),
            'анимация копируется байтово, без перекодирования'
        );
    }

    /**
     * Маленькая прозрачная область не должна выпадать из выборки.
     *
     * Первая версия детектора смотрела сетку 64x64 точек, и прозрачный
     * уголок на большом холсте мог в неё не попасть - картинка уезжала в
     * JPEG. Теперь сканируются все пиксели, поэтому случай ловится.
     */
    public function testSmallAlphaPatchIsNotMissed(): void
    {
        $src = $this->tmpDir . '/patch.png';
        $w = 600;
        $h = 600;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 40, 40, 40, 0));
        // прозрачная полоска 8x8 в правом нижнем углу
        imagefilledrectangle($im, $w - 9, $h - 9, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagepng($im, $src);
        imagedestroy($im);

        $this->assertTrue(
            has_alpha_channel($src),
            'прозрачная полоска 8x8 обязана находиться, сетка 64x64 её пропускала'
        );
    }
}
