
--
-- Database: `el2demosites_alfadb`
--

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_cart`
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_shop_group` int(11) NOT NULL,
  `id_payment` int(11) DEFAULT NULL,
  `id_shipment` int(11) DEFAULT NULL,
  `id_lang` int(11) NOT NULL,
  `id_user_info_delivery` int(11) NOT NULL,
  `id_user_info_invoice` int(11) NOT NULL,
  `id_currency` int(11) NOT NULL,
  `id_customer` int(11) NOT NULL,
  `added` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `recognize_key` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_cart_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart_items` (
  `id_cart` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `quantity` float NOT NULL,
  `added` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT 0,
  `name` varchar(400) NOT NULL,
  `desc` text DEFAULT NULL,
  `alias` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT '',
  `meta_desc` text DEFAULT NULL,
  `state` tinyint(1) DEFAULT 1,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  `prices` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`prices`)),
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_categories_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories_usergroups` (
  `category_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  KEY `category_id` (`category_id`,`usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_categories_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories_users` (
  `category_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  KEY `category_id` (`category_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_coupons`
--

CREATE TABLE IF NOT EXISTS `#__alfa_coupons` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `coupon_code` varchar(255) NOT NULL,
  `num_of_uses` double NOT NULL DEFAULT 0,
  `value_type` varchar(255) DEFAULT '0',
  `value` decimal(12,2) NOT NULL,
  `min_value` double DEFAULT NULL,
  `max_value` double DEFAULT 0,
  `hidden` varchar(255) DEFAULT '0',
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `associate_to_new_users` varchar(255) DEFAULT '',
  `user_associated` varchar(255) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_coupons_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_coupons_usergroups` (
  `coupon_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  KEY `coupon_id` (`coupon_id`,`usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_coupons_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_coupons_users` (
  `coupon_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  KEY `coupon_id` (`coupon_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_currencies`
--

CREATE TABLE IF NOT EXISTS `#__alfa_currencies` (
  `id` int(1) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  `code` char(3) DEFAULT NULL,
  `number` int(4) DEFAULT NULL,
  `symbol` varchar(8) DEFAULT NULL,
  `decimal_place` varchar(8) DEFAULT NULL,
  `decimal_symbol` varchar(10) DEFAULT NULL,
  `decimal_separator` varchar(10) NOT NULL,
  `thousand_separator` varchar(10) NOT NULL,
  `format_pattern` varchar(30) NOT NULL DEFAULT '{number}{symbol}',
  `currency_thousands` varchar(8) DEFAULT NULL,
  `currency_positive_style` varchar(64) DEFAULT NULL,
  `currency_negative_style` varchar(64) DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `checked_out_time` datetime DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currency_code_3` (`code`),
  KEY `ordering` (`ordering`),
  KEY `currency_name` (`name`),
  KEY `published` (`state`),
  KEY `currency_numeric_code` (`number`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to store currencies';

--
-- Dumping data for table `#__alfa_currencies`
--

INSERT INTO `#__alfa_currencies` (`id`, `name`, `code`, `number`, `symbol`, `decimal_place`, `decimal_symbol`, `decimal_separator`, `thousand_separator`, `format_pattern`, `currency_thousands`, `currency_positive_style`, `currency_negative_style`, `ordering`, `state`, `created_by`, `modified_by`, `checked_out_time`, `checked_out`) VALUES
(2, 'United Arab Emirates dirham', 'AED', 784, 'د.إ', '4', 'bfa', 'a', 'a', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(4, 'Albanian lek', 'ALL', 8, 'Lek', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(5, 'Netherlands Antillean gulden', 'ANG', 532, 'ƒ', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(7, 'Argentine peso', 'ARS', 32, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(9, 'Australian dollar', 'AUD', 36, '$', '2', ',', '', '', '{number}{symbol}', '', '{symbol} {number}', '{sign}{symbol} {number}', 0, 1, 0, 923, NULL, NULL),
(10, 'Aruban florin', 'AWG', 533, 'ƒ', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(11, 'Barbadian dollar', 'BBD', 52, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(12, 'Bangladeshi taka', 'BDT', 50, '৳', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(15, 'Bahraini dinar', 'BHD', 48, 'ب.د', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(16, 'Burundian franc', 'BIF', 108, 'Fr', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(17, 'Bermudian dollar', 'BMD', 60, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(18, 'Brunei dollar', 'BND', 96, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(19, 'Bolivian boliviano', 'BOB', 68, '$b', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(20, 'Brazilian real', 'BRL', 986, 'R$', '2', ',', '', '', '{number}{symbol}', '.', '{symbol} {number}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, 0),
(21, 'Bahamian dollar', 'BSD', 44, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(22, 'Bhutanese ngultrum', 'BTN', 64, 'BTN', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(24, 'Botswana pula', 'BWP', 72, 'P', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(25, 'Belize dollar', 'BZD', 84, 'BZ$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(26, 'Canadian dollar', 'CAD', 124, '$', '2', ',', '', '', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(27, 'Swiss franc', 'CHF', 756, 'CHF', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(28, 'Unidad de Fomento', 'CLF', 990, 'CLF', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(29, 'Chilean peso', 'CLP', 152, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(30, 'Chinese renminbi yuan', 'CNY', 156, '元', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(31, 'Colombian peso', 'COP', 170, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(32, 'Costa Rican colón', 'CRC', 188, '₡', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(33, 'Czech koruna', 'CZK', 203, 'Kč', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(34, 'Cuban peso', 'CUP', 192, '₱', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(35, 'Cape Verdean escudo', 'CVE', 132, '$', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(40, 'Danish krone', 'DKK', 208, 'kr', '2', ',', '', '', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(41, 'Dominican peso', 'DOP', 214, 'RD$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(42, 'Algerian dinar', 'DZD', 12, 'د.ج', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(44, 'Egyptian pound', 'EGP', 818, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(46, 'Ethiopian birr', 'ETB', 230, 'ETB', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(47, 'Euro', 'EUR', 978, '€', '2', ',', '', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(49, 'Fijian dollar', 'FJD', 242, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(50, 'Falkland pound', 'FKP', 238, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(52, 'British pound', 'GBP', 826, '£', '2', ',', '', '', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(54, 'Gibraltar pound', 'GIP', 292, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(55, 'Gambian dalasi', 'GMD', 270, 'D', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(56, 'Guinean franc', 'GNF', 324, 'Fr', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(58, 'Guatemalan quetzal', 'GTQ', 320, 'Q', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(60, 'Guyanese dollar', 'GYD', 328, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(61, 'Hong Kong dollar', 'HKD', 344, '元', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(62, 'Honduran lempira', 'HNL', 340, 'L', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(63, 'Haitian gourde', 'HTG', 332, 'G', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(64, 'Hungarian forint', 'HUF', 348, 'Ft', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(65, 'Indonesian rupiah', 'IDR', 360, 'Rp', '0', ',', '', '', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(67, 'Israeli new sheqel', 'ILS', 376, '₪', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(68, 'Indian rupee', 'INR', 356, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(69, 'Iraqi dinar', 'IQD', 368, 'ع.د', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(70, 'Iranian rial', 'IRR', 364, '﷼', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number}{symbol}', 0, 1, 0, 0, NULL, 0),
(73, 'Jamaican dollar', 'JMD', 388, 'J$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(74, 'Jordanian dinar', 'JOD', 400, 'د.ا', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(75, 'Japanese yen', 'JPY', 392, '¥', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(76, 'Kenyan shilling', 'KES', 404, 'Sh', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(77, 'Cambodian riel', 'KHR', 116, '៛', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(78, 'Comorian franc', 'KMF', 174, 'Fr', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(79, 'North Korean won', 'KPW', 408, '₩', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(80, 'South Korean won', 'KRW', 410, '₩', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(81, 'Kuwaiti dinar', 'KWD', 414, 'د.ك', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(82, 'Cayman Islands dollar', 'KYD', 136, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(83, 'Lao kip', 'LAK', 418, '₭', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(84, 'Lebanese pound', 'LBP', 422, '£', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(85, 'Sri Lankan rupee', 'LKR', 144, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(86, 'Liberian dollar', 'LRD', 430, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(87, 'Lesotho loti', 'LSL', 426, 'L', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(89, 'Libyan dinar', 'LYD', 434, 'ل.د', '3', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(90, 'Moroccan dirham', 'MAD', 504, 'د.م.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(92, 'Mongolian tögrög', 'MNT', 496, '₮', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(93, 'Macanese pataca', 'MOP', 446, 'P', '1', ',', '', '', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(94, 'Mauritanian ouguiya', 'MRO', 478, 'UM', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(96, 'Mauritian rupee', 'MUR', 480, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(97, 'Maldivian rufiyaa', 'MVR', 462, 'ރ.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(98, 'Malawian kwacha', 'MWK', 454, 'MK', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(100, 'Malaysian ringgit', 'MYR', 458, 'RM', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(102, 'Nigerian naira', 'NGN', 566, '₦', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(105, 'Norwegian krone', 'NOK', 578, 'kr', '2', ',', '', '', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(106, 'Nepalese rupee', 'NPR', 524, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(107, 'New Zealand dollar', 'NZD', 554, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(108, 'Omani rial', 'OMR', 512, '﷼', '3', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(109, 'Panamanian balboa', 'PAB', 590, 'B/.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(110, 'Peruvian nuevo sol', 'PEN', 604, 'S/.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(111, 'Papua New Guinean kina', 'PGK', 598, 'K', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(112, 'Philippine peso', 'PHP', 608, '₱', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(113, 'Pakistani rupee', 'PKR', 586, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(114, 'Polish Złoty', 'PLN', 985, 'zł', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(116, 'Paraguayan guaraní', 'PYG', 600, '₲', '0', ',', '', '', '{number}{symbol}', '.', '{symbol} {number}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, 0),
(117, 'Qatari riyal', 'QAR', 634, '﷼', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(118, 'Romanian leu', 'RON', 946, 'lei', '2', ',', '', '', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(119, 'Rwandan franc', 'RWF', 646, 'Fr', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(120, 'Saudi riyal', 'SAR', 682, '﷼', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(121, 'Solomon Islands dollar', 'SBD', 90, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(122, 'Seychellois rupee', 'SCR', 690, '₨', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(124, 'Swedish krona', 'SEK', 752, 'kr', '2', ',', '', '', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(125, 'Singapore dollar', 'SGD', 702, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(126, 'Saint Helenian pound', 'SHP', 654, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(127, 'Sierra Leonean leone', 'SLL', 694, 'Le', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(128, 'Somali shilling', 'SOS', 706, 'S', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(130, 'São Tomé and Príncipe dobra', 'STD', 678, 'Db', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(131, 'Russian ruble', 'RUB', 643, 'руб', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(132, 'Salvadoran colón', 'SVC', 222, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(133, 'Syrian pound', 'SYP', 760, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(134, 'Swazi lilangeni', 'SZL', 748, 'L', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(135, 'Thai baht', 'THB', 764, '฿', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(136, 'Tunisian dinar', 'TND', 788, 'د.ت', '3', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(137, 'Tongan paʻanga', 'TOP', 776, 'T$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(139, 'Türk Lirası', 'TRY', 949, 'TL', '2', ',', '', '', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(140, 'Trinidad and Tobago dollar', 'TTD', 780, 'TT$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(141, 'New Taiwan dollar', 'TWD', 901, 'NT$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(142, 'Tanzanian shilling', 'TZS', 834, 'Sh', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(144, 'United States dollar', 'USD', 840, '$', '2', ',', '', '', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(147, 'Vietnamese Dong', 'VND', 704, '₫', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(148, 'Vanuatu vatu', 'VUV', 548, 'Vt', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(149, 'Samoan tala', 'WST', 882, 'T', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(151, 'Yemeni rial', 'YER', 886, '﷼', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(152, 'Serbian dinar', 'RSD', 941, 'Дин.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(153, 'South African rand', 'ZAR', 710, 'R', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(154, 'Zambian kwacha', 'ZMK', 894, 'ZK', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(156, 'Zimbabwean dollar', 'ZWD', 932, 'Z$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(158, 'Armenian dram', 'AMD', 51, 'դր.', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(159, 'Myanmar kyat', 'MMK', 104, 'K', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, 0),
(160, 'Croatian kuna', 'HRK', 191, 'kn', '2', ',', '', '', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(161, 'Eritrean nakfa', 'ERN', 232, 'Nfk', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(162, 'Djiboutian franc', 'DJF', 262, 'Fr', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(163, 'Icelandic króna', 'ISK', 352, 'kr', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(164, 'Kazakhstani tenge', 'KZT', 398, 'лв', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(165, 'Kyrgyzstani som', 'KGS', 417, 'лв', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(166, 'Latvian lats', 'LVL', 428, 'Ls', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(167, 'Lithuanian litas', 'LTL', 440, 'Lt', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(168, 'Mexican peso', 'MXN', 484, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(169, 'Moldovan leu', 'MDL', 498, 'L', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(170, 'Namibian dollar', 'NAD', 516, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(171, 'Nicaraguan córdoba', 'NIO', 558, 'C$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(172, 'Ugandan shilling', 'UGX', 800, 'Sh', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(173, 'Macedonian denar', 'MKD', 807, 'ден', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(174, 'Uruguayan peso', 'UYU', 858, '$', '0', ',', '', '', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, 0),
(175, 'Uzbekistani som', 'UZS', 860, 'лв', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(176, 'Azerbaijani manat', 'AZN', 934, 'ман', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(177, 'Ghanaian cedi', 'GHS', 936, '₵', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(178, 'Venezuelan bolívar', 'VEF', 937, 'Bs', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(179, 'Sudanese pound', 'SDG', 938, '£', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(180, 'Uruguay Peso', 'UYI', 940, 'UYI', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(181, 'Mozambican metical', 'MZN', 943, 'MT', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(182, 'WIR Euro', 'CHE', 947, '€', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(183, 'WIR Franc', 'CHW', 948, 'CHW', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(184, 'Central African CFA franc', 'XAF', 950, 'Fr', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(185, 'East Caribbean dollar', 'XCD', 951, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(186, 'West African CFA franc', 'XOF', 952, 'Fr', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(187, 'CFP franc', 'XPF', 953, 'F', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(188, 'Surinamese dollar', 'SRD', 968, '$', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(189, 'Malagasy ariary', 'MGA', 969, 'MGA', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(190, 'Unidad de Valor Real', 'COU', 970, 'COU', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(191, 'Afghan afghani', 'AFN', 971, '؋', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(192, 'Tajikistani somoni', 'TJS', 972, 'ЅМ', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(193, 'Angolan kwanza', 'AOA', 973, 'Kz', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(194, 'Belarusian ruble', 'BYR', 974, 'p.', '0', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(195, 'Bulgarian lev', 'BGN', 975, 'лв', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(196, 'Congolese franc', 'CDF', 976, 'Fr', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(197, 'Bosnia and Herzegovina convert', 'BAM', 977, 'KM', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(198, 'Mexican Unid', 'MXV', 979, 'MXV', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(199, 'Ukrainian hryvnia', 'UAH', 980, '₴', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(200, 'Georgian lari', 'GEL', 981, 'ლ', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, 0),
(201, 'Mvdol', 'BOV', 984, 'BOV', '2', ',', '', '', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, '2024-10-29 13:13:48', 110);

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_customs`
--

CREATE TABLE IF NOT EXISTS `#__alfa_customs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `type` varchar(255) DEFAULT '',
  `name` varchar(255) NOT NULL,
  `desc` text DEFAULT NULL,
  `required` varchar(255) DEFAULT '',
  `categories` text DEFAULT NULL,
  `items` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discounts`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discounts` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(400) NOT NULL,
  `desc` text DEFAULT NULL,
  `value` int(11) NOT NULL,
  `is_amount` tinyint(1) DEFAULT 0 COMMENT '0 is percentage, 1 is amount',
  `behavior` tinyint(1) NOT NULL COMMENT '0 only this tax, 1 combine , 2 one after another',
  `state` tinyint(1) DEFAULT 1,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  `apply_before_tax` tinyint(4) NOT NULL COMMENT '0 => discount applied before tax,\r\n1 => discount applied after tax',
  `operation` tinyint(1) NOT NULL COMMENT '0 => substract ( - ), \r\n1 => add ( + )',
  `show_tag` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discount_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_categories` (
  `discount_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discount_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_manufacturers` (
  `discount_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discount_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_places` (
  `discount_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discount_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_usergroups` (
  `discount_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_discount_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_users` (
  `discount_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_form_fields`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` text DEFAULT NULL,
  `params` text DEFAULT NULL,
  `name` text NOT NULL,
  `field_name` text NOT NULL,
  `field_label` text NOT NULL,
  `field_description` text NOT NULL DEFAULT '',
  `required` tinyint(1) DEFAULT 0,
  `registration` tinyint(1) DEFAULT 0,
  `billing` tinyint(1) DEFAULT 0,
  `shipping` tinyint(1) DEFAULT 0,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  `state` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_name` (`field_name`) USING HASH
) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_form_fields_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields_usergroups` (
  `field_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_form_fields_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields_users` (
  `field_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_category_default` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_desc` text DEFAULT NULL,
  `full_desc` text DEFAULT NULL,
  `sku` varchar(255) DEFAULT '',
  `gtin` varchar(255) DEFAULT '',
  `mpn` varchar(255) DEFAULT '',
  `stock` float UNSIGNED DEFAULT NULL,
  `quantity_min` float UNSIGNED NOT NULL DEFAULT 1,
  `quantity_max` float UNSIGNED DEFAULT NULL,
  `quantity_step` float UNSIGNED NOT NULL DEFAULT 1,
  `stock_action` tinyint(1) DEFAULT 0,
  `stock_low` float UNSIGNED DEFAULT NULL,
  `stock_low_message` varchar(300) NOT NULL,
  `stock_zero_message` varchar(300) NOT NULL,
  `manage_stock` tinyint(1) DEFAULT 1,
  `width` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `height` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `depth` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `weight` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `alias` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT '',
  `meta_desc` text DEFAULT NULL,
  `state` tinyint(1) DEFAULT 1,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_categories` (
  `item_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  KEY `idx_item_id` (`item_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_manufacturers` (
  `item_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  KEY `idx_item_id` (`item_id`),
  KEY `idx_manufacturer_id` (`manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_prices`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_prices` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `value` double DEFAULT 0,
  `ovewrited_value` double DEFAULT NULL,
  `item_id` int(11) UNSIGNED NOT NULL,
  `currency_id` int(11) UNSIGNED DEFAULT NULL,
  `usergroup_id` int(11) UNSIGNED DEFAULT NULL,
  `user_id` int(11) UNSIGNED DEFAULT NULL,
  `country_id` int(11) UNSIGNED DEFAULT NULL,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `quantity_start` float DEFAULT NULL,
  `quantity_end` float DEFAULT NULL,
  `tax_id` int(11) DEFAULT 0,
  `discount_id` int(11) DEFAULT 0,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_currency_id` (`currency_id`),
  KEY `idx_usergroup_id` (`usergroup_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_country_id` (`country_id`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_usergroups` (
  `item_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  KEY `item_id` (`item_id`,`usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_users` (
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  KEY `item_id` (`item_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_manufacturers` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `alias` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL,
  `desc` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT '',
  `meta_desc` text DEFAULT NULL,
  `website` varchar(255) DEFAULT '',
  `state` tinyint(1) DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_orders`
--

CREATE TABLE IF NOT EXISTS `#__alfa_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_user_group` int(11) DEFAULT NULL,
  `id_user` int(11) DEFAULT NULL,
  `id_cart` int(11) DEFAULT NULL,
  `id_currency` int(11) DEFAULT NULL,
  `id_address_delivery` int(11) DEFAULT NULL,
  `id_address_invoice` int(11) DEFAULT NULL,
  `id_payment_method` int(11) DEFAULT NULL,
  `id_shipment_method` int(11) DEFAULT NULL,
  `id_order_status` int(11) DEFAULT NULL,
  `id_payment_currency` int(11) DEFAULT NULL,
  `id_language` int(11) DEFAULT NULL,
  `id_shipping_carrier` int(11) DEFAULT NULL,
  `id_coupon` int(11) DEFAULT NULL,
  `code_coupon` int(11) DEFAULT NULL,
  `original_price` float DEFAULT NULL,
  `payed_price` float DEFAULT NULL,
  `total_shipping` float DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `payment_status` varchar(255) DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  `state` tinyint(1) DEFAULT 1 COMMENT 'To manage trashed orders e.g. before delete',
  `checked_out_time` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checked_out` (`checked_out`),
  KEY `modified_by` (`modified_by`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_orders_statuses`
--

CREATE TABLE IF NOT EXISTS `#__alfa_orders_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `color` varchar(50) NOT NULL,
  `bg_color` varchar(50) NOT NULL,
  `stock_operation` tinyint(1) DEFAULT 1 COMMENT '0 removes from stock,\r\n1 keep in stock',
  `state` tinyint(1) DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `#__alfa_orders_statuses`
--

INSERT INTO `#__alfa_orders_statuses` (`id`, `name`, `color`, `bg_color`, `stock_operation`, `state`, `checked_out`, `checked_out_time`, `created_by`, `modified`, `modified_by`, `ordering`) VALUES
(1, 'Επιβεβαιωμένη', 'rgba(255, 255, 255, 1)', 'rgba(14, 158, 11, 1)', 0, 1, NULL, NULL, 0, '2025-02-26 12:12:35', 923, 1),
(2, 'Απεστάλη', '#eeeeee', 'rgba(25, 129, 255, 1)', 0, 1, NULL, NULL, 0, '2025-02-26 12:12:33', 923, 2),
(3, 'Ακυρωμένη', 'rgba(255, 255, 255, 1)', 'rgba(255, 0, 0, 1)', 1, 1, NULL, NULL, 0, '2025-02-26 12:12:42', 923, 4),
(4, 'Ολοκληρώθηκε', 'rgba(0, 0, 0, 1)', 'rgba(214, 214, 214, 1)', 0, 1, NULL, NULL, 0, '2025-02-26 12:12:37', 923, 3);

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `id_shipmentmethod` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `total` float NOT NULL,
  `quantity` float NOT NULL,
  `added` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_item` (`id_item`),
  KEY `id_order` (`id_order`),
  KEY `id_shipmentmethod` (`id_shipmentmethod`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` varchar(9) DEFAULT NULL,
  `id_currency` int(10) UNSIGNED NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `id_payment_method` varchar(255) NOT NULL,
  `conversion_rate` decimal(13,6) DEFAULT 1.000000,
  `transaction_id` varchar(254) DEFAULT NULL,
  `added` datetime NOT NULL,
  `id_user` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` varchar(9) NOT NULL,
  `id_currency` int(11) NOT NULL,
  `amount` decimal(10,0) NOT NULL,
  `id_shipment_method` varchar(255) NOT NULL,
  `track_id` varchar(255) NOT NULL,
  `added` datetime NOT NULL,
  `id_user` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` text NOT NULL DEFAULT 'standard',
  `color` varchar(50) NOT NULL,
  `bg_color` varchar(50) NOT NULL,
  `params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}',
  `show_on_product` tinyint(4) NOT NULL,
  `description` text NOT NULL,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `state` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payment_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_categories` (
  `payment_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payment_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_manufacturers` (
  `payment_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payment_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_places` (
  `payment_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payment_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_usergroups` (
  `payment_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payment_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_users` (
  `payment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_places` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT 0,
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `#__alfa_places`
--

INSERT INTO `#__alfa_places` (`id`, `name`, `parent_id`, `state`, `ordering`, `checked_out`, `checked_out_time`, `created_by`, `modified_by`) VALUES
(1, 'Afghanistan', 0, 0, 0, NULL, NULL, 42, 0),
(2, 'Albania', 0, 0, 0, NULL, NULL, 42, 0),
(3, 'Algeria', 0, 0, 0, NULL, NULL, 42, 0),
(4, 'American Samoa', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(5, 'Andorra', 0, 0, 0, NULL, NULL, 42, 0),
(6, 'Angola', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(7, 'Anguilla', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(8, 'Antarctica', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(9, 'Antigua and Barbuda', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(10, 'Argentina', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(11, 'Armenia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(12, 'Aruba', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(13, 'Australia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(14, 'Austria', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(15, 'Azerbaijan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(16, 'Bahamas', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(17, 'Bahrain', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(18, 'Bangladesh', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(19, 'Barbados', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(20, 'Belarus', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(21, 'Belgium', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(22, 'Belize', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(23, 'Benin', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(24, 'Bermuda', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(25, 'Bhutan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(26, 'Bolivia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(27, 'Bosnia and Herzegowina', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(28, 'Botswana', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(29, 'Bouvet Island', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(30, 'Brazil', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(31, 'British Indian Ocean Territory', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(32, 'Brunei Darussalam', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(33, 'Bulgaria', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(34, 'Burkina Faso', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(35, 'Burundi', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(36, 'Cambodia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(37, 'Cameroon', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(38, 'Canada', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(39, 'Cape Verde', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(40, 'Cayman Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(41, 'Central African Republic', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(42, 'Chad', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(43, 'Chile', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(44, 'China', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(45, 'Christmas Island', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(46, 'Cocos (Keeling) Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(47, 'Colombia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(48, 'Comoros', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(49, 'Congo', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(50, 'Cook Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(51, 'Costa Rica', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(52, 'Cote D\'Ivoire', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(53, 'Croatia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(54, 'Cuba', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(55, 'Cyprus', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(56, 'Czech Republic', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(57, 'Denmark', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(58, 'Djibouti', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(59, 'Dominica', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(60, 'Dominican Republic', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(61, 'East Timor', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(62, 'Ecuador', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(63, 'Egypt', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(64, 'El Salvador', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(65, 'Equatorial Guinea', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(66, 'Eritrea', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(67, 'Estonia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(68, 'Ethiopia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(69, 'Falkland Islands (Malvinas)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(70, 'Faroe Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(71, 'Fiji', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(72, 'Finland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(73, 'France', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(75, 'French Guiana', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(76, 'French Polynesia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(77, 'French Southern Territories', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(78, 'Gabon', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(79, 'Gambia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(80, 'Georgia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(81, 'Germany', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(82, 'Ghana', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(83, 'Gibraltar', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(84, 'Greece', 0, 1, 0, NULL, NULL, 42, 0),
(85, 'Greenland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(86, 'Grenada', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(87, 'Guadeloupe', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(88, 'Guam', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(89, 'Guatemala', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(90, 'Guinea', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(91, 'Guinea-bissau', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(92, 'Guyana', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(93, 'Haiti', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(94, 'Heard and Mc Donald Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(95, 'Honduras', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(96, 'Hong Kong', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(97, 'Hungary', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(98, 'Iceland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(99, 'India', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(100, 'Indonesia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(101, 'Iran (Islamic Republic of)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(102, 'Iraq', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(103, 'Ireland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(104, 'Israel', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(105, 'Italy', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(106, 'Jamaica', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(107, 'Japan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(108, 'Jordan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(109, 'Kazakhstan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(110, 'Kenya', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(111, 'Kiribati', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(112, 'Korea, Democratic People\'s Republic of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(113, 'Korea, Republic of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(114, 'Kuwait', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(115, 'Kyrgyzstan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(116, 'Lao People\'s Democratic Republic', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(117, 'Latvia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(118, 'Lebanon', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(119, 'Lesotho', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(120, 'Liberia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(121, 'Libya', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(122, 'Liechtenstein', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(123, 'Lithuania', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(124, 'Luxembourg', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(125, 'Macau', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(126, 'Macedonia, The Former Yugoslav Republic of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(127, 'Madagascar', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(128, 'Malawi', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(129, 'Malaysia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(130, 'Maldives', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(131, 'Mali', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(132, 'Malta', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(133, 'Marshall Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(134, 'Martinique', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(135, 'Mauritania', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(136, 'Mauritius', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(137, 'Mayotte', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(138, 'Mexico', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(139, 'Micronesia, Federated States of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(140, 'Moldova, Republic of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(141, 'Monaco', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(142, 'Mongolia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(143, 'Montserrat', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(144, 'Morocco', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(145, 'Mozambique', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(146, 'Myanmar', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(147, 'Namibia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(148, 'Nauru', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(149, 'Nepal', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(150, 'Netherlands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(151, 'Netherlands Antilles', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(152, 'New Caledonia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(153, 'New Zealand', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(154, 'Nicaragua', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(155, 'Niger', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(156, 'Nigeria', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(157, 'Niue', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(158, 'Norfolk Island', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(159, 'Northern Mariana Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(160, 'Norway', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(161, 'Oman', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(162, 'Pakistan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(163, 'Palau', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(164, 'Panama', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(165, 'Papua New Guinea', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(166, 'Paraguay', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(167, 'Peru', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(168, 'Philippines', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(169, 'Pitcairn', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(170, 'Poland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(171, 'Portugal', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(172, 'Puerto Rico', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(173, 'Qatar', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(174, 'Reunion', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(175, 'Romania', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(176, 'Russian Federation', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(177, 'Rwanda', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(178, 'Saint Kitts and Nevis', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(179, 'Saint Lucia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(180, 'Saint Vincent and the Grenadines', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(181, 'Samoa', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(182, 'San Marino', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(183, 'Sao Tome and Principe', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(184, 'Saudi Arabia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(185, 'Senegal', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(186, 'Seychelles', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(187, 'Sierra Leone', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(188, 'Singapore', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(189, 'Slovakia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(190, 'Slovenia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(191, 'Solomon Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(192, 'Somalia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(193, 'South Africa', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(194, 'South Georgia and the South Sandwich Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(195, 'Spain', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(196, 'Sri Lanka', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(197, 'St. Helena', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(198, 'St. Pierre and Miquelon', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(199, 'Sudan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(200, 'Suriname', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(201, 'Svalbard and Jan Mayen Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(202, 'Swaziland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(203, 'Sweden', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(204, 'Switzerland', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(205, 'Syrian Arab Republic', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(206, 'Taiwan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(207, 'Tajikistan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(208, 'Tanzania, United Republic of', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(209, 'Thailand', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(210, 'Togo', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(211, 'Tokelau', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(212, 'Tonga', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(213, 'Trinidad and Tobago', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(214, 'Tunisia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(215, 'Turkey', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(216, 'Turkmenistan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(217, 'Turks and Caicos Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(218, 'Tuvalu', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(219, 'Uganda', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(220, 'Ukraine', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(221, 'United Arab Emirates', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(222, 'United Kingdom', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(223, 'United States', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(224, 'United States Minor Outlying Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(225, 'Uruguay', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(226, 'Uzbekistan', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(227, 'Vanuatu', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(228, 'Vatican City State (Holy See)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(229, 'Venezuela', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(230, 'Viet Nam', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(231, 'Virgin Islands (British)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(232, 'Virgin Islands (U.S.)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(233, 'Wallis and Futuna Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(234, 'Western Sahara', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(235, 'Yemen', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(237, 'The Democratic Republic of Congo', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(238, 'Zambia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(239, 'Zimbabwe', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(240, 'East Timor', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(241, 'Jersey', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(242, 'St. Barthelemy', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(243, 'St. Eustatius', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(244, 'Canary Islands', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(245, 'Serbia', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(246, 'Sint Maarten (French Antilles)', 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(247, 'Sint Maarten (Netherlands Antilles)', 0, 0, 0, NULL, NULL, 42, 923),
(248, 'Palestinian Territory, occupied', 0, 0, 0, NULL, NULL, 42, 923),
(249, 'Larisa', 84, 1, 1, NULL, NULL, 923, 923);

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` text NOT NULL,
  `color` varchar(50) NOT NULL,
  `bg_color` varchar(50) NOT NULL,
  `params` longtext NOT NULL,
  `show_on_product` tinyint(4) NOT NULL,
  `description` text NOT NULL,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `state` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipment_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_categories` (
  `shipment_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipment_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_manufacturers` (
  `shipment_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipment_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_places` (
  `shipment_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipment_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_usergroups` (
  `shipment_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipment_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_users` (
  `shipment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_taxes`
--

CREATE TABLE IF NOT EXISTS `#__alfa_taxes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(400) NOT NULL,
  `desc` text DEFAULT NULL,
  `value` int(11) NOT NULL,
  `behavior` tinyint(1) NOT NULL COMMENT '0 only this tax, 1 combine , 2 one after another',
  `state` tinyint(1) DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_tax_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_categories` (
  `tax_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_tax_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_manufacturers` (
  `tax_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_tax_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_places` (
  `tax_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_tax_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_usergroups` (
  `tax_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_tax_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_users` (
  `tax_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_usergroups` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `prices_display` varchar(255) DEFAULT '',
  `name` varchar(255) NOT NULL,
  `prices_enable` varchar(255) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_users` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_user_info`
--

CREATE TABLE IF NOT EXISTS `#__alfa_user_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
