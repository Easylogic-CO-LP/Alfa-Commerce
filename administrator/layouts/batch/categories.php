<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The categories picker of the Items list's batch dialog: the categories to assign to the selected items.
 *
 * A new assignment, so it follows the category picker rule: published and unpublished categories none of whose
 * parent categories is archived or trashed, an unpublished one marked.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$db = Factory::getContainer()->get('DatabaseDriver');
$query = $db->getQuery(true)
    ->select($db->quoteName(['a.id', 'a.state']))
    ->from($db->quoteName('#__alfa_categories', 'a'))
    ->whereIn($db->quoteName('a.state'), CategoryPickerPolicy::OFFERED_STATES);

// The name in the active language; translatable columns live in the per-language tables.
MultilingualHelper::addMultilingualJoinToQuery(
    query:             $query,
    mainAlias:         'a',
    mainPrimaryColumn: 'id',
    langTableBase:     '#__alfa_categories',
    langPrimaryColumn: 'id_category',
    fields:            ['name'],
);

$options = [];
$tree = CategoryPickerPolicy::tree(db: $db);

foreach ($db->setQuery($query)->loadAssocList() ?: [] as $category) {
    $reason = CategoryPickerPolicy::reason(id: (int) $category['id'], tree: $tree);

    // A category inside an archived or trashed category is not available either.
    if (!CategoryPickerPolicy::offered($reason)) {
        continue;
    }

    $options[] = HTMLHelper::_(
        'select.option',
        $category['id'],
        CategoryPickerPolicy::label(text: (string) $category['name'], reason: $reason),
    );
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

?>
<label id="batch-category-lbl" for="batch-category-id">
    <?php echo Text::_('COM_ALFA_BATCH_CATEGORY_LABEL'); ?>
</label>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
        'select.genericlist',
        $options,
        'batch[category_id][]',
        [
            // The select's id is given as genericlist's own option: an id inside list.attr loses to the one it writes.
            'id' => 'batch-category-id',
            'list.attr' => 'class="form-select" multiple data-placeholder="'
                . htmlspecialchars(Text::_('COM_ALFA_BATCH_CATEGORY_PLACEHOLDER'), ENT_QUOTES, 'UTF-8') . '"',
            'list.select' => '',
        ],
    ); ?>
</joomla-field-fancy-select>
