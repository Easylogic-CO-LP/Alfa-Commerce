<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
	->useScript('keepalive')
	->useScript('form.validate');


// $input = Factory::getApplication()->getInput();

$ignoreFieldsets = ['general', 'publish'];
// 'paymentparams' and any other from any plugin that we want to load params.xml of shipment plugin should have <fields name="paymentparams"> to work

$fieldsets = $this->form->getFieldsets();

?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="payment-form" class="form-validate form-horizontal">

	<div class="row title-alias form-vertical mb-3">
        <div class="col-12">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <!-- <div class="col-12 col-md-6"> -->
            <?php //echo $this->form->renderField('alias'); ?>
        <!-- </div> -->
    </div>

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_PAYMENT', true)); ?>
	<div class="row">
		<div class="col-lg-9">
			<fieldset class="adminform">
				<legend><?php echo Text::_('COM_ALFA_FIELDSET_PAYMENT'); ?></legend>
				<?php echo $this->form->renderFieldset('general'); ?>
			</fieldset>
		</div>
		 <div class="col-lg-3">
		 	<?php echo $this->form->renderFieldset('publish'); ?>
        </div>
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	    <?php
        foreach ($fieldsets as $fieldsetName => $fieldset){
            if (in_array($fieldsetName, $ignoreFieldsets)) {
                continue;
            }
            // Fallback label if not defined
            $fieldSetLabel = isset($fieldset->label) && !empty($fieldset->label)
                ? Text::_($fieldset->label)
                : ucwords(str_replace('_', ' ', $fieldsetName));

            echo HTMLHelper::_('uitab.addTab', 'myTab', $fieldsetName, Text::_($fieldSetLabel) , true);
            ?>
            <div class="row">
                <div class="col-lg-12">
                    <fieldset class="adminform">
                        <?php echo $this->form->renderFieldset($fieldsetName); ?>
                    </fieldset>
                </div>
            </div>
            <?php
            echo HTMLHelper::_('uitab.endTab');
        }
    ?>
		
	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

	<input type="hidden" name="task" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>

</form>
