<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The Products list's batch of labels: the labels to give the checked products and the labels to take off them, two
 * pickers of the shop's labels (the Labels screen), as the manufacturers picker beside them.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$options = [];

foreach (Labels::all() as $id => $record) {
    $options[] = HTMLHelper::_('select.option', (int) $id, $record->label->name . ($record->state === 1 ? '' : ' (' . Text::_('JUNPUBLISHED') . ')'));
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->usePreset('choicesjs')
    ->useScript('webcomponent.field-fancy-select');

foreach (['label_add' => 'COM_ALFA_BATCH_LABEL_ADD_LABEL', 'label_remove' => 'COM_ALFA_BATCH_LABEL_REMOVE_LABEL'] as $command => $label) : ?>
    <div class="mb-3">
        <label id="batch-<?php echo $command; ?>-lbl" for="batch-<?php echo $command; ?>">
            <?php echo Text::_($label); ?>
        </label>
        <joomla-field-fancy-select>
            <?php echo HTMLHelper::_(
                'select.genericlist',
                $options,
                'batch[' . $command . '][]',
                [
                    'id' => 'batch-' . $command,
                    'list.attr' => 'class="form-select" multiple data-placeholder="' . htmlspecialchars(Text::_('COM_ALFA_BATCH_LABEL_PLACEHOLDER'), ENT_QUOTES, 'UTF-8') . '"',
                    'list.select' => '',
                ],
            ); ?>
        </joomla-field-fancy-select>
    </div>
<?php endforeach;
