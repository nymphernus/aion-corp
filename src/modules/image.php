<?php
/**
 * Сжатие и нормализация изображений при загрузке (Stage 8-финал).
 *
 * Модуль подключается из connect.php: обработчик загрузки в admin.php
 * живёт там же, где остальной код, и не должен знать про порядок require.
 *
 * Правило конвертации одно: на выходе ВСЕГДА PNG.
 *
 * Раньше формат выбирался по прозрачности (альфа -> png, остальное ->
 * jpg), но JPEG в проекте запрещён: JPG несёт потерю качества при
 * каждом пересохранении и не умеет альфу. PNG хранит картинку без
 * потерь и прозрачность, поэтому переход любого формата в PNG
 * безопасен. GIF-анимация - единственное исключение: GD не умеет
 * собирать анимацию обратно, поэтому такой файл копируется байтово.
 *
 * Имя файла всегда slug.png (или slug.gif для анимации).
 */

declare(strict_types=1);

/*
 * ============================================================================
 * Favicon: генератор из буквы и цветов (Stage 9, ПРАВКА 4 и 5)
 * ============================================================================
 *
 * Раньше favicon был нарисован вручную и лежал файлом favicon.svg с
 * буквой «A». Это хардкод бренда: смени название, и иконка продолжала
 * показывать старую букву, а чтобы поменять, приходилось править SVG
 * руками. Теперь иконку делает сайт из site_settings: буква и цвета
 * задаются в админке, файл перезаписывается при сохранении.
 *
 * Только PNG. Причины те же, что и у остальных загрузок: SVG-favicon
 * в <head> требует отдельного ключа настройки, второй <link rel="icon">
 * для браузеров без поддержки svg, и при смене иконки приходилось
 * сбрасывать сразу два ключа, иначе старый кандидат продолжал
 * показываться. Один PNG - одна настройка.
 *
 * Размер 128x128, а не 64x64: на вкладке иконка показывается в 16-32px,
 * но экраны с удвоенной плотностью пикселей берут её из файла как есть,
 * и 64-пиксельная иконка на Retina выглядит заметно мыльнее.
 *
 * Буква рисуется TTF-шрифтом DejaVuSans-Bold (assets/fonts), крупно -
 * 70% размера иконки: во встроенном шрифте GD глиф занимал четверть
 * квадрата и выглядел точкой. Если шрифта нет (странная сборка PHP,
 * вырезанный каталог), рисуется встроенный шрифт - маленький, но
 * рабочий, и иконка не пропадает вовсе.
 *
 * Цвет буквы задаётся вручную или подбирается автоматически по
 * контрасту с фоном: тёмный фон - светлая буква, светлый - тёмная.
 * Формула - стандартная яркость ITU-R BT.601, тот же вес каналов, что
 * в JPEG и в CSS-функции luminance.
 */

if (!function_exists('favicon_letter_error')) {
    /**
     * Подходит ли буква для генератора, и если нет - почему.
     *
     * Пустая строка означает «подходит». Отдельная проверка нужна,
     * потому что saveSettings должен сказать админу причину отказа, а
     * generate_favicon() молча вернёт null.
     *
     * Кириллица теперь проходит: TTF-шрифт умеет любые глифы, а вот
     * встроенный шрифт GD (запасной путь без шрифта) - только ASCII,
     * поэтому кириллица допускается лишь при наличии шрифта.
     */
    function favicon_letter_error(string $letter): string
    {
        $letter = trim($letter);
        if ($letter === '') {
            return 'favicon_letter_empty';
        }
        $hasFont = is_file(__DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf');
        if (mb_strlen($letter, 'UTF-8') !== 1) {
            return 'favicon_letter_unsupported';
        }
        // Латиница и цифры рисуются всегда. Кириллица - только шрифтом.
        if (preg_match('/^[A-Za-z0-9]$/', $letter) === 1) {
            return '';
        }
        return $hasFont ? '' : 'favicon_letter_unsupported';
    }
}

if (!function_exists('favicon_font_path')) {
    /**
     * Путь к шрифту иконки или null, если шрифта нет.
     */
    function favicon_font_path(): ?string
    {
        $path = __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf';
        return is_file($path) ? $path : null;
    }
}

if (!function_exists('favicon_text_color_auto')) {
    /**
     * Цвет буквы по контрасту с фоном.
     *
     * Возвращается чёрный или белый: смешанный цвет по яркости читается
     * хуже, чем чистый, а задача иконки - читаться на 16 пикселях.
     *
     * @return string чёрный ('#000000') или белый ('#ffffff')
     */
    function favicon_text_color_auto(string $bgColor): string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $bgColor, $m) !== 1) {
            return '#000000';
        }
        $hex = $m[1];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // BT.601: веса каналов те же, что в JPEG и в CSS luminance.
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.6 ? '#000000' : '#ffffff';
    }
}

