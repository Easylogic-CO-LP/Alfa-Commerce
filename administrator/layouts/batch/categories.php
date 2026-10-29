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

use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$options = [];

foreach (CategoryPickerPolicy::offeredOptions() as $option) {
    $options[] = HTMLHelper::_('select.option', $option['value'], $option['text']);
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
