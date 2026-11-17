-- Alfa Commerce 2.2.1: the orders of one customer by the e-mail on the order, and the status e-mail switch's comment
--
-- The REST API's orders list gains filter[email], the orders carrying one e-mail, matched whole: a guest's orders are
-- found by it too. An index on the order's e-mail makes it a lookup instead of a read of every order, for the page and
-- for its count. It is an addition only: nothing is dropped or renamed, so a database kept by an uninstall and
-- reconnected keeps its data, and the install SQL creates the same index for a new shop. The statement carries
-- Joomla's CAN FAIL marker, as Joomla's own update files mark index changes: an update that stopped after it and is run
-- again finds the index already there and goes on.

ALTER TABLE `#__alfa_orders` ADD INDEX `idx_customer_email` (`customer_email`) /** CAN FAIL **/;

-- The comment of the status e-mail switch names what EmailService sends: the status e-mail, its parts and its wrapper.
-- It named the e-mail subject and body columns of the per-language status tables, which nothing read; those, and the
-- order columns written only empty (the coupon id and code, the invoice date, the invoice id of the order lines, the
-- parcels and the order's cart rules), are dropped by the installation itself (script.php, dropLeftoverColumns()),
-- each only where its table still has it, so a run again and the tables an uninstall kept go on. The same comment is
-- the install SQL's.
ALTER TABLE `#__alfa_orders_statuses` MODIFY `notify_customer` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'When 1, EmailService sends the customer the status e-mail (its parts in email_positions_customer, its wrapper email_layout_customer) on transition INTO this status.';

-- Alfa Commerce 2.2.1: the ISO 3166-2 code of a place below a country
--
-- A place below a country (a region, an island, a town) no longer carries invented country codes: code2 and code3
-- belong to a country, and a place below one may carry its ISO 3166-2 code in the new column subdivision_code instead
-- (GR-I for Attica, ES-CN for the Canary Islands). The column is added by the installation itself (script.php,
-- addMissingColumns()), only where the places table lacks it, so an update run again, a table an uninstall kept, and a
-- site whose database check already added the column all go on; the install SQL creates it for a new shop. The
-- countries the shop never changed are corrected after it (script.php), not here.
