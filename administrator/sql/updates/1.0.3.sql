--
-- Alfa Commerce 1.0.3 - Add Foreign Key Constraints
--
-- Adds referential integrity constraints to enforce data consistency.
-- Uses ON DELETE CASCADE for junction/pivot tables (removing parent removes links).
-- Uses ON DELETE SET NULL for optional references (preserving records when referenced entity is removed).
-- Uses ON DELETE RESTRICT for critical references (preventing deletion of referenced records).
--

-- ============================================================
-- JUNCTION / PIVOT TABLES (CASCADE on delete)
-- ============================================================

-- Categories <-> Usergroups
ALTER TABLE `#__alfa_categories_usergroups`
    ADD CONSTRAINT `fk_catug_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_catug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Categories <-> Users
ALTER TABLE `#__alfa_categories_users`
    ADD CONSTRAINT `fk_catu_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_catu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Items <-> Categories
ALTER TABLE `#__alfa_items_categories`
    ADD CONSTRAINT `fk_itemcat_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_itemcat_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Items <-> Manufacturers
ALTER TABLE `#__alfa_items_manufacturers`
    ADD CONSTRAINT `fk_itemmfr_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_itemmfr_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `#__alfa_manufacturers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Items <-> Usergroups
ALTER TABLE `#__alfa_items_usergroups`
    ADD CONSTRAINT `fk_itemug_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_itemug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Items <-> Users
ALTER TABLE `#__alfa_items_users`
    ADD CONSTRAINT `fk_itemu_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_itemu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment <-> Categories
ALTER TABLE `#__alfa_payment_categories`
    ADD CONSTRAINT `fk_paycat_payment` FOREIGN KEY (`payment_id`) REFERENCES `#__alfa_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_paycat_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment <-> Manufacturers
ALTER TABLE `#__alfa_payment_manufacturers`
    ADD CONSTRAINT `fk_paymfr_payment` FOREIGN KEY (`payment_id`) REFERENCES `#__alfa_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_paymfr_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `#__alfa_manufacturers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment <-> Places
ALTER TABLE `#__alfa_payment_places`
    ADD CONSTRAINT `fk_payplc_payment` FOREIGN KEY (`payment_id`) REFERENCES `#__alfa_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payplc_place` FOREIGN KEY (`place_id`) REFERENCES `#__alfa_places` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment <-> Usergroups
ALTER TABLE `#__alfa_payment_usergroups`
    ADD CONSTRAINT `fk_payug_payment` FOREIGN KEY (`payment_id`) REFERENCES `#__alfa_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment <-> Users
ALTER TABLE `#__alfa_payment_users`
    ADD CONSTRAINT `fk_payu_payment` FOREIGN KEY (`payment_id`) REFERENCES `#__alfa_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Shipment <-> Categories
ALTER TABLE `#__alfa_shipment_categories`
    ADD CONSTRAINT `fk_shipcat_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_shipcat_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Shipment <-> Manufacturers
ALTER TABLE `#__alfa_shipment_manufacturers`
    ADD CONSTRAINT `fk_shipmfr_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_shipmfr_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `#__alfa_manufacturers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Shipment <-> Places
ALTER TABLE `#__alfa_shipment_places`
    ADD CONSTRAINT `fk_shipplc_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_shipplc_place` FOREIGN KEY (`place_id`) REFERENCES `#__alfa_places` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Shipment <-> Usergroups
