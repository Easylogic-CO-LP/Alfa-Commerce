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

-- Each cart rule's share of each order line, frozen at placement (the install SQL creates the same table and says what
-- it holds). An updated site starts with no rows: orders placed before keep their documents' sharing of a rule over the
-- goods, and none is filled in by guess. This runs again harmlessly over it.

CREATE TABLE IF NOT EXISTS `#__alfa_order_item_cart_rule` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL COMMENT 'Its order (#__alfa_orders.id)',
  `id_order_item` int(11) NOT NULL COMMENT 'The order line (#__alfa_order_items.id); the share is removed with it',
  `id_order_cart_rule` int(10) UNSIGNED NOT NULL COMMENT 'The order''s rule row (#__alfa_order_cart_rule.id_order_cart_rule) the share belongs to',
  `value_tax_excl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'The share net of tax',
  `value_tax_incl` decimal(24,8) NOT NULL DEFAULT 0.00000000 COMMENT 'The share as it came off the line, tax included',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_line_rule` (`id_order_item`, `id_order_cart_rule`),
  KEY `idx_order` (`id_order`),
  KEY `idx_order_cart_rule` (`id_order_cart_rule`),
  CONSTRAINT `fk_rule_share_order_item` FOREIGN KEY (`id_order_item`) REFERENCES `#__alfa_order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Each cart rule''s share of each order line, frozen at placement';
