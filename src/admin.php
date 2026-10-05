<?php
/**
 * Админ-панель AION CORP.
 *
 * URL: /admin.php?tab=users|orders|components
 *  - без ?tab        → 302 на ?tab=users (3.7-f-3)
 *  - неизвестный tab → 404
 *  - гость / не админ (свежая группа из БД) → 302 на /profile.php
 *
 * Обработчики POST и разметка вкладок вынесены в src/admin/.
 */

require_once __DIR__ . '/modules/connect.php';
require_once __DIR__ . '/modules/pagination.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

csrf_token();

$mysql = connect();
mysqli_set_charset($mysql, 'utf8');

if (!$mysql) {
    die("Ошибка подключения к базе данных");
}

// Доступ: только залогиненный админ (группа — свежая из БД, не из сессии)
if (empty($_SESSION['user_id'])) {
    header('Location: /profile.php');
    exit();
}

$stmt = db_prepare($mysql, "SELECT user_group FROM `users` WHERE `user_id` = ?", "i", $_SESSION['user_id']);
$stmt->execute();
$adminRow = $stmt->get_result()->fetch_assoc();

if (($adminRow['user_group'] ?? '') !== 'admin') {
    header('Location: /profile.php');
    exit();
}

$isAdmin = true;

// Роутинг вкладки
$tab = $_GET['tab'] ?? '';
if ($tab === '') {
    // 3.7-f-3: вход из профиля ведёт сразу на список пользователей
    header('Location: /admin.php?tab=users');
    exit();
}

$allowedTabs = ['users' => true, 'orders' => true, 'components' => true, 'files' => true, 'dashboard' => true, 'settings' => true];
if (!isset($allowedTabs[$tab])) {
    http_response_code(404);
    exit('Раздел не найден');
}

// 3.7-f-5: пагинация. Считаем ДО вывода HTML: header() в paginate()
// не сработает после старта вывода (headers already sent), и редирект
// с page=99 молча превратился бы в пустую таблицу.
$perPage = 10; // 3.7-f-2-2: было 20

// 3.7-f-2-2 / 3.7-f-4-3: фильтры и сортировка админ-таблиц.
// Условие общее для COUNT, для выборки и для ссылок пагинации.
$listWhere = '';
$listParams = [];
$listTypes = '';
$listQuery = '';  // GET-параметры для сохранения в ссылках
$listOrder = '';  // ORDER BY из белого списка

// экранирование спецсимволов LIKE, чтобы «%» не стал маской
$escapeLike = static function (string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
};

