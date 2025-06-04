-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1
-- Время создания: Июн 17 2022 г., 03:37
-- Версия сервера: 10.4.24-MariaDB
-- Версия PHP: 8.1.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `u1697528_aion-bd`
--

-- --------------------------------------------------------

--
-- Структура таблицы `assembly`
--

CREATE TABLE `assembly` (
  `assembly_id` int(11) NOT NULL,
  `assembly_name` varchar(255) NOT NULL,
  `cpu_id` int(11) NOT NULL,
  `gpu_id` int(11) DEFAULT NULL,
  `motherboard_id` int(11) NOT NULL,
  `ram_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `cooler_id` int(11) NOT NULL,
  `power_supply_id` int(11) NOT NULL,
  `ssd_id` int(11) NOT NULL,
  `os` varchar(255) DEFAULT NULL,
  `ssd_2_id` int(11) DEFAULT NULL,
  `hdd_id` int(11) DEFAULT NULL,
  `dvd_id` int(11) DEFAULT NULL,
  `assembly_price` int(11) NOT NULL
) ENGINE=InnoDB AVG_ROW_LENGTH=5461 DEFAULT CHARSET=utf8;

--
-- Дамп данных таблицы `assembly`
--

INSERT INTO `assembly` (`assembly_id`, `assembly_name`, `cpu_id`, `gpu_id`, `motherboard_id`, `ram_id`, `case_id`, `cooler_id`, `power_supply_id`, `ssd_id`, `os`, `ssd_2_id`, `hdd_id`, `dvd_id`, `assembly_price`) VALUES
(1, 'EinTech', 4, NULL, 44, 102, 133, 147, 117, 174, NULL, NULL, NULL, NULL, 30000),
(2, 'Eternal', 38, 83, 66, 104, 135, 149, 122, 178, NULL, NULL, NULL, NULL, 105000),
(3, 'Magic Workbench', 29, 96, 56, 103, 143, 162, 125, 180, NULL, NULL, 170, NULL, 340000),
(4, '#4', 18, NULL, 47, 109, 139, 150, 123, 177, NULL, NULL, NULL, NULL, 90192),
(5, '#5', 43, NULL, 71, 116, 144, 167, 127, 181, NULL, NULL, NULL, NULL, 459892);

-- --------------------------------------------------------

--
-- Структура таблицы `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(255) CHARACTER SET utf8 NOT NULL
) ENGINE=InnoDB AVG_ROW_LENGTH=1820 DEFAULT CHARSET=utf8mb4;

--
-- Дамп данных таблицы `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`) VALUES
(1, 'Процессор'),
(2, 'Материнская плата'),
(3, 'Видеокарта'),
(4, 'Оперативная память'),
(5, 'Блок питания'),
(6, 'Корпус'),
(7, 'Кулер'),
(8, 'HDD'),
(9, 'SSD'),
(10, 'Привод');

-- --------------------------------------------------------

--
-- Структура таблицы `components`
--

CREATE TABLE `components` (
  `component_id` int(11) NOT NULL,
  `component_name` varchar(255) CHARACTER SET utf8 NOT NULL,
  `category_id` int(11) NOT NULL,
  `socket_id` int(11) DEFAULT NULL,
  `video_core` tinyint(1) DEFAULT NULL,
  `tdp` int(11) DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `component_price` int(11) NOT NULL,
  `amount` int(10) NOT NULL
) ENGINE=InnoDB AVG_ROW_LENGTH=181 DEFAULT CHARSET=utf8mb4;

--
-- Дамп данных таблицы `components`
--