if (!function_exists('favicon_png_bytes')) {
    /**
     * PNG иконки в памяти, без записи на диск.
     *
     * Нужна предпросмотру в админке: он должен показать ровно то, что
     * потом сохранится, а значит рисовать тем же кодом. Побочный
     * эффект тот же - если картинка рисуется, она уже валидна.
     *
     * @param string $letter    буква или цифра
     * @param string $bgColor   фон, #rrggbb
     * @param string $textColor цвет буквы, #rrggbb; 'auto' - по контрасту
     * @param int $size         сторона квадрата, по умолчанию 128 (Retina)
     * @return string|null бинарный PNG или null, если буква не подходит
     */
    function favicon_png_bytes(string $letter, string $bgColor, string $textColor = 'auto', int $size = 128): ?string
    {
        if (favicon_letter_error($letter) !== '' || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $size = max(16, min(512, $size));

        // Цвета: только #rrggbb. Произвольная строка сюда не пробрасывается:
        // значение попадает в input type=color на следующем открытии
        // формы, и мусор в нём сломал бы сам пикер.
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $bgColor, $m) !== 1) {
            // Матч не удался: $m после preg_match пуст, а ниже читается
            // группа $m[1]. Ключ задать нельзя как в матче - [0] это
            // полное совпадение, группа живёт в [1].
            $bgColor = '#C99CFF';
            $m = [1 => 'C99CFF'];
        }
        $br = hexdec(substr($m[1], 0, 2));
        $bg = hexdec(substr($m[1], 2, 2));
        $bb = hexdec(substr($m[1], 4, 2));

        if ($textColor === 'auto' || $textColor === '') {
            $textColor = favicon_text_color_auto($bgColor);
        }
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $textColor, $m) !== 1) {
            $textColor = '#000000';
            $m = [1 => '000000'];
        }
        $tr = hexdec(substr($m[1], 0, 2));
        $tg = hexdec(substr($m[1], 2, 2));
        $tb = hexdec(substr($m[1], 4, 2));

        $im = imagecreatetruecolor($size, $size);
        if ($im === false) {
            return null;
        }

        // Скруглённый квадрат. GD не умеет скруглённые прямоугольники, и
        // тянуть за это curve-функции в проект не хочется: скругление
        // рисуется вручную - сначала весь квадрат заливается
        // прозрачным, потом цветом заливаются полосы и четыре круга в
        // углах.
        //
        // imagesavealpha обязателен: без него GD рисует полупрозрачные
        // цвета, но в файл альфа не попадает - углы становились белыми.
        // Заливка идёт при выключенном смешивании, иначе цвет
        // смешался бы с прозрачной подложкой и стал полупрозрачным.
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $radius = (int) round($size * 0.1875); // 24px при 128
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        $bgAlloc = imagecolorallocate($im, $br, $bg, $bb);
        imagefilledrectangle($im, $radius, 0, $size - 1 - $radius, $size - 1, $bgAlloc);
        imagefilledrectangle($im, 0, $radius, $size - 1, $size - 1 - $radius, $bgAlloc);
        imagefilledellipse($im, $radius, $radius, $radius * 2, $radius * 2, $bgAlloc);
        imagefilledellipse($im, $size - 1 - $radius, $radius, $radius * 2, $radius * 2, $bgAlloc);
        imagefilledellipse($im, $radius, $size - 1 - $radius, $radius * 2, $radius * 2, $bgAlloc);
        imagefilledellipse($im, $size - 1 - $radius, $size - 1 - $radius, $radius * 2, $radius * 2, $bgAlloc);

        // Буква - при включённом смешивании: глиф непрозрачный, и при
        // выключенном его цвет записался бы поверх пикселей вместе с
        // их альфой, то есть углы буквы стали бы дырявыми.
        imagealphablending($im, true);

        $letter = mb_strtoupper(mb_substr(trim($letter), 0, 1, 'UTF-8'), 'UTF-8');
        $textAlloc = imagecolorallocate($im, $tr, $tg, $tb);

        $fontPath = favicon_font_path();
        if ($fontPath !== null) {
            // Крупная буква: 70% стороны квадрата, как в оригинальном
            // SVG. imagettfbbox возвращает рамку глифа: левый-верхний
            // угол не в (0,0), и смещение обязано учитывать это, иначе
            // буква уползала бы вниз-вправо.
            $fontSize = (int) round($size * 0.7);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $letter);
            if ($bbox !== false) {
                $textWidth = abs($bbox[2] - $bbox[0]);
                $textHeight = abs($bbox[1] - $bbox[7]);
                $x = (int) (($size - $textWidth) / 2 - $bbox[0]);
                $y = (int) (($size + $textHeight) / 2 - $bbox[1]);
                imagettftext($im, $fontSize, 0, $x, $y, $textAlloc, $fontPath, $letter);
            }
        } else {
            // Запасной путь без шрифта: встроенный шрифт GD, маленький.
            $font = 5;
            $x = (int) (($size - imagefontwidth($font)) / 2);
            $y = (int) (($size - imagefontheight($font)) / 2);
            imagestring($im, $font, $x, $y, $letter, $textAlloc);
        }

        // Вывод в память: level 9, как и у остальных PNG проекта.
        ob_start();
        $ok = imagepng($im, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return ($ok && $bytes !== '') ? $bytes : null;
    }
}