if ($tab === 'components') {
    $fCat = (int) ($_GET['cat'] ?? 0);
    $fSock = (int) ($_GET['sock'] ?? 0);
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if ($fQ !== '') {
        $fQ = $escapeLike($fQ);
    }

    if ($fCat > 0) {
        $listWhere .= ' AND components.category_id = ?';
        $listParams[] = $fCat;
        $listTypes .= 'i';
    }
    // 3.7-f-3-10: сокет применяем только когда категория выбрана и
    // входит в [1, 2, 7] - скрытый select всё равно шлёт значение
    $sockRelevant = in_array($fCat, [1, 2, 7], true);
    if ($fSock > 0 && $sockRelevant) {
        $listWhere .= ' AND components.socket_id = ?';
        $listParams[] = $fSock;
        $listTypes .= 'i';
    }
    if ($fQ !== '') {
        $listWhere .= ' AND components.component_name LIKE ?';
        $listParams[] = '%' . $fQ . '%';
        $listTypes .= 's';
    }

    // FIX-3: только корпуса без картинки (ссылка из файлового менеджера).
    // Признак - флаг без значения: ?tab=components&cat=6&no_image=1
    if (isset($_GET['no_image'])) {
        $listWhere .= " AND (components.image IS NULL OR components.image = '')";
        // флаг остаётся в ссылках пагинации - иначе со страницы 2 он терялся
        $qs_no_image = true;
    }

    // 3.7-f-3-11: сортировка только из белого списка, $_GET в SQL не идёт
    $sortWhitelist = [
        'price_asc' => 'components.component_price ASC',
        'price_desc' => 'components.component_price DESC',
        'amount_asc' => 'components.amount ASC',
        'amount_desc' => 'components.amount DESC',
        'name_asc' => 'components.component_name ASC',
    ];
    $sort = (string) ($_GET['sort'] ?? '');
    $listOrder = $sortWhitelist[$sort] ?? 'components.component_id ASC';

    $qs = [];
    if ($fCat > 0) {
        $qs[] = 'cat=' . $fCat;
    }
    if ($fSock > 0) {
        $qs[] = 'sock=' . $fSock;
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode((string) ($_GET['q'] ?? ''));
    }
    if (isset($sortWhitelist[$sort])) {
        $qs[] = 'sort=' . $sort;
    }
    // FIX-3: флаг переносится в пагинацию, если проставлен выше
    if (!empty($qs_no_image)) {
        $qs[] = 'no_image=1';
    }
    $listQuery = implode('&', $qs);
} elseif ($tab === 'orders') {
    // 3.7-f-4-3: фильтр по статусу, поиск по покупателю, сортировка
    $fStatus = trim((string) ($_GET['status'] ?? ''));
    $fQ = trim((string) ($_GET['q'] ?? ''));
    $statusWhitelist = ['Обрабатывается', 'Собирается', 'Доставляется', 'Выполнен', 'Отменён'];
    if (in_array($fStatus, $statusWhitelist, true)) {
        $listWhere .= ' AND orders.status = ?';
        $listParams[] = $fStatus;
        $listTypes .= 's';
    }
    if ($fQ !== '') {
        $like = '%' . $escapeLike($fQ) . '%';
        $listWhere .= ' AND (users.user_name LIKE ? OR users.user_surname LIKE ? OR users.user_login LIKE ?)';
        $listParams[] = $like;
        $listParams[] = $like;
        $listParams[] = $like;
        $listTypes .= 'sss';
    }
    // 3.7-f-4b-4: сортировка убрана и из UI, и из бэкенда — порядок
    // фиксированный (свежие сверху), параметр sort больше не читается
    $listOrder = 'orders.created_at DESC, orders.order_id DESC';

    $qs = [];
    if (in_array($fStatus, $statusWhitelist, true)) {
        $qs[] = 'status=' . urlencode($fStatus);
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode($fQ);
    }
    $listQuery = implode('&', $qs);
} elseif ($tab === 'users') {
    // 3.7-f-4-3: фильтр по группе и поиск по имени или логину
    $fGroup = trim((string) ($_GET['group'] ?? ''));
    $fQ = trim((string) ($_GET['q'] ?? ''));
    if (in_array($fGroup, ['user', 'admin'], true)) {
        $listWhere .= ' AND users.user_group = ?';
        $listParams[] = $fGroup;
        $listTypes .= 's';
    }
    if ($fQ !== '') {
        $like = '%' . $escapeLike($fQ) . '%';
        $listWhere .= ' AND (users.user_name LIKE ? OR users.user_login LIKE ?)';
        $listParams[] = $like;
        $listParams[] = $like;
        $listTypes .= 'ss';
    }
    // 3.7-f-4b-4: сортировка убрана, порядок по умолчанию — по id
    $listOrder = 'users.user_id ASC';

    $qs = [];
    if (in_array($fGroup, ['user', 'admin'], true)) {
        $qs[] = 'group=' . $fGroup;
    }
    if ($fQ !== '') {
        $qs[] = 'q=' . urlencode($fQ);
    }
    $listQuery = implode('&', $qs);
}

