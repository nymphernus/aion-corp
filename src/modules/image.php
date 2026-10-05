<?php
/**
 * Сжатие и нормализация изображений при загрузке (Stage 8-финал).
 *
 * Модуль подключается из connect.php: обработчик загрузки в admin.php
 * живёт там же, где остальной код, и не должен знать про порядок require.
 *
 * Правила конвертации:
 *   - JPEG -> JPEG (перекодирование с quality 85: метаданные и EXIF
 *     отбрасываются, на фотографиях корпусов это большая часть веса);
 *   - PNG с прозрачностью -> PNG (прозрачность терять нельзя);
 *   - PNG без прозрачности -> JPEG (рендеры весили бы сотни КБ);
 *   - GIF анимированный -> GIF как есть (покадровая обработка через GD
 *     всё равно уничтожила бы анимацию);
 *   - GIF статичный -> JPEG (первый кадр);
 *   - WebP -> JPEG (webp больше не основной формат сайта).
 *
 * Имя файла всегда slug.ext, где ext - итоговый формат.
 */

declare(strict_types=1);

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
     * @return array{path: string, ext: string}|null null при неудаче
     */
    function process_uploaded_image(string $tmpPath, string $targetDir, string $slug): ?array
    {
        $mime = detect_image_mime($tmpPath);
        if ($mime === '') {
            $mime = 'image/jpeg';
        }

        // Итоговый формат решает ПРОЗРАЧНОСТЬ, а не формат входа.
        // Раньше ветка была "png с альфой -> png, всё остальное -> jpg",
        // и прозрачный webp молча уезжал в JPEG вместе со своей альфой -
        // так потерялись все 17 картинок корпусов при миграции webp -> jpg.
        // Теперь: анимация байтово gif, альфа любого формата -> png,
        // остальное -> jpg.
        if ($mime === 'image/gif' && is_animated_gif($tmpPath)) {
            $ext = 'gif';
        } elseif (has_alpha_channel($tmpPath)) {
            $ext = 'png';
        } else {
            $ext = 'jpg';
        }

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
            if ($ext === 'png') {
                // прозрачность: альфа-канал сохраняется целиком
                imagealphablending($im, false);
                imagesavealpha($im, true);
                if (!imagepng($im, $target, 6)) {
                    return null;
                }
            } else {
                // JPEG: белый фон вместо прозрачных пикселей,quality 85
                $out = imagecreatetruecolor(imagesx($im), imagesy($im));
                $white = imagecolorallocate($out, 255, 255, 255);
                imagefilledrectangle($out, 0, 0, imagesx($im), imagesy($im), $white);
                if (!imagecopy($out, $im, 0, 0, 0, 0, imagesx($im), imagesy($im))) {
                    imagedestroy($out);
                    return null;
                }
                $ok = imagejpeg($out, $target, 85);
                imagedestroy($out);
                if (!$ok) {
                    return null;
                }
            }
        } finally {
            imagedestroy($im);
        }

        return ['path' => $target, 'ext' => $ext];
    }
}