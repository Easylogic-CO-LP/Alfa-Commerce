<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Boxnow
 */

namespace Joomla\Plugin\AlfaShipments\Boxnow\Extension;

use \Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Response\JsonResponse;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\Layout\FileLayout;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Bow Now - Alfa Shipment Plugin
 */
class AuthenticationData {
    public string $schema = 'https://';
    public array $urls;
    public string $access_token;

    public function __construct() {
        $this->urls = [
            'sandbox' => 'api-stage.boxnow.gr/api/v1',
            'live' => 'api.boxnow.gr/api/v1'
        ];
        $this->access_token = '';
    }
}

final class Boxnow extends ShipmentsPlugin 
{
    
    protected $authenticationData;

    protected $order = null;

    protected $boxnow_params = null;

    protected $latestLogData = []; // MUST BE ARRAY.

    protected $relativeVoucherPath;

    public function __construct($dispatcher, array $config = [])
    {
        parent::__construct($dispatcher, $config);
        
        //Uri::root()."media/plg_alfa-shipments_boxnow/images/vouchers/"
        $this->relativeVoucherPath = "/media/plg_{$this->_type}_{$this->_name}/images/vouchers";
        $this->authenticationData = new AuthenticationData();
    }


    public static function getSubscribedEvents(): array
    {
        return array_merge(
            parent::getSubscribedEvents(), //keep the already subscribed
            [ 
                'onBeforeCompileHead' => 'onBeforeCompileHead',//we added a system event
            ]
        );
    }
    
    // TODO: ALSO TRANSLATE ALL TEXTS/STRINGS IN PLUGIN
    // TODO: TO LANGUAGE FILES

