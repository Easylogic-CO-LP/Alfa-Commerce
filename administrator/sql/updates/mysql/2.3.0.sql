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

-- Card disputes
--
-- One record per dispute (a case), tied to the disputed payment; the money moves only through the chargeback and
-- reversal rows the dispute ledger books at a decision, each naming its case in id_dispute. The install SQL creates the
-- same table and column; on an updated site the statements below make one record for every chargeback row written
-- before the table existed, so no chargeback stands without its case. Each statement runs again harmlessly: the table
-- and the column exist, and a row already joined to its record is never read again. A site that ran an earlier copy of
-- this file gets the column from script.php.

CREATE TABLE IF NOT EXISTS `#__alfa_order_disputes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL COMMENT 'The order of the disputed payment',
  `id_payment` int(11) NOT NULL COMMENT 'The disputed payment: #__alfa_order_payments.id, a row of type payment',
  `payment_method_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'The plugin element of the disputed payment, copied when the record is made: the one gateway that may write it',
  `source` enum('staff','provider','migrated') NOT NULL COMMENT 'Who made the record: staff by hand, a gateway from its provider, or the update from an older chargeback row',
  `joined` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 once the record follows a provider word',
  `state` enum('inquiry','open','under_review','won','lost','closed') NOT NULL,
  `outcome` enum('won','lost') DEFAULT NULL COMMENT 'The bank''s last decision; kept by a reopen; NULL when none was made or a record by hand was closed in error. The money follows it, never the state',
  `closed_reason` enum('inquiry_ended','merged','in_error') DEFAULT NULL COMMENT 'Only for state closed',
  `merged_into` int(11) DEFAULT NULL COMMENT 'closed as merged: the record this one is the same case as',
  `stage` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'The round: 1, then one more each time a won or lost case opens again',
  `reference` mediumtext DEFAULT NULL COMMENT 'The provider''s dispute id or the bank''s case number, complete; NULL when none is known',
  `reference_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (IF(OCTET_LENGTH(`reference`) > 0, SHA2(`reference`, 256), NULL)) STORED COMMENT 'Lowercase hex SHA-256 of reference; the lookup key, never the identity',
  `id_currency` int(10) UNSIGNED NOT NULL COMMENT 'The order''s currency',
  `currency_scale` tinyint(1) UNSIGNED NOT NULL COMMENT 'The order''s frozen scale; amount is read at it',
  `amount` decimal(24,8) DEFAULT NULL COMMENT 'The disputed amount in the order''s currency; NULL while only another currency''s figure is known',
  `stated_amount` decimal(24,8) NOT NULL COMMENT 'The disputed amount as the provider or the bank states it',
  `stated_currency` char(3) NOT NULL COMMENT 'ISO 4217 code of stated_amount',
  `reason_code` enum('fraud','not_received','not_as_described','duplicate','credit_not_processed','cancelled','unrecognised','other') NOT NULL DEFAULT 'other',
  `reason_text` varchar(255) NOT NULL DEFAULT '' COMMENT 'The provider''s own reason, as given',
  `evidence_due_by` datetime DEFAULT NULL COMMENT 'UTC',
  `evidence_sent_at` datetime DEFAULT NULL COMMENT 'UTC',
  `fee_amount` decimal(24,8) DEFAULT NULL COMMENT 'The provider''s fee, in fee_currency',
  `fee_currency` char(3) DEFAULT NULL,
  `fee_returned` decimal(24,8) DEFAULT NULL COMMENT 'The part of the fee given back, in fee_currency',
  `provider_link` varchar(2048) NOT NULL DEFAULT '' COMMENT 'https address of the case at the provider',
  `waits_for` enum('same_payment','amount_unknown') DEFAULT NULL COMMENT 'What staff must decide before the record books money',
  `bookings` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Money rows booked so far; numbers the core references dispute:<id>:<n>',
  `provider_as_of` datetime(6) DEFAULT NULL COMMENT 'UTC time of the last provider word applied; an older word is not applied',
  `gateway_response` mediumtext DEFAULT NULL COMMENT 'The last provider payload, for staff and support',
  `note` varchar(255) NOT NULL DEFAULT '',
  `decided_at` datetime DEFAULT NULL COMMENT 'UTC of the last outcome',
  `created` datetime NOT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0 COMMENT '0 for a provider word',
  `modified` datetime NOT NULL,
  `modified_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_payment_reference` (`id_payment`, `reference_hash`),
  KEY `idx_order` (`id_order`),
  KEY `idx_state_due` (`state`, `evidence_due_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Card disputes: one case a row, tied to the disputed payment; money moves only through the payment rows it books';

