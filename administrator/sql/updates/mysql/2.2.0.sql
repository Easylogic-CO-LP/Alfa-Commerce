-- Alfa Commerce 2.2.0: wider ids where the rows grow fastest, the catalogue rebuild marker, the frozen tax name of a
-- document line, the rows of the job runner, and the handler index of the tasks with their finish time
--
-- The stock movements, the stock operations and the order history get one row per changed unit, location or event, so
-- their ids are widened to bigint before they run out. Changing a primary key type rebuilds the table: its writes wait
-- for the copy, on both release databases (the release notes give the measured times).
-- An item gets the moment a rebuild of its projections was found owed and not yet done (NULL = current), with a key.
-- The tax chain of a parcel becomes text: from 2.2.0 each rate names the tax rule that applied it, and a name is kept
-- whole however long, as the document line that freezes it keeps it (empty on documents issued before 2.2.0), and
-- as the order line keeps the name of combined rules, which can pass 255 characters (lines frozen before 2.2.0 keep
-- what they hold). Each change of type rebuilds its table, the shipments and the order lines, as the widened ids do.
-- The slot rows are data: the heavy class of jobs and the three trigger gates; a second run changes nothing.
-- One column per ALTER statement, and MODIFY names its column without the word COLUMN: Joomla database check reads
-- the first column of each statement, and the word after MODIFY as that column.
-- The handler index of the tasks gains finished_at, so each retention rule reads a tight range. It takes a new name:
-- the database check reads a DROP INDEX as an index that must not exist, so one name dropped and added again would
-- never check clean. First, so the code that names it waits the least.

ALTER TABLE `#__alfa_tasks` DROP INDEX `idx_handler_state`;
ALTER TABLE `#__alfa_tasks` ADD INDEX `idx_handler_state_finished` (`handler`, `state`, `finished_at`);
ALTER TABLE `#__alfa_inventory_movements` MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `#__alfa_inventory_operations` MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `#__alfa_order_activity_log` MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

-- The comment of the scope key names the example of the scope rule (the scope of a job is the name of its handler, or
-- that name, a colon and what it covers); 2.1.4 wrote one the rule refuses.
ALTER TABLE `#__alfa_tasks` MODIFY `scope_key` varchar(190) DEFAULT NULL COMMENT 'job: what it covers, e.g. price.set.prepare:<digest>; one active job per scope. NULL for a single';
ALTER TABLE `#__alfa_order_items` MODIFY `tax_name` text NOT NULL DEFAULT ('') COMMENT 'Applied tax name snapshot, whole: the name of combined rules joined with + can pass 255 characters; lines frozen before 2.2.0 keep what they hold';
ALTER TABLE `#__alfa_order_shipments` MODIFY `shipping_tax_chain` text NOT NULL DEFAULT ('[]') COMMENT 'The tax rates the charge was calculated with, in the order they apply, each an exact decimal text with the name of the tax rule that applied it, whole (a JSON list, e.g. [{"rate":"10","name":"VAT"},{"rate":"5","name":"Eco levy"}]; a chain frozen before 2.2.0 holds the rates alone, e.g. ["10","5"]); [] = untaxed. shipping_tax_rate is their combined rate, for display and reconciliation only.';
ALTER TABLE `#__alfa_document_items` ADD COLUMN `tax_name` text NOT NULL DEFAULT ('') COMMENT 'The applied tax rule name frozen at issue, whole: from the order line or the shipping tax chain, on a credit from the corrected document line; empty on documents issued before 2.2.0';
ALTER TABLE `#__alfa_items` ADD COLUMN `projection_dirty_at` datetime(6) NULL DEFAULT NULL COMMENT 'When a rebuild of this item projections was found owed and not yet done; NULL = current';
ALTER TABLE `#__alfa_items` ADD KEY `idx_projection_dirty` (`projection_dirty_at`);
-- A gallery row records what its thumbnail was made from, so Recreate thumbnails leaves a picture chosen on the tile as
-- it is; the rows written before 2.2.0 keep NULL. Last in the table, as a fresh install makes it.
ALTER TABLE `#__alfa_media` ADD COLUMN `thumbnail_origin` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'What the thumbnail was made from: image (the row image) or chosen (a picture chosen on the tile); NULL before 2.2.0';
INSERT IGNORE INTO `#__alfa_task_slots` (`name`) VALUES ('heavy'), ('gate.page'), ('gate.endpoint'), ('gate.api');
