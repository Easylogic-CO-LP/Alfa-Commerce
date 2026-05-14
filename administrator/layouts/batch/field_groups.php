<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$db    = Factory::getContainer()->get('DatabaseDriver');
$query = $db->getQuery(true)
    ->select($db->quoteName(['id', 'title', 'state']))
    ->from($db->quoteName('#__alfa_form_field_groups'))
    ->order('ordering ASC');
$db->setQuery($query);
$groups = $db->loadObjectList() ?: [];

$options   = [];
$options[] = HTMLHelper::_('select.option', '',  '- ' . Text::_('JOPTION_SELECT') . ' -');
$options[] = HTMLHelper::_('select.option', '0', Text::_('COM_ALFA_FORM_OPTION_FIELD_GROUP_NONE'));

foreach ($groups as $group) {
    $label = $group->title . ((int) $group->state === 0 ? ' (' . Text::_('COM_ALFA_FORM_FIELDS_GROUP_DISABLED') . ')' : '');
    $options[] = HTMLHelper::_('select.option', (int) $group->id, $label);
}

?>
<label for="batch-field-group-id">
    <?php echo Text::_('COM_ALFA_FORM_BATCH_GROUP_LABEL'); ?>
</label>

<?php echo HTMLHelper::_(
    'select.genericlist',
    $options,
    'batch[group_id]',
    [
        'list.attr'   => 'class="form-select" id="batch-field-group-id"',
        'list.select' => '',
    ]
); ?>
