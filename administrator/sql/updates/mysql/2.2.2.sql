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
