<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 *
 */

namespace Joomla\Plugin\AlfaShipments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use Joomla\CMS\Factory;

// use Joomla\CMS\Component\ComponentHelper;
// use Joomla\CMS\Factory;
// use Joomla\CMS\Layout\FileLayout;
// use Joomla\CMS\Plugin\PluginHelper;
// use \Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Text Plugin
 *
 * @since  3.7.0
 */
final class Standard extends ShipmentsPlugin
{

    // public function onAfterRender()
    // {

        // $app = self::getApplication();

        // if($app->isClient('site')) exit ;
        
        // $buffer=$this->app->getBody();
        // $buffer=str_replace('</ body>','</body>',$buffer);
            
        // $html='<div id="blablabla">heyyy</div>';
        // $buffer=str_replace('</body>',$html."\n</body>",$buffer);
                
        // // if($this->app->input->getCmd('option')=='com_virtuemart' && $this->app->input->getCmd('view')=='cart')
        // // {
        // //     $db = JFactory::getDBO();
        // //     $db->setQuery('SELECT virtuemart_shipmentmethod_id FROM `#__virtuemart_shipmentmethods` WHERE `shipment_element`='.$db->quote("bownow").' AND `published`=1 ORDER BY `virtuemart_shipmentmethod_id` ASC LIMIT 1;');
        // //     $method=$this->getVmPluginMethod($db->loadResult());
        // //     $this->convert($method);
            
        //     $url = \Joomla\CMS\Router\Route::_('index.php?option=com_virtuemart&view=cart&tmpl=component&plg=boxnow',false);

        //     $headData='<script>
        //             alert("test");
        //     </script>';
        //     $buffer=str_replace('</head>',$headData."\n</head>",$buffer);
        // // }
        // $this->app->setBody($buffer);
    // }
    
    // public function onCartView($cart) : string{        
    // }

    // public function onOrderFormSubmit($form){
    //     return;
    // }

    public function onOrderBeforePlace($event)
    {
        $cart = $event->getCart();
        $data = $cart->getData();
        $data->shipment_costs_total = self::calculateShippingCost($cart);
    }

    public function onOrderAfterPlace($event)
    {
        $orderData = $event->getOrder();
        $app = self::getApplication();

        // Inserting order shipment.
        $emptyShipmentEntry = self::createEmptyOrderShipment();
        $emptyShipmentEntry['id_order'] = $orderData->id;
        $emptyShipmentEntry['id_currency'] = $orderData->id_currency;
        $emptyShipmentEntry['id_shipment_method'] = $orderData->id_shipment_method;
        $emptyShipmentEntry['id_user'] = $orderData->id_user;
        $emptyShipmentEntry['amount'] = $orderData->total_shipping;
        $emptyShipmentEntry['track_id'] = $orderData->shipping_tracking_number ?? "";
        $emptyShipmentEntry['date_add'] = date('Y-m-d H:i:s');
        $id_order_shipment = self::insertOrderShipment($emptyShipmentEntry);

        // Logging.
        $newLog = self::createEmptyLog();
        $newLog["id_order"] = $orderData->id;
        $newLog["status"] = "P";
        $newLog["id_order_shipment"] = $id_order_shipment;
        $newLog["shipment_total"] = $orderData->total_shipping;
        $newLog["currency"] = $orderData->id_payment_currency;
        $newLog["created_on"]      = Factory::getDate()->format('Y-m-d H:i:s');
        self::insertLog($newLog);

        return null;
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

//        echo "<pre>";
//        print_r($calculationData);
//        echo "</pre>";
//        exit;


        // if (isset($calculationData['costs']) && is_array($calculationData['costs'])) {
        //     foreach ($calculationData['costs'] as $costKey => $costData) {
        //         if (isset($costData['cost'])) {
        //             $cost = $costData['cost'];
        //             // echo "Cost: $cost\n"; // Output: Cost: 12
        //         }
        //     }
        // }

        $cartItems = $cart->getData()->items;
        $selectedShipmentCosts = $calculationData['costs'];

        $cost = self::findBestShippingMethod($cartItems, $selectedShipmentCosts, $zipCode);

        return $cost;
    }

    public function findBestShippingMethod($products, $shippingMethods, $zipCode = -1) {

        if(empty($products) || empty($shippingMethods))
            return 0;

        // Calculate total dimensions of products
        $packageDimensions = self::getTotalDimensions($products);

        // Find the cheapest valid shipping method
        usort($shippingMethods, function ($a, $b) {
            return $a['cost'] <=> $b['cost']; // Sort by cost ascending
        });

        foreach ($shippingMethods as $method) {
            if (
                $packageDimensions['width'] <= $method['width-max'] &&
                $packageDimensions['height'] <= $method['height-max'] &&
                $packageDimensions['depth'] <= $method['depth-max'] &&
                $packageDimensions['weight'] <= $method['weight-max']
                && self::isValueInRange($zipCode, $method['zip-start'], $method['zip-end'])  // Make sure we have a valid zip as well.
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


    function isValueInRange($value, $min, $max) {

        // Normalize all to strings
        $valueStr = is_string($value) ? $value  : strval($value);
        $minStr   = is_string($min)   ? $min    : strval($min);
        $maxStr   = is_string($max)   ? $max    : strval($max);

//        echo $valueStr;
//        echo "<br>" . $min;
//        echo "<br>" . $max;
//        echo "<br>";

        // If all are numeric, compare numerically (including floats)
        if (is_numeric($valueStr) && is_numeric($minStr) && is_numeric($maxStr)) {
            return $valueStr >= $minStr && $valueStr <= $maxStr;
        }

        // Otherwise, compare as normalized strings
        return strcmp($valueStr, $minStr) >= 0 && strcmp($valueStr, $maxStr) <= 0;
    }

    public function getShipmentPackages($shipmentID){
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select("params")
            ->from("#__alfa_shipments")
            ->where("id=" . $shipmentID);

        $db->setQuery($query);
        return $db->loadResult();
    }





}
