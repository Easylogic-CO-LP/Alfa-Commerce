<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The notice a storefront shopper's cart carries in the builder, and the body of
 * the dialog behind the toolbar's Take over this cart.
 *
 * Server-rendered, and the dialog is Joomla's own (joomla.dialog-autocreate,
 * loaded by the toolbar's popup layout): the button posts cart.takeOver like any
 * other builder form. The confirmation is a UX safeguard: every endpoint asks
 * Carts: Edit for this cart whether or not it ever appeared.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

if (!$this->isShopperCart || !$this->canCheckout) {
    return;
}

$next = $this->takenOver
    ? 'COM_ALFA_CART_SHOPPER_CART_TAKEN_OVER'
    : ($this->canTakeOver ? 'COM_ALFA_CART_SHOPPER_CART_TAKEOVER_HINT' : 'COM_ALFA_CART_SHOPPER_CART_READONLY');
?>
<div class="alert <?php echo $this->takenOver ? 'alert-info' : 'alert-warning'; ?>" role="status" data-alfa-shopper-cart>
    <p class="fw-semibold mb-1"><?php echo Text::_('COM_ALFA_CART_SHOPPER_CART_NOTICE'); ?></p>
    <p class="mb-0"><?php echo Text::_($next); ?></p>
</div>

<?php if ($this->canTakeOver) : ?>
    <template id="joomla-dialog-takeover">
        <div class="p-4">
            <p><?php echo Text::_('COM_ALFA_CART_SHOPPER_CART_NOTICE'); ?></p>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_CART_TAKEOVER_CONFIRM'); ?></p>
        </div>

        <div class="btn-toolbar p-3 border-top">
            <form method="dialog" class="m-0">
                <button type="submit" class="btn btn-outline-secondary"><?php echo Text::_('JCANCEL'); ?></button>
            </form>

            <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="m-0 ms-auto">
                <button type="submit" class="btn btn-warning"><?php echo Text::_('COM_ALFA_CART_TAKEOVER_SUBMIT'); ?></button>
                <input type="hidden" name="task" value="cart.takeOver">
                <input type="hidden" name="form" value="page">
                <input type="hidden" name="cart_id" value="<?php echo (int) $this->builderCartId; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </template>
<?php endif; ?>
