<?php

namespace Alfa\Component\Alfa\Administrator\Plugin;

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
abstract class PaymentsPlugin extends CMSPlugin //implements SubscriberInterface
{
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
	private $database;

	private $mustHaveColumns = [
			['name'=>'order_id','mysql_type' => 'int(11)', 'default' => 'NULL'],
			['name'=>'status', 'mysql_type' => 'char(3)', 'default' => 'NULL'],//S ( success ) ,P ( pending ) , F ( failed ) ,R ( refunded )
	];

	private $layoutPath;

	// public 	$emptyLogData;

	public function __construct(DispatcherInterface $dispatcher, array $config = [])
	{
		parent::__construct($dispatcher, $config);
		$this->database = Factory::getContainer()->get('DatabaseDriver');
		$this->layoutPath = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/tmpl';

		// $this->emptyLogData = $this->createEmptyLog();


	}


    /**
     * @param $orderData object Contains data about the order to be saved.
     * @return bool true for saving operations to continue, false for not.
     */
    public function onAdminOrderBeforeSave(&$order): bool {
        return true;
    }

    public function onPaymentResponse($order){
        return;
    }

    public function onAdminOrderAfterSave($order){
        return;
    }

    public function onAdminOrderPrepareForm(&$form, &$order){
        return;
    }

    public function onAdminOrderView(&$order) : string{

    	// load logs from xml
        $formFile = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/params/logs.xml';
        if (!file_exists($formFile)) return '';

        $xml = simplexml_load_file($formFile);

        // Get logs data from db
        $logData = self::loadLogData($order->id,false);

        if(empty($logData)) return '';

        function createHeadingLabel($label,$type){
	    	$field = new \stdClass();
			$field->label = Text::_($label);
	    	$field->type = $type;
	    	return $field;
	    }

        // Get the labels from xml file
        $fieldLabels = [];
	    foreach($xml->fields->fieldset->field as $field) {
	        $fieldLabels[(string)$field['name']] = createHeadingLabel($field['label'], $field['mysql_type']);
	    }

        // Get table headings dynamically		
	    $headers = array_keys(get_object_vars(reset($logData))); // Get keys from the first object of logs data from db.

	    $tableHeadings = $tableBody = '';

	    foreach ($headers as $header) {

	    	// If the field from db does not have a label set, then we will create one.
	    	if(!isset($fieldLabels[$header]) || empty($fieldLabels[$header]->label)){ 
	    		$generatedLabel = ucfirst(str_replace('_', ' ', $header));      // generate label from db column name
	    		$fieldLabels[$header] = createHeadingLabel($generatedLabel,'');         // assign to the fieldLabel array of objects
	    	}

	    	$label = $fieldLabels[$header]->label;

	    	// Generate table headings dynamically
	        $tableHeadings .= "<th>" . Text::_($label) . "</th>"; // Format heading
	    }
	
        // Generate table body rows dynamically
		foreach ($logData as $log) {
		    $tableBody .= "<tr>";
		    foreach ($headers as $header) {

		    	$label = $fieldLabels[$header]->label;
		    	$value = htmlspecialchars($log->$header ?? '');
		    	// TODO: check if the type is date or datetime and show the current date value with htmlHelper.
                $type = $fieldLabels[$header]->type;

                // Dates need to be shown on local time.
                if($type == 'datetime' || $type == 'date'){
                    $displayDate =  HtmlHelper::_('date', $value, Text::_('DATE_FORMAT_LC6'));
                    $tableBody .= "<td style='text-wrap: wrap;' data-th='" . $label . "'>" . $displayDate . "</td>";
                    }
                else
                    $tableBody .= "<td style='text-wrap: wrap;' data-th='" . $label . "'>" . $value . "</td>";
		    }
		    $tableBody .= "</tr>";
		}

        $html = <<<HTML
			<div class='table-responsive table-mobile-responsive'>
			    <table class='table table-striped table-bordered'>
			        <thead>
			            <tr>
			                $tableHeadings
			            </tr>
			        </thead>
			        <tbody>
			            $tableBody
			        </tbody>
			    </table>
			</div>
			HTML;

        return $html;
    }

    /**
     *  Called right before an order is deleted from the database.
     *  @param $orderID int the id of the order being deleted.
     *  @return void
     */
    public function onAdminOrderDelete($orderID){

        return true;
    }

    public function onOrderBeforeSave(&$cart){
        return;
    }

    public function onOrderAfterSave($orderData){
        return;
    }

    /**
     * @param $productData object holds the data of the current product.
     * @param $method object holds the data of the payment method it was called from.
     * @return void
     */
    public function onProductView($productData, $method){
        $html = 
			<<<HTML
				<div>
					<h5>{$method->name}</h5>
					<p>{$method->description}</p>
				</div>
			HTML;

        return $html;
    }

    /**
     *  Gets called on the cart's view.
     *
     *  @param $order object cart object containing information about the order.
     *
     *  @return string the html output of the event.
     */
	public function onCartView($cart) : string {
		return '';
	}

    /*
     *  Inputs: An alfa-commerce order object.
     *          (#)
     *  Returns: The html of a layout to be displayed as html on proccess order view.
     */
    public function onOrderProcessView($order) : string {
        return '';
    }

