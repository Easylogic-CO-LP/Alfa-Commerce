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

-- Outgoing notifications: who the shop tells of its changes (a webhook address, or an alfa plugin that delivers
-- through its own client), and what it owes each of them. A delivery row is written in the transaction of the change
-- it tells, so a rolled back change owes nothing; the outbound.deliver job of the task runner sends it, retries a
-- failure on its backoff and gives up after the sixth retry (state dead, shown in Tools, Tasks). A stock or price row
-- waits once per unit and topic (pending 1); its values are read when it is sent, so the newest figure goes out.
CREATE TABLE IF NOT EXISTS `#__alfa_outbound_consumers` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL COMMENT 'webhook: core posts signed JSON to url; plugin: an alfa plugin delivers through its own client',
  `plugin` varchar(100) NULL DEFAULT NULL COMMENT 'kind plugin: the delivering plugin as group.element; NULL for a webhook',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'What staff read on the Webhooks screen and in Tools, Tasks',
  `url` varchar(2048) NOT NULL DEFAULT '' COMMENT 'kind webhook: the https address the changes are posted to',
  `secret` varchar(255) NOT NULL DEFAULT '' COMMENT 'kind webhook: the signing key; shown once when made, never read back or written to a log',
  `topics` varchar(255) NOT NULL DEFAULT '' COMMENT 'Comma list of stock, price, order.created, order.status',
  `state` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 delivering; 0 paused: nothing new is queued and nothing waiting is sent or lost',
  `last_attempt_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC, the last delivery attempt or test',
  `last_ok` tinyint(1) NULL DEFAULT NULL COMMENT '1 when the last attempt was taken, 0 when it failed, NULL before the first',
  `last_result` varchar(500) NOT NULL DEFAULT '' COMMENT 'What the last attempt answered: the HTTP status, or why it failed',
  `created` datetime NOT NULL,
  `created_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_plugin` (`plugin`),
  KEY `idx_kind_state` (`kind`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Who the shop tells of its changes: a webhook address or an alfa plugin';

CREATE TABLE IF NOT EXISTS `#__alfa_outbound_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `consumer_id` int(11) UNSIGNED NOT NULL COMMENT 'The consumer told',
  `topic` varchar(32) NOT NULL COMMENT 'stock, price, order.created or order.status',
  `subject_id` int(11) UNSIGNED NOT NULL COMMENT 'The product (stock, price) or the order (order.created, order.status)',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The combination for stock and price; 0 for a product without combinations, and for an order',
  `payload` varchar(255) NOT NULL DEFAULT '' COMMENT 'order.status: the status left and the status entered as from:to; empty otherwise, values are read when sent',
  `pending` tinyint(1) NULL DEFAULT NULL COMMENT '1 while a stock or price row waits, NULL otherwise: one waiting row a consumer, topic and unit',
  `state` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent or dead',
  `attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Delivery attempts that failed',
  `next_attempt_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC, not before this; NULL: due now',
  `last_error` varchar(500) NOT NULL DEFAULT '' COMMENT 'Why the last attempt failed, for staff',
  `created` datetime(6) NOT NULL COMMENT 'UTC, written with the change it tells',
  `sent_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC, when the consumer took it',
  `dead_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC, when the shop gave up on it or staff discarded it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_waiting` (`consumer_id`, `topic`, `subject_id`, `unit_id`, `pending`),
  KEY `idx_due` (`consumer_id`, `state`, `next_attempt_at`, `id`),
  KEY `idx_state_due` (`state`, `next_attempt_at`),
  KEY `idx_subject` (`consumer_id`, `subject_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='What the shop owes each consumer, written in the transaction of the change';
