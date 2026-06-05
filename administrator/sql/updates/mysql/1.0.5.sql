--
-- Backend notification store (pushed from anywhere, always read from here).
-- Live/computed sources (e.g. the integrity check) push/clear their own row;
-- discrete events (new order, low stock, …) push a row and are dismissed on action.
-- Dismissed rows are kept as history until `expires`, then lazily purged (no cron).
--
CREATE TABLE IF NOT EXISTS `#__alfa_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dedup_key` VARCHAR(191) NOT NULL DEFAULT '',
  `notify_group` VARCHAR(64) NOT NULL DEFAULT '',
  `severity` VARCHAR(16) NOT NULL DEFAULT 'info',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `url` VARCHAR(1024) NOT NULL DEFAULT '',
  `dismissible` TINYINT(1) NOT NULL DEFAULT 1,
  `view_access` VARCHAR(128) NULL DEFAULT NULL,
  `view_access_asset` VARCHAR(191) NULL DEFAULT NULL,
  `url_access` VARCHAR(128) NULL DEFAULT NULL,
  `url_access_asset` VARCHAR(191) NULL DEFAULT NULL,
  `created` DATETIME NULL DEFAULT NULL,
  `readed` DATETIME NULL DEFAULT NULL,
  `readed_by` INT UNSIGNED NULL DEFAULT NULL,
  `dismissed` DATETIME NULL DEFAULT NULL,
  `expires` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_dedup_key` (`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
