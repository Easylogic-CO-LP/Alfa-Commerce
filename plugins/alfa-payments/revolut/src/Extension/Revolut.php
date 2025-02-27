<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Revolut
 */

namespace Joomla\Plugin\AlfaPayments\Revolut\Extension;

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
final class Revolut extends PaymentsPlugin
{

    public function onOrderProcessView($order): string {

        $app = self::getApplication();

        $sandboxUrl = 'https://sandbox-merchant.revolut.com/api/orders';
//        $productionUrl = 'https://merchant.revolut.com/api/orders';
        $productionUrl = 'https://sandbox-merchant.revolut.com/api/orders';

        // Parameters set by administrator.
        $params = $order->payment->params;
        $sandboxMode = $params['revolut_sandbox_mode'];

        $paymentData = [];
        $paymentData['secret_key'] = $params['revolut_secret_key'];
        $paymentData['url'] = $sandboxMode ? $sandboxUrl : $productionUrl;
        $paymentData['redirect_url'] = Uri::getInstance()->toString().'/index.php?option=com_alfa&task=payment.response';

        // Create a revolut order.
        $revolutCreateOrderResponse = self::createOrder($order, $paymentData);
        $revolutCheckoutUrl = $revolutCreateOrderResponse['checkout_url'];
        $revolutOrderId     = $revolutCreateOrderResponse['id']?:'';

        $revolutErrorCode = $revolutCreateOrderResponse['code'] ??'';
        $revolutErrorMessage = $revolutCreateOrderResponse['message'] ?? '';

        // Saving the order's id and response url.
        $app->setUserState('com_alfa.revolut_order_id', $revolutOrderId);
        $app->setUserState('com_alfa.revolut_response_url', $revolutCheckoutUrl);

        $orderStatus = empty($errorCode)?'P':'F';
        
        // Logging.
        $logData = $this->createEmptyLog();
        $logData["id"]              = null;
        $logData["order_id"]        = $order->id;      //should always be passed
        $logData["status"]          = $orderStatus;    //should always be passed
        $logData["transaction_id"]  = $revolutOrderId;
        $logData["order_total"]     = $order->original_price;  // Do we store cents?
        $logData["currency"]        = $order->id_payment_currency;
        $logData["error_code"]      = $revolutErrorCode ?: '-';
        $logData["error_text"]      = $revolutErrorMessage ?: '-';
        $logData["installments"]    = 1;                        // Change with installments.
        $logData["checkout_url"]    = $revolutCheckoutUrl;
        $logData["created_on"]      = Factory::getDate()->format('Y-m-d H:i:s');//stores in utc format always
        $logData["created_by"]      = $app->getIdentity()->id;

        $this->insertLog($logData);

        if(!empty($errorCode)){
            // $app->enqueueMessage("Something went wrong while creating a Revolut order.");

            //by returning a non-empty string it will show us the process layout. By reloading the page onOrderProcessView will be reloaded
            return '(oopss..'.$revolutErrorCode.') '.$revolutErrorMessage;
                   // '<br><button onclick="location.reload();" style="padding: 10px; background-color: red; color: white; border: none; cursor: pointer;">Reload Page</button>';
        }

        Factory::getApplication()->redirect($revolutCheckoutUrl);

        return "";
    }

