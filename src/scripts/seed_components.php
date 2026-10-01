<?php
/**
 * CLI-сидер каталога комплектующих (Stage 3.7-b).
 *
 * Заполняет новые колонки components (description, manufacturer, model, specs и
 * категорийные характеристики) для 50 компонентов. Существующие 186 строк
 * не трогает.
 *
 * Использование:
 *   php src/scripts/seed_components.php --dry-run
 *   php src/scripts/seed_components.php --if-not-exists
 */

require_once __DIR__ . '/../modules/connect.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

$dryRun = in_array('--dry-run', $argv, true);
$ifNotExists = in_array('--if-not-exists', $argv, true);

// socket_id: 2 LGA1700, 3 AM4, 5 LGA1851, 6 AM5
const SOCK_LGA1700 = 2;
const SOCK_AM4 = 3;
const SOCK_LGA1851 = 5;
const SOCK_AM5 = 6;
const SOCK_COOLER_FALLBACK = 2;

$COOLER_SOCKETS = ['LGA1700', 'AM4', 'AM5', 'LGA1851'];

$rows = [

    // ── Процессоры (cat 1) ───────────────────────────────────────────
    [
        'name' => 'Intel Core i5-12400F 2.5GHz 18MB LGA1700',
        'category_id' => 1, 'socket_id' => SOCK_LGA1700, 'tdp' => 65, 'video_core' => 0,
        'price' => 11990, 'manufacturer' => 'Intel', 'model' => 'i5-12400F',
        'description' => 'Шестиядерный процессор 12-го поколения без встроенной графики. Оптимален для игр и работы в связке с дискретной видеокартой.',
        'frequency_mhz' => 2500,
        'specs' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 2.5, 'boost_ghz' => 4.4, 'cache_mb' => 18, 'lit' => false],
    ],
    [
        'name' => 'Intel Core i5-13400 2.5GHz 20MB LGA1700',
        'category_id' => 1, 'socket_id' => SOCK_LGA1700, 'tdp' => 65, 'video_core' => 1,
        'price' => 15990, 'manufacturer' => 'Intel', 'model' => 'i5-13400',
        'description' => 'Процессор 13-го поколения со встроенной графикой UHD 730. Универсальный вариант для офисных задач и игр без дискретной видеокарты.',
        'frequency_mhz' => 2500,
        'specs' => ['cores' => 10, 'threads' => 16, 'base_ghz' => 2.5, 'boost_ghz' => 4.6, 'cache_mb' => 20, 'lit' => false],
    ],
    [
        'name' => 'AMD Ryzen 5 5600 3.5GHz 32MB AM4',
        'category_id' => 1, 'socket_id' => SOCK_AM4, 'tdp' => 65, 'video_core' => 0,
        'price' => 9490, 'manufacturer' => 'AMD', 'model' => 'Ryzen 5 5600',
        'description' => 'Шестиядерный процессор архитектуры Zen 3 с высокой однопоточной производительностью. Один из самых популярных чипов для игровых сборок.',
        'frequency_mhz' => 3500,
        'specs' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.5, 'boost_ghz' => 4.4, 'cache_mb' => 32, 'lit' => false],
    ],
    [
        'name' => 'AMD Ryzen 7 5700X 3.6GHz 32MB AM4',
        'category_id' => 1, 'socket_id' => SOCK_AM4, 'tdp' => 65, 'video_core' => 0,
        'price' => 14990, 'manufacturer' => 'AMD', 'model' => 'Ryzen 7 5700X',
        'description' => 'Восьмиядерный процессор Zen 3 для игр и стриминга. Высокая многопоточная производительность в играх и монтаже видео.',
        'frequency_mhz' => 3600,
        'specs' => ['cores' => 8, 'threads' => 16, 'base_ghz' => 3.6, 'boost_ghz' => 4.6, 'cache_mb' => 32, 'lit' => false],
    ],
    [
        'name' => 'AMD Ryzen 5 7600 3.8GHz 38MB AM5',
        'category_id' => 1, 'socket_id' => SOCK_AM5, 'tdp' => 65, 'video_core' => 1,
        'price' => 18990, 'manufacturer' => 'AMD', 'model' => 'Ryzen 5 7600',
        'description' => 'Процессор на сокете AM5 со встроенной графикой Radeon. Современная платформа с поддержкой DDR5.',
        'frequency_mhz' => 3800,
        'specs' => ['cores' => 6, 'threads' => 12, 'base_ghz' => 3.8, 'boost_ghz' => 5.1, 'cache_mb' => 38, 'lit' => true],
    ],
    [
        'name' => 'Intel Core Ultra 5 245K 3.3GHz 20MB LGA1851',
        'category_id' => 1, 'socket_id' => SOCK_LGA1851, 'tdp' => 125, 'video_core' => 1,
        'price' => 24990, 'manufacturer' => 'Intel', 'model' => 'Core Ultra 5 245K',
        'description' => 'Процессор на новой платформе LGA1851 с интегрированной графикой Intel Arc Xe-LPG. Разблокированный множитель для разгона.',
        'frequency_mhz' => 3300,
        'specs' => ['cores' => 14, 'threads' => 14, 'base_ghz' => 3.3, 'boost_ghz' => 5.2, 'cache_mb' => 20, 'e_core' => 6],
    ],

    // ── Материнские платы (cat 2) ─────────────────────────────────────
    [
        'name' => 'MSI PRO B660M-A DDR4 LGA1700',
        'category_id' => 2, 'socket_id' => SOCK_LGA1700, 'form_factor' => 'mATX', 'ram_type' => 'DDR4',
        'price' => 9990, 'manufacturer' => 'MSI', 'model' => 'PRO B660M-A',
        'description' => 'Бюджетная материнская плата на чипсете B660 с двумя слотами M.2. Подходит для игровых сборок начального уровня.',
        'specs' => ['chipset' => 'B660', 'ram_slots' => 4, 'm2_slots' => 2, 'sata_ports' => 4, 'wifi' => false],
    ],
    [
        'name' => 'Gigabyte B760M DS3H DDR4 LGA1700',
        'category_id' => 2, 'socket_id' => SOCK_LGA1700, 'form_factor' => 'mATX', 'ram_type' => 'DDR4',
        'price' => 10990, 'manufacturer' => 'Gigabyte', 'model' => 'B760M DS3H',
        'description' => 'Надёжная плата на чипсете B760 с усиленной подсистемой питания и разъёмом M.2 PCIe 4.0.',
        'specs' => ['chipset' => 'B760', 'ram_slots' => 4, 'm2_slots' => 2, 'sata_ports' => 4, 'wifi' => false],
    ],
    [
        'name' => 'ASUS PRIME B550M-K AM4',
        'category_id' => 2, 'socket_id' => SOCK_AM4, 'form_factor' => 'mATX', 'ram_type' => 'DDR4',
        'price' => 9990, 'manufacturer' => 'ASUS', 'model' => 'PRIME B550M-K',
        'description' => 'Проверенная временем плата под процессоры Ryzen 3000 и 5000 серий. Крепкие VRM и полный набор портов.',
        'specs' => ['chipset' => 'B550', 'ram_slots' => 4, 'm2_slots' => 2, 'sata_ports' => 4, 'wifi' => false],
    ],
    [
        'name' => 'MSI B550M PRO-VDH WIFI AM4',
        'category_id' => 2, 'socket_id' => SOCK_AM4, 'form_factor' => 'mATX', 'ram_type' => 'DDR4',
        'price' => 11990, 'manufacturer' => 'MSI', 'model' => 'B550M PRO-VDH WIFI',
        'description' => 'Материнская плата с модулем Wi-Fi 6E и Bluetooth из коробки. Хороший выбор для игровой сборки на AM4.',
        'specs' => ['chipset' => 'B550', 'ram_slots' => 4, 'm2_slots' => 2, 'sata_ports' => 6, 'wifi' => true],
    ],
    [
        'name' => 'ASUS TUF B650-PLUS WIFI AM5',
        'category_id' => 2, 'socket_id' => SOCK_AM5, 'form_factor' => 'ATX', 'ram_type' => 'DDR5',
        'price' => 16990, 'manufacturer' => 'ASUS', 'model' => 'TUF GAMING B650-PLUS WIFI',
        'description' => 'Средний сегмент на новом чипсете B650 с поддержкой DDR5 и Wi-Fi 6. Разъёмы USB 3.2 Gen2.',
        'specs' => ['chipset' => 'B650', 'ram_slots' => 4, 'm2_slots' => 3, 'sata_ports' => 4, 'wifi' => true],
    ],
    [
        'name' => 'Gigabyte Z890 AORUS ELITE WiFi7 LGA1851',
        'category_id' => 2, 'socket_id' => SOCK_LGA1851, 'form_factor' => 'ATX', 'ram_type' => 'DDR5',
        'price' => 28990, 'manufacturer' => 'Gigabyte', 'model' => 'Z890 AORUS ELITE WIFI7',
        'description' => 'Топовая плата на чипсете Z890 для процессоров Core Ultra. Wi-Fi 7, Thunderbolt 4 и мощная подсистема питания.',
        'specs' => ['chipset' => 'Z890', 'ram_slots' => 4, 'm2_slots' => 5, 'sata_ports' => 4, 'wifi' => true],
    ],

    // ── Видеокарты (cat 3) ────────────────────────────────────────────
    [
        'name' => 'MSI GeForce RTX 4060 Ventus 2X Black 8GB GDDR6',
        'category_id' => 3, 'capacity_gb' => 8, 'memory_type' => 'GDDR6', 'tdp' => 115, 'wattage' => 115,
        'price' => 28990, 'manufacturer' => 'MSI', 'model' => 'RTX 4060 VENTUS 2X BLACK 8G OC',
        'description' => 'Компактная видеокарта начального игрового уровня с поддержкой DLSS 3 и рейтрейсинга. Не требует дополнительного питания.',
        'specs' => ['chip' => 'AD107', 'length_mm' => 199, 'psu_req_w' => 550, 'connector' => '1x 8-pin', 'streams' => 24],
    ],
    [
        'name' => 'Palit GeForce RTX 4060 Dual 8GB GDDR6',
        'category_id' => 3, 'capacity_gb' => 8, 'memory_type' => 'GDDR6', 'tdp' => 115, 'wattage' => 115,
        'price' => 27990, 'manufacturer' => 'Palit', 'model' => 'NE64060019P1-1070D',
        'description' => 'Двухвентиковая версия RTX 4060 с низким энергопотреблением. Подходит для компактных корпусов и игр в 1080p.',
        'specs' => ['chip' => 'AD107', 'length_mm' => 172, 'psu_req_w' => 550, 'connector' => '1x 8-pin', 'streams' => 24],
    ],
    [
        'name' => 'Gigabyte GeForce RTX 3060 12GB GDDR3',
        'category_id' => 3, 'capacity_gb' => 12, 'memory_type' => 'GDDR3', 'tdp' => 170, 'wattage' => 170,
        'price' => 23990, 'manufacturer' => 'Gigabyte', 'model' => 'GV-N3060OC12GD',
        'description' => 'Видеокарта с 12 ГБ видеопамяти — запас для текстур в современных играх и работы с 3D. Популярный вариант для 1440p.',
        'specs' => ['chip' => 'GA106', 'length_mm' => 265, 'psu_req_w' => 600, 'connector' => '1x 12-pin', 'streams' => 28],
    ],
    [
        'name' => 'ASUS Dual GeForce RTX 4070 12GB GDDR6X',
        'category_id' => 3, 'capacity_gb' => 12, 'memory_type' => 'GDDR6X', 'tdp' => 200, 'wattage' => 200,
        'price' => 46990, 'manufacturer' => 'ASUS', 'model' => 'TUF-RTX4070-O12G-GAMING',
        'description' => 'Средний класс на архитектуре Ada Lovelace. Трассировка лучей и DLSS 3 Frame Generation в играх высокого разрешения.',
        'specs' => ['chip' => 'AD104', 'length_mm' => 267, 'psu_req_w' => 650, 'connector' => '1x 8-pin', 'streams' => 29],
    ],
    [
        'name' => 'PowerColor RX 7700 XT Hellhound 12GB GDDR6',
        'category_id' => 3, 'capacity_gb' => 12, 'memory_type' => 'GDDR6', 'tdp' => 245, 'wattage' => 245,
        'price' => 39990, 'manufacturer' => 'PowerColor', 'model' => 'RX 7700 XT Hellhound OC',
        'description' => 'Мощная видеокарта AMD с 12 ГБ памяти и большим запасом производительности для 1440p и 4K.',
        'specs' => ['chip' => 'Navi 33', 'length_mm' => 301, 'psu_req_w' => 750, 'connector' => '2x 8-pin', 'streams' => 20],
    ],
    [
        'name' => 'Zotac GeForce GTX 1650 OC 4GB GDDR6',
        'category_id' => 3, 'capacity_gb' => 4, 'memory_type' => 'GDDR6', 'tdp' => 75, 'wattage' => 75,
        'price' => 12990, 'manufacturer' => 'Zotac', 'model' => 'GTX 1650 OC 4G',
        'description' => 'Бюджетная видеокарта для офисных задач, лёгких игр и игровых систем без отдельной видеокарты процессора.',
        'specs' => ['chip' => 'TU117', 'length_mm' => 158, 'psu_req_w' => 400, 'connector' => 'none', 'streams' => 14],
    ],

    // ── Оперативная память (cat 4) ────────────────────────────────────
    [
        'name' => 'Corsair Vengeance LPX 16GB (2x8) DDR4-3200',
        'category_id' => 4, 'ram_type' => 'DDR4', 'capacity_gb' => 16, 'frequency_mhz' => 3200,
        'price' => 3990, 'manufacturer' => 'Corsair', 'model' => 'CMK16GX2M2D3200C16',
        'description' => 'Классический комплект из двух модулей с низким профилем. Не конфликтует с крупными башенными кулерами.',
        'specs' => ['modules' => 2, 'timings' => 'CL16', 'voltage' => 1.35, 'ecc' => false],
    ],
    [
        'name' => 'Kingston FURY Beast 32GB (2x16) DDR4-3600',
        'category_id' => 4, 'ram_type' => 'DDR4', 'capacity_gb' => 32, 'frequency_mhz' => 3600,
        'price' => 7990, 'manufacturer' => 'Kingston', 'model' => 'KF4360C18BB/32',
        'description' => 'Скоростной комплект DDR4 для игровых систем и монтажа видео. Профилировка AGP с теплораспределителем.',
        'specs' => ['modules' => 2, 'timings' => 'CL18', 'voltage' => 1.45, 'ecc' => false],
    ],
    [
        'name' => 'G.Skill Trident Z Neo RGB 32GB (2x16) DDR4-3600',
        'category_id' => 4, 'ram_type' => 'DDR4', 'capacity_gb' => 32, 'frequency_mhz' => 3600,
        'price' => 9990, 'manufacturer' => 'G.Skill', 'model' => 'F4-3600J18ED32GTZR',
        'description' => 'Модули с подсветкой RGB и настройкой через программы производителя. Низкие тайминги для чувствительного отклика.',
        'specs' => ['modules' => 2, 'timings' => 'CL18', 'voltage' => 1.45, 'rgb' => true, 'ecc' => false],
    ],
    [
        'name' => 'Corsair Vengeance 32GB (2x16) DDR5-5600',
        'category_id' => 4, 'ram_type' => 'DDR5', 'capacity_gb' => 32, 'frequency_mhz' => 5600,
        'price' => 9990, 'manufacturer' => 'Corsair', 'model' => 'CMK32GX2M2B5600C36',
        'description' => 'Стартовый комплект DDR5 для новых платформ. Работает на заявленной частоте без ручного разгона.',
        'specs' => ['modules' => 2, 'timings' => 'CL36', 'voltage' => 1.25, 'ecc' => false],
    ],
    [
        'name' => 'Kingston FURY Beast 64GB (2x32) DDR5-6000',
        'category_id' => 4, 'ram_type' => 'DDR5', 'capacity_gb' => 64, 'frequency_mhz' => 6000,
        'price' => 17990, 'manufacturer' => 'Kingston', 'model' => 'KF5600C36BB/64',
        'description' => 'Объём для тяжёлых многопоточных задач, виртуальных машин и работы с большими проектами.',
        'specs' => ['modules' => 2, 'timings' => 'CL36', 'voltage' => 1.35, 'ecc' => false],
    ],
    [
        'name' => 'G.Skill Trident Z RGB 64GB (2x32) DDR5-6400',
        'category_id' => 4, 'ram_type' => 'DDR5', 'capacity_gb' => 64, 'frequency_mhz' => 6400,
        'price' => 22990, 'manufacturer' => 'G.Skill', 'model' => 'F5-6400J3239G64GTZR',
        'description' => 'Высокопроизводительный комплект DDR5 с агрессивными таймингами и RGB-подсветкой для топовых сборок.',
        'specs' => ['modules' => 2, 'timings' => 'CL32', 'voltage' => 1.40, 'rgb' => true, 'ecc' => false],
    ],

    // ── Блоки питания (cat 5) ─────────────────────────────────────────
    [
        'name' => 'Corsair CV650 650W 80+ Bronze',
        'category_id' => 5, 'wattage' => 650, 'form_factor' => 'ATX',
        'price' => 5490, 'manufacturer' => 'Corsair', 'model' => 'CP-9020235-NA',
        'description' => 'Базовый БП для офисных и бюджетных игровых сборок. Защита от КЗ, перегрузки и перегрева.',
        'specs' => ['cert' => '80+ Bronze', 'modular' => 'non-modular', 'fan_mm' => 120, 'connectors' => 4],
    ],
    [
        'name' => 'Cooler Master MWE Bronze 650 V2 650W',
        'category_id' => 5, 'wattage' => 650, 'form_factor' => 'ATX',
        'price' => 6990, 'manufacturer' => 'Cooler Master', 'model' => 'MWE-650BR-V2',
        'description' => 'Обновлённая версия солидного БП начального уровня с кабелями для видеокарты и процессора.',
        'specs' => ['cert' => '80+ Bronze', 'modular' => 'non-modular', 'fan_mm' => 120, 'connectors' => 4],
    ],
    [
        'name' => 'be quiet! System Power 10 850W 80+ Bronze',
        'category_id' => 5, 'wattage' => 850, 'form_factor' => 'ATX',
        'price' => 9490, 'manufacturer' => 'be quiet!', 'model' => 'BN344',
        'description' => 'Тихий блок питания с вентилятором, который почти не слышно. Запас мощности для мощной видеокарты.',
        'specs' => ['cert' => '80+ Bronze', 'modular' => 'non-modular', 'fan_mm' => 120, 'connectors' => 4],
    ],
    [
        'name' => 'Corsair RM750e 750W 80+ Gold',
        'category_id' => 5, 'wattage' => 750, 'form_factor' => 'ATX',
        'price' => 10990, 'manufacturer' => 'Corsair', 'model' => 'CP-9020265-NA',
        'description' => 'Модульный БП с сертификатом 80+ Gold и японскими конденсаторами. Надёжен для игровых систем среднего класса.',
        'specs' => ['cert' => '80+ Gold', 'modular' => 'modular', 'fan_mm' => 140, 'connectors' => 6],
    ],
    [
        'name' => 'MSI MAG A850GL PCIE5 850W 80+ Gold',
        'category_id' => 5, 'wattage' => 850, 'form_factor' => 'ATX',
        'price' => 13990, 'manufacturer' => 'MSI', 'model' => 'MAG A850GL PCIE5',
        'description' => 'Полностью модульный блок питания с нативным разъёмом 12VHPWR для видеокарт нового поколения.',
        'specs' => ['cert' => '80+ Gold', 'modular' => 'full-modular', 'fan_mm' => 135, 'connectors' => 6],
    ],

    // ── Корпуса (cat 6) ───────────────────────────────────────────────
    [
        'name' => 'Zalman i3 Neo ATX Mid-Tower',
        'category_id' => 6, 'form_factor' => 'Mid-Tower',
        'price' => 3990, 'manufacturer' => 'Zalman', 'model' => 'i3 NEO',
        'description' => 'Бюджетный корпус с прозрачной боковой панелью и посадочными местами для вентиляторов. Входит в комплект LED-подсветка.',
        'specs' => ['max_gpu_mm' => 320, 'max_cooler_mm' => 160, 'psu_form' => 'ATX', 'mobo' => ['mATX', 'ITX']],
    ],
    [
        'name' => 'DeepCool CH510 Mid-Tower ATX',
        'category_id' => 6, 'form_factor' => 'Mid-Tower',
        'price' => 4990, 'manufacturer' => 'DeepCool', 'model' => 'R-CH510-BKNSE1-G-1',
        'description' => 'Просторный корпус с хорошей продуваемостью и возможностью установки до трёх вентиляторов на фронтальной панели.',
        'specs' => ['max_gpu_mm' => 380, 'max_cooler_mm' => 175, 'psu_form' => 'ATX', 'mobo' => ['E-ATX', 'ATX', 'mATX']],
    ],
    [
        'name' => 'Cougar MX330 Aero ATX Mid-Tower',
        'category_id' => 6, 'form_factor' => 'Mid-Tower',
        'price' => 5990, 'manufacturer' => 'Cougar', 'model' => 'CGR MX330-A',
        'description' => 'Корпус с акцентной RGB-подсветкой и вентиляторами в комплекте. Хорошо сочетается с игровыми видеокартами.',
        'specs' => ['max_gpu_mm' => 330, 'max_cooler_mm' => 160, 'psu_form' => 'ATX', 'mobo' => ['mATX', 'ITX']],
    ],
    [
        'name' => 'Thermaltake View 37 TG ARGB Mid-Tower',
        'category_id' => 6, 'form_factor' => 'Mid-Tower',
        'price' => 9990, 'manufacturer' => 'Thermaltake', 'model' => 'CA-1D3-00NNAR',
        'description' => 'Две стеклянные панели и три ARGB-вентилятора в комплекте. Показывает сборку со всех сторон.',
        'specs' => ['max_gpu_mm' => 400, 'max_cooler_mm' => 180, 'psu_form' => 'ATX', 'mobo' => ['E-ATX', 'ATX', 'mATX']],
    ],
    [
        'name' => 'Corsair 4000D Airflow ATX Mid-Tower',
        'category_id' => 6, 'form_factor' => 'Mid-Tower',
        'price' => 8990, 'manufacturer' => 'Corsair', 'model' => 'CC-9011200-WW',
        'description' => 'Продуваемый корпус с фронтальной сеткой и высоким потолком. Совместим с длинными видеокартами и башенными кулерами.',
        'specs' => ['max_gpu_mm' => 360, 'max_cooler_mm' => 170, 'psu_form' => 'ATX', 'mobo' => ['E-ATX', 'ATX', 'mATX']],
    ],

    // ── Кулеры (cat 7) ────────────────────────────────────────────────
    [
        'name' => 'DeepCool AK400 155mm 1500rpm',
        'category_id' => 7, 'socket_id' => SOCK_COOLER_FALLBACK, 'cooler_type' => 'air', 'tdp' => 220,
        'price' => 2990, 'manufacturer' => 'DeepCool', 'model' => 'R-AK400-BKNNM-G',
        'description' => 'Компактный башенный кулер с четырьмя теплотрубками и вентилятором 120 мм. Хватает для процессоров с TDP до 220 Вт.',
        'specs' => ['height_mm' => 155, 'rpm_max' => 1500, 'noise_db' => 27, 'heatpipes' => 4, 'sockets' => $COOLER_SOCKETS],
    ],
    [
        'name' => 'DeepCool AK620 160mm 1850rpm',
        'category_id' => 7, 'socket_id' => SOCK_COOLER_FALLBACK, 'cooler_type' => 'air', 'tdp' => 260,
        'price' => 5990, 'manufacturer' => 'DeepCool', 'model' => 'R-AK620-BKNNM-G',
        'description' => 'Двухбашенный кулер с шестью теплотрубками. Справляется с горячими процессорами, включая Core Ultra и Ryzen 7.',
        'specs' => ['height_mm' => 160, 'rpm_max' => 1850, 'noise_db' => 28, 'heatpipes' => 6, 'sockets' => $COOLER_SOCKETS],
    ],
    [
        'name' => 'Arctic Freezer 34 eSports DUO 120mm',
        'category_id' => 7, 'socket_id' => SOCK_COOLER_FALLBACK, 'cooler_type' => 'air', 'tdp' => 150,
        'price' => 2990, 'manufacturer' => 'Arctic', 'model' => 'ACFRE00137A',
        'description' => 'Однобайенетный кулер с вентилятором Pwm PST, который не останавливается на низких оборотах. Тихий в простое.',
        'specs' => ['height_mm' => 157, 'rpm_max' => 1200, 'noise_db' => 0.3, 'heatpipes' => 4, 'sockets' => $COOLER_SOCKETS],
    ],
    [
        'name' => 'ID-Cooling SE-214-XT 120mm',
        'category_id' => 7, 'socket_id' => SOCK_COOLER_FALLBACK, 'cooler_type' => 'air', 'tdp' => 150,
        'price' => 1990, 'manufacturer' => 'ID-Cooling', 'model' => 'SE-214-XT ARGB',
        'description' => 'Самый доступный кулер в подборке. Достаточный отвод тепла для процессоров среднего сегмента.',
        'specs' => ['height_mm' => 150, 'rpm_max' => 1950, 'noise_db' => 30, 'heatpipes' => 4, 'sockets' => $COOLER_SOCKETS],
    ],
    [
        'name' => 'DeepCool LE520 140mm',
        'category_id' => 7, 'socket_id' => SOCK_COOLER_FALLBACK, 'cooler_type' => 'air', 'tdp' => 250,
        'price' => 5490, 'manufacturer' => 'DeepCool', 'model' => 'R-LE520-BKAMNF-G-140',
        'description' => 'Кулер с вентилятором 140 мм для тихой работы под нагрузкой. Пять теплотрубок, пылевой фильтр на основании.',
        'specs' => ['height_mm' => 157, 'rpm_max' => 1400, 'noise_db' => 27, 'heatpipes' => 5, 'sockets' => $COOLER_SOCKETS],
    ],

    // ── HDD (cat 8) ───────────────────────────────────────────────────
    [
        'name' => 'Seagate Barracuda 2TB 7200rpm SATA III',
        'category_id' => 8, 'capacity_gb' => 2000, 'interface' => 'SATA III', 'rpm' => 7200, 'form_factor' => '3.5"',
        'price' => 5490, 'manufacturer' => 'Seagate', 'model' => 'ST2000DM008',
        'description' => 'Надёжный диск для хранения игр, медиа и резервных копий. Высокая скорость вращения обеспечивает быстрый отклик.',
        'specs' => ['rpm' => 7200, 'cache_mb' => 256, 'form_factor' => '3.5"', 'capacity_tb' => 2],
    ],
    [
        'name' => 'Toshiba P300 4TB 5400rpm SATA III',
        'category_id' => 8, 'capacity_gb' => 4000, 'interface' => 'SATA III', 'rpm' => 5400, 'form_factor' => '3.5"',
        'price' => 8490, 'manufacturer' => 'Toshiba', 'model' => 'HDPE420',
        'description' => 'Тихоходный диск большой ёмкости для видеоархива и резервного хранения данных.',
        'specs' => ['rpm' => 5400, 'cache_mb' => 64, 'form_factor' => '3.5"', 'capacity_tb' => 4],
    ],
    [
        'name' => 'WD Purple 4TB 5400rpm SATA III',
        'category_id' => 8, 'capacity_gb' => 4000, 'interface' => 'SATA III', 'rpm' => 5400, 'form_factor' => '3.5"',
        'price' => 9990, 'manufacturer' => 'Western Digital', 'model' => 'WD40PURZ',
        'description' => 'Диск для систем видеонаблюдения с повышенной наработкой и ресурсом под постоянную запись.',
        'specs' => ['rpm' => 5400, 'cache_mb' => 256, 'form_factor' => '3.5"', 'surveillance' => true],
    ],

    // ── SSD (cat 9) ───────────────────────────────────────────────────
    [
        'name' => 'Samsung 980 Pro 1TB M.2 NVMe PCIe 4.0',
        'category_id' => 9, 'capacity_gb' => 1000, 'interface' => 'M.2 NVMe PCIe 4.0', 'form_factor' => 'M.2',
        'price' => 8990, 'manufacturer' => 'Samsung', 'model' => 'MZ-V8V1T0BW',
        'description' => 'Флагманский NVMe-накопитель с ресурсом 600 ТБ записи. Быстрый запуск игр и системы в целом.',
        'specs' => ['read_mbs' => 7000, 'write_mbs' => 5000, 'tbw' => 600, 'nand' => 'TLC', 'dram' => true],
    ],
    [
        'name' => 'Kingston NV2 1TB M.2 NVMe PCIe 4.0',
        'category_id' => 9, 'capacity_gb' => 1000, 'interface' => 'M.2 NVMe PCIe 4.0', 'form_factor' => 'M.2',
        'price' => 5990, 'manufacturer' => 'Kingston', 'model' => 'SNV2S/1000G',
        'description' => 'Бюджетный NVMe для сборок начального уровня. Достаточная скорость для повседневных задач и игр.',
        'specs' => ['read_mbs' => 3500, 'write_mbs' => 2100, 'tbw' => 320, 'nand' => 'TLC', 'dram' => false],
    ],
    [
        'name' => 'WD Blue SN580 1TB M.2 NVMe PCIe 4.0',
        'category_id' => 9, 'capacity_gb' => 1000, 'interface' => 'M.2 NVMe PCIe 4.0', 'form_factor' => 'M.2',
        'price' => 7490, 'manufacturer' => 'Western Digital', 'model' => 'WDS100T2B0E',
        'description' => 'Сбалансированный накопитель с хорошей скоростью записи. Популярный выбор для игровых систем.',
        'specs' => ['read_mbs' => 4150, 'write_mbs' => 4150, 'tbw' => 600, 'nand' => 'TLC', 'dram' => false],
    ],
    [
        'name' => 'Samsung 870 EVO 1TB 2.5" SATA III',
        'category_id' => 9, 'capacity_gb' => 1000, 'interface' => 'SATA III', 'form_factor' => '2.5"',
        'price' => 8490, 'manufacturer' => 'Samsung', 'model' => 'MZ-77E1T0',
        'description' => 'Проверенный SATA-накопитель для систем без свободного слота M.2. Скорость до 560 МБ/с.',
        'specs' => ['read_mbs' => 560, 'write_mbs' => 530, 'tbw' => 600, 'nand' => 'TLC', 'dram' => true],
    ],
    [
        'name' => 'Crucial P3 Plus 2TB M.2 NVMe PCIe 4.0',
        'category_id' => 9, 'capacity_gb' => 2000, 'interface' => 'M.2 NVMe PCIe 4.0', 'form_factor' => 'M.2',
        'price' => 14990, 'manufacturer' => 'Crucial', 'model' => 'CT2000P3PSSD8',
        'description' => 'Двухтерабайтный NVMe для больших библиотек игр и рабочих проектов. Оптимальный вариант по цене за гигабайт.',
        'specs' => ['read_mbs' => 5000, 'write_mbs' => 3600, 'tbw' => 800, 'nand' => 'TLC', 'dram' => false],
    ],

    // ── Приводы (cat 10) ──────────────────────────────────────────────
    [
        'name' => 'ASUS DRW-24D5MT SATA',
        'category_id' => 10, 'interface' => 'SATA',
        'price' => 1990, 'manufacturer' => 'ASUS', 'model' => 'DRW-24D5MT',
        'description' => 'Внутренний DVD-привод для чтения и записи дисков формата DVD и CD. Подключается через SATA.',
        'specs' => ['type' => 'DVD-RW', 'cache_kb' => 2048, 'silent' => true],
    ],
    [
        'name' => 'LG GH24NSD1 DVD-RW SATA',
        'category_id' => 10, 'interface' => 'SATA',
        'price' => 2190, 'manufacturer' => 'LG', 'model' => 'GH24NSD1',
        'description' => 'Надёжный привод для чтения и записи DVD/CD. Корпус M-ATX, полная совместимость с настольными ПК.',
        'specs' => ['type' => 'DVD-RW', 'cache_kb' => 2048, 'silent' => false],
    ],
    [
        'name' => 'Lite-On DVD-RW DUO DVDRW-16S1L11 SATA',
        'category_id' => 10, 'interface' => 'SATA',
        'price' => 2490, 'manufacturer' => 'Lite-On', 'model' => 'DVDRW-16S1L11',
        'description' => 'Двухскоростной привод с поддержкой M-DISC для долговечного хранения данных на специальных дисках.',
        'specs' => ['type' => 'DVD-RW', 'cache_kb' => 2048, 'mdisc' => true],
    ],
];

