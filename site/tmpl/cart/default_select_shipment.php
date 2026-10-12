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

// A reading, not the cart. The layouts each carrier asked for come with it,
// looked up by method id rather than hung on the method object.
$snapshot  = !empty($displayData) ? $displayData : $this->snapshot;
$shipments = $snapshot->offers->shipments;
?>
<div class="cart-methods" data-cart-shipments>

	<?php foreach ($snapshot->offers->notices('shipment') as $notice) : ?>
        <p class="cart-methods-notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endforeach; ?>

	<?php if ($shipments === []) : ?>
        <p class="cart-methods-empty"><?php echo Text::_('COM_ALFA_CART_NO_SHIPMENT_AVAILABLE'); ?></p>
	<?php endif; ?>

	<?php
	foreach ($shipments as $shipment):
		$checked = $snapshot->offers->selectedShipmentId === (int) $shipment->id ? 'checked' : '';
		$view = $snapshot->offers->viewFor('shipment', (int) $shipment->id);
		?>
        <div>
            <input
                    type="radio"
                    required
                    id="shipment_method_<?php echo $shipment->id; ?>"
                    name="shipment_method"
                    value="<?php echo $shipment->id; ?>"
				<?php echo $checked; ?>
            >

            <label for="shipment_method_<?php echo $shipment->id; ?>">
				<?php echo htmlspecialchars($shipment->name, ENT_QUOTES, 'UTF-8'); ?>
            </label>

            <p><?php echo htmlspecialchars($shipment->description, ENT_QUOTES, 'UTF-8'); ?></p>

			<?php if ($view !== null) : ?>
				<?php echo Layout::render($view->layout, $view->layoutData, $view->pluginType, $view->pluginName); ?>
			<?php endif; ?>

        </div>
	<?php endforeach ?>

</div>
