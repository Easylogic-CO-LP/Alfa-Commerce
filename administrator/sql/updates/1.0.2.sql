ALTER TABLE `#__alfa_items_categories` CHANGE `manufacturer_id` `category_id` INT(11) NULL DEFAULT '0'; 

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Manufacturer','com_alfa.manufacturer','{"special":{"dbtable":"#__alfa_manufacturers","key":"id","type":"Manufacturer","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/manufacturer.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.manufacturer')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Manufacturer', 
	`table` = '{"special":{"dbtable":"#__alfa_manufacturers","key":"id","type":"Manufacturer","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/manufacturer.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.manufacturer');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Category','com_alfa.category','{"special":{"dbtable":"#__alfa_categories","key":"id","type":"Category","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/category.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.category')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Category', 
	`table` = '{"special":{"dbtable":"#__alfa_categories","key":"id","type":"Category","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/category.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.category');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Item','com_alfa.item','{"special":{"dbtable":"#__alfa_items","key":"id","type":"Item","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/item.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.item')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Item', 
	`table` = '{"special":{"dbtable":"#__alfa_items","key":"id","type":"Item","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/item.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.item');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Custom','com_alfa.custom','{"special":{"dbtable":"#__alfa_customs","key":"id","type":"Custom","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/custom.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.custom')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Custom', 
	`table` = '{"special":{"dbtable":"#__alfa_customs","key":"id","type":"Custom","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/custom.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.custom');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Shipment Method','com_alfa.shipment','{"special":{"dbtable":"#__alfa_shipments","key":"id","type":"Shipment","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/shipment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.shipment')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Shipment Method', 
	`table` = '{"special":{"dbtable":"#__alfa_shipments","key":"id","type":"Shipment Method","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/shipment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.shipment');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Payment Method','com_alfa.payment','{"special":{"dbtable":"#__alfa_payments","key":"id","type":"Payment","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/payment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.payment')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Payment Method', 
	`table` = '{"special":{"dbtable":"#__alfa_payments","key":"id","type":"Payment Method","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/payment.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.payment');

INSERT INTO `#__content_types` (`type_title`, `type_alias`, `table`, `content_history_options`,`rules`,`field_mappings`)
SELECT * FROM ( SELECT 'Setting','com_alfa.setting','{"special":{"dbtable":"#__alfa_settings","key":"id","type":"Setting","prefix":"AlfaTable"}}', '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/setting.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"currency","targetTable":"#__alfa_currencies","targetColumn":"id","displayColumn":"name"}]}', CASE WHEN 'rules' is null THEN " " ELSE " " END as rules, CASE  WHEN 'field_mappings' is null THEN " " ELSE " " END as field_mappings) AS tmp
WHERE NOT EXISTS (
	SELECT type_alias FROM `#__content_types` WHERE (`type_alias` = 'com_alfa.setting')
) LIMIT 1;

UPDATE `#__content_types` SET
	`type_title` = 'Setting', 
	`table` = '{"special":{"dbtable":"#__alfa_settings","key":"id","type":"Setting","prefix":"AlfaTable"}}', 
	`content_history_options` = '{"formFile":"administrator\/components\/com_alfa\/models\/forms\/setting.xml", "hideFields":["checked_out","checked_out_time","params","language"], "ignoreChanges":["modified_by", "modified", "checked_out", "checked_out_time"], "convertToInt":["publish_up", "publish_down"], "displayLookup":[{"sourceColumn":"catid","targetTable":"#__categories","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"group_id","targetTable":"#__usergroups","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"created_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"access","targetTable":"#__viewlevels","targetColumn":"id","displayColumn":"title"},{"sourceColumn":"modified_by","targetTable":"#__users","targetColumn":"id","displayColumn":"name"},{"sourceColumn":"currency","targetTable":"#__alfa_currencies","targetColumn":"id","displayColumn":"name"}]}'
WHERE (`type_alias` = 'com_alfa.setting');