// 3.7-g: дашборду пагинация и счётчик строк не нужны, поэтому весь блок
// с COUNT и paginate() для него пропускается. Иначе пришлось бы держать
// в $countSql фиктивную запись ради значения, которое никто не читает.
// 5-f-2: то же для settings - это форма на пару экранов, а не таблица.
// Без этой правки вкладка падала: в $countSql нет ключа settings,
// $countSql приходил null, и db_prepare() умирал на типе аргумента.
if ($tab !== 'dashboard' && $tab !== 'settings') {
    $countSql = [
        'components' => 'SELECT COUNT(*) FROM components WHERE 1=1' . $listWhere,
    'files' => 'SELECT 0',  // файлы считаются в _tab_files.php
        'users' => 'SELECT COUNT(*) FROM users WHERE 1=1' . $listWhere,
        'orders' => 'SELECT COUNT(*) FROM users,assembly,orders
                     WHERE users.user_id = orders.user_id AND assembly.assembly_id = orders.assembly_id'
                     . $listWhere,
    ][$tab];
    if ($listParams === []) {
        $stmt = db_prepare($mysql, $countSql, '');
    } else {
        $stmt = db_prepare($mysql, $countSql, $listTypes, ...$listParams);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    // [$page, $pages, $offset] доступны во всех вкладках через общий scope
    [$page, $pages, $offset] = paginate($tab, $total, $perPage, $listQuery);
}

// Обработчики POST (перенесено из profile.php, SQL без изменений)
if ($isAdmin && isset($_POST['deleteComponent'])) {
    csrf_verify();
    // 3.7-e: FK assembly.*_id → components.component_id (11 колонок, NO ACTION).
    // Без проверки MySQL выдал бы 23000 пользователю, поэтому считаем
    // использования заранее и отказываем с понятным сообщением.
    $delId = (int) ($_POST['deleteComponentId'] ?? 0);

    if ($delId > 0) {
        $stmt = db_prepare($mysql,
            "SELECT COUNT(*) FROM assembly WHERE
             cpu_id = ? OR motherboard_id = ? OR gpu_id = ? OR ram_id = ?
             OR case_id = ? OR cooler_id = ? OR power_supply_id = ? OR ssd_id = ?
             OR ssd_2_id = ? OR hdd_id = ? OR dvd_id = ?",
            'iiiiiiiiiii', ...array_fill(0, 11, $delId));
        $stmt->execute();
        $usedCount = (int) $stmt->get_result()->fetch_row()[0];

        if ($usedCount > 0) {
            csrf_rotate();
            header('Location: /admin.php?tab=components&error=used&count=' . $usedCount);
            exit();
        }

        $stmt = db_prepare($mysql, "DELETE FROM `components` WHERE `component_id` = ?", "i", $delId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=components');
    exit();
}

if ($isAdmin && isset($_POST['addComponent'])) {
    csrf_verify();
    // 3.7-d: пустой editComponentId = INSERT, заполненный = UPDATE
    $editId = (int) ($_POST['editComponentId'] ?? 0);
    $name = $_POST['nm'] ?? '';
    $price = (int) ($_POST['pr'] ?? 0);
    $amount = (int) ($_POST['col'] ?? 0);
    $categoryId = (int) ($_POST['cat'] ?? 0);
    
    // --- Изображение корпуса (Stage 8) ---
    // Только для category_id = 6 (Корпус). Приоритет по БЛОКУ 3:
    // 1) image_selected_url - выбран существующий файл;
    // 2) image_file - загружен новый;
    // 3) removeImage=1 - отвязать.
    $newImagePath = null;
    if ($categoryId === 6) {
        // Существующий путь (при edit)
        $stmt = db_prepare($mysql, "SELECT image FROM components WHERE component_id = ?", "i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $newImagePath = $row['image'] ?? null;

        // При выборе из загруженных клиент чистит файловый input, но
        // сервер всё равно решает сам: file вытесняет url, url вытесняет
        // простой сброс флага (иначе последний open+cancel сохранил бы
        // stale-выбор).
        if (!empty($_FILES['image_file']['name'])) {
            $imageSelectedUrl = '';
        } else {
            $imageSelectedUrl = trim((string) ($_POST['image_selected_url'] ?? ''));
        }

        // 1) Выбран существующий файл: валидация - путь строго в cases/ и
        // файл физически есть. Формат хранения - без ведущего слеша, как
        // в init.sql у всех существующих записей.
        if (!empty($imageSelectedUrl)) {
            $pickUrl = (string) $imageSelectedUrl;
            if (strpos($pickUrl, 'assets/images/cases/') === 0
                && strpos($pickUrl, '..') === false
                && is_file(__DIR__ . '/assets/images/cases/' . basename($pickUrl))) {
                $newImagePath = $pickUrl;
            }
        }
        // 3) Отвязка: только когда нет ни файла, ни выбора из пикера
        elseif (!empty($_POST['removeImage']) && $_POST['removeImage'] === '1') {
            $newImagePath = null;
        }
        
        // Загрузка нового изображения: сжатие через GD-модуль,
        // дедупликация по MD5 против всех файлов cases/
        if (!empty($_FILES['image_file']['name'])) {
            $file = $_FILES['image_file'];
            
            // 1. Ошибки PHP. INI_SIZE означает, что файл не прошёл лимит
            // php.ini (upload_max_filesize) - для пользователя это та же
            // "слишком большой", просто отсечённая раньше нашей проверки
            if ($file['error'] === UPLOAD_ERR_INI_SIZE) {
                header('Location: /admin.php?tab=components&error=size');
                exit();
            }
            elseif ($file['error'] !== UPLOAD_ERR_OK) {
                header('Location: /admin.php?tab=components&error=upload');
                exit();
            }
            
            // 2. Размер: до 10 МБ (ini поднят до 10M/12M в Dockerfile)
            elseif ($file['size'] > 10 * 1024 * 1024) {
                header('Location: /admin.php?tab=components&error=size');
                exit();
            }
            
            // 3. MIME через finfo
            else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                
                if (!in_array($mime, $allowedMimes, true)) {
                    header('Location: /admin.php?tab=components&error=mime');
                    exit();
                }
                // 4. Проверка что это настоящая картинка
                elseif (!@getimagesize($file['tmp_name'])) {
                    header('Location: /admin.php?tab=components&error=image');
                    exit();
                }
                else {
                    // slug из имени компонента (не файла!) - имя файла
                    // всегда итоговое slug.ext
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)));
                    $slug = trim($slug, '-');
                    if ($slug === '') $slug = 'case';
                    $slug = substr($slug, 0, 40);

                    $targetDir = __DIR__ . '/assets/images/cases/';
                    $result = process_uploaded_image($file['tmp_name'], $targetDir, $slug);

                    if ($result === null) {
                        header('Location: /admin.php?tab=components&error=save');
                        exit();
                    }
                    $newImagePath = 'assets/images/cases/' . basename($result['path']);

                    // Дедупликация по MD5 против всех файлов каталога.
                    // Тот же файл под другим именем не плодит копии: новый
                    // файл удаляется, путь занимал уже существующий.
                    $newMd5 = md5_file($result['path']);
                    $duplicate = null;
                    foreach (scandir($targetDir) as $name2) {
                        if ($name2 === '.' || $name2 === '..' || $name2[0] === '.') continue;
                        if (!preg_match('/\.(jpg|png|gif)$/i', $name2)) continue;
                        $path2 = $targetDir . $name2;
                        if (!is_file($path2) || $path2 === $result['path']) continue;
                        if (md5_file($path2) === $newMd5) {
                            $duplicate = $name2;
                            break;
                        }
                    }
                    if ($duplicate !== null) {
                        @unlink($result['path']);
                        $newImagePath = 'assets/images/cases/' . $duplicate;
                    }
                }
            }
        }
    }

    // 3.7-c: разрешённые поля по категориям. Скрытые input всё равно
    // уходят в $_POST (залипший tdp от «Процессора» после переключения
    // на «ОЗУ»), поэтому сервер обязан резать всё вне маппинга.
    $fieldMap = [
        1  => ['socket_id', 'tdp', 'frequency_mhz', 'video_core'],
        2  => ['socket_id', 'form_factor', 'ram_type'],
        3  => ['capacity_gb', 'memory_type', 'tdp', 'wattage'],
        4  => ['ram_type', 'capacity_gb', 'frequency_mhz'],
        5  => ['wattage', 'form_factor'],
        6  => ['form_factor'],
        7  => ['cooler_type', 'tdp', 'socket_id'],
        8  => ['capacity_gb', 'interface', 'rpm', 'form_factor'],
        9  => ['capacity_gb', 'interface', 'form_factor'],
        10 => ['interface'],
    ];

    if (!empty($name) && !empty($price) && !empty($amount) && !empty($categoryId)) {
        $fields = [
            'component_name' => $name,
            'component_price' => $price,
            'amount' => $amount,
            'category_id' => $categoryId,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'manufacturer' => trim($_POST['manufacturer'] ?? '') ?: null,
            'model' => trim($_POST['model'] ?? '') ?: null,
            'image' => ($categoryId === 6) ? $newImagePath : null,
            'socket_id' => null,
            'tdp' => null,
            'frequency_mhz' => null,
            'video_core' => null,
            'ram_type' => null,
            'capacity_gb' => null,
            'memory_type' => null,
            'wattage' => null,
            'interface' => null,
            'form_factor' => null,
            'rpm' => null,
            'cooler_type' => null,
        ];

        foreach ($fieldMap[$categoryId] ?? [] as $f) {
            if ($f === 'socket_id') {
                // select шлёт socket_id, значение — id из таблицы sockets
                $fields['socket_id'] = ($_POST['socket'] ?? '') !== '' ? (int) $_POST['socket'] : null;
            } elseif ($f === 'video_core') {
                $fields['video_core'] = isset($_POST['video_core']) ? 1 : 0;
            } elseif (($_POST[$f] ?? '') !== '') {
                $fields[$f] = $_POST[$f];
            }
        }

        // 3.7-c: типы выводятся из набора колонок (i для числовых, s для остальных)
        $intCols = ['component_price', 'amount', 'category_id', 'socket_id', 'tdp',
            'frequency_mhz', 'video_core', 'capacity_gb', 'wattage', 'rpm'];
        $cols = array_keys($fields);
        $types = '';
        foreach ($cols as $c) {
            $types .= in_array($c, $intCols, true) ? 'i' : 's';
        }
        if ($editId > 0) {
            // 3.7-d: UPDATE всех 19 колонок — при смене категории поля,
            // не входящие в новый маппинг, обнуляются ($fields = null)
            $sql = "UPDATE `components` SET `" . implode('`=?,`', $cols) . '`=?'
                . " WHERE `component_id`=?";
            // PHP не даёт позиционный аргумент после ... — id дописываем в массив
            $args = array_values($fields);
            $args[] = $editId;
            $stmt = db_prepare($mysql, $sql, $types . 'i', ...$args);
        } else {
            $sql = "INSERT INTO `components` (`" . implode('`,`', $cols) . '`) VALUES('
                . implode(',', array_fill(0, count($cols), '?')) . ')';
            $stmt = db_prepare($mysql, $sql, $types, ...array_values($fields));
        }
        $stmt->execute();
        csrf_rotate();
    }
    header('Location: /admin.php?tab=components');
    exit();
}

