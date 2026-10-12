<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * User Info popup (modal iframe, tmpl=component): ONE saved-info row,
 * add (id=0) or edit, with the checkout fields rendered through the
 * same FieldsHelper pipeline as the storefront, showon + validation
 * engines included. Saving redirects to edit_userinfo_return which
 * reloads the parent (one view at a time: the order-popups doctrine).
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$input = Factory::getApplication()->getInput();
$userId = $input->getInt('user_id', 0);
$rowId = $input->getInt('info_id', 0);

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.validate.engine');
$wa->useScript('com_alfa.showon.engine');
$wa->useStyle('com_alfa.validate');
Text::script('COM_ALFA_VALIDATE_FIELD_INVALID');

$row = $rowId > 0 ? UserInfoHelper::getRow(userId: $userId, id: $rowId) : null;

// Built through the helper so this screen and the controller that saves it
// validate against the SAME field set.
$uinfoForm = UserInfoHelper::adminForm(userId: $userId);

if ($row !== null) {
    $values = get_object_vars($row);
    unset($values['id'], $values['id_user']);
    $uinfoForm->bind([FieldsHelper::FIELDS_KEY => $values]);
}
?>
<div class="container-fluid p-3">
    <form action="<?php echo Route::_('index.php?option=com_alfa&task=user.saveUserinfoForm'); ?>"
          method="post" name="adminForm" id="userinfo-popup-form" class="form-validate">

        <?php echo FieldsHelper::renderFieldset(form: $uinfoForm, target: 'userinfo'); ?>

        <button type="submit" class="btn btn-primary mt-2">
            <?php echo Text::_('COM_ALFA_USERINFO_SAVE'); ?>
        </button>

        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
        <input type="hidden" name="source_id" value="<?php echo $rowId; ?>">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>