if (!function_exists('generate_favicon')) {
    /**
     * Записать сгенерированный favicon на диск.
     *
     * Путь фиксированный: branding/favicon.png. Один путь, одна
     * настройка site_favicon_png_url, без варианта с именем из POST.
     *
     * @param string $letter    буква или цифра
     * @param string $bgColor   фон, #rrggbb
     * @param string $textColor цвет буквы, #rrggbb; 'auto' - по контрасту
     * @return string|null путь от корня сайта или null, если не вышло
     */
    function generate_favicon(string $letter, string $bgColor, string $textColor = 'auto'): ?string
    {
        $bytes = favicon_png_bytes($letter, $bgColor, $textColor);
        if ($bytes === null) {
            return null;
        }

        $fullPath = __DIR__ . '/../assets/images/branding/favicon.png';
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (@file_put_contents($fullPath, $bytes) === false) {
            return null;
        }

        return '/assets/images/branding/favicon.png';
    }
}

if (!function_exists('store_favicon_upload')) {
    /**
     * Загруженная пользователем иконка: сжать в квадрат 64x64 и положить
     * на место сгенерированной.
     *
     * Тот же путь, что у генератора - иначе в site_settings пришлось бы
     * держать два ключа, и после загрузки своей иконки старая
     * сгенерированная продолжала бы где-то использоваться.
     *
     * @param array|null $file элемент $_FILES['...']
     * @return array{ok: bool, url: string, error: ?string}
     */
    function store_favicon_upload(?array $file): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'url' => '', 'error' => null];
        }

        // Иконка показывается 16-32px, файл крупнее 2 МБ - это не иконка,
        // а картинка, случайно выбранная не тем диалогом.
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return ['ok' => false, 'url' => '', 'error' => 'branding_size'];
        }

        $mime = detect_image_mime($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['ok' => false, 'url' => '', 'error' => 'branding_mime'];
        }

        $src = decode_image($file['tmp_name'], $mime);
        if ($src === null) {
            return ['ok' => false, 'url' => '', 'error' => 'branding_image'];
        }

        // 128x128, как у генератора: Retina-экраны берут иконку из
        // файла как есть, и на удвоенной плотности половинный размер
        // выглядел бы мыльным.
        $size = 128;
        $im = imagecreatetruecolor($size, $size);
        if ($im === false) {
            return ['ok' => false, 'url' => '', 'error' => 'branding_image'];
        }

        // Прозрачный фон: у иконки часто бывают скруглённые или
        // нестандартные края, и если залить фон белым, квадрат станет
        // видно на любой вкладке.
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);
            imagedestroy($im);
            return ['ok' => false, 'url' => '', 'error' => 'branding_image'];
        }

        // Вписываем по меньшей стороне и центрируем: иконка не должна
        // растягиваться, иначе круг превратится в овал.
        $scale = min($size / $w, $size / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        imagecopyresampled(
            $im,
            $src,
            (int) (($size - $dw) / 2),
            (int) (($size - $dh) / 2),
            0,
            0,
            $dw,
            $dh,
            $w,
            $h
        );
        imagedestroy($src);

        $fullPath = __DIR__ . '/../assets/images/branding/favicon.png';
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($im);
            return ['ok' => false, 'url' => '', 'error' => 'branding_dir'];
        }
        imagepng($im, $fullPath, 9);
        imagedestroy($im);

        return ['ok' => true, 'url' => '/assets/images/branding/favicon.png', 'error' => null];
    }
}

