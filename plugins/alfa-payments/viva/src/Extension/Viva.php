<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Viva
 *
 */

namespace Joomla\Plugin\AlfaPayments\Viva\Extension;

use \Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use \Joomla\CMS\Component\ComponentHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Text Plugin
 *
 * @since  3.7.0
 */
final class Viva extends PaymentsPlugin
{
    // TODO: Should all be turned to events as with the function onProductView
    public function onOrderProcessView($event){

        $app = $this->getApplication();

        $order = $event->getOrder();
        $method = $event->getMethod();

        $params = $order->selected_payment->params;

        // Viva does not accept decimal values. (We set amount = 1000 for 10 euros.)
        $priceParams = $order->currency_data;

        // TODO: We dont know the decimal place we should get it from the administration ??
        $priceAmount = $order->original_price * (10 ** $priceParams->decimal_place);
        $currencyCode = $priceParams->number;

        $development_mode = $params["vivapayment_sandbox_mode"];
        $merchant_id = $params['vivapayment_business'];
        $api_key = $params['vivapayment_apikey'];
        $source_code = $params['vivapayment_sourcecode'];

        // Check if any required parameter is missing
        if (empty(trim($params['vivapayment_business'])) || 
            empty(trim($params['vivapayment_apikey'])) || 
            empty(trim($params['vivapayment_sourcecode']))) {

            $layoutData = [
                'method'    => $method,
                'order'     => $order,
            ];

            $event->setLayout('default_payment_process');
            $event->setLayoutData($layoutData);

            $app->enqueueMessage('Missing required Viva Payment settings. Please check your payment configuration or get in contact with the eshop administrator to report the problem.', 'error');

            return;
        }

        $scheme = "https://";

        $host_production = "www.vivapayments.com";
        $host_development = "demo.vivapayments.com";

        $create_order_path = "/api/orders";
        $new_transaction_path = "/web/checkout";

        $postArguments =
            'Amount=' . urlencode($priceAmount) .
            '&CurrencyCode' . urlencode($currencyCode) .
            '&Email' . urlencode($order->user_email) .
            '&SourceCode' . urlencode($source_code) .
            '&PaymentTimeOut=301' .
            '&disableCash=true';

        // DEBUGGING.
	    $development_mode = true;
        if($development_mode){
            $request = $scheme . $host_development . $create_order_path;
        }
        else{
	        $request = $scheme . $host_production . $create_order_path;
        }

        $session = curl_init($request);

        curl_setopt($session, CURLOPT_POST, true);
        curl_setopt($session, CURLOPT_POSTFIELDS, $postArguments);
        curl_setopt($session, CURLOPT_HEADER, true);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_USERPWD, $merchant_id . ':' . html_entity_decode($api_key));

        $response = curl_exec($session);

        $header_len = curl_getinfo($session, CURLINFO_HEADER_SIZE);
        $resHeader = substr($response, 0, $header_len);
        $resBody = substr($response, $header_len);

        curl_close($session);

        try{
            if(is_object(json_decode($resBody))){   //If we got a response,
                $resultObj = json_decode($resBody); //We turn it into an object for later use.
            }
            else{
                preg_match('#^HTTP/1.(?:0|1) [\d]{3} (.*)$#m', $resHeader, $match);
                throw new \Exception("API Call failed! The error was: " . trim($match[1] ?? ' '));
            }
        }
        catch(\Exception $e){
            echo $e->getMessage();
        }

        $OrderCode = $resultObj->OrderCode??'';
        $ErrorCode = $resultObj->ErrorCode??'';
        $ErrorText = $resultObj->ErrorText??'';

        if(!empty($ErrorCode)){ //Error handling.

            $layoutData = [
                'method'    => $method,
                'order'     => $order,
            ];

            $event->setLayout('default_payment_process');
            $event->setLayoutData($layoutData);

            $app->enqueueMessage($ErrorText, 'error');
            return;
        }

        $paymentUrl = $scheme . $host_development . $new_transaction_path . '?ref=' . $OrderCode;

        $logData = $this->createEmptyLog();

        // Log payment insert/update.
        $logData["id"]              = null;
        $logData["id_order"]        = $order->id;      //should always be passed
        $logData["status"]          = "P";               //should always be passed
        $logData["id_order_payment"] = 0;   // Can't have it yet. 
        $logData["order_code"]      = 0;
        $logData["transaction_id"]  = 1;
        $logData["order_total"]     = $order->original_price;  // Do we store cents?
        $logData["currency"]        = $order->id_payment_currency;
        $logData["custom"]          = 1;
        $logData["error_code"]      = $ErrorCode;
        $logData["error_text"]      = $ErrorText;
        $logData["ref"]             = 1;
        $logData["installments"]    = 1;                        // Change with installments.
        $logData["response_raw"]    = $paymentUrl;              // Payment url?
        $logData["created_on"]      = Factory::getDate()->format('Y-m-d H:i:s');
        $logData["created_by"]      = 1;                          // ?

	    $this->insertLog($logData);

		// $html = '';
		// METHOD 1 ( by rendering form or custom html to the user)
//	    $payment_form_data = [
//		    'url' => $paymentUrl,
//	    ];
//
//	    // Load the layout file (tmpl/view file)
//	    $layout = new FileLayout('default_proccess_payment', $this->getLayoutPath());
//
//	    // Render the layout and pass data
//	    $html = $layout->render($payment_form_data);


