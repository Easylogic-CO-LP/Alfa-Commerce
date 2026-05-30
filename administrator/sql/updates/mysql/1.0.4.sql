--
-- 1.0.4 — Order-status role flags + status-change email notifications
--
-- Single migration that covers two related slices of work landing in
-- the same release:
--
--   Part A — Role-flag taxonomy on `#__alfa_orders_statuses`. Replaces
--            the legacy `is_default` column with three lifecycle role
--            flags so OrderstatusModel can enforce its invariants
--            (within-row exclusion, mandatory ≥1 holder, transfer
--            semantics).
--
--   Part B — Status-change email notifications. Adds the `notify_customer`
--            flag, a join table for admin recipients, and converts the
--            remaining MyISAM tables to InnoDB so the whole schema runs
--            on one modern engine.
--
-- Statements run top-to-bottom. Column positions match
-- `sql/install.mysql.utf8.sql` after the migration completes.
--

-- ─────────────────────────────────────────────────────────────────
--  Part A — Role flags
-- ─────────────────────────────────────────────────────────────────
--
--   • is_initial   — singleton: the brand-new-order status (the role
--                    the dropped `is_default` used to play). Exactly
--                    one row across the table holds this; the model
--                    enforces single-row uniqueness on save.
--
--   • is_cancelled — multi-row family: any number of rows may carry
--                    it (Returned, Refunded, Cancelled all belong
--                    here). Drives stock-release and refund logic.
--
--   • is_completed — multi-row family: any number of rows may carry
--                    it (Delivered, Picked up, Paid all belong here).
--
-- Within-row exclusion (a row holds AT MOST one of the three) is
-- enforced by `forms/orderstatus.xml` showon rules and by
-- OrderstatusModel::canSaveStatus(). All three are mandatory ≥1
-- once first nominated — see canSaveStatus() and removableStatuses().
--

ALTER TABLE `#__alfa_orders_statuses`
  DROP COLUMN `is_default`;

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `is_initial` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Singleton: brand-new-order status. Exactly one row should hold this; OrderstatusModel enforces single-row uniqueness.'
    AFTER `stock_operation`;

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Multi-row family: cancellation states (Returned, Refunded, Cancelled, …). Drives stock-release and refund logic.'
    AFTER `is_initial`;

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `is_completed` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Multi-row family: terminal success states (Delivered, Picked up, Paid, …).'
    AFTER `is_cancelled`;

-- ─────────────────────────────────────────────────────────────────
--  Part B — Status-change email notifications
-- ─────────────────────────────────────────────────────────────────
--
--   • notify_customer (column)         — single-bit toggle. When 1,
--                                        the order's customer is emailed
--                                        with the per-language
--                                        email_subject_customer /
--                                        email_body_customer template on
--                                        transition INTO this status.
--
--   • `#__alfa_orderstatus_recipients` — join table. Each row links one
--                                        status to one Joomla user who
--                                        receives the admin notification.
--                                        Cleanup is programmatic:
--                                          ◦ Joomla user deleted →
--                                            alfasync plugin's
--                                            onUserAfterDelete.
--                                          ◦ Status deleted →
--                                            OrderstatusModel::delete().
--
-- The per-language subject + body templates live in the multilingual
-- aux tables `#__alfa_orders_statuses_<langtag>` and are created
-- automatically by SyncHelper::syncLanguageSchema() (postflight hook)
-- once `forms/orderstatus.xml` declares the matching MultilingualText /
-- MultilingualEditor fields.
--

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `notify_customer` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When 1, OrderEmailHelper sends the customer the email_subject_customer / email_body_customer template on transition INTO this status.'
    AFTER `is_completed`;

