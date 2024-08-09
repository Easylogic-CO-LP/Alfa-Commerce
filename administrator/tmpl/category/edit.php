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
$wa->useScript('keepalive')
    ->useScript('form.validate');

$input = Factory::getApplication()->getInput();

//HTMLHelper::_('bootstrap.tooltip');
?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int)$this->item->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="category-form"
        class="form-validate form-horizontal"
        aria-label="<?php echo Text::_('COM_ALFA_CATEGORY_FORM_TITLE_' . ((int)$this->item->id === 0 ? 'NEW' : 'EDIT'), true); ?>">
    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('alias'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', array('active' => 'category')); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'category', Text::_('COM_ALFA_TAB_CATEGORY', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <div>
                <fieldset class="adminform">
                    <div class="mb-3">
                        <?php echo $this->form->getLabel('desc'); ?>
                    </div>
                    <?php echo $this->form->getInput('desc'); ?>
                </fieldset>
            </div>
        </div>
        <div class="col-lg-3">
            <?php echo $this->form->renderField('parent_id'); ?>

            <?php echo $this->form->renderField('state'); ?>

            <?php echo $this->form->renderField('version_note'); ?>

        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('COM_ALFA_FIELDSET_PUBLISHING_SEO')); ?>
    <div class="row">
        <div class="col-12 col-lg-6">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <div>
                    <?php echo $this->form->renderField('publish_up'); ?>

                    <?php echo $this->form->renderField('publish_down'); ?>

                    <?php echo $this->form->renderField('allowedUsers'); ?>

                    <?php echo $this->form->renderField('allowedUserGroups'); ?>

                    <?php echo $this->form->renderField('created_by'); ?>

                    <?php echo $this->form->renderField('modified_by'); ?>

                    <?php echo $this->form->renderField('id'); ?>


                </div>
            </fieldset>
        </div>
        <div class="col-12 col-lg-6">
            <fieldset id="fieldset-metadata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_METADATA_OPTIONS'); ?></legend>
                <div>
                    <?php echo $this->form->renderField('meta_title'); ?>
                    <?php echo $this->form->renderField('meta_desc'); ?>
                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <!--    <input type="hidden" name="jform[ordering]" value="--><?php //echo $this->item->ordering; ?><!--" />-->
    <!--    <input type="hidden" name="jform[checked_out]" value="-->
    <?php //echo $this->item->checked_out; ?><!--" />-->
    <!--    <input type="hidden" name="jform[checked_out_time]" value="-->
    <?php //echo $this->item->checked_out_time; ?><!--" />-->


    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="return" value="<?php echo $input->getBase64('return'); ?>">

    <?php echo HTMLHelper::_('form.token'); ?>

</form>
