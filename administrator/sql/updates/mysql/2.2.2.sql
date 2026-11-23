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

-- No stock transfers between locations

-- Nothing moves stock between locations, so nothing writes a transfer header or a movement's link to one. The headers'
-- table goes, and the movements lose the link and its key; the movements' type and the stock commands' log no longer
-- name the transfer types in their comments. A site with no transfer table or column left goes on: the table is
-- dropped only where it exists, and the key and the column drops carry the CAN FAIL marker. The install SQL makes the
-- same tables.

DROP TABLE IF EXISTS `#__alfa_inventory_transfers`;
ALTER TABLE `#__alfa_inventory_movements` DROP KEY `idx_transfer` /** CAN FAIL **/;
ALTER TABLE `#__alfa_inventory_movements` DROP COLUMN `transfer_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_inventory_movements` MODIFY `movement_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'receive | deduct | restore | adjust | migration';
ALTER TABLE `#__alfa_inventory_operations` MODIFY `command` varchar(20) NOT NULL COMMENT 'reserve | release | commit | restore | reverse_restore | receive | adjust | expire | reconcile | retire | location_state | unit_state | track_open | track_close | write_off | resolve_line | archive | unarchive | sale_rules | prioritise';