if (!function_exists('detect_image_mime')) {
    /**
     * Реальный MIME файла по содержимому (finfo), не по расширению.
     * Всё остальное в модуле полагается на это: расширение приходит от
     * клиента и может быть подменено.
     */
    function detect_image_mime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime;
    }
}

if (!function_exists('decode_image')) {
    /**
     * Декодер GD по MIME содержимого. GIF отдаёт первый кадр.
     */
    function decode_image(string $path, string $mime)
    {
        if ($mime === 'image/jpeg') {
            return @imagecreatefromjpeg($path);
        }
        if ($mime === 'image/png') {
            return @imagecreatefrompng($path);
        }
        if ($mime === 'image/webp') {
            return @imagecreatefromwebp($path);
        }
        if ($mime === 'image/gif') {
            return @imagecreatefromgif($path);
        }
        return false;
    }
}

if (!function_exists('has_alpha_channel')) {
    /**
     * Есть ли в изображении реально используемая прозрачность: хоть один
     * пиксель с alpha > 0. Шкала GD обратная привычной: 0 = непрозрачно,
     * 127 = полностью прозрачно, поэтому проверка именно "больше нуля".
     *
     * Формат неважен: прозрачный webp должен так же уйти в PNG, как и
     * прозрачный PNG. Раньше проверка жила только в ветке image/png, и
     * webp с альфой молча становился JPEG - прозрачность терялась.
     *
     * Сканируются ВСЕ пиксели, а не сетка: прозрачная область бывает
     * маленькой (иконка 40x40 на холсте 2000x2000), и сетка 64x64 её
     * пропускала. Полный скан 1920x1080 измерен в 0.000с, ранний выход
     * на первом же найденном alpha держит прозрачные файлы быстрыми.
     *
     * Для индексированных изображений альфа лежит в палитре.
     */
    function has_alpha_channel(string $path): bool
    {
        $im = decode_image($path, detect_image_mime($path));
        if ($im === false) {
            return false;
        }

        try {
            if (!imageistruecolor($im)) {
                for ($i = 0, $n = imagecolorstotal($im); $i < $n; $i++) {
                    if (imagecolorsforindex($im, $i)['alpha'] > 0) {
                        return true;
                    }
                }
                return false;
            }

            $w = imagesx($im);
            $h = imagesy($im);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($im, $x, $y);
                    if ((($rgba & 0x7F000000) >> 24) > 0) {
                        return true;
                    }
                }
            }
            return false;
        } finally {
            imagedestroy($im);
        }
    }
}

