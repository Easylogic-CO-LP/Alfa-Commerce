
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>" method="POST">

    <div class="row">
        <div class="col-md-6">
            <h4>Customer Details</h4>

            <?php
//                echo "<pre>";
//
//                foreach($this->form->getFieldsets() as $fieldset){
//                    $fields = $this->form->getFieldset($fieldset->name);
//                    print_r($fields);
////                    exit;
//                    if(count($fields)){
//                        foreach($fields as $field) {
//                            print_r($field);
//                        }
//                    }
//                }
//                echo "</pre>";
//                exit;

                // Iterate through field groups.
                foreach ($this->form->getFieldsets() as $fieldset) {
                    if ($fieldset->name === 'captcha')
                        continue;

                    // Render all fields of field group.
                    $fields = $this->form->getFieldset($fieldset->name);
                    if (count($fields))
                        foreach ($fields as $field)
                            echo $field->renderField();

                }
            ?>
            <?php echo $this->form->renderFieldset('captcha');?>


        </div>

        <div class="col-md-6">
            <h4>Payment</h4>
            <?php echo $this->loadTemplate('select_payment'); ?>

            <h4>Shipment</h4>
            <?php echo $this->loadTemplate('select_shipment'); ?>
        </div>
        
    </div>


    <button type="submit" class="btn btn-primary w-100" data-main_button="1">Place Order</button>

	<?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
</form>