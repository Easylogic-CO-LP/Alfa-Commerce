<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Destination picker for the categories list's "Set parent" batch action.
 *
 * The whole tree is loaded straight from the table rather than through the
 * Categories list model: that model populates its state from the merchant's
 * remembered list filters (search, state, page limit), and AlfaHelper's tree walk
 * DROPS any node whose parent is missing from the set; a paginated picker would
 * therefore hide most of the tree.
 *
 * Trashed and archived categories are excluded: they are not valid destinations.
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$db    = Factory::getContainer()->get('DatabaseDriver');
$query = $db->getQuery(true)
    ->select($db->quoteName(['a.id', 'a.parent_id']))
    ->from($db->quoteName('#__alfa_categories', 'a'))
    ->where($db->quoteName('a.state') . ' IN (0, 1)')
    ->order($db->quoteName('a.ordering') . ' ASC');

// Name in the active language; translatable columns live in the per-language tables.
MultilingualHelper::addMultilingualJoinToQuery(
    query:             $query,
    mainAlias:         'a',
    mainPrimaryColumn: 'id',
    langTableBase:     '#__alfa_categories',
    langPrimaryColumn: 'id_category',
    fields:            ['name'],
);

$categories = AlfaHelper::addHierarchyData(items: $db->setQuery($query)->loadObjectList() ?: []);

$options   = [];
// JOPTION_SELECT_CATEGORY, not the bare JOPTION_SELECT the other batch layouts
// use; that key does not exist in core and renders as its own name.
$options[] = HTMLHelper::_('select.option', '', Text::_('JOPTION_SELECT_CATEGORY'));
// Non-empty sentinel: AdminModel::batch() drops a command whose value is empty(),
// and the string '0' is empty.
$options[] = HTMLHelper::_('select.option', 'root', Text::_('COM_ALFA_CATEGORY_BATCH_PARENT_ROOT'));

foreach ($categories as $category) {
    $options[] = HTMLHelper::_(
        'select.option',
        (string) (int) $category->id,
        str_repeat('- ', (int) $category->depth) . $category->name,
    );
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

?>
<label for="batch-category-parent-id">
    <?php echo Text::_('COM_ALFA_CATEGORY_BATCH_PARENT_LABEL'); ?>
</label>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
        'select.genericlist',
        $options,
        'batch[parent_id]',
        [
            'list.attr'   => 'class="form-select" id="batch-category-parent-id"',
            'list.select' => '',
        ],
    ); ?>
</joomla-field-fancy-select>
