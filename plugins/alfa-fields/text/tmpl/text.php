<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright   (C) 2016 Open Source Matters, Inc. <https://www.joomla.org>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

// FileLayout passes $displayData as a single array — pull the named
// keys ($field, $fieldParams, $item, $context, $value) into local scope.
extract($displayData);

$value = $field->value ?? '';

if ($value === '' || $value === null) {
    return;
}

if (is_array($value)) {
    $value = implode(', ', $value);
}

echo htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
