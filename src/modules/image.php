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
            // Пиксели копируем в новый truecolor с отключённым
            // блендингом: иначе полупрозрачные области смешались бы с
            // чёрным и потеряли исходную альфу.
            $out = imagecreatetruecolor(imagesx($im), imagesy($im));
            imagealphablending($out, false);
            imagesavealpha($out, true);
            // Заливка прозрачью: у результата всегда есть альфа-канал,
            // даже когда источник был непрозрачным JPG.
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

            if (!imagecopy($out, $im, 0, 0, 0, 0, imagesx($im), imagesy($im))) {
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