ALTER TABLE `#__alfa_order_payments` ADD COLUMN `id_dispute` int(11) DEFAULT NULL COMMENT 'Chargeback or reversal: the dispute that booked this row; written once, by the ledger' AFTER `id_reversed_payment` /** CAN FAIL **/;

ALTER TABLE `#__alfa_order_payments` ADD KEY `idx_id_dispute` (`id_dispute`) /** CAN FAIL **/;

-- A record for each chargeback row with none, holding what stands: the row's amount less its completed reversals.
-- Lost while any of it stands; won when it was reversed in full, the record then naming the whole amount, which books
-- nothing. The round is 1; the record's first save compares what it books with what its rows book, so it writes no new
-- money row. Joined to its chargeback row by payment and reference (a chargeback's reference is unique per payment, the
-- rule the ledger keeps). A chargeback is booked only on an order with a frozen scale, so every such row has one.

INSERT INTO `#__alfa_order_disputes`
  (`id_order`, `id_payment`, `payment_method_type`, `source`, `joined`, `state`, `outcome`, `stage`, `reference`,
   `id_currency`, `currency_scale`, `amount`, `stated_amount`, `stated_currency`, `reason_code`, `reason_text`,
   `provider_link`, `bookings`, `note`, `decided_at`, `created`, `created_by`, `modified`, `modified_by`)
SELECT cb.`id_order`, cb.`id_refunded_payment`, p.`payment_method_type`, 'migrated', 0,
       IF(ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0) > 0, 'lost', 'won'),
       IF(ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0) > 0, 'lost', 'won'),
       1, cb.`transaction_id`, o.`id_currency`, o.`currency_scale`,
       IF(ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0) > 0, ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0), ABS(cb.`amount`)),
       IF(ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0) > 0, ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0), ABS(cb.`amount`)),
       c.`code`, 'other', '', '', 1 + COALESCE(rv.`n`, 0), '',
       IF(ABS(cb.`amount`) - COALESCE(rv.`reversed`, 0) > 0, COALESCE(cb.`processed_at`, cb.`added`), COALESCE(rv.`last_at`, cb.`processed_at`, cb.`added`)),
       cb.`added`, COALESCE(cb.`id_employee`, 0), cb.`added`, 0
  FROM `#__alfa_order_payments` cb
  JOIN `#__alfa_order_payments` p ON p.`id` = cb.`id_refunded_payment`
  JOIN `#__alfa_orders` o ON o.`id` = cb.`id_order`
  JOIN `#__alfa_currencies` c ON c.`id` = o.`id_currency`
  LEFT JOIN (SELECT `id_reversed_payment`, SUM(ABS(`amount`)) AS `reversed`, COUNT(*) AS `n`,
                    MAX(COALESCE(`processed_at`, `added`)) AS `last_at`
               FROM `#__alfa_order_payments`
              WHERE `payment_type` = 'reversal' AND `transaction_status` = 'completed'
              GROUP BY `id_reversed_payment`) rv ON rv.`id_reversed_payment` = cb.`id`
 WHERE cb.`payment_type` = 'chargeback'
   AND cb.`transaction_status` = 'completed'
   AND cb.`id_dispute` IS NULL
   AND o.`currency_scale` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `#__alfa_order_disputes` d
                    WHERE d.`id_payment` = cb.`id_refunded_payment` AND d.`reference_hash` = cb.`transaction_id_hash`);

-- The chargeback row names its record, joined by payment and reference.

UPDATE `#__alfa_order_payments` cb
  JOIN `#__alfa_order_disputes` d
    ON d.`id_payment` = cb.`id_refunded_payment` AND d.`reference_hash` = cb.`transaction_id_hash` AND d.`source` = 'migrated'
   SET cb.`id_dispute` = d.`id`
 WHERE cb.`payment_type` = 'chargeback' AND cb.`id_dispute` IS NULL;