INSERT INTO `components` (`component_id`, `component_name`, `category_id`, `socket_id`, `video_core`, `tdp`, `image`, `component_price`, `amount`) VALUES
(1, 'Celeron G5905', 1, 1, 1, 58, NULL, 4699, 10),
(2, 'Pentium Gold G6405', 1, 1, 1, 58, NULL, 7599, 10),
(3, 'A8-9600', 1, 3, 1, 65, NULL, 5499, 10),
(4, 'i3-10100F', 1, 1, 0, 65, NULL, 7999, 10),
(5, 'i3-10100', 1, 1, 1, 65, NULL, 10999, 10),
(6, 'i5-10400', 1, 1, 1, 65, NULL, 12499, 10),
(7, 'i5-11400F', 1, 1, 0, 65, NULL, 14899, 10),
(8, 'i5-10400F', 1, 1, 0, 65, NULL, 13199, 10),
(9, 'i5-11400F', 1, 1, 1, 65, NULL, 15999, 10),
(10, 'i5-10600KF', 1, 1, 0, 125, NULL, 16399, 10),
(11, 'i5-10400F', 1, 1, 0, 65, NULL, 13199, 10),
(12, 'i5-10600', 1, 1, 1, 65, NULL, 16999, 10),
(13, 'i5-11600', 1, 1, 1, 65, NULL, 19199, 10),
(14, 'i5-11600KF', 1, 1, 0, 125, NULL, 20299, 10),
(15, 'i5-11600K', 1, 1, 1, 125, NULL, 20699, 10),
(16, 'i7-10700F', 1, 1, 0, 65, NULL, 24299, 10),
(17, 'i7-10700F', 1, 1, 0, 65, NULL, 24299, 10),
(18, 'i7-11700', 1, 1, 1, 65, NULL, 24399, 10),
(19, 'i9-10900F', 1, 1, 0, 65, NULL, 29399, 10),
(20, 'i9-10900', 1, 1, 1, 65, NULL, 30399, 10),
(21, 'i9-11900F', 1, 1, 0, 65, NULL, 31499, 10),
(22, 'i9-11900KF', 1, 1, 0, 125, NULL, 37999, 10),
(23, 'Celeron G6900', 1, 2, 1, 46, NULL, 4999, 10),
(24, 'Pentium Gold G7400', 1, 2, 1, 46, NULL, 10999, 10),
(25, 'i3-12100F', 1, 2, 0, 89, NULL, 12599, 10),
(26, 'i3-12100', 1, 2, 1, 89, NULL, 14999, 10),
(27, 'i5-12400F', 1, 2, 0, 117, NULL, 16599, 10),
(28, 'i5-12400', 1, 2, 1, 117, NULL, 18599, 10),
(29, 'i7-12700F', 1, 2, 0, 180, NULL, 32599, 10),
(30, 'i9-12900F', 1, 2, 0, 202, NULL, 48999, 10),
(31, 'i9-12900KF', 1, 2, 0, 241, NULL, 54999, 10),
(32, 'A6-9500E', 1, 3, 1, 35, NULL, 3399, 10),
(33, 'Athlon X4 950', 1, 3, 0, 65, NULL, 3699, 10),
(34, 'Athlon 3000G', 1, 3, 1, 35, NULL, 5899, 10),
(35, 'Ryzen 3 PRO 1200', 1, 3, 0, 65, NULL, 7299, 10),
(36, 'Ryzen 3 PRO 2100GE', 1, 3, 1, 35, NULL, 10199, 10),
(37, 'Ryzen 5 3600', 1, 3, 0, 65, NULL, 15899, 10),
(38, 'Ryzen 5 5600G', 1, 3, 1, 65, NULL, 19699, 10),
(39, 'Ryzen 7 3700X', 1, 3, 0, 65, NULL, 22899, 10),
(40, 'Ryzen 7 3800X', 1, 3, 0, 105, NULL, 23199, 10),
(41, 'Ryzen 7 5800X', 1, 3, 0, 105, NULL, 28199, 10),
(42, 'Ryzen 9 5900X', 1, 3, 0, 105, NULL, 39999, 10),
(43, 'Ryzen 9 5950X', 1, 3, 0, 105, NULL, 55299, 10),
(44, 'ASRock H410M-HVS R2.0', 2, 1, NULL, NULL, NULL, 4199, 10),
(45, 'ASRock H470M-HVS', 2, 1, NULL, NULL, NULL, 4499, 10),
(46, 'ASRock H510M-HDV', 2, 1, NULL, NULL, NULL, 4999, 10),
(47, 'ASRock H570M Pro4', 2, 1, NULL, NULL, NULL, 8999, 10),
(48, 'ASRock Z590M Phantom Gaming 4', 2, 1, NULL, NULL, NULL, 9999, 10),
(49, 'ASRock Z590 PG Velocita', 2, 1, NULL, NULL, NULL, 20999, 10),
(50, 'Gigabyte B560M Gaming HD', 2, 1, NULL, NULL, NULL, 6499, 10),
(51, 'Gigabyte B560M Gaming HD', 2, 1, NULL, NULL, NULL, 6499, 10),
(52, 'Gigabyte Z590 UD AC', 2, 1, NULL, NULL, NULL, 11499, 10),
(53, 'Gigabyte Z590 AORUS ULTRA', 2, 1, NULL, NULL, NULL, 16999, 10),
(54, 'ASRock H610M-HDV/M.2', 2, 2, NULL, NULL, NULL, 8499, 10),
(55, 'ASRock B660M Pro RS', 2, 2, NULL, NULL, NULL, 10999, 10),
(56, 'ASRock Z690 Phantom Gaming 4', 2, 2, NULL, NULL, NULL, 13999, 10),
(57, 'ASRock Z690 Extreme', 2, 2, NULL, NULL, NULL, 21999, 10),
(58, 'Gigabyte H610M H DDR4', 2, 2, NULL, NULL, NULL, 6999, 10),
(59, 'Gigabyte Z690 Gaming X DDR4', 2, 2, NULL, NULL, NULL, 15999, 10),
(60, 'MSI MPG Z690 EDGE WIFI DDR4', 2, 2, NULL, NULL, NULL, 26999, 10),
(61, 'ASRock A320M-DVS R4.0', 2, 3, NULL, NULL, NULL, 2999, 10),
(62, 'ASUS PRIME A320M-K', 2, 3, NULL, NULL, NULL, 3799, 10),
(63, 'MSI B450M-A PRO MAX', 2, 3, NULL, NULL, NULL, 3899, 10),
(64, 'ASRock A520M-HVS', 2, 3, NULL, NULL, NULL, 4199, 10),
(65, 'Gigabyte B450 AORUS M', 2, 3, NULL, NULL, NULL, 4999, 10),
(66, 'ASRock A520M Pro4', 2, 3, NULL, NULL, NULL, 6799, 10),
(67, 'Gigabyte B550M AORUS ELITE', 2, 3, NULL, NULL, NULL, 8999, 10),
(68, 'Gigabyte Z570 Gaming X', 2, 3, NULL, NULL, NULL, 10999, 10),
(69, 'ASRock X570M Pro4', 2, 3, NULL, NULL, NULL, 15999, 10),
(70, 'Gigabyte X570S AERO G', 2, 3, NULL, NULL, NULL, 29999, 10),
(71, 'Gigabyte X550 AORUS XTREME', 2, 3, NULL, NULL, NULL, 64999, 10),
(72, 'MSI GeForce 210', 3, NULL, NULL, NULL, NULL, 3399, 10),
(73, 'GIGABYTE GeForce GT 730', 3, NULL, NULL, NULL, NULL, 4950, 10),
(74, 'MSI GeForce 210', 3, NULL, NULL, NULL, NULL, 3399, 10),
(75, 'GIGABYTE GeForce GT 730', 3, NULL, NULL, NULL, NULL, 4950, 10),
(76, 'PowerColor AMD Radeon R7 240', 3, NULL, NULL, NULL, NULL, 5899, 10),
(77, 'GIGABYTE GeForce GT 1030 Low Profile D4 2G', 3, NULL, NULL, NULL, NULL, 6899, 10),
(78, 'PowerColor AMD Radeon 550 LP', 3, NULL, NULL, NULL, NULL, 9999, 10),
(79, 'MSI AMD Radeon RX 550 AERO ITX OC', 3, NULL, NULL, NULL, NULL, 11799, 10),
(80, 'AFOX GTX 750', 3, NULL, NULL, NULL, NULL, 13999, 10),
(81, 'Palit GeForce GTX 1050 Ti STORMX', 3, NULL, NULL, NULL, NULL, 18799, 10),
(82, 'ASUS GeForce GTX 1650 PHOENIX OC', 3, NULL, NULL, NULL, NULL, 27499, 10),
(83, 'PowerColor AMD Radeon RX 6500 XT ITX', 3, NULL, NULL, NULL, NULL, 28499, 10),
(84, 'Palit GeForce GTX 1660 SUPER STORMX', 3, NULL, NULL, NULL, NULL, 33999, 10),
(85, 'Palit GeForce RTX 3050 Dual', 3, NULL, NULL, NULL, NULL, 39999, 10),
(86, 'GIGABYTE GeForce RTX 2060 D6 6G (rev. 2.0)', 3, NULL, NULL, NULL, NULL, 42999, 10),
(87, 'MSI GeForce RTX 3050 GAMING X', 3, NULL, NULL, NULL, NULL, 46799, 10),
(88, 'ASRock AMD Radeon RX 6600 Challenger D', 3, NULL, NULL, NULL, NULL, 47999, 10),
(89, 'GIGABYTE GeForce RTX 3060 EAGLE OC (LHR)', 3, NULL, NULL, NULL, NULL, 60199, 10),
(90, 'Palit GeForce RTX 3060 Ti DUAL OC V1 (LHR)', 3, NULL, NULL, NULL, NULL, 70999, 10),
(91, 'ZOTAC GAMING GeForce RTX 3060 Ti AMP LHR White Edition', 3, NULL, NULL, NULL, NULL, 82999, 10),
(92, 'Powercolor AMD Radeon RX 6700 XT Fighter', 3, NULL, NULL, NULL, NULL, 89099, 10),
(93, 'Palit GeForce RTX 3070 JetStream OC (LHR)', 3, NULL, NULL, NULL, NULL, 91399, 10),
(94, 'GIGABYTE GeForce RTX 3080 GAMING OC (LHR)', 3, NULL, NULL, NULL, NULL, 101899, 10),
(95, 'PowerColor Red Devil AMD Radeon RX 6800 XT', 3, NULL, NULL, NULL, NULL, 115599, 10),
(96, 'GIGABYTE GeForce RTX 3080 GAMING OC', 3, NULL, NULL, NULL, NULL, 120099, 10),
(97, 'KFA2 GeForce RTX 3080 Ti SG', 3, NULL, NULL, NULL, NULL, 145599, 10),
(98, 'Palit GeForce RTX 3090 GamingPro', 3, NULL, NULL, NULL, NULL, 222999, 10),
(99, 'Palit GeForce RTX 3090 TI GameRock OC', 3, NULL, NULL, NULL, NULL, 232999, 10),
(102, 'Patriot Signature Line 4gbx2', 4, NULL, NULL, NULL, NULL, 2599, 10),
(103, 'A-Data XPG Spectrix D60G RGB 16gbx2', 4, NULL, NULL, NULL, NULL, 15999, 10),
(104, 'Kingston FURY Beast Black 4gbx4', 4, NULL, NULL, NULL, NULL, 9999, 10),
(105, 'Kingston FURY Beast Black 4gbx2', 4, NULL, NULL, NULL, NULL, 4199, 10),
(106, 'Goodram Iridium 4gbx2', 4, NULL, NULL, NULL, NULL, 5099, 10),
(107, 'A-Data XPG GAMMIX D20 8gbx2', 4, NULL, NULL, NULL, NULL, 5999, 10),
(108, 'Corsair Vengeance LPX 8gbx2', 4, NULL, NULL, NULL, NULL, 6299, 10),
(109, 'Patriot Viper Steel 8gbx2', 4, NULL, NULL, NULL, NULL, 7799, 10),
(110, 'A-Data XPG SPECTRIX D50 RGB 8gbx2', 4, NULL, NULL, NULL, NULL, 10299, 10),
(111, 'Patriot Viper Elite II 16gbx2', 4, NULL, NULL, NULL, NULL, 11999, 10),
(112, 'Patriot Viper Steel 16gbx2', 4, NULL, NULL, NULL, NULL, 13499, 10),
(113, 'Kingston FURY Beast Black 16gbx2', 4, NULL, NULL, NULL, NULL, 14499, 10),
(114, 'Kingston FURY Beast Black 32gbx2', 4, NULL, NULL, NULL, NULL, 26299, 10),
(115, 'G.Skill TRIDENT Z Neo 32gbx2', 4, NULL, NULL, NULL, NULL, 35999, 10),
(116, 'G.Skill Trident Z Royal 32gbx2', 4, NULL, NULL, NULL, NULL, 61999, 10),
(117, 'Cougar VTE400', 5, NULL, NULL, NULL, NULL, 3199, 10),
(118, 'Cougar VTX 600W', 5, NULL, NULL, NULL, NULL, 4350, 10),
(119, 'Corsair CV650', 5, NULL, NULL, NULL, NULL, 4799, 10),
(120, 'be quiet! SYSTEM POWER 9 600W', 5, NULL, NULL, NULL, NULL, 4999, 10),
(121, 'Thermaltake Smart BX1 550W', 5, NULL, NULL, NULL, NULL, 5099, 10),
(122, 'Cougar GEC 650', 5, NULL, NULL, NULL, NULL, 5711, 10),
(123, 'Cougar GEC 750', 5, NULL, NULL, NULL, NULL, 6399, 10),
(124, 'Cougar GX 800W', 5, NULL, NULL, NULL, NULL, 7699, 10),
(125, 'Cougar GX 1050W', 5, NULL, NULL, NULL, NULL, 12499, 10),
(126, 'HIPER HPG-1200FM', 5, NULL, NULL, NULL, NULL, 15999, 10),
(127, 'GIGABYTE AORUS P1200W', 5, NULL, NULL, NULL, NULL, 29999, 10),
(128, 'ExeGate BAA-106', 6, NULL, NULL, NULL, 'assets/images/cases/baa-16.png', 1599, 10),
(129, 'ExeGate XP-329-XP500', 6, NULL, NULL, NULL, 'assets/images/cases/xp-329.png', 1999, 10),
(130, 'GiNZZU B185 White', 6, NULL, NULL, NULL, 'assets/images/cases/b185w.png', 2599, 10),
(131, 'Accord K-16', 6, NULL, NULL, NULL, 'assets/images/cases/k-16.png', 2750, 10),
(132, 'AeroCool Streak', 6, NULL, NULL, NULL, 'assets/images/cases/streak.png', 2799, 10),
(133, 'AeroCool Cylon White', 6, NULL, NULL, NULL, 'assets/images/cases/cylonw.png', 3799, 10),
(134, 'ZALMAN i3 Edge', 6, NULL, NULL, NULL, 'assets/images/cases/i3edge.png', 4299, 10),
(135, 'MONTECH FIGHTER 500', 6, NULL, NULL, NULL, 'assets/images/cases/fighter500.png', 4799, 10),
(136, 'AeroCool Aero One Mini Frost', 6, NULL, NULL, NULL, 'assets/images/cases/aomf.png', 5199, 10),
(137, 'Corsair 470T RGB', 6, NULL, NULL, NULL, 'assets/images/cases/470trgb.png', 5999, 10),
(138, 'Thermaltake Core G21 Tempered Glass Edition', 6, NULL, NULL, NULL, 'assets/images/cases/g21tge.png', 6999, 10),
(139, 'Cougar MX-660 Iron RGB', 6, NULL, NULL, NULL, 'assets/images/cases/mx-660irgb.png', 7599, 10),
(140, 'Thermaltake AH T200', 6, NULL, NULL, NULL, 'assets/images/cases/aht200.png', 11999, 10),
(141, 'Cougar Blazer Essence', 6, NULL, NULL, NULL, 'assets/images/cases/essence.png', 17419, 10),
(142, 'Corsair Obsidian Series 500D', 6, NULL, NULL, NULL, 'assets/images/cases/obs500d.png', 20799, 10),
(143, 'Thermaltake View 71 Tempered Glass SNOW Edition RGB', 6, NULL, NULL, NULL, 'assets/images/cases/view71.png', 25349, 10),
(144, 'Corsair Obsidian Series 1000D', 6, NULL, NULL, NULL, 'assets/images/cases/obs1000d.png', 58999, 10),
(145, 'DEEPCOOL Theta 20', 7, NULL, NULL, 82, NULL, 599, 10),
(146, 'DEEPCOOL Alta 9', 7, NULL, NULL, 65, NULL, 499, 10),
(147, 'DEEPCOOL Gamma Archer', 7, NULL, NULL, 95, NULL, 799, 10),
(148, 'DEEPCOOL Ice Blade 100', 7, NULL, NULL, 100, NULL, 850, 10),
(149, 'DEEPCOOL GAMMAXX 300', 7, NULL, NULL, 130, NULL, 1399, 10),
(150, 'DEEPCOOL GAMMAXX 400K', 7, NULL, NULL, 150, NULL, 1699, 10),
(151, 'DEEPCOOL GAMMAXX 400 V2', 7, NULL, NULL, 180, NULL, 1799, 10),
(152, 'DEEPCOOL GAMMAXX 400 EX', 7, NULL, NULL, 180, NULL, 2599, 10),
(153, 'be quiet! PURE ROCK 2', 7, NULL, NULL, 150, NULL, 3799, 10),
(154, 'DEEPCOOL REDHAT', 7, NULL, NULL, 250, NULL, 4199, 10),
(155, 'be quiet! SHADOW ROCK SLIM', 7, NULL, NULL, 160, NULL, 4499, 10),
(156, 'DEEPCOOL AS500', 7, NULL, NULL, 220, NULL, 5299, 10),
(157, 'be quiet! DARK ROCK 4', 7, NULL, NULL, 200, NULL, 5999, 10),
(158, 'DEEPCOOL AK620', 7, NULL, NULL, 200, NULL, 6299, 10),
(159, 'Noctua NH-U9DX i4', 7, NULL, NULL, 200, NULL, 7409, 10),
(160, 'Cooler Master MasterLiquid Lite 120', 7, NULL, NULL, 180, NULL, 3799, 10),
(161, 'DEEPCOOL GAMMAXX L120 V2', 7, NULL, NULL, 150, NULL, 4050, 10),
(162, 'DEEPCOOL GAMMAXX L240T WHITE', 7, NULL, NULL, 200, NULL, 4699, 10),
(163, 'Cooler Master MasterLiquid ML360 RGB TR4 Edition', 7, NULL, NULL, 250, NULL, 4999, 10),
(164, 'Xilence Performance A+ LiQuRizer LQ240.W.ARGB', 7, NULL, NULL, 300, NULL, 7799, 10),
(165, 'ID-Cooling AURAFLOW X 360 SNOW', 7, NULL, NULL, 300, NULL, 7999, 10),
(166, 'AeroCool P7-L240', 7, NULL, NULL, 380, NULL, 9749, 10),
(167, 'Corsair iCUE H150i RGB PRO XT', 7, NULL, NULL, 400, NULL, 17999, 10),
(168, 'Western Digital Blue 500gb', 8, NULL, NULL, NULL, NULL, 3499, 10),
(169, 'Western Digital Blue 1tb', 8, NULL, NULL, NULL, NULL, 3999, 10),
(170, 'Western Digital Blue 2tb', 8, NULL, NULL, NULL, NULL, 4999, 10),
(171, 'A-Data Ultimate SU650 M.2 120gb', 9, NULL, NULL, NULL, NULL, 1650, 10),
(172, 'Kingston A400 M.2 120gb', 9, NULL, NULL, NULL, NULL, 1999, 10),
(173, 'GIGABYTE NVMe SSD M.2 256gb', 9, NULL, NULL, NULL, NULL, 2399, 10),
(174, 'Apacer AST280 M.2 240gb', 9, NULL, NULL, NULL, NULL, 2199, 10),
(175, 'A-Data XPG SX6000 Pro M.2 256gb', 9, NULL, NULL, NULL, NULL, 3599, 10),
(176, 'ExeGate NextPro KC2000TP480 M.2 480gb', 9, NULL, NULL, NULL, NULL, 4699, 10),
(177, 'ExeGate NextPro+ KC2000TP512 M.2 512gb', 9, NULL, NULL, NULL, NULL, 4799, 10),
(178, 'Kingston NV1 M.2 500gb', 9, NULL, NULL, NULL, NULL, 4550, 10),
(179, 'Samsung 970 EVO Plus M.2 500gb', 9, NULL, NULL, NULL, NULL, 9999, 10),
(180, 'Samsung 980 M.2 500gb', 9, NULL, NULL, NULL, NULL, 8899, 10),
(181, 'Samsung 980 PRO M.2 1tb', 9, NULL, NULL, NULL, NULL, 24999, 10),
(182, 'Western Digital Blue M.2 2tb', 9, NULL, NULL, NULL, NULL, 23999, 10),
(183, 'Western Digital Blue M.2 1tb', 9, NULL, NULL, NULL, NULL, 9999, 10),
(184, 'ExeGate UN450', 5, NULL, NULL, NULL, NULL, 1199, 10),
(185, 'DVD-RW LG GH24NSD5', 10, NULL, NULL, NULL, NULL, 1099, 10),
(186, 'Patriot Burst Elite 480gb', 9, NULL, NULL, NULL, NULL, 3999, 10),
(187, 'Patriot Burst Elite 960gb', 9, NULL, NULL, NULL, NULL, 8499, 10);