    public function onPaymentResponse($order){


        $app = self::getApplication();
        // $input = $app->input;

        // echo '<pre>';
        // print_r($input);
        // echo '</pre>';
        // exit;

        $response = self::retrieveOrderDetails($order);

        $errorCode = $response['code'] ?? '';
        $errorMessage = $response['message'] ?? '';

        $redirectUrl = $app->getUserState('com_alfa.revolut_response_url', null);

        if(empty($errorCode)) { // No errors
            if($response['state'] == 'pending')         $orderStatus = 'P';
            else if($response['state'] == 'completed')  $orderStatus = 'S';
            else if($response['state'] == 'failed')     $orderStatus = 'F';
        }
        else
            $orderStatus = 'F';

        // Logging.
        $logData = $this->createEmptyLog();
        $logData["id"]              = null;
        $logData["order_id"]        = $order->id;      //should always be passed
        $logData["status"]          = $orderStatus;    //should always be passed
        $logData["order_code"]      = 0;
        $logData["transaction_id"]  = $response['id'] ?: '';
        $logData["order_total"]     = $order->original_price;  // Do we store cents?
        $logData["amount_paid"]     = $response['amount'] ?: 0.0;
        $logData["currency"]        = $order->id_payment_currency;
        $logData["custom"]          = 1;
        $logData["error_code"]      = $errorCode ?: '-';
        $logData["error_text"]      = $errorMessage ?: '-';
        $logData["ref"]             = 1;
        $logData["installments"]    = 1;                        // Change with installments.
        $logData["response_raw"]    = $redirectUrl;
        $logData["created_on"]      = Factory::getDate()->format('Y-m-d H:i:s');//stores in utc format always
        $logData["created_by"]      = $app->getIdentity()->id;

        $this->insertLog($logData);

        if(!empty($errorCode)){
            $app->enqueueMessage("Something went wrong while retrieving data from a Revolut order.");
            $app->setUserState('com_alfa.payment_done', false);
        }
        else
            $app->setUserState('com_alfa.payment_done', true);

        // Insert payment data.
        if($orderStatus == 'S'){
            $orderPaymentData = self::createEmptyOrderPayment();
            $orderPaymentData = [
                'id_order' => $order->id,
                'id_currency' => $order->id_currency,
                'id_payment_method' => $order->id_paymentmethod,
                'id_user' => $order->id_user,
                'amount' => $order->payed_price,
                'conversion_rate' => 1.00,
                'transaction_id' => $response['id'] ?: '',
                'date_add' => Factory::getDate()->format('Y-m-d H:i:s'),
            ];
            self::insertOrderPayment($orderPaymentData);
        }

        $orderViewUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
        $app->redirect($orderViewUrl);  // Send user to complete order view

        return "";

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

    

    /**
     *  Creates a new revolut order.
     *  @param $order object Contains details about the order.
     *  @param $paymentData array Contains specific payment data (secret key, urls, etc).
     *  @return array associative array with the response from revolut.
     */
    protected function createOrder($order, $paymentData){

        $url = $paymentData['url'];
        $secretKey = $paymentData['secret_key'];
        $redirectUrl = $paymentData['redirect_url'];

        $priceAmount = $order->original_price * (10 ** $order->currency_data->decimal_place);

        $curl = curl_init();
        // https://alfa.el2.demosites.gr/index.php?option=com_alfa&task=cart.paymentResponse&payment=completed

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{
              "amount": "' . $priceAmount. '",
              "currency": "EUR",'
            . '"redirect_url": "' . $redirectUrl . '"'
            . '}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'Revolut-Api-Version: 2024-09-01',
                'Authorization: Bearer ' . $secretKey,
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);

    }


    /**
     *  Gets the details of a Revolut order.
     *  @param $order object Contains data about the order.
     *  @return array The details of the Revolut order/payment.
     */
    protected function retrieveOrderDetails($order){

        $params = $order->payment->params;
        $secret_key = $params['revolut_secret_key'];

        $curl = curl_init();
        $app = self::getApplication();
        $order_id = $app->getUserState('com_alfa.revolut_order_id');

        // Get correct url.
        $sandboxUrl = 'https://sandbox-merchant.revolut.com/api/orders';
//        $productionUrl = 'https://merchant.revolut.com/api/orders';
        $productionUrl = 'https://sandbox-merchant.revolut.com/api/orders';
        $sandboxMode = $params['revolut_sandbox_mode'];
        $url = $sandboxMode ? $sandboxUrl : $productionUrl;

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url . '/' . $order_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Revolut-Api-Version: 2024-09-01',
                'Authorization: Bearer ' . $secret_key
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }


    /**
     *   - CURRENTLY NOT IN USE. -
     *  Gives the user the opportunity to pay for their submitted order.
     *  @param $order object Contains data about the order.
     *  @param $paymentData array Contains data about the occurring payment.
     *  @return array Response data (associative array).
     */
    // protected function payOrder($order, $paymentData){

    //     $paymentUrl = 'https://sandbox-merchant.revolut.com/api/orders/' . $paymentData['order_response_id'] . '/payments';

    //     $curl = curl_init();

    //     curl_setopt_array($curl, array(
    //         CURLOPT_URL => $paymentUrl,
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_ENCODING => '',
    //         CURLOPT_MAXREDIRS => 10,
    //         CURLOPT_TIMEOUT => 0,
    //         CURLOPT_FOLLOWLOCATION => true,
    //         CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //         CURLOPT_CUSTOMREQUEST => 'POST',
    //         CURLOPT_POSTFIELDS =>'{
    //           "payment_method": {
    //             "type": "card",
    //             "id": ' . '"' . $paymentData['order_response_id'] . '",
    //             "initiator": "merchant"
    //           }
    //         }',
    //         CURLOPT_HTTPHEADER => array(
    //             'Content-Type: application/json',
    //             'Accept: application/json',
    //             'Authorization: Bearer ' . $paymentData['secret_key']
    //         ),
    //     ));

    //     $response = curl_exec($curl);
    //     curl_close($curl);

    //     return json_decode($response, true);

    // }




}













