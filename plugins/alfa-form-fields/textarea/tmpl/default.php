<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

// FileLayout passes $displayData as a single array; pull the named
// keys ($field, $fieldParams, $item, $context, $value) into local scope.
extract($displayData);

$value = $field->value ?? '';

if ($value === '' || $value === null) {
    return;
}

// Buyer-submitted value: escape by default. It is rendered into order emails and
// admin screens, so passing it through content.prepare (raw HTML + onContentPrepare
// plugins) would let a shopper inject markup or {loadposition}-style shortcodes.
echo nl2br(htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
