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

-- A payment method's fee: the tax rules it is taxed with (the fee's three columns on #__alfa_payments are added by the
-- installation's new-columns step). No row = an untaxed fee. The install SQL creates the same table.

CREATE TABLE IF NOT EXISTS `#__alfa_payment_fee_taxes` (
  `payment_id` int(11) NOT NULL,
  `tax_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`, `tax_id`),
  KEY `idx_tax_id` (`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The categories a shipping method does not deliver. No row = none; an updated shop starts with none, so every method
-- serves what it served. The install SQL creates the same table.

CREATE TABLE IF NOT EXISTS `#__alfa_shipment_excluded_categories` (
  `shipment_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`shipment_id`, `category_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
