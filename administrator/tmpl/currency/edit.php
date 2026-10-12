<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate');

$input = Factory::getApplication()->getInput();

?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="currency-form"
        class="form-validate form-horizontal"
        aria-label="<?php echo Text::_('COM_ALFA_CURRENCY_FORM_TITLE_' . ((int)$this->item->id === 0 ? 'NEW' : 'EDIT'), true); ?>">

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_CURRENCY', true)); ?>
    <div class="row">
        <div class="col-lg-9">
	        <?php echo $this->form->renderField('code'); ?>
	        <?php echo $this->form->renderField('name'); ?>
	        <?php echo $this->form->renderField('symbol'); ?>
	        <?php echo $this->form->renderField('number'); ?>

	        <?php echo $this->form->renderField('decimal_separator'); ?>
	        <?php echo $this->form->renderField('thousand_separator'); ?>
	        <?php echo $this->form->renderField('decimal_place'); ?>
	        <?php echo $this->form->renderField('format_pattern'); ?>
        </div>


        <div class="col-lg-3">

			<?php echo $this->form->renderField('state'); ?>

			<?php echo $this->form->renderField('version_note'); ?>

        </div>
    </div>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php // This tab holds publishing data only; no SEO fields exist for a currency. ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('JGLOBAL_FIELDSET_PUBLISHING')); ?>

    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <div>

					<?php echo $this->form->renderField('created_by'); ?>

					<?php echo $this->form->renderField('modified_by'); ?>

					<?php echo $this->form->renderField('modified'); ?>

					<?php echo $this->form->renderField('id'); ?>

                </div>
            </fieldset>
        </div>

    </div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?><?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab: the entity-screen counterpart of the
// Panel form template's rule that a new fieldset is a new tab. Only contributions can appear:
// the form's own fieldset is anonymous, and Form::getFieldsets() returns named ones only, so
// with no contributing plugin this loop emits nothing at all.
foreach ($this->form->getFieldsets() as $fieldset) {
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

