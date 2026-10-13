--
-- THIS FILE RUNS AGAIN OVER KEPT TABLES. Uninstalling Alfa Commerce keeps every
-- Alfa table and its rows, and installing it again runs this file over them. So
-- every table is CREATE TABLE IF NOT EXISTS with its keys and foreign keys inside
-- it (no ALTER TABLE), and every seed writes only into an EMPTY table: a table
-- that holds rows, the merchant edited or not, is left exactly as it is.
-- It is not a migration: script.php preflight lets it run over kept tables only
-- when their recorded schema version is the one this file creates.
--

--
-- Alfa Commerce facts about its own database, by name. schema_version is the
-- schema the Alfa tables are at: written first, so a run of this file that
-- stopped part-way can be run again; kept on uninstall like every Alfa table;
-- read by the installer before it changes anything, and raised by an update.
-- The value is script.php SCHEMA_VERSION, the newest sql/updates/mysql file.
--

CREATE TABLE IF NOT EXISTS `#__alfa_meta` (
  `name` varchar(64) NOT NULL COMMENT 'The fact, e.g. schema_version',
  `value` text NOT NULL COMMENT 'Its value, e.g. 2.0.0, the download store path, or the store protection result as JSON',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alfa Commerce facts it keeps beside its data: the schema version, the download store path and its protection';

INSERT INTO `#__alfa_meta` (`name`, `value`)
SELECT 'schema_version', '2.0.1' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_meta` WHERE `name` = 'schema_version');

--
-- Table structure for table `#__alfa_cart`
--
-- TEMPORARY OPERATIONAL STATE, never an archive. A cart that becomes an order
-- is DELETED inside the placement transaction (the placement discards its
-- source cart before commit), so no converted cart survives and there
-- is no cart -> order relationship: the order is fully self-contained.
-- active | abandoned | empty are QUERIES over `updated` plus line existence
-- (CartsModel), never a stored status -- a cart the shopper opens again stops
-- being abandoned by itself, with nothing to write.
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_payment` int(11) NOT NULL DEFAULT 0 COMMENT 'Chosen payment method; 0 = none chosen yet',
  `id_shipment` int(11) NOT NULL DEFAULT 0 COMMENT 'Chosen shipment method; 0 = none chosen yet',
  `id_user_info_delivery` int(11) NOT NULL,
  `id_user_info_invoice` int(11) NOT NULL,
  `id_currency` int(11) NOT NULL,
  `id_customer` int(11) NOT NULL COMMENT '0 = guest; the browser owns the cart through recognize_key',
  `added` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `recognize_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'sha256 hex from the browser cookie -- identifies the BROWSER that owns a guest cart, not the cart',
  `source` varchar(16) NOT NULL DEFAULT 'storefront' COMMENT 'Who created the cart: storefront (a shopper) | admin (the order builder). Written once at creation, never from a request; changing or placing a storefront cart also needs Carts: Edit',
  PRIMARY KEY (`id`),
  -- THE ABANDONMENT CLOCK. Every question the admin asks this table is asked of
  -- `updated`: the default ordering, the date filter, both abandonment cutoffs
  -- and all three summary tiles (CartsModel). `added` carries no predicate
  -- anywhere in the tree and is deliberately NOT indexed.
  KEY `idx_updated` (`updated`),
  -- Every cart load resolves the shopper: a visitor by the browser's key, a
  -- member by their id. Nothing prunes abandoned carts, so without these the
  -- lookup scans the whole table on every page that shows the mini-cart.
  KEY `idx_recognize_key` (`recognize_key`),
  KEY `idx_customer` (`id_customer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Live shopping carts -- operational state only; deleted at placement, never an archive';

--
-- Table structure for table `#__alfa_cart_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cart` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `id_combination` int(11) UNSIGNED DEFAULT NULL COMMENT 'FK #__alfa_item_combinations.id: resolved variant for this line',
  `quantity` int(10) UNSIGNED NOT NULL COMMENT 'Whole units: every reader casts to int, and the order line this freezes into is int(10) UNSIGNED',
  `custom_unit_price_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'Staff-negotiated unit price, tax incl. NULL = no override (canonical pricing). 0 = an explicit free price, never a sentinel. Written only on an admin-created cart, only with Carts: Set custom price. The override state IS this column and nothing else: never inferred by comparing money against the catalogue.',
  PRIMARY KEY (`id`),
  KEY `idx_id_combination` (`id_combination`),
  -- The line's own cart FIRST, then the item. Every read of a cart's contents
  -- drives from `id_cart` (left prefix, so those are unaffected), and the two
  -- probes that run on every add-to-cart, findCartLine() and
  -- poolQuantityInCart(), narrow by `id_item` in the same dive instead of
  -- scanning the cart's lines.
  KEY `idx_cart_item` (`id_cart`, `id_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Cart lines (live), removed together with their cart when it is cleared or placed';

--
-- Table structure for table `#__alfa_cart_item_fields`
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart_item_fields` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cart_item` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_cart_items.id',
  `id_item_field` int(11) UNSIGNED DEFAULT NULL COMMENT 'FK #__alfa_item_fields.id',
  `id_item_field_value` int(11) UNSIGNED DEFAULT NULL COMMENT 'FK #__alfa_item_field_values.id: the chosen value',
  `payload` longtext DEFAULT NULL COMMENT 'Opaque plugin config (configurator); NULL for plain selections',
  PRIMARY KEY (`id`),
  KEY `idx_id_cart_item` (`id_cart_item`),
  KEY `idx_id_item_field` (`id_item_field`),
  KEY `idx_id_item_field_value` (`id_item_field_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart line field selections (live)';

--
-- Table structure for table `#__alfa_cart_coupons`
--
-- Codes the shopper has ENTERED on a live cart. OPERATIONAL STATE, never a
-- record: no amount, no rule id, no name, no outcome, no timestamp.
-- CartRulesEngine re-derives every one of those on each read (state, publish
-- window, targeting, usage), and the authoritative freeze -- the one that
-- carries money -- is #__alfa_order_cart_rule.
--
-- Keyed on the CODE, not on id_cart_rule: a rule the merchant deletes or
-- renames then owes no cascade here, the code simply stops resolving and the
-- engine reports it as not found. Codes were session-only before this table,
-- so a shopper's cart outlived their discount.
--
CREATE TABLE IF NOT EXISTS `#__alfa_cart_coupons` (
  `id_cart` int(11) NOT NULL COMMENT 'FK #__alfa_cart.id; the code dies with the cart',
  `code` varchar(64) NOT NULL COMMENT 'The code as entered, uppercased by applyCouponCode(); matches #__alfa_cart_rules.code',
  -- (id_cart, code) IS the identity: every access is cart-scoped; one read per
  -- cart render, one insert per accepted code, one delete on remove or clear.
  -- Clustering on it makes the render read a covering range scan with no
  -- secondary lookup, and gives the database the duplicate guard
  -- applyCouponCode() used to do in PHP. There is no surrogate id: the row has
  -- no identity of its own.
  PRIMARY KEY (`id_cart`, `code`),
  -- The ONLY cascade in the cart tree. #__alfa_cart_items and
  -- #__alfa_cart_item_fields stay hand-cascaded by the cart writers, inside the same
  -- transaction as the line delete. No ON UPDATE clause: cart ids are never
  -- rewritten, and declaring one would only make renumbering them look safe.
  CONSTRAINT `fk_cart_coupons_cart` FOREIGN KEY (`id_cart`)
    REFERENCES `#__alfa_cart` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Coupon codes held on a live cart (operational state)';

--
-- Table structure for table `#__alfa_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT 0,
  -- Translatable fields (name, desc, alias, meta_title, meta_desc) live ONLY in
  -- the per-language tables #__alfa_categories_<langtag>. No main-table copy.
  `meta_data` text NOT NULL DEFAULT ('{}'),
  `layouts` text NOT NULL DEFAULT ('{}') COMMENT 'Layout overrides JSON {layout,card,item,images,manufacturers,categories}; empty key = inherit menu/global',
  `list_options` text NOT NULL DEFAULT ('{}') COMMENT 'Listing defaults JSON {items_limit,items_ordering}; empty key = inherit menu/global',
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

--
-- Table structure for table `#__alfa_categories_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories_usergroups` (
  `category_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`category_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_categories_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_categories_users` (
  `category_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`category_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_currencies`
--

CREATE TABLE IF NOT EXISTS `#__alfa_currencies` (
  `id` int(1) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  `code` char(3) DEFAULT NULL,
  `number` int(4) DEFAULT NULL,
  `symbol` varchar(8) DEFAULT NULL,
  -- Alfa's authoritative monetary scale for this currency: every rounding,
  -- stored amount, formatted figure and gateway charge derives from it.
  -- The merchant owns the value (ISO 4217 is not enforced) and the
  -- supported range is 0..8, which is what the decimal(24,8) money columns
  -- can carry. Typed as a number so the column cannot hold a non-scale.
  `decimal_place` tinyint(1) UNSIGNED NOT NULL DEFAULT 2,
  `decimal_separator` varchar(10) NOT NULL,
  `thousand_separator` varchar(10) NOT NULL,
  -- ONE pattern renders both signs; do not add a negative counterpart.
  -- Currency::format() builds the amount with number_format(), which puts the
  -- minus INSIDE {number} ("-1.234,50"), so the same pattern serves positive
  -- and negative and the two can never drift apart. Tokens: {number}, {symbol}.
  -- There is no {sign} token (a concept inherited from another shop system, never implemented).
  `format_pattern` varchar(30) NOT NULL DEFAULT '{number}{symbol}',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to store currencies';

--
-- Dumping data for table `#__alfa_currencies`
--

INSERT INTO `#__alfa_currencies` (`id`, `name`, `code`, `number`, `symbol`, `decimal_place`, `decimal_separator`, `thousand_separator`, `format_pattern`, `ordering`, `state`, `created_by`, `modified_by`, `checked_out_time`, `checked_out`)
SELECT * FROM (
            SELECT 2 AS `id`, 'United Arab Emirates dirham' AS `name`, 'AED' AS `code`, 784 AS `number`, 'د.إ' AS `symbol`, 2 AS `decimal_place`, ',' AS `decimal_separator`, '.' AS `thousand_separator`, '{number} {symbol}' AS `format_pattern`, 0 AS `ordering`, 1 AS `state`, 0 AS `created_by`, 922 AS `modified_by`, NULL AS `checked_out_time`, NULL AS `checked_out`
  UNION ALL SELECT 4, 'Albanian lek', 'ALL', 8, 'Lek', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 7, 'Argentine peso', 'ARS', 32, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 9, 'Australian dollar', 'AUD', 36, '$', 2, ',', '.', '{symbol} {number}', 0, 1, 0, 923, NULL, NULL
  UNION ALL SELECT 10, 'Aruban florin', 'AWG', 533, 'ƒ', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 11, 'Barbadian dollar', 'BBD', 52, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 922, NULL, NULL
  UNION ALL SELECT 12, 'Bangladeshi taka', 'BDT', 50, '৳', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 15, 'Bahraini dinar', 'BHD', 48, 'ب.د', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 16, 'Burundian franc', 'BIF', 108, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 17, 'Bermudian dollar', 'BMD', 60, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 18, 'Brunei dollar', 'BND', 96, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 19, 'Bolivian boliviano', 'BOB', 68, '$b', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 20, 'Brazilian real', 'BRL', 986, 'R$', 2, ',', '.', '{symbol} {number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 21, 'Bahamian dollar', 'BSD', 44, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 22, 'Bhutanese ngultrum', 'BTN', 64, 'BTN', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 24, 'Botswana pula', 'BWP', 72, 'P', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 25, 'Belize dollar', 'BZD', 84, 'BZ$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 26, 'Canadian dollar', 'CAD', 124, '$', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 27, 'Swiss franc', 'CHF', 756, 'CHF', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 28, 'Unidad de Fomento', 'CLF', 990, 'CLF', 4, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 29, 'Chilean peso', 'CLP', 152, '$', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 30, 'Chinese renminbi yuan', 'CNY', 156, '元', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 31, 'Colombian peso', 'COP', 170, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 32, 'Costa Rican colón', 'CRC', 188, '₡', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 33, 'Czech koruna', 'CZK', 203, 'Kč', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 34, 'Cuban peso', 'CUP', 192, '₱', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 35, 'Cape Verdean escudo', 'CVE', 132, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 40, 'Danish krone', 'DKK', 208, 'kr', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 41, 'Dominican peso', 'DOP', 214, 'RD$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 42, 'Algerian dinar', 'DZD', 12, 'د.ج', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 44, 'Egyptian pound', 'EGP', 818, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 46, 'Ethiopian birr', 'ETB', 230, 'ETB', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 47, 'Euro', 'EUR', 978, '€', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 922, NULL, NULL
  UNION ALL SELECT 49, 'Fijian dollar', 'FJD', 242, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 50, 'Falkland pound', 'FKP', 238, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 52, 'British pound', 'GBP', 826, '£', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 54, 'Gibraltar pound', 'GIP', 292, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 55, 'Gambian dalasi', 'GMD', 270, 'D', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 56, 'Guinean franc', 'GNF', 324, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 58, 'Guatemalan quetzal', 'GTQ', 320, 'Q', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 60, 'Guyanese dollar', 'GYD', 328, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 61, 'Hong Kong dollar', 'HKD', 344, '元', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 62, 'Honduran lempira', 'HNL', 340, 'L', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 63, 'Haitian gourde', 'HTG', 332, 'G', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 64, 'Hungarian forint', 'HUF', 348, 'Ft', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 65, 'Indonesian rupiah', 'IDR', 360, 'Rp', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 67, 'Israeli new sheqel', 'ILS', 376, '₪', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 68, 'Indian rupee', 'INR', 356, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 69, 'Iraqi dinar', 'IQD', 368, 'ع.د', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 70, 'Iranian rial', 'IRR', 364, '﷼', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 73, 'Jamaican dollar', 'JMD', 388, 'J$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 74, 'Jordanian dinar', 'JOD', 400, 'د.ا', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 75, 'Japanese yen', 'JPY', 392, '¥', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 76, 'Kenyan shilling', 'KES', 404, 'Sh', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 77, 'Cambodian riel', 'KHR', 116, '៛', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 78, 'Comorian franc', 'KMF', 174, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 79, 'North Korean won', 'KPW', 408, '₩', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 80, 'South Korean won', 'KRW', 410, '₩', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 81, 'Kuwaiti dinar', 'KWD', 414, 'د.ك', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 82, 'Cayman Islands dollar', 'KYD', 136, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 83, 'Lao kip', 'LAK', 418, '₭', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 84, 'Lebanese pound', 'LBP', 422, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 85, 'Sri Lankan rupee', 'LKR', 144, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 86, 'Liberian dollar', 'LRD', 430, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 87, 'Lesotho loti', 'LSL', 426, 'L', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 89, 'Libyan dinar', 'LYD', 434, 'ل.د', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 90, 'Moroccan dirham', 'MAD', 504, 'د.م.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 92, 'Mongolian tögrög', 'MNT', 496, '₮', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 93, 'Macanese pataca', 'MOP', 446, 'P', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 96, 'Mauritian rupee', 'MUR', 480, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 97, 'Maldivian rufiyaa', 'MVR', 462, 'ރ.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 98, 'Malawian kwacha', 'MWK', 454, 'MK', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 100, 'Malaysian ringgit', 'MYR', 458, 'RM', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 102, 'Nigerian naira', 'NGN', 566, '₦', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 105, 'Norwegian krone', 'NOK', 578, 'kr', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 106, 'Nepalese rupee', 'NPR', 524, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 107, 'New Zealand dollar', 'NZD', 554, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 108, 'Omani rial', 'OMR', 512, '﷼', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 109, 'Panamanian balboa', 'PAB', 590, 'B/.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 110, 'Peruvian nuevo sol', 'PEN', 604, 'S/.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 111, 'Papua New Guinean kina', 'PGK', 598, 'K', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 112, 'Philippine peso', 'PHP', 608, '₱', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 113, 'Pakistani rupee', 'PKR', 586, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 114, 'Polish Złoty', 'PLN', 985, 'zł', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 116, 'Paraguayan guaraní', 'PYG', 600, '₲', 0, ',', '.', '{symbol} {number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 117, 'Qatari riyal', 'QAR', 634, '﷼', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 118, 'Romanian leu', 'RON', 946, 'lei', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 119, 'Rwandan franc', 'RWF', 646, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 120, 'Saudi riyal', 'SAR', 682, '﷼', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 121, 'Solomon Islands dollar', 'SBD', 90, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 122, 'Seychellois rupee', 'SCR', 690, '₨', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 124, 'Swedish krona', 'SEK', 752, 'kr', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 125, 'Singapore dollar', 'SGD', 702, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 126, 'Saint Helenian pound', 'SHP', 654, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 128, 'Somali shilling', 'SOS', 706, 'S', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 131, 'Russian ruble', 'RUB', 643, 'руб', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 132, 'Salvadoran colón', 'SVC', 222, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 133, 'Syrian pound', 'SYP', 760, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 134, 'Swazi lilangeni', 'SZL', 748, 'L', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 135, 'Thai baht', 'THB', 764, '฿', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 136, 'Tunisian dinar', 'TND', 788, 'د.ت', 3, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 137, 'Tongan paʻanga', 'TOP', 776, 'T$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 139, 'Türk Lirası', 'TRY', 949, 'TL', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 140, 'Trinidad and Tobago dollar', 'TTD', 780, 'TT$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 141, 'New Taiwan dollar', 'TWD', 901, 'NT$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 142, 'Tanzanian shilling', 'TZS', 834, 'Sh', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 144, 'United States dollar', 'USD', 840, '$', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 147, 'Vietnamese Dong', 'VND', 704, '₫', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 148, 'Vanuatu vatu', 'VUV', 548, 'Vt', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 149, 'Samoan tala', 'WST', 882, 'T', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 151, 'Yemeni rial', 'YER', 886, '﷼', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 152, 'Serbian dinar', 'RSD', 941, 'Дин.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 153, 'South African rand', 'ZAR', 710, 'R', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 158, 'Armenian dram', 'AMD', 51, 'դր.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 159, 'Myanmar kyat', 'MMK', 104, 'K', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 161, 'Eritrean nakfa', 'ERN', 232, 'Nfk', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 162, 'Djiboutian franc', 'DJF', 262, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 163, 'Icelandic króna', 'ISK', 352, 'kr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 164, 'Kazakhstani tenge', 'KZT', 398, 'лв', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 165, 'Kyrgyzstani som', 'KGS', 417, 'лв', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 168, 'Mexican peso', 'MXN', 484, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 169, 'Moldovan leu', 'MDL', 498, 'L', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 170, 'Namibian dollar', 'NAD', 516, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 171, 'Nicaraguan córdoba', 'NIO', 558, 'C$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 172, 'Ugandan shilling', 'UGX', 800, 'Sh', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 173, 'Macedonian denar', 'MKD', 807, 'ден', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 174, 'Uruguayan peso', 'UYU', 858, '$', 2, ',', '.', '{symbol}{number}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 175, 'Uzbekistani som', 'UZS', 860, 'лв', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 176, 'Azerbaijani manat', 'AZN', 944, 'ман', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 177, 'Ghanaian cedi', 'GHS', 936, '₵', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 179, 'Sudanese pound', 'SDG', 938, '£', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 180, 'Uruguay Peso', 'UYI', 940, 'UYI', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 181, 'Mozambican metical', 'MZN', 943, 'MT', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 182, 'WIR Euro', 'CHE', 947, '€', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 183, 'WIR Franc', 'CHW', 948, 'CHW', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 184, 'Central African CFA franc', 'XAF', 950, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 185, 'East Caribbean dollar', 'XCD', 951, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 186, 'West African CFA franc', 'XOF', 952, 'Fr', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 187, 'CFP franc', 'XPF', 953, 'F', 0, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 188, 'Surinamese dollar', 'SRD', 968, '$', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 189, 'Malagasy ariary', 'MGA', 969, 'MGA', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 190, 'Unidad de Valor Real', 'COU', 970, 'COU', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 191, 'Afghan afghani', 'AFN', 971, '؋', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 192, 'Tajikistani somoni', 'TJS', 972, 'ЅМ', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 193, 'Angolan kwanza', 'AOA', 973, 'Kz', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 195, 'Bulgarian lev', 'BGN', 975, 'лв', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 196, 'Congolese franc', 'CDF', 976, 'Fr', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 197, 'Bosnia and Herzegovina convert', 'BAM', 977, 'KM', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 198, 'Mexican Unid', 'MXV', 979, 'MXV', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 199, 'Ukrainian hryvnia', 'UAH', 980, '₴', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 200, 'Georgian lari', 'GEL', 981, 'ლ', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 201, 'Mvdol', 'BOV', 984, 'BOV', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 202, 'Mauritanian ouguiya', 'MRU', 929, 'UM', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 203, 'Sierra Leonean leone', 'SLE', 925, 'Le', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 204, 'Zambian kwacha', 'ZMW', 967, 'ZK', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 205, 'Venezuelan bolívar soberano', 'VES', 928, 'Bs.', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 206, 'Venezuelan bolívar digital', 'VED', 926, 'Bs.D', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 207, 'Belarusian ruble', 'BYN', 933, 'Br', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 208, 'South Sudanese pound', 'SSP', 728, 'SSP', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 209, 'São Tomé and Príncipe dobra', 'STN', 930, 'Db', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 210, 'Turkmenistan manat', 'TMT', 934, 'm', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 211, 'Unidad previsional', 'UYW', 927, 'UP', 4, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 212, 'Zimbabwe Gold', 'ZWG', 924, 'ZiG', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
  UNION ALL SELECT 213, 'Caribbean guilder', 'XCG', 532, 'Cg', 2, ',', '.', '{number} {symbol}', 0, 1, 0, 0, NULL, NULL
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_currencies`);

--
-- Table structure for table `#__alfa_discounts`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discounts` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Translatable field (name) lives ONLY in the per-language tables
  -- #__alfa_discounts_<langtag>. No main-table copy. (`desc` is unused/legacy.)
  `desc` text DEFAULT NULL,
  `value` decimal(24,8) NOT NULL COMMENT 'Percent for percent, currency amount for amount',
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
  `tag_list_show` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Show the discount name as a badge on catalogue cards',
  `tag_list_slot` varchar(50) NOT NULL DEFAULT 'image' COMMENT 'ItemListSurface slot for the card badge',
  `tag_item_show` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Show the discount name as a badge on the product page',
  `tag_item_slot` varchar(50) NOT NULL DEFAULT 'image' COMMENT 'ItemSurface slot for the product-page badge',
  `tag_bg` varchar(30) NOT NULL DEFAULT '' COMMENT 'Badge background colour (CSS colour); empty = layout default',
  `tag_color` varchar(30) NOT NULL DEFAULT '' COMMENT 'Badge text colour (CSS colour); empty = layout default',
  `tag_class` varchar(190) NOT NULL DEFAULT '' COMMENT 'Extra CSS class(es) appended to the badge element',
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_discount_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_categories` (
  `discount_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_discount_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_manufacturers` (
  `discount_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_id`, `manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_discount_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_places` (
  `discount_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_id`, `place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_discount_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_usergroups` (
  `discount_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_discount_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_discount_users` (
  `discount_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_form_field_groups`
--

-- Translatable title/description live in #__alfa_form_field_groups_<langtag>.
CREATE TABLE IF NOT EXISTS `#__alfa_form_field_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `state` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `modified_by` int(11) DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_form_fields`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL DEFAULT 0,
  `type` text DEFAULT NULL,
  `params` text DEFAULT NULL,
  -- Translatable fields (name, field_label, field_description) live ONLY in
  -- the per-language tables #__alfa_form_fields_<langtag>.
  -- field_name is the machine key (NOT translatable) and stays here.
  `field_name` varchar(255) NOT NULL,
  -- IDENTITY ROLE. The semantic meaning of a field, so consumers (emails,
  -- documents, gateways, the orders list) ask "which column is the email"
  -- through RoleRegistry instead of guessing by field_name. One row per role.
  -- name | email = the customer's identity (system rows, always present);
  -- phone = the customer's phone (a default row the merchant may drop).
  `role` varchar(32) DEFAULT NULL,
  -- SYSTEM ROW. The product owns the definition (type, column, role; never
  -- deleted, never unpublished); the merchant owns position, group, label and
  -- the per-section visibility below its floor.
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  -- WHERE THE FIELD SHOWS, per section: 0 = hidden, 1 = optional, 2 = required.
  --   registration -> the registration form
  --   shipping     -> the checkout DELIVERY section ("Customer details")
  --   billing      -> the checkout INVOICE section ("different details")
  -- Required is a property of the section, not of the field: a phone can be
  -- required at checkout and optional at registration.
  `registration` tinyint(4) NOT NULL DEFAULT 0,
  `billing` tinyint(4) NOT NULL DEFAULT 0,
  `shipping` tinyint(4) NOT NULL DEFAULT 0,
  `unique` tinyint(1) DEFAULT 0,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  `state` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_name` (`field_name`),
  UNIQUE KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The identity fields every store needs. `name` and `email` are SYSTEM rows:
-- a guest order with no email cannot be confirmed, invoiced or served its
-- downloads, so they are always present and always required at registration
-- and in the delivery section (members never see the email field; the
-- account's address is used). `phone` and `country` are DEFAULT rows: shipped
-- so a fresh store captures what couriers, gateways and shipping zones need,
-- but the merchant may unpublish, regroup or delete them like any own field.
-- Their #__alfa_user_info columns are part of that table's CREATE below.
INSERT INTO `#__alfa_form_fields` (`id`, `group_id`, `type`, `params`, `field_name`, `role`, `is_system`, `registration`, `billing`, `shipping`, `unique`, `ordering`, `created_by`, `state`)
SELECT * FROM (
            SELECT 1 AS `id`, 0 AS `group_id`, 'text' AS `type`, '{"filter":"string","maxlength":"400","validation":"","sql_type":"varchar(400)"}' AS `params`, 'name' AS `field_name`, 'name' AS `role`, 1 AS `is_system`, 2 AS `registration`, 2 AS `billing`, 2 AS `shipping`, 0 AS `unique`, 1 AS `ordering`, 0 AS `created_by`, 1 AS `state`
  UNION ALL SELECT 2, 0, 'text', '{"filter":"string","maxlength":"254","validation":"email","sql_type":"varchar(254)"}', 'email', 'email', 1, 2, 0, 2, 0, 2, 0, 1
  UNION ALL SELECT 3, 0, 'tel', '{"allowed_regions":"","require_mobile":"0","sql_type":"varchar(20)"}', 'phone', 'phone', 0, 0, 1, 1, 0, 3, 0, 1
  UNION ALL SELECT 4, 0, 'country', '{"allowed_places":"","default_place":"","sql_type":"int(11)"}', 'country', 'country', 0, 0, 2, 2, 0, 4, 0, 1
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_form_fields`);

-- The en-GB labels for the seeded fields. The per-language sibling tables are
-- normally created by the multilingual system (utf8mb4_unicode_ci, description
-- columns LONGTEXT); en-GB is pre-created here with the same shape so the seed
-- ships with readable labels out of the box. Other content languages fall back
-- to the COM_ALFA_SYSTEM_FIELD_<ROLE> language strings until translated.
CREATE TABLE IF NOT EXISTS `#__alfa_form_fields_en_gb` (
  `id_formfield` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `field_label` varchar(255) NOT NULL DEFAULT '',
  `field_description` longtext DEFAULT NULL,
  PRIMARY KEY (`id_formfield`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__alfa_form_fields_en_gb` (`id_formfield`, `name`, `field_label`, `field_description`)
SELECT * FROM (
            SELECT 1 AS `id_formfield`, 'Full name' AS `name`, 'Full name' AS `field_label`, NULL AS `field_description`
  UNION ALL SELECT 2, 'Email', 'Email', NULL
  UNION ALL SELECT 3, 'Phone', 'Phone', NULL
  UNION ALL SELECT 4, 'Country', 'Country', NULL
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_form_fields_en_gb`);

--
-- Table structure for table `#__alfa_form_fields_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields_usergroups` (
  `field_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`field_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_form_fields_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_fields_users` (
  `field_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`field_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_inventory_movements`
--
-- Inventory movement ledger, append-only, single-sided: one location's typed
-- signed delta per row. Quantity is a consequence of movements; a stock write
-- without its movement is forbidden. Corrections are opposite entries, never edits.
--

--
-- A STOCK EFFECT THAT A COMMITTED FACT REQUIRES IS ITSELF A DURABLE FACT.
--
-- The ledger is the authority for stock, and a document commits atomically,
-- but the obligation to move stock used to live only in process memory between
-- that commit and the call that applied it. A crash in that window lost the
-- movement permanently: nothing retried it, and no surface reported it.
--
-- The obligation is therefore written in the SAME transaction as the fact that
-- requires it, and is not discharged until the movement exists. Nothing outside
-- that transaction is ever the only record of it.
--
-- UNIQUE(source_type, source_id) is the idempotency guard: one business fact
-- owes at most one stock effect, so a replayed producer cannot enqueue twice.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_effect_outbox` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_type` varchar(32) NOT NULL COMMENT 'The producing domain: document, and whatever else later owes stock',
  `source_id` int(11) UNSIGNED NOT NULL COMMENT 'The committed business row that owes the effect',
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The order the effect resolves against',
  `payload` varchar(255) NOT NULL DEFAULT '' COMMENT 'What the producer needs to apply it (doc_type for a document)',
  `status` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | claimed | applied | failed | refused: applied is terminal; refused waits for the operator',
  `attempts` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Incremented on every claim, so a poison row is visible',
  `claim_token` varchar(64) DEFAULT NULL COMMENT 'Set by the worker that owns this row; only that worker may settle it',
  `claimed_at` datetime DEFAULT NULL COMMENT 'When the claim was taken; a claim older than the stale window is reclaimable (the worker died)',
  `last_error` varchar(500) NOT NULL DEFAULT '' COMMENT 'Why the last attempt failed: observability, not control flow',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_status_claimed` (`status`,`claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Durable stock-effect obligations, written with the fact that requires them, drained until the movement exists';

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_movements` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = item-level unit',
  `location_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `qty_change` float NOT NULL COMMENT 'Signed delta, same float semantics as stock',
  `movement_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'receive | deduct | restore | adjust | transfer_out | transfer_in | migration',
  `source_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'Command source domain (order, document, manual, import, migration, catalog_edit, inventory_repair, location, stock_writeoff, ...)',
  `source_id` int(11) UNSIGNED DEFAULT NULL,
  `source_line_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Source line: per-line cumulative deduction ledger anchor',
  `counterparty_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'Conceptual other side (supplier, customer, location), optional',
  `counterparty_id` int(11) UNSIGNED DEFAULT NULL,
  `transfer_id` int(11) UNSIGNED DEFAULT NULL,
  `operation_id` varchar(40) NOT NULL DEFAULT '' COMMENT 'Domain identity of the writing command',
  `correlation_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'External chain thread passed in by callers',
  `note` varchar(255) DEFAULT '',
  `created_by` int(11) DEFAULT 0,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_combination_id` (`combination_id`),
  KEY `idx_location` (`location_id`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_source_line` (`source_line_id`),
  KEY `idx_transfer` (`transfer_id`),
  KEY `idx_created` (`created`),
  -- Serves the admin movements list filtered by type: InventorymovementsModel
  -- issues `WHERE movement_type = ? ORDER BY id DESC LIMIT n`. Unindexed, that
  -- walked the whole ledger for a rare type: measured at 43,808 rows read to
  -- return 6 (r_filtered 0.014%, ~14ms); with this it is a ref lookup reading
  -- 6 (0.05ms). Deliberately ONE column: InnoDB appends the primary key to a
  -- secondary index, so (movement_type) already orders by id within a type and
  -- satisfies the ORDER BY without a filesort. A (movement_type, id) composite
  -- was measured alongside it and performed identically, so it would only cost
  -- space on an append-only table.
  KEY `idx_movement_type` (`movement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inventory ledger: every quantity change is an appended movement';

--
-- Inventory locations. location_type + capabilities are the semantic surface;
-- `kind` is a presentation preset with no engine meaning. Ownership is
-- polymorphic (owner_type/owner_id), unassigned until a domain claims it.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_locations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT 'Internal admin label (display names become multilingual with the UI phase)',
  `location_type` varchar(10) NOT NULL DEFAULT 'physical' COMMENT 'physical | virtual: the only semantic type axis',
  `capabilities` varchar(100) NOT NULL DEFAULT 'storage' COMMENT 'Comma set: storage | fulfillment | sourcing; behaviour asks capabilities, never kinds',
  `kind` varchar(30) NOT NULL DEFAULT '' COMMENT 'Presentation preset label (warehouse, store, ...); zero engine meaning',
  `owner_type` varchar(30) DEFAULT NULL COMMENT 'Polymorphic owner domain; NULL = shop-owned',
  `owner_id` int(11) UNSIGNED DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Exactly one default location always exists',
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `params` text DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state_ordering` (`state`, `ordering`),
  KEY `idx_owner` (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inventory locations: always-on engine, one default seeded';

INSERT INTO `#__alfa_inventory_locations` (`title`, `location_type`, `capabilities`, `kind`, `is_default`, `state`, `ordering`, `created`)
SELECT * FROM (
            SELECT 'Default location' AS `title`, 'physical' AS `location_type`, 'storage,fulfillment' AS `capabilities`, 'warehouse' AS `kind`, 1 AS `is_default`, 1 AS `state`, 1 AS `ordering`, NOW() AS `created`
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_inventory_locations`);

--
-- Authoritative per-location stock. combination_id 0 = item-level unit
-- (sentinel instead of NULL so the UNIQUE key enforces uniqueness).
-- items.stock / item_combinations.stock are projections of these rows.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_stock` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = item-level unit; >0 = the combination tracks its own stock',
  `location_id` int(11) UNSIGNED NOT NULL,
  `quantity` float NOT NULL DEFAULT 0 COMMENT 'Physical quantity at this location',
  `reserved_quantity` float NOT NULL DEFAULT 0 COMMENT 'Projection of ACTIVE reservation remainders, service-maintained, never authoritative',
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_unit` (`item_id`, `combination_id`, `location_id`),
  KEY `idx_location` (`location_id`),
  KEY `idx_combination` (`combination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Authoritative per-location stock; items.stock is a projection of this';

--
-- Stock reservations: stateful entity, NOT movements. PARTIAL is derived
-- (active + fulfilled_quantity > 0 + remainder > 0), never stored.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_reservations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `location_id` int(11) UNSIGNED NOT NULL,
  `quantity` float NOT NULL COMMENT 'Reserved quantity at creation',
  `fulfilled_quantity` float NOT NULL DEFAULT 0 COMMENT 'Converted to physical deduction so far',
  `released_quantity` float NOT NULL DEFAULT 0 COMMENT 'Let go without deduction so far',
  `state` varchar(10) NOT NULL DEFAULT 'active' COMMENT 'active | fulfilled | released | expired | cancelled',
  `source_type` varchar(30) NOT NULL COMMENT 'Creating domain: order | manual | package-defined sources',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `source_line_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Source line (e.g. order line): the fulfilment matching anchor',
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = lifecycle-governed (no time expiry)',
  `priority` int(11) NOT NULL DEFAULT 0 COMMENT 'Order of claims on its stock record: higher first, then older, then lower id; 0 at creation, raised only by an administrator serving its source first',
  `operation_id` varchar(40) NOT NULL DEFAULT '' COMMENT 'Domain identity of the creating command',
  `correlation_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'External chain thread passed in by callers',
  `note` varchar(255) NOT NULL DEFAULT '',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_unit` (`item_id`, `combination_id`, `location_id`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_source_line` (`source_line_id`),
  KEY `idx_state_expiry` (`state`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stock reservations: order lifecycle governs creation/release; documents consume';

--
-- Transfer headers: v1 transfers are instantaneous (completed); the header
-- exists so an in-transit state can be added later without reshaping the ledger.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_transfers` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_location_id` int(11) UNSIGNED NOT NULL,
  `destination_location_id` int(11) UNSIGNED NOT NULL,
  `state` varchar(12) NOT NULL DEFAULT 'completed' COMMENT 'completed (v1); in_transit reserved for a later phase',
  `operation_id` varchar(40) NOT NULL DEFAULT '',
  `correlation_id` varchar(64) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_source_location` (`source_location_id`),
  KEY `idx_destination_location` (`destination_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Transfer headers: each owns a movement pair (out/in)';

--
-- Operation registry: one row per executed inventory command, written
-- inside the command's own transaction: the audit spine tying an
-- operation_id to its source and correlation chain.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_operations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(40) NOT NULL,
  `command` varchar(20) NOT NULL COMMENT 'reserve | release | commit | restore | reverse_restore | receive | adjust | transfer | expire | reconcile | retire | location_state | unit_state | track_open | track_close | write_off | resolve_line | archive | unarchive | sale_rules | prioritise',
  `source_type` varchar(30) NOT NULL DEFAULT '',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `correlation_id` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'completed',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operation` (`operation_id`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_correlation` (`correlation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inventory command audit spine';

--
-- Recorded operator resolutions of order lines whose stock identity no longer
-- exists, or that an operator classified. Written only by the inventory service's
-- resolve command (Super User, mandatory reason) and never updated or deleted: a
-- correction is a new row that supersedes the effective one, and the order line
-- itself is never rewritten. The inventory service reads the effective row (the
-- one no other row supersedes) by order line.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_line_resolutions` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_item_id` int(11) NOT NULL COMMENT 'The resolved order line (#__alfa_order_items.id); the line is never changed',
  `order_id` int(11) NOT NULL COMMENT 'Its order, as recorded when resolved',
  `revision` smallint(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Position in the line''s chain: 1 for the first resolution, one more for each correction; the highest is the effective one',
  `supersedes_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'The resolution this one corrects (#__alfa_inventory_line_resolutions.id); NULL for the first',
  `original_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The item the line was sold as',
  `original_combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination the line was sold as; 0 = the item itself',
  `action` varchar(20) NOT NULL COMMENT 'historical_parent | bind_unit',
  `resolved_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The item whose stock identity the line acts on (always the original item)',
  `resolved_combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination the line acts on; 0 = the item itself',
  `reason` varchar(255) NOT NULL COMMENT 'The operator''s mandatory reason',
  `operation_id` varchar(40) NOT NULL COMMENT 'The resolve_line operation that recorded it (#__alfa_inventory_operations.operation_id)',
  `created` datetime NOT NULL COMMENT 'When it was recorded (UTC)',
  `created_by` int(11) NOT NULL COMMENT 'The operator (a Super User)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_line_revision` (`order_item_id`, `revision`),
  UNIQUE KEY `uq_supersedes` (`supersedes_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_resolved_unit` (`resolved_item_id`, `resolved_combination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Operator resolutions of order lines: append-only; a correction supersedes the effective row and never rewrites it';

--
-- Units kept unavailable for sale after a return or cancellation recorded stock
-- on them while their stock figures claimed stock with no stock records behind
-- them. Begun by the inventory service in the command that recorded the stock;
-- ended only by the audited repair of the unit's figures, which fills in the
-- release columns. Rows are never deleted.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_integrity_holds` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The held unit''s item',
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The held unit''s combination; 0 = the item itself',
  `stock_figure` float DEFAULT NULL COMMENT 'The stock figure the unit showed with no stock records when the hold began',
  `available_figure` float DEFAULT NULL COMMENT 'The available figure it showed then',
  `operation_id` varchar(40) NOT NULL COMMENT 'The command that recorded stock on the unit and began the hold (#__alfa_inventory_operations.operation_id)',
  `source_line_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'The order line that command recorded, if any',
  `created` datetime NOT NULL COMMENT 'When the hold began (UTC)',
  `created_by` int(11) NOT NULL DEFAULT 0 COMMENT 'The acting user of that command',
  `released_operation_id` varchar(40) DEFAULT NULL COMMENT 'The reconcile operation that repaired the figures and ended the hold; NULL while held',
  `released` datetime DEFAULT NULL COMMENT 'When it ended (UTC)',
  `released_by` int(11) DEFAULT NULL COMMENT 'The operator who repaired the figures',
  PRIMARY KEY (`id`),
  KEY `idx_unit` (`item_id`, `combination_id`, `released_operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Units unavailable for sale until their stock figures are repaired: begun by a restore onto broken figures, ended only by the audited repair';

--
-- Table structure for table `#__alfa_item_combination_values`
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_combination_values` (
  `combination_id` int(11) UNSIGNED NOT NULL,
  `value_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`combination_id`,`value_id`),
  KEY `idx_value_id` (`value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_combinations`
--
-- Variant combinations. signature = sorted value ids joined by '-' (resolution
-- + dedupe key). Own identity: sku/gtin/mpn/stock. NULL scalar = inherit from
-- item. Translatable overrides (name_suffix, description) live in the SPARSE
-- #__alfa_item_combinations_<langtag> aux tables: row present only when
-- overridden, deleted when cleared.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_combinations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `signature` varchar(255) NOT NULL,
  `sku` varchar(255) DEFAULT '',
  `gtin` varchar(255) DEFAULT '',
  `mpn` varchar(255) DEFAULT '',
  `stock` float NOT NULL DEFAULT 0 COMMENT 'Physical projection of this combination unit, maintained by the inventory service from its stock records whatever its state or tracking; display only',
  `available_quantity` float NOT NULL DEFAULT 0 COMMENT 'Salable projection of this combination unit at enabled locations, maintained by the inventory service; display only',
  `default_on` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Default combination: preselected, drives price_index.final_price',
  `weight` decimal(24,8) DEFAULT NULL COMMENT 'NULL = inherit item',
  `width` decimal(24,8) DEFAULT NULL,
  `height` decimal(24,8) DEFAULT NULL,
  `depth` decimal(24,8) DEFAULT NULL,
  `quantity_min` decimal(24,8) DEFAULT NULL COMMENT 'NULL = inherit the item''s minimum; exact to 8 decimals',
  `quantity_max` decimal(24,8) DEFAULT NULL COMMENT 'NULL = inherit the item''s maximum; exact to 8 decimals',
  `quantity_step` decimal(24,8) DEFAULT NULL COMMENT 'NULL = inherit the item''s step; exact to 8 decimals, above zero',
  `stock_action` tinyint(1) DEFAULT NULL COMMENT 'NULL = inherit item',
  `stock_low` float UNSIGNED DEFAULT NULL,
  `low_stock_notify` tinyint(1) DEFAULT NULL COMMENT 'Low-stock notify override; NULL = inherit the item',
  `manage_stock` tinyint(1) DEFAULT NULL,
  `backorder` varchar(12) DEFAULT NULL COMMENT 'NULL = inherit item; empty string = skip the item and follow the shop default; else deny|allow|allow_notify',
  `min_qty` float DEFAULT NULL COMMENT 'Safety stock for THIS variant; NULL = inherit item. Two-level by design: no skip-the-item sentinel',
  `state` tinyint(1) DEFAULT 1 COMMENT 'Catalogue state: 1 published, 0 unpublished, 2 archived (kept only as the stock identity of order history; excluded from every catalogue surface)',
  `ordering` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_available_quantity` (`available_quantity`),
  UNIQUE KEY `idx_item_signature` (`item_id`,`signature`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_state` (`state`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_option_values`
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_option_values` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `value_id` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_item_field_values.id',
  `operation` enum('+','-','%','-%') DEFAULT NULL COMMENT 'NULL = free option (no price delta); % = +%',
  `amount` decimal(24,8) DEFAULT NULL,
  `apply_on` enum('start','final') DEFAULT NULL COMMENT 'Fixed-amount delta base: start=net, final=gross; NULL = global config default',
  `ordering` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_item_value` (`item_id`,`value_id`),
  KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Item options: offered value + optional cart-line price delta';

--
-- Table structure for table `#__alfa_item_field_overrides`
--
-- ITEM-level overrides of a field's global settings (one independent row per
-- field): plugin config (via FieldParams) + the per-item field display order.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_field_overrides` (
  `item_id` int(11) UNSIGNED NOT NULL,
  `field_id` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_item_fields.id',
  `params` text DEFAULT NULL COMMENT 'Plugin-owned JSON config for this field on this item',
  `ordering` int(11) DEFAULT NULL COMMENT 'Per-item field display order; NULL = inherit global #__alfa_item_fields.ordering',
  PRIMARY KEY (`item_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Item-level item-field overrides: plugin config + per-item field display order';

--
-- Table structure for table `#__alfa_item_combination_field_params`
--
-- VARIATION / OPTION field plugin config: one independent row per field per
-- variant row, scope-keyed (combination|option). item_id is carried for the
-- item-delete cascade and the item-level fallback. Kept apart from the
-- item-level table (catalog-side, lean) and the order snapshot (history).
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_combination_field_params` (
  `scope` varchar(20) NOT NULL COMMENT '''combination'' | ''option''',
  `scope_id` int(11) UNSIGNED NOT NULL COMMENT 'FK combination.id or option-value.id',
  `field_id` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_item_fields.id',
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_items.id (cascade + fallback)',
  `params` text DEFAULT NULL COMMENT 'Plugin-owned JSON config for this field on this variant row',
  PRIMARY KEY (`scope`,`scope_id`,`field_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_field_id` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Variation/option item-field plugin config';

--
-- Table structure for table `#__alfa_item_field_categories`
--
-- Category scoping: no rows = available for all categories. Inherits to
-- descendant categories at query time.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_field_categories` (
  `item_field_id` int(11) UNSIGNED NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`item_field_id`, `category_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_field_values`
--
-- Global value pool (S/M/L/XL, Red/Blue, ...). No pair rules live here; per
-- item, the published combinations decide which value pairs exist. Translatable
-- name + alias live in #__alfa_item_field_values_<langtag>.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_field_values` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_field_id` int(11) UNSIGNED NOT NULL,
  `params` longtext DEFAULT NULL,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_item_field_id` (`item_field_id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_fields`
--
-- Global product field definitions (behavior: 0=informational, 1=option,
-- 2=variant). Translatable label/description live in
-- #__alfa_item_fields_<langtag>.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_fields` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_name` varchar(255) NOT NULL COMMENT 'Machine key, unique (like form_fields.field_name)',
  `type` varchar(255) NOT NULL COMMENT 'alfa-item-fields plugin element (choice, color, ...)',
  `informational` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = spec/display only, never selectable and never builds variants',
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `filterable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Expose in mod_alfa_filters',
  `searchable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Value names+aliases join keyword search',
  `show_on_listing` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Render on category cards (onItemListPrepare)',
  `params` longtext DEFAULT NULL COMMENT 'JSON, plugin-specific config',
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_field_name` (`field_name`),
  KEY `idx_state` (`state`),
  KEY `idx_type` (`type`),
  KEY `idx_informational` (`informational`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_downloads`
--
-- Downloadable files attached to an item (the POLICY: what future buyers get).
-- Independent of items.is_virtual: a physical item may also carry files
-- (manual, license, firmware). storage_key is an opaque object id; path
-- resolution lives ONLY in Storage (local driver shards to
-- aa/bb/<key>). Objects are deleted ONLY by garbage collection of orphaned
-- keys (no row here and no snapshot in #__alfa_order_downloads references
-- them); admin replace/delete touches rows, never objects.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_downloads` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) UNSIGNED NOT NULL,
  `combination_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = file belongs to every variant of the item',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT 'Customer-facing label (e.g. "Full package", "PDF manual")',
  `storage_disk` varchar(20) NOT NULL DEFAULT 'local' COMMENT 'Storage driver key (only "local" for now)',
  `storage_key` char(64) NOT NULL COMMENT 'Opaque object id in the storage backend (random sha256 hex, no extension)',
  `mime_type` varchar(127) NOT NULL DEFAULT 'application/octet-stream',
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bytes',
  `sha256` char(64) NOT NULL DEFAULT '' COMMENT 'Content checksum (audit: proves what was sold)',
  `original_filename` varchar(255) NOT NULL DEFAULT '' COMMENT 'Name of the uploaded file, as received',
  `display_filename` varchar(255) NOT NULL DEFAULT '' COMMENT 'Filename the customer receives (Content-Disposition)',
  `max_downloads` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Per purchased unit; 0 = unlimited',
  `days_valid` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Access window in days from grant; 0 = forever',
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_combination_id` (`combination_id`),
  KEY `idx_storage_key` (`storage_key`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Downloadable files per item (policy side)';

--
-- Table structure for table `#__alfa_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_category_default` int(11) NOT NULL,
  -- Translatable fields (name, short_desc, full_desc, alias, meta_title,
  -- meta_desc, stock_low_message, stock_zero_message) live ONLY in the
  -- per-language tables #__alfa_items_<langtag>.
  `sku` varchar(255) DEFAULT '',
  `gtin` varchar(255) DEFAULT '',
  `mpn` varchar(255) DEFAULT '',
  `stock` float DEFAULT NULL COMMENT 'Physical projection maintained by the inventory service (sum over its current, not archived, combination units while it has any; otherwise its own stock records); display only',
  `available_quantity` float DEFAULT NULL COMMENT 'Salable projection maintained by the inventory service (sum over its published, tracked combination units while it has current combinations; otherwise its own stock records at enabled locations); display only',
  `quantity_min` decimal(24,8) NOT NULL DEFAULT 1.00000000 COMMENT 'Smallest quantity sold, exact to 8 decimals; cart lines hold whole units, so the smallest sale is the first whole multiple of the step at or above it',
  `quantity_max` decimal(24,8) DEFAULT NULL COMMENT 'Largest quantity one cart line may hold, exact to 8 decimals; NULL = no limit',
  `quantity_step` decimal(24,8) NOT NULL DEFAULT 1.00000000 COMMENT 'Quantities are sold in whole multiples of this, exact to 8 decimals; above zero',
  `stock_action` tinyint(1) DEFAULT 0,
  `stock_low` float UNSIGNED DEFAULT NULL,
  `low_stock_notify` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Push a back-office notification when salable stock drops to/below stock_low',
  `manage_stock` tinyint(1) DEFAULT 1,
  `backorder` varchar(12) DEFAULT NULL COMMENT 'deny | allow | allow_notify; NULL = global default',
  `min_qty` float DEFAULT NULL COMMENT 'Safety floor withheld from sale; NULL = global default',
  `width` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `height` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `depth` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `weight` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `is_virtual` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Needs no shipping. Explicit flag, never inferred from weight/dimensions.',
  `meta_data` text NOT NULL DEFAULT ('{}'),
  `layouts` text NOT NULL DEFAULT ('{}') COMMENT 'Layout overrides JSON {layout,card,images,manufacturers,categories}; empty key = inherit category/menu/global',
  `state` tinyint(1) DEFAULT 1 COMMENT 'Catalogue state: 1 published, 0 unpublished, 2 archived (removed from current commerce and kept as the stock identity of order history), -2 trashed',
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_available_quantity` (`available_quantity`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_items_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_categories` (
  `item_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`, `category_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_item_info_values`
--
-- The item's INFORMATIONAL values (specs / catalog filters). Roles are
-- implicit: variations live in the combination tables, options in
-- #__alfa_item_option_values, informational here.
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_info_values` (
  `item_id` int(11) UNSIGNED NOT NULL,
  `value_id` int(11) UNSIGNED NOT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`,`value_id`),
  KEY `idx_value_id` (`value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_items_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_manufacturers` (
  `item_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`, `manufacturer_id`),
  KEY `idx_manufacturer_id` (`manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_items_prices`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_prices` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `value` double DEFAULT 0,
  `override_value` double DEFAULT NULL,
  `override_type` enum('start','final') NOT NULL DEFAULT 'start' COMMENT 'Meaningful only when override_value is set: start = replaces net (taxes apply), final = gross the customer pays (tax carved out)',
  `item_id` int(11) UNSIGNED NOT NULL,
  `combination_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'FK #__alfa_item_combinations.id; NULL = item-level price row. Absence of combination rows = inherit item rows.',
  `mode` enum('price','adjust') NOT NULL DEFAULT 'price' COMMENT 'price = full price row; adjust = +/-/% delta on the computed item price (combination rows only)',
  `operation` enum('+','-','%','-%') DEFAULT NULL COMMENT 'Adjust rows only; % = +%',
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
  KEY `idx_combination_id` (`combination_id`),
  KEY `idx_mode` (`mode`),
  KEY `idx_currency_id` (`currency_id`),
  KEY `idx_usergroup_id` (`usergroup_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_country_id` (`country_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_items_price_index`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_price_index` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'FK to #__alfa_items.id',
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = item-level/default row; else #__alfa_item_combinations.id',
  `currency_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to #__alfa_currencies.id; 0 = any currency',
  `place_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to #__alfa_places.id; 0 = any place / unknown location',
  `group_set_id` int(10) UNSIGNED NOT NULL COMMENT 'The set of pricing groups this row is prepared for: #__alfa_price_group_sets.id',
  `base_price` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Raw unit price: no discounts, no tax',
  `discount_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Total money saved. 0 = no discount. Filter: > 0 (on sale), >= X (save at least €X)',
  `base_price_with_discounts` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'After discounts, before tax: B2B ex-VAT net price',
  `tax_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Tax applied to the discounted price',
  `base_price_with_tax` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Base price + tax, WITHOUT discounts: strikethrough "was" price',
  `final_price` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'FILTER COLUMN *: final price visitor pays (after discounts + tax). "From €X" / ranges = MIN() across an item''s rows.',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentage saving 0-100. Filter: >= X (at least X% off)',
  `valid_from` datetime NOT NULL DEFAULT '1000-01-01 00:00:00' COMMENT 'Segment start; epoch sentinel = live segment (PK column, so no NULL). The catalog query picks the segment matching NOW().',
  `valid_until` datetime DEFAULT NULL COMMENT 'Segment end (next publish-window edge); NULL = open-ended',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'When this row was last written; the price index has no ledger, so this is its only forensic record. Self-maintained by ON UPDATE; nothing reads it in code.',
  PRIMARY KEY (`item_id`,`combination_id`,`currency_id`,`place_id`,`group_set_id`,`valid_from`),
  KEY `idx_final_price` (`currency_id`,`place_id`,`group_set_id`,`final_price`),
  KEY `idx_group_set_id` (`group_set_id`),
  KEY `idx_discount_amount` (`discount_amount`),
  KEY `idx_discount_percent` (`discount_percent`),
  KEY `idx_validity` (`valid_from`,`valid_until`),
  KEY `idx_item_id` (`item_id`),
  CONSTRAINT `fk_priceidx_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Price index: one row per item x currency x place x set of pricing groups held by customers. Column names match com_alfa config fields.';

--
-- Table structure for table `#__alfa_price_group_sets`
--
-- The sets of pricing groups customers hold, which the price index is keyed by.
-- A set nobody held before is requested by the request that brought it and
-- prepared by the scheduled task, a chunk of products at a time; prepared_through
-- is its checkpoint. prepared_at is written after the last chunk, and a set
-- without it is never read, whatever rows the index holds of it.
--

CREATE TABLE IF NOT EXISTS `#__alfa_price_group_sets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_list` text NOT NULL COMMENT 'The pricing groups of the set: ids ascending, comma-separated; empty = none',
  `digest` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-1 of group_list: the key writer and reader find the set by',
  `requested_at` datetime DEFAULT NULL COMMENT 'When the set was asked for; NULL once it is prepared',
  `prepared_at` datetime DEFAULT NULL COMMENT 'When its last chunk was written; NULL = not read',
  `prepared_through` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Checkpoint of the preparation: the last product id written',
  `left_out` text DEFAULT NULL COMMENT 'Products whose pricing threw when the set was prepared, ids comma-separated: no row of the set until the product is saved again; NULL or empty = none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_digest` (`digest`),
  KEY `idx_requested` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sets of pricing groups customers hold: the price index is keyed by them.';

--
-- Table structure for table `#__alfa_item_field_value_index`
--

CREATE TABLE IF NOT EXISTS `#__alfa_item_field_value_index` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'FK to #__alfa_items.id',
  `field_id` int(11) UNSIGNED NOT NULL COMMENT 'FK to #__alfa_item_fields.id (the value''s owning field)',
  `value_id` int(11) UNSIGNED NOT NULL COMMENT 'FK to #__alfa_item_field_values.id',
  PRIMARY KEY (`item_id`,`field_id`,`value_id`),
  KEY `idx_field_value` (`field_id`,`value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Filter projection: flat (item, field, value) rows materialised from combinations/options/info for catalog filtering + facets.';

-- Rebuild checkpoint.
--
-- A rebuild is a long walk across the whole catalogue that cannot finish inside one
-- request, so its position has to outlive the request. It previously lived in the
-- admin's SESSION, which made three things true at once: closing the tab stranded a
-- pending generation nothing would ever activate, a second administrator could start
-- a competing rebuild, and no cron or CLI driver could exist at all because the
-- checkpoint was unreachable outside a session.
--
-- The UNIQUE key on `engine` IS the mutual exclusion: at most one rebuild in flight
-- per engine, enforced by the schema rather than by a convention someone can forget.
-- `heartbeat` is what lets the next driver tell an abandoned rebuild from a running
-- one without a lock service.

CREATE TABLE IF NOT EXISTS `#__alfa_search_rebuilds` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `engine` varchar(64) NOT NULL COMMENT 'Engine key this rebuild targets',
  `generation` varchar(64) NOT NULL COMMENT 'Opaque IndexGeneration handle being filled; varchar because another engine generation is an index name, not a number',
  `supersedes` varchar(64) NOT NULL DEFAULT '' COMMENT 'Handle that was serving when this started; unknowable after the swap, so it is recorded before',
  `cursor` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Keyset position: highest source record id already processed',
  `processed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `written` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `removed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `failures` mediumtext DEFAULT NULL COMMENT 'JSON map document key => reason; activation is refused while it is non-empty',
  `enumerated` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'The walk reached the end of the catalogue',
  `activated` tinyint(1) NOT NULL DEFAULT 0,
  `started` datetime NOT NULL,
  `heartbeat` datetime NOT NULL COMMENT 'Last sign of life; a stale one marks the rebuild abandoned',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_engine` (`engine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='In-flight index rebuilds: one row per engine, resumable from any request, cron or CLI.';

-- Products written by ordinary saves WHILE a rebuild is walking.
--
-- Closes a race that is otherwise silent and permanent. The runner builds a whole
-- chunk of documents in memory before writing it; a product saved inside that window
-- is written correctly by the live path and then OVERWRITTEN by the runner's older
-- snapshot. The hash guard does not help: the two documents genuinely differ, so the
-- stale one wins. After activation the product answers to its old wording for ever,
-- with the rebuild reporting zero failures.
--
-- Recording the ids costs one INSERT IGNORE per save during a rebuild and lets
-- activation re-project exactly those products, rather than repeating the walk.

CREATE TABLE IF NOT EXISTS `#__alfa_search_rebuild_items` (
  `rebuild_id` int(10) UNSIGNED NOT NULL,
  `item_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`rebuild_id`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Items written live during a rebuild; re-projected before the generation is activated.';

--
-- Table structure for table `#__alfa_items_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_usergroups` (
  `item_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_items_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_users` (
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_manufacturers` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Translatable fields (name, alias, desc, meta_title, meta_desc) live ONLY in
  -- the per-language tables #__alfa_manufacturers_<langtag>. No main-table copy.
  `meta_data` text NOT NULL DEFAULT ('{}'),
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

--
-- Table structure for table `#__alfa_media`
--

CREATE TABLE IF NOT EXISTS `#__alfa_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alt` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `origin` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alfa_media_origin_item` (`origin`, `item_id`),
  KEY `idx_alfa_media_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_orders`
--

CREATE TABLE IF NOT EXISTS `#__alfa_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference` varchar(20) DEFAULT NULL COMMENT 'Human-readable order code (optional - can use ID instead)',
  `id_user` int(11) DEFAULT NULL,
  -- CUSTOMER SNAPSHOT: who placed the order, written once at creation.
  -- Members: copied from #__users; guests: from the delivery details they
  -- typed. The order stays identifiable after an account rename or deletion
  -- (id_user is then NULL), and a guest is a first-class customer here.
  `customer_name` varchar(400) NOT NULL DEFAULT '',
  `customer_email` varchar(254) NOT NULL DEFAULT '',
  `customer_is_guest` tinyint(1) NOT NULL DEFAULT 0,
  -- THE ORDER'S CURRENCY: stated when the order is created and never changed.
  -- Required, with no default: an insert that names none is refused. Every
  -- amount on the order, its payments, its shipments and its documents is
  -- money in it.
  `id_currency` int(11) NOT NULL,
  `currency_scale` tinyint(1) UNSIGNED DEFAULT NULL COMMENT 'The monetary scale in force when this record was created, 0-8. WRITE-ONCE. Every amount on this record is interpreted, rounded and formatted at THIS scale, never at the current #__alfa_currencies.decimal_place - the currency table is configuration for NEW monetary facts, not authority over ones already made. NULL = a pre-v1 row with no scale authority; it is NOT a zero scale and there is deliberately no runtime fallback to the live row.',
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
  `payment_method_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'Payment plugin element snapshot - the gateway to boot when id_payment_method is gone. A name cannot boot a plugin.',
  `shipment_method_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Shipment method name snapshot (fallback if id_shipment_method deleted)',
  `shipment_method_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'Shipment plugin element snapshot - the plugin to boot when id_shipment_method is gone.',
  `id_payment_currency` int(11) DEFAULT NULL,
  `id_language` int(11) DEFAULT NULL,
  `id_coupon` int(11) DEFAULT NULL,
  `code_coupon` int(11) DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'storefront' COMMENT 'Order origin: storefront | admin | renewal | api',
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
  KEY `idx_date_add` (`created`),
  KEY `idx_invoice_number` (`invoice_number`),
  -- "Waiting for you" counts every order still in the initial status, with no date
  -- bound; it is a queue, not a period. Without this it read the whole table on
  -- every dashboard load, and it is also what the attention signals' NOT IN (...) walk.
  KEY `idx_status` (`id_order_status`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_orders_statuses`
--

CREATE TABLE IF NOT EXISTS `#__alfa_orders_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  -- Translatable field (name) lives ONLY in the per-language tables
  -- #__alfa_orders_statuses_<langtag>. No main-table copy.
  `color` varchar(50) NOT NULL,
  `bg_color` varchar(50) NOT NULL,
  `stock_operation` tinyint(1) DEFAULT 1 COMMENT '0 removes from stock,\r\n1 keep in stock',
  `is_initial` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Singleton: brand-new-order status. Exactly one row should hold this; OrderstatusModel enforces single-row uniqueness.',
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Multi-row family: cancellation states (Returned, Refunded, Cancelled, ...). Drives stock-release and refund logic.',
  `is_completed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Multi-row family: terminal success states (Delivered, Picked up, Paid, ...).',
  `notify_customer` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1, EmailService sends the customer the email_subject_customer / email_body_customer template on transition INTO this status.',
  `email_layout_customer` varchar(190) NOT NULL DEFAULT 'emails.order.default' COMMENT 'LayoutHelper id for the customer notification wrapper (EmailService). Empty = emails.order.',
  `email_layout_admin` varchar(190) NOT NULL DEFAULT 'emails.order.default' COMMENT 'LayoutHelper id for the admin notification wrapper (EmailService). Empty = emails.order.',
  `issues_doc_rules` text DEFAULT NULL COMMENT 'Sales-document auto-issue rules: [{usergroups:[], series}]; series = id or sales-type slug (type default); first match wins, empty usergroups = everyone, no rows/no match = no document. Consumed by the auto-issue listener; skip+notify on missing data, never blocks the transition.',
  `issues_delivery_note` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Auto-issue a delivery note on entering this status.',
  `attach_document_pdf` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Attach the issued documents'' PDFs to this status'' email.',
  -- Status gates: live facts (never stored) decide whether an irreversible
  -- side effect may run. A status declares what it requires; the ledger and
  -- the current total answer.
  `requires_paid` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Entering this status requires the order to be fully paid. Refused otherwise, unless allow_unpaid_exception.',
  `allow_unpaid_exception` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'With requires_paid: an unpaid order may still enter, but the transition is SURFACED as an unpaid exception (history event + warning) instead of blocked.',
  `grants_downloads` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Entering this status grants download entitlements. Explicit: is_completed does not imply entitlement.',
  `documents_require_paid` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Auto-issue fiscal documents only when the order is paid; never merely because an admin picked this status.',
  `state` tinyint(1) DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) DEFAULT 0,
  `ordering` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default order-status set. A store cannot place an order without an initial
-- status (the placement falls back to an id=0 stub and logs CRITICAL), so a
-- fresh install ships a working baseline: Pending (initial) -> Processing ->
-- Shipped -> Delivered (completed), plus the cancellation family. Names seed
-- in en-GB (always present in Joomla); other content languages are managed
-- through the multilingual system.
INSERT INTO `#__alfa_orders_statuses` (`id`, `color`, `bg_color`, `stock_operation`, `is_initial`, `is_cancelled`, `is_completed`, `notify_customer`, `email_layout_customer`, `email_layout_admin`, `grants_downloads`, `state`, `created_by`, `modified`, `modified_by`, `ordering`)
SELECT * FROM (
            SELECT 1 AS `id`, '#ffffff' AS `color`, '#f0ad4e' AS `bg_color`, 0 AS `stock_operation`, 1 AS `is_initial`, 0 AS `is_cancelled`, 0 AS `is_completed`, 0 AS `notify_customer`, 'emails.order.default' AS `email_layout_customer`, 'emails.order.default' AS `email_layout_admin`, 0 AS `grants_downloads`, 1 AS `state`, 0 AS `created_by`, NOW() AS `modified`, 0 AS `modified_by`, 1 AS `ordering`
  UNION ALL SELECT 2, '#ffffff', '#0d6efd', 0, 0, 0, 0, 0, 'emails.order.default', 'emails.order.default', 0, 1, 0, NOW(), 0, 2
  UNION ALL SELECT 3, '#ffffff', '#6f42c1', 0, 0, 0, 0, 0, 'emails.order.default', 'emails.order.default', 0, 1, 0, NOW(), 0, 3
  -- Delivered hands over the goods, so it is the status that releases files.
  UNION ALL SELECT 4, '#ffffff', '#198754', 0, 0, 0, 1, 0, 'emails.order.default', 'emails.order.default', 1, 1, 0, NOW(), 0, 4
  UNION ALL SELECT 5, '#ffffff', '#dc3545', 1, 0, 1, 0, 0, 'emails.order.default', 'emails.order.default', 0, 1, 0, NOW(), 0, 5
  UNION ALL SELECT 6, '#ffffff', '#6c757d', 1, 0, 1, 0, 0, 'emails.order.default', 'emails.order.default', 0, 1, 0, NOW(), 0, 6
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_orders_statuses`);

-- The en-GB names for the seeded statuses. The per-language sibling tables are
-- normally created by the multilingual system; en-GB is pre-created here so the
-- seed ships with readable names out of the box.
CREATE TABLE IF NOT EXISTS `#__alfa_orders_statuses_en_gb` (
  `id_orderstatus` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email_subject_customer` varchar(255) DEFAULT NULL,
  `email_body_customer` longtext DEFAULT NULL,
  `email_subject_admin` varchar(255) DEFAULT NULL,
  `email_body_admin` longtext DEFAULT NULL,
  `email_positions_customer` longtext DEFAULT NULL,
  `email_positions_admin` longtext DEFAULT NULL,
  `name_customer` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_orderstatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `#__alfa_orders_statuses_en_gb` (`id_orderstatus`, `name`, `name_customer`)
SELECT * FROM (
            SELECT 1 AS `id_orderstatus`, 'Pending' AS `name`, 'Pending' AS `name_customer`
  UNION ALL SELECT 2, 'Processing', 'Processing'
  UNION ALL SELECT 3, 'Shipped', 'Shipped'
  UNION ALL SELECT 4, 'Delivered', 'Delivered'
  UNION ALL SELECT 5, 'Cancelled', 'Cancelled'
  UNION ALL SELECT 6, 'Refunded', 'Refunded'
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_orders_statuses_en_gb`);

--
-- Order statuses are intentionally NOT seeded. They are created (and named
-- per language) by the admin / install wizard; the translatable name lives in
-- the per-language tables #__alfa_orders_statuses_<langtag>.
--

--
-- Table structure for table `#__alfa_orderstatus_recipients`
--
-- Join table: each row links one order status to one Joomla user who
-- should receive the admin notification when an order transitions INTO
-- that status. Composite PK prevents duplicates.
--
-- Cleanup is programmatic, not via FK cascades:
--   - Joomla user deleted -> alfasync plugin's onUserAfterDelete drops
--     the matching id_user rows (consistent with how it drops the
--     #__alfa_users shadow row).
--   - Order status deleted -> OrderstatusModel::delete() drops the
--     matching id_orderstatus rows.
--

CREATE TABLE IF NOT EXISTS `#__alfa_orderstatus_recipients` (
  `id_orderstatus` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  PRIMARY KEY (`id_orderstatus`, `id_user`),
  KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

--
-- Table structure for table `#__alfa_order_cart_rule`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_cart_rule` (
  `id_order_cart_rule` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(10) UNSIGNED NOT NULL,
  `id_cart_rule` int(10) UNSIGNED NOT NULL,
  `id_order_invoice` int(10) UNSIGNED DEFAULT 0,
  `name` varchar(254) NOT NULL,
  `code` varchar(64) DEFAULT NULL COMMENT 'The canonical rule code frozen from the engine (never the raw POSTed string); NULL = automatic rule',
  `value_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `value_tax` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'The discount own tax component, never re-derived from the items ratio',
  `value_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'What the customer actually saw come off',
  `discount_class` varchar(10) NOT NULL DEFAULT 'order' COMMENT 'product | order | shipping; shipping rules reduce shipping, they are not discounts',
  `free_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_order_cart_rule`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_cart_rule` (`id_cart_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_order_downloads`
--
-- Download entitlements: one row per (order line x file), the customer's
-- frozen "ticket". Terms (max_downloads/days_valid/filename/storage_key) are
-- SNAPSHOTS taken at grant time; later product edits never touch history.
-- Serving follows the CURRENT file of id_download (fixes propagate); the
-- snapshot storage_key is the audit trail + fallback if the row is deleted.
-- The raw token is emailed to the customer and never stored: lookups match
-- sha256(token) against token_hash. token_prefix exists for support queries
-- only, never for authentication. status: 0 pending / 1 available / 2 expired
-- / 3 revoked (Entitlements::STATUS_*); AVAILABLE is the ONLY servable
-- state, now and always (semantic contract, enforced via isServable()).
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_downloads` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_order_item` int(11) NOT NULL,
  `id_download` int(11) UNSIGNED DEFAULT NULL COMMENT 'Soft FK #__alfa_item_downloads.id; may be deleted later, snapshot columns take over',
  `token_prefix` char(8) NOT NULL COMMENT 'First 8 chars of the raw token (support lookups)',
  `token_hash` char(64) NOT NULL COMMENT 'sha256(raw token)',
  -- THE AUTHORITATIVE ALLOWANCE. Renamed from `downloads_bought`, which read as
  -- "how many were purchased" and was in fact the TOTAL number of downloads this
  -- entitlement permits. 0 = unlimited, which is a real business meaning and not
  -- an absent value.
  --
  -- MONOTONIC DOWNWARD ONLY (pre-v1 policy): reconciliation may REDUCE it when
  -- eligibility shrinks, and may never raise it. An ordinary edit to a mutable
  -- order or catalogue is not proof of entitlement, so no edit, re-sync, re-grant
  -- or status flip can enlarge what was granted.
  `granted_allowance` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total downloads this entitlement permits. 0 = unlimited. Frozen at grant and only ever REDUCED afterwards, never raised.',
  `downloads_used` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Incremented by the atomic serve claim. NEVER decremented: consumption is historical fact.',
  -- PROVENANCE: what the allowance was actually derived from, so a figure can be
  -- explained and a shrink can be computed without consulting the mutable line.
  `granted_quantity` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The order line quantity the allowance was derived from, at grant time.',
  `granted_id_item` int(11) NOT NULL DEFAULT 0 COMMENT 'The item identity at grant time, not the line''s current one.',
  `granted_id_combination` int(11) UNSIGNED DEFAULT NULL COMMENT 'The variant identity at grant time. NULL = the line carried no combination then.',
  `max_downloads` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Snapshot of the per-unit limit at grant time',
  `days_valid` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Snapshot at grant time',
  `display_filename` varchar(255) NOT NULL DEFAULT '' COMMENT 'Snapshot at grant time',
  `mime_type` varchar(127) NOT NULL DEFAULT 'application/octet-stream' COMMENT 'Snapshot at grant time',
  `storage_key` char(64) NOT NULL COMMENT 'Snapshot at grant time; keeps the object GC-referenced (audit fallback)',
  `sha256` char(64) NOT NULL DEFAULT '' COMMENT 'Snapshot content checksum (proof of what was sold)',
  `expires_at` datetime DEFAULT NULL COMMENT 'granted_at + days_valid; NULL = no time limit',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 pending / 1 available / 2 expired / 3 revoked',
  `granted_at` datetime NOT NULL,
  `last_download_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_token_prefix` (`token_prefix`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_order_item` (`id_order_item`),
  KEY `idx_id_download` (`id_download`),
  KEY `idx_storage_key` (`storage_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Download entitlements: frozen per-purchase download rights';

--
-- Table structure for table `#__alfa_order_item_fields`
--
-- Purchase-time snapshots: orders must survive later field/value deletion and
-- renames. payload = opaque plugin data (configurator scene, upload ref, ...);
-- the core never interprets it.
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_item_fields` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order_item` int(11) NOT NULL,
  `id_order` int(11) NOT NULL COMMENT 'Denormalized for reporting',
  `id_item_field` int(11) UNSIGNED DEFAULT NULL COMMENT 'Soft FK - definition may be deleted later',
  `id_item_field_value` int(11) UNSIGNED DEFAULT NULL,
  `field_label` longtext DEFAULT NULL COMMENT 'Frozen field label, {lang:text} map across all content languages',
  `value_text` longtext DEFAULT NULL COMMENT 'Frozen value name, {lang:text} map across all content languages',
  `payload` longtext DEFAULT NULL,
  `operation` enum('+','-','%','-%') DEFAULT NULL COMMENT 'Frozen price-delta rule (NULL = variant/no delta); % = +%',
  `amount` decimal(24,8) DEFAULT NULL COMMENT 'Frozen rule amount (currency for +/-, percent for %)',
  `apply_on` enum('start','final') DEFAULT NULL COMMENT 'Frozen delta base: start=net, final=gross',
  `breakdown` longtext DEFAULT NULL COMMENT 'Frozen plugin price breakdown: [{label:{lang:text},operation,amount,price_impact_tax_excl,price_impact_tax_incl}]',
  `price_impact_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Frozen resolved delta (sum of breakdown lines), excl tax',
  `price_impact_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Frozen resolved delta (sum of breakdown lines), incl tax',
  PRIMARY KEY (`id`),
  KEY `idx_id_order_item` (`id_order_item`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_item_field` (`id_item_field`),
  KEY `idx_id_item_field_value` (`id_item_field_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_order_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_item` int(11) NOT NULL,
  `id_order` int(11) NOT NULL,
  `id_order_invoice` int(11) UNSIGNED DEFAULT NULL COMMENT 'Invoice ID (for multi-invoice orders)',
  `id_combination` int(10) UNSIGNED DEFAULT NULL COMMENT 'Resolved variant/combination; full config frozen in #__alfa_order_item_fields',
  `id_shipment_method` int(11) NOT NULL,
  `id_order_shipment` int(10) UNSIGNED DEFAULT NULL,
  `name` longtext NOT NULL COMMENT 'Frozen effective name as a {lang:text} JSON map (combination name if any, else item).',
  `sku` varchar(255) DEFAULT NULL COMMENT 'Product SKU snapshot: same width as the catalogue column; never truncated',
  `gtin` varchar(255) DEFAULT NULL COMMENT 'GTIN barcode snapshot (GTIN-8/12/13/14): same width as the catalogue column; never truncated',
  `mpn` varchar(255) DEFAULT NULL COMMENT 'Manufacturer Part Number snapshot: same width as the catalogue column; never truncated',
  `weight` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Product weight for shipping',
  `total_price_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_price_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Line total excluding tax',
  `unit_price_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Unit price including tax',
  `unit_price_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Unit price excluding tax',
  `total_shipping_price_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Shipping for this item including tax',
  `total_shipping_price_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Shipping for this item excluding tax',
  `original_product_price` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Original price before discounts',
  -- THE AUTHORITATIVE WHOLE-LINE MONEY. Every figure here is
  -- the pricing engine's own whole-line Money at the order currency's scale, never
  -- a rounded unit price multiplied by quantity and never a gross reconstructed at
  -- a blended rate. `base_total_tax_excl` IS THE AUTHORITY MARKER: when it is NULL
  -- this row predates Item 3 and carries NO authoritative whole-line facts at all.
  -- NULL means UNKNOWN and 0.00000000 means a real zero; a consumer that cannot
  -- tell them apart must not aggregate this row.
  `base_total_tax_excl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line UNDISCOUNTED net. NULL = this row has no Item 3 authority (pre-v1 legacy); never written as 0 to mean unknown.',
  `base_total_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line UNDISCOUNTED gross, charged through the REAL rate chain, never net x a blended rate.',
  `reference_total_tax_excl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line net the ordinary pricing would have charged, BEFORE any explicit staff override. The canonical comparison amount.',
  `reference_total_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line gross the ordinary pricing would have charged, before any explicit staff override.',
  `custom_unit_price_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'Staff-negotiated unit price, tax incl, carried from the cart line. NULL = no override; 0 = an explicit free price. The override state IS this column: never inferred by comparing money against the catalogue.',
  `custom_total_tax_excl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line NET of the custom price before any manual discount: as the engine applied it when the price is set (PriceResult::getExplicitPriceTotal()); after a quantity-only edit to a new quantity, and for a Set on a line whose sold configuration no longer resolves (w15-sold-configuration-changed-or-gone B), the custom gross divided out at the line''s stored tax_rate and rounded once (w19-custom-rate-quantity-edit-gross C). Written with custom_unit_price_tax_incl wherever it is set, NULL with no custom price. A manual discount on the custom price is taken off it. NULL beside a custom price = written before this column, not backfilled.',
  `custom_total_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'Whole-line GROSS of the custom price before any manual discount: the custom unit rate x quantity, rounded once at the currency scale, recomputed when the quantity changes (w15-custom-price-finer-than-scale, w19-custom-rate-quantity-edit-gross C). Set and cleared together with custom_total_tax_excl.',
  `payable_unit` decimal(24,8) DEFAULT NULL COMMENT 'The per-piece amount the customer pays before discounts, exactly as PriceResult::getPayableUnit() states it (at most 8 decimals): a quantity edit on the line''s own terms is judged by it, as a new line is. NULL = a line written before this column; its price is set again before its quantity can change.',
  `manual_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Staff intent: the manual line discount, 0-100, the value the operator set (pass2::MONEY-12). Never money and never derived from it; the saving is discount_percent / discount_amount_*.',
  `manual_discount_scope` varchar(24) NOT NULL DEFAULT 'exclude_custom_price' COMMENT 'exclude_custom_price | apply_to_custom_price: the operator''s per-line choice whether THIS manual discount applies to THIS line''s custom price (w10a-q4). Never a promotion''s override policy, and never inferred from a discount being present.',
  `refunded_amount_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Total refunded excluding tax',
  `refunded_amount_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Total refunded including tax',
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `quantity_refunded` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity refunded',
  `quantity_returned` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity returned by customer',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount percentage (e.g., 20.00 = 20% off)',
  `discount_amount_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Discount amount excluding tax',
  `discount_amount_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Discount amount including tax',
  `tax_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Applied tax name snapshot: same width as the catalogue name column; clamped at the column width, never silently truncated',
  `tax_rate` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Tax percentage (e.g., 24.000 = 24%)',
  `added` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_shipment_method` (`id_shipment_method`),
  KEY `idx_id_combination` (`id_combination`),
  KEY `idx_id_item` (`id_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Whether each order line's stock identity tracked stock when the line was
-- placed: the fact that decides whether its fulfilment deducts stock, whatever
-- the identity's tracking says later. Written by the inventory service at
-- placement (and again only when an operator re-places the line on another
-- identity); removed with its order line by the database, which is why it is
-- created after #__alfa_order_items. Lines placed before these rows existed
-- have none and are judged from their own stock history.
--

CREATE TABLE IF NOT EXISTS `#__alfa_inventory_line_provenance` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_item_id` int(11) NOT NULL COMMENT 'The order line (#__alfa_order_items.id); the record is removed with it',
  `order_id` int(11) NOT NULL COMMENT 'Its order (#__alfa_orders.id)',
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The item the line was placed as',
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination the line was placed as; 0 = the item itself',
  `tracked` tinyint(1) NOT NULL COMMENT '1 = that identity tracked stock when the line was placed (a stock claim); 0 = it did not',
  `created` datetime NOT NULL COMMENT 'When the line was placed (UTC)',
  `created_by` int(11) NOT NULL DEFAULT 0 COMMENT 'The acting user at placement; 0 = guest or system',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_item` (`order_item_id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_provenance_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `#__alfa_order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stock tracking of each order line''s identity at placement, written by the inventory service';

--
-- Table structure for table `#__alfa_order_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_reference` varchar(20) DEFAULT NULL COMMENT 'Order reference (if using references)',
  `id_order` int(11) NOT NULL,
  `id_currency` int(10) UNSIGNED NOT NULL,
  `currency_scale` tinyint(1) UNSIGNED DEFAULT NULL COMMENT 'The monetary scale in force when this record was created, 0-8. WRITE-ONCE. Every amount on this record is interpreted, rounded and formatted at THIS scale, never at the current #__alfa_currencies.decimal_place - the currency table is configuration for NEW monetary facts, not authority over ones already made. NULL = a pre-v1 row with no scale authority; it is NOT a zero scale and there is deliberately no runtime fallback to the live row.',
  `amount` decimal(24,8) NOT NULL COMMENT 'Payment amount, always positive; direction is payment_type (payment/refund/authorization/reversal/chargeback)',
  `id_payment_method` int(11) NOT NULL COMMENT 'Payment method ID',
  `payment_method` varchar(255) NOT NULL COMMENT 'Payment method name snapshot (fallback if method deleted)',
  `payment_method_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'Payment plugin element snapshot - which gateway took THIS money. credit = no gateway (store credit).',
  `id_refunded_payment` int(11) DEFAULT NULL COMMENT 'Refund or chargeback: the payment whose money this row takes back',
  `id_reversed_payment` int(11) DEFAULT NULL COMMENT 'Reversal: the refund or chargeback row this row reverses; written once, by PaymentLedger::recordReversal()',
  `id_document` int(11) UNSIGNED DEFAULT NULL COMMENT 'Credit document this refund settles -> #__alfa_documents.id; NULL = none',
  `id_credit_transaction` int(11) UNSIGNED DEFAULT NULL COMMENT 'Wallet spend this row IS -> #__alfa_customer_credit_transactions.id; NULL = not paid from the wallet',
  `payment_type` enum('payment','refund','authorization','reversal','chargeback') NOT NULL DEFAULT 'payment' COMMENT 'payment=normal, refund=money back, authorization=held funds, reversal=a provider undid a refund or chargeback, chargeback=the customer''s bank took money back',
  `transaction_status` enum('pending','authorized','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'completed' COMMENT 'pending=awaiting, authorized=held, completed=success',
  `transaction_id` mediumtext DEFAULT NULL COMMENT 'The provider''s reference, complete and never cut; equality is on the whole value (w17-refund-dedup-matching)',
  `transaction_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (IF(OCTET_LENGTH(`transaction_id`) > 0, SHA2(`transaction_id`, 256), NULL)) STORED COMMENT 'Lowercase hex SHA-256 of the complete transaction_id, generated by the database; NULL when transaction_id is NULL or empty. The lookup key, never the identity',
  `plugin_data` longtext DEFAULT NULL COMMENT 'Opaque plugin-owned JSON for this attempt: core never interprets it (instalments, chosen wallet, ...)',
  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000 COMMENT 'Currency conversion rate',
  `gateway_response` text DEFAULT NULL COMMENT 'Complete JSON from gateway (card details, metadata, everything)',
  `refund_reason` varchar(255) DEFAULT NULL,
  `refund_type` enum('full','partial') DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT 'Merchant label for this money movement (Deposit 30%, Balance); internal',
  `added` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL COMMENT 'When payment was confirmed',
  `id_employee` int(11) DEFAULT NULL COMMENT 'Admin who created payment',
  PRIMARY KEY (`id`),
  KEY `idx_order_reference` (`order_reference`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_transaction_status` (`transaction_status`),
  KEY `idx_payment_type` (`payment_type`),
  KEY `idx_transaction_id_hash` (`transaction_id_hash`),
  KEY `idx_id_refunded_payment` (`id_refunded_payment`),
  KEY `idx_id_reversed_payment` (`id_reversed_payment`),
  KEY `idx_id_document` (`id_document`),
  KEY `idx_id_credit_transaction` (`id_credit_transaction`),
  KEY `idx_payment_method_type` (`payment_method_type`),
  -- A method's setup checkup asks for its first completed payment and its first refund:
  -- one method, one direction, one status, lowest id first (the key carries `id`).
  KEY `idx_payment_method` (`id_payment_method`,`payment_type`,`transaction_status`),
  -- Every settled-money question the dashboard asks is the same shape: completed
  -- payments of one direction, in one currency, over a date window. The three single
  -- -column keys above each answer a third of that and leave the rest to a filesort;
  -- this one answers all of it, leading with the two enums so the window is a range
  -- scan rather than a scan of every payment ever taken.
  KEY `idx_reporting` (`transaction_status`,`payment_type`,`id_currency`,`processed_at`),
  -- Failed attempts have no `processed_at` (they were never processed), so the
  -- dashboard dates them by when they were attempted, and that needs its own key.
  KEY `idx_added` (`added`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_order_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) UNSIGNED NOT NULL,
  `id_shipment_method` int(11) UNSIGNED NOT NULL COMMENT 'Shipment method ID',
  `shipment_method_name` varchar(255) DEFAULT NULL COMMENT 'Shipment method name snapshot (fallback if method deleted)',
  `shipment_method_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'Shipment plugin element snapshot - which plugin shipped THIS parcel.',
  `id_order_invoice` int(11) UNSIGNED DEFAULT NULL,
  `id_currency` int(11) UNSIGNED NOT NULL,
  `weight` decimal(24,8) DEFAULT NULL,
  `shipping_cost_tax_excl` decimal(24,8) DEFAULT NULL,
  `shipping_cost_tax_incl` decimal(24,8) DEFAULT NULL,
  `shipping_tax_rate` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'VAT rate the shipping charge was taxed at (24.000 = 24%); 0.000 = untaxed. Frozen with the amounts: a rate cannot be recovered from rounded amounts.',
  `shipping_charge_basis` varchar(16) NOT NULL DEFAULT 'tax_included' COMMENT 'What the costs were derived from: tax_included (the gross was the input), tax_excluded (the net was the input) or carrier_stated (a carrier stated them; never recalculated). A staff or REST change of the charge is checked through it.',
  `shipping_tax_chain` varchar(255) NOT NULL DEFAULT '[]' COMMENT 'The tax rates the charge was calculated with, in the order they apply, as exact decimal texts (a JSON list, e.g. ["10","5"]); [] = untaxed. shipping_tax_rate is their combined rate, for display and reconciliation only.',
  `tracking_number` varchar(64) DEFAULT NULL,
  `carrier_name` varchar(255) DEFAULT NULL COMMENT 'Actual carrier name (the courier company handling the parcel)',
  `added` datetime NOT NULL,
  `shipped` datetime DEFAULT NULL COMMENT 'Timestamp when shipment was marked as shipped',
  `delivered` datetime DEFAULT NULL COMMENT 'Timestamp when shipment was marked as delivered',
  `id_employee` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'Lifecycle status: pending, shipped, delivered, cancelled, on_hold, returned',
  `plugin_data` text DEFAULT NULL COMMENT 'Opaque per-plugin payload (locker id, service code, label URL). The carrier plugin owns the shape; the core never reads inside it.',
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_shipment_method` (`id_shipment_method`),
  KEY `idx_tracking_number` (`tracking_number`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_order_shipment_items`
--
-- Which order lines ride in which parcel, and HOW MANY of each. An order line
-- may be split across several shipments ("3 now, 2 Monday"), so the quantity
-- lives here rather than on the line.
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_shipment_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order_shipment` int(11) UNSIGNED NOT NULL COMMENT '-> #__alfa_order_shipments.id',
  `id_order_item` int(11) UNSIGNED NOT NULL COMMENT '-> #__alfa_order_items.id',
  `id_order` int(11) UNSIGNED NOT NULL COMMENT 'Denormalised: per-order reads without a second join',
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Units of this line dispatched in THIS parcel',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shipment_line` (`id_order_shipment`, `id_order_item`),
  KEY `idx_id_order_item` (`id_order_item`),
  KEY `idx_id_order` (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_payment_references`
--
-- Payment reference VAULT: stored credentials for
-- merchant-initiated charges are a first-class PAYMENTS entity, reusable
-- across subscriptions, future saved-cards checkout, revocable, with expiry
-- visibility. The `reference` payload stays OPAQUE, gateway-owned JSON;
-- everything around it is normalized. Consumers FK to a row.
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_references` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL DEFAULT 0 COMMENT 'Owning customer; 0 = guest (bound to the origin order only)',
  `id_payment_method` int(11) UNSIGNED NOT NULL COMMENT 'FK #__alfa_payments: the method that can charge this reference',
  `gateway` varchar(50) NOT NULL COMMENT 'Payment plugin element (mygateway, ...); denormalized guard against method retyping',
  `id_origin_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Order whose customer-present payment established the mandate',
  `reference` longtext NOT NULL COMMENT 'Opaque gateway-owned JSON payload; core never interprets it',
  `label` varchar(100) NOT NULL DEFAULT '' COMMENT 'Customer-facing display, plugin-provided (e.g. "card **** 4242")',
  `expires_at` datetime DEFAULT NULL COMMENT 'Credential expiry when the gateway exposes it (card expiry); enables pre-dunning',
  `state` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 active / 0 revoked (revocations keep the row for audit)',
  `last_used_at` datetime DEFAULT NULL COMMENT 'Last successful charge through this reference',
  `created` datetime NOT NULL,
  `created_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_id_payment_method` (`id_payment_method`),
  KEY `idx_gateway` (`gateway`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stored payment credentials (vault) for merchant-initiated charges';

--
-- Table structure for table `#__alfa_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL DEFAULT 'standard',
  `color` varchar(50) NOT NULL,
  `bg_color` varchar(50) NOT NULL,
  `params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('{}'),
  `show_on_product` tinyint(4) NOT NULL,
  -- Translatable fields (name, description) live ONLY in the per-language
  -- tables #__alfa_payments_<langtag>. No main-table copy.
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `state` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_payments_standard_logs`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payments_standard_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_order_payment` int(11) DEFAULT NULL,
  `id_payment_method` int(11) NOT NULL DEFAULT 0,
  `action` varchar(50) DEFAULT NULL,
  `transaction_status` varchar(20) DEFAULT NULL,
  `amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `order_total` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `currency` char(3) DEFAULT NULL,
  `transaction_id` varchar(254) DEFAULT NULL,
  `refund_type` varchar(10) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_on` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_order_payment` (`id_order_payment`),
  KEY `idx_id_payment_method_action` (`id_payment_method`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_payment_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_categories` (
  `payment_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_payment_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_manufacturers` (
  `payment_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_payment_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_places` (
  `payment_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_payment_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_shipments` (
  `payment_id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL COMMENT 'Shipping method the payment method is offered with; 0 = every shipping method',
  PRIMARY KEY (`payment_id`, `shipment_id`),
  KEY `idx_shipment_id` (`shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_payment_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_usergroups` (
  `payment_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_payment_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payment_users` (
  `payment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_places` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code2` varchar(2) NOT NULL,
  `code3` varchar(3) NOT NULL,
  `currency_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to #__alfa_currencies.id; 0 = none. The default currency this country uses.',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `#__alfa_places`
--

INSERT INTO `#__alfa_places` (`id`, `name`, `code2`, `code3`, `currency_id`, `parent_id`, `state`, `ordering`, `checked_out`, `checked_out_time`, `created_by`, `modified_by`)
SELECT `seed`.`id`, `seed`.`name`, `seed`.`code2`, `seed`.`code3`, COALESCE(`c`.`id`, `seed`.`currency_id`), `seed`.`parent_id`, `seed`.`state`, `seed`.`ordering`, `seed`.`checked_out`, `seed`.`checked_out_time`, `seed`.`created_by`, `seed`.`modified_by`
FROM (
            SELECT 1 AS `id`, 'Afghanistan' AS `name`, 'AF' AS `code2`, 'AFG' AS `code3`, 0 AS `currency_id`, 0 AS `parent_id`, 0 AS `state`, 0 AS `ordering`, NULL AS `checked_out`, NULL AS `checked_out_time`, 42 AS `created_by`, 0 AS `modified_by`
  UNION ALL SELECT 2, 'Albania', 'AL', 'ALB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 3, 'Algeria', 'DZ', 'DZA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 4, 'American Samoa', 'AS', 'ASM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 5, 'Andorra', 'AD', 'AND', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 6, 'Angola', 'AO', 'AGO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 7, 'Anguilla', 'AI', 'AIA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 8, 'Antarctica', 'AQ', 'ATA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 9, 'Antigua and Barbuda', 'AG', 'ATG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 10, 'Argentina', 'AR', 'ARG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 11, 'Armenia', 'AM', 'ARM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 12, 'Aruba', 'AW', 'ABW', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 13, 'Australia', 'AU', 'AUS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 14, 'Austria', 'AT', 'AUT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 15, 'Azerbaijan', 'AZ', 'AZE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 16, 'Bahamas', 'BS', 'BHS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 17, 'Bahrain', 'BH', 'BHR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 18, 'Bangladesh', 'BD', 'BGD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 19, 'Barbados', 'BB', 'BRB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 20, 'Belarus', 'BY', 'BLR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 21, 'Belgium', 'BE', 'BEL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 22, 'Belize', 'BZ', 'BLZ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 23, 'Benin', 'BJ', 'BEN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 24, 'Bermuda', 'BM', 'BMU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 25, 'Bhutan', 'BT', 'BTN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 26, 'Bolivia', 'BO', 'BOL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 27, 'Bosnia and Herzegowina', 'BA', 'BIH', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 28, 'Botswana', 'BW', 'BWA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 29, 'Bouvet Island', 'BV', 'BVT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 30, 'Brazil', 'BR', 'BRA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 31, 'British Indian Ocean Territory', 'IO', 'IOT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 32, 'Brunei Darussalam', 'BN', 'BRN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 33, 'Bulgaria', 'BG', 'BGR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 34, 'Burkina Faso', 'BF', 'BFA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 35, 'Burundi', 'BI', 'BDI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 36, 'Cambodia', 'KH', 'KHM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 37, 'Cameroon', 'CM', 'CMR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 38, 'Canada', 'CA', 'CAN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 39, 'Cape Verde', 'CV', 'CPV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 40, 'Cayman Islands', 'KY', 'CYM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 41, 'Central African Republic', 'CF', 'CAF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 42, 'Chad', 'TD', 'TCD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 43, 'Chile', 'CL', 'CHL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 44, 'China', 'CN', 'CHN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 45, 'Christmas Island', 'CX', 'CXR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 46, 'Cocos (Keeling) Islands', 'CC', 'CCK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 47, 'Colombia', 'CO', 'COL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 48, 'Comoros', 'KM', 'COM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 49, 'Congo', 'CG', 'COG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 50, 'Cook Islands', 'CK', 'COK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 51, 'Costa Rica', 'CR', 'CRI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 52, 'Cote D\'Ivoire', 'CI', 'CIV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 53, 'Croatia', 'HR', 'HRV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 54, 'Cuba', 'CU', 'CUB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 55, 'Cyprus', 'CY', 'CYP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 56, 'Czech Republic', 'CZ', 'CZE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 57, 'Denmark', 'DK', 'DNK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 58, 'Djibouti', 'DJ', 'DJI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 59, 'Dominica', 'DM', 'DMA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 60, 'Dominican Republic', 'DO', 'DOM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 61, 'East Timor', 'TP', 'TMP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 62, 'Ecuador', 'EC', 'ECU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 63, 'Egypt', 'EG', 'EGY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 64, 'El Salvador', 'SV', 'SLV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 65, 'Equatorial Guinea', 'GQ', 'GNQ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 66, 'Eritrea', 'ER', 'ERI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 67, 'Estonia', 'EE', 'EST', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 68, 'Ethiopia', 'ET', 'ETH', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 69, 'Falkland Islands (Malvinas)', 'FK', 'FLK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 70, 'Faroe Islands', 'FO', 'FRO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 71, 'Fiji', 'FJ', 'FJI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 72, 'Finland', 'FI', 'FIN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 73, 'France', 'FR', 'FRA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 75, 'French Guiana', 'GF', 'GUF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 76, 'French Polynesia', 'PF', 'PYF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 77, 'French Southern Territories', 'TF', 'ATF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 78, 'Gabon', 'GA', 'GAB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 79, 'Gambia', 'GM', 'GMB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 80, 'Georgia', 'GE', 'GEO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 81, 'Germany', 'DE', 'DEU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 82, 'Ghana', 'GH', 'GHA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 83, 'Gibraltar', 'GI', 'GIB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 84, 'Greece', 'GR', 'GRC', 0, 0, 1, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 85, 'Greenland', 'GL', 'GRL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 86, 'Grenada', 'GD', 'GRD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 87, 'Guadeloupe', 'GP', 'GLP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 88, 'Guam', 'GU', 'GUM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 89, 'Guatemala', 'GT', 'GTM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 90, 'Guinea', 'GN', 'GIN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 91, 'Guinea-bissau', 'GW', 'GNB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 92, 'Guyana', 'GY', 'GUY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 93, 'Haiti', 'HT', 'HTI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 94, 'Heard and Mc Donald Islands', 'HM', 'HMD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 95, 'Honduras', 'HN', 'HND', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 96, 'Hong Kong', 'HK', 'HKG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 97, 'Hungary', 'HU', 'HUN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 98, 'Iceland', 'IS', 'ISL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 99, 'India', 'IN', 'IND', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 100, 'Indonesia', 'ID', 'IDN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 101, 'Iran (Islamic Republic of)', 'IR', 'IRN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 102, 'Iraq', 'IQ', 'IRQ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 103, 'Ireland', 'IE', 'IRL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 104, 'Israel', 'IL', 'ISR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 105, 'Italy', 'IT', 'ITA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 106, 'Jamaica', 'JM', 'JAM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 107, 'Japan', 'JP', 'JPN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 108, 'Jordan', 'JO', 'JOR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 109, 'Kazakhstan', 'KZ', 'KAZ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 110, 'Kenya', 'KE', 'KEN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 111, 'Kiribati', 'KI', 'KIR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 112, 'Korea, Democratic People\'s Republic of', 'KP', 'PRK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 113, 'Korea, Republic of', 'KR', 'KOR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 114, 'Kuwait', 'KW', 'KWT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 115, 'Kyrgyzstan', 'KG', 'KGZ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 116, 'Lao People\'s Democratic Republic', 'LA', 'LAO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 117, 'Latvia', 'LV', 'LVA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 118, 'Lebanon', 'LB', 'LBN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 119, 'Lesotho', 'LS', 'LSO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 120, 'Liberia', 'LR', 'LBR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 121, 'Libya', 'LY', 'LBY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 122, 'Liechtenstein', 'LI', 'LIE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 123, 'Lithuania', 'LT', 'LTU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 124, 'Luxembourg', 'LU', 'LUX', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 125, 'Macau', 'MO', 'MAC', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 126, 'Macedonia, The Former Yugoslav Republic of', 'MK', 'MKD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 127, 'Madagascar', 'MG', 'MDG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 128, 'Malawi', 'MW', 'MWI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 129, 'Malaysia', 'MY', 'MYS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 130, 'Maldives', 'MV', 'MDV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 131, 'Mali', 'ML', 'MLI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 132, 'Malta', 'MT', 'MLT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 133, 'Marshall Islands', 'MH', 'MHL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 134, 'Martinique', 'MQ', 'MTQ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 135, 'Mauritania', 'MR', 'MRT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 136, 'Mauritius', 'MU', 'MUS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 137, 'Mayotte', 'YT', 'MYT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 138, 'Mexico', 'MX', 'MEX', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 139, 'Micronesia, Federated States of', 'FM', 'FSM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 140, 'Moldova, Republic of', 'MD', 'MDA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 141, 'Monaco', 'MC', 'MCO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 142, 'Mongolia', 'MN', 'MNG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 143, 'Montserrat', 'MS', 'MSR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 144, 'Morocco', 'MA', 'MAR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 145, 'Mozambique', 'MZ', 'MOZ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 146, 'Myanmar', 'MM', 'MMR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 147, 'Namibia', 'NA', 'NAM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 148, 'Nauru', 'NR', 'NRU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 149, 'Nepal', 'NP', 'NPL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 150, 'Netherlands', 'NL', 'NLD', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 151, 'Netherlands Antilles', 'AN', 'ANT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 152, 'New Caledonia', 'NC', 'NCL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 153, 'New Zealand', 'NZ', 'NZL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 154, 'Nicaragua', 'NI', 'NIC', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 155, 'Niger', 'NE', 'NER', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 156, 'Nigeria', 'NG', 'NGA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 157, 'Niue', 'NU', 'NIU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 158, 'Norfolk Island', 'NF', 'NFK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 159, 'Northern Mariana Islands', 'MP', 'MNP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 160, 'Norway', 'NO', 'NOR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 161, 'Oman', 'OM', 'OMN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 162, 'Pakistan', 'PK', 'PAK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 163, 'Palau', 'PW', 'PLW', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 164, 'Panama', 'PA', 'PAN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 165, 'Papua New Guinea', 'PG', 'PNG', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 166, 'Paraguay', 'PY', 'PRY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 167, 'Peru', 'PE', 'PER', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 168, 'Philippines', 'PH', 'PHL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 169, 'Pitcairn', 'PN', 'PCN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 170, 'Poland', 'PL', 'POL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 171, 'Portugal', 'PT', 'PRT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 172, 'Puerto Rico', 'PR', 'PRI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 173, 'Qatar', 'QA', 'QAT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 174, 'Reunion', 'RE', 'REU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 175, 'Romania', 'RO', 'ROM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 176, 'Russian Federation', 'RU', 'RUS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 177, 'Rwanda', 'RW', 'RWA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 178, 'Saint Kitts and Nevis', 'KN', 'KNA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 179, 'Saint Lucia', 'LC', 'LCA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 180, 'Saint Vincent and the Grenadines', 'VC', 'VCT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 181, 'Samoa', 'WS', 'WSM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 182, 'San Marino', 'SM', 'SMR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 183, 'Sao Tome and Principe', 'ST', 'STP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 184, 'Saudi Arabia', 'SA', 'SAU', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 185, 'Senegal', 'SN', 'SEN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 186, 'Seychelles', 'SC', 'SYC', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 187, 'Sierra Leone', 'SL', 'SLE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 188, 'Singapore', 'SG', 'SGP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 189, 'Slovakia', 'SK', 'SVK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 190, 'Slovenia', 'SI', 'SVN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 191, 'Solomon Islands', 'SB', 'SLB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 192, 'Somalia', 'SO', 'SOM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 193, 'South Africa', 'ZA', 'ZAF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 194, 'South Georgia and the South Sandwich Islands', 'GS', 'SGS', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 195, 'Spain', 'ES', 'ESP', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 196, 'Sri Lanka', 'LK', 'LKA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 197, 'St. Helena', 'SH', 'SHN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 198, 'St. Pierre and Miquelon', 'PM', 'SPM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 199, 'Sudan', 'SD', 'SDN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 200, 'Suriname', 'SR', 'SUR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 201, 'Svalbard and Jan Mayen Islands', 'SJ', 'SJM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 202, 'Swaziland', 'SZ', 'SWZ', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 203, 'Sweden', 'SE', 'SWE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 204, 'Switzerland', 'CH', 'CHE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 205, 'Syrian Arab Republic', 'SY', 'SYR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 206, 'Taiwan', 'TW', 'TWN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 207, 'Tajikistan', 'TJ', 'TJK', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 208, 'Tanzania, United Republic of', 'TZ', 'TZA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 209, 'Thailand', 'TH', 'THA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 210, 'Togo', 'TG', 'TGO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 211, 'Tokelau', 'TK', 'TKL', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 212, 'Tonga', 'TO', 'TON', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 213, 'Trinidad and Tobago', 'TT', 'TTO', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 214, 'Tunisia', 'TN', 'TUN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 215, 'Turkey', 'TR', 'TUR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 216, 'Turkmenistan', 'TM', 'TKM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 217, 'Turks and Caicos Islands', 'TC', 'TCA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 218, 'Tuvalu', 'TV', 'TUV', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 219, 'Uganda', 'UG', 'UGA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 220, 'Ukraine', 'UA', 'UKR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 221, 'United Arab Emirates', 'AE', 'ARE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 222, 'United Kingdom', 'GB', 'GBR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 223, 'United States', 'US', 'USA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 224, 'United States Minor Outlying Islands', 'UM', 'UMI', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 225, 'Uruguay', 'UY', 'URY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 226, 'Uzbekistan', 'UZ', 'UZB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 227, 'Vanuatu', 'VU', 'VUT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 228, 'Vatican City State (Holy See)', 'VA', 'VAT', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 229, 'Venezuela', 'VE', 'VEN', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 230, 'Viet Nam', 'VN', 'VNM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 231, 'Virgin Islands (British)', 'VG', 'VGB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 232, 'Virgin Islands (U.S.)', 'VI', 'VIR', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 233, 'Wallis and Futuna Islands', 'WF', 'WLF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 234, 'Western Sahara', 'EH', 'ESH', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 235, 'Yemen', 'YE', 'YEM', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 237, 'The Democratic Republic of Congo', 'DC', 'DRC', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 238, 'Zambia', 'ZM', 'ZMB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 239, 'Zimbabwe', 'ZW', 'ZWE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 240, 'East Timor', 'XE', 'XET', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 241, 'Jersey', 'JE', 'JEY', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 242, 'St. Barthelemy', 'XB', 'XSB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 243, 'St. Eustatius', 'XU', 'XSE', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 244, 'Canary Islands', 'XC', 'XCA', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 245, 'Serbia', 'RS', 'SRB', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 246, 'Sint Maarten (French Antilles)', 'MF', 'MAF', 0, 0, 0, 0, NULL, NULL, 42, 0
  UNION ALL SELECT 247, 'Sint Maarten (Netherlands Antilles)', 'SX', 'SXM', 0, 0, 0, 0, NULL, NULL, 42, 923
  UNION ALL SELECT 248, 'Palestinian Territory, occupied', 'PS', 'PSE', 0, 0, 0, 0, NULL, NULL, 42, 923
  UNION ALL SELECT 249, 'Larisa', 'ZZ', 'LAR', 0, 84, 1, 1, NULL, NULL, 923, 923
) AS `seed`
-- Each country takes the default currency of its ISO code, joined on the
-- currency code (id-agnostic). A country whose currency is not seeded keeps 0.
LEFT JOIN `#__alfa_currencies` `c` ON `c`.`code` = CASE `seed`.`code2`
    WHEN 'AD' THEN 'EUR'
    WHEN 'AE' THEN 'AED'
    WHEN 'AF' THEN 'AFN'
    WHEN 'AG' THEN 'XCD'
    WHEN 'AI' THEN 'XCD'
    WHEN 'AL' THEN 'ALL'
    WHEN 'AM' THEN 'AMD'
    WHEN 'AN' THEN 'ANG'
    WHEN 'AO' THEN 'AOA'
    WHEN 'AR' THEN 'ARS'
    WHEN 'AS' THEN 'USD'
    WHEN 'AT' THEN 'EUR'
    WHEN 'AU' THEN 'AUD'
    WHEN 'AW' THEN 'AWG'
    WHEN 'AZ' THEN 'AZN'
    WHEN 'BA' THEN 'BAM'
    WHEN 'BB' THEN 'BBD'
    WHEN 'BD' THEN 'BDT'
    WHEN 'BE' THEN 'EUR'
    WHEN 'BF' THEN 'XOF'
    WHEN 'BG' THEN 'BGN'
    WHEN 'BH' THEN 'BHD'
    WHEN 'BI' THEN 'BIF'
    WHEN 'BJ' THEN 'XOF'
    WHEN 'BM' THEN 'BMD'
    WHEN 'BN' THEN 'BND'
    WHEN 'BO' THEN 'BOB'
    WHEN 'BR' THEN 'BRL'
    WHEN 'BS' THEN 'BSD'
    WHEN 'BT' THEN 'BTN'
    WHEN 'BW' THEN 'BWP'
    WHEN 'BY' THEN 'BYR'
    WHEN 'BZ' THEN 'BZD'
    WHEN 'CA' THEN 'CAD'
    WHEN 'CC' THEN 'AUD'
    WHEN 'CF' THEN 'XAF'
    WHEN 'CG' THEN 'XAF'
    WHEN 'CH' THEN 'CHF'
    WHEN 'CI' THEN 'XOF'
    WHEN 'CK' THEN 'NZD'
    WHEN 'CL' THEN 'CLP'
    WHEN 'CM' THEN 'XAF'
    WHEN 'CN' THEN 'CNY'
    WHEN 'CO' THEN 'COP'
    WHEN 'CR' THEN 'CRC'
    WHEN 'CU' THEN 'CUP'
    WHEN 'CV' THEN 'CVE'
    WHEN 'CX' THEN 'AUD'
    WHEN 'CY' THEN 'EUR'
    WHEN 'CZ' THEN 'CZK'
    WHEN 'DC' THEN 'CDF'
    WHEN 'DE' THEN 'EUR'
    WHEN 'DJ' THEN 'DJF'
    WHEN 'DK' THEN 'DKK'
    WHEN 'DM' THEN 'XCD'
    WHEN 'DO' THEN 'DOP'
    WHEN 'DZ' THEN 'DZD'
    WHEN 'EC' THEN 'USD'
    WHEN 'EE' THEN 'EUR'
    WHEN 'EG' THEN 'EGP'
    WHEN 'ER' THEN 'ERN'
    WHEN 'ES' THEN 'EUR'
    WHEN 'ET' THEN 'ETB'
    WHEN 'FI' THEN 'EUR'
    WHEN 'FJ' THEN 'FJD'
    WHEN 'FK' THEN 'FKP'
    WHEN 'FM' THEN 'USD'
    WHEN 'FO' THEN 'DKK'
    WHEN 'FR' THEN 'EUR'
    WHEN 'GA' THEN 'XAF'
    WHEN 'GB' THEN 'GBP'
    WHEN 'GD' THEN 'XCD'
    WHEN 'GE' THEN 'GEL'
    WHEN 'GF' THEN 'EUR'
    WHEN 'GH' THEN 'GHS'
    WHEN 'GI' THEN 'GIP'
    WHEN 'GL' THEN 'DKK'
    WHEN 'GM' THEN 'GMD'
    WHEN 'GN' THEN 'GNF'
    WHEN 'GP' THEN 'EUR'
    WHEN 'GQ' THEN 'XAF'
    WHEN 'GR' THEN 'EUR'
    WHEN 'GT' THEN 'GTQ'
    WHEN 'GU' THEN 'USD'
    WHEN 'GW' THEN 'XOF'
    WHEN 'GY' THEN 'GYD'
    WHEN 'HK' THEN 'HKD'
    WHEN 'HN' THEN 'HNL'
    WHEN 'HR' THEN 'EUR'
    WHEN 'HT' THEN 'HTG'
    WHEN 'HU' THEN 'HUF'
    WHEN 'ID' THEN 'IDR'
    WHEN 'IE' THEN 'EUR'
    WHEN 'IL' THEN 'ILS'
    WHEN 'IN' THEN 'INR'
    WHEN 'IO' THEN 'USD'
    WHEN 'IQ' THEN 'IQD'
    WHEN 'IR' THEN 'IRR'
    WHEN 'IS' THEN 'ISK'
    WHEN 'IT' THEN 'EUR'
    WHEN 'JE' THEN 'GBP'
    WHEN 'JM' THEN 'JMD'
    WHEN 'JO' THEN 'JOD'
    WHEN 'JP' THEN 'JPY'
    WHEN 'KE' THEN 'KES'
    WHEN 'KG' THEN 'KGS'
    WHEN 'KH' THEN 'KHR'
    WHEN 'KI' THEN 'AUD'
    WHEN 'KM' THEN 'KMF'
    WHEN 'KN' THEN 'XCD'
    WHEN 'KP' THEN 'KPW'
    WHEN 'KR' THEN 'KRW'
    WHEN 'KW' THEN 'KWD'
    WHEN 'KY' THEN 'KYD'
    WHEN 'KZ' THEN 'KZT'
    WHEN 'LA' THEN 'LAK'
    WHEN 'LB' THEN 'LBP'
    WHEN 'LC' THEN 'XCD'
    WHEN 'LI' THEN 'CHF'
    WHEN 'LK' THEN 'LKR'
    WHEN 'LR' THEN 'LRD'
    WHEN 'LS' THEN 'LSL'
    WHEN 'LT' THEN 'EUR'
    WHEN 'LU' THEN 'EUR'
    WHEN 'LV' THEN 'EUR'
    WHEN 'LY' THEN 'LYD'
    WHEN 'MA' THEN 'MAD'
    WHEN 'MC' THEN 'EUR'
    WHEN 'MD' THEN 'MDL'
    WHEN 'ME' THEN 'EUR'
    WHEN 'MF' THEN 'EUR'
    WHEN 'MG' THEN 'MGA'
    WHEN 'MH' THEN 'USD'
    WHEN 'MK' THEN 'MKD'
    WHEN 'ML' THEN 'XOF'
    WHEN 'MM' THEN 'MMK'
    WHEN 'MN' THEN 'MNT'
    WHEN 'MO' THEN 'MOP'
    WHEN 'MQ' THEN 'EUR'
    WHEN 'MR' THEN 'MRO'
    WHEN 'MS' THEN 'XCD'
    WHEN 'MT' THEN 'EUR'
    WHEN 'MU' THEN 'MUR'
    WHEN 'MV' THEN 'MVR'
    WHEN 'MW' THEN 'MWK'
    WHEN 'MX' THEN 'MXN'
    WHEN 'MY' THEN 'MYR'
    WHEN 'MZ' THEN 'MZN'
    WHEN 'NA' THEN 'NAD'
    WHEN 'NC' THEN 'XPF'
    WHEN 'NE' THEN 'XOF'
    WHEN 'NG' THEN 'NGN'
    WHEN 'NI' THEN 'NIO'
    WHEN 'NL' THEN 'EUR'
    WHEN 'NO' THEN 'NOK'
    WHEN 'NP' THEN 'NPR'
    WHEN 'NR' THEN 'AUD'
    WHEN 'NU' THEN 'NZD'
    WHEN 'NZ' THEN 'NZD'
    WHEN 'OM' THEN 'OMR'
    WHEN 'PA' THEN 'PAB'
    WHEN 'PE' THEN 'PEN'
    WHEN 'PF' THEN 'XPF'
    WHEN 'PG' THEN 'PGK'
    WHEN 'PH' THEN 'PHP'
    WHEN 'PK' THEN 'PKR'
    WHEN 'PL' THEN 'PLN'
    WHEN 'PM' THEN 'EUR'
    WHEN 'PN' THEN 'NZD'
    WHEN 'PR' THEN 'USD'
    WHEN 'PS' THEN 'ILS'
    WHEN 'PT' THEN 'EUR'
    WHEN 'PW' THEN 'USD'
    WHEN 'PY' THEN 'PYG'
    WHEN 'QA' THEN 'QAR'
    WHEN 'RE' THEN 'EUR'
    WHEN 'RO' THEN 'RON'
    WHEN 'RS' THEN 'RSD'
    WHEN 'RU' THEN 'RUB'
    WHEN 'RW' THEN 'RWF'
    WHEN 'SA' THEN 'SAR'
    WHEN 'SB' THEN 'SBD'
    WHEN 'SC' THEN 'SCR'
    WHEN 'SD' THEN 'SDG'
    WHEN 'SE' THEN 'SEK'
    WHEN 'SG' THEN 'SGD'
    WHEN 'SH' THEN 'SHP'
    WHEN 'SI' THEN 'EUR'
    WHEN 'SJ' THEN 'NOK'
    WHEN 'SK' THEN 'EUR'
    WHEN 'SL' THEN 'SLL'
    WHEN 'SM' THEN 'EUR'
    WHEN 'SN' THEN 'XOF'
    WHEN 'SO' THEN 'SOS'
    WHEN 'SR' THEN 'SRD'
    WHEN 'ST' THEN 'STD'
    WHEN 'SV' THEN 'USD'
    WHEN 'SX' THEN 'ANG'
    WHEN 'SY' THEN 'SYP'
    WHEN 'SZ' THEN 'SZL'
    WHEN 'TC' THEN 'USD'
    WHEN 'TD' THEN 'XAF'
    WHEN 'TG' THEN 'XOF'
    WHEN 'TH' THEN 'THB'
    WHEN 'TJ' THEN 'TJS'
    WHEN 'TM' THEN 'USD'
    WHEN 'TN' THEN 'TND'
    WHEN 'TO' THEN 'TOP'
    WHEN 'TR' THEN 'TRY'
    WHEN 'TT' THEN 'TTD'
    WHEN 'TV' THEN 'AUD'
    WHEN 'TW' THEN 'TWD'
    WHEN 'TZ' THEN 'TZS'
    WHEN 'UA' THEN 'UAH'
    WHEN 'UG' THEN 'UGX'
    WHEN 'UM' THEN 'USD'
    WHEN 'US' THEN 'USD'
    WHEN 'UY' THEN 'UYU'
    WHEN 'UZ' THEN 'UZS'
    WHEN 'VA' THEN 'EUR'
    WHEN 'VC' THEN 'XCD'
    WHEN 'VE' THEN 'VEF'
    WHEN 'VG' THEN 'USD'
    WHEN 'VI' THEN 'USD'
    WHEN 'VN' THEN 'VND'
    WHEN 'VU' THEN 'VUV'
    WHEN 'WF' THEN 'XPF'
    WHEN 'WS' THEN 'WST'
    WHEN 'XC' THEN 'EUR'
    WHEN 'YE' THEN 'YER'
    WHEN 'YT' THEN 'EUR'
    WHEN 'ZA' THEN 'ZAR'
    WHEN 'ZM' THEN 'ZMK'
    WHEN 'ZW' THEN 'ZWD'
    WHEN 'ZZ' THEN 'EUR'
END
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_places`);

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
  -- Translatable fields (name, description) live ONLY in the per-language
  -- tables #__alfa_shipments_<langtag>. No main-table copy.
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `state` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_shipment_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_categories` (
  `shipment_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_shipment_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_manufacturers` (
  `shipment_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_shipment_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_places` (
  `shipment_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_shipment_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_usergroups` (
  `shipment_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_shipment_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_users` (
  `shipment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `#__alfa_shipment_taxes`
--
-- The tax rules a shipping method's charge is taxed with. No row = the method
-- names none and uses the shop's default shipping taxes (component option
-- shipping_tax_rules). Each rule's places, user groups, users and publish
-- window decide whether it applies to a shipment; its category and
-- manufacturer scopes describe goods and are not read for shipping.
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_taxes` (
  `shipment_id` int(11) NOT NULL,
  `tax_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `tax_id`),
  KEY `idx_tax_id` (`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_taxes`
--

CREATE TABLE IF NOT EXISTS `#__alfa_taxes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Translatable fields (name, desc) live ONLY in the per-language tables
  -- #__alfa_taxes_<langtag>. No main-table copy.
  `value` decimal(10,3) NOT NULL COMMENT 'Tax percentage (e.g., 24.000 = 24%)',
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

--
-- Table structure for table `#__alfa_tax_categories`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_categories` (
  `tax_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`tax_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_tax_manufacturers`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_manufacturers` (
  `tax_id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  PRIMARY KEY (`tax_id`, `manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_tax_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_places` (
  `tax_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  PRIMARY KEY (`tax_id`, `place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_tax_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_usergroups` (
  `tax_id` int(11) NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`tax_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_tax_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_tax_users` (
  `tax_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`tax_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_usergroups`
--

CREATE TABLE IF NOT EXISTS `#__alfa_usergroups` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`usergroup_id` int(11) NOT NULL DEFAULT 1,
`prices_enable` varchar(255) DEFAULT '0',
`prices_display` longtext NOT NULL,
PRIMARY KEY (`id`),
KEY `idx_usergroup_id` (`usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_users`
--

CREATE TABLE IF NOT EXISTS `#__alfa_users` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`id_user` int(11) NOT NULL DEFAULT 0,
`note` varchar(255) NOT NULL DEFAULT '',
PRIMARY KEY (`id`),
KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__alfa_user_info`
--

-- One row = one set of customer details (a delivery or invoice record; rows
-- are role-neutral). Every column but id/id_user is a form field: the seeded
-- identity fields ship with their columns here, merchant fields add theirs
-- through FormfieldModel (ALTER TABLE).
CREATE TABLE IF NOT EXISTS `#__alfa_user_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `name` varchar(400) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `country` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Backend notification store (pushed from anywhere, always read from here).
--
CREATE TABLE IF NOT EXISTS `#__alfa_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dedup_key` VARCHAR(191) NOT NULL DEFAULT '',
  `notify_group` VARCHAR(64) NOT NULL DEFAULT '',
  `severity` VARCHAR(16) NOT NULL DEFAULT 'info',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `url` VARCHAR(1024) NOT NULL DEFAULT '',
  `dismissible` TINYINT(1) NOT NULL DEFAULT 1,
  `view_access` VARCHAR(128) NULL DEFAULT NULL,
  `view_access_asset` VARCHAR(191) NULL DEFAULT NULL,
  `url_access` VARCHAR(128) NULL DEFAULT NULL,
  `url_access_asset` VARCHAR(191) NULL DEFAULT NULL,
  `created` DATETIME NULL DEFAULT NULL,
  `readed` DATETIME NULL DEFAULT NULL,
  `readed_by` INT UNSIGNED NULL DEFAULT NULL,
  `dismissed` DATETIME NULL DEFAULT NULL,
  `expires` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_dedup_key` (`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Frozen cart contributions: the calculated snapshot of the cart
-- contribution pipeline (onCartTotals). Full accounting form per row; the
-- totals add contributions the way they subtract cart rules. Append-only history.
CREATE TABLE IF NOT EXISTS `#__alfa_order_contributions` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `code` varchar(64) NOT NULL COMMENT 'Stable contribution id, e.g. signup_fee',
  `source` varchar(128) NOT NULL DEFAULT '' COMMENT 'Contributor, e.g. subscriptions.signup_fee',
  `name` longtext NOT NULL COMMENT 'Frozen display label',
  `amount_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `tax_rate` decimal(10,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `amount_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen cart contributions per order';

-- CART RULES - coupons + automatic condition rules (ONE entity:
-- a coupon is a rule whose activation is a code). Conditions are
-- relational (groups AND, condition rows per group all required,
-- values inside a condition OR) so rules stay queryable. Applied
-- rules freeze to #__alfa_order_cart_rule; usage is COUNTED from
-- that freeze (no counter columns to lock).

CREATE TABLE IF NOT EXISTS `#__alfa_cart_rules` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Translatable field (name) lives ONLY in the per-language tables
  -- #__alfa_cart_rules_<langtag>. No main-table copy, no fallback to one.
  `description` text DEFAULT NULL,
  `code` varchar(64) DEFAULT NULL COMMENT 'NULL = automatic (codeless) rule',
  `discount_class` varchar(10) NOT NULL DEFAULT 'order' COMMENT 'product | order | shipping',
  `action` varchar(32) NOT NULL DEFAULT 'percent' COMMENT 'percent | amount | <plugin type>',
  `action_value` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Percent for percent, tax-incl currency for amount',
  `max_discount_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT '0 = uncapped; tax-incl ceiling on this rule payout',
  `discount_base` varchar(10) NOT NULL DEFAULT 'cart' COMMENT 'cart | matching | cheapest',
  `exclude_discounted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Skip cart lines that already carry a catalogue discount',
  `overridden_lines` varchar(10) NOT NULL DEFAULT 'exclude' COMMENT 'Price-overridden lines: exclude | allow. A DOMAIN, not a boolean, so a third policy needs no schema change. Eligibility only -- never execution order, and a SEPARATE axis from exclude_discounted: a negotiated line carries no catalogue discount, so the two never stand in for one another.',
  `min_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `min_amount_tax_incl` tinyint(1) NOT NULL DEFAULT 1,
  `min_amount_base` varchar(10) NOT NULL DEFAULT 'cart' COMMENT 'cart | matching',
  `combines_product` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'May combine with product-class rules (mutual opt-in)',
  `combines_order` tinyint(1) NOT NULL DEFAULT 0,
  `combines_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 0 COMMENT 'Intra-class evaluation order (lower first)',
  `usage_limit_total` int(11) NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `usage_limit_per_user` int(11) NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `highlight` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Advertise on product/cart surfaces',
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_code` (`code`),
  KEY `idx_state_dates` (`state`, `publish_up`, `publish_down`),
  KEY `idx_class` (`discount_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rules: coupons + automatic discounts (one entity)';

-- Condition groups: ALL groups of a rule must be satisfied (AND).
-- A group is satisfied when the quantity of cart lines matching ALL
-- its condition rows reaches min_quantity.
CREATE TABLE IF NOT EXISTS `#__alfa_cart_rule_groups` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cart_rule` int(11) UNSIGNED NOT NULL,
  `min_quantity` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_id_cart_rule` (`id_cart_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rule condition groups (AND between groups)';

-- Condition rows: a line matches the GROUP only when it matches EVERY
-- condition row of the group; a row matches when the line hits any of
-- the row values (OR), inverted by exclude.
CREATE TABLE IF NOT EXISTS `#__alfa_cart_rule_conditions` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_group` int(11) UNSIGNED NOT NULL,
  `type` varchar(32) NOT NULL COMMENT 'items | categories | manufacturers',
  `exclude` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_id_group` (`id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rule conditions (all rows of a group must match)';

CREATE TABLE IF NOT EXISTS `#__alfa_cart_rule_condition_values` (
  `id_condition` int(11) UNSIGNED NOT NULL,
  `id_value` int(11) NOT NULL,
  PRIMARY KEY (`id_condition`, `id_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rule condition value sets (OR inside a condition)';

CREATE TABLE IF NOT EXISTS `#__alfa_cart_rules_users` (
  `id_cart_rule` int(11) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id_cart_rule`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rule user targeting (empty = everyone)';

CREATE TABLE IF NOT EXISTS `#__alfa_cart_rules_usergroups` (
  `id_cart_rule` int(11) UNSIGNED NOT NULL,
  `usergroup_id` int(11) NOT NULL,
  PRIMARY KEY (`id_cart_rule`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cart rule usergroup targeting (empty = every group)';

-- SALES DOCUMENTS: unified document family
-- One header table for every legal document (fiscal + delivery notes),
-- typed frozen lines, per-rate VAT summary, gapless series.
-- Issued rows are IMMUTABLE: void/corrective only, never edit/delete.

-- Numbering series: first-class entity. Gapless counters are drawn from
-- next_number under SELECT ... FOR UPDATE inside the issue transaction;
-- last_issued_at enforces per-series date monotonicity.
--
-- Document type registry: protected semantic core, extended
-- only by installers/migrations/packages (never a merchant "new type").
-- The engine reads PROPERTIES (family, quantity_pool, ...), never aliases;
-- aliases are the immutable published contract frozen onto documents.
--
CREATE TABLE IF NOT EXISTS `#__alfa_document_types` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alias` varchar(60) NOT NULL COMMENT 'Immutable machine key, namespaced (core.invoice, <vendor>.<type>), frozen onto documents/series',
  `family` varchar(16) NOT NULL COMMENT 'Semantic family (closed engine axis): sales | credit | logistics | informational',
  `quantity_pool` varchar(16) NOT NULL COMMENT 'Counting semantics (closed engine axis): sales (shared pool) | credit (draws on issued sales) | independent (per-type counter) | none (uncounted)',
  `shows_prices` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Money columns and tax/total blocks render',
  `movement_mode` varchar(10) NOT NULL DEFAULT 'none' COMMENT 'none | optional | required: movement-block applicability and requirement',
  `requires_original` varchar(10) NOT NULL DEFAULT 'no' COMMENT 'no | optional | required: corrective association strictness',
  `corrects` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Credit types: comma set of the sales type aliases this type corrects; an automatic correction of such a document is issued as this type. Empty = corrects none',
  `requires_buyer_tax_id` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Issuance requires a buyer tax/VAT number (jurisdiction packages enable)',
  `stock_effect` varchar(10) NOT NULL DEFAULT 'none' COMMENT 'Semantic axis: none | deduct | restore; what issuing this type does to physical stock',
  `default_layout` varchar(100) NOT NULL DEFAULT '' COMMENT 'Layout id override; empty = documents.<alias>.default',
  `icon` varchar(50) NOT NULL DEFAULT '',
  `color` varchar(7) NOT NULL DEFAULT '',
  `ordering` int(11) NOT NULL DEFAULT 0,
  `state` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Visibility in pickers only; types referenced by documents are never deleted',
  `is_core` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Core-seeded: undeletable, semantics locked',
  `provider` varchar(100) NOT NULL DEFAULT '' COMMENT 'Owning extension element for package types; empty = core',
  `provider_version` varchar(20) NOT NULL DEFAULT '' COMMENT 'Version of the package that last registered this type',
  `params` text DEFAULT NULL COMMENT 'Extension metadata JSON (capabilities, provider hints), never semantics',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alias` (`alias`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Document type registry: protected semantic core, extended by packages';

INSERT INTO `#__alfa_document_types` (`id`, `alias`, `family`, `quantity_pool`, `shows_prices`, `movement_mode`, `requires_original`, `corrects`, `requires_buyer_tax_id`, `stock_effect`, `ordering`, `state`, `is_core`)
SELECT * FROM (
            SELECT 1 AS `id`, 'core.invoice' AS `alias`, 'sales' AS `family`, 'sales' AS `quantity_pool`, 1 AS `shows_prices`, 'optional' AS `movement_mode`, 'no' AS `requires_original`, '' AS `corrects`, 0 AS `requires_buyer_tax_id`, 'none' AS `stock_effect`, 1 AS `ordering`, 1 AS `state`, 1 AS `is_core`
  UNION ALL SELECT 2, 'core.retail_receipt', 'sales', 'sales', 1, 'optional', 'no', '', 0, 'none', 2, 1, 1
  UNION ALL SELECT 3, 'core.credit_invoice', 'credit', 'credit', 1, 'none', 'optional', 'core.invoice', 0, 'restore', 3, 1, 1
  UNION ALL SELECT 4, 'core.credit_receipt', 'credit', 'credit', 1, 'none', 'optional', 'core.retail_receipt', 0, 'restore', 4, 1, 1
  UNION ALL SELECT 5, 'core.delivery_note', 'logistics', 'independent', 0, 'optional', 'no', '', 0, 'deduct', 5, 1, 1
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `#__alfa_document_types`);

CREATE TABLE IF NOT EXISTS `#__alfa_document_series` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(60) NOT NULL COMMENT 'The #__alfa_document_types.alias this series numbers (core.invoice, <vendor>.<type>); same width as the registry alias, frozen onto every document issued from it',
  `code` varchar(20) NOT NULL COMMENT 'Series code printed on documents',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT 'Internal admin label',
  `prefix` varchar(20) NOT NULL DEFAULT '' COMMENT 'Printed number prefix',
  `padding` tinyint(3) UNSIGNED NOT NULL DEFAULT 6 COMMENT 'Zero-pad width of the printed number',
  `next_number` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `year_scoped` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = counter resets each shop-timezone year',
  `current_year` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Year next_number belongs to (shop tz); NULL when not year_scoped',
  `branch_code` varchar(20) NOT NULL DEFAULT '' COMMENT 'Branch/location code for tax-authority reporting',
  `seller_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Issuer identity of this series, frozen onto each document at issue',
  `seller_vat_number` varchar(20) NOT NULL DEFAULT '',
  `seller_tax_office` varchar(100) NOT NULL DEFAULT '',
  `seller_activity` varchar(255) NOT NULL DEFAULT '',
  `seller_address` text DEFAULT NULL COMMENT 'Plain-text address block',
  `seller_extra` text DEFAULT NULL COMMENT 'Registry no, phone, web, IBAN: printed in the document header/footer',
  `last_issued_at` datetime DEFAULT NULL COMMENT 'UTC instant of last issuance; monotonicity guard',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Default series for its doc_type (auto-issue target). Cross-row singleton per type, model-enforced.',
  `layout` varchar(100) NOT NULL DEFAULT '' COMMENT 'Optional per-series layout override',
  `positions` text DEFAULT NULL COMMENT 'Per-language merchant text for the slots declared by the layout: {lang: {slot: html}}',
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_series` (`doc_type`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales document numbering series (gapless, per type)';

-- Document headers: one row per legal document of ANY type.
-- number/number_formatted/issue dates stay NULL until issue; both are FROZEN
-- at issue (later prefix/padding changes never rewrite history).
CREATE TABLE IF NOT EXISTS `#__alfa_documents` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(60) NOT NULL,
  `status` tinyint(1) NOT NULL COMMENT '1 issued, 2 voided. NO DEFAULT on purpose: the lifecycle state is never implicit, so a writer that forgets it fails loudly instead of minting a document. Issued = immutable; NO unvoid transition exists.',
  `is_combined` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Fiscal doc that also serves as delivery note (movement block filled)',
  `id_series` int(11) UNSIGNED DEFAULT NULL,
  `number` int(11) UNSIGNED DEFAULT NULL COMMENT 'Raw gapless counter value',
  `number_year` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Numbering year for year-scoped series (shop tz), 0 for non-scoped; part of the unique key so a year-scoped counter can restart at 1 without colliding with prior years',
  `number_formatted` varchar(64) DEFAULT NULL COMMENT 'FROZEN formatted number',
  `issue_date` datetime DEFAULT NULL COMMENT 'UTC instant, server-assigned at issue',
  `issue_date_local` date DEFAULT NULL COMMENT 'FROZEN legal date in shop timezone (drives year scoping/reports)',
  `id_order` int(11) NOT NULL,
  `id_shipment` int(10) UNSIGNED DEFAULT NULL COMMENT 'Delivery notes / combined docs -> #__alfa_order_shipments',
  `id_corrects` int(11) UNSIGNED DEFAULT NULL COMMENT 'Corrective chain -> #__alfa_documents.id. REQUIRED on credit types: it is the axis the cumulative credit cap is computed on, so a credit without one has no ceiling. NULL only on non-credit documents',
  `id_replaces` int(11) UNSIGNED DEFAULT NULL COMMENT 'Canonical replacement reference -> #__alfa_documents.id: the document this one supersedes. Written ONLY by the explicit Void & Replace workflow; a manual void followed by ordinary issuance leaves it NULL. replaced_by is DERIVED from this column (WHERE id_replaces = :id) and never persisted. Deliberately NOT a foreign key: a cascade would destroy the replacement together with the document it supersedes, and SET NULL would erase the provenance this column exists to record',
  `credit_destination` varchar(20) DEFAULT NULL COMMENT 'Where the money of a credit note was sent, FROZEN with its number and never rewritten: customer_credit = the customer credit account. NULL = nothing was frozen: a document that is not a credit note, a credit note sent anywhere else, or one numbered before destinations were frozen. A note frozen for the credit account holds what it has not paid in yet against what its order can still give back, until that credit is paid in, a refund settles the note, or the note is voided. What was paid in is read from the credit ledger and the payments, never stored here',
  `language` varchar(10) NOT NULL DEFAULT 'en-GB' COMMENT 'Document language; lines store RESOLVED text',
  `id_currency` int(11) NOT NULL,
  `currency_scale` tinyint(1) UNSIGNED DEFAULT NULL COMMENT 'The monetary scale in force when this record was created, 0-8. WRITE-ONCE. Every amount on this record is interpreted, rounded and formatted at THIS scale, never at the current #__alfa_currencies.decimal_place - the currency table is configuration for NEW monetary facts, not authority over ones already made. NULL = a pre-v1 row with no scale authority; it is NOT a zero scale and there is deliberately no runtime fallback to the live row.',
  `currency_code` char(3) NOT NULL DEFAULT 'EUR',
  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000 COMMENT 'Currency conversion rate',
  `buyer_name` varchar(255) NOT NULL DEFAULT '',
  `buyer_vat_number` varchar(20) NOT NULL DEFAULT '' COMMENT 'Buyer tax/VAT number, required per configuration',
  `buyer_tax_office` varchar(100) NOT NULL DEFAULT '' COMMENT 'Buyer tax office, where the jurisdiction uses one',
  `buyer_activity` varchar(255) NOT NULL DEFAULT '',
  `buyer_country` char(2) NOT NULL DEFAULT '',
  `buyer_email` varchar(255) NOT NULL DEFAULT '',
  `buyer_address` text DEFAULT NULL COMMENT 'JSON {street,number,city,zip,region,country}',
  `seller_name` varchar(255) NOT NULL DEFAULT '',
  `seller_vat_number` varchar(20) NOT NULL DEFAULT '',
  `seller_tax_office` varchar(100) NOT NULL DEFAULT '',
  `seller_activity` varchar(255) NOT NULL DEFAULT '',
  `seller_address` text DEFAULT NULL COMMENT 'JSON snapshot of shop identity at issue',
  `seller_extra` text DEFAULT NULL COMMENT 'JSON (business registry no, phone, web, IBAN display)',
  `payment_method_name` varchar(255) NOT NULL DEFAULT '',
  `authority_payment_type` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Tax-authority payment method code (transmission plugins)',
  `movement_purpose` varchar(50) DEFAULT NULL COMMENT 'Movement purpose code (delivery notes/combined)',
  `carrier_name` varchar(255) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `movement_start` datetime DEFAULT NULL COMMENT 'Local wall-clock as printed',
  `ship_from` text DEFAULT NULL COMMENT 'JSON address',
  `ship_to` text DEFAULT NULL COMMENT 'JSON address',
  `total_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_tax` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `authority_type` varchar(10) DEFAULT NULL COMMENT 'Tax-authority document type code, stamped by the transmission plugin',
  `authority_mark` varchar(30) DEFAULT NULL,
  `authority_uid` varchar(64) DEFAULT NULL,
  `authority_authcode` varchar(64) DEFAULT NULL,
  `authority_state` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 n/a, 1 pending, 2 sent, 3 accepted, 4 rejected; ORTHOGONAL to status',
  `qr_url` varchar(500) DEFAULT NULL COMMENT 'Provider verification URL rendered as QR',
  `provider_signature` text DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) UNSIGNED DEFAULT NULL,
  `void_reason` varchar(500) DEFAULT NULL,
  `note` text DEFAULT NULL COMMENT 'Internal admin note (never printed)',
  `print_note` text DEFAULT NULL COMMENT 'Printed remark',
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT 0,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_series_number` (`id_series`, `number_year`, `number`),
  KEY `idx_order` (`id_order`),
  KEY `idx_type_status` (`doc_type`, `status`),
  KEY `idx_corrects` (`id_corrects`),
  KEY `idx_replaces` (`id_replaces`),
  KEY `idx_issue_local` (`issue_date_local`),
  KEY `idx_shipment` (`id_shipment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales documents: unified fiscal + delivery family; issued rows immutable';

-- Document lines: typed frozen snapshots. Totals are FROZEN at issue and
-- never recomputed at render. id_order_item NULL = free-text / value-only
-- line (price corrections, adjustments).
CREATE TABLE IF NOT EXISTS `#__alfa_document_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_document` int(11) UNSIGNED NOT NULL,
  `line_type` varchar(12) NOT NULL DEFAULT 'item' COMMENT 'item | shipping | fee | adjustment',
  `id_order_item` int(11) DEFAULT NULL COMMENT '-> #__alfa_order_items.id; NULL = free-text/value-only line. PROVENANCE and ledger identity only; the inventory authority is the frozen id_item/id_combination/movement_bound below, never this mutable row',
  `id_item` int(11) DEFAULT NULL COMMENT 'FROZEN inventory identity at issue -> #__alfa_items.id; never re-read from the order line, so a later variant edit cannot rewrite what the document moved. NULL = this line carries no inventory identity: a free-text line, or any line of a stock_effect: none document',
  `id_combination` int(10) UNSIGNED DEFAULT NULL COMMENT 'FROZEN variant identity at issue -> #__alfa_item_combinations.id; never re-read from the order line. NULL = the goods have no variant, or the line carries no inventory identity at all',
  `movement_bound` decimal(24,8) DEFAULT NULL COMMENT 'FROZEN cumulative movement ceiling as at issue: the ordered quantity this line was authorised against; never re-read from the order line. NULL = this line carries no movement authority. A zero default would be a lie: it reads as "authorised to move nothing" and would hide a forgotten write',
  `id_source` int(10) UNSIGNED DEFAULT NULL COMMENT 'Charge source per line_type: shipping=#__alfa_order_shipments.id, fee=#__alfa_order_contributions.id, adjustment=#__alfa_order_cart_rule.id_order_cart_rule - the consumed-balance ledger key. Required on every shipping, fee and adjustment line of a fiscal sales document (the issuer refuses one without it) and on every credit charge line, which copies the corrected document line''s id_source: it is the axis of the per-source credit cap',
  `sku` varchar(255) NOT NULL DEFAULT '' COMMENT 'Frozen order-line SKU: same width as the order-line snapshot; never truncated',
  `name` varchar(500) NOT NULL COMMENT 'RESOLVED single-language text (document.language)',
  `description` text DEFAULT NULL COMMENT 'Attributes/terms snapshot as printed',
  `quantity` decimal(24,8) NOT NULL DEFAULT 1.000000,
  `unit` varchar(20) NOT NULL DEFAULT '' COMMENT 'Unit label as printed (pcs, kg, ...)',
  `unit_price_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `unit_price_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `original_unit_price_tax_excl` decimal(24,8) DEFAULT NULL COMMENT 'Pre-discount unit price for display',
  `custom_unit_price_tax_incl` decimal(24,8) DEFAULT NULL COMMENT 'FROZEN AT ISSUE: the explicit custom unit rate, tax incl, the order line was agreed at (a credit copies the corrected line''s). NULL = the line had no custom rate, and on every charge line and every line of a document without money. Never re-read from the order line, which stays editable',
  `discount_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Per-line discount amount as printed',
  `tax_rate` decimal(10,3) NOT NULL DEFAULT 0.000,
  `vat_category` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Tax-authority VAT category code (transmission plugins)',
  `vat_exemption_code` varchar(10) DEFAULT NULL COMMENT 'Tax-authority exemption reason code when rate 0',
  `total_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `tax_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `total_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `authority_classification` text DEFAULT NULL COMMENT 'JSON income classification slot (transmission plugin)',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_document` (`id_document`),
  KEY `idx_order_item` (`id_order_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales document lines: typed frozen snapshots';

-- Per-rate tax summary, written at issue from the lines;
-- rendering and VAT reports read THIS, never re-derive (rounding truth).
CREATE TABLE IF NOT EXISTS `#__alfa_document_taxes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_document` int(11) UNSIGNED NOT NULL,
  `tax_rate` decimal(10,3) NOT NULL,
  `vat_category` tinyint(3) UNSIGNED DEFAULT NULL,
  `exemption_code` varchar(10) DEFAULT NULL,
  `base_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `tax_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_rate` (`id_document`, `tax_rate`, `vat_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frozen per-rate VAT summary per document';

--  CUSTOMER CREDIT: the store-credit ledger ("wallet" in UI copy)
-- Two-layer shape, same as inventory: an append-only TRANSACTIONS
-- ledger is the truth (balance = SUM(amount)), and a per-user ACCOUNT
-- row is the SELECT...FOR UPDATE anchor that serializes concurrent
-- writes and carries a cached balance, never authoritative, always
-- recomputed from the ledger inside the lock.
-- Named "customer credit", not wallet: it holds refund/goodwill/promo
-- CREDIT, not stored customer money (payment regulation matters).

CREATE TABLE IF NOT EXISTS `#__alfa_customer_credit_accounts` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `balance` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'CACHE of SUM(transactions.amount), refreshed under the row lock, never authoritative',
  `created` datetime NOT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer credit: per-user lock anchor + balance cache';

CREATE TABLE IF NOT EXISTS `#__alfa_customer_credit_transactions` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `entry_type` varchar(10) NOT NULL COMMENT 'credit | debit: display semantics; the SIGN of amount is the arithmetic truth',
  `amount` decimal(24,8) NOT NULL COMMENT 'SIGNED: credits positive, debits negative; balance = SUM(amount)',
  `source_type` varchar(32) NOT NULL DEFAULT 'manual' COMMENT 'credit_note | order | manual | promotion | ... - what produced this entry',
  `source_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'PK in the source domain (document id, order id, ...)',
  `id_order_payment` int(11) UNSIGNED DEFAULT NULL COMMENT 'The payments-ledger row this movement SPENDS or RETURNS money for. Set on a wallet spend and on every return of that spend, so "how much of payment P has gone back" is arithmetic (a SIGNED SUM) instead of a LIKE over note text. NULL on credit-note rows (those are counted on the source_type=credit_note axis) and on manual credit. No FK: payment rows are deletable and an orphan must read as unattributed, never block a delete',
  `note` varchar(500) NOT NULL DEFAULT '' COMMENT 'Human reason, mandatory for manual entries. DISPLAY ONLY: never parse it to decide money, that is what id_order_payment is for',
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = never expires. Policy: refund-sourced credit is customer money and must not auto-expire; promotional credit may. Enforcement = future task plugin (counter-entry at expiry, never row deletion)',
  `created` datetime NOT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`id_user`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_order_payment` (`id_order_payment`, `amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer credit ledger: append-only, the truth';

--
-- One-time action keys already used. A state-changing action claims its
-- key here before it runs; the primary key refuses a second claim of the
-- same key by the same user. Later claims remove rows past the retention.
--
CREATE TABLE IF NOT EXISTS `#__alfa_action_once` (
  `id_user` int(11) NOT NULL,
  `key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'sha256 hex of the key the request presented',
  `claimed` datetime NOT NULL,
  PRIMARY KEY (`id_user`, `key_hash`),
  KEY `idx_claimed` (`claimed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='One-time action keys already used, per user';