        // https://alfa.el2.demosites.gr/index.php?option=com_alfa&task=cart.paymentResponse&payment=completed

		// METHOD 2 ( by instant redirecting the user )

        $event->setRedirectUrl($paymentUrl);

    }

    public function onPaymentResponse($event) {

        $app = $this->getApplication();
        $input = $app->input;

        $order = $event->getOrder();

        // &t=1b755fd5-8404-4ab5-9903-336e0591d17b&s=8227677046572600&lang=el-GR&eventId=0&eci=1

        //eci code : https://developer.viva.com/integration-reference/response-codes/#electronic-commerce-indicator
        //eventId code : https://developer.viva.com/integration-reference/response-codes/

        $transaction_id = $input->get('t','');
        $order_code = $input->get('s','');
        $paymentStatus = $input->get('payment','completed');

        $responseUrl = $input->server->get('REQUEST_URI', '', 'STRING'); //Uri::getInstance()->toString();

        $redirectUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';

        if( empty($transaction_id)  || 
            empty($order_code)      ||
            ($paymentStatus !== 'completed' && $paymentStatus !== 'failed' )
        ){ 
            $app->enqueueMessage("Payment was unsuccessful. Missing response data.");
            $event->setRedirectUrl($redirectUrl);
            return;
//            $app->redirect($redirectUrl);
        }

        $paymentDone = false;

        // $redirectUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_process';
        // $this->onOrderProcessView($order);

        $orderStatus = 'F';

        if($paymentStatus == 'completed'){
            $orderStatus = 'S';
            $message = 'Order paid successfully.';
            $paymentDone = true;
        }else if($paymentStatus == 'failed'){
            $orderStatus = 'F';
            $message = 'Order paid failed.';
            $paymentDone = false;
        }


        // Insert payment data.
        if($paymentStatus == 'completed'){
            $orderPaymentData = self::createEmptyOrderPayment();
            $orderPaymentData = [
                'id_order' => $order->id,
                'id_currency' => $order->id_currency,
                'id_payment_method' => $order->id_payment_method,
                'id_user' => $order->id_user,
                'amount' => $order->payed_price,
                'conversion_rate' => 1.00,
                'transaction_id' => $transaction_id,
                'date_add' => Factory::getDate()->format('Y-m-d H:i:s'),
            ];
            $id_order_payment = self::insertOrderPayment($orderPaymentData);
        }
        

        // METHOD 1: Use the previous log data 
        $logData = $this->loadLogData($order->id);

        if(!empty($logData)){
            $logToInsert = $logData[0];    
        }

        // METHOD 2: Create a new log data
//        $logToInsert = $this->createEmptyLog();

//        $logToInsert['id'] = null; //if its empty ( '' or 0 or null ) it will be inserted as a new row in logs
//        $logToInsert['order_id'] = $order->id;
        $logToInsert['id_order_payment'] = $id_order_payment ?? 0;
        $logToInsert['status'] = $orderStatus;
        $logToInsert['order_code'] = $order_code;
        $logToInsert['transaction_id'] = $transaction_id;
//        $logToInsert['order_total'] = $order->original_price;
//        $logToInsert['currency'] = $order->id_currency;
//        $logToInsert['custom'] = 1;
//        $logToInsert['error_code'] = "";
//        $logToInsert['error_text'] = "";
//        $logToInsert['ref'] = 1;
//        $logToInsert['installments'] = 1;
        $logToInsert['response_raw'] = $responseUrl;
        $logToInsert['created_on'] = Factory::getDate()->format('Y-m-d H:i:s');
        $logToInsert['created_by'] = 1;

        self::insertLog($logToInsert);

        $app->setUserState('com_alfa.payment_done', $paymentDone);//set variable to handle it from the onOrderComplete which we redirect the user
        $app->enqueueMessage($message, $paymentDone?'info':'error');
        $event->setRedirectUrl($redirectUrl);   //send user to complete order view
//        $app->redirect($redirectUrl);

    }

    // After order has been completed.
	public function onOrderCompleteView($event){
        $order = $event->getOrder();
        $method = $event->getMethod();

        $layoutData = [
            'method'    => $method,
            'order'     => $order,
        ];

        $event->setLayout('default_payment_success');
        $event->setLayoutData($layoutData);
    }

    // At default cart view.
    public function onCartView($event) {

        $cart = $event->getCart();
        $method = $event->getMethod();

        $layoutData = [
            'method'    => $method,
            'cart'      => $cart
        ];

        $event->setLayout('default_cart_view');
        $event->setLayoutData($layoutData);

    }

    public function onItemView($event){
        $item = $event->getItem();
        $method = $event->getMethod();

	    $layoutData = [
		    'method' => $method,
		    'item' => $item,
	    ];

//		we should create a layout file or run the standar layout file
//	    $event->setLayoutPluginName('standard'); //auto setted to viva from html view
//	    $event->setLayoutPluginType('alfa-payments'); //auto setted
	    $event->setLayout('default_product_view');
	    $event->setLayoutData($layoutData);

//		$event->setRedirectUrl("https://www.google.com");
//	    $event->setRedirectCode(301);

    }

    public function onAdminOrderDelete($event){
        $orderID = $event->getOrder()->order_id;
        parent::deleteLogEntry($orderID);
        $event->setCanDelete(true);
    }

    // TODO: CHECK THIS ???
    public function onAdminOrderBeforeSave($event){
        $order = $event->getOrder();
        $event->setCanSave(true);
    }


}
