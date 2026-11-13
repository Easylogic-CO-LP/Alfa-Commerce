-- Alfa Commerce 2.2.1: the orders of one customer by the e-mail on the order
--
-- The REST API's orders list gains filter[email], the orders carrying one e-mail, matched whole: a guest's orders are
-- found by it too. An index on the order's e-mail makes it a lookup instead of a read of every order, for the page and
-- for its count. It is an addition only: nothing is dropped or renamed, so a database kept by an uninstall and
-- reconnected keeps its data, and the install SQL creates the same index for a new shop. The statement carries
-- Joomla's CAN FAIL marker, as Joomla's own update files mark index changes: an update that stopped after it and is run
-- again finds the index already there and goes on.

ALTER TABLE `#__alfa_orders` ADD INDEX `idx_customer_email` (`customer_email`) /** CAN FAIL **/;
