--
-- Indexes for #__alfa_media. Speed up the media-maintenance finders
-- (orphan cleanup joins on origin + item_id; the missing-file finder filters on
-- type) and the per-entity media-zone lookups, which query by origin + item_id.
--
ALTER TABLE `#__alfa_media` ADD INDEX `idx_alfa_media_origin_item` (`origin`, `item_id`);
ALTER TABLE `#__alfa_media` ADD INDEX `idx_alfa_media_type` (`type`);