ALTER TABLE `#__alfa_shipment_usergroups`
    ADD CONSTRAINT `fk_shipug_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_shipug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Shipment <-> Users
ALTER TABLE `#__alfa_shipment_users`
    ADD CONSTRAINT `fk_shipu_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_shipu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Tax <-> Categories
ALTER TABLE `#__alfa_tax_categories`
    ADD CONSTRAINT `fk_taxcat_tax` FOREIGN KEY (`tax_id`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_taxcat_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Tax <-> Manufacturers
ALTER TABLE `#__alfa_tax_manufacturers`
    ADD CONSTRAINT `fk_taxmfr_tax` FOREIGN KEY (`tax_id`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_taxmfr_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `#__alfa_manufacturers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Tax <-> Places
ALTER TABLE `#__alfa_tax_places`
    ADD CONSTRAINT `fk_taxplc_tax` FOREIGN KEY (`tax_id`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_taxplc_place` FOREIGN KEY (`place_id`) REFERENCES `#__alfa_places` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Tax <-> Usergroups
ALTER TABLE `#__alfa_tax_usergroups`
    ADD CONSTRAINT `fk_taxug_tax` FOREIGN KEY (`tax_id`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_taxug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Tax <-> Users
ALTER TABLE `#__alfa_tax_users`
    ADD CONSTRAINT `fk_taxu_tax` FOREIGN KEY (`tax_id`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_taxu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Discount <-> Categories
ALTER TABLE `#__alfa_discount_categories`
    ADD CONSTRAINT `fk_disccat_discount` FOREIGN KEY (`discount_id`) REFERENCES `#__alfa_discounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_disccat_category` FOREIGN KEY (`category_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Discount <-> Manufacturers
ALTER TABLE `#__alfa_discount_manufacturers`
    ADD CONSTRAINT `fk_discmfr_discount` FOREIGN KEY (`discount_id`) REFERENCES `#__alfa_discounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_discmfr_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `#__alfa_manufacturers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Discount <-> Places
ALTER TABLE `#__alfa_discount_places`
    ADD CONSTRAINT `fk_discplc_discount` FOREIGN KEY (`discount_id`) REFERENCES `#__alfa_discounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_discplc_place` FOREIGN KEY (`place_id`) REFERENCES `#__alfa_places` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Discount <-> Usergroups
ALTER TABLE `#__alfa_discount_usergroups`
    ADD CONSTRAINT `fk_discug_discount` FOREIGN KEY (`discount_id`) REFERENCES `#__alfa_discounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_discug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Discount <-> Users
ALTER TABLE `#__alfa_discount_users`
    ADD CONSTRAINT `fk_discu_discount` FOREIGN KEY (`discount_id`) REFERENCES `#__alfa_discounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_discu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Coupons <-> Usergroups
ALTER TABLE `#__alfa_coupons_usergroups`
    ADD CONSTRAINT `fk_coupug_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `#__alfa_coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_coupug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Coupons <-> Users
ALTER TABLE `#__alfa_coupons_users`
    ADD CONSTRAINT `fk_coupu_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `#__alfa_coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_coupu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Form Fields <-> Usergroups
ALTER TABLE `#__alfa_form_fields_usergroups`
    ADD CONSTRAINT `fk_ffug_formfield` FOREIGN KEY (`form_field_id`) REFERENCES `#__alfa_form_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_ffug_usergroup` FOREIGN KEY (`usergroup_id`) REFERENCES `#__alfa_usergroups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Form Fields <-> Users
ALTER TABLE `#__alfa_form_fields_users`
    ADD CONSTRAINT `fk_ffu_formfield` FOREIGN KEY (`form_field_id`) REFERENCES `#__alfa_form_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_ffu_user` FOREIGN KEY (`user_id`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


-- ============================================================
-- CART REFERENCES (SET NULL - keep cart if method is deleted)
-- ============================================================

ALTER TABLE `#__alfa_cart`
    ADD CONSTRAINT `fk_cart_payment` FOREIGN KEY (`id_payment`) REFERENCES `#__alfa_payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_cart_shipment` FOREIGN KEY (`id_shipment`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_cart_currency` FOREIGN KEY (`id_currency`) REFERENCES `#__alfa_currencies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Cart Items
ALTER TABLE `#__alfa_cart_items`
    ADD CONSTRAINT `fk_cartitem_cart` FOREIGN KEY (`id_cart`) REFERENCES `#__alfa_cart` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_cartitem_item` FOREIGN KEY (`id_item`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


-- ============================================================
-- ORDER REFERENCES (RESTRICT - prevent deleting referenced data)
-- ============================================================

ALTER TABLE `#__alfa_orders`
    ADD CONSTRAINT `fk_order_currency` FOREIGN KEY (`id_currency`) REFERENCES `#__alfa_currencies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_status` FOREIGN KEY (`id_order_status`) REFERENCES `#__alfa_orders_statuses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_payment_method` FOREIGN KEY (`id_payment_method`) REFERENCES `#__alfa_payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_shipment_method` FOREIGN KEY (`id_shipment_method`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_coupon` FOREIGN KEY (`id_coupon`) REFERENCES `#__alfa_coupons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Order Items
ALTER TABLE `#__alfa_order_items`
    ADD CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_orderitem_item` FOREIGN KEY (`id_item`) REFERENCES `#__alfa_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Order Payments
ALTER TABLE `#__alfa_order_payments`
    ADD CONSTRAINT `fk_orderpay_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_orderpay_currency` FOREIGN KEY (`id_currency`) REFERENCES `#__alfa_currencies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_orderpay_method` FOREIGN KEY (`id_payment_method`) REFERENCES `#__alfa_payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Order Shipments
ALTER TABLE `#__alfa_order_shipments`
    ADD CONSTRAINT `fk_ordership_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_ordership_method` FOREIGN KEY (`id_shipment_method`) REFERENCES `#__alfa_shipments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Order Activity Log
ALTER TABLE `#__alfa_order_activity_log`
    ADD CONSTRAINT `fk_orderlog_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Order Detail Tax
ALTER TABLE `#__alfa_order_detail_tax`
    ADD CONSTRAINT `fk_ordertax_detail` FOREIGN KEY (`id_order_detail`) REFERENCES `#__alfa_order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_ordertax_tax` FOREIGN KEY (`id_tax`) REFERENCES `#__alfa_taxes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Order Slip (Returns/Refunds)
ALTER TABLE `#__alfa_order_slip`
    ADD CONSTRAINT `fk_orderslip_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Order Slip Detail
ALTER TABLE `#__alfa_order_slip_detail`
    ADD CONSTRAINT `fk_orderslipdet_slip` FOREIGN KEY (`id_order_slip`) REFERENCES `#__alfa_order_slip` (`id_order_slip`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_orderslipdet_detail` FOREIGN KEY (`id_order_detail`) REFERENCES `#__alfa_order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Payment Standard Logs
ALTER TABLE `#__alfa_payments_standard_logs`
    ADD CONSTRAINT `fk_paylog_order` FOREIGN KEY (`id_order`) REFERENCES `#__alfa_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_paylog_orderpay` FOREIGN KEY (`id_order_payment`) REFERENCES `#__alfa_order_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Items Prices
ALTER TABLE `#__alfa_items_prices`
    ADD CONSTRAINT `fk_itemprice_item` FOREIGN KEY (`item_id`) REFERENCES `#__alfa_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- User Info
ALTER TABLE `#__alfa_user_info`
    ADD CONSTRAINT `fk_userinfo_user` FOREIGN KEY (`id_user`) REFERENCES `#__alfa_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Categories self-referential (parent)
ALTER TABLE `#__alfa_categories`
    ADD CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `#__alfa_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
