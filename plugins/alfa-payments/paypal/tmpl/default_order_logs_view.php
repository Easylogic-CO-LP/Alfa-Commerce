<?php

defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

extract($displayData);

if (empty($xml)) {
    echo '<div class="alert alert-warning"><span class="icon-warning-circle" aria-hidden="true"></span> ' . Text::_('COM_ALFA_LOGS_NO_SCHEMA') . '</div>';
    return;
}

if (empty($logData)) {
    echo '<div class="alert alert-info"><span class="icon-info-circle" aria-hidden="true"></span> ' . Text::_('COM_ALFA_LOGS_NO_ENTRIES') . '</div>';
    return;
}

$createHeadingLabel = function ($label, $type) {
    $field = new \stdClass();
    $field->label = Text::_($label);
    $field->type = $type;
    return $field;
};

$fieldLabels = [];
foreach ($xml->fields->fieldset->field as $field) {
    $fieldLabels[(string) $field['name']] = $createHeadingLabel((string) $field['label'], (string) $field['mysql_type']);
}

$firstRow = reset($logData);
$headers = is_array($firstRow) ? array_keys($firstRow) : array_keys(get_object_vars($firstRow));

$tableHeadings = '';
foreach ($headers as $header) {
    if (!isset($fieldLabels[$header]) || empty($fieldLabels[$header]->label)) {
        $fieldLabels[$header] = $createHeadingLabel(ucfirst(str_replace('_', ' ', $header)), '');
    }
    $tableHeadings .= '<th>' . Text::_($fieldLabels[$header]->label) . '</th>';
}

$tableBody = '';
foreach ($logData as $log) {
    $tableBody .= '<tr>';
    foreach ($headers as $header) {
        $label = $fieldLabels[$header]->label;
        $type = $fieldLabels[$header]->type;
        $value = is_array($log) ? htmlspecialchars($log[$header] ?? '') : htmlspecialchars($log->$header ?? '');
        if (($type === 'datetime' || $type === 'date') && !empty($value) && $value !== '0000-00-00 00:00:00') {
            $tableBody .= '<td style="text-wrap:wrap" data-th="' . $label . '">' . HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC6')) . '</td>';
        } else {
            $tableBody .= '<td style="text-wrap:wrap" data-th="' . $label . '">' . $value . '</td>';
        }
    }
    $tableBody .= '</tr>';
}

echo '<div class="table-responsive table-mobile-responsive">';
echo '<table class="table table-striped table-bordered">';
echo '<thead><tr>' . $tableHeadings . '</tr></thead>';
echo '<tbody>' . $tableBody . '</tbody>';
echo '</table></div>';
