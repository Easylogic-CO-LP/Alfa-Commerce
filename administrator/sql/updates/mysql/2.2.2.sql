-- Alfa Commerce 2.2.2: related products
--
-- The products a product's page shows as related, in the order staff set on the product's Related tab. A link goes
-- one way: a row from A to B shows B on A's page and nothing on B's. link_type names the kind of link: related is the
-- one kind core writes and reads; the column is there so that a further kind needs no schema change. The table is new
-- in 2.2.2 and empty on an updated site; the install SQL creates the same table, and this runs again harmlessly over
-- it (an update run again, a table an uninstall kept).

CREATE TABLE IF NOT EXISTS `#__alfa_items_links` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product whose page shows the link',
  `linked_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product linked to it, shown on its page; one direction only',
  `link_type` varchar(16) NOT NULL DEFAULT 'related' COMMENT 'The kind of link: related, the one kind core writes and reads; the column holds further kinds without a schema change',
  `ordering` int(11) NOT NULL DEFAULT 0 COMMENT 'Position on the page, from 1, in the order staff set',
  PRIMARY KEY (`item_id`, `linked_item_id`, `link_type`),
  KEY `idx_linked_item_id` (`linked_item_id`),
  KEY `idx_item_ordering` (`item_id`, `link_type`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product links by kind (related: the products a product page shows), in order';

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
