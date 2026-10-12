<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Language\Text;

// A reading, not the cart. The layouts each method asked for come with it,
// looked up by method id rather than hung on the method object.
$snapshot = !empty($displayData) ? $displayData : $this->snapshot;
$payments = $snapshot->offers->payments;
$shipmentChosen = $snapshot->offers->selectedShipmentId > 0;

// Nothing offered: with a shipping method chosen, none is allowed with it;
// with none chosen yet, only payment methods tied to a shipping method
// exist and the choice comes first.
$emptyKey = match (true) {
    $shipmentChosen                   => 'COM_ALFA_CART_NO_PAYMENT_FOR_SHIPMENT',
    $snapshot->offers->hasShipments    => 'COM_ALFA_CART_PAYMENT_CHOOSE_SHIPMENT_FIRST',
    default                           => 'COM_ALFA_CART_NO_PAYMENT_AVAILABLE',
};
?>

<div class="cart-methods" data-cart-payments>

	<?php foreach ($snapshot->offers->notices('payment') as $notice) : ?>
        <p class="cart-methods-notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endforeach; ?>

	<?php if ($payments === []) : ?>
        <p class="cart-methods-empty"><?php echo Text::_($emptyKey); ?></p>
	<?php endif; ?>

	<?php

	foreach ($payments as $payment):

		$checked = $snapshot->offers->selectedPaymentId === (int) $payment->id ? 'checked' : '';
		$view = $snapshot->offers->viewFor('payment', (int) $payment->id);
		?>
        <div>
            <input
                    type="radio"
                    required
                    id="payment_method_<?php echo $payment->id; ?>"
                    name="payment_method"
                    value="<?php echo $payment->id; ?>"
				<?php echo $checked; ?>
            >

            <label for="payment_method_<?php echo $payment->id; ?>">
				<?php echo htmlspecialchars($payment->name, ENT_QUOTES, 'UTF-8'); ?>
            </label>

            <p><?php echo htmlspecialchars($payment->description, ENT_QUOTES, 'UTF-8'); ?></p>

			<?php if ($view !== null) : ?>
				<?php echo Layout::render($view->layout, $view->layoutData, $view->pluginType, $view->pluginName); ?>
			<?php endif; ?>

        </div>
	<?php endforeach ?>

</div>
