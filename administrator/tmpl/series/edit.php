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
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$hasDocuments = !empty($this->item->id) && (int) ($this->item->next_number ?? 1) > 1;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" enctype="multipart/form-data" name="adminForm" id="series-form"
      class="form-validate form-horizontal">

    <?php echo HTMLHelper::_('uitab.startTabSet', 'com_alfa-series', ['active' => 'details', 'recall' => true, 'breakpoint' => 768]); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'com_alfa-series', 'details', Text::_('COM_ALFA_SERIES_TAB_DETAILS')); ?>
        <div class="row">
            <div class="col-lg-6">
                <?php echo $this->form->renderField('doc_type'); ?>
                <?php echo $this->form->renderField('code'); ?>
                <?php echo $this->form->renderField('title'); ?>
                <?php echo $this->form->renderField('state'); ?>
                <?php echo $this->form->renderField('is_default'); ?>
            </div>
            <div class="col-lg-6">
                <?php echo $this->form->renderField('prefix'); ?>
                <?php echo $this->form->renderField('padding'); ?>
                <?php echo $this->form->renderField('next_number'); ?>
                <?php if ($hasDocuments) : ?>
                    <div class="alert alert-info py-2">
                        <?php echo Text::_('COM_ALFA_SERIES_COUNTER_LOCKED_HINT'); ?>
                    </div>
                <?php endif; ?>
                <?php echo $this->form->renderField('year_scoped'); ?>
                <?php echo $this->form->renderField('branch_code'); ?>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'com_alfa-series', 'seller', Text::_('COM_ALFA_SERIES_TAB_SELLER')); ?>
        <div class="alert alert-info py-2">
            <?php echo Text::_('COM_ALFA_SERIES_SELLER_HINT'); ?>
        </div>
        <div class="row">
            <div class="col-lg-6">
                <?php echo $this->form->renderField('seller_name'); ?>
                <?php echo $this->form->renderField('seller_vat_number'); ?>
                <?php echo $this->form->renderField('seller_tax_office'); ?>
                <?php echo $this->form->renderField('seller_activity'); ?>
            </div>
            <div class="col-lg-6">
                <?php echo $this->form->renderField('seller_address'); ?>
                <?php echo $this->form->renderField('seller_extra'); ?>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'com_alfa-series', 'templating', Text::_('COM_ALFA_SERIES_TAB_TEMPLATING')); ?>
        <?php // Help note: how layouts + positions + sections fit together.
              // Collapsed <details> (the order-emails pattern) so it sits
              // quietly above the layout picker and composer. ?>
        <details class="mb-3">
            <summary style="cursor:pointer;font-weight:600;">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_DOCUMENT_POSITIONS_NOTE_TITLE'); ?>
            </summary>
            <div class="mt-2">
                <?php // %1$s = active admin template element (e.g. 'atum') so
                      // the override steps name the real template + exact path. ?>
                <?php echo Text::sprintf('COM_ALFA_DOCUMENT_POSITIONS_NOTE_BODY', Factory::getApplication()->getTemplate()); ?>
            </div>
        </details>

        <div class="row">
            <div class="col-lg-6">
                <?php echo $this->form->renderField('layout'); ?>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php echo $this->form->getInput('positions'); ?>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?><?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab; same rule as every Alfa edit
// screen: a contributed named fieldset is a new tab. The template's OWN named fieldsets
// are listed in $ownFieldsets and skipped, so nothing renders twice.
$ownFieldsets = ['series'];
foreach ($this->form->getFieldsets() as $fieldset) {
    if (in_array($fieldset->name, $ownFieldsets, true)) {
        continue;
    }
    $fieldsetLabel = !empty($fieldset->label)
        ? Text::_($fieldset->label)
        : ucwords(str_replace('_', ' ', $fieldset->name));

    echo HTMLHelper::_('uitab.addTab', 'com_alfa-series', 'plg-' . $fieldset->name, $fieldsetLabel);
    echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
        . $fieldsetLabel . '</legend><div>'
        . $this->form->renderFieldset($fieldset->name)
        . '</div></fieldset></div></div>';
    echo HTMLHelper::_('uitab.endTab');
}
?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value=""/>
    <?php echo $this->form->getInput('id'); ?>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
