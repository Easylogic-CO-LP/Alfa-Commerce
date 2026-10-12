<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart rule edit: two tabs. Everything that describes WHAT the rule does
 * (discount, limits) is one screen; Conditions is the separate question of
 * WHEN it fires.
 *
 * THE FIELDSETS ARE THE LAYOUT. Each block is one renderFieldset() call, never
 * a list of renderField() calls, so a field added to forms/cartrule.xml (or
 * injected by a plugin through onAlfaAdminForm) appears where its fieldset is
 * rendered without this template knowing it exists. Only `identity` is rendered
 * by name, because it sits above the tab set rather than inside a block.
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate')
	->useScript('showon');

// Rendered as their own blocks below; everything else the form carries becomes
// its own tab in the contributed-tabs loop at the foot of this file.
$ownFieldsets = ['identity', 'general', 'publish', 'discount', 'limits', 'targeting', 'conditions'];
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=cartrule&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" enctype="multipart/form-data" name="adminForm" id="cartrule-form"
      class="form-validate form-horizontal">

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
			<?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
			<?php echo $this->form->renderField('name_customer'); ?>
        </div>
    </div>

	<?php echo HTMLHelper::_('uitab.startTabSet', 'cartruleTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

	<?php // 1. GENERAL: what the rule gives, to whom, how often?>
	<?php echo HTMLHelper::_('uitab.addTab', 'cartruleTab', 'general', Text::_('COM_ALFA_CARTRULE_TAB_GENERAL', true)); ?>
    <div class="row">
        <div class="col-lg-9">
			<?php // Holds the coupon code, plus anything a plugin contributed. ?>
			<?php if ($top = $this->form->renderFieldset('general')) : ?>
            <fieldset class="adminform"><?php echo $top; ?></fieldset>
			<?php endif; ?>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_CARTRULE_TAB_DISCOUNT'); ?></legend>
                <p class="text-muted"><?php echo Text::_('COM_ALFA_CARTRULE_DISCOUNT_HINT'); ?></p>
				<?php echo $this->form->renderFieldset('discount'); ?>
            </fieldset>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_CARTRULE_FIELDSET_LIMITS'); ?></legend>
				<?php echo $this->form->renderFieldset('limits'); ?>
            </fieldset>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_CARTRULE_FIELDSET_TARGETING'); ?></legend>
				<?php echo $this->form->renderFieldset('targeting'); ?>
            </fieldset>
        </div>
        <div class="col-lg-3">
            <fieldset class="adminform">
				<?php echo $this->form->renderFieldset('publish'); ?>
            </fieldset>
			<?php // A plugin hangs a compact card or a button here (e.g. a
			      // "View usage" popup) without touching this template. ?>
			<?php echo $this->renderSlot('cartrule-sidebar'); ?>
        </div>
    </div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php // 2. CONDITIONS: when it unlocks?>
	<?php echo HTMLHelper::_('uitab.addTab', 'cartruleTab', 'conditions', Text::_('COM_ALFA_CARTRULE_TAB_CONDITIONS', true)); ?>
    <fieldset class="adminform">
        <p class="text-muted"><?php echo Text::_('COM_ALFA_CARTRULE_CONDITIONS_HINT'); ?></p>
		<?php echo $this->form->renderFieldset('conditions'); ?>
    </fieldset>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab, same rule as every Alfa edit
// screen: a contributed named fieldset is a new tab. The blocks above are listed in
// $ownFieldsets and skipped, so nothing renders twice. A plugin wanting to sit INSIDE
// an existing block adds its field to that fieldset instead, and renderFieldset picks
// it up with no change here.
foreach ($this->form->getFieldsets() as $fieldset) {
    if (in_array($fieldset->name, $ownFieldsets, true)) {
        continue;
    }
    $fieldsetLabel = !empty($fieldset->label)
        ? Text::_($fieldset->label)
        : ucwords(str_replace('_', ' ', $fieldset->name));

    echo HTMLHelper::_('uitab.addTab', 'cartruleTab', 'plg-' . $fieldset->name, $fieldsetLabel);
    echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
        . $fieldsetLabel . '</legend><div>'
        . $this->form->renderFieldset($fieldset->name)
        . '</div></fieldset></div></div>';
    echo HTMLHelper::_('uitab.endTab');
}
?><?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
