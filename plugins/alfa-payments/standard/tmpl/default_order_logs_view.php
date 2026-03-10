<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Order Logs View Layout - v3.5.0
 *
 * Shared layout for all plugins to render log entries in a modal.
 * Shows user-friendly messages when no logs exist instead of blank modal.
 *
 * Path: administrator/components/com_alfa/layouts/orders/default_order_logs_view.php
 * @since  3.0.0
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

extract($displayData);

// -- Handle missing schema gracefully (instead of blank modal) --
if (empty($xml)) {
    echo '<div class="alert alert-warning">'
        . '<span class="icon-warning-circle" aria-hidden="true"></span> '
        . Text::_('COM_ALFA_LOGS_NO_SCHEMA')
        . '</div>';

    return;
}

// -- Handle empty logs gracefully (instead of blank modal) --
if (empty($logData)) {
    echo '<div class="alert alert-info">'
        . '<span class="icon-info-circle" aria-hidden="true"></span> '
        . Text::_('COM_ALFA_LOGS_NO_ENTRIES')
        . '</div>';

    return;
}

// -- Build heading labels from XML schema --
$createHeadingLabel = function ($label, $type) {
    $field = new \stdClass();
    $field->label = Text::_($label);
    $field->type = $type;

    return $field;
};

$fieldLabels = [];

foreach ($xml->fields->fieldset->field as $field) {
    $fieldLabels[(string) $field['name']] = $createHeadingLabel($field['label'], $field['mysql_type']);
}

// -- Get column keys from first log row (supports arrays and objects) --
$firstRow = reset($logData);
$headers = is_array($firstRow)
    ? array_keys($firstRow)
    : array_keys(get_object_vars($firstRow));

// -- Build table headings --
$tableHeadings = '';

foreach ($headers as $header) {
    // Auto-generate label for DB columns not defined in XML
    if (!isset($fieldLabels[$header]) || empty($fieldLabels[$header]->label)) {
        $generatedLabel = ucfirst(str_replace('_', ' ', $header));
        $fieldLabels[$header] = $createHeadingLabel($generatedLabel, '');
    }

    $tableHeadings .= '<th>' . Text::_($fieldLabels[$header]->label) . '</th>';
}

// -- Build table body rows --
$tableBody = '';

foreach ($logData as $log) {
    $tableBody .= '<tr>';

    foreach ($headers as $header) {
        $label = $fieldLabels[$header]->label;
        $type = $fieldLabels[$header]->type;

        // Support both assoc arrays and objects
        $value = is_array($log)
            ? htmlspecialchars($log[$header] ?? '')
            : htmlspecialchars($log->$header ?? '');

        // Dates: convert to user local timezone via HTMLHelper
        if (($type === 'datetime' || $type === 'date') && !empty($value) && $value !== '0000-00-00 00:00:00') {
            $displayDate = HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC6'));
            $tableBody .= '<td style="text-wrap: wrap;" data-th="' . $label . '">' . $displayDate . '</td>';
        } else {
            $tableBody .= '<td style="text-wrap: wrap;" data-th="' . $label . '">' . $value . '</td>';
        }
    }

    $tableBody .= '</tr>';
}

// -- Render responsive table --
echo '<div class="table-responsive table-mobile-responsive">';
echo '<table class="table table-striped table-bordered">';
echo '<thead><tr>' . $tableHeadings . '</tr></thead>';
echo '<tbody>' . $tableBody . '</tbody>';
echo '</table></div>';