-- --------------------------------------------------------

--
-- Структура таблицы `favorites`
--

CREATE TABLE `favorites` (
  `favorit_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assembly_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Дамп данных таблицы `favorites`
--

INSERT INTO `favorites` (`favorit_id`, `user_id`, `assembly_id`) VALUES
(1, 12, 2),
(2, 12, 2);

-- --------------------------------------------------------

--
-- Структура таблицы `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assembly_id` int(11) NOT NULL
) ENGINE=InnoDB AVG_ROW_LENGTH=5461 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Структура таблицы `sockets`
--

CREATE TABLE `sockets` (
  `socket_id` int(11) NOT NULL,
  `socket_type` varchar(255) CHARACTER SET utf8 NOT NULL
) ENGINE=InnoDB AVG_ROW_LENGTH=5461 DEFAULT CHARSET=utf8mb4;

--
-- Дамп данных таблицы `sockets`
--

INSERT INTO `sockets` (`socket_id`, `socket_type`) VALUES
(1, 'LGA1200'),
(2, 'LGA1700'),
(3, 'AM4');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `user_id` int(5) UNSIGNED NOT NULL,
  `user_name` varchar(20) NOT NULL,
  `user_surname` varchar(30) DEFAULT NULL,
  `user_login` varchar(25) NOT NULL,
  `user_pass` varchar(32) NOT NULL,
  `user_group` varchar(10) NOT NULL,
  `user_address` varchar(1000) DEFAULT NULL,
  `user_email` varchar(50) DEFAULT NULL,
  `user_number` varchar(13) DEFAULT NULL
) ENGINE=MyISAM AVG_ROW_LENGTH=172 DEFAULT CHARSET=utf8;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`user_id`, `user_name`, `user_surname`, `user_login`, `user_pass`, `user_group`, `user_address`, `user_email`, `user_number`) VALUES
(1, 'Администратор', NULL, 'admin', '3ea5ea9cd5f2220018e44e1e1449ca98', 'admin', NULL, NULL, NULL);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `assembly`
--
ALTER TABLE `assembly`
  ADD PRIMARY KEY (`assembly_id`),
  ADD KEY `cpu_id` (`cpu_id`,`gpu_id`,`motherboard_id`,`ram_id`,`case_id`,`cooler_id`,`power_supply_id`,`ssd_id`,`ssd_2_id`,`hdd_id`,`dvd_id`),
  ADD KEY `gpu_id` (`gpu_id`),
  ADD KEY `motherboard_id` (`motherboard_id`),
  ADD KEY `ram_id` (`ram_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `cooler_id` (`cooler_id`),
  ADD KEY `power_supply_id` (`power_supply_id`),
  ADD KEY `ssd_id` (`ssd_id`),
  ADD KEY `ssd_2_id` (`ssd_2_id`),
  ADD KEY `hdd_id` (`hdd_id`),
  ADD KEY `dvd_id` (`dvd_id`);

--
-- Индексы таблицы `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Индексы таблицы `components`
--
ALTER TABLE `components`
  ADD PRIMARY KEY (`component_id`),
  ADD KEY `category_id` (`category_id`,`socket_id`),
  ADD KEY `components_ibfk_1` (`socket_id`);

--
-- Индексы таблицы `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`favorit_id`),
  ADD KEY `user_id` (`user_id`,`assembly_id`),
  ADD KEY `assembly_id` (`assembly_id`);

