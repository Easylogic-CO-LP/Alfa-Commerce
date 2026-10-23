<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

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
foreach ($manufacturers as $manufacturer) {
    $options[] = HTMLHelper::_('select.option', $manufacturer['id'], $manufacturer['name']);
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

// Create the select list
?>

<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    <?php echo Text::_('COM_ALFA_BATCH_MANUFACTURER_LABEL'); ?>
</label>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
    'select.genericlist',
    $options,
    'batch[manufacturer_id][]',
    array(
        // The select's id is given as genericlist's own option: an id inside list.attr loses to the one it writes.
        'id' => 'batch-manufacturer-id',
        'list.attr' => 'class="form-select" multiple data-placeholder="' . htmlspecialchars(Text::_('COM_ALFA_BATCH_MANUFACTURER_PLACEHOLDER'), ENT_QUOTES, 'UTF-8') . '"',
        'list.select' => ''
    ),
);
?>
</joomla-field-fancy-select>