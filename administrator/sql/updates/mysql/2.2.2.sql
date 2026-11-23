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
  `ordering` int(11) NOT NULL DEFAULT 0 COMMENT 'Position on the page, from 1, in the order staff set',
  PRIMARY KEY (`item_id`, `linked_item_id`, `link_type`),
  KEY `idx_linked_item_id` (`linked_item_id`),
  KEY `idx_item_ordering` (`item_id`, `link_type`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product links by kind (related: the products a product page shows), in order';

-- Customers may cancel their orders in a status
--
-- customer_can_cancel marks the statuses in which a customer may cancel their own order from their account. Every
-- status a shop already has gets 0, so no shop offers its customers the cancel after the update until it marks a status
-- and names the status a cancelled order moves to (Options, Cart, Customer account). A new shop has its Pending status
-- marked by the install SQL. An addition only; the statement carries the CAN FAIL marker, so an update run again finds
-- the column and goes on. The install SQL creates the same column.

ALTER TABLE `#__alfa_orders_statuses` ADD COLUMN `customer_can_cancel` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1, a customer may cancel their own order in this status from their account; the order moves to the status Options name (customer_cancel_status). Never inferred from the name or the role flags.' AFTER `is_completed` /** CAN FAIL **/;
