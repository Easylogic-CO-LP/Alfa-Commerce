<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Viva
 *
 */

namespace Joomla\Plugin\AlfaPayments\Viva\Extension;


use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;
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

    /*
     *  Inputs: An alfa-commerce order object.
     *          (#)
     *  Returns: The html of a layout to be displayed as html.
     */
    public function onOrderProcessView($order) : string {

        $app = $this->getApplication();
        $html = '';

        $params = $order->payment->params;

        //Viva does not accept decimal values. (We set amount = 1000 for 10 euros.)
        $priceParams = $order->currency_data;

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
            
            // $html = $params
            $html .= '<button onclick="location.reload();">Retry paying</button>';
            $app->enqueueMessage('Missing required Viva Payment settings. Please check your payment configuration or get in contact with the eshop administrator to report the problem.', 'error');
            return $html;
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
            $app->enqueueMessage($ErrorText, 'error');
            return $html;
        }

        $paymentUrl = $scheme . $host_development . $new_transaction_path . '?ref=' . $OrderCode;


        $logData = $this->createEmptyLog();

        // Log payment insert/update.
        $logData["id"]              = null;
        $logData["order_id"]        = $order->id;      //should always be passed
        $logData["status"]          = "P";               //should always be passed
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
		$app->redirect($paymentUrl);

        return $html;

    }

    public function onPaymentResponse($order) {


        $app = $this->getApplication();
        $input = $app->input;

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
            ($paymentStatus!=='completed' && $paymentStatus!=='failed' )
        ){ 
            $app->enqueueMessage("Payment was unsuccessful. Missing response data.");
            $app->redirect($redirectUrl);
        }

//        exit;

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

        // METHOD 1: Use the previous log data 
        $logData = $this->loadLogData($order->id);

        if(!empty($logData)){
            $logToInsert = $logData[0];    
        }

        // METHOD 2: Create a new log data
//        $logToInsert = $this->createEmptyLog();

//        $logToInsert['id'] = null; //if its empty ( '' or 0 or null ) it will be inserted as a new row in logs
//        $logToInsert['order_id'] = $order->id;
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


        // Insert payment data.
        if($paymentStatus == 'completed'){
            $orderPaymentData = self::createEmptyOrderPayment();
            $orderPaymentData = [
                'id_order' => $order->id,
                'id_currency' => $order->id_currency,
                'id_payment_method' => $order->id_paymentmethod,
                'id_user' => $order->id_user,
                'amount' => $order->payed_price,
                'conversion_rate' => 1.00,
                'transaction_id' => $transaction_id,
                'date_add' => Factory::getDate()->format('Y-m-d H:i:s'),
            ];
            self::insertOrderPayment($orderPaymentData);
        }


        $app->setUserState('com_alfa.payment_done', $paymentDone);//set variable to handle it from the onOrderComplete which we redirect the user
        $app->enqueueMessage($message, $paymentDone?'info':'error');
        $app->redirect($redirectUrl);//send user to complete order view

    }

	public function onOrderCompleteView($order) : string {

        $app = $this->getApplication();
        $html = '';

        $paymentStatus = $app->getUserState('com_alfa.payment_done', false); //handle the variable setted in the payment response

        if($paymentStatus){
            $html .= '<p>Η πληρωμή έγινε επιτυχώς</p>';
        }else{
            $html .= '<p>Η πληρωμή απέτυχε! Θέλετε να ξαναδοκιμάσετε;</p><br>';
            $html .= '<a href="/index.php?option=com_alfa&view=cart&layout=default_order_process">Pay again</a>';
        }


        return $html;
	}

    public function onProductView($productData, $method){
        $html =
            "<div>
                <h5>{$method->name}</h5>
                <p>{$method->description}</p>
            </div>
            ";

        return $html;
    }

    public function onAdminOrderDelete($orderID){
        parent::deleteLogEntry($orderID);
        return true;
    }

    public function onAdminOrderBeforeSave(&$order): bool {
        $app = $this->getApplication();
        $app->enqueueMessage("Could not save the order.");
        return false;
    }


}
