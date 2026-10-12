<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Reload pattern: Apply posts the WHOLE cart form to cart.pickUserinfo,
// so typed values survive the round trip. Rendered once per role: the
// view sets $this->pickerRole ('billing') before loadTemplate; default
// is the delivery section.
$billing = ($this->pickerRole ?? '') === 'billing';
$pickName = $billing ? 'userinfo_pick_billing' : 'userinfo_pick';
$pickedId = $billing ? (int) $this->pickedBillingInfoId : (int) $this->pickedUserInfoId;
$newPicked = $billing ? $this->newBillingPicked : $this->newUserInfoPicked;
?>
<div class="cart-user-info">
    <label for="<?php echo $pickName; ?>"><?php echo Text::_('COM_ALFA_USERINFO_PICK_SAVED'); ?></label>
    <select id="<?php echo $pickName; ?>" name="<?php echo $pickName; ?>" class="form-select cart-user-info-select">
        <option value="new"<?php echo $newPicked ? ' selected' : ''; ?>>
            <?php echo Text::_('COM_ALFA_USERINFO_NEW_DETAILS'); ?>
        </option>
        <?php foreach ($this->savedUserInfo as $row) : ?>
            <option value="<?php echo (int) $row->id; ?>"<?php echo ($pickedId === (int) $row->id) ? ' selected' : ''; ?>>
                <?php echo $this->escape(UserInfoHelper::label($row)); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="action secondary small" formnovalidate
            name="picker_apply" value="<?php echo $billing ? 'billing' : 'delivery'; ?>"
            formaction="<?php echo Route::_('index.php?option=com_alfa&task=cart.pickUserinfo'); ?>">
        <?php echo Text::_('COM_ALFA_USERINFO_USE_DETAILS'); ?>
    </button>
</div>
