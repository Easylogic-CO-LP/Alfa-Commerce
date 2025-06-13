<?php

use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

$cart = !empty($displayData) ? $displayData : $this->cart;

?>
<div class="mb-3" data-cart-shipments>

    <?php
    foreach($cart->getShipmentMethods() as $shipment): 
        $checked = $cart->getData()->id_shipment == $shipment->id ? 'checked' : '';
        ?>
        <div>
            <input
                type="radio"
                required
                id="shipment_method_<?php echo $shipment->id;?>"
                name="shipment_method"
                value="<?php echo $shipment->id;?>"
                <?php echo $checked; ?>
            >

            <label for="shipment_method_<?php echo $shipment->id;?>">
                <?php echo $shipment->name;?>
            </label>

            <p><?php echo $shipment->description;?></p>

            <?php
            // TODO: Error handling for missing template.
            echo PluginLayoutHelper::pluginLayout(
                $shipment->events->onCartView->getLayoutPluginType(),
                $shipment->events->onCartView->getLayoutPluginName(),
                $shipment->events->onCartView->getLayout()
            )->render($shipment->events->onCartView->getLayoutData());
            ?>

            
<!--            --><?php //echo $shipment->event->onCartView; ?>

        </div>
    <?php endforeach?>

</div>
