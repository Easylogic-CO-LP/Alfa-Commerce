<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="formfieldgroup-form"
      class="form-validate form-horizontal">

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('title'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_FORMFIELDGROUP', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_FORMFIELDGROUP'); ?></legend>
                <?php echo $this->form->renderFieldset('general'); ?>
            </fieldset>
        </div>
        <div class="col-lg-3">
            <?php echo $this->form->renderFieldset('publish'); ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?><?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab, same rule as every Alfa edit
// screen: a contributed named fieldset is a new tab. The template's OWN named fieldsets
// are listed in $ownFieldsets and skipped, so nothing renders twice.
$ownFieldsets = ['general', 'publish'];
foreach ($this->form->getFieldsets() as $fieldset) {
    if (in_array($fieldset->name, $ownFieldsets, true)) {
        continue;
    }
    $fieldsetLabel = !empty($fieldset->label)
        ? Text::_($fieldset->label)
        : ucwords(str_replace('_', ' ', $fieldset->name));

    echo HTMLHelper::_('uitab.addTab', 'myTab', 'plg-' . $fieldset->name, $fieldsetLabel);
    echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
        . $fieldsetLabel . '</legend><div>'
        . $this->form->renderFieldset($fieldset->name)
        . '</div></fieldset></div></div>';
    echo HTMLHelper::_('uitab.endTab');
}
?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>
</form>
