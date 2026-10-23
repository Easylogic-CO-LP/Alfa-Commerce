-- Alfa Commerce 2.1.1: one collation for every Alfa table
--
-- Every Alfa table is utf8mb4_unicode_ci, as the Joomla tables and the language tables that
-- Alfa creates for each content language are. The tables below were created utf8mb4_general_ci,
-- and the notifications table took the default collation of the database server. A query that
-- compares a column of one of them with a column of another table, such as an order status name
-- read in the current language or else in the site default language, failed with
-- Illegal mix of collations. The install SQL creates them utf8mb4_unicode_ci.
--
-- Each statement converts every text column of its table and changes nothing when run again.
-- The cart keeps the binary collation of its browser key: only its default and its source
-- column change. The order status names come first, the large order tables last.
--
-- CAN FAIL: a statement that fails (two values a unique key now reads as one, a lost
-- connection, a full disk) must not stop the update, or the step after it could not name the
-- table. After this file, the installer lists every Alfa table that is still not in this
-- collation, converts each with its statement here, and names any that fails, with the
-- error of the database, in the update messages, the setup log and the notifications.
-- Running the installation again converts what is left.

ALTER TABLE `#__alfa_orders_statuses_en_gb` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_field_groups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_fields` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_fields_usergroups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_form_fields_users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_orders_statuses` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_orderstatus_recipients` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_categories` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_manufacturers` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_places` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_shipments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_usergroups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_payment_users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_categories` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_manufacturers` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_places` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_usergroups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_shipment_users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_notifications` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_media` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_user_info` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart_items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_cart` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY `source` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'storefront' COMMENT 'Who created the cart: storefront (a shopper) | admin (the order builder). Written once at creation, never from a request; changing or placing a storefront cart also needs Carts: Edit' /** CAN FAIL **/;
ALTER TABLE `#__alfa_orders` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
ALTER TABLE `#__alfa_order_items` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci /** CAN FAIL **/;
