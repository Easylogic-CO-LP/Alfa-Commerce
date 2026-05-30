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
