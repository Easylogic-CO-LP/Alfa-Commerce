<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

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

<label id="batch-usergroup-lbl" for="batch-usergroup-id">
    <?php echo Text::_('COM_ALFA_BATCH_USERGROUP_LABEL'); ?>
</label>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
    'select.genericlist',
    $options,
    'batch[usergroup_id][]',
    array(
        // The select's id is given as genericlist's own option: an id inside list.attr loses to the one it writes.
        'id' => 'batch-usergroup-id',
        'list.attr' => 'class="form-select" multiple data-placeholder="' . htmlspecialchars(Text::_('COM_ALFA_BATCH_USERGROUP_PLACEHOLDER'), ENT_QUOTES, 'UTF-8') . '"',
        'list.select' => ''
    ),
);
?>
</joomla-field-fancy-select>