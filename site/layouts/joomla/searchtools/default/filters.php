<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Storefront override of joomla.searchtools.default.filters.
 *
 * Core renders every filter's label inside `<span class="visually-hidden">`: an
 * ADMIN assumption, where a power user recognises a control from its placeholder
 * alone. A shopper does not: the saving and discount filters are literally two
 * boxes reading "e.g. 10" and "e.g. 20". Every one of these fields already
 * carries a real label in filter_items.xml, so this override just stops hiding
 * them; a field that genuinely wants no label says hiddenLabel="true" and
 * FormField renders it visually-hidden on its own.
 *
 * Reached because Layout::searchtools() renders the searchtools layout resolved
 * by component (the active template's html/layouts/com_alfa first, then this
 * folder) and sublayout() carries that resolution down, so `filters` resolves
 * here and every other searchtools sublayout still falls back to core.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;

$data = $displayData;

// Load the form filters
$filters = $data['view']->filterForm->getGroup('filter');

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();

?>
<?php if ($filters) : ?>
    <?php foreach ($filters as $fieldName => $field) : ?>
        <?php if ($fieldName !== 'filter_search') : ?>
            <?php $dataShowOn = ''; ?>
            <?php if ($field->showon) : ?>
                <?php $wa->useScript('showon'); ?>
                <?php $dataShowOn = " data-showon='" . json_encode(FormHelper::parseShowOnConditions($field->showon, $field->formControl, $field->group)) . "'"; ?>
            <?php endif; ?>
            <div class="js-stools-field-filter filter-field-wrapper"<?php echo $dataShowOn; ?>>
                <?php // Rendered <label> markup, already translated (FormField::getLabel()).
                      // hiddenLabel is honoured by hand: FormField only consults that
                      // attribute inside renderField(), which this layout does not use. ?>
                <?php if ((string) $field->getAttribute('hiddenLabel') === 'true') : ?>
                    <span class="visually-hidden"><?php echo $field->label; ?></span>
                <?php else : ?>
                    <?php echo $field->label; ?>
                <?php endif; ?>
                <?php echo $field->input; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
