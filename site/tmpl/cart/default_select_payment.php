<div class="mb-3">
    
    <?php

        // $cart = $this->cart->getData();

    foreach($this->cart->getPaymentMethods() as $payment): ?>
        <div>
            <input 
                type="radio"
                required
                id="payment_method_<?php echo $payment->id;?>"
                name="payment_method"
                value="<?php echo $payment->id;?>"
            >
            
            <label for="payment_method_<?php echo $payment->id;?>">
                <?php echo $payment->name;?>
            </label>
            
            <p><?php echo $payment->description;?></p>

            <?php echo $payment->event->onCartView; ?>
            
        </div>
    <?php endforeach?>

</div>
