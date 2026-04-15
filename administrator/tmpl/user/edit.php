<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
   ->useScript('form.validate');

$linkedUser = !empty($this->item->id_user);

$this->linkedUser = $linkedUser;
$this->editUrl    = $linkedUser
    ? $this->item->joomla_edit_url . '&' . Session::getFormToken() . '=1'
    : '';
?>

<form
    action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post"
    enctype="multipart/form-data"
    name="adminForm"
    id="user-form"
    class="form-validate form-horizontal">

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'details']); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'details', Text::_('COM_ALFA_TAB_USER_DETAILS')); ?>

    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">

                <?php echo $this->form->renderField('id'); ?>
                <?php echo $this->form->renderField('id_user'); ?>
                <?php echo $this->form->renderField('note'); ?>

                <?php if ($linkedUser) : ?>
                    <div class="control-group">
                        <div class="control-label">
                            <label><?php echo Text::_('COM_ALFA_FIELD_JOOMLA_ACCOUNT_LABEL'); ?></label>
                        </div>
                        <div class="controls">
                            <?php echo $this->loadTemplate('user_popup'); ?>
                        </div>
                    </div>
                <?php endif; ?>

            </fieldset>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="jform[id]"      value="<?php echo (int) $this->item->id; ?>" />
    <input type="hidden" name="jform[id_user]" value="<?php echo (int) ($this->item->id_user ?? 0); ?>" />
    <input type="hidden" name="task" value="" />
    <?php echo HTMLHelper::_('form.token'); ?>

</form>