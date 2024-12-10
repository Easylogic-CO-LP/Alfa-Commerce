<?php

$this->cart = !empty($displayData) ? $displayData : $this->cart;

?>

<div data-cart-items>

    <!-- Items Section -->    
    <h4>Items</h4>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Discount</th>
                <th>Tax</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
                // $itemsTotal = 0;
                foreach ($this->cart->getData()->items as $index=>$item) {
                    // $itemsTotal += $item->price['price_with_tax'];
            ?>
                <tr class="cart-item-outer" data-item-id='<?php echo $item->id;?>'>
                    <td data-label="Name" class="cart-item-col-name cart-item-col"><?php echo $item->name;?></td>
                    <td data-label="Quantity" class="cart-item-col-quantity cart-item-col">
                        
                        <div class="cart-item-quantity-wrapper">
                            <button class="cart-item-quantity-controls decrement" data-action="cart-item-decrement">-</button>
                            <input class="cart-item-quantity" type="number" name="quantity" data-action="cart-item-quantity" min="1" value="<?php echo $item->quantity; ?>">
                            <button class="cart-item-quantity-controls increment" data-action="cart-item-increment">+</button>
                        </div>

                        <button class="cart-item-remove" data-action="cart-item-remove">
                            <svg height="32" viewBox="0 0 32 32" width="32" xmlns="http://www.w3.org/2000/svg"><path d="m3 7h2v20.48a3.53 3.53 0 0 0 3.52 3.52h15a3.53 3.53 0 0 0 3.48-3.52v-20.48h2a1 1 0 0 0 0-2h-26a1 1 0 0 0 0 2zm22 0v20.48a1.52 1.52 0 0 1 -1.52 1.52h-15a1.52 1.52 0 0 1 -1.48-1.52v-20.48z"/><path d="m12 3h8a1 1 0 0 0 0-2h-8a1 1 0 0 0 0 2z"/><path d="m12.68 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/><path d="m19.32 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/></svg>
                        </button>

                    </td>
                    <td data-label="Price" class="cart-item-col-price cart-item-col"><?php echo $item->price['base_price'];?></td>
                    <td data-label="Discount" class="cart-item-col-price cart-item-col"><?php echo $item->price['discounts_totals']['percent'];?></td>
                    <td data-label="Tax" class="cart-item-col-tax cart-item-col">
                        <?php 
                            foreach ($item->price['taxes'] as $item_tax) {
                                echo $item_tax;
                            }
                        ?>        
                    </td>
                    <td data-label="Total" class="cart-item-col-total"><?php echo $item->price['final_price'];?></td>
                </tr>
            <?php } ?>
        </tbody>

        <tfoot>
            <tr>
                <td colspan="3"><button data-action="cart-clear">Clear Cart</button></td>
                <td colspan="1"></td>
                <td><strong>Total:</strong></td>
                <td><?php echo $this->cart->getTotal(); ?></td>
            </tr>
        </tfoot>
    </table>


</div>