try {
    $mysql = connect();
} catch (Throwable $e) {
    echo "Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}

// Колонки и их значения (порядок фиксирован — он же в SQL)
$inserted = 0;
$skipped = 0;
$errors = 0;

foreach ($rows as $i => $r) {
    $params = [
        $r['name'],
        $r['description'],
        $r['category_id'],
        $r['socket_id'] ?? null,
        $r['video_core'] ?? null,
        $r['tdp'] ?? null,
        null, // image
        $r['price'],
        10,   // amount
        $r['manufacturer'],
        $r['model'],
        json_encode($r['specs'], JSON_UNESCAPED_UNICODE),
        $r['ram_type'] ?? null,
        $r['capacity_gb'] ?? null,
        $r['frequency_mhz'] ?? null,
        $r['memory_type'] ?? null,
        $r['wattage'] ?? null,
        $r['interface'] ?? null,
        $r['form_factor'] ?? null,
        $r['rpm'] ?? null,
        $r['cooler_type'] ?? null,
    ];

    // Типы для bind_param выводим из самих значений — меньше шанс ошибиться
    $types = '';
    foreach ($params as $p) {
        if (is_int($p)) {
            $types .= 'i';
        } elseif (is_float($p)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    // Дубликаты — по component_name + category_id
    if ($ifNotExists) {
        $check = db_prepare($mysql, "SELECT component_id FROM components WHERE component_name = ? AND category_id = ?", "si", $r['name'], $r['category_id']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $skipped++;
            if (!$dryRun) {
                echo "  пропущен (дубль): {$r['name']}\n";
            }
            continue;
        }
    }

    if ($dryRun) {
        echo "  [{$i}] {$r['name']} — cat {$r['category_id']}, {$r['price']} руб.\n";
        $inserted++;
        continue;
    }

    $sql = "INSERT INTO `components`
        (`component_name`, `description`, `category_id`, `socket_id`, `video_core`, `tdp`,
         `image`, `component_price`, `amount`, `manufacturer`, `model`, `specs`,
         `ram_type`, `capacity_gb`, `frequency_mhz`, `memory_type`, `wattage`,
         `interface`, `form_factor`, `rpm`, `cooler_type`)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    try {
        $stmt = db_prepare($mysql, $sql, $types, ...$params);
        $stmt->execute();
        $inserted++;
        echo "  [{$i}] {$r['name']} → id " . $mysql->insert_id . "\n";
    } catch (Throwable $e) {
        $errors++;
        echo "  [{$i}] ОШИБКА {$r['name']}: " . $e->getMessage() . "\n";
    }
}

$mysql->close();

echo "\n=== Итог ===\n";
echo ($dryRun ? "dry-run: будет вставлено" : "вставлено") . ": $inserted\n";
echo "пропущено: $skipped\n";
echo "ошибок: $errors\n";
exit($errors > 0 ? 1 : 0);
