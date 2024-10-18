--
-- Table structure for table `#__alfa_cart`
--

CREATE TABLE IF NOT EXISTS `#__alfa_cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_shop_group` int(11) NOT NULL,
  `id_carrier` int(11) NOT NULL,
  `delivery_option` text NOT NULL,
  `id_lang` int(11) NOT NULL,
  `id_address_delivery` int(11) NOT NULL,
  `id_address_invoice` int(11) NOT NULL,
  `id_currency` int(11) NOT NULL,
  `id_customer` int(11) NOT NULL,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  `recognize_key` int(11) NOT NULL,
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
  `date_add` datetime NOT NULL DEFAULT current_timestamp()
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
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
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
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `state` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `code` varchar(255) DEFAULT '',
  `name` varchar(255) NOT NULL,
  `symbol` varchar(255) DEFAULT '',
  `number` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
-- Table structure for table `#__alfa_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `short_desc` text DEFAULT NULL,
  `full_desc` text DEFAULT NULL,
  `sku` varchar(255) DEFAULT '',
  `gtin` varchar(255) DEFAULT '',
  `mpn` varchar(255) DEFAULT '',
  `stock` float DEFAULT 0,
  `stock_action` tinyint(1) DEFAULT 0,
  `manage_stock` tinyint(1) DEFAULT 2,
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
  `modify` tinyint(1) NOT NULL DEFAULT 0,
  `modify_function` enum('add','remove') DEFAULT 'add',
  `modify_type` enum('amount','percentage') DEFAULT 'amount',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `id_user_group` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `id_cart` int(11) NOT NULL,
  `id_currency` int(11) NOT NULL,
  `id_address_delivery` int(11) NOT NULL,
  `id_address_invoice` int(11) NOT NULL,
  `id_paymentmethod` int(11) NOT NULL,
  `id_shipmentmethod` int(11) NOT NULL,
  `id_order_status` int(11) NOT NULL,
  `id_payment_currency` int(11) NOT NULL,
  `id_language` int(11) NOT NULL,
  `id_shipping_carrier` int(11) NOT NULL,
  `id_coupon` int(11) NOT NULL,
  `code_coupon` int(11) NOT NULL,
  `original_price` float NOT NULL,
  `payed_price` float NOT NULL,
  `total_shipping` float NOT NULL,
  `ip_address` varchar(255) NOT NULL,
  `shipping_tracking_number` varchar(255) NOT NULL,
  `payment_status` varchar(255) NOT NULL,
  `customer_note` text NOT NULL,
  `note` text NOT NULL,
  `checked_out` int(11) DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `modified` datetime NOT NULL,
  `modified_by` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `checked_out` (`checked_out`),
  KEY `modified_by` (`modified_by`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_items`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` float NOT NULL,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_item` (`id_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_order_user_info`
--

CREATE TABLE IF NOT EXISTS `#__alfa_order_user_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `shipping_address` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `state` varchar(255) NOT NULL,
  `zip_code` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_payments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_payments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
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
-- Table structure for table `#__alfa_places`
--

CREATE TABLE IF NOT EXISTS `#__alfa_places` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ordering` int(11) DEFAULT 0,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `state` tinyint(1) NOT NULL DEFAULT 1,
  `number` double NOT NULL DEFAULT 0,
  `parent_id` int(11) DEFAULT 0,
  `code2` varchar(2) NOT NULL,
  `code3` varchar(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_settings`
--

CREATE TABLE IF NOT EXISTS `#__alfa_settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `checked_out` int(11) UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `modified_by` int(11) DEFAULT 0,
  `currency` int(10) NOT NULL DEFAULT 0,
  `currency_display` varchar(255) DEFAULT '00_Symb',
  `terms_accept` varchar(255) DEFAULT '1',
  `allow_guests` varchar(255) DEFAULT '1',
  `manage_stock` tinyint(1) DEFAULT 0,
  `stock_action` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_currency` (`currency`),
  KEY `idx_manage_stock` (`manage_stock`),
  KEY `idx_stock_action` (`stock_action`),
  KEY `idx_checked_out` (`checked_out`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `#__alfa_shipments`
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
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