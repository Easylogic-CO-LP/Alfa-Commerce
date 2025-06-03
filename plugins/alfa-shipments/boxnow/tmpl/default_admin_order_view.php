<?php

$app = \Joomla\CMS\Factory::getApplication();
$doc = $app->getDocument();
$wa = $doc->getWebAssetManager();




$order = $displayData['order'];
$method = $displayData['method'];
$fetchType = $displayData['plugin_type']; //alfa-payments
$fetchName = $displayData['plugin_name']; //box-now
$parcelData = $displayData['parcel_data'];
$vouchersURL = $displayData['voucher_url'];
$fetchMethodID = $order->id_shipment_method;
$orderID = $order->id;
$orderShipmentID = $method->id;

// functions to call
$fetchRequestDeliveryFunction = "requestDelivery";
$fetchCreateLabelFunction = "fetchCreateLabels";
$fetchCancelDeliveryFunction = "fetchCancelDelivery";
$fetchCancelIndividualParcelFunction = "fetchCancelIndividualParcel";


// Request delivery.
$fetchRequestDeliveryURL = "index.php?option=com_alfa&task=plugin.trigger&name={$fetchName}&type={$fetchType}&func={$fetchRequestDeliveryFunction}&format=json"
    . "&method_id={$fetchMethodID}&order_id={$orderID}";

// Create label.
$fetchCreateLabelURL = '/administrator/index.php?option=com_alfa&task=plugin.trigger&name='
    . $fetchName . '&type=' . $fetchType . '&method_id=' . $fetchMethodID . '&func=' . $fetchCreateLabelFunction . '&format=json&order_id=' . $orderID.'&order_shipment_id=' . $orderShipmentID;

// Cancel delivery.
$fetchCancelDeliveryURL = '/administrator/index.php?option=com_alfa&task=plugin.trigger&name='
    . $fetchName . '&type=' . $fetchType . '&method_id=' . $fetchMethodID . '&func=' . $fetchCancelDeliveryFunction . '&format=json&order_id=' . $orderID.'&order_shipment_id=' . $orderShipmentID;

// Cancel individual delivery.
$fetchCancelIndividualParcelFunctionURL = '/administrator/index.php?option=com_alfa&task=plugin.trigger&name='
    . $fetchName . '&type=' . $fetchType . '&method_id=' . $fetchMethodID . '&func=' . $fetchCancelIndividualParcelFunction . '&format=json&order_id=' . $orderID.'&order_shipment_id=' . $orderShipmentID;

// Inject BoxNow required script to the head
$inlineScript = <<<JS
    // pass php variables to be used from api-functions.js file
    var fetchRequestDeliveryURL = "{$fetchRequestDeliveryURL}";
    var fetchCreateLabelURL = "{$fetchCreateLabelURL}";
    var fetchCancelDeliveryURL = "{$fetchCancelDeliveryURL}";
    var rawParcelData = "{$parcelData}";
    var vouchersURL = "{$vouchersURL}";
    var orderId = "{$orderID}";
JS;

$wa->addInlineScript($inlineScript);

$wa->registerAndUseScript('box-now-api-functions','media/plg_alfa-shipments_boxnow/js/admin/api-functions.js'); //['defer' => true]); to lazy load
$wa->registerAndUseScript('box-now-main-functions','media/plg_alfa-shipments_boxnow/js/admin/main.js');

$wa->registerAndUseStyle('box-now-admin-css','media/plg_alfa-shipments_boxnow/css/admin/main.css');

?>
        
<button type="button" class="btn btn-primary" id="boxnow_request_delivery_btn" onclick="requestDelivery()">
    Request Delivery
</button>
<button type="button" class="btn btn-primary" id="boxnow_create_stickers_btn" onclick="createStickers()">
    Create Stickers
</button>
<button type="button" class="btn btn-primary" id="boxnow_cancel_delivery_btn" onclick="cancelDelivery()">
    Cancel Delivery
</button>
<br><br>