-- Related products
--
-- The products a product's page shows as related, in the order staff set on the product's Related tab. A link goes
-- one way: a row from A to B shows B on A's page and nothing on B's. link_type names the kind of link: related is the
-- one kind core writes and reads; the column is there so that a further kind needs no schema change. The table is empty
-- on an updated site; the install SQL creates the same table, and this runs again harmlessly over
-- it (an update run again, a table an uninstall kept).

CREATE TABLE IF NOT EXISTS `#__alfa_items_links` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product whose page shows the link',
  `linked_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product linked to it, shown on its page; one direction only',
  `link_type` varchar(16) NOT NULL DEFAULT 'related' COMMENT 'The kind of link: related, the one kind core writes and reads; the column holds further kinds without a schema change',
  `linked_combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination of the linked product its card opens on; 0 for none',
  `ordering` int(11) NOT NULL DEFAULT 0 COMMENT 'Position on the page, from 1, in the order staff set',
  PRIMARY KEY (`item_id`, `linked_item_id`, `link_type`),
  KEY `idx_linked_item_id` (`linked_item_id`),
  KEY `idx_item_ordering` (`item_id`, `link_type`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product links by kind (related: the products a product page shows), in order';

--
-- The values a link opens the linked product's card with, one a field: a value of a dimension of the linked product's
-- combinations or the value of an option it offers. A link with values and no combination is a preselection; a
-- combination link may carry option values. A link whose combination or value is no longer the product's is removed
-- with its values by the save that removed it (RelatedItems::forgetVanished()).
--

CREATE TABLE IF NOT EXISTS `#__alfa_items_link_values` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The link''s product',
  `linked_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The link''s linked product',
  `link_type` varchar(16) NOT NULL DEFAULT 'related' COMMENT 'The link''s kind',
  `field_id` int(11) UNSIGNED NOT NULL COMMENT 'The item field of the value: a dimension of the linked product or the field of an option it offers',
  `value_id` int(11) UNSIGNED NOT NULL COMMENT 'The value chosen for that field on the card',
  PRIMARY KEY (`item_id`, `linked_item_id`, `link_type`, `field_id`),
  KEY `idx_value_id` (`value_id`),
  KEY `idx_linked_item_id` (`linked_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='The values a link preselects on the linked product''s card, one per field';

-- The other system id of an order
--
-- An order created by another system (a marketplace, an ERP, a connector) carries that system name in external_source
-- and its id of the order in external_reference, both or neither, written once. The pair is unique, so a connector that
-- sends the same order again after a lost answer is answered with the order it made and never makes a second one. A
-- row with neither column set holds no place in the unique key, so every existing order keeps NULL in both. Additions
-- only; each statement carries the CAN FAIL marker, so an update run again finds the column or the key and goes on.
-- The install SQL creates the same columns and key.

ALTER TABLE `#__alfa_orders` ADD COLUMN `external_source` varchar(32) DEFAULT NULL COMMENT 'The other system the order came from: lower-case letters, digits, _ and -; NULL = none. Written with external_reference, once' AFTER `source` /** CAN FAIL **/;
ALTER TABLE `#__alfa_orders` ADD COLUMN `external_reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'The order id in the system external_source names, compared exactly; NULL = none. Unique with external_source' AFTER `external_source` /** CAN FAIL **/;
ALTER TABLE `#__alfa_orders` ADD UNIQUE KEY `idx_external_reference` (`external_source`, `external_reference`) /** CAN FAIL **/;

-- The source of an order a REST API client creates
--
-- A client of the REST API names the channel an order comes from (for example base for a marketplace connector), up to
-- 32 characters, where every such order read api before. The column is widened from 16 to 32 characters; the values
-- stored are kept, and the statement can run again. The install SQL has the same width.

ALTER TABLE `#__alfa_orders` MODIFY `source` varchar(32) NOT NULL DEFAULT 'storefront' COMMENT 'Order origin: storefront | admin | renewal | api, or the channel a REST API client names (lower-case letters, digits, _ and -)';

-- When a product stock or prices last changed
--
-- A stock move or a price row written does not touch a product modified time, so an integrator that keeps stock and
-- prices in step had to read every product. commerce_changed_at records, in UTC to the microsecond, when the product
-- stock, its price rows, a price period that began, or the product itself last changed, written in the same
-- transaction as the change; the REST API filter[changed_since] reads it on its key. The two additions carry the CAN
-- FAIL marker, so an update run again finds them and goes on. The products already in the shop are then marked changed
-- at the moment of the update, once: a client that reads the changes since a time before the update reads every
-- product once, and none is missed. The install SQL creates the same column and key.

ALTER TABLE `#__alfa_items` ADD COLUMN `commerce_changed_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC: when the product stock, prices, a price period that began, or the product itself last changed; the REST API filter[changed_since]' AFTER `modified` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items` ADD KEY `idx_commerce_changed` (`commerce_changed_at`) /** CAN FAIL **/;
UPDATE `#__alfa_items` SET `commerce_changed_at` = UTC_TIMESTAMP(6) WHERE `commerce_changed_at` IS NULL;

-- The address an image was fetched from
--
-- A REST API client may give an image as an address ({"type": "image", "url": "https://..."}): the shop fetches it and
-- stores it as an upload. The media row keeps the address (source_url, for the integrator) and its sha256
-- (source_url_hash), so the same address is not fetched twice for one record. Additions only, NULL on every row an
-- upload or a pick made; each carries the CAN FAIL marker, so an update run again goes on. The install SQL has them.

ALTER TABLE `#__alfa_media` ADD COLUMN `source_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'The address the image was fetched from, as the REST API client gave it; NULL for an image uploaded or picked' /** CAN FAIL **/;
ALTER TABLE `#__alfa_media` ADD COLUMN `source_url_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'sha256 of source_url: the same address is not fetched twice for one record' AFTER `source_url` /** CAN FAIL **/;
ALTER TABLE `#__alfa_media` ADD KEY `idx_alfa_media_source` (`origin`, `item_id`, `source_url_hash`) /** CAN FAIL **/;

-- One SKU, one unit

-- Every SKU in use is kept once in a registry: a product's own SKU and each combination's, archived ones included,
-- compared without regard to letter case. A save that gives a product or a variant a SKU another one holds is refused,
-- and the REST API finds a unit by its SKU. The update fills the registry after this file runs (script.php); a SKU held
-- by more than one unit is left out and named, until each has its own. The install SQL creates the same table.

CREATE TABLE IF NOT EXISTS `#__alfa_skus` (
  `sku` varchar(255) NOT NULL COMMENT 'A SKU in use, trimmed; compared without regard to letter case',
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product',
  `combination_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination; 0 for the product itself',
  PRIMARY KEY (`sku`),
  UNIQUE KEY `uq_unit` (`item_id`, `combination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Every SKU in use, once: products and combinations, archived ones included';

-- A write sent twice is applied once

-- The REST API's writes that move stock take an Idempotency-Key: the same request sent again with the same key is
-- answered with what the first one stored and applies nothing, and the same key with another body is refused. The keys
-- are kept 30 days. The install SQL creates the same table.

CREATE TABLE IF NOT EXISTS `#__alfa_api_keys` (
  `created_by` int(11) NOT NULL COMMENT 'The API user whose key it is',
  `scope` varchar(32) NOT NULL COMMENT 'The write the key names, such as inventory.adjustments',
  `key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'sha256 hex of the key the client sent',
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'sha256 hex of the request body in its canonical form',
  `result` text DEFAULT NULL COMMENT 'JSON: what the write answered (the ids and operation ids it wrote), to answer a replay',
  `created` datetime NOT NULL,
  PRIMARY KEY (`created_by`, `scope`, `key_hash`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Idempotency keys of REST API writes, kept 30 days';

-- A value's code

-- An item field's value gets a machine key of its own, unique in its field: an ERP names a value by its field's
-- field_name and the value's code instead of an id. Codes start empty; staff (the field's Values tab) or the REST API set
-- them. A site that ran an earlier copy of this file gets the column and the key from script.php. The install SQL has
-- the same column and key.

ALTER TABLE `#__alfa_item_field_values` ADD COLUMN `code` varchar(64) DEFAULT NULL COMMENT 'The value''s machine key, unique in its field (a to z, 0 to 9, - and _); NULL = none' AFTER `item_field_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_item_field_values` ADD UNIQUE KEY `uq_field_code` (`item_field_id`, `code`) /** CAN FAIL **/;
