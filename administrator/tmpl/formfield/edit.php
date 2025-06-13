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
use Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('keepalive')
    ->useScript('form.validate');


$input = Factory::getApplication()->getInput();

//$pluginGroup = 'alfa-formfields';
//$plugins = PluginHelper::getPlugin($pluginGroup);// Get a list of all plugins in the specified group
//
//echo "<pre>";
//print_r($plugins);
//echo "</pre>";
//exit;

$ignoreFieldsets = ['general', 'publish', 'meta'];
// 'shipmentsparams' and any other from any plugin that we want to load params.xml of shipment plugin should have <fields name="shipmentsparams"> to work

$fieldsets = $this->form->getFieldsets();

?>

<script>
document.addEventListener('DOMContentLoaded', () => {

  const title = document.getElementById('jform_name');
  title.dpOldValue = title.value;
  title.addEventListener('change', ({
    currentTarget
  }) => {
    const label = document.getElementById('jform_field_label');
    const changedTitle = currentTarget;
    if (changedTitle.dpOldValue === label.value) {
      label.value = changedTitle.value;
    }
    changedTitle.dpOldValue = changedTitle.value;
  });
});
</script>

<form
    action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post" enctype="multipart/form-data" name="adminForm" id="formitem-form" class="form-validate form-horizontal">

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
<!--        <div class="col-12 col-md-6">-->
<!--            --><?php //echo $this->form->renderField('alias'); ?>
<!--        </div>-->
    </div>


    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_FORMFIELD', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_FORMFIELD'); ?></legend>
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

<!--    --><?php //echo HTMLHelper::_('uitab.addTab', 'myTab', 'prices', Text::_('Prices')); ?>
<!--    <div class="row">-->
<!--        <div class="col-12">-->
<!--            <fieldset id="fieldset-pricedata" class="options-form">-->
<!--                <legend>--><?php //echo Text::_('COM_ALFA_FORM_FIELDSET_PRICE'); ?><!--</legend>-->
<!---->
<!--                --><?php //echo $this->form->renderField('prices'); ?>
<!---->
<!--            </fieldset>-->
<!--        </div>-->
<!---->
<!--    </div>-->
<!--    --><?php //echo HTMLHelper::_('uitab.endTab'); ?>

<!--    --><?php //echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('COM_ALFA_FIELDSET_PUBLISHING_SEO')); ?>
<!--    <div class="row">-->
<!--        <div class="col-12 col-lg-6">-->
<!--            <fieldset id="fieldset-publishingdata" class="options-form">-->
<!--                <legend>--><?php //echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?><!--</legend>-->
<!--                --><?php //echo $this->form->renderField('allowedUsers'); ?>
<!--                --><?php //echo $this->form->renderField('allowedUserGroups'); ?>
<!---->
<!--                --><?php //echo $this->form->renderFieldset('publish'); ?>
<!---->
<!--                --><?php //echo $this->form->renderField('id'); ?>
<!--            </fieldset>-->
<!--        </div>-->
<!--        <div class="col-12 col-lg-6">-->
<!--            <fieldset id="fieldset-metadata" class="options-form">-->
<!--                <legend>--><?php //echo Text::_('JGLOBAL_FIELDSET_METADATA_OPTIONS'); ?><!--</legend>-->
<!--                <div>-->
<!--                    --><?php //echo $this->form->renderFieldset('meta'); ?>
<!--                </div>-->
<!--            </fieldset>-->
<!--        </div>-->
<!--    </div>-->
<!--    --><?php //echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value=""/>
    <?php echo HTMLHelper::_('form.token'); ?>

</form>
