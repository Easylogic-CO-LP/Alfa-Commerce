<?php

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Abstract Fields Plugin
 *
 * @since  3.7.0
 */
abstract class PaymentsPlugin extends Plugin //implements SubscriberInterface
{

	protected $mustHaveColumns = [
        ['name'=>'order_id','mysql_type' => 'int(11)', 'default' => 'NULL'],
        ['name'=>'status', 'mysql_type' => 'char(3)', 'default' => 'NULL'],//S ( success ) ,P ( pending ) , F ( failed ) ,R ( refunded )
    ];

    // public function productView($event){

    //     $item = $event->getItem();
    //     $method = $event->getMethod();

    //     // if($method->type !== $this->_type){
    //     //     return;
    //     // }

    //     $html = 
    //         <<<HTML
    //             <div>aaa
    //                 <h5>{$method->name}</h5>
    //                 <p>{$method->description}</p>
    //             </div>
    //         HTML;

    //     return $html;
    // }

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   5.0.0
	 */
	//	public static function getSubscribedEvents(): array
	//	{
	//		return [
	//          'onAdminOrderView'  => 'adminOrderView',
	//			'onCartView'     	=> 'cartView',
	//			'onProcessPayment' 	=> 'processPayment',
	//			'onCompleteOrder'   => 'completeOrder',
	//		];
	//	}

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  3.7.0
	 */
//	protected $autoloadLanguage = true;

	/**
	 * Application object.
	 *
	 * @var    \Joomla\CMS\Application\CMSApplication
	 * @since  4.0.0
	 */
//	protected $app;

//	public function __construct(DispatcherInterface $dispatcher, array $config = [])
//	{
//		parent::__construct($dispatcher, $config);
//		$this->database = Factory::getContainer()->get('DatabaseDriver');
//		$this->layoutPath = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/tmpl';
//
//		// $this->emptyLogData = $this->createEmptyLog();
//
//	}


    
   
    // $orderId = $this->app->getUserState('com_alfa.order_id');


    protected function insertOrderPayment($data):int {

    	// $this->app->getUserState('com_alfa.order_id')

        // Function can only handle arrays.
        if(is_object($data))
            $data = (array)$data; //json_decode(json_encode($data), true); // for nested also

//    	 $user  = $this->app->getIdentity();
//    	 $userId = $user->id;
//         if(empty($userId))
//            $userId = $data['id_user'];


		// use Joomla\CMS\Date\Date;//libraries > src > Date > Date.php
		// $offset = Factory::getConfig()->get('offset');

		// $now = new Date('now',$offset);

         $component_params = ComponentHelper::getParams('com_alfa');
         $currency_id = $component_params->get('default_currency', 47);//47 is euro with number 978

         $paymentObject = new \stdClass();
//         $paymentObject->id              = isset($data['id']) ? intval($data['id']) : 0;
         $paymentObject->id_order        = isset($data['id_order']) ? $data['id_order'] : 0;
         $paymentObject->id_currency     = isset($data['id_currency']) && $data['id_currency'] > 0 ? intval($data['id_currency']) : $currency_id;
         $paymentObject->id_payment_method  = isset($data['id_payment_method']) ? intval($data['id_payment_method']) : 0;
         $paymentObject->id_user         = isset($data['id_user']) ? $data['id_user'] : 0;
         $paymentObject->amount          = isset($data['amount']) ? floatval($data['amount']) : 0.0;
         $paymentObject->conversion_rate = isset($data['conversion_rate']) ? floatval($data['conversion_rate']) : 0.0;
         $paymentObject->transaction_id  = isset($data['transaction_id']) ? $data['transaction_id'] : '';
         $paymentObject->added        = !empty($data['added']) ? Factory::getDate($data['added'])->toSql() : NULL;

        $errorMessage = "Insufficient data to insert a payment.";
        if(!$paymentObject){
            $this->app->enqueueMessage($errorMessage, "error");
            return 0;
        }

        $db = self::getDatabase();
        $db->insertObject('#__alfa_order_payments', $paymentObject, "id");

        return $paymentObject->id;
    }

     public function createEmptyOrderPayment(){
        $orderPaymentArray = [
            'id_order' => null,
            'id_currency' => null,
            'id_payment_method' => null,
            'id_user' => null,
            'amount' => null,
            'conversion_rate' => null,
            'transaction_id' => null,
            'date_add' => null,
        ];

        return $orderPaymentArray;

     }

	public function onAdminOrderPaymentPrepareForm($event)
	{
		$order = $event->getData();
		$form = $event->getForm();

		$event->setData($order);
		$event->setForm($form);
	}

	public function onAdminOrderPaymentView($event) {
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

	public function onAdminOrderPaymentViewLogs($event) {

		$order = $event->getOrder();

		// load logs from xml
		$formFile = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/params/logs.xml';
		if (!file_exists($formFile)) return;

		$xml = simplexml_load_file($formFile);

		// Get logs data from db
		$logData = self::loadLogData($order->id,false);

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

}
