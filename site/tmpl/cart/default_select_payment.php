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
// with one offered and none chosen yet, only payment methods tied to a
// shipping method exist and the choice comes first. With none offered
// because a carrier waits for a fact, the list names the same fact;
// with none offered at all, nothing can pay.
$emptyKey = match (true) {
    $shipmentChosen                       => 'COM_ALFA_CART_NO_PAYMENT_FOR_SHIPMENT',
    $snapshot->offers->shipments !== []   => 'COM_ALFA_CART_PAYMENT_CHOOSE_SHIPMENT_FIRST',
    $snapshot->offers->needsKey() !== ''  => $snapshot->offers->needsKey(),
    default                               => 'COM_ALFA_CART_NO_PAYMENT_AVAILABLE',
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
		// Each method in the order every method keeps: its radio, then in its label the image the shop set (none
		// drawn without one) and its title, then the description the shop wrote, then what its plugin adds.
		$image = Layout::render('methods.image', ['image' => $snapshot->offers->imageFor('payment', (int) $payment->id), 'title' => (string) $payment->name]);
		// The fee the method adds to the order, before it is chosen: the same figure the totals carry once it is.
		$fee = $snapshot->offers->feeFor((int) $payment->id);
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
				<?php echo $image . htmlspecialchars($payment->name, ENT_QUOTES, 'UTF-8'); ?>
				<?php if ($fee !== null) : ?><span class="cart-method-fee"><?php echo htmlspecialchars(Text::sprintf('COM_ALFA_CART_PAYMENT_FEE', $fee->format()), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </label>

            <?php if (trim((string) $payment->description) !== '') : ?><p><?php echo htmlspecialchars($payment->description, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

			<?php if ($view !== null) : ?>
				<?php echo Layout::render($view->layout, $view->layoutData, $view->pluginType, $view->pluginName); ?>
			<?php endif; ?>

        </div>
	<?php endforeach ?>

</div>
