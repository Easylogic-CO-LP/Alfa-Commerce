<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

$cart = !empty($displayData) ? $displayData : $this->cart;

?>
<div class="mb-3" data-cart-shipments>

	<?php
	foreach ($cart->getShipmentMethods() as $shipment):
		$checked = $cart->getData()->id_shipment == $shipment->id ? 'checked' : '';
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

			<?php
			// TODO: Error handling for missing template.
			echo PluginLayoutHelper::pluginLayout(
				$shipment->events->onCartView->getLayoutPluginType(),
				$shipment->events->onCartView->getLayoutPluginName(),
				$shipment->events->onCartView->getLayout()
			)->render($shipment->events->onCartView->getLayoutData());
			?>


            <!--            --><?php //echo $shipment->event->onCartView;
			?>

        </div>
	<?php endforeach ?>

</div>