if (!function_exists('is_animated_gif')) {
    /**
     * Анимированный ли GIF: считаем блоки Graphic Control Extension
     * (0x21 0xF9 0x04) в сырых байтах. Один блок описывает один кадр;
     * у статики кадр один.
     */
    function is_animated_gif(string $path): bool
    {
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 13) {
            return false;
        }

        $frames = 0;
        $offset = 13; // заголовок GIF87a/GIF89a + logical screen descriptor
        while (($pos = strpos($data, "\x21\xF9\x04", $offset)) !== false) {
            $frames++;
            $offset = $pos + 8; // блок: 3 байта сигнатуры + 5 поля + terminator
        }
        return $frames > 1;
    }
}

if (!function_exists('process_uploaded_image')) {
    /**
     * Обработка загруженного изображения: конвертация и сжатие.
     *
     * @param string $tmpPath   путь к исходному файлу (tmp_name)
     * @param string $targetDir каталог сохранения (со слешем на конце)
     * @param string $slug      база имени файла без расширения
     * @param int|null $maxWidth максимальная ширина в px; null - не
     *        уменьшать. Нужен для логотипа: он показывается в шапке
     *        шириной 200px, а хранить его в исходном размере смысла нет.
     *        Картинки корпусов передают null - там уменьшение не
     *        требуется и портит качество при лишнем пересчёте пикселей.
     *        Кадры анимации не трогаются: GIF копируется байтово.
     * @return array{path: string, ext: string}|null null при неудаче
     */
    function process_uploaded_image(string $tmpPath, string $targetDir, string $slug, ?int $maxWidth = null): ?array
    {
        $mime = detect_image_mime($tmpPath);
        if ($mime === '') {
            $mime = 'image/jpeg';
        }

        // Формат на выходе не зависит от входа: всегда PNG, кроме
        // GIF-анимации. has_alpha_channel() в выборе формата больше не
        // участвует - альфа в PNG сохраняется и так, когда она есть, а
        // когда её нет, она и не появится (из JPG её не бывает).
        $ext = ($mime === 'image/gif' && is_animated_gif($tmpPath)) ? 'gif' : 'png';

        $target = $targetDir . $slug . '.' . $ext;

        if ($ext === 'gif') {
            // копия как есть: GD не умеет собирать анимацию обратно
            if (!copy($tmpPath, $target)) {
                return null;
            }
            return ['path' => $target, 'ext' => $ext];
        }

        $im = decode_image($tmpPath, $mime);
        if ($im === false) {
            return null;
        }

        try {
            $srcW = imagesx($im);
            $srcH = imagesy($im);

            // Уменьшение только если ширина больше лимита. Пропорции
            // сохраняются, иначе логотип растянулся бы по высоте.
            $outW = $srcW;
            $outH = $srcH;
            if ($maxWidth !== null && $maxWidth > 0 && $srcW > $maxWidth) {
                $outW = $maxWidth;
                $outH = max(1, (int) round($srcH * ($maxWidth / $srcW)));
            }

            // Пиксели копируем в новый truecolor с отключённым
            // блендингом: иначе полупрозрачные области смешались бы с
            // чёрным и потеряли исходную альфу.
            $out = imagecreatetruecolor($outW, $outH);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            // Заливка прозрачью: у результата всегда есть альфа-канал,
            // даже когда источник был непрозрачным JPG.
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

            $copied = ($outW === $srcW && $outH === $srcH)
                ? imagecopy($out, $im, 0, 0, 0, 0, $srcW, $srcH)
                : imagecopyresampled($out, $im, 0, 0, 0, 0, $outW, $outH, $srcW, $srcH);

            if (!$copied) {
                imagedestroy($out);
                return null;
            }

            // 9 = максимальное сжатие без потери качества (zlib level 9)
            $ok = imagepng($out, $target, 9);
            imagedestroy($out);
            if (!$ok) {
                return null;
            }
        } finally {
            imagedestroy($im);
        }

        return ['path' => $target, 'ext' => $ext];
    }
}

