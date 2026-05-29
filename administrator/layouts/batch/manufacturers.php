<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    Manufacturer to select:
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
    ->from($db->quoteName('#__alfa_manufacturers', 'a'));

MultilingualHelper::addMultilingualJoinToQuery(
    query:             $query,
    mainAlias:         'a',
    mainPrimaryColumn: 'id',
    langTableBase:     '#__alfa_manufacturers',
    langPrimaryColumn: 'id_manufacturer',
    fields:            ['name'],
);

// Set the query and load the result.
$db->setQuery($query);

// Load the result as an associative array.
$manufacturers = $db->loadAssocList();

// Create options array for the select list
$options = array();
// $options[] = HTMLHelper::_('select.option', '', '- Keep current manufacturer -');
foreach ($manufacturers as $manufacturer) {
    $options[] = HTMLHelper::_('select.option', $manufacturer['id'], $manufacturer['name']);
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
    'batch[manufacturer_id][]',
    array(
        'list.attr' => 'class="form-select" id="batch-manufacturer-id" multiple data-placeholder="- Select a manufacturer -"',
        'list.select' => ''
    ),
);
?>
</joomla-field-fancy-select>