--
-- Индексы таблицы `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`assembly_id`),
  ADD KEY `orders_ibfk_1` (`assembly_id`);

--
-- Индексы таблицы `sockets`
--
ALTER TABLE `sockets`
  ADD PRIMARY KEY (`socket_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `user_login` (`user_login`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `assembly`
--
ALTER TABLE `assembly`
  MODIFY `assembly_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=256;

--
-- AUTO_INCREMENT для таблицы `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT для таблицы `components`
--
ALTER TABLE `components`
  MODIFY `component_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=188;

--
-- AUTO_INCREMENT для таблицы `favorites`
--
ALTER TABLE `favorites`
  MODIFY `favorit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT для таблицы `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT для таблицы `sockets`
--
ALTER TABLE `sockets`
  MODIFY `socket_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `assembly`
--
ALTER TABLE `assembly`
  ADD CONSTRAINT `assembly_ibfk_1` FOREIGN KEY (`cpu_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_10` FOREIGN KEY (`hdd_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_11` FOREIGN KEY (`dvd_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_2` FOREIGN KEY (`gpu_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_3` FOREIGN KEY (`motherboard_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_4` FOREIGN KEY (`ram_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_5` FOREIGN KEY (`case_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_6` FOREIGN KEY (`cooler_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_7` FOREIGN KEY (`power_supply_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_8` FOREIGN KEY (`ssd_id`) REFERENCES `components` (`component_id`),
  ADD CONSTRAINT `assembly_ibfk_9` FOREIGN KEY (`ssd_2_id`) REFERENCES `components` (`component_id`);

--
-- Ограничения внешнего ключа таблицы `components`
--
ALTER TABLE `components`
  ADD CONSTRAINT `components_ibfk_1` FOREIGN KEY (`socket_id`) REFERENCES `sockets` (`socket_id`),
  ADD CONSTRAINT `components_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`);

--
-- Ограничения внешнего ключа таблицы `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`assembly_id`) REFERENCES `assembly` (`assembly_id`);

--
-- Ограничения внешнего ключа таблицы `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`assembly_id`) REFERENCES `assembly` (`assembly_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