    /*
     *  Inputs: An alfa-commerce order object.
     *          (#)
     *  Returns: The html of a layout to be displayed as html on complete order view.
     */
	public function onOrderCompleteView($order) : string {
        return '';
	}


	public function createEmptyLog(){
        $logsTableArrayToReturn = [
        		'id'=>'',
        		'order_id'=>'',
        		'status'=>'',
		];


        //If table exists, we log our data.
		//Import XML fields.
		$app    = $this->getApplication();
		$db     = $this->getDatabase();
		$plugin_name = $this->_name;

		$pluginGroup = "alfa-payments";

		$pluginDir = JPATH_PLUGINS . '/' . $pluginGroup;
		$pluginName = $plugin_name;
		$formFile = $pluginDir . '/' . $pluginName . '/params/logs.xml';
  
                    
    // foreach($xml->dplogs->fields->fieldset->field as $curr_field){
    //     $fieldName = strval($curr_field['name']);
    //     $fieldDefault = 'NULL';
    //     if(isset($curr_field['default'])){$fieldDefault=strval($curr_field['default']);}

    //     $logsTableArrayToReturn[$fieldName]=$fieldDefault;



        if (file_exists($formFile)) {
			$xml = simplexml_load_file($formFile);

//            exit;

			if(isset($xml->fields->fieldset->field)){

				foreach($xml->fields->fieldset->field as $curr_field){
					$fieldName = strval($curr_field['name']);

					if(strval($curr_field['type'])=='integer'){$fieldType='int(11)';}
					//if mysql_type is setted in dpconfig and in field as attribute mysql then listen to this
					if(isset($curr_field['mysql_type'])){$fieldType=strval($curr_field['mysql_type']);}
					// TODO CHECK IF ATTRIBUTE IS FINE STRUCTURED like mysql_type default etc

					$fieldDefault = (isset($curr_field['default'])?strval($curr_field['default']):'NULL');
					// $fieldDefaultSql = ($fieldDefault=='NULL'?'DEFAULT NULL':'NOT NULL DEFAULT '. $db->quote($fieldDefault));


					$logsTableArrayToReturn[$fieldName]=$fieldDefault;

				}

			}else{
				$app->enqueueMessage("No right structure in $formFile", 'warning');
				return [];
			}

		} else {
			$app->enqueueMessage("Form file ( $formFile ) does not exist for plugin: $pluginName", 'info');
			return [];
		}


        return $logsTableArrayToReturn;
    
	}

	/*
	 *  Inputs: - Checks if a log table exists in database.
	 *              - Tries to create one if there isn't.
	 *          - Imports logs.xml (the file that contains the plugin's logging form)
	 *          - Inserts order data into the table.
	 *
	 *  If $data['id'] is passed it will update this row
	 *
	 *  Returns: True if data was submitted successfully, false if not.
	 */
	protected function insertLog($data): bool{

        // Function cannot handle objects.
        if(is_object($data))
            $data = (array)$data; //json_decode(json_encode($data), true); // for nested also


		//If table exists, we log our data.
		//Import XML fields.
		$app    = $this->getApplication();
		$db     = $this->getDatabase();
		$plugin_name = $this->_name;

		foreach ($this->mustHaveColumns as $column) {
			if(!isset($data[$column['name']])){
				$app->enqueueMessage("insertLog : {$column['name']} is missing from \$data", 'warning');
				return false;
			}
		}

		$pluginGroup = "alfa-payments";

		$pluginDir = JPATH_PLUGINS . '/' . $pluginGroup;
		$pluginName = $plugin_name;
		$formFile = $pluginDir . '/' . $pluginName . '/params/logs.xml';

		if (file_exists($formFile)) {
			$xml = simplexml_load_file($formFile);
			if(isset($xml->fields->fieldset->field)){

				// CREATE PLUGINS TABLE IF NOT EXIST
				// $tableName = "#__deliveryplus_".$pluginGroup."_".$pluginName;
				$tableName = self::getLogsTableName();

				$insertColumns = array();
				$insertValues = array();

				if(isset($data['id']) && !empty($data['id'])){// for replace into to update the values if id is setted
					array_push($insertColumns,'id');
					array_push($insertValues,$db->quote($data['id']));
				}

				// epishs to query auto parousiazetai sto settings model
				$create_query = $db->getQuery(true);
				$create_query = "CREATE TABLE IF NOT EXISTS " . $db->quoteName($tableName) . " (";

				$create_query .= "`id` int(11) AUTO_INCREMENT PRIMARY KEY NOT NULL";

				foreach ($this->mustHaveColumns as $index => $column) {
					//	push must have columns from our local array to the create query
					$create_query .= ", `" . $column['name'] . "` " . $column['mysql_type'] . " " . $column['default'];

					array_push($insertColumns,$column['name']);
					array_push($insertValues,$db->quote($data[$column['name']]));
				}

				foreach($xml->fields->fieldset->field as $curr_field){
					$fieldName = strval($curr_field['name']);
					$fieldType = 'varchar(255)';

					if(strval($curr_field['type'])=='integer'){$fieldType='int(11)';}
					//if mysql_type is setted in dpconfig and in field as attribute mysql then listen to this
					if(isset($curr_field['mysql_type'])){$fieldType=strval($curr_field['mysql_type']);}
					// TODO CHECK IF ATTRIBUTE IS FINE STRUCTURED like mysql_type default etc

					$fieldDefault = (isset($curr_field['default'])?strval($curr_field['default']):'NULL');
					$fieldDefaultSql = ($fieldDefault=='NULL'?'DEFAULT NULL':'NOT NULL DEFAULT '. $db->quote($fieldDefault));

					$create_query .= ",`".$fieldName."` ".$fieldType." ".$fieldDefaultSql;

					//	push other columns from logs.xml file
					if(isset($data[$fieldName])){//is this data passed then insert it or update it
						array_push($insertColumns,$fieldName);

						// if(str_contains($data[$fieldName], '()')){// do not add quotes on mysql functions cause it doesnt work
						if(str_ends_with($data[$fieldName], '()')){// do not add quotes on mysql functions cause it doesnt work
							array_push($insertValues,$data[$fieldName]);
						}else{
							array_push($insertValues,$db->quote($data[$fieldName]));
						}
					}

				}
				$create_query .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8";

				$db->setQuery($create_query);
				$db->execute();
				// Factory::getApplication()->enqueueMessage("$tableName created successfully", "notice");

				// Create a new query object.
				$insert_query = $db->getQuery(true);

				$insert_query = "REPLACE INTO".$db->quoteName($tableName);
				$insert_query .= "(".implode(',', $insertColumns).")";
				$insert_query .= "VALUES(".implode(',', $insertValues).")";

				try{
					$db->setQuery($insert_query);
					$db->execute();
				}catch(\Exception $e){
					$app->enqueueMessage('('.$e->getCode().') ' . $e->getMessage() . '! Logs data did not inserted!','error');

					return false;
				}
				// Factory::getApplication()->enqueueMessage("$tableName values inserted successfully", "notice");
			}else{
				$app->enqueueMessage("No right structure in $formFile", 'warning');
				return false;
			}

		} else {
			$app->enqueueMessage("Form file ( $formFile ) does not exist for plugin: $pluginName", 'info');
			return false;
		}

		//Data inserted.
		return true;

	}


