-- Alfa Commerce 2.2.2: related products
--
-- The products a product's page shows as related, in the order staff set on the product's Related tab. A link goes
-- one way: a row from A to B shows B on A's page and nothing on B's. The table is new in 2.2.2 and empty on an updated
-- site; the install SQL creates the same table, and this runs again harmlessly over it (an update run again, a table
-- an uninstall kept).

CREATE TABLE IF NOT EXISTS `#__alfa_items_related` (
  `item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product whose page shows the link',
  `related_item_id` int(11) UNSIGNED NOT NULL COMMENT 'The product shown; one direction only',
  `ordering` int(11) NOT NULL DEFAULT 0 COMMENT 'Position on the page, from 1, in the order staff set',
  PRIMARY KEY (`item_id`, `related_item_id`),
  KEY `idx_related_item_id` (`related_item_id`),
  KEY `idx_item_ordering` (`item_id`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Related products: which products a product page shows, in order';
