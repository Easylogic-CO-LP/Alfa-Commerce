<?php
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

extract($displayData);


if(empty($logData) || empty($xml)) return;

$createHeadingLabel = function ($label, $type) {
    $field        = new \stdClass();
    $field->label = Text::_($label);
    $field->type  = $type;

    return $field;
};

// Get the labels from xml file
$fieldLabels = [];
foreach ($xml->fields->fieldset->field as $field)
{
    $fieldLabels[(string) $field['name']] = $createHeadingLabel($field['label'], $field['mysql_type']);
}

// Get table headings dynamically
if(is_array($logData)){
    $headers = array_keys($logData[0]); // Get keys from the first object of logs data from db.
}
else {
    $headers = array_keys(get_object_vars(reset($logData))); // Get keys from the first object of logs data from db.
}

$tableHeadings = $tableBody = '';

foreach ($headers as $header)
{

    // If the field from db does not have a label set, then we will create one.
    if (!isset($fieldLabels[$header]) || empty($fieldLabels[$header]->label))
    {
        $generatedLabel       = ucfirst(str_replace('_', ' ', $header));      // generate label from db column name
        $fieldLabels[$header] = $createHeadingLabel($generatedLabel, '');         // assign to the fieldLabel array of objects
    }

    $label = $fieldLabels[$header]->label;

    // Generate table headings dynamically
    $tableHeadings .= "<th>" . Text::_($label) . "</th>"; // Format heading
}

// Generate table body rows dynamically
foreach ($logData as $log)
{

    $tableBody .= "<tr>";
    foreach ($headers as $i => $header)
    {

        $label = $fieldLabels[$header]->label;
//        $value = htmlspecialchars($log->header ?? '');
        $value = htmlspecialchars($log[$header] ?? '');
        // TODO: check if the type is date or datetime and show the current date value with htmlHelper.
        $type = $fieldLabels[$header]->type;

        // Dates need to be shown on local time.
        if ($type == 'datetime' || $type == 'date')
        {
            $displayDate = HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC6'));
            $tableBody   .= "<td style='text-wrap: wrap;' data-th='" . $label . "'>" . $displayDate . "</td>";
        }
        else
            $tableBody .= "<td style='text-wrap: wrap;' data-th='" . $label . "'>" . $value . "</td>";
    }
    $tableBody .= "</tr>";
}

$html = <<<HTML
        <div class='table-responsive table-mobile-responsive'>
            <table class='table table-striped table-bordered'>
                <thead>
                    <tr>
                        $tableHeadings
                    </tr>
                </thead>
                <tbody>
                    $tableBody
                </tbody>
            </table>
        </div>
        HTML;

echo $html;