// --- Файловый менеджер: удаление (Stage 8-финал) ---
// Архивации больше нет: файл удаляется с диска напрямую и только если
// на него не ссылается ни один компонент. basename режет path traversal,
// поэтому filename вида ../../index.php превращается в index.php и
// ищется внутри каталога cases/.
$filename = isset($_POST['deleteFile']) ? basename((string) ($_POST['filename'] ?? '')) : '';

if ($isAdmin && $filename !== '') {
    csrf_verify();

    $path = __DIR__ . '/assets/images/cases/' . $filename;

    if (is_file($path)) {
        // Привязанный файл удалять нельзя: ссылка из components.image
        // осталась бы битой. Сравнение идёт по имени файла, а не по
        // полному пути - в базе путь может лежать и со слешем, и без.
        $stmt = db_prepare($mysql,
            "SELECT component_id FROM components
             WHERE image IS NOT NULL
               AND image LIKE '%assets/images/cases/%'
               AND SUBSTRING_INDEX(image, '/', -1) = ?",
            "s", $filename);
        $stmt->execute();
        $usedCount = count($stmt->get_result()->fetch_all(MYSQLI_ASSOC));

        if ($usedCount > 0) {
            header('Location: /admin.php?tab=files&error=used');
            exit;
        }

        unlink($path);
    }

    csrf_rotate();
    header('Location: /admin.php?tab=files');
    exit;
}