    /*
     *
     * FRONTEND SITE
     *
     */
    // include javascript, css and main map html always and once by calling joomla system onBeforeCompileHead event
    public function onBeforeCompileHead(){

        $app = $this->getApplication();
        $input = $app->input;
        $option = $input->get('option');
        $view = $input->get('view');
        $doc = $app->getDocument();
        $wa = $doc->getWebAssetManager();

        if( !($doc instanceof \Joomla\CMS\Document\HtmlDocument) || //if it's not an html document
            !$app->isClient('site') ||  //if it's not in frontend
            $option != 'com_alfa' ||    //if it's not in alfa commerce component
            $view !='cart'  //if it's not in cart view
        ){
            return;
        }  
        
        $boxNowMapFileName = 'default_site_map';
        $boxNowMapPath = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $boxNowMapFileName));
        $boxnowMapLayout = new FileLayout($boxNowMapFileName,$boxNowMapPath);

        // add custom structure to the frontend html
        $doc->addCustomTag($boxnowMapLayout->render()); // on locker select it calls saveCurrentCartBoxNowData

        $wa->registerAndUseStyle('box-now','media/plg_alfa-shipments_boxnow/css/site/main.css',[],['defer' => true]);
    }

    public function saveCurrentCartBoxNowData(){
        $app = $this->getApplication();
        $input = $app->input;

        $boxNowPostalCode = $input->get('boxNowPostalCode','');

        $boxNowAddress = rawurldecode($input->get('boxNowAddress', '', 'RAW'));
        $boxNowAddress = mb_convert_encoding($boxNowAddress, 'UTF-8', 'auto');// If we're still getting garbled text

        $boxNowLockerId = $input->get('boxNowLockerId','');

        $app->setUserState('com_alfa.boxnow.postalCode', $boxNowPostalCode);
        $app->setUserState('com_alfa.boxnow.address', $boxNowAddress);
        $app->setUserState('com_alfa.boxnow.lockerId', $boxNowLockerId);

        echo new JsonResponse(null, 'Current box now data saved!', false);
        $app->close();

    }

    public function onCartView($event){

        $cart = $event->getCart();

        $app = $this->getApplication();
        $doc = $app->getDocument();
        $wa = $doc->getWebAssetManager();

        $lang = $app->getLanguage();
        $lang->load('plg_alfa-shipments_boxnow',JPATH_ADMINISTRATOR);

        $shippingCost = 0;

        // fetch if data are saved
        $boxNowPostalCode = $app->getUserState('com_alfa.boxnow.postalCode', '');
        $boxNowAddress = $app->getUserState('com_alfa.boxnow.address', '');
        $boxNowLockerId = $app->getUserState('com_alfa.boxnow.lockerId', '');

        $shipmentMethodData = $cart->getShipmentMethodData();
        $this->boxnow_params = (!empty($shipmentMethodData)) ? $shipmentMethodData->params : null; 
        
        // echo '<pre>';
        // print_r($this->boxnow_params['backgroundcolor']);
        // echo '</pre>';
        // exit;

        // Data that will pass to our layout file
        $cartLayoutData = 
                [
                    'selected_postal_code' => $boxNowPostalCode,
                    'selected_address' => $boxNowAddress,
                    'selected_locker_id' => $boxNowLockerId,
                    'button_background_color' => $this->boxnow_params['backgroundcolor']??'#000000',
                    'button_text_color' => $this->boxnow_params['color']??'#ffffff',
                ];


        $siteCartViewFileName = 'default_site_cart_view';
        $siteCartViewPath = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $siteCartViewFileName));
        $siteCartViewLayout = new FileLayout($siteCartViewFileName, $siteCartViewPath);

        $event->setLayout("default_site_cart_view");
        $event->setLayoutData($cartLayoutData);

    }

    public function onOrderAfterPlace($event)
    {

        $app = $this->getApplication();
        $input = $app->input;
        $user = $app->getIdentity();//$user->id (find current user) or $order->id_user (use order user)
        $order = $event->getOrder();

        // $params = $order->shipment->params;

        $currentDate = Factory::getDate('now','UTC');

        // Inserting order shipment For Alfa Commerce and calculate the cost/amount of it.
        $shipmentEntry = self::createEmptyOrderShipment();
        $shipmentEntry['id_order'] = $order->id;
        $shipmentEntry['id_currency'] = $order->id_currency;
        $shipmentEntry['id_shipment_method'] = $order->id_shipment_method;
        $shipmentEntry['id_user'] = $order->id_user;
        // $cart = new CartHelper();
        // $shipmentEntry['amount'] = self::onCalculateShippingCost($cart);
        $shipmentEntry['amount'] = $order->total_shipping; // self::onCalculateShippingCost(); /
        $shipmentEntry['track_id'] = $order->shipping_tracking_number;
        $shipmentEntry['date_add'] = $currentDate->toSql(false); //date('Y-m-d H:i:s'); or format('Y-m-d H:i:s');
        $insertedId = self::insertOrderShipment($shipmentEntry);

	    //Add Logging For Box Now Shipment
	    $boxNowLog = self::createEmptyLog();
	    $boxNowLog["id_order"] = $order->id;
	    $boxNowLog["id_order_shipment"] = $insertedId;
	    $boxNowLog["locker_id"] = $app->getUserState('com_alfa.boxnow.lockerId', $input->getInt("boxNowLockerIdHidden"));
	    $boxNowLog["shipment_total"] = $order->total_shipping;
	    $boxNowLog["created_on"]      = $currentDate->toSql(false);
	    $boxNowLog["created_by"]      = $user->id;
	    self::insertLog($boxNowLog);

        $event->setOrder($order);
    }


    /*
     *  Cost calculation.
     */
    public function onCalculateShippingCost($event){
        $event->setShippingCost(self::calculateShippingCost($event->getCart()));
    }

    public function calculateShippingCost($cart){

        $cost = 0;

        $cartData = $cart->getData();


        // Don't bother with places for the moment.
        $shipmentMethodData = $cart->getShipmentMethodData();
        $shipmentPackages = $shipmentMethodData->params;

        $countrySelected = 84; //Greece
        // $countrySelected = 55; //Cyprus


        $zipCode = "000000";
        if(isset($cartData->user_info_delivery->zip_code) && !empty($cartData->user_info_delivery->zip_code))
            $zipCode = $cartData->user_info_delivery->zip_code;


        $calculationData = null;

        // select the calculation Data based on the country selected on the cart
        foreach ($shipmentPackages['cost-per-place'] as $key => $entry) {

            if (isset($entry['places'])) {
                foreach ($entry['places'] as $place) {
                    if($place == $countrySelected){ //found calculation costs for specific place
                        $calculationData = $entry;
                    }
                }
            }else{ //means we have a global entry for all places
                $calculationData = $entry;
            }

        }

        // No valid entries found.
        if(empty($calculationData))
            return 0;

        $cartItems = $cart->getData()->items;
        $selectedShipmentCosts = $calculationData['costs'];

        $cost = self::findBestShippingMethod($cartItems, $selectedShipmentCosts, $zipCode);

        return $cost;
    }

    public function findBestShippingMethod($products, $shippingCosts, $zipCode) {

        if(empty($products) || empty($shippingCosts))
            return 0;

        // Calculate total dimensions of products
        $packageDimensions = self::getTotalDimensions($products);

        // Put the cheapest methods on top ( so if two methods to return the cheapest one first )
        usort($shippingCosts, function ($a, $b) {
            return $a['cost'] <=> $b['cost']; // Sort by cost ascending
        });

        foreach ($shippingCosts as $method) {
            if (
                $packageDimensions['width'] <= $method['width-max'] &&
                $packageDimensions['height'] <= $method['height-max'] &&
                $packageDimensions['depth'] <= $method['depth-max'] &&
                $packageDimensions['weight'] <= $method['weight-max']
                && $this->isValueInRange($zipCode, $method['zip-start'], $method['zip-end'])  // Make sure we have a valid zip as well.
            ) {
                return $method['cost'];
            }
        }

        return 0; // Return the cheapest valid shipping method
    }

    public function getTotalDimensions($products) {

        $totalWidth = 0;
        $maxHeight = 0;
        $maxDepth = 0;
        $totalWeight = 0;

        foreach ($products as $product) {
            $totalWidth += $product->width * $product->quantity;
            $maxHeight = max($maxHeight, $product->height);
            $maxDepth = max($maxDepth, $product->depth);
            $totalWeight += $product->weight * $product->quantity;
        }

        return [
            'width' => $totalWidth,
            'height' => $maxHeight,
            'depth' => $maxDepth,
            'weight' => $totalWeight
        ];
    }


    /*
     *
     * BACKEND ADMIN
     *
     */
    public function onAdminOrderShipmentView($event) {

        $order = $event->getOrder();
	    $method = $event->getMethod();

        $app = $this->getApplication();
        $input = $app->input;
        $option = $input->get('option');
        $view = $input->get('view');
        $doc = $app->getDocument();
        $wa = $doc->getWebAssetManager();

        $this->latestLogData = self::loadLogData($order->id);
        if(!empty($this->latestLogData))//only one log
            $this->latestLogData = $this->latestLogData[0];

        if(!empty($this->latestLogData["parcel_data"]))
            $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);

        // No data found.