CREATE TABLE IF NOT EXISTS `#__alfa_orderstatus_recipients` (
  `id_orderstatus` INT(11) NOT NULL,
  `id_user`        INT(11) NOT NULL,
  PRIMARY KEY (`id_orderstatus`, `id_user`),
  KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Convert the remaining MyISAM tables to InnoDB so the whole schema
-- runs on a single, modern engine: ACID transactions, row-level
-- locking, crash recovery, and FK support. MyISAM was the default
-- before MySQL 5.5 — these tables predate that switch.
--

ALTER TABLE `#__alfa_form_fields`              ENGINE=InnoDB;
ALTER TABLE `#__alfa_form_fields_usergroups`   ENGINE=InnoDB;
ALTER TABLE `#__alfa_form_fields_users`        ENGINE=InnoDB;
ALTER TABLE `#__alfa_orders_statuses`          ENGINE=InnoDB;
ALTER TABLE `#__alfa_payment_categories`       ENGINE=InnoDB;
ALTER TABLE `#__alfa_payment_manufacturers`    ENGINE=InnoDB;
ALTER TABLE `#__alfa_payment_places`           ENGINE=InnoDB;
ALTER TABLE `#__alfa_payment_usergroups`       ENGINE=InnoDB;
ALTER TABLE `#__alfa_payment_users`            ENGINE=InnoDB;
ALTER TABLE `#__alfa_shipment_categories`      ENGINE=InnoDB;
ALTER TABLE `#__alfa_shipment_manufacturers`   ENGINE=InnoDB;
ALTER TABLE `#__alfa_shipment_places`          ENGINE=InnoDB;
ALTER TABLE `#__alfa_shipment_usergroups`      ENGINE=InnoDB;
ALTER TABLE `#__alfa_shipment_users`           ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────────
--  Part C — Selectable email wrapper layout (per recipient)
-- ─────────────────────────────────────────────────────────────────
--
-- Each status can render its customer / admin notification through a
-- chosen LayoutHelper layout id (e.g. 'emails.order.default'). The selection is
-- structural, not translatable, so it lives on the base table — the
-- per-language tables only hold the position CONTENT. OrderEmailHelper
-- reads these columns at dispatch / preview / test time; an empty value
-- falls back to 'emails.order.default'. The picker is OrderStatusEmailLayoutField.
--

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `email_layout_customer` VARCHAR(190) NOT NULL DEFAULT 'emails.order.default'
    COMMENT 'LayoutHelper id for the customer notification wrapper (OrderEmailHelper). Empty = emails.order.'
    AFTER `notify_customer`;

ALTER TABLE `#__alfa_orders_statuses`
  ADD COLUMN `email_layout_admin` VARCHAR(190) NOT NULL DEFAULT 'emails.order.default'
    COMMENT 'LayoutHelper id for the admin notification wrapper (OrderEmailHelper). Empty = emails.order.'
    AFTER `email_layout_customer`;

-- ─────────────────────────────────────────────────────────────────
--  Part D — Missing `#__alfa_form_field_groups` table
-- ─────────────────────────────────────────────────────────────────
--
-- FormfieldgroupTable / FormfieldgroupsModel and FormfieldModel's
-- group lookup all target `#__alfa_form_field_groups`, but the table
-- was never added to install.mysql.utf8.sql. Create it here (and in
-- install/uninstall) so fresh installs and existing sites converge.
-- `form_fields.group_id` references `id`.
--

CREATE TABLE IF NOT EXISTS `#__alfa_form_field_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `state` tinyint(4) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `modified_by` int(11) DEFAULT NULL,
  `checked_out` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────────────────
--  Part E — Drop stock-message columns missed by the multilingual port
-- ─────────────────────────────────────────────────────────────────
--
-- `stock_low_message` / `stock_zero_message` became MultilingualEditor
-- fields (forms/item.xml) backed by `multilingual_table="#__alfa_items"`,
-- so their text now lives ONLY in the per-language tables
-- `#__alfa_items_<langtag>` (created by SyncHelper::syncLanguageSchema()).
-- The base-table columns were left behind as `varchar(300) NOT NULL`
-- with no default — ItemModel::save() never writes them (the value
-- arrives as per-language flat keys), so every save failed under MySQL
-- strict mode with "Field 'stock_low_message' doesn't have a default
-- value". Drop them to match the other translatable columns already
-- removed from this table.
--
-- NB: one column per ALTER statement. Joomla's schema checker
-- (Joomla\CMS\Schema\ChangeItem\MysqlChangeItem) parses only the FIRST
-- `DROP COLUMN <name>` of an ALTER, so the System → Maintenance →
-- Database "Fix" tool would miss the second column if both were combined
-- into one statement. Split = each column gets its own check/fix item.
--

ALTER TABLE `#__alfa_items` DROP COLUMN `stock_low_message`;

ALTER TABLE `#__alfa_items` DROP COLUMN `stock_zero_message`;

-- ─────────────────────────────────────────────────────────────────
--  Part F — Per-country default currency on #__alfa_places
-- ─────────────────────────────────────────────────────────────────
--
-- The old `number` column (labelled "Global Currency Number") was dead:
-- seeded 0 for every country and read by no business logic. Replace it
-- with `currency_id` — a FK to #__alfa_currencies.id giving each country
-- its default currency (resolve via Currency::loadById to get code /
-- symbol / ISO number). Consistent with the currency_id FK already used
-- on items_prices / price_index.
--
-- One column per ALTER (the schema-fix tool checks one per statement).
--

ALTER TABLE `#__alfa_places` DROP COLUMN `number`;

ALTER TABLE `#__alfa_places`
  ADD COLUMN `currency_id` int(11) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'FK to #__alfa_currencies.id; 0 = none. The default currency this country uses.'
    AFTER `code3`;

-- Backfill each country's default currency by joining on ISO code (so it
-- is independent of the currencies table's autoincrement ids). Countries
-- whose currency is not in #__alfa_currencies (uninhabited / disputed
-- territories, duplicate legacy codes) match no row and stay 0.

