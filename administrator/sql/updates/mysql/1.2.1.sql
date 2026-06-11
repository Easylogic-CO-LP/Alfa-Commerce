--
-- Plugin group rename: alfa-fields → alfa-form-fields. The group holds the
-- checkout/order FORM fields; the product fields system will use alfa-item-fields.
-- Rename the existing plugin rows in place so enabled-state and params survive —
-- the bundled plugins then reinstall over the renamed rows later in this update
-- (schema SQL runs before the script.php update() hook installs plugins).
--
UPDATE `#__extensions`
   SET `folder` = 'alfa-form-fields',
       `name`   = REPLACE(`name`, 'plg_alfa-fields_', 'plg_alfa-form-fields_')
 WHERE `type` = 'plugin'
   AND `folder` = 'alfa-fields';