// --- БЛОК 4: привязка файла к корпусу из файлового менеджера ---
if ($isAdmin && isset($_POST['attachFile'])) {
    csrf_verify();

    $fileUrl = trim((string) ($_POST['fileUrl'] ?? ''));
    $caseId = (int) ($_POST['caseId'] ?? 0);

    // Валидация: путь строго в cases/, без traversal, файл есть на диске.
    // Привязка возможна только к категории 6 - условие прямо в UPDATE.
    if (strpos($fileUrl, 'assets/images/cases/') === 0
        && strpos($fileUrl, '..') === false
        && $caseId > 0
        && is_file(__DIR__ . '/assets/images/cases/' . basename($fileUrl))) {
        $stmt = db_prepare($mysql,
            "UPDATE components SET image = ?
             WHERE component_id = ? AND category_id = 6",
            "si", $fileUrl, $caseId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=files');
    exit;
}

if ($isAdmin && isset($_POST['editOrderStatus'])) {
    csrf_verify();
    $status = $_POST['status'] ?? '';
    $orderId = $_POST['editOrderStatus'];

    $stmt = db_prepare($mysql, "UPDATE orders SET status = ? WHERE order_id = ?", "si", $status, $orderId);
    $stmt->execute();
    csrf_rotate();
    header('Location: /admin.php?tab=orders');
    exit();
}

// 5-f-2: сохранение контактов и текстовых настроек из формы вкладки
// «Настройки сайта». Координаты и снимок карты сюда не попадают: их
// пишет отдельный обработчик saveMapSnapshot, который проверяет PNG.
if ($isAdmin && isset($_POST['saveSettings'])) {
    csrf_verify();

    // Белый список: ключи из POST не должны попадать в запрос как есть.
    // Здесь только значения, ключ берётся из этого списка.
    $allowed = [
        'contact_phone', 'contact_email',
        'contact_vk', 'contact_telegram', 'contact_whatsapp',
        'map_address_text',
    ];

    $values = [];
    foreach ($allowed as $key) {
        $raw = (string) ($_POST[$key] ?? '');
        // trim убирает случайные пробелы по краям, но не трогает
        // внутренние: телефон и адрес пишутся как человек их ввёл
        $values[$key] = trim($raw);
    }

    // Ссылки принимаются только как http(s). Иначе через javascript:
    // можно было бы заставить админа кликнуть по иконке соцсети и
    // выполнить произвольный скрипт. Пустая строка допустима - значит
    // иконку не показываем вовсе.
    foreach (['contact_vk', 'contact_telegram', 'contact_whatsapp'] as $linkKey) {
        if ($values[$linkKey] === '') {
            continue;
        }
        if (!filter_var($values[$linkKey], FILTER_VALIDATE_URL)) {
            $values[$linkKey] = '';
        } elseif (!preg_match('#^https?://#i', $values[$linkKey])) {
            $values[$linkKey] = '';
        }
    }

    site_setting_save($mysql, $values);

    csrf_rotate();
    header('Location: /admin.php?tab=settings');
    exit();
}

// 5-f-2: приём снимка карты с админской страницы.
//
// В POST приходит data:image/png;base64,... из html2canvas. Данные
// приходят из браузера, поэтому проверяем всё, на что можно опереться:
// форму префикса, результат base64_decode, сигнатуру PNG и размер.
// Файл пишется под фиксированным именем - имя из POST не используется
// принципиально, иначе через имя можно было бы записать что угодно
// в любой каталог.
if ($isAdmin && isset($_POST['saveMapSnapshot'])) {
    csrf_verify();

    $dataUrl = (string) ($_POST['map_snapshot'] ?? '');
    $address = trim((string) ($_POST['map_address_text'] ?? ''));
    $lat = (float) ($_POST['map_lat'] ?? 0);
    $lng = (float) ($_POST['map_lng'] ?? 0);

    // этот обработчик может завершиться ошибкой до вывода, а header() после
    // начала вывода не сработает, поэтому проверки идут до любого echo
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        http_response_code(400);
        exit('Invalid image format');
    }

    $binary = base64_decode($m[1], true);
    if ($binary === false || $binary === '') {
        http_response_code(400);
        exit('Invalid base64 payload');
    }

    // снимок делается с scale:2, контейнер примерно 760x400, то есть около
    // 6 МБ. Потолок 8 МБ: он не мешает нормальному снимку и не даёт телу
    // POST выесть память сервера
    if (strlen($binary) > 8 * 1024 * 1024) {
        http_response_code(400);
        exit('Image too large');
    }

    // база64 в принципе можно подделать, а вот эти восемь байт обязаны
    // стоять в начале настоящего PNG
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        http_response_code(400);
        exit('Not a PNG');
    }

    // координаты приходят из тех же данных браузера, но диапазон проверяем:
    // за пределами Земли их не бывает, а мусор в базе не нужен
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        http_response_code(400);
        exit('Invalid coordinates');
    }

    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('Upload directory is not available');
    }

    $path = $dir . '/site-map.png';
    // Пишем во временный файл и переименовываем: частичная запись оставила
    // бы на главном битую картинку, а rename в пределах каталога атомарен
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $binary, LOCK_EX) === false) {
        @unlink($tmp);
        http_response_code(500);
        exit('Failed to write snapshot');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        http_response_code(500);
        exit('Failed to replace snapshot');
    }
    @chmod($path, 0644);

    // Версия в URL - иначе браузер будет показывать старый снимок из кеша.
    // mtime меняется при каждой перезаписи, поэтому ссылка всегда новая.
    $version = (string) @filemtime($path);

    site_setting_save($mysql, [
        'map_snapshot_url' => '/assets/uploads/site-map.png?v=' . $version,
        'map_address_text' => $address,
        'map_lat'          => (string) $lat,
        'map_lng'          => (string) $lng,
        // Масштаб закрепляем за снимком: он дискретен, 15 это то, чем
        // снимали. Иначе настройка расходилась бы с картинкой
        'map_zoom'         => '15',
    ]);

    csrf_rotate();
    header('Location: /admin.php?tab=settings');
    exit();
}

