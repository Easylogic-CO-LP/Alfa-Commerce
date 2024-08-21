<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    Manufacturer to select:
</label>

<?php

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;

// Get a database connection.
$db = Factory::getContainer()->get('DatabaseDriver');

// Create a new query object.
$query = $db->getQuery(true);

// Select the columns you want to retrieve.
$query->select($db->quoteName(['id', 'name']))
    ->from($db->quoteName('#__alfa_manufacturers'));

// Set the query and load the result.
$db->setQuery($query);

// Load the result as an associative array.
$manufacturers = $db->loadAssocList();

// Create options array for the select list
$options = array();
$options[] = HTMLHelper::_('select.option', '', '- Keep current manufacturer -');
foreach ($manufacturers as $manufacturer) {
    $options[] = HTMLHelper::_('select.option', $manufacturer['id'], $manufacturer['name']);
}

// Create the select list
echo HTMLHelper::_(
    'select.genericlist',
    $options,
    'batch[manufacturer_id][]',
    array(
        'list.attr' => 'class="form-select" id="batch-manufacturer-id" multiple ',
        'list.select' => ''
    ),
);
?>
