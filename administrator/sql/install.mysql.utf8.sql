--
-- Table structure for table `#__alfa_items_medias`
--


CREATE TABLE IF NOT EXISTS `#__alfa_items_media` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `path` varchar(250) NOT NULL,
  `thumbnail` varchar(400) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `alt` varchar(75) DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `origin` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


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
  `recognize_key` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `meta_data` text NOT NULL,
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
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=202 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to store currencies';

--
-- Dumping data for table `#__alfa_currencies`
--

INSERT INTO `#__alfa_currencies` (`id`, `name`, `code`, `number`, `symbol`, `decimal_place`, `decimal_separator`, `thousand_separator`, `format_pattern`, `currency_thousands`, `currency_positive_style`, `currency_negative_style`, `ordering`, `state`, `created_by`, `modified_by`, `checked_out_time`, `checked_out`) VALUES
(2, 'United Arab Emirates dirham', 'AED', 784, 'د.إ', '4', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(4, 'Albanian lek', 'ALL', 8, 'Lek', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(5, 'Netherlands Antillean gulden', 'ANG', 532, 'ƒ', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(7, 'Argentine peso', 'ARS', 32, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(9, 'Australian dollar', 'AUD', 36, '$', '2', ',', '.', '{number}{symbol}', '', '{symbol} {number}', '{sign}{symbol} {number}', 0, 1, 0, 923, NULL, NULL),
(10, 'Aruban florin', 'AWG', 533, 'ƒ', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(11, 'Barbadian dollar', 'BBD', 52, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(12, 'Bangladeshi taka', 'BDT', 50, '৳', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(15, 'Bahraini dinar', 'BHD', 48, 'ب.د', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(16, 'Burundian franc', 'BIF', 108, 'Fr', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(17, 'Bermudian dollar', 'BMD', 60, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(18, 'Brunei dollar', 'BND', 96, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(19, 'Bolivian boliviano', 'BOB', 68, '$b', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(20, 'Brazilian real', 'BRL', 986, 'R$', '2', ',', '.', '{number}{symbol}', '.', '{symbol} {number}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, NULL),
(21, 'Bahamian dollar', 'BSD', 44, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(22, 'Bhutanese ngultrum', 'BTN', 64, 'BTN', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(24, 'Botswana pula', 'BWP', 72, 'P', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(25, 'Belize dollar', 'BZD', 84, 'BZ$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(26, 'Canadian dollar', 'CAD', 124, '$', '2', ',', '.', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(27, 'Swiss franc', 'CHF', 756, 'CHF', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(28, 'Unidad de Fomento', 'CLF', 990, 'CLF', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(29, 'Chilean peso', 'CLP', 152, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(30, 'Chinese renminbi yuan', 'CNY', 156, '元', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(31, 'Colombian peso', 'COP', 170, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(32, 'Costa Rican colón', 'CRC', 188, '₡', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(33, 'Czech koruna', 'CZK', 203, 'Kč', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(34, 'Cuban peso', 'CUP', 192, '₱', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(35, 'Cape Verdean escudo', 'CVE', 132, '$', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(40, 'Danish krone', 'DKK', 208, 'kr', '2', ',', '.', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(41, 'Dominican peso', 'DOP', 214, 'RD$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(42, 'Algerian dinar', 'DZD', 12, 'د.ج', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(44, 'Egyptian pound', 'EGP', 818, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(46, 'Ethiopian birr', 'ETB', 230, 'ETB', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(47, 'Euro', 'EUR', 978, '€', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 922, NULL, NULL),
(49, 'Fijian dollar', 'FJD', 242, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(50, 'Falkland pound', 'FKP', 238, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(52, 'British pound', 'GBP', 826, '£', '2', ',', '.', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(54, 'Gibraltar pound', 'GIP', 292, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(55, 'Gambian dalasi', 'GMD', 270, 'D', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(56, 'Guinean franc', 'GNF', 324, 'Fr', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(58, 'Guatemalan quetzal', 'GTQ', 320, 'Q', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(60, 'Guyanese dollar', 'GYD', 328, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(61, 'Hong Kong dollar', 'HKD', 344, '元', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(62, 'Honduran lempira', 'HNL', 340, 'L', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(63, 'Haitian gourde', 'HTG', 332, 'G', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(64, 'Hungarian forint', 'HUF', 348, 'Ft', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(65, 'Indonesian rupiah', 'IDR', 360, 'Rp', '0', ',', '.', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(67, 'Israeli new sheqel', 'ILS', 376, '₪', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(68, 'Indian rupee', 'INR', 356, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(69, 'Iraqi dinar', 'IQD', 368, 'ع.د', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(70, 'Iranian rial', 'IRR', 364, '﷼', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number}{symbol}', 0, 1, 0, 0, NULL, NULL),
(73, 'Jamaican dollar', 'JMD', 388, 'J$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(74, 'Jordanian dinar', 'JOD', 400, 'د.ا', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(75, 'Japanese yen', 'JPY', 392, '¥', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(76, 'Kenyan shilling', 'KES', 404, 'Sh', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(77, 'Cambodian riel', 'KHR', 116, '៛', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(78, 'Comorian franc', 'KMF', 174, 'Fr', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(79, 'North Korean won', 'KPW', 408, '₩', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(80, 'South Korean won', 'KRW', 410, '₩', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(81, 'Kuwaiti dinar', 'KWD', 414, 'د.ك', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(82, 'Cayman Islands dollar', 'KYD', 136, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(83, 'Lao kip', 'LAK', 418, '₭', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(84, 'Lebanese pound', 'LBP', 422, '£', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(85, 'Sri Lankan rupee', 'LKR', 144, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(86, 'Liberian dollar', 'LRD', 430, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(87, 'Lesotho loti', 'LSL', 426, 'L', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(89, 'Libyan dinar', 'LYD', 434, 'ل.د', '3', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(90, 'Moroccan dirham', 'MAD', 504, 'د.م.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(92, 'Mongolian tögrög', 'MNT', 496, '₮', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(93, 'Macanese pataca', 'MOP', 446, 'P', '1', ',', '.', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(94, 'Mauritanian ouguiya', 'MRO', 478, 'UM', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(96, 'Mauritian rupee', 'MUR', 480, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(97, 'Maldivian rufiyaa', 'MVR', 462, 'ރ.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(98, 'Malawian kwacha', 'MWK', 454, 'MK', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(100, 'Malaysian ringgit', 'MYR', 458, 'RM', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(102, 'Nigerian naira', 'NGN', 566, '₦', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(105, 'Norwegian krone', 'NOK', 578, 'kr', '2', ',', '.', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(106, 'Nepalese rupee', 'NPR', 524, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(107, 'New Zealand dollar', 'NZD', 554, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(108, 'Omani rial', 'OMR', 512, '﷼', '3', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(109, 'Panamanian balboa', 'PAB', 590, 'B/.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(110, 'Peruvian nuevo sol', 'PEN', 604, 'S/.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(111, 'Papua New Guinean kina', 'PGK', 598, 'K', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(112, 'Philippine peso', 'PHP', 608, '₱', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(113, 'Pakistani rupee', 'PKR', 586, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(114, 'Polish Złoty', 'PLN', 985, 'zł', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(116, 'Paraguayan guaraní', 'PYG', 600, '₲', '0', ',', '.', '{number}{symbol}', '.', '{symbol} {number}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, NULL),
(117, 'Qatari riyal', 'QAR', 634, '﷼', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(118, 'Romanian leu', 'RON', 946, 'lei', '2', ',', '.', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(119, 'Rwandan franc', 'RWF', 646, 'Fr', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(120, 'Saudi riyal', 'SAR', 682, '﷼', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(121, 'Solomon Islands dollar', 'SBD', 90, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(122, 'Seychellois rupee', 'SCR', 690, '₨', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(124, 'Swedish krona', 'SEK', 752, 'kr', '2', ',', '.', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(125, 'Singapore dollar', 'SGD', 702, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(126, 'Saint Helenian pound', 'SHP', 654, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(127, 'Sierra Leonean leone', 'SLL', 694, 'Le', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(128, 'Somali shilling', 'SOS', 706, 'S', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(130, 'São Tomé and Príncipe dobra', 'STD', 678, 'Db', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(131, 'Russian ruble', 'RUB', 643, 'руб', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(132, 'Salvadoran colón', 'SVC', 222, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(133, 'Syrian pound', 'SYP', 760, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(134, 'Swazi lilangeni', 'SZL', 748, 'L', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(135, 'Thai baht', 'THB', 764, '฿', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(136, 'Tunisian dinar', 'TND', 788, 'د.ت', '3', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(137, 'Tongan paʻanga', 'TOP', 776, 'T$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(139, 'Türk Lirası', 'TRY', 949, 'TL', '2', ',', '.', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(140, 'Trinidad and Tobago dollar', 'TTD', 780, 'TT$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(141, 'New Taiwan dollar', 'TWD', 901, 'NT$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(142, 'Tanzanian shilling', 'TZS', 834, 'Sh', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(144, 'United States dollar', 'USD', 840, '$', '2', ',', '.', '{number}{symbol}', ',', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(147, 'Vietnamese Dong', 'VND', 704, '₫', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(148, 'Vanuatu vatu', 'VUV', 548, 'Vt', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(149, 'Samoan tala', 'WST', 882, 'T', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(151, 'Yemeni rial', 'YER', 886, '﷼', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(152, 'Serbian dinar', 'RSD', 941, 'Дин.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(153, 'South African rand', 'ZAR', 710, 'R', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(154, 'Zambian kwacha', 'ZMK', 894, 'ZK', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(156, 'Zimbabwean dollar', 'ZWD', 932, 'Z$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(158, 'Armenian dram', 'AMD', 51, 'դր.', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(159, 'Myanmar kyat', 'MMK', 104, 'K', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{symbol} {sign}{number}', 0, 1, 0, 0, NULL, NULL),
(160, 'Croatian kuna', 'HRK', 191, 'kn', '2', ',', '.', '{number}{symbol}', '.', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(161, 'Eritrean nakfa', 'ERN', 232, 'Nfk', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(162, 'Djiboutian franc', 'DJF', 262, 'Fr', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(163, 'Icelandic króna', 'ISK', 352, 'kr', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(164, 'Kazakhstani tenge', 'KZT', 398, 'лв', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(165, 'Kyrgyzstani som', 'KGS', 417, 'лв', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(166, 'Latvian lats', 'LVL', 428, 'Ls', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(167, 'Lithuanian litas', 'LTL', 440, 'Lt', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(168, 'Mexican peso', 'MXN', 484, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(169, 'Moldovan leu', 'MDL', 498, 'L', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(170, 'Namibian dollar', 'NAD', 516, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(171, 'Nicaraguan córdoba', 'NIO', 558, 'C$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(172, 'Ugandan shilling', 'UGX', 800, 'Sh', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(173, 'Macedonian denar', 'MKD', 807, 'ден', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(174, 'Uruguayan peso', 'UYU', 858, '$', '0', ',', '.', '{number}{symbol}', '', '{symbol}{number}', '{symbol}{sign}{number}', 0, 1, 0, 0, NULL, NULL),
(175, 'Uzbekistani som', 'UZS', 860, 'лв', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(176, 'Azerbaijani manat', 'AZN', 934, 'ман', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(177, 'Ghanaian cedi', 'GHS', 936, '₵', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(178, 'Venezuelan bolívar', 'VEF', 937, 'Bs', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(179, 'Sudanese pound', 'SDG', 938, '£', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(180, 'Uruguay Peso', 'UYI', 940, 'UYI', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(181, 'Mozambican metical', 'MZN', 943, 'MT', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(182, 'WIR Euro', 'CHE', 947, '€', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(183, 'WIR Franc', 'CHW', 948, 'CHW', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(184, 'Central African CFA franc', 'XAF', 950, 'Fr', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(185, 'East Caribbean dollar', 'XCD', 951, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(186, 'West African CFA franc', 'XOF', 952, 'Fr', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(187, 'CFP franc', 'XPF', 953, 'F', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(188, 'Surinamese dollar', 'SRD', 968, '$', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(189, 'Malagasy ariary', 'MGA', 969, 'MGA', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(190, 'Unidad de Valor Real', 'COU', 970, 'COU', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(191, 'Afghan afghani', 'AFN', 971, '؋', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(192, 'Tajikistani somoni', 'TJS', 972, 'ЅМ', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(193, 'Angolan kwanza', 'AOA', 973, 'Kz', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(194, 'Belarusian ruble', 'BYR', 974, 'p.', '0', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(195, 'Bulgarian lev', 'BGN', 975, 'лв', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(196, 'Congolese franc', 'CDF', 976, 'Fr', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(197, 'Bosnia and Herzegovina convert', 'BAM', 977, 'KM', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(198, 'Mexican Unid', 'MXV', 979, 'MXV', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(199, 'Ukrainian hryvnia', 'UAH', 980, '₴', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(200, 'Georgian lari', 'GEL', 981, 'ლ', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL),
(201, 'Mvdol', 'BOV', 984, 'BOV', '2', ',', '.', '{number}{symbol}', '', '{number} {symbol}', '{sign}{number} {symbol}', 0, 1, 0, 0, NULL, NULL);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `stock` float DEFAULT NULL COMMENT 'Current stock level (negative = backorder)',
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
  `meta_data` text NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_items_price_index`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_price_index` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'FK to #__alfa_items.id',
  `currency_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to #__alfa_currencies.id; 0 = any currency',
  `place_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to #__alfa_places.id; 0 = any place / unknown location',
  `usergroup_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Usergroup ID; 0 = public / any group',
  `base_price` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Raw unit price — no discounts, no tax',
  `discount_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Total money saved. 0 = no discount. Filter: > 0 (on sale), >= X (save at least €X)',
  `base_price_with_discounts` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'After discounts, before tax — B2B ex-VAT net price',
  `tax_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Tax applied to the discounted price',
  `base_price_with_tax` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'Base price + tax, WITHOUT discounts — strikethrough "was" price',
  `final_price` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'FILTER COLUMN ★ — final price visitor pays (after discounts + tax)',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentage saving 0-100. Filter: >= X (at least X% off)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Last sync timestamp for stale-row detection in partial re-index jobs',
  PRIMARY KEY (`item_id`,`currency_id`,`place_id`,`usergroup_id`),
  KEY `idx_final_price` (`currency_id`,`place_id`,`usergroup_id`,`final_price`),
  KEY `idx_discount_amount` (`discount_amount`),
  KEY `idx_discount_percent` (`discount_percent`),
  KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Price index: one row per item x currency x place x usergroup. Column names match com_alfa config fields.';

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
  `reference` varchar(20) DEFAULT NULL COMMENT 'Human-readable order code (optional - can use ID instead)',
  `id_user` int(11) DEFAULT NULL,
  `id_cart` int(11) DEFAULT NULL,
  `id_currency` int(11) DEFAULT NULL,
  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000 COMMENT 'Currency conversion rate at order time',
  `invoice_number` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sequential invoice number',
  `invoice_date` datetime DEFAULT NULL COMMENT 'When invoice was generated',
  `delivery_date` datetime DEFAULT NULL COMMENT 'When order was delivered',
  `id_address_delivery` int(11) DEFAULT NULL,
  `id_address_invoice` int(11) DEFAULT NULL,
  `id_payment_method` int(11) DEFAULT NULL,
  `id_shipment_method` int(11) DEFAULT NULL,
  `id_order_status` int(11) DEFAULT NULL,
  `secure_key` varchar(32) NOT NULL DEFAULT '' COMMENT 'Security key for guest order tracking',
  `payment_method_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Payment method name snapshot (fallback if id_payment_method deleted)',
  `shipment_method_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Shipment method name snapshot (fallback if id_shipment_method deleted)',
  `id_payment_currency` int(11) DEFAULT NULL,
  `id_language` int(11) DEFAULT NULL,
  `id_coupon` int(11) DEFAULT NULL,
  `code_coupon` int(11) DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `checked_out` int(10) DEFAULT NULL,
  `state` tinyint(1) DEFAULT 1 COMMENT 'To manage trashed orders e.g. before delete',
  `checked_out_time` datetime DEFAULT NULL,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_reference` (`reference`),
  KEY `checked_out` (`checked_out`),
  KEY `modified_by` (`modified_by`),
  KEY `created_by` (`created_by`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_id_cart` (`id_cart`),
  KEY `idx_date_add` (`created`),
  KEY `idx_invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Default status for new orders (only one should be 1)',
  `state` tinyint(1) DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `#__alfa_orders_statuses`
--

INSERT INTO `#__alfa_orders_statuses` (`id`, `name`, `color`, `bg_color`, `stock_operation`, `is_default`, `state`, `checked_out`, `checked_out_time`, `created_by`, `modified`, `modified_by`, `ordering`) VALUES
(1, 'Επιβεβαιωμένη', 'rgba(255, 255, 255, 1)', 'rgba(14, 158, 11, 1)', 0, 1, 1, NULL, NULL, 0, '2025-02-26 12:12:35', 923, 1),
(2, 'Απεστάλη', '#eeeeee', 'rgba(25, 129, 255, 1)', 0, 0, 1, NULL, NULL, 0, '2025-02-26 12:12:33', 923, 2),
(3, 'Ακυρωμένη', 'rgba(255, 255, 255, 1)', 'rgba(255, 0, 0, 1)', 1, 0, 1, NULL, NULL, 0, '2025-02-26 12:12:42', 923, 4),
(4, 'Ολοκληρώθηκε', 'rgba(0, 0, 0, 1)', 'rgba(214, 214, 214, 1)', 0, 0, 1, NULL, NULL, 0, '2025-02-26 12:12:37', 923, 3);

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_activity_log`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_activity_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(11) UNSIGNED NOT NULL,
  `id_employee` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = system or customer',
  `employee_name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Name snapshot at time of event',
  `event` varchar(64) NOT NULL COMMENT 'Dot-notation: entity.action',
  `id_order_status` int(11) UNSIGNED DEFAULT NULL COMMENT 'Order status at time of event',
  `status_name` varchar(100) DEFAULT '' COMMENT 'Status name snapshot',
  `entity_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'PK of related item/payment/shipment row',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT 'Human-readable: "Added Main Test Item x2 at 13.02"',
  `context` text DEFAULT NULL COMMENT 'JSON: event-specific structured data',
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order` (`id_order`),
  KEY `idx_event` (`event`),
  KEY `idx_entity` (`event`,`entity_id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_cart_rule`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_cart_rule` (
  `id_order_cart_rule` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(10) UNSIGNED NOT NULL,
  `id_cart_rule` int(10) UNSIGNED NOT NULL,
  `id_order_invoice` int(10) UNSIGNED DEFAULT 0,
  `name` varchar(254) NOT NULL,
  `value_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `free_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_order_cart_rule`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_cart_rule` (`id_cart_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_detail_tax`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_detail_tax` (
  `id_order_detail` int(11) NOT NULL,
  `id_tax` int(11) NOT NULL,
  `unit_amount` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `total_amount` decimal(16,6) NOT NULL DEFAULT 0.000000,
  KEY `idx_id_order_detail` (`id_order_detail`),
  KEY `idx_id_tax` (`id_tax`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_item` int(11) NOT NULL,
  `id_order` int(11) NOT NULL,
  `id_order_invoice` int(11) UNSIGNED DEFAULT NULL COMMENT 'Invoice ID (for multi-invoice orders)',
  `id_warehouse` int(10) UNSIGNED DEFAULT 0 COMMENT 'Warehouse item shipped from',
  `id_product_attribute` int(10) UNSIGNED DEFAULT NULL COMMENT 'Product variation/combination ID',
  `id_customization` int(10) UNSIGNED DEFAULT 0 COMMENT 'Customization ID',
  `id_shipmentmethod` int(11) NOT NULL,
  `id_order_shipment` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `reference` varchar(64) DEFAULT NULL COMMENT 'Product SKU/reference',
  `supplier_reference` varchar(64) DEFAULT NULL COMMENT 'Supplier SKU/reference',
  `ean13` varchar(13) DEFAULT NULL COMMENT 'EAN-13 barcode (European Article Number)',
  `isbn` varchar(32) DEFAULT NULL COMMENT 'ISBN for books',
  `upc` varchar(12) DEFAULT NULL COMMENT 'UPC-12 barcode (Universal Product Code)',
  `mpn` varchar(40) DEFAULT NULL COMMENT 'Manufacturer Part Number',
  `weight` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Product weight for shipping',
  `total_price_tax_incl` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `total_price_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Line total excluding tax',
  `unit_price_tax_incl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Unit price including tax',
  `unit_price_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Unit price excluding tax',
  `total_shipping_price_tax_incl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Shipping for this item including tax',
  `total_shipping_price_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Shipping for this item excluding tax',
  `purchase_supplier_price` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Your cost from supplier',
  `original_product_price` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Original price before discounts',
  `original_wholesale_price` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Original wholesale price',
  `total_refunded_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Total refunded excluding tax',
  `total_refunded_tax_incl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Total refunded including tax',
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `quantity_in_stock` int(10) NOT NULL DEFAULT 0 COMMENT 'Stock at order time (snapshot)',
  `quantity_refunded` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity refunded',
  `quantity_return` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity returned by customer',
  `quantity_reinjected` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity put back in stock',
  `reduction_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount percentage (e.g., 20.00 = 20% off)',
  `reduction_amount_tax_excl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Discount amount excluding tax',
  `reduction_amount_tax_incl` decimal(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Discount amount including tax',
  `group_reduction` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Customer group discount',
  `id_tax_rules_group` int(11) UNSIGNED DEFAULT 0 COMMENT 'Tax rules group ID',
  `tax_computation_method` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=tax excluded, 1=tax included',
  `tax_name` varchar(16) NOT NULL DEFAULT '' COMMENT 'Tax name (e.g., VAT, Sales Tax)',
  `tax_rate` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Tax percentage (e.g., 24.000 = 24%)',
  `ecotax` decimal(17,6) NOT NULL DEFAULT 0.000000 COMMENT 'Environmental tax amount',
  `ecotax_tax_rate` decimal(5,3) NOT NULL DEFAULT 0.000 COMMENT 'Tax rate on ecotax',
  `download_hash` varchar(255) DEFAULT NULL COMMENT 'Download link hash for digital products',
  `download_nb` int(10) UNSIGNED DEFAULT 0 COMMENT 'Number of downloads allowed',
  `download_deadline` datetime DEFAULT NULL COMMENT 'Download expiration date',
  `added` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_order` (`id_order`),
  KEY `id_shipmentmethod` (`id_shipmentmethod`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_product_attribute_id` (`id_product_attribute`),
  KEY `id_item` (`id_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_reference` varchar(20) DEFAULT NULL COMMENT 'Order reference (if using references)',
  `id_order` int(11) NOT NULL,
  `id_currency` int(10) UNSIGNED NOT NULL,
  `amount` decimal(20,6) NOT NULL COMMENT 'Payment amount (negative for refunds)',
  `id_payment_method` int(11) NOT NULL COMMENT 'Payment method ID',
  `payment_method` varchar(255) NOT NULL COMMENT 'Payment method name snapshot (fallback if method deleted)',
  `id_refunded_payment` int(11) DEFAULT NULL COMMENT 'If refund, links to original payment',
  `payment_type` enum('payment','refund','authorization') NOT NULL DEFAULT 'payment' COMMENT 'payment=normal, refund=money back, authorization=held funds',
  `transaction_status` enum('pending','authorized','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'completed' COMMENT 'pending=awaiting, authorized=held, completed=success',
  `transaction_id` varchar(254) DEFAULT NULL COMMENT 'Gateway transaction ID',
  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000 COMMENT 'Currency conversion rate',
  `gateway_response` text DEFAULT NULL COMMENT 'Complete JSON from gateway (card details, metadata, everything)',
  `refund_reason` varchar(255) DEFAULT NULL,
  `refund_type` enum('full','partial') DEFAULT NULL,
  `added` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL COMMENT 'When payment was confirmed',
  `id_employee` int(11) DEFAULT NULL COMMENT 'Admin who created payment',
  PRIMARY KEY (`id`),
  KEY `idx_order_reference` (`order_reference`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_transaction_status` (`transaction_status`),
  KEY `idx_payment_type` (`payment_type`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_id_refunded_payment` (`id_refunded_payment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) UNSIGNED NOT NULL,
  `id_shipment_method` int(11) UNSIGNED NOT NULL COMMENT 'Shipment method ID',
  `shipment_method_name` varchar(255) DEFAULT NULL COMMENT 'Shipment method name snapshot (fallback if method deleted)',
  `id_order_invoice` int(11) UNSIGNED DEFAULT NULL,
  `id_currency` int(11) UNSIGNED NOT NULL,
  `weight` decimal(20,6) DEFAULT NULL,
  `shipping_cost_tax_excl` decimal(20,6) DEFAULT NULL,
  `shipping_cost_tax_incl` decimal(20,6) DEFAULT NULL,
  `tracking_number` varchar(64) DEFAULT NULL,
  `carrier_name` varchar(255) DEFAULT NULL COMMENT 'Actual carrier name (DHL, FedEx, etc)',
  `added` datetime NOT NULL,
  `shipped` datetime DEFAULT NULL COMMENT 'Timestamp when shipment was marked as shipped',
  `delivered` datetime DEFAULT NULL COMMENT 'Timestamp when shipment was marked as delivered',
  `id_employee` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'Lifecycle status: pending, shipped, delivered, cancelled',
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_shipment_method` (`id_shipment_method`),
  KEY `idx_tracking_number` (`tracking_number`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_slip`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_slip` (
  `id_order_slip` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000,
  `id_customer` int(10) UNSIGNED NOT NULL,
  `id_order` int(10) UNSIGNED NOT NULL,
  `total_products_tax_excl` decimal(20,6) DEFAULT NULL,
  `total_products_tax_incl` decimal(20,6) DEFAULT NULL,
  `total_shipping_tax_excl` decimal(20,6) DEFAULT NULL,
  `total_shipping_tax_incl` decimal(20,6) DEFAULT NULL,
  `amount` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `shipping_cost_amount` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `partial` tinyint(1) NOT NULL DEFAULT 0,
  `order_slip_type` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_order_slip`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_slip_detail`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_slip_detail` (
  `id_order_slip` int(10) UNSIGNED NOT NULL,
  `id_order_detail` int(10) UNSIGNED NOT NULL,
  `product_quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `unit_price_tax_excl` decimal(20,6) DEFAULT NULL,
  `unit_price_tax_incl` decimal(20,6) DEFAULT NULL,
  `total_price_tax_excl` decimal(20,6) DEFAULT NULL,
  `total_price_tax_incl` decimal(20,6) DEFAULT NULL,
  `amount_tax_excl` decimal(20,6) DEFAULT NULL,
  `amount_tax_incl` decimal(20,6) DEFAULT NULL,
  PRIMARY KEY (`id_order_slip`,`id_order_detail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payments_standard_logs`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payments_standard_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_order_payment` int(11) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `transaction_status` varchar(20) DEFAULT NULL,
  `amount` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `order_total` decimal(15,5) NOT NULL DEFAULT 0.00000,
  `currency` char(3) DEFAULT NULL,
  `transaction_id` varchar(254) DEFAULT NULL,
  `refund_type` varchar(10) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_order_payment` (`id_order_payment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `code2` varchar(2) NOT NULL,
  `code3` varchar(3) NOT NULL,
  `number` double NOT NULL DEFAULT 0,
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
) ENGINE=InnoDB AUTO_INCREMENT=250 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `#__alfa_places`
--

INSERT INTO `#__alfa_places` (`id`, `name`, `code2`, `code3`, `number`, `parent_id`, `state`, `ordering`, `checked_out`, `checked_out_time`, `created_by`, `modified_by`) VALUES
(1, 'Afghanistan', 'AF', 'AFG', 0, 0, 0, 0, NULL, NULL, 42, 0),
(2, 'Albania', 'AL', 'ALB', 0, 0, 0, 0, NULL, NULL, 42, 0),
(3, 'Algeria', 'DZ', 'DZA', 0, 0, 0, 0, NULL, NULL, 42, 0),
(4, 'American Samoa', 'AS', 'ASM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(5, 'Andorra', 'AD', 'AND', 0, 0, 0, 0, NULL, NULL, 42, 0),
(6, 'Angola', 'AO', 'AGO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(7, 'Anguilla', 'AI', 'AIA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(8, 'Antarctica', 'AQ', 'ATA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(9, 'Antigua and Barbuda', 'AG', 'ATG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(10, 'Argentina', 'AR', 'ARG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(11, 'Armenia', 'AM', 'ARM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(12, 'Aruba', 'AW', 'ABW', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(13, 'Australia', 'AU', 'AUS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(14, 'Austria', 'AT', 'AUT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(15, 'Azerbaijan', 'AZ', 'AZE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(16, 'Bahamas', 'BS', 'BHS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(17, 'Bahrain', 'BH', 'BHR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(18, 'Bangladesh', 'BD', 'BGD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(19, 'Barbados', 'BB', 'BRB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(20, 'Belarus', 'BY', 'BLR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(21, 'Belgium', 'BE', 'BEL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(22, 'Belize', 'BZ', 'BLZ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(23, 'Benin', 'BJ', 'BEN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(24, 'Bermuda', 'BM', 'BMU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(25, 'Bhutan', 'BT', 'BTN', 64, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(26, 'Bolivia', 'BO', 'BOL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(27, 'Bosnia and Herzegowina', 'BA', 'BIH', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(28, 'Botswana', 'BW', 'BWA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(29, 'Bouvet Island', 'BV', 'BVT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(30, 'Brazil', 'BR', 'BRA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(31, 'British Indian Ocean Territory', 'IO', 'IOT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(32, 'Brunei Darussalam', 'BN', 'BRN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(33, 'Bulgaria', 'BG', 'BGR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(34, 'Burkina Faso', 'BF', 'BFA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(35, 'Burundi', 'BI', 'BDI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(36, 'Cambodia', 'KH', 'KHM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(37, 'Cameroon', 'CM', 'CMR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(38, 'Canada', 'CA', 'CAN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(39, 'Cape Verde', 'CV', 'CPV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(40, 'Cayman Islands', 'KY', 'CYM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(41, 'Central African Republic', 'CF', 'CAF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(42, 'Chad', 'TD', 'TCD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(43, 'Chile', 'CL', 'CHL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(44, 'China', 'CN', 'CHN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(45, 'Christmas Island', 'CX', 'CXR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(46, 'Cocos (Keeling) Islands', 'CC', 'CCK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(47, 'Colombia', 'CO', 'COL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(48, 'Comoros', 'KM', 'COM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(49, 'Congo', 'CG', 'COG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(50, 'Cook Islands', 'CK', 'COK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(51, 'Costa Rica', 'CR', 'CRI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(52, 'Cote D\'Ivoire', 'CI', 'CIV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(53, 'Croatia', 'HR', 'HRV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(54, 'Cuba', 'CU', 'CUB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(55, 'Cyprus', 'CY', 'CYP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(56, 'Czech Republic', 'CZ', 'CZE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(57, 'Denmark', 'DK', 'DNK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(58, 'Djibouti', 'DJ', 'DJI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(59, 'Dominica', 'DM', 'DMA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(60, 'Dominican Republic', 'DO', 'DOM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(61, 'East Timor', 'TP', 'TMP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(62, 'Ecuador', 'EC', 'ECU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(63, 'Egypt', 'EG', 'EGY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(64, 'El Salvador', 'SV', 'SLV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(65, 'Equatorial Guinea', 'GQ', 'GNQ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(66, 'Eritrea', 'ER', 'ERI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(67, 'Estonia', 'EE', 'EST', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(68, 'Ethiopia', 'ET', 'ETH', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(69, 'Falkland Islands (Malvinas)', 'FK', 'FLK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(70, 'Faroe Islands', 'FO', 'FRO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(71, 'Fiji', 'FJ', 'FJI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(72, 'Finland', 'FI', 'FIN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(73, 'France', 'FR', 'FRA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(75, 'French Guiana', 'GF', 'GUF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(76, 'French Polynesia', 'PF', 'PYF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(77, 'French Southern Territories', 'TF', 'ATF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(78, 'Gabon', 'GA', 'GAB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(79, 'Gambia', 'GM', 'GMB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(80, 'Georgia', 'GE', 'GEO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(81, 'Germany', 'DE', 'DEU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(82, 'Ghana', 'GH', 'GHA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(83, 'Gibraltar', 'GI', 'GIB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(84, 'Greece', 'GR', 'GRC', 0, 0, 1, 0, NULL, NULL, 42, 0),
(85, 'Greenland', 'GL', 'GRL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(86, 'Grenada', 'GD', 'GRD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(87, 'Guadeloupe', 'GP', 'GLP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(88, 'Guam', 'GU', 'GUM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(89, 'Guatemala', 'GT', 'GTM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(90, 'Guinea', 'GN', 'GIN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(91, 'Guinea-bissau', 'GW', 'GNB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(92, 'Guyana', 'GY', 'GUY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(93, 'Haiti', 'HT', 'HTI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(94, 'Heard and Mc Donald Islands', 'HM', 'HMD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(95, 'Honduras', 'HN', 'HND', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(96, 'Hong Kong', 'HK', 'HKG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(97, 'Hungary', 'HU', 'HUN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(98, 'Iceland', 'IS', 'ISL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(99, 'India', 'IN', 'IND', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(100, 'Indonesia', 'ID', 'IDN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(101, 'Iran (Islamic Republic of)', 'IR', 'IRN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(102, 'Iraq', 'IQ', 'IRQ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(103, 'Ireland', 'IE', 'IRL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(104, 'Israel', 'IL', 'ISR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(105, 'Italy', 'IT', 'ITA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(106, 'Jamaica', 'JM', 'JAM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(107, 'Japan', 'JP', 'JPN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(108, 'Jordan', 'JO', 'JOR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(109, 'Kazakhstan', 'KZ', 'KAZ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(110, 'Kenya', 'KE', 'KEN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(111, 'Kiribati', 'KI', 'KIR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(112, 'Korea, Democratic People\'s Republic of', 'KP', 'PRK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(113, 'Korea, Republic of', 'KR', 'KOR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(114, 'Kuwait', 'KW', 'KWT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(115, 'Kyrgyzstan', 'KG', 'KGZ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(116, 'Lao People\'s Democratic Republic', 'LA', 'LAO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(117, 'Latvia', 'LV', 'LVA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(118, 'Lebanon', 'LB', 'LBN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(119, 'Lesotho', 'LS', 'LSO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(120, 'Liberia', 'LR', 'LBR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(121, 'Libya', 'LY', 'LBY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(122, 'Liechtenstein', 'LI', 'LIE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(123, 'Lithuania', 'LT', 'LTU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(124, 'Luxembourg', 'LU', 'LUX', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(125, 'Macau', 'MO', 'MAC', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(126, 'Macedonia, The Former Yugoslav Republic of', 'MK', 'MKD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(127, 'Madagascar', 'MG', 'MDG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(128, 'Malawi', 'MW', 'MWI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(129, 'Malaysia', 'MY', 'MYS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(130, 'Maldives', 'MV', 'MDV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(131, 'Mali', 'ML', 'MLI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(132, 'Malta', 'MT', 'MLT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(133, 'Marshall Islands', 'MH', 'MHL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(134, 'Martinique', 'MQ', 'MTQ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(135, 'Mauritania', 'MR', 'MRT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(136, 'Mauritius', 'MU', 'MUS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(137, 'Mayotte', 'YT', 'MYT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(138, 'Mexico', 'MX', 'MEX', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(139, 'Micronesia, Federated States of', 'FM', 'FSM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(140, 'Moldova, Republic of', 'MD', 'MDA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(141, 'Monaco', 'MC', 'MCO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(142, 'Mongolia', 'MN', 'MNG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(143, 'Montserrat', 'MS', 'MSR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(144, 'Morocco', 'MA', 'MAR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(145, 'Mozambique', 'MZ', 'MOZ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(146, 'Myanmar', 'MM', 'MMR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(147, 'Namibia', 'NA', 'NAM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(148, 'Nauru', 'NR', 'NRU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(149, 'Nepal', 'NP', 'NPL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(150, 'Netherlands', 'NL', 'NLD', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(151, 'Netherlands Antilles', 'AN', 'ANT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(152, 'New Caledonia', 'NC', 'NCL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(153, 'New Zealand', 'NZ', 'NZL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(154, 'Nicaragua', 'NI', 'NIC', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(155, 'Niger', 'NE', 'NER', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(156, 'Nigeria', 'NG', 'NGA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(157, 'Niue', 'NU', 'NIU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(158, 'Norfolk Island', 'NF', 'NFK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(159, 'Northern Mariana Islands', 'MP', 'MNP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(160, 'Norway', 'NO', 'NOR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(161, 'Oman', 'OM', 'OMN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(162, 'Pakistan', 'PK', 'PAK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(163, 'Palau', 'PW', 'PLW', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(164, 'Panama', 'PA', 'PAN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(165, 'Papua New Guinea', 'PG', 'PNG', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(166, 'Paraguay', 'PY', 'PRY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(167, 'Peru', 'PE', 'PER', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(168, 'Philippines', 'PH', 'PHL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(169, 'Pitcairn', 'PN', 'PCN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(170, 'Poland', 'PL', 'POL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(171, 'Portugal', 'PT', 'PRT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(172, 'Puerto Rico', 'PR', 'PRI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(173, 'Qatar', 'QA', 'QAT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(174, 'Reunion', 'RE', 'REU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(175, 'Romania', 'RO', 'ROM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(176, 'Russian Federation', 'RU', 'RUS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(177, 'Rwanda', 'RW', 'RWA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(178, 'Saint Kitts and Nevis', 'KN', 'KNA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(179, 'Saint Lucia', 'LC', 'LCA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(180, 'Saint Vincent and the Grenadines', 'VC', 'VCT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(181, 'Samoa', 'WS', 'WSM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(182, 'San Marino', 'SM', 'SMR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(183, 'Sao Tome and Principe', 'ST', 'STP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(184, 'Saudi Arabia', 'SA', 'SAU', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(185, 'Senegal', 'SN', 'SEN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(186, 'Seychelles', 'SC', 'SYC', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(187, 'Sierra Leone', 'SL', 'SLE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(188, 'Singapore', 'SG', 'SGP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(189, 'Slovakia', 'SK', 'SVK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(190, 'Slovenia', 'SI', 'SVN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(191, 'Solomon Islands', 'SB', 'SLB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(192, 'Somalia', 'SO', 'SOM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(193, 'South Africa', 'ZA', 'ZAF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(194, 'South Georgia and the South Sandwich Islands', 'GS', 'SGS', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(195, 'Spain', 'ES', 'ESP', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(196, 'Sri Lanka', 'LK', 'LKA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(197, 'St. Helena', 'SH', 'SHN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(198, 'St. Pierre and Miquelon', 'PM', 'SPM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(199, 'Sudan', 'SD', 'SDN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(200, 'Suriname', 'SR', 'SUR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(201, 'Svalbard and Jan Mayen Islands', 'SJ', 'SJM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(202, 'Swaziland', 'SZ', 'SWZ', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(203, 'Sweden', 'SE', 'SWE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(204, 'Switzerland', 'CH', 'CHE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(205, 'Syrian Arab Republic', 'SY', 'SYR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(206, 'Taiwan', 'TW', 'TWN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(207, 'Tajikistan', 'TJ', 'TJK', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(208, 'Tanzania, United Republic of', 'TZ', 'TZA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(209, 'Thailand', 'TH', 'THA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(210, 'Togo', 'TG', 'TGO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(211, 'Tokelau', 'TK', 'TKL', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(212, 'Tonga', 'TO', 'TON', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(213, 'Trinidad and Tobago', 'TT', 'TTO', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(214, 'Tunisia', 'TN', 'TUN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(215, 'Turkey', 'TR', 'TUR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(216, 'Turkmenistan', 'TM', 'TKM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(217, 'Turks and Caicos Islands', 'TC', 'TCA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(218, 'Tuvalu', 'TV', 'TUV', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(219, 'Uganda', 'UG', 'UGA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(220, 'Ukraine', 'UA', 'UKR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(221, 'United Arab Emirates', 'AE', 'ARE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(222, 'United Kingdom', 'GB', 'GBR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(223, 'United States', 'US', 'USA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(224, 'United States Minor Outlying Islands', 'UM', 'UMI', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(225, 'Uruguay', 'UY', 'URY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(226, 'Uzbekistan', 'UZ', 'UZB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(227, 'Vanuatu', 'VU', 'VUT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(228, 'Vatican City State (Holy See)', 'VA', 'VAT', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(229, 'Venezuela', 'VE', 'VEN', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(230, 'Viet Nam', 'VN', 'VNM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(231, 'Virgin Islands (British)', 'VG', 'VGB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(232, 'Virgin Islands (U.S.)', 'VI', 'VIR', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(233, 'Wallis and Futuna Islands', 'WF', 'WLF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(234, 'Western Sahara', 'EH', 'ESH', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(235, 'Yemen', 'YE', 'YEM', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(237, 'The Democratic Republic of Congo', 'DC', 'DRC', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(238, 'Zambia', 'ZM', 'ZMB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(239, 'Zimbabwe', 'ZW', 'ZWE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(240, 'East Timor', 'XE', 'XET', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(241, 'Jersey', 'JE', 'JEY', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(242, 'St. Barthelemy', 'XB', 'XSB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(243, 'St. Eustatius', 'XU', 'XSE', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(244, 'Canary Islands', 'XC', 'XCA', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(245, 'Serbia', 'RS', 'SRB', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(246, 'Sint Maarten (French Antilles)', 'MF', 'MAF', 0, 0, 0, 0, NULL, '0000-00-00 00:00:00', 42, 0),
(247, 'Sint Maarten (Netherlands Antilles)', 'SX', 'SXM', 0, 0, 0, 0, NULL, NULL, 42, 923),
(248, 'Palestinian Territory, occupied', 'PS', 'PSE', 0, 0, 0, 0, NULL, NULL, 42, 923),
(249, 'Larisa', 'ZZ', 'LAR', 121, 84, 1, 1, NULL, NULL, 923, 923);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `test` text DEFAULT NULL,
  `bka-bla` decimal(15,5) DEFAULT NULL,
  `aaa` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `#__alfa_items_price_index`
--
ALTER TABLE `#__alfa_items_price_index`
  ADD CONSTRAINT `fk_priceidx_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;