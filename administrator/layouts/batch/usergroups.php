<label id="batch-usergroup-lbl" for="batch-usergroup-id">
    Usergroup to select:
</label>

<?php

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;

// Get a database connection.
$db = Factory::getContainer()->get('DatabaseDriver');

// Create a new query object.
$query = $db->getQuery(true);

// Select the columns you want to retrieve.
$query->select($db->quoteName(['id', 'title']))
    ->from($db->quoteName('#__usergroups'));

// Set the query and load the result.
$db->setQuery($query);

// Load the result as an associative array.
$usergroups = $db->loadAssocList();

// Create options array for the select list
$options = array();

foreach ($usergroups as $usergroup) {
    $options[] = HTMLHelper::_('select.option', $usergroup['id'], $usergroup['title']);
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

// Create the select list
?>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
    'select.genericlist',
    $options,
    'batch[usergroup_id][]',
    array(
        'list.attr' => 'class="form-select" id="batch-usergroup-id" multiple data-placeholder="- Select a usergroup -"',
        'list.select' => ''
    ),
);
?>
</joomla-field-fancy-select>