
<br>

<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>" method="POST">

    <div class="row">
        <div class="col-md-6">
            <h4>Customer Details</h4>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="firstName" class="form-label">First Name</label>
                    <input type="text" class="form-control" id="firstName" name="name" required>
                </div>
                <div class="col-md-6">
                    <label for="lastName" class="form-label">Last Name</label>
                    <input type="text" class="form-control" id="lastName" name="name1" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>

            <div class="mb-3">
                <label for="address" class="form-label">Shipping Address</label>
                <input type="text" class="form-control" id="address" name="shipping_address" required>
            </div>

            <div class="mb-3">
                <label for="city" class="form-label">City</label>
                <input type="text" class="form-control" id="city" name="city" required>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="state" class="form-label">State/Province</label>
                    <input type="text" class="form-control" id="state" name="state" required>
                </div>
                <div class="col-md-6">
                    <label for="zip" class="form-label">Zip Code</label>
                    <input type="text" class="form-control" id="zip" name="zip_code" required>
                </div>
            </div>
        </div>


        <div class="col-md-6">
            <h4>Payment</h4>
            <?php echo $this->loadTemplate('select_payment'); ?>
        </div>
    </div>


    <button type="submit" class="btn btn-primary w-100">Place Order</button>

	<?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
</form>