if (!function_exists('slugify_image_name')) {
    /**
     * Имя файла из названия компонента: латиница, цифры, дефис.
     * Не пустой результат обязателен - иначе на диске появился бы файл
     * без расширения.
     */
    function slugify_image_name(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)));
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'case';
        }
        return substr($slug, 0, 40);
    }
}

if (!function_exists('branding_store')) {
    /**
     * Приём картинки бренда (логотип, favicon) из формы настроек.
     *
     * Имя файла на диске фиксированное и берётся из белого списка: имя
     * из POST не используется принципиально, иначе через него можно
     * было бы записать что угодно в любой каталог.
     *
     * SVG-иконка сохраняется как есть: GD конвертирует в растр, а положить
     * растр под именем .svg браузер не нарисует. Проверяются наличие <svg>
     * и отсутствие <script> - иконка попадает в <head> каждой страницы.
     *
     * @param array|null $file  элемент $_FILES['...']
     * @param string $target   logo | favicon
     * @param string $setting  ключ site_settings для URL (не пишется здесь)
     * @return array{url: string, is_svg: bool, error: ?string}
     */
    function branding_store(?array $file, string $target, string $setting): array
    {
        $files = [
            'logo' => ['file' => 'logo.png', 'max_width' => 400],
            'favicon' => ['file' => 'favicon.png', 'max_width' => 512],
        ];
        $cfg = $files[$target] ?? null;

        if ($cfg === null || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['url' => '', 'is_svg' => false, 'error' => null];
        }

        // Логотип показывается в шапке шириной 200px, хранить его в
        // исходном размере смысла нет.
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return ['url' => '', 'is_svg' => false, 'error' => 'branding_size'];
        }

        $mime = detect_image_mime($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
        if (!in_array($mime, $allowed, true)) {
            return ['url' => '', 'is_svg' => false, 'error' => 'branding_mime'];
        }

        // getimagesize() про векторные файлы не знает и возвращает false
        // на валидном svg, поэтому для них проверка другая - ниже, по
        // содержимому. Раньше проверка стояла до ветки и отклоняла любой
        // svg-фavicon с ошибкой branding_image.
        if ($mime !== 'image/svg+xml' && @getimagesize($file['tmp_name']) === false) {
            return ['url' => '', 'is_svg' => false, 'error' => 'branding_image'];
        }

        $dir = __DIR__ . '/../assets/images/branding/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['url' => '', 'is_svg' => false, 'error' => 'branding_dir'];
        }

        // Признак возвращается наружу: вызывающий код решает, в какой
        // ключ настройки положить ссылку. Для svg он определяется здесь,
        // потому что дальше по коду ветка одна, а значение нужно и в
        // случае успеха.
        $isSvg = $mime === 'image/svg+xml';

        // Расширение результата - по типу файла, а не по заготовке: svg
        // обязан лежать под именем .svg, иначе браузер не нарисует его.
        $base = pathinfo($cfg['file'], PATHINFO_FILENAME);
        $path = $dir . $base . ($isSvg ? '.svg' : '.png');

        if ($isSvg) {
            $svg = (string) @file_get_contents($file['tmp_name']);
            $looksLikeSvg = $svg !== ''
                && str_contains(substr($svg, 0, 512), '<svg')
                && preg_match('/<script/i', $svg) === 0;
            if (!$looksLikeSvg) {
                return ['url' => '', 'is_svg' => false, 'error' => 'branding_svg'];
            }
            // Временный файл с последующим rename: частичная запись
            // оставила бы в шапке битую картинку.
            $tmp = $path . '.tmp';
            if (@file_put_contents($tmp, $svg, LOCK_EX) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                return ['url' => '', 'is_svg' => false, 'error' => 'branding_save'];
            }
            $url = '/assets/images/branding/' . $base . '.svg';
        } else {
            $result = process_uploaded_image($file['tmp_name'], $dir, $base, $cfg['max_width']);
            if ($result === null || !is_file($result['path'])) {
                @unlink($dir . $base . '.png');
                return ['url' => '', 'is_svg' => false, 'error' => 'branding_save'];
            }
            $url = '/assets/images/branding/' . basename($result['path']);
        }

        @chmod($path, 0644);

        // Версия в URL: без неё браузер продолжит показывать старую
        // картинку из кеша. mtime меняется при каждой перезаписи.
        $version = (string) @filemtime($path);

        return ['url' => $url . '?v=' . $version, 'is_svg' => $isSvg, 'error' => null];
    }
}

