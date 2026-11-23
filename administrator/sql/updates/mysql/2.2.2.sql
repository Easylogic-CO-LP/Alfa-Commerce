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

-- Card disputes
--
-- One record per dispute (a case), tied to the disputed payment; the money moves only through the chargeback and
-- reversal rows the dispute ledger books at a decision, each naming its case in id_dispute. The install SQL creates the
-- same table and column; on an updated site the statements below make one record for every chargeback row written
-- before the table existed, so no chargeback stands without its case. Each statement runs again harmlessly: the table
-- and the column exist, and a row already joined to its record is never read again.

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