	// isOrderId = true means the orderCode is the order_id
	public function loadLogData($orderId,$asArray=true) {

		$order_result = null;

		$db = $this->getDatabase();
		$query = $db->getQuery(true);
		$query
			->select("*")
			->from($db->quoteName(self::getLogsTableName()));

		$query->where($db->quoteName('order_id') . ' = ' . $db->quote($orderId));
        $query->order('id DESC');

        try {

			$db->setQuery($query);

			if($asArray){
				$order_result = $db->loadAssocList();
			}else{
				$order_result = $db->loadObjectList();
			}
		} catch (\Exception $e) {

			if($e->getCode() == '1146'){ //table is missing error

			}
 
	    }


		return $order_result;



	}


	/**
	 * @param $prefix
	 *               0 no prefix
	 *               1 real prefix
	 *               2 #__ as prefix
	 *
	 * @return string the table name
	 *
	 * @since version
	 */
	protected function getLogsTableName($prefix=2) : string{

		$tableNameNoPrefix = str_replace("-","_",$this->_type)."_" . $this->_name.'_logs';

		$returnedTableString = '#__'.$tableNameNoPrefix;

		if($prefix==0){$returnedTableString = $tableNameNoPrefix;}
		elseif($prefix==1){ $returnedTableString = $this->db->getPrefix().$tableNameNoPrefix;}

		return $returnedTableString;
	}

	/**
	 * Returns the internal application or null when not set.
	 *
	 *
	 * @since   4.2.0
	 */
	protected function getDatabase()
	{
		return $this->database;
	}

	/**
	 * Returns the internal application or null when not set.
	 *
	 *
	 * @since   4.2.0
	 */
	protected function getLayoutPath()
	{
		return $this->layoutPath;
	}

    protected function deleteLogEntry($order_id){

        $tableName = self::getLogsTableName();
        $db = self::getDatabase();

        $query = $db->getQuery(true);
        $query
            ->delete($db->quoteName($tableName))
            ->where("order_id = " . $order_id);
        $db->setQuery($query);
        $db->execute();

    }

    /**
     *  Check if given data is valid.
     *  Attempts to insert them in the database.
     *  @param $paymentData object holds the payment data to be inserted.
     *  @return bool True if insertion was successful, false if not.
     */

    // $orderId = $this->app->getUserState('com_alfa.order_id');

    protected function insertOrderPayment($data):bool {

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
         $paymentObject->date_add        = !empty($data['date_add']) ? Factory::getDate($data['date_add'])->toSql() : NULL;

        $errorMessage = "Insufficient data to insert a payment.";
        if(!$paymentObject){
            $this->app->enqueueMessage($errorMessage, "error");
            return false;
        }

        $db = self::getDatabase();
        $db->insertObject('#__alfa_order_payments', $paymentObject);

        return true;

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

}
