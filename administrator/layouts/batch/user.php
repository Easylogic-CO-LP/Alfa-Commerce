<label id="batch-user-lbl" for="batch-user-id">
    User to select:
</label>

<?php

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;

// Get a database connection.
$db = Factory::getContainer()->get('DatabaseDriver');

// Create a new query object.
$query = $db->getQuery(true);

// Select the columns you want to retrieve.
$query->select($db->quoteName(['id','name']))
    ->from($db->quoteName('#__users'));

// Set the query and load the result.
$db->setQuery($query);

// Load the result as an associative array.
$users = $db->loadAssocList();

// Create options array for the select list
$options = array();

foreach ($users as $user) {
    $options[] = HTMLHelper::_('select.option', $user['id'], $user['name']);
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
    'batch[user_id][]',
    array(
        'list.attr' => 'class="form-select" id="batch-user-id" multiple data-placeholder="- Select a user -"',
        'list.select' => ''
    ),
);
?>
</joomla-field-fancy-select>
