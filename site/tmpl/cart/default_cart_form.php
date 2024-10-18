<h4>Customer Details</h4>

<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>" method="POST">
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

    <div class="mb-3">
        <label for="paymentMethod" class="form-label">Payment Method</label>
        <select class="form-select" id="paymentMethod" name="paymentMethod" >
            <option selected disabled>Choose...</option>
            <option value="creditCard">Credit Card</option>
            <option value="paypal">PayPal</option>
            <option value="cash">Cash on Delivery</option>
        </select>
    </div>

    <button type="submit" class="btn btn-primary w-100">Place Order</button>
</form>