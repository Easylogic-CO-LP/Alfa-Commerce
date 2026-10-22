-- Alfa Commerce 2.0.1
--
-- Every link table (two ids per row, one row per link) gets a primary key on its pair of ids, so the
-- database itself refuses a pair written twice. Before this a repeated id could add a second, identical
-- row. The install script reduces such pairs to one row before Joomla runs this file, table by table
-- in a transaction, and checks afterwards that every key is in place (script.php, reduceRepeatedLinks
-- and completeLinkKeys).
--
-- The key statements may fail so that this file can run again after an interruption: a key that
-- already exists, or a plain key already dropped, is not an error. The install script adds anything
-- this file could not and names what is still missing. Joomla reads the may-fail marker only
-- directly before the closing semicolon of its statement (Installer::splitSql()). 2.1.0 moved every marker
-- there: as released in 2.0.1 they opened their statements, so every statement ran strict.
--
-- A plain key that the primary key makes redundant (the same columns, or its first column alone) is
-- dropped. Keys on the second column stay: they serve the lookups by that id.

ALTER TABLE `#__alfa_categories_usergroups` ADD PRIMARY KEY (`category_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_categories_usergroups` DROP KEY `category_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_categories_users` ADD PRIMARY KEY (`category_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_categories_users` DROP KEY `category_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_discount_categories` ADD PRIMARY KEY (`discount_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_discount_manufacturers` ADD PRIMARY KEY (`discount_id`, `manufacturer_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_discount_places` ADD PRIMARY KEY (`discount_id`, `place_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_discount_usergroups` ADD PRIMARY KEY (`discount_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_discount_users` ADD PRIMARY KEY (`discount_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_fields_usergroups` ADD PRIMARY KEY (`field_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_fields_users` ADD PRIMARY KEY (`field_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_item_field_categories` ADD PRIMARY KEY (`item_field_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_item_field_categories` DROP KEY `idx_item_field_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_categories` ADD PRIMARY KEY (`item_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_categories` DROP KEY `idx_item_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_manufacturers` ADD PRIMARY KEY (`item_id`, `manufacturer_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_manufacturers` DROP KEY `idx_item_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_usergroups` ADD PRIMARY KEY (`item_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_usergroups` DROP KEY `item_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_users` ADD PRIMARY KEY (`item_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_items_users` DROP KEY `item_id` /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_categories` ADD PRIMARY KEY (`payment_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_manufacturers` ADD PRIMARY KEY (`payment_id`, `manufacturer_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_places` ADD PRIMARY KEY (`payment_id`, `place_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_usergroups` ADD PRIMARY KEY (`payment_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_users` ADD PRIMARY KEY (`payment_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_categories` ADD PRIMARY KEY (`shipment_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_manufacturers` ADD PRIMARY KEY (`shipment_id`, `manufacturer_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_places` ADD PRIMARY KEY (`shipment_id`, `place_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_usergroups` ADD PRIMARY KEY (`shipment_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_users` ADD PRIMARY KEY (`shipment_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tax_categories` ADD PRIMARY KEY (`tax_id`, `category_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tax_manufacturers` ADD PRIMARY KEY (`tax_id`, `manufacturer_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tax_places` ADD PRIMARY KEY (`tax_id`, `place_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tax_usergroups` ADD PRIMARY KEY (`tax_id`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_tax_users` ADD PRIMARY KEY (`tax_id`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rule_condition_values` ADD PRIMARY KEY (`id_condition`, `id_value`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rule_condition_values` DROP KEY `idx_id_condition` /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rules_users` ADD PRIMARY KEY (`id_cart_rule`, `user_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rules_users` DROP KEY `idx_rule_user` /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rules_usergroups` ADD PRIMARY KEY (`id_cart_rule`, `usergroup_id`) /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_rules_usergroups` DROP KEY `idx_rule_group` /** CAN FAIL **/;