UPDATE `#__alfa_places` p
JOIN `#__alfa_currencies` c ON c.`code` = CASE p.`code2`
    WHEN 'AD' THEN 'EUR'
    WHEN 'AE' THEN 'AED'
    WHEN 'AF' THEN 'AFN'
    WHEN 'AG' THEN 'XCD'
    WHEN 'AI' THEN 'XCD'
    WHEN 'AL' THEN 'ALL'
    WHEN 'AM' THEN 'AMD'
    WHEN 'AN' THEN 'ANG'
    WHEN 'AO' THEN 'AOA'
    WHEN 'AR' THEN 'ARS'
    WHEN 'AS' THEN 'USD'
    WHEN 'AT' THEN 'EUR'
    WHEN 'AU' THEN 'AUD'
    WHEN 'AW' THEN 'AWG'
    WHEN 'AZ' THEN 'AZN'
    WHEN 'BA' THEN 'BAM'
    WHEN 'BB' THEN 'BBD'
    WHEN 'BD' THEN 'BDT'
    WHEN 'BE' THEN 'EUR'
    WHEN 'BF' THEN 'XOF'
    WHEN 'BG' THEN 'BGN'
    WHEN 'BH' THEN 'BHD'
    WHEN 'BI' THEN 'BIF'
    WHEN 'BJ' THEN 'XOF'
    WHEN 'BM' THEN 'BMD'
    WHEN 'BN' THEN 'BND'
    WHEN 'BO' THEN 'BOB'
    WHEN 'BR' THEN 'BRL'
    WHEN 'BS' THEN 'BSD'
    WHEN 'BT' THEN 'BTN'
    WHEN 'BW' THEN 'BWP'
    WHEN 'BY' THEN 'BYR'
    WHEN 'BZ' THEN 'BZD'
    WHEN 'CA' THEN 'CAD'
    WHEN 'CC' THEN 'AUD'
    WHEN 'CF' THEN 'XAF'
    WHEN 'CG' THEN 'XAF'
    WHEN 'CH' THEN 'CHF'
    WHEN 'CI' THEN 'XOF'
    WHEN 'CK' THEN 'NZD'
    WHEN 'CL' THEN 'CLP'
    WHEN 'CM' THEN 'XAF'
    WHEN 'CN' THEN 'CNY'
    WHEN 'CO' THEN 'COP'
    WHEN 'CR' THEN 'CRC'
    WHEN 'CU' THEN 'CUP'
    WHEN 'CV' THEN 'CVE'
    WHEN 'CX' THEN 'AUD'
    WHEN 'CY' THEN 'EUR'
    WHEN 'CZ' THEN 'CZK'
    WHEN 'DC' THEN 'CDF'
    WHEN 'DE' THEN 'EUR'
    WHEN 'DJ' THEN 'DJF'
    WHEN 'DK' THEN 'DKK'
    WHEN 'DM' THEN 'XCD'
    WHEN 'DO' THEN 'DOP'
    WHEN 'DZ' THEN 'DZD'
    WHEN 'EC' THEN 'USD'
    WHEN 'EE' THEN 'EUR'
    WHEN 'EG' THEN 'EGP'
    WHEN 'ER' THEN 'ERN'
    WHEN 'ES' THEN 'EUR'
    WHEN 'ET' THEN 'ETB'
    WHEN 'FI' THEN 'EUR'
    WHEN 'FJ' THEN 'FJD'
    WHEN 'FK' THEN 'FKP'
    WHEN 'FM' THEN 'USD'
    WHEN 'FO' THEN 'DKK'
    WHEN 'FR' THEN 'EUR'
    WHEN 'GA' THEN 'XAF'
    WHEN 'GB' THEN 'GBP'
    WHEN 'GD' THEN 'XCD'
    WHEN 'GE' THEN 'GEL'
    WHEN 'GF' THEN 'EUR'
    WHEN 'GH' THEN 'GHS'
    WHEN 'GI' THEN 'GIP'
    WHEN 'GL' THEN 'DKK'
    WHEN 'GM' THEN 'GMD'
    WHEN 'GN' THEN 'GNF'
    WHEN 'GP' THEN 'EUR'
    WHEN 'GQ' THEN 'XAF'
    WHEN 'GR' THEN 'EUR'
    WHEN 'GT' THEN 'GTQ'
    WHEN 'GU' THEN 'USD'
    WHEN 'GW' THEN 'XOF'
    WHEN 'GY' THEN 'GYD'
    WHEN 'HK' THEN 'HKD'
    WHEN 'HN' THEN 'HNL'
    WHEN 'HR' THEN 'EUR'
    WHEN 'HT' THEN 'HTG'
    WHEN 'HU' THEN 'HUF'
    WHEN 'ID' THEN 'IDR'
    WHEN 'IE' THEN 'EUR'
    WHEN 'IL' THEN 'ILS'
    WHEN 'IN' THEN 'INR'
    WHEN 'IO' THEN 'USD'
    WHEN 'IQ' THEN 'IQD'
    WHEN 'IR' THEN 'IRR'
    WHEN 'IS' THEN 'ISK'
    WHEN 'IT' THEN 'EUR'
    WHEN 'JE' THEN 'GBP'
    WHEN 'JM' THEN 'JMD'
    WHEN 'JO' THEN 'JOD'
    WHEN 'JP' THEN 'JPY'
    WHEN 'KE' THEN 'KES'
    WHEN 'KG' THEN 'KGS'
    WHEN 'KH' THEN 'KHR'
    WHEN 'KI' THEN 'AUD'
    WHEN 'KM' THEN 'KMF'
    WHEN 'KN' THEN 'XCD'
    WHEN 'KP' THEN 'KPW'
    WHEN 'KR' THEN 'KRW'
    WHEN 'KW' THEN 'KWD'
    WHEN 'KY' THEN 'KYD'
    WHEN 'KZ' THEN 'KZT'
    WHEN 'LA' THEN 'LAK'
    WHEN 'LB' THEN 'LBP'
    WHEN 'LC' THEN 'XCD'
    WHEN 'LI' THEN 'CHF'
    WHEN 'LK' THEN 'LKR'
    WHEN 'LR' THEN 'LRD'
    WHEN 'LS' THEN 'LSL'
    WHEN 'LT' THEN 'EUR'
    WHEN 'LU' THEN 'EUR'
    WHEN 'LV' THEN 'EUR'
    WHEN 'LY' THEN 'LYD'
    WHEN 'MA' THEN 'MAD'
    WHEN 'MC' THEN 'EUR'
    WHEN 'MD' THEN 'MDL'
    WHEN 'ME' THEN 'EUR'
    WHEN 'MF' THEN 'EUR'
    WHEN 'MG' THEN 'MGA'
    WHEN 'MH' THEN 'USD'
    WHEN 'MK' THEN 'MKD'
    WHEN 'ML' THEN 'XOF'
    WHEN 'MM' THEN 'MMK'
    WHEN 'MN' THEN 'MNT'
    WHEN 'MO' THEN 'MOP'
    WHEN 'MQ' THEN 'EUR'
    WHEN 'MR' THEN 'MRO'
    WHEN 'MS' THEN 'XCD'
    WHEN 'MT' THEN 'EUR'
    WHEN 'MU' THEN 'MUR'
    WHEN 'MV' THEN 'MVR'
    WHEN 'MW' THEN 'MWK'
    WHEN 'MX' THEN 'MXN'
    WHEN 'MY' THEN 'MYR'
    WHEN 'MZ' THEN 'MZN'
    WHEN 'NA' THEN 'NAD'
    WHEN 'NC' THEN 'XPF'
    WHEN 'NE' THEN 'XOF'
    WHEN 'NG' THEN 'NGN'
    WHEN 'NI' THEN 'NIO'
    WHEN 'NL' THEN 'EUR'
    WHEN 'NO' THEN 'NOK'
    WHEN 'NP' THEN 'NPR'
    WHEN 'NR' THEN 'AUD'
    WHEN 'NU' THEN 'NZD'
    WHEN 'NZ' THEN 'NZD'
    WHEN 'OM' THEN 'OMR'
    WHEN 'PA' THEN 'PAB'
    WHEN 'PE' THEN 'PEN'
    WHEN 'PF' THEN 'XPF'
    WHEN 'PG' THEN 'PGK'
    WHEN 'PH' THEN 'PHP'
    WHEN 'PK' THEN 'PKR'
    WHEN 'PL' THEN 'PLN'
    WHEN 'PM' THEN 'EUR'
    WHEN 'PN' THEN 'NZD'
    WHEN 'PR' THEN 'USD'
    WHEN 'PS' THEN 'ILS'
    WHEN 'PT' THEN 'EUR'
    WHEN 'PW' THEN 'USD'
    WHEN 'PY' THEN 'PYG'
    WHEN 'QA' THEN 'QAR'
    WHEN 'RE' THEN 'EUR'
    WHEN 'RO' THEN 'RON'
    WHEN 'RS' THEN 'RSD'
    WHEN 'RU' THEN 'RUB'
    WHEN 'RW' THEN 'RWF'
    WHEN 'SA' THEN 'SAR'
    WHEN 'SB' THEN 'SBD'
    WHEN 'SC' THEN 'SCR'
    WHEN 'SD' THEN 'SDG'
    WHEN 'SE' THEN 'SEK'
    WHEN 'SG' THEN 'SGD'
    WHEN 'SH' THEN 'SHP'
    WHEN 'SI' THEN 'EUR'
    WHEN 'SJ' THEN 'NOK'
    WHEN 'SK' THEN 'EUR'
    WHEN 'SL' THEN 'SLL'
    WHEN 'SM' THEN 'EUR'
    WHEN 'SN' THEN 'XOF'
    WHEN 'SO' THEN 'SOS'
    WHEN 'SR' THEN 'SRD'
    WHEN 'ST' THEN 'STD'
    WHEN 'SV' THEN 'USD'
    WHEN 'SX' THEN 'ANG'
    WHEN 'SY' THEN 'SYP'
    WHEN 'SZ' THEN 'SZL'
    WHEN 'TC' THEN 'USD'
    WHEN 'TD' THEN 'XAF'
    WHEN 'TG' THEN 'XOF'
    WHEN 'TH' THEN 'THB'
    WHEN 'TJ' THEN 'TJS'
    WHEN 'TM' THEN 'USD'
    WHEN 'TN' THEN 'TND'
    WHEN 'TO' THEN 'TOP'
    WHEN 'TR' THEN 'TRY'
    WHEN 'TT' THEN 'TTD'
    WHEN 'TV' THEN 'AUD'
    WHEN 'TW' THEN 'TWD'
    WHEN 'TZ' THEN 'TZS'
    WHEN 'UA' THEN 'UAH'
    WHEN 'UG' THEN 'UGX'
    WHEN 'UM' THEN 'USD'
    WHEN 'US' THEN 'USD'
    WHEN 'UY' THEN 'UYU'
    WHEN 'UZ' THEN 'UZS'
    WHEN 'VA' THEN 'EUR'
    WHEN 'VC' THEN 'XCD'
    WHEN 'VE' THEN 'VEF'
    WHEN 'VG' THEN 'USD'
    WHEN 'VI' THEN 'USD'
    WHEN 'VN' THEN 'VND'
    WHEN 'VU' THEN 'VUV'
    WHEN 'WF' THEN 'XPF'
    WHEN 'WS' THEN 'WST'
    WHEN 'XC' THEN 'EUR'
    WHEN 'YE' THEN 'YER'
    WHEN 'YT' THEN 'EUR'
    WHEN 'ZA' THEN 'ZAR'
    WHEN 'ZM' THEN 'ZMK'
    WHEN 'ZW' THEN 'ZWD'
    WHEN 'ZZ' THEN 'EUR'
END
SET p.`currency_id` = c.`id`;
