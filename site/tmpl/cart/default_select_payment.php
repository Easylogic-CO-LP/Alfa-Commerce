<?php

use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

$cart = !empty($displayData) ? $displayData : $this->cart;

?>

<div class="mb-3" data-cart-payments>
    
    <?php

    foreach($cart->getPaymentMethods() as $payment): 
        $checked = $cart->getData()->id_payment == $payment->id ? 'checked' : '';
        ?>
        <div>
            <input 
                type="radio"
                required
                id="payment_method_<?php echo $payment->id;?>"
                name="payment_method"
                value="<?php echo $payment->id;?>"
                <?php echo $checked; ?>
            >
            
            <label for="payment_method_<?php echo $payment->id;?>">
                <?php echo $payment->name;?>
            </label>
            
            <p><?php echo $payment->description;?></p>
            
            <?php
                // TODO: Error handling for missing template.
                echo PluginLayoutHelper::pluginLayout(
                        $payment->events->onCartView->getLayoutPluginType(),
                        $payment->events->onCartView->getLayoutPluginName(),
                        $payment->events->onCartView->getLayout()
                )->render($payment->events->onCartView->getLayoutData());
            ?>
            
        </div>
    <?php endforeach?>

</div>
