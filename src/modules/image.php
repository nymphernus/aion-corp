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

if (!function_exists('has_alpha_channel')) {
    /**
     * Есть ли в PNG реально используемая прозрачность: пиксели с alpha < 255.
     * Проверка color_type из заголовка не годится: alpha-канал может быть
     * заявлен, но не использоваться, и такой PNG спокойно пережил бы JPEG.
     */
    function has_alpha_channel(string $path): bool
    {
        $im = @imagecreatefrompng($path);
        if ($im === false) {
            return false;
        }

        try {
            if (!imageistruecolor($im)) {
                // палитровый PNG: прозрачность живёт в альфе цветов палитры.
                // В GD alpha = 0 это непрозрачно, 127 - прозрачно полностью,
                // значит "есть прозрачность" = любой alpha > 0
                for ($i = 0, $n = imagecolorstotal($im); $i < $n; $i++) {
                    if (imagecolorsforindex($im, $i)['alpha'] > 0) {
                        return true;
                    }
                }
                return false;
            }

            // truecolor: пробегаем сетку до 64x64 точек - краевые артефакты
            // и полупрозрачные края попадают в выборку с большим запасом
            $w = imagesx($im);
            $h = imagesy($im);
            $stepX = max(1, (int) ceil($w / 64));
            $stepY = max(1, (int) ceil($h / 64));
            for ($y = 0; $y < $h; $y += $stepY) {
                for ($x = 0; $x < $w; $x += $stepX) {
                    $rgba = imagecolorat($im, $x, $y);
                    // (alpha << 24) | (r << 16) | (g << 8) | b, alpha: 0..127.
                    // alpha > 0 = хоть какая-то прозрачность у пикселя
                    if ((($rgba >> 24) & 0x7F) > 0) {
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
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }

        // Итоговый формат. Расширение входа не учитывается вовсе: файлы
        // с подменённым расширением раскидываются по реальному содержимому.
        // gif-анимация сохраняется байтово, всё остальное конвертируется.
        if ($mime === 'image/gif' && is_animated_gif($tmpPath)) {
            $ext = 'gif';
        } elseif ($mime === 'image/png' && has_alpha_channel($tmpPath)) {
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

        // Декодер по фактическому MIME.
        $im = false;
        if ($mime === 'image/jpeg') {
            $im = @imagecreatefromjpeg($tmpPath);
        } elseif ($mime === 'image/png') {
            $im = @imagecreatefrompng($tmpPath);
        } elseif ($mime === 'image/webp') {
            $im = @imagecreatefromwebp($tmpPath);
        } elseif ($mime === 'image/gif') {
            $im = @imagecreatefromgif($tmpPath); // статичный: первый кадр
        }
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