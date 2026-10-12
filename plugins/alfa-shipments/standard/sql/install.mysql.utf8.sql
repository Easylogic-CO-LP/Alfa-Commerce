--
-- plg_alfa-shipments_standard: the plugin's log table.
--
-- Created here, by the installer, so that no request ever has to create it: a log
-- row can be written inside an open transaction, and MySQL commits an open
-- transaction before any CREATE or ALTER. The install script completes the table on
-- every update (PluginLogTable::complete()), so a column added to params/logs.xml
-- later arrives without an update file.
--
-- Exactly what PluginLogTable::createStatement() builds for this plugin: core's
-- columns (id, id_order, id_order_shipment, id_shipment_method), then the columns of
-- params/logs.xml in their order, then the order, shipment and method keys.
--

CREATE TABLE IF NOT EXISTS `#__alfa_shipments_standard_logs` (
  `id` INT(11) AUTO_INCREMENT NOT NULL,
  `id_order` INT(11) NOT NULL,
  `id_order_shipment` INT(11) DEFAULT NULL,
  `id_shipment_method` INT(11) NOT NULL DEFAULT 0,
  `action` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `shipment_total` decimal(24,8) NOT NULL DEFAULT '0.00000000',
  `currency` char(3) DEFAULT NULL,
  `tracking_number` varchar(64) DEFAULT NULL,
  `carrier_name` varchar(255) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_by` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_id_order` (`id_order`),
  KEY `idx_id_order_shipment` (`id_order_shipment`),
  KEY `idx_id_shipment_method_action` (`id_shipment_method`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