if (!function_exists('store_case_image')) {
    /**
     * Приём одного загруженного файла в каталог корпусов: проверки,
     * конвертация в PNG, дедупликация по MD5.
     *
     * Живёт здесь, а не в admin.php, потому что загрузка идёт из двух
     * мест - из модалки компонента (один файл) и с вкладки «Изображения»
     * (пачка файлов). Дублировать правила означало бы рано или поздно
     * разойтись: сейчас проверки и дедупликация одни и те же.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{ok:bool, path:?string, error:?string, duplicate:bool}
     *         error: size|upload|mime|image|save
     *         duplicate: тот же файл уже лежал в каталоге, новый удалён,
     *         путь указывает на существующий. Считать его сохранённым
     *         нельзя - на диске ничего не добавилось.
     */
    function store_case_image(array $file, string $targetDir, string $slug): array
    {
        $fail = static fn(string $error): array => [
            'ok' => false,
            'path' => null,
            'error' => $error,
            'duplicate' => false,
        ];

        // INI_SIZE - файл не прошёл upload_max_filesize из php.ini. Для
        // пользователя это тот же «слишком большой», просто отсечённый
        // раньше нашей проверки.
        if ($file['error'] === UPLOAD_ERR_INI_SIZE) {
            return $fail('size');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $fail('upload');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            return $fail('size');
        }

        // MIME по содержимому, а не по заголовку от клиента
        $mime = detect_image_mime($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowed, true)) {
            return $fail('mime');
        }
        if (@getimagesize($file['tmp_name']) === false) {
            return $fail('image');
        }

        $result = process_uploaded_image($file['tmp_name'], $targetDir, $slug);
        if ($result === null) {
            return $fail('save');
        }

        // Дедупликация по MD5 против остальных файлов каталога: тот же
        // файл под другим именем не должен плодить копии. JPG в поиске
        // не участвует - таких файлов в проекте больше нет, на выходе
        // у обработчика только png (и gif для анимации).
        $md5 = md5_file($result['path']);
        foreach (scandir($targetDir) as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            if (!preg_match('/\.(png|gif)$/i', $name)) {
                continue;
            }
            $path = $targetDir . $name;
            if ($path === $result['path'] || !is_file($path)) {
                continue;
            }
            if (md5_file($path) === $md5) {
                @unlink($result['path']);
                return [
                    'ok' => true,
                    'path' => $path,
                    'error' => null,
                    'duplicate' => true,
                ];
            }
        }

        return ['ok' => true, 'path' => $result['path'], 'error' => null, 'duplicate' => false];
    }
}