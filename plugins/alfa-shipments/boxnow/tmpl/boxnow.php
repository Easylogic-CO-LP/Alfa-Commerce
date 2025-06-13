<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaShipments.Standard
 *
 * @copyright   (C) 2016 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

//$value = $field->value;

// if ($value == '') {
//     return;
// }

// if (is_array($value)) {
//     $value = implode(', ', $value);
// }

// echo htmlentities($value);
// Default output for the Boxnow shipment plugin
echo htmlspecialchars($value ?? '');