-- Its reversals name the same record, joined by the row they reverse (a reversal carries its own event's reference,
-- never the chargeback's).

UPDATE `#__alfa_order_payments` rv
  JOIN `#__alfa_order_payments` cb ON cb.`id` = rv.`id_reversed_payment`
   SET rv.`id_dispute` = cb.`id_dispute`
 WHERE rv.`payment_type` = 'reversal' AND rv.`id_dispute` IS NULL
   AND cb.`payment_type` = 'chargeback' AND cb.`id_dispute` IS NOT NULL;

-- A record made from a partly reversed chargeback says so on the order's timeline, once.

INSERT INTO `#__alfa_order_activity_log` (`id_order`, `id_employee`, `employee_name`, `event`, `entity_id`, `summary`, `context`, `created`)
SELECT d.`id_order`, 0, '', 'dispute.migrated', d.`id`,
       CONCAT('Dispute #', d.`id`, ' made from chargeback #', cb.`id`, ', partly reversed before: ',
              FORMAT(ABS(cb.`amount`) - d.`amount`, d.`currency_scale`), ' reversed, ', FORMAT(d.`amount`, d.`currency_scale`), ' stands'),
       JSON_OBJECT('dispute', d.`id`, 'chargeback', cb.`id`, 'payment', d.`id_payment`,
                   'reversed', CAST(ABS(cb.`amount`) - d.`amount` AS CHAR), 'stands', CAST(d.`amount` AS CHAR)),
       UTC_TIMESTAMP()
  FROM `#__alfa_order_disputes` d
  JOIN `#__alfa_order_payments` cb ON cb.`id_dispute` = d.`id` AND cb.`payment_type` = 'chargeback'
 WHERE d.`source` = 'migrated' AND d.`outcome` = 'lost' AND d.`amount` < ABS(cb.`amount`)
   AND NOT EXISTS (SELECT 1 FROM `#__alfa_order_activity_log` a WHERE a.`event` = 'dispute.migrated' AND a.`entity_id` = d.`id`);

-- The other system id of an order
--
-- An order created by another system (a marketplace, an ERP, a connector) carries that system name in external_source
-- and its id of the order in external_reference, both or neither, written once. The pair is unique, so a connector that
-- sends the same order again after a lost answer is answered with the order it made and never makes a second one. A
-- row with neither column set holds no place in the unique key, so every existing order keeps NULL in both. Additions
-- only; each statement carries the CAN FAIL marker, so an update run again finds the column or the key and goes on.
-- A site that ran an earlier copy of this file gets the columns from script.php. The install SQL creates the same
-- columns and key.

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
-- product once, and none is missed. A site that ran an earlier copy of this file gets the column from script.php. The
-- install SQL creates the same column and key.

ALTER TABLE `#__alfa_items` ADD COLUMN `commerce_changed_at` datetime(6) NULL DEFAULT NULL COMMENT 'UTC: when the product stock, prices, a price period that began, or the product itself last changed; the REST API filter[changed_since]' AFTER `modified` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items` ADD KEY `idx_commerce_changed` (`commerce_changed_at`) /** CAN FAIL **/;
UPDATE `#__alfa_items` SET `commerce_changed_at` = UTC_TIMESTAMP(6) WHERE `commerce_changed_at` IS NULL;

-- The address an image was fetched from
--
-- A REST API client may give an image as an address ({"type": "image", "url": "https://..."}): the shop fetches it and
-- stores it as an upload. The media row keeps the address (source_url, for the integrator) and its sha256
-- (source_url_hash), so the same address is not fetched twice for one record. Additions only, NULL on every row an
-- upload or a pick made; each carries the CAN FAIL marker, so an update run again goes on. A site that ran an earlier
-- copy of this file gets the columns from script.php. The install SQL has them.

ALTER TABLE `#__alfa_media` ADD COLUMN `source_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'The address the image was fetched from, as the REST API client gave it; NULL for an image uploaded or picked' /** CAN FAIL **/;
ALTER TABLE `#__alfa_media` ADD COLUMN `source_url_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'sha256 of source_url: the same address is not fetched twice for one record' AFTER `source_url` /** CAN FAIL **/;
ALTER TABLE `#__alfa_media` ADD KEY `idx_alfa_media_source` (`origin`, `item_id`, `source_url_hash`) /** CAN FAIL **/;

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
  `state` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, no_consumer (its plugin takes none), dead or discarded',
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

-- Background work of extensions: every task and job names its owner (com_alfa, or the alfa plugin that declared it as
-- group.element), and one waiting or running job per owner and scope; an extension's task or job keeps its result,
-- written with its done settle; a job cancelled while it runs is marked and settled cancelled once its step has
-- ended. The rows written before this update are core's: only core's handlers could write them. The new unique key is
-- added before the old one is dropped, so the table always has one. Each statement carries the CAN FAIL marker or is
-- harmless when run again. A site that ran an earlier copy of this file gets the columns from script.php. The install
-- SQL creates the same columns and keys.
ALTER TABLE `#__alfa_tasks` ADD COLUMN `owner` varchar(100) NOT NULL DEFAULT '' COMMENT 'Whose work it is: com_alfa, or the plugin that declared it as group.element; never empty once written' /** CAN FAIL **/;
UPDATE `#__alfa_tasks` SET `owner` = 'com_alfa' WHERE `owner` = '';
ALTER TABLE `#__alfa_tasks` ADD COLUMN `result` mediumtext DEFAULT NULL COMMENT 'JSON written with the done settle of an extension task or job, at most 16 KiB; NULL otherwise' /** CAN FAIL **/;
ALTER TABLE `#__alfa_tasks` ADD COLUMN `cancel_requested` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'job: cancelled while running; the row stays running until its step has ended, then cancelled' /** CAN FAIL **/;
ALTER TABLE `#__alfa_tasks` ADD UNIQUE KEY `idx_owner_active_key` (`owner`, `active_key`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tasks` DROP INDEX `idx_active_key` /** CAN FAIL **/;
ALTER TABLE `#__alfa_tasks` ADD KEY `idx_owner_state` (`owner`, `state`) /** CAN FAIL **/;

-- The background work each owner declares, as core last read it: core's own rows from its handler classes, written
-- by the installer and every update, and each alfa plugin's from its onAlfaDeclareWork answer, read when the plugin is
-- installed, updated, saved or enabled. The runner's due read, its schedules and Tools > Tasks read this copy and never
-- boot a plugin for it.
CREATE TABLE IF NOT EXISTS `#__alfa_work_declarations` (
  `owner` varchar(100) NOT NULL COMMENT 'com_alfa, or a plugin as group.element',
  `name` varchar(64) NOT NULL COMMENT 'The work, unique within its owner',
  `kind` varchar(8) NOT NULL COMMENT 'single or job',
  `label` varchar(255) NOT NULL COMMENT 'A language key of the owner',
  `covers` varchar(255) NOT NULL DEFAULT '' COMMENT 'job: a language key, %s the scope; empty: the scope as given',
  `heavy` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'job: shares the one heavy slot',
  `calls_out` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'job: steps outside the runner transaction, the long ladder',
  `repeatable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'single: a second run duplicates nothing',
  `urgent` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'a failure shows as danger',
  `every_minutes` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'job: owed when none is active and none was created within; 0 none',
  `tells_settled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'the plugin has onAlfaWorkSettled',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 while the owner is disabled: its work waits; corrected at a refused boot and hourly against the extensions',
  `declared_at` datetime(6) NOT NULL COMMENT 'UTC, when core last read the declaration',
  PRIMARY KEY (`owner`, `name`),
  KEY `idx_every` (`enabled`, `every_minutes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='The work each owner declares, as core last read it';

-- The extension job is gone: a plugin declares its own tasks and jobs. Its rows, which no handler runs any more, are
-- deleted; a second run deletes nothing.
DELETE FROM `#__alfa_tasks` WHERE `handler` = 'extension.job';

-- The transfer headers
--
-- No command moves stock between locations, and Alfa Commerce itself never made a transfer: nothing writes or reads
-- #__alfa_inventory_transfers. It is dropped; the install SQL no longer creates it.

DROP TABLE IF EXISTS `#__alfa_inventory_transfers` /** CAN FAIL **/;

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

-- Customers may cancel their orders in a status
--
-- customer_can_cancel marks the statuses in which a customer may cancel their own order from their account. Every
-- status a shop already has gets 0, so no shop offers its customers the cancel after the update until it marks a status
-- and names the status a cancelled order moves to (Options, Cart, Customer account). A new shop has its Pending status
-- marked by the install SQL. An addition only; the statement carries the CAN FAIL marker, so an update run again finds
-- the column and goes on. A site that ran an earlier copy of this file gets the column from script.php. The install SQL
-- creates the same column.

ALTER TABLE `#__alfa_orders_statuses` ADD COLUMN `customer_can_cancel` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1, a customer may cancel their own order in this status from their account; the order moves to the status Options name (customer_cancel_status). Never inferred from the name or the role flags.' AFTER `is_completed` /** CAN FAIL **/;

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

-- The movements' link to a transfer

-- With no transfer headers, a movement links to none: the movements lose transfer_id and its key, and the movements'
-- type and the stock commands' log no longer name the transfer types in their comments. A site with the column or the
-- key already gone goes on: both drops carry the CAN FAIL marker. The install SQL makes the same tables.

ALTER TABLE `#__alfa_inventory_movements` DROP KEY `idx_transfer` /** CAN FAIL **/;
ALTER TABLE `#__alfa_inventory_movements` DROP COLUMN `transfer_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_inventory_movements` MODIFY `movement_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'receive | deduct | restore | adjust | migration';
ALTER TABLE `#__alfa_inventory_operations` MODIFY `command` varchar(20) NOT NULL COMMENT 'reserve | release | commit | restore | reverse_restore | receive | adjust | expire | reconcile | retire | location_state | unit_state | track_open | track_close | write_off | resolve_line | archive | unarchive | sale_rules | prioritise';
