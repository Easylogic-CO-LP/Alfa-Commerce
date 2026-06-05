<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    Category to select:
</label>

<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;

// Get a database connection.
$db = Factory::getContainer()->get('DatabaseDriver');

// Create a new query object.
$query = $db->getQuery(true);

// Select the id; the translatable name is resolved in the active language
// from the per-language tables (name no longer lives in the main table).
$query->select($db->quoteName('a.id'))
    ->from($db->quoteName('#__alfa_categories', 'a'));

MultilingualHelper::addMultilingualJoinToQuery(
    query:             $query,
    mainAlias:         'a',
    mainPrimaryColumn: 'id',
    langTableBase:     '#__alfa_categories',
    langPrimaryColumn: 'id_category',
    fields:            ['name'],
);

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