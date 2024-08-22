<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    Category to select:
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
    ->from($db->quoteName('#__alfa_categories'));

// Set the query and load the result.
$db->setQuery($query);

// Load the result as an associative array.
$categories = $db->loadAssocList();

// Create options array for the select list
$options = array();
// $options[] = HTMLHelper::_('select.option', '', '- Keep current category -');
foreach ($categories as $category) {
    $options[] = HTMLHelper::_('select.option', $category['id'], $category['name']);
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

?>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
        'select.genericlist',
        $options,
        'batch[category_id][]',
        array(
            'list.attr' => 'class="form-select" id="batch-category-id" multiple data-placeholder="- Select a category -" ',
            'list.select' => ''
        ),
    ); ?>
</joomla-field-fancy-select>