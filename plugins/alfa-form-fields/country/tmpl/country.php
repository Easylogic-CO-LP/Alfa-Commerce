<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Plugin\AlfaFormFields\Country\Extension\Country;

// FileLayout passes $displayData as a single array; pull the named
// keys ($field, $fieldParams, $item, $context, $value) into local scope.
extract($displayData);

$placeId = (int) ($field->value ?? 0);

if ($placeId <= 0) {
    return;
}

echo htmlspecialchars(Country::placeLabel($placeId) ?: (string) $placeId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
