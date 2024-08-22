CREATE TABLE IF NOT EXISTS `#__alfa_manufacturers` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`name` VARCHAR(255)  NOT NULL ,
`alias` VARCHAR(255) COLLATE utf8_bin NULL ,
`desc` TEXT NULL ,
`meta_title` VARCHAR(255)  NULL  DEFAULT "",
`meta_desc` TEXT NULL ,
`website` VARCHAR(255)  NULL  DEFAULT "",
`state` TINYINT(1)  NULL  DEFAULT 1,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified` DATETIME NOT NULL,
`modified_by` INT(11)  NULL  DEFAULT 0,
`ordering` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
,KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_categories` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`parent_id` INT(11)  NULL  DEFAULT 0,
`name` VARCHAR(400)  NOT NULL ,
`desc` TEXT NULL ,
`alias` VARCHAR(255) COLLATE utf8_bin NULL ,
`meta_title` VARCHAR(255)  NULL  DEFAULT "",
`meta_desc` TEXT NULL ,
`state` TINYINT(1)  NULL  DEFAULT 1,
`publish_up` datetime DEFAULT NULL,
`publish_down` datetime DEFAULT NULL,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified` datetime NOT NULL,
`modified_by` INT(11)  NULL  DEFAULT 0,
`ordering` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_parent_id` (`parent_id`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
,KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_categories_users` (
    `category_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    KEY (`category_id`, `user_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_categories_usergroups` (
    `category_id` INT(11) NOT NULL,
    `usergroup_id` INT(11) NOT NULL,
    KEY (`category_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`name` VARCHAR(255)  NOT NULL ,
`short_desc` TEXT NULL ,
`full_desc` TEXT NULL ,
`sku` VARCHAR(255)  NULL  DEFAULT "",
`gtin` VARCHAR(255)  NULL  DEFAULT "",
`mpn` VARCHAR(255)  NULL  DEFAULT "",
`stock` FLOAT NULL  DEFAULT 0,
`stock_action` TINYINT(1)  NULL  DEFAULT 0,
`manage_stock` TINYINT(1)  NULL  DEFAULT 2,
`alias` VARCHAR(255) COLLATE utf8_bin NULL ,
`meta_title` VARCHAR(255)  NULL  DEFAULT "",
`meta_desc` TEXT NULL ,
`state` TINYINT(1)  NULL  DEFAULT 1,
`publish_up` datetime DEFAULT NULL,
`publish_down` datetime DEFAULT NULL,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified` datetime NOT NULL,
`modified_by` INT(11)  NULL  DEFAULT 0,
`ordering` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items_users` (
    `item_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    KEY (`item_id`, `user_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items_usergroups` (
    `item_id` INT(11) NOT NULL,
    `usergroup_id` INT(11) NOT NULL,
    KEY (`item_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items_prices` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`value` DOUBLE NULL DEFAULT 0,
`override` DOUBLE NULL DEFAULT 0,
`start_date` DATETIME NULL  DEFAULT NULL ,
`quantity_start` FLOAT NULL  DEFAULT 0,
`end_date` DATETIME NULL  DEFAULT NULL ,
`quantity_end` FLOAT NULL  DEFAULT 0,
`tax_id` INT(11)  NULL  DEFAULT 0,
`discount_id` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items_manufacturers` (
`product_id` INT(11)  NULL  DEFAULT 0,
`manufacturer_id` INT(11)  NULL  DEFAULT 0,
KEY `idx_product_id` (`product_id`),
KEY `idx_manufacturer_id` (`manufacturer_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_items_categories` (
`product_id` INT(11)  NULL  DEFAULT 0,
`category_id` INT(11)  NULL  DEFAULT 0,
KEY `idx_product_id` (`product_id`),
KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `#__alfa_users` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_usergroups` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`prices_display` VARCHAR(255)  NULL  DEFAULT "",
`name` VARCHAR(255)  NOT NULL ,
`prices_enable` VARCHAR(255)  NULL  DEFAULT "0",
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_customs` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`type` VARCHAR(255)  NULL  DEFAULT "",
`name` VARCHAR(255)  NOT NULL ,
`desc` TEXT NULL ,
`required` VARCHAR(255)  NULL  DEFAULT "",
`categories` TEXT NULL ,
`items` TEXT NULL ,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_currencies` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`number` DOUBLE NOT NULL DEFAULT 0,
`code` VARCHAR(255)  NULL  DEFAULT "",
`name` VARCHAR(255)  NOT NULL ,
`symbol` VARCHAR(255)  NULL  DEFAULT "",
`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_coupons` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`modified` datetime NOT NULL,
`coupon_code` VARCHAR(255)  NOT NULL ,
`num_of_uses` DOUBLE NOT NULL DEFAULT 0,
`value_type` VARCHAR(255)  NULL  DEFAULT "0",
`value` DECIMAL(12,2)  NOT NULL ,
`min_value` DOUBLE NULL ,
`max_value` DOUBLE NULL DEFAULT 0,
`hidden` VARCHAR(255)  NULL  DEFAULT "0",
`publish_up` DATETIME NULL  DEFAULT NULL ,
`publish_down` DATETIME NULL  DEFAULT NULL ,
`associate_to_new_users` VARCHAR(255)  NULL  DEFAULT "",
`user_associated` VARCHAR(255)  NULL  DEFAULT "0",
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_coupons_users` (
    `coupon_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    KEY (`coupon_id`, `user_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_coupons_usergroups` (
    `coupon_id` INT(11) NOT NULL,
    `usergroup_id` INT(11) NOT NULL,
    KEY (`coupon_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_shipments` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`name` VARCHAR(255)  NOT NULL ,
`state` TINYINT(1)  NULL  DEFAULT 1,
PRIMARY KEY (`id`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
,KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_payments` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`name` VARCHAR(255)  NOT NULL ,
`state` TINYINT(1)  NULL  DEFAULT 1,
PRIMARY KEY (`id`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
,KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_places` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`name` VARCHAR(255)  NOT NULL ,
`number` DOUBLE NOT NULL DEFAULT 0,
`parent_id` INT(11)  NULL  DEFAULT 0,
`state` TINYINT(1)  NOT NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`modified` datetime NOT NULL,
`code2` VARCHAR(2)  NOT NULL ,
`code3` VARCHAR(3)  NOT NULL ,
PRIMARY KEY (`id`)
,KEY `idx_parent_id` (`parent_id`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
,KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_places_users` (
    `place_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    KEY (`place_id`, `user_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_places_usergroups` (
    `place_id` INT(11) NOT NULL,
    `usergroup_id` INT(11) NOT NULL,
    KEY (`place_id`, `usergroup_id`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_settings` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`currency` INT(10)  NOT NULL  DEFAULT 0,
`currency_display` VARCHAR(255)  NULL  DEFAULT "00_Symb",
`terms_accept` VARCHAR(255)  NULL  DEFAULT "1",
`allow_guests` VARCHAR(255)  NULL  DEFAULT "1",
`manage_stock` TINYINT(1)  NULL  DEFAULT 0,
`stock_action` TINYINT(1)  NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_currency` (`currency`)
,KEY `idx_manage_stock` (`manage_stock`)
,KEY `idx_stock_action` (`stock_action`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__alfa_orders` (
`id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,

`state` TINYINT(1)  NULL  DEFAULT 1,
`ordering` INT(11)  NULL  DEFAULT 0,
`checked_out` INT(11)  UNSIGNED,
`checked_out_time` DATETIME NULL  DEFAULT NULL ,
`created_by` INT(11)  NULL  DEFAULT 0,
`modified_by` INT(11)  NULL  DEFAULT 0,
`currency` VARCHAR(255)  NULL  DEFAULT "",
`payment` INT NULL  DEFAULT 0,
`total` FLOAT NULL  DEFAULT 0,
PRIMARY KEY (`id`)
,KEY `idx_state` (`state`)
,KEY `idx_payment` (`payment`)
,KEY `idx_checked_out` (`checked_out`)
,KEY `idx_created_by` (`created_by`)
,KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT COLLATE=utf8mb4_unicode_ci;


INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Manufacturer','com_alfa.manufacturer','{"special":{"dbtable":"#__alfa_manufacturers","key":"id","type":"ManufacturerTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/manufacturer.xml", "hideFields":["checked_out","checked_out_time","params","language" ,"meta_desc"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.manufacturer')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Category','com_alfa.category','{"special":{"dbtable":"#__alfa_categories","key":"id","type":"CategoryTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/category.xml", "hideFields":["checked_out","checked_out_time","params","language" ,"meta_desc"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.category')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Item','com_alfa.item','{"special":{"dbtable":"#__alfa_items","key":"id","type":"ItemTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/item.xml", "hideFields":["checked_out","checked_out_time","params","language" ,"meta_desc"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.item')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Custom','com_alfa.custom','{"special":{"dbtable":"#__alfa_customs","key":"id","type":"CustomTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/custom.xml", "hideFields":["checked_out","checked_out_time","params","language" ,"desc"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.custom')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Shipment Method','com_alfa.shipment','{"special":{"dbtable":"#__alfa_shipments","key":"id","type":"ShipmentTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/shipment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.shipment')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Payment Method','com_alfa.payment','{"special":{"dbtable":"#__alfa_payments","key":"id","type":"PaymentTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/payment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.payment')
) LIMIT 1;

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `rules`, `field_mappings`, `content_history_options`)
SELECT * FROM ( SELECT 'Setting','com_alfa.setting','{"special":{"dbtable":"#__alfa_settings","key":"id","type":"SettingTable","prefix":"Joomla\\\\Component\\\\Alfa\\\\Administrator\\\\Table\\\\"}}', CASE 
                                    WHEN 'rules' is null THEN ''
                                    ELSE ''
                                    END as rules, CASE 
                                    WHEN 'field_mappings' is null THEN ''
                                    ELSE ''
                                    END as field_mappings, '{"formFile":"administrator\/components\/com_alfa\/forms\/setting.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"currency","targetTable":"#__alfa_currencies","targetColumn":"id","displayColumn":"name"}]}') AS tmp
WHERE NOT EXISTS (
    SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.setting')
) LIMIT 1;
