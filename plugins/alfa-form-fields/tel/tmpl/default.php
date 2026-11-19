<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Tel default layout.
 *
 * Renders <a href="tel:+E164">formatted text</a>.
 * The href is always E.164 (what diallers expect). The visible text is
 * shaped by the field's `display_format` param (E164 / INTERNATIONAL / NATIONAL).
 *
 * Received in $displayData:
 *   field        loaded field object ($field->value = stored phone)
 *   fieldParams  merged plugin + per-field Registry
 *   item         surrounding record (order/user/cart), optional
 *   context      caller view.section string, optional
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

extract($displayData);

$value = (string) ($field->value ?? '');
if ($value === '') {
    return;
}

// The region a stored number without its country is read against: the field's Default region, else the shop's
// default country (2.2.2); a stored number is E.164 and carries its own.
$region = FieldsHelper::phoneDefaultRegion(fieldRegion: (string) $fieldParams->get('default_region', ''));
$displayFormat = strtoupper((string) $fieldParams->get('display_format', 'INTERNATIONAL'));

$href = $value;
$text = $value;

try {
    $util = PhoneNumberUtil::getInstance();
    $parsed = $util->parse($value, $region !== '' ? $region : null);

    // tel: href is always E.164; phone diallers require it unambiguous.
    $href = $util->format($parsed, PhoneNumberFormat::E164);

    $text = match ($displayFormat) {
        'E164' => $util->format($parsed, PhoneNumberFormat::E164),
        'NATIONAL' => $util->format($parsed, PhoneNumberFormat::NATIONAL),
        default => $util->format($parsed, PhoneNumberFormat::INTERNATIONAL),
    };
} catch (\Throwable $e) {
    // Keep raw fallbacks; don't break rendering for a single bad number.
}

echo '<a href="tel:' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
    . '</a>';
