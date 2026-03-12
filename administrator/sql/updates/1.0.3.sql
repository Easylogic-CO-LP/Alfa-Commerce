ALTER TABLE `#__alfa_categories` MODIFY `meta_data` text NOT NULL DEFAULT '{}';
ALTER TABLE `#__alfa_items` MODIFY `meta_data` text NOT NULL DEFAULT '{}';
ALTER TABLE `#__alfa_manufacturers` ADD COLUMN `meta_data` text NOT NULL DEFAULT '{}' AFTER `meta_desc`;
