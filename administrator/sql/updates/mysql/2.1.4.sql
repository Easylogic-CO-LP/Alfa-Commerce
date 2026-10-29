-- Alfa Commerce 2.1.4: the tasks table, and when a notification was last written
--
-- One table for the work Alfa Commerce owes and runs outside the change that asked for it, by type:
--   - single: one thing a change owes after its commit (an e-mail, a plugin's reaction, a notice). It is written in
--     the change's own transaction, so a rollback removes it and a commit keeps it, and it runs as soon as the
--     outermost transaction has committed and Alfa's named locks are released. A request that ends before running it
--     leaves it here, and the next recovery runs it.
--   - job: long work with steps and a checkpoint (from 2.2.0). The job columns are here from 2.1.4, so 2.2.0 adds no
--     table and no column; until then no job row is written and nothing claims one.
-- The slots table holds the concurrency classes of jobs (one heavy job at a time), used from 2.2.0.
--
-- The two tables are new in 2.1.4, empty on an updated site; the install SQL creates the same tables and this runs again
-- harmlessly over them.

CREATE TABLE IF NOT EXISTS `#__alfa_tasks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` varchar(8) NOT NULL COMMENT 'single: one thing a change owes after its commit, born in that transaction | job: long work with steps and a checkpoint',
  `handler` varchar(64) NOT NULL COMMENT 'What runs it, e.g. notice, order.status_email, order.status_event, price.set.prepare, stock.effects.drain',
  `state` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | running | done | failed | held | resolved | cancelled (per type: section 2.3)',
  `batch` char(32) DEFAULT NULL COMMENT 'single: the business transaction that wrote it; its singles run together, in id order. NULL for a job',
  `subject_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'What the task is about, e.g. order; empty = nothing',
  `subject_id` int UNSIGNED NOT NULL DEFAULT 0,
  `scope_key` varchar(190) DEFAULT NULL COMMENT 'job: what it covers, e.g. price.set:<digest>; one active job per scope. NULL for a single',
  `active_key` varchar(190) GENERATED ALWAYS AS (IF(`state` IN ('pending','running'), `scope_key`, NULL)) STORED,
  `weight` varchar(8) NOT NULL DEFAULT 'light' COMMENT 'light | heavy: one heavy job runs at a time. A single is light',
  `priority` smallint NOT NULL DEFAULT 0 COMMENT 'job claim order; a single runs in id order',
  `rerun` tinyint NOT NULL DEFAULT 0 COMMENT 'job: its scope changed again while it was active',
  `payload` mediumtext NOT NULL COMMENT 'JSON: what the handler needs, with the handler version',
  `checkpoint` mediumtext DEFAULT NULL COMMENT 'job: owned by the handler, written in the chunk transaction',
  `history` mediumtext DEFAULT NULL COMMENT 'single: JSON audit of every attempt and staff action (when, trigger, user, recipient or listener, outcome)',
  `done_units` bigint UNSIGNED NOT NULL DEFAULT 0,
  `total_units` bigint UNSIGNED NOT NULL DEFAULT 0,
  `run_after` datetime(3) NOT NULL COMMENT 'Not before this, UTC: a single''s creation or retry backoff; a job''s schedule or backoff',
  `lease_owner` char(32) DEFAULT NULL COMMENT 'The claim: only its owner settles the task',
  `lease_until` datetime(3) DEFAULT NULL COMMENT 'A running task whose lease passed: a job is reclaimable; a single is reclaimable only when its handler is idempotent, else held',
  `claims` int UNSIGNED NOT NULL DEFAULT 0,
  `failures` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive known failures; drives the backoff',
  `max_failures` int UNSIGNED NOT NULL DEFAULT 6,
  `last_error` varchar(1000) NOT NULL DEFAULT '',
  `last_trigger` varchar(16) NOT NULL DEFAULT '' COMMENT 'request | bell | tools | staff | runner | processnow | cli',
  `origin` varchar(32) NOT NULL DEFAULT '' COMMENT 'admin | site | api | cli | event',
  `created_by` int UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL COMMENT 'Server UTC with microseconds: a notice single is compared with the notification row''s modified',
  `updated_at` datetime(3) NOT NULL,
  `started_at` datetime(3) DEFAULT NULL,
  `finished_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_active_key` (`active_key`),
  KEY `idx_due` (`type`, `state`, `run_after`),
  KEY `idx_lease` (`type`, `state`, `lease_until`),
  KEY `idx_batch` (`batch`),
  KEY `idx_subject` (`subject_type`, `subject_id`, `id`),
  KEY `idx_handler_state` (`handler`, `state`),
  KEY `idx_finished` (`finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alfa tasks: singles a change owes after its commit, and jobs with steps';

CREATE TABLE IF NOT EXISTS `#__alfa_task_slots` (
  `name` varchar(32) NOT NULL COMMENT 'heavy, and any later concurrency class (used from 2.2.0)',
  `owner` char(32) DEFAULT NULL,
  `lease_until` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Concurrency slots of jobs: one heavy job at a time';

-- Notifications keep a language key and its parameters, translated when shown in each reader's language:
-- title and message hold the snapshot rendered in the shop's default administrator language, never rendered again, for
-- search and for a key that can no longer be resolved; notify_group holds a group key from now on. `modified`: when the
-- last write of the row was raised (server UTC, microseconds). A notice raised inside a transaction is written after its
-- commit, and only when its row holds no write raised after it, so it never overwrites a newer write of the same notice
-- and applying it twice changes nothing. The rows written before map to keys once, in the install script.
-- One column per statement: Joomla's database check reads only the first column of an ALTER.
ALTER TABLE `#__alfa_notifications` ADD COLUMN `text_domain` varchar(100) NOT NULL DEFAULT '' COMMENT 'The extension whose language files hold the keys, e.g. com_alfa, plg_alfa_subscriptions; empty = literal text' AFTER `notify_group`;
ALTER TABLE `#__alfa_notifications` ADD COLUMN `title_key` varchar(191) NOT NULL DEFAULT '' COMMENT 'Language key of the title (canonical); empty = title is literal text' AFTER `title`;
ALTER TABLE `#__alfa_notifications` ADD COLUMN `message_key` varchar(191) NOT NULL DEFAULT '' COMMENT 'Language key of the message (canonical); empty = message is literal text' AFTER `message`;
ALTER TABLE `#__alfa_notifications` ADD COLUMN `text_args` text NULL COMMENT 'JSON {"title": [...], "message": [...]}: the keys'' parameters' AFTER `message_key`;
ALTER TABLE `#__alfa_notifications` ADD COLUMN `modified` datetime(6) NULL DEFAULT NULL COMMENT 'When its last write was raised, server UTC: an older notice is not applied' AFTER `expires`;
