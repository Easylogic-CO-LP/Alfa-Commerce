<?php

namespace Alfa\Component\Alfa\Administrator\Plugin;


use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Abstract Fields Plugin
 *
 * @since  3.7.0
 */
abstract class ShipmentsPlugin extends Plugin //implements SubscriberInterface
{

    // Used to uniquely identify a shipment log entry.
    protected $logIdentifierField = "id_order_shipment";

    protected $mustHaveColumns = [
        ['name'=>'id_order','mysql_type' => 'int(11)', 'default' => 'NULL'],
        ['name'=>'id_order_shipment','mysql_type' => 'int(11)', 'default' => 'NULL'],
    ];

    protected function createEmptyOrderShipment(){
        $orderPaymentArray = [
            'id_order' => null,
            'id_currency' => null,
            'id_shipment_method' => null,
            'id_user' => null,
            'amount' => null,
            'track_id' => null,
            'date_add' => null,
        ];

        return $orderPaymentArray;
    }

    protected function insertOrderShipment($data){

        if(is_object($data))
            $data = (array)$data; //json_decode(json_encode($data), true); // for nested also

        $component_params = ComponentHelper::getParams('com_alfa');
        $currency_id = $component_params->get('default_currency', 47);  //47 is euro with number 978

        $shipmentObject = new \stdClass();
//         $paymentObject->id              = isset($data['id']) ? intval($data['id']) : 0;
        $shipmentObject->id_order        = isset($data['id_order']) ? $data['id_order'] : 0;
        $shipmentObject->id_currency     = isset($data['id_currency']) && $data['id_currency'] > 0 ? intval($data['id_currency']) : $currency_id;
        $shipmentObject->id_shipment_method  = isset($data['id_shipment_method']) ? intval($data['id_shipment_method']) : 0;
        $shipmentObject->id_user         = isset($data['id_user']) ? $data['id_user'] : 0;
        $shipmentObject->amount          = isset($data['amount']) ? floatval($data['amount']) : 0.0;
        $shipmentObject->track_id  = isset($data['track_id']) ? $data['track_id'] : '';
        $shipmentObject->added        = !empty($data['date_add']) ? Factory::getDate($data['date_add'])->toSql() : NULL;

        $errorMessage = "Insufficient data to insert a shipment.";
        if(!$shipmentObject){
            $this->app->enqueueMessage($errorMessage, "error");
            return 0;
        }

        $db = self::getDatabase();
        $db->insertObject('#__alfa_order_shipments', $shipmentObject,"id");

        return $shipmentObject->id;
    }
    

	public function onAdminOrderShipmentPrepareForm($event)
    {
        $order = $event->getData();
        $form = $event->getForm();

        $event->setData($order);
        $event->setForm($form);
    }

    public function onAdminOrderShipmentView($event) {
        $order = $event->getOrder();
        $event->setOrder($order);

	    $event->setLayoutPluginName($this->_name);
	    $event->setLayoutPluginType($this->_type);
	    $event->setLayout('default_order_view');
//	    $event->setLayoutData(
//		    [
//			    "logData" => $logData,
//			    "xml" => $xml
//		    ]
//	    );
    }

	public function onAdminOrderShipmentViewLogs($event) {

		$order = $event->getOrder();
        $method = $event->getMethod();  // Represents the shipping order's shipment.

		// load logs from xml
		$formFile = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/params/logs.xml';
		if (!file_exists($formFile)) return;

		$xml = simplexml_load_file($formFile);

		// Get logs data from db
		$logData = self::loadLogData($order->id, $method->id);

		$event->setLayoutPluginName($this->_name);
		$event->setLayoutPluginType($this->_type);
		$event->setLayout('default_order_logs_view');
		$event->setLayoutData(
			[
				"logData" => $logData,
				"xml" => $xml
			]
		);


	}



//Improved Function with Rotation Consideration

//    public function getTotalDimensions2($products)
//    {
//        $totalWidth = 0;
//        $maxHeight = 0;
//        $maxDepth = 0;
//
//        foreach ($products as $product) {
//            // Try different orientations and pick the best one dynamically
//            $orientations = [
//                [$product['width'], $product['height'], $product['depth']], // Default
//                [$product['height'], $product['width'], $product['depth']], // Swap width and height
//                [$product['depth'], $product['height'], $product['width']], // Swap width and depth
//                [$product['depth'], $product['width'], $product['height']], // Swap height and depth
//                [$product['width'], $product['depth'], $product['height']], // Another rotation
//                [$product['height'], $product['depth'], $product['width']], // Another rotation
//            ];
//
//            // Pick the best orientation to minimize the final package dimensions
//            $bestOrientation = $orientations[0];
//            foreach ($orientations as $o) {
//                if ($o[0] <= $o[1] && $o[0] <= $o[2]) { // Ensure width is the smallest
//                    $bestOrientation = $o;
//                    break;
//                }
//            }
//
//            // Update package dimensions
//            $totalWidth += $bestOrientation[0]; // Sum best width
//            $maxHeight = max($maxHeight, $bestOrientation[1]); // Max height
//            $maxDepth = max($maxDepth, $bestOrientation[2]); // Max depth
//        }
//
//        return [
//            'width' => $totalWidth,
//            'height' => $maxHeight,
//            'depth' => $maxDepth
//        ];
//    }

    public function onCalculateShippingCost($event){
        $event->setShippingCost(0);
    }

}