//        if(empty($this->latestLogData["parcel_data"])) {
////            $event->setLayoutPluginType($this->_type);
////            $event->setLayoutPluginName($this->_name);
//            $event->setLayout("default_empty");
//            $event->setLayoutData([]);
//            return;
//        }

        // data initialize
        $parcelData = json_encode($this->latestLogData["parcel_data"]) ?? "";

        $basicVoucherURL = Uri::root(). $this->relativeVoucherPath; //"media/plg_alfa-shipments_boxnow/images/vouchers/";

        // parcel data example
        // {
        //  "id":"50192",
        //  "parcels":[{"id":"2416803409","created_sticker":0,"cancelled":0,"package_value":"0.00","compartment_size":"2","weight":"0"}
                        // {"id":"9926830420","created_sticker":0,"cancelled":0,"package_value":"0.00","compartment_size":"1","weight":"0"}
                    // ],
        //  "order_price":"0",
        //  "payment_mode":"prepaid",
        //  "collected_amount":"0"
        // }


        // LayoutHelper::render('com_mycomponent.payments.form', $payment_form_data)

        // data that will pass to our layout file
        $backendOrderLayoutData = 
                [
                    'plugin_type' => $this->_type,
                    'plugin_name' => $this->_name,
                    'order' => $order,
	                'method' => $method,
                    'parcel_data' => addslashes($parcelData),
                    'voucher_url' => $basicVoucherURL
                ];

        $backendOrderLayoutFileName = 'default_admin_order_view';
        $backendOrderLayoutPath = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $backendOrderLayoutFileName));
        $backendOrderLayout = new FileLayout($backendOrderLayoutFileName,$backendOrderLayoutPath);
//
//		print_r('hey');
//		exit;

        // HERE.
//        $event->setLayoutPluginType($this->_type);
//        $event->setLayoutPluginName($this->_name);
        $event->setLayout("default_admin_order_view");
        $event->setLayoutData($backendOrderLayoutData);