// 3.7-h-1: смена статуса из модалки заказа. Существующий editOrderStatus
// (кнопки в строках таблицы) не меняем — здесь свой обработчик с
// валидацией статуса по белому списку.
if ($isAdmin && isset($_POST['editOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['Обрабатывается', 'Собирается', 'Доставляется', 'Выполнен', 'Отменён'];

    if ($orderId > 0 && in_array($status, $allowed, true)) {
        $stmt = db_prepare($mysql, "UPDATE `orders` SET `status` = ? WHERE `order_id` = ?", "si", $status, $orderId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=orders');
    exit();
}

// 3.7-h-2: редактирование профиля пользователя из модалки.
// Валидация: user_name 2-20 символов (колонка varchar(20)), группа из
// белого списка, телефон по маске проекта. Разжаловать себя нельзя —
// иначе админ теряет доступ к панели.
if ($isAdmin && isset($_POST['editUser'])) {
    csrf_verify();
    $editUserId = (int) ($_POST['editUserId'] ?? 0);
    $editName = trim($_POST['user_name'] ?? '');
    // 3.7-f-4c-3: фамилия не обязательна, но слишком длинное значение
    // в varchar(30) не влезет, поэтому длина всё равно проверяется
    $editSurname = trim($_POST['user_surname'] ?? '');
    $editGroup = $_POST['user_group'] ?? '';
    // 3.7-i-2: адрес разбит на поля. user_address больше не обновляется -
    // это legacy-строка, её значение остаётся как было при миграции.
    $editPostal = trim($_POST['user_postal_code'] ?? '');
    $editRegion = trim($_POST['user_region'] ?? '');
    $editCity = trim($_POST['user_city'] ?? '');
    $editStreet = trim($_POST['user_street'] ?? '');
    $editHouse = trim($_POST['user_house'] ?? '');
    $editApartment = trim($_POST['user_apartment'] ?? '');
    $editPhone = trim($_POST['user_number'] ?? '');

    // ошибки возвращаем на ту же вкладку с сообщением
    $fail = static function (string $code): void {
        header('Location: /admin.php?tab=users&error=' . urlencode($code));
        exit();
    };

    $nameLen = mb_strlen($editName, 'UTF-8');
    if ($nameLen < 2 || $nameLen > 20) {
        $fail('name');
    }
    if (!in_array($editGroup, ['user', 'admin'], true)) {
        $fail('group');
    }
    if (mb_strlen($editSurname, 'UTF-8') > 30) {
        $fail('surname');
    }
    // 3.7-i-2: адресные поля. Все необязательны: у части пользователей
    // адреса нет вовсе, и пустое значение пишется в NULL.
    if ($editCity !== '' && mb_strlen($editCity, 'UTF-8') > 100) {
        $fail('city');
    }
    if ($editRegion !== '' && mb_strlen($editRegion, 'UTF-8') > 100) {
        $fail('region');
    }
    if ($editStreet !== '' && mb_strlen($editStreet, 'UTF-8') > 150) {
        $fail('street');
    }
    if (mb_strlen($editHouse, 'UTF-8') > 20) {
        $fail('house');
    }
    if (mb_strlen($editApartment, 'UTF-8') > 20) {
        $fail('apartment');
    }
    // индекс: 5-10 цифр, пустое значение допустимо
    if ($editPostal !== '' && !preg_match('/^\d{5,10}$/', $editPostal)) {
        $fail('postal');
    }
    if ($editUserId > 0 && $editUserId === (int) ($_SESSION['user_id'] ?? 0) && $editGroup !== 'admin') {
        $fail('self-demote');
    }
    if ($editPhone !== '' && !preg_match('/^\+7\s?[\(]{0,1}\d{3}[\)]{0,1}\s?\d{3}[\-]{0,1}\d{2}[\-]{0,1}\d{2}$/', $editPhone)) {
        $fail('phone');
    }

    if ($editUserId > 0) {
        // существующий пользователь? (иначе UPDATE молча затронет 0 строк)
        $check = db_prepare($mysql, "SELECT user_id FROM users WHERE user_id = ?", "i", $editUserId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $fail('missing');
        }

        // 3.7-i-2: user_address в UPDATE не участвует - legacy остаётся как есть
        $stmt = db_prepare($mysql, "UPDATE users SET user_name = ?, user_surname = ?, user_group = ?,
                                       user_postal_code = ?, user_region = ?, user_city = ?, user_street = ?,
                                       user_house = ?, user_apartment = ?, user_number = ?
                                       WHERE user_id = ?", "ssssssssssi",
                        $editName,
                        $editSurname !== '' ? $editSurname : null,
                        $editGroup,
                        $editPostal !== '' ? $editPostal : null,
                        $editRegion !== '' ? $editRegion : null,
                        $editCity !== '' ? $editCity : null,
                        $editStreet !== '' ? $editStreet : null,
                        $editHouse !== '' ? $editHouse : null,
                        $editApartment !== '' ? $editApartment : null,
                        $editPhone !== '' ? $editPhone : null,
                        $editUserId);
        $stmt->execute();
    } else {
        $fail('missing');
    }

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

// 3.7-g-4: удаление заказа. На orders ссылается только сам заказ,
// других таблиц с FK на orders нет (проверено: единственный FK у orders -
// assembly_id, то есть от заказа к сборке, а не наоборот), поэтому
// удалять можно без проверок использования.

// 7: подтверждение верификации контактов администратором.
//
// Двойная защита. Кнопка в модалке активна только при заявке, но это
// интерфейс: прямой POST без заявки обязан быть отбит здесь, поэтому
// requested = 1 стоит в WHERE. Без этого условия любой, кто открыл
// сессию админа, подтвердил бы любой контакт одной отправкой формы.
//
// После UPDATE заявка снимается: verified = 1 и requested = 0.
// Иначе повторное нажатие снова дало бы UPDATE, хотя суть уже сделана,
// и в модалке остался бы бейдж «Заявка от пользователя».
//
// userId приводится к int и проверяется на ноль: в WHERE подставляется
// параметр, но пустая строка в int дала бы 0 и UPDATE по user_id = 0 -
// обновление ни одной строки, но лишний поход в базу и редирект с
// сообщением об успехе.
if ($isAdmin && isset($_POST['approveEmail'])) {
    csrf_verify();
    $userId = (int) ($_POST['userId'] ?? 0);

    if ($userId > 0) {
        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `email_verified` = 1, `email_verification_requested` = 0
              WHERE `user_id` = ? AND `email_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

if ($isAdmin && isset($_POST['approvePhone'])) {
    csrf_verify();
    $userId = (int) ($_POST['userId'] ?? 0);

    if ($userId > 0) {
        $stmt = db_prepare(
            $mysql,
            "UPDATE `users`
                SET `phone_verified` = 1, `phone_verification_requested` = 0
              WHERE `user_id` = ? AND `phone_verification_requested` = 1",
            "i",
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

if ($isAdmin && isset($_POST['deleteOrder'])) {
    csrf_verify();
    $orderId = (int) ($_POST['orderId'] ?? 0);

    if ($orderId > 0) {
        // проверка существования: UPDATE/DELETE молча затрагивают 0 строк
        $check = db_prepare($mysql, "SELECT order_id FROM orders WHERE order_id = ?", "i", $orderId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            header('Location: /admin.php?tab=orders&error=missing-order');
            exit();
        }

        $stmt = db_prepare($mysql, "DELETE FROM orders WHERE order_id = ?", "i", $orderId);
        $stmt->execute();
    }

    csrf_rotate();
    header('Location: /admin.php?tab=orders');
    exit();
}

if ($isAdmin && isset($_POST['deleteUser'])) {
    csrf_verify();
    $userId = $_POST['userId'] ?? 0;

    $stmt = db_prepare($mysql, "DELETE FROM orders WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    $stmt = db_prepare($mysql, "DELETE FROM users WHERE user_id = ?", "i", $userId);
    $stmt->execute();

    csrf_rotate();
    header('Location: /admin.php?tab=users');
    exit();
}

$pageTitle = 'Админ-панель';
$extraCss = ['/assets/css/profile.css'];
$extraJs  = ['/assets/js/scripts.js'];

// 5-f-2: карта администрируется только на вкладке настроек, а на главной
// это статичный <img>. Leaflet весит около 150 КБ, и тащить его на каждую
// страницу админки незачем. Подключается здесь, до header.php: вкладки
// включаются уже после вывода <head>.
// html2canvas в задании предполагался для снимка карты, но с Leaflet он не
// работает (проверено, снимок выходил пустым), поэтому карта собирается
// вручную в admin-settings.js и библиотека нигде не используется.
if ($tab === 'settings') {
    $extraCss[] = '/assets/vendor/leaflet/leaflet.css';
    $extraJs[]  = '/assets/vendor/leaflet/leaflet.js';
    $extraJs[]  = '/assets/js/admin-settings.js';
}
require __DIR__ . '/partials/header.php';
?>
        <div class="profile-layout">
<?php // 3.7-f-4-1: тот же сайдбар, что и в profile.php ?>
<?php $activeTab = $tab; ?>
<?php require __DIR__ . '/partials/profile-sidebar.php'; ?>
            <div class="profile-content">
<?php
define('ADMIN_CONTEXT', true);
// 3.7-f-4-2: модалки пользователя нужны на всех вкладках - из модалки
// заказа можно перейти к покупателю
require __DIR__ . '/partials/admin-user-modal.php';
// 3.7-g-3: модалка заказа нужна и таблице заказов, и дашборду
require __DIR__ . '/partials/admin-order-modal.php';
// 3.7-g-3: общий контракт data-row для обеих таблиц с заказами
require_once __DIR__ . '/admin/_order_row_data.php';

// 3.7-g: дашборд - первая вкладка в роутинге и первый пункт сайдбара
if ($tab === 'dashboard') {
    require __DIR__ . '/admin/_tab_dashboard.php';
} elseif ($tab === 'components') {
    require __DIR__ . '/admin/_tab_components.php';
} elseif ($tab === 'files') {
    require __DIR__ . '/admin/_tab_files.php';
} elseif ($tab === 'orders') {
    require __DIR__ . '/admin/_tab_orders.php';
} elseif ($tab === 'users') {
    require __DIR__ . '/admin/_tab_users.php';
} elseif ($tab === 'settings') {
    // 5-f-2: настройки читаются один раз на страницу и уходят и в форму,
    // и в модалку снимка карты
    $settings = site_settings($mysql);
    require __DIR__ . '/admin/_tab_settings.php';
} else {
?>
                <section class="card">
                    <h2>Админ-панель</h2>
                    <p style="color:var(--text-secondary);">Выберите раздел</p>
                </section>
<?php
}
?>
            </div>
        </div>
<?php require __DIR__ . '/partials/footer.php'; ?>

<!-- Скрытая форма удаления файла. deleteFile всегда равен 1 - JS только
     подставляет имя файла и отправляет форму. -->
<form id="deleteFileForm" method="post" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
    <input type="hidden" name="filename" value="">
    <input type="hidden" name="deleteFile" value="1">
</form>

<?php $mysql->close(); ?>