//        return $backendOrderLayout->render($backendOrderLayoutData);
    }

    public function onAdminOrderShipmentPrepareForm($event){

        $order = $event->getData();
        $form = $event->getForm();

        $app = $this->getApplication();
        $lang  = $app->getLanguage();

        // JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/forms/admin_order_view_form.xml';
        $xmlForm = $this->getPluginPath() .'/forms/admin_order_view_form.xml';
        if(!file_exists($xmlForm)){
            return '';
        }

        // $form->load($xmlForm);
        $form->loadFile($xmlForm, false);
        $lang->load('plg_alfa-shipments_boxnow');

        // Get latest log data.
        $this->latestLogData = self::loadLogData($order->id);
        if(empty($this->latestLogData))
            return '';

        $this->latestLogData = $this->latestLogData[0];
        if(empty($this->latestLogData["parcel_data"]))
            return '';
        $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);

        $form->setValue('order_price', 'shipment', $this->latestLogData["parcel_data"]->order_price);
        $form->setValue('payment_mode', 'shipment', $this->latestLogData["parcel_data"]->payment_mode);
        $form->setValue('amount_to_be_collected', 'shipment', $this->latestLogData["parcel_data"]->collected_amount);

        // Repeatable fields.
        $subformData = [];
        foreach($this->latestLogData["parcel_data"]->parcels as $parcel){
            $subformData[] =
                [
                    'packages_value' => $parcel->package_value,
                    'packages_weight' => $parcel->weight,
                    'compartment_size' => $parcel->compartment_size,
                    'boxnow_actions' => ''  // I guess ID comes here.
                ];
        }

        $form->setValue('packages', 'shipment', $subformData);

        $event->setForm($form);
        $event->setData($order);
        
    }

    /*
     *
     * GENERAL FUNCTIONS
     *
     */
    protected function getOrderData($orderID){
        $orderModel = self::getApplication()->bootComponent('com_alfa')->getMVCFactory()->createModel('order', 'Administrator', ['ignore_request' => true]);
        return $orderModel->getItem($orderID);
    }

    /*
     *
     * BOX NOW MAIN REQUESTS
     *
     */
    public function requestDelivery() {

        $app = $this->getApplication();
        $input = $app->input;

        $rawData = file_get_contents('php://input');

        $orderId = $input->get('order_id', '');
	    $orderShipmentId = $input->get('order_shipment_id', '');

        if(empty($orderId)){
            return new JsonResponse(null, 'Order id is empty', true);
        }

        $order = self::getOrderData($orderId);

        if(empty($order)){
            return new JsonResponse(null, "Order with {$orderId} not found!", true);
        }

        // TODO: an o diaxeiristis prosthesei meta th boxnow tote tha prepei na ftiaksoume ena log toulaxiston
        $allLogData = self::loadLogData($orderId,$orderShipmentId);
        $this->latestLogData = $allLogData[0];

        // we already requested a delivery
        if(!empty($this->latestLogData["parcel_data"])){
            return new JsonResponse(null, "A previous delivery request was already found active!", true);
        }

        $this->boxnow_params = $order->shipment->params;

        //TODO: From order's details.
        $PackageID = $this->latestLogData["boxNowPackageID"] ?? "";
        $CustomerEmail = $this->latestLogData["boxNowCustomerEmail"] ?? "";
        $CustomerPhoneNumber = $this->latestLogData["boxNowCustomerPhoneNumber"] ?? "";
        $CustomerName = $this->latestLogData["boxNowCustomerName"] ?? "";
        $OwnerEmail = $this->boxnow_params->contact_email ?? "";
        $OwnerPhoneNumber = $this->boxnow_params->phone_number ?? "";
        $OwnerName = $this->boxnow_params->contact_name ?? "";
        $CompartmentSize = $this->boxnow_params->compartment_size ?? "";
        $Weight = $this->boxnow_params->weight ?? "";

        $OriginID = $this->boxnow_params->warehouse_id;
        $DestinationID = $this->latestLogData["locker_id"] ?? "";

        $parcelData = json_decode($rawData, true);

        $invoiceValue = (double) $parcelData["order_price"];
        $invoiceValue = number_format($invoiceValue, 2, '.', '');
        $paymentMode = $parcelData["payment_mode"];
        $collectedAmount = (double) $parcelData["collected_amount"];
        $collectedAmount = number_format($collectedAmount, 2, '.', '');

        // for test initialize
        $OwnerPhoneNumber = "+306900000000";
        $OwnerEmail = "email@email.email";
        $OwnerName = "Customer Name";
        $OriginID = "2";
        $CustomerPhoneNumber = "+306900000000";
        $CustomerEmail = "email@email.email" ;
        $CustomerName = "Owner Name";
        $DestinationID = "4";

        $items = [];
        // REQUIRES ERROR HANDLING: CHECK IF PARCEL DATA HAS ENOUGH DATA FOR AT LEAST ONE ENTRY.
        foreach($parcelData["packages_data"] as $i => $data){
            $items[] = [
                "id" => (string) ($i + 1),
                "name" => "voucher",
                "value" => (string) $data["package_value"],
                "compartmentSize" => (int) $data["package_compartment_size"],
                "weight" => (float) $data["package_weight"]
            ];
        }

        // TODO: VALID DATA
        $data = [
            "orderNumber" => (string) ($order->id . '-' . time()),  // Must be unique.
            "invoiceValue" => (string) $invoiceValue,
            "paymentMode" => (string) $paymentMode,
            "amountToBeCollected" => (string) $collectedAmount,
            "notifyOnAccepted" => "email@email.email",  // ??
            "origin" => [
                "contactNumber" => $OwnerPhoneNumber,
                "contactEmail"  => $OwnerEmail,
                "contactName"   => $OwnerName,
                "locationId"    => $OriginID,
            ],
            "destination" => [
                "contactNumber" => $CustomerPhoneNumber,
                "contactEmail"  => $CustomerEmail,
                "contactName"   => $CustomerName,
                "locationId"    => $DestinationID,
            ],
            "items" => $items
        ];

        $contentType = "application/json";
        $response = self::doPost('delivery-requests', $data);

        if($response->success){
            // Tracking states of each parcel.
            foreach($response->data['parcels'] as $i => &$parcel){
                $parcel["created_sticker"] = 0;
                $parcel["cancelled"] = 0;
                $parcel["package_value"] = $parcelData["packages_data"][$i]["package_value"];
                $parcel["package_value"] = number_format($parcel["package_value"], 2, '.', '');
                $parcel["compartment_size"] = $parcelData["packages_data"][$i]["package_compartment_size"];
                $parcel["weight"] = $parcelData["packages_data"][$i]["package_weight"];
            }

            $response->data["order_price"] = $parcelData["order_price"];
            $response->data["payment_mode"] = $parcelData["payment_mode"];
            $response->data["collected_amount"] = $parcelData["collected_amount"];

            $this->latestLogData['parcel_data'] = json_encode($response->data);
            $this->insertLog($this->latestLogData); // updates the log because the auto increment id is passed
        }else{
            $app->enqueueMessage($response->message,'error');
        }

        return $response;
    }


    // Creates a label for every ID given, if a label has not been already made.
    public function fetchCreateLabels(){
        $app = $this->getApplication();
        $input = $app->input;

        $orderId = $input->get('order_id', '');

        if(empty($orderId))
            return new JsonResponse(null, 'Order id is empty', true);

        $order = self::getOrderData($orderId);

        if(empty($order))
            return new JsonResponse(null, "Order with {$orderId} not found!", true);


        $allLogData = self::loadLogData($orderId);
        $this->latestLogData = $allLogData[0];
        if(!empty($this->latestLogData["parcel_data"]))
            $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);
        else    // Cannot handle request on our own.
            return new JsonResponse(null, "No delivery request has been made. Request a delivery before attempting to print the labels!", true);

        // Create a label for each parcel that doesn't have one.
        foreach($this->latestLogData["parcel_data"]->parcels as $i => &$parcel){

            // Skip already cancelled parcels.
            if($parcel->cancelled == 1)
                continue;

            if(empty($parcel->id))
                return new JsonResponse(null, "One or more parcels had an invalid ID.", true);


            $filePath = $this->relativeVoucherPath;//"/media/plg_{$this->_type}_{$this->_name}/images/vouchers";
            $fileName = "{$order->id}_{$parcel->id}.pdf";

            $absoluteFilePath = JPATH_ROOT . $filePath;
            $relativePath = $filePath . "/" . $fileName;

            $response_pdf_data = self::doPost("parcels/{$parcel->id}/label.pdf", [], false, "application/pdf");

            if ($response_pdf_data->success) {
                
            } else {
                return new JsonResponse(null, $response_pdf_data->message, true);
            }

            // Create directory if it doesn't exist
            if (!is_dir($absoluteFilePath)) {
                mkdir($absoluteFilePath, 0777, true);
            }

            file_put_contents($absoluteFilePath . "/{$fileName}", $response_pdf_data->data);

            $response_data[] = [
                'fullPath' => Uri::root() . $relativePath,
                'filePath' => $filePath,
                'fileName' => $fileName,
                'id'       => $parcel->id
            ];

            $parcel->created_sticker = 1;
        }

        // Update logs.
        $this->latestLogData["parcel_data"] = json_encode($this->latestLogData["parcel_data"]);
        self::insertLog(json_decode(json_encode($this->latestLogData)));

        return new JsonResponse($response_data, "Label fetched!", false);

    }


    public function fetchCancelDelivery(){

        $app = $this->getApplication();
        $input = $app->input;

        $orderId = $input->get('order_id', '');

        if(empty($orderId))
            return new JsonResponse(null, 'Order id is empty', true);

        $order = self::getOrderData($orderId);

        if(empty($order))
            return new JsonResponse(null, "Order with {$orderId} not found!", true);

        $allLogData = self::loadLogData($orderId);
        $this->latestLogData = $allLogData[0];
        if(empty($this->latestLogData["parcel_data"]))
            return new JsonResponse(null, 'No parcel data found to cancel!', true);
        else
            $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);

        foreach($this->latestLogData["parcel_data"]->parcels as $parcel){

            $filePath = JPATH_ROOT . $this->relativeVoucherPath;//"/media/plg_{$this->_type}_{$this->_name}/images/vouchers";
            $fileName = "{$order->id}_{$parcel->id}.pdf";

            if (file_exists("{$filePath}/{$fileName}"))
                unlink("{$filePath}/{$fileName}");

            // $parcelID = $this->latestLogData["parcel_data"];
            $response = self::doPost("parcels/{$parcel->id}:cancel");

            if($response->success){ // ??
            }else{
                return new JsonResponse(null, $response->message, true);
                // $app->enqueueMessage($response->message,'error');
            }

            $this->latestLogData['parcel_data'] = null;
            self::insertLog($this->latestLogData);//updates the log because the auto increment id is passed
        }

        // Return empty pdf file link.
        $response_data= [];

        return new JsonResponse($response_data, "Cancel done!", false);
    }

    public function fetchCancelIndividualParcel(){

        $app = $this->getApplication();
        $input = $app->input;
        // $rawData = file_get_contents('php://input');

        $orderId = $input->get('order_id', '');
        $parcelId = $input->get('parcel_id', '');//because we passed it in the body of the request

        if(empty($orderId))
            return new JsonResponse(null, 'Order id is empty', true);

        if(empty($parcelId))
            return new JsonResponse(null, 'Parcel id is empty', true);

        $order = self::getOrderData($orderId);

        if(empty($order))
            return new JsonResponse(null, "Order with {$orderId} not found!", true);

        $allLogData = self::loadLogData($orderId);
        $this->latestLogData = $allLogData[0];
        if(empty($this->latestLogData["parcel_data"]))
            return new JsonResponse(null, 'No parcel data found to cancel!', true);
        else
            $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);

        // $parcelData = json_decode($rawData, true);

        // Fetch label for the given ID.
        $filePath = JPATH_ROOT . $this->relativeVoucherPath;//"/media/plg_{$this->_type}_{$this->_name}/images/vouchers";
        $fileName = "{$order->id}_{$parcelId}.pdf";

        if (file_exists("{$filePath}/{$fileName}"))
            unlink("{$filePath}/{$fileName}");

        $response = self::doPost("parcels/{$parcelId}:cancel");

        if($response->success){ // ??
        }else{
            return new JsonResponse(null, $response->message, true);
        }

        // Update the status of the parcel.
        foreach($this->latestLogData["parcel_data"]->parcels as &$parcel)
            if($parcel->id == $parcelId)
                $parcel->cancelled = 1;

        $allCancelled = true;
        // If every parcel of the order has been cancelled, delete it altogether.
        foreach($this->latestLogData["parcel_data"]->parcels as &$parcel)
            if($parcel->cancelled != 1) {
                $allCancelled = false;
                break;
            }

        // Remove data, all parcels have been cancelled.
        if($allCancelled)
            $this->latestLogData["parcel_data"] = null;

        if($this->latestLogData["parcel_data"] != null)
            $this->latestLogData["parcel_data"] = json_encode($this->latestLogData["parcel_data"]);

        self::insertLog($this->latestLogData);  //updates the log because the auto increment id is passed
        if($this->latestLogData["parcel_data"] != null)
            $this->latestLogData["parcel_data"] = json_decode($this->latestLogData["parcel_data"]);

        return new JsonResponse([], "Cancel done!", false);

    }


    /*
     * BOX NOW GENERAL REQUESTS
     */

    // AUTHENTICATE REQUEST - GET BEARER TOKEN
    public function authenticateForRequests(){

        $app = $this->getApplication();
        
        $sandboxMode = $this->boxnow_params->sandbox_mode == 1;
        $sandboxMode = true;        // Always on sandbox.

        if(empty($this->boxnow_params->client_id) || empty($this->boxnow_params->client_secret)){
            $app->enqueueMessage('Client ID or Client Secret is missing.', 'error');
            return false;
        }

        $request_url = 'auth-sessions';
        
        $domain = $sandboxMode?$this->authenticationData->urls['sandbox']:$this->authenticationData->urls['live'];
        $url = $this->authenticationData->schema . $domain . '/' .$request_url;

        // $url = "https://api-stage.boxnow.gr/api/v1/auth-sessions";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,  $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "grant_type" => "client_credentials",
            "client_id" => $this->boxnow_params->client_id,
            "client_secret" => $this->boxnow_params->client_secret,
        ]));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);

        // Check for cURL errors
        if ($curlError) {
            $app->enqueueMessage('CURL Error: '. curl_error($ch) , 'error');
            return false;
            // throw new \Exception("cURL Error: " . curl_error($ch));
        }

        // Check for HTTP errors
        if ($httpCode >= 400) {
            $app->enqueueMessage("HTTP Error {$httpCode}: {$response}", 'error');
            // throw new \Exception("HTTP Error: " . $httpCode . " - Response: " . $response);
            return false;
            
        }

        // Validate JSON response
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $app->enqueueMessage('Invalid JSON response received.', 'error');
            // throw new \Exception("Invalid JSON response: " . $response);
            return false;
        }

        return $decodedResponse;
    }

    protected function doPost($request_url, $fields = array(),$post=true, $contentType='application/json')
    {
        $app = $this->getApplication();

        $response_data = [];
        $response_message = 'Done';
        $response_error = false;

        // We need to authenticate before any request.
        if(empty($this->authenticationData->access_token)){
            $response = self::authenticateForRequests();

            // Handle authentication failure
            if (!$response || !isset($response['access_token'])) {
                $response_message = 'Authentication failed. Unable to retrieve access token.';
                $response_error = true;
                $response = new JsonResponse($response_data, $response_message, $response_error);
                return $response;
            }

            $this->authenticationData->access_token = $response['access_token'];
        }

        $sandboxMode = $this->boxnow_params->sandbox_mode == 1;
        $sandboxMode = true;        // Always on sandbox.

        $domain = $sandboxMode?$this->authenticationData->urls['sandbox']:$this->authenticationData->urls['live'];
        $url = $this->authenticationData->schema . $domain . '/' . $request_url;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: '. $contentType ,'Authorization: Bearer '. $this->authenticationData->access_token)); // Inject the token into the header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, $post);
        if(count($fields)) curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($fields));   // Body is not always necessary.
        
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);
        
        // Handle cURL errors
        if ($curlError) {
            $response_message = 'cURL Error: ' . curl_error($ch);
            $response_error = true;
        }
        else{
            // Return pdf response raw.
            
            if($contentType == 'application/pdf' && (substr($result, 0, 4) === "%PDF")){
                $response_data = $result;//no need to decode the pdf file
            }else{

                $response_decoded = json_decode($result,true);

                // Associating error code with error message to get a clearer picture.
                if(!empty($response_decoded) && isset($response_decoded['code'])){
                    $detailedErrorMessage = (isset($response_decoded['jsonSchemaErrors']) ? '(' . implode(",", $response_decoded['jsonSchemaErrors']) . ')' : '');

                    $lang  = $app->getLanguage();
                    $lang->load('plg_alfa-shipments_boxnow');

                    $response_message = Text::_("PLG_ALFA_SHIPMENTS_BOXNOW_ERROR_CODE_" . $response_decoded['code']) . $detailedErrorMessage;
                    $response_error = true;
                    $response_data = $fields;
                }else{
                    $response_data = $response_decoded;
                }

            }
        }

        $response = new JsonResponse($response_data, $response_message, $response_error);
        return $response;
    }

}
