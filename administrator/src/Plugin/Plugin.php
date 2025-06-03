<?php

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;


abstract class Plugin extends CMSPlugin implements SubscriberInterface
{

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.7.0
     */
    protected $autoloadLanguage = true;

    /**
     * Application object.
     *
     * @var    \Joomla\CMS\Application\CMSApplication
     * @since  4.0.0
     */
    protected $app;

    public static function getSubscribedEvents(): array
    {
        return [
            'onItemView' => 'onItemView',
        ];
    }

    private $database;

    protected $mustHaveColumns = [
        ['name'=>'id_order','mysql_type' => 'int(11)', 'default' => 'NULL'],
        ['name'=>'id_order_shipment','mysql_type' => 'int(11)', 'default' => 'NULL'],
    ];

    private $pluginPath;
    private $mediaPath;

    public function __construct(DispatcherInterface $dispatcher, array $config = [])
    {
        parent::__construct($dispatcher, $config);
        $this->database = Factory::getContainer()->get('DatabaseDriver');
        $this->mediaPath = JPATH_ROOT . '/media/plg_' . $this->_type . '_' . $this->_name;
        $this->pluginPath = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name;

        $this->registerListeners();//register listeners dynamically
        // $this->getDispatcher()->addSubscriber($this);

    }
    
    public function onAdminOrderBeforeSave($event) {
        $event->setCanSave(true);
    }

    public function onAdminOrderAfterSave($event)
    {

    }

//    public function onAdminOrderPrepareForm($event)
//    {
//        $order = $event->getData();
//        $form = $event->getForm();
//
//        $event->setData($order);
//        $event->setForm($form);
//    }

//    public function onAdminOrderViewLogs($event) {
//
//        $order = $event->getOrder();
//
//        // load logs from xml
//        $formFile = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name.'/params/logs.xml';
//        if (!file_exists($formFile)) return;
//
//        $xml = simplexml_load_file($formFile);
//
//        // Get logs data from db
//        $logData = self::loadLogData($order->id,false);
//
//		$event->setLayoutPluginName($this->_name);
//		$event->setLayoutPluginType($this->_type);
//	    $event->setLayout('default_order_logs_view');
//	    $event->setLayoutData(
//		    [
//                "logData" => $logData,
//                "xml" => $xml
//            ]
//	    );
//
//    }


//    public function onAdminOrderView($event) {
//        $order = $event->getOrder();
//        $event->setOrder($order);
//
//	    $event->setLayoutPluginName($this->_name);
//	    $event->setLayoutPluginType($this->_type);
//	    $event->setLayout('default_order_view');
////	    $event->setLayoutData(
////		    [
////			    "logData" => $logData,
////			    "xml" => $xml
////		    ]
////	    );
//    }

    public function onAdminOrderDelete($event){
        $event->setCanDelete(true);
    }

    public function onOrderBeforePlace($event){
        return;
    }

    public function onOrderAfterPlace($event){
        $order = $event->getOrder();
    }

    /**
     * @param $productData object holds the data of the current product.
     * @param $method object holds the data of the payment method it was called from.
     * @return void
     */
	public function onItemView($event){
		$item = $event->getItem();
		$method = $event->getMethod();

		$layoutData = [
			'method' => $method,
			'item' => $item,
		];

		$event->setLayoutPluginName('standard'); //fallback to standard if never set
		$event->setLayout('default_product_view');
		$event->setLayoutData($layoutData);

	}

    public function onCartView($event) {
        $cart = $event->getCart();
        $method = $event->getMethod();

        $layoutData = [
            'method' => $method,
            'item' => $cart,
        ];

        $event->setLayoutPluginName($this->_name); //fallback to standard if never set
        $event->setLayout('default_cart_view');
        $event->setLayoutData($layoutData);
    }

    /*
     *  Inputs: An alfa-commerce order object.
     *          (#)
     *  Returns: The html of a layout to be displayed as html on proccess order view.
     */
    public function onOrderProcessView($event){
        $order = $event->getOrder();
        $method = $event->getMethod();

        $layoutData = [
            'method' => $method,
            'item' => $order,
        ];

        $event->setRedirectUrl("index.php?option=com_alfa&view=cart&layout=default_order_completed");
    }

    public function onOrderCompleteView($event) {
        $order = $event->getOrder();
        $method = $event->getMethod();

        $layoutData = [
            'method' => $method,
            'item' => $order,
        ];

        $event->setLayoutPluginName("standard"); //fallback to standard if never set
        $event->setLayout('default_payment_success');
        $event->setLayoutData($layoutData);
    }

    // Logging.
    public function createEmptyLog(){
        $logsTableArrayToReturn = [
            'id'=>'',
            // 'order_id'=>'',
            // 'status'=>'',
        ];

        foreach($this->mustHaveColumns as $column){//add must have columns
            $logsTableArrayToReturn[$column['name']] = '';
        }

        //If table exists, we log our data.
        //Import XML fields.
        $app    = $this->getApplication();
        $db     = $this->getDatabase();
        $plugin_name = $this->_name;

        $pluginGroup = $this->_type;

        $pluginDir = JPATH_PLUGINS . '/' . $pluginGroup;
        $pluginName = $plugin_name;
        $formFile = $pluginDir . '/' . $pluginName . '/params/logs.xml';


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


    /**
     *  - Checks if a log table exists in database.
     *  - Tries to create one if there isn't.
     *  - Imports logs.xml (the file that contains the plugin's logging form)
     *  - Inserts order data into the table.
     *  - If $data['id'] is passed it will update this row
     * @param $data object|array with the data to be inserted.
     * @return bool True if data was submitted successfully, false if not.
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

        $pluginGroup = $this->_type;

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
                        if(str_ends_with($data[$fieldName], '()')){// do not add quotes on mysql functions because it doesn't work
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

                    $app->enqueueMessage('('.$e->getCode().') ' . $e->getMessage() . '! Log data was not inserted!','error');
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


    // filter support

    // ['is_active' => '1']
    // ['status' => ['IN', ['pending', 'completed']]]
    // raw filter - ['__raw' => 'DATE(created_at) = CURDATE()']
    
    // isOrderId = true means the orderCode is the id_order
    public function loadLogData($orderId, $shipmentId = 0, $asArray=true , $filter = null) {

        $order_result = null;

        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query
            ->select("*")
            ->from($db->quoteName(self::getLogsTableName()));

        $query->where($db->quoteName('id_order') . ' = ' . $db->quote($orderId));

		if(!empty($shipmentId)){
			$query->where($db->quoteName('id_order_shipment') . ' = ' . $db->quote($shipmentId));
		}

        // filtering logic
        if (is_array($filter) && !empty($filter)) {
            foreach ($filter as $key => $condition) {
                // Allow raw SQL condition
                if ($key === '__raw' && is_string($condition)) {
                    $query->where($condition);
                    continue;
                }

                $field = $db->quoteName($key);

                // If advanced format: ['operator', value]
                if (is_array($condition) && count($condition) >= 2) {
                    [$operator, $value] = $condition;
                    $operator = strtoupper(trim($operator));

                    switch ($operator) {
                        case 'IN':
                            $escaped = array_map([$db, 'quote'], (array) $value);
                            $query->where("$field IN (" . implode(', ', $escaped) . ")");
                            // $query->whereIn($field, $value);
                            break;
                        case 'NOT IN':
                            $escaped = array_map([$db, 'quote'], (array) $value);
                            $query->where("$field NOT IN (" . implode(', ', $escaped) . ")");
                            break;
                        case '=':
                        case '!=':
                        case '<>':
                        case '<':
                        case '>':
                        case '<=':
                        case '>=':
                            $query->where("$field $operator " . $db->quote($value));
                            break;
                        case 'LIKE':
                            $query->where("$field LIKE " . $db->quote($value));
                            break;
                        default:
                            // unsupported operator - optionally log or skip
                            break;
                    }
                } else {
                    // Simple format: ['key' => 'value']
                    $query->where("$field = " . $db->quote($condition));
                }
            }
        }

        // $query->where($db->quoteName('id_order') . ' = ' . $db->quote($orderId));
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

    protected function deleteLogEntry($id_order,$id_order_shipment=0){

        $tableName = self::getLogsTableName();
        $db = self::getDatabase();

        $query = $db->getQuery(true);
        $query
            ->delete($db->quoteName($tableName))
            ->where("id_order = " . $id_order);
		if(!empty($id_order_shipment)){
			$query->where("id_order_shipment = " . $id_order_shipment);
		}
        $db->setQuery($query);
        $db->execute();

    }

    // Utility.
    protected function getDatabase()
    {
        return $this->database;
    }

    protected function getMediaPath()
    {
        return $this->mediaPath;
    }

    protected function getPluginPath()
    {
        return $this->pluginPath;
    }

    protected function isValueInRange($value, $min, $max) {

        // Normalize all to strings
        $valueStr = is_string($value) ? $value  : strval($value);
        $minStr   = is_string($min)   ? $min    : strval($min);
        $maxStr   = is_string($max)   ? $max    : strval($max);

        // If all are numeric, compare numerically (including floats)
        if (is_numeric($valueStr) && is_numeric($minStr) && is_numeric($maxStr)) {
            return $valueStr >= $minStr && $valueStr <= $maxStr;
        }

        // Otherwise, compare as normalized strings
        return strcmp($valueStr, $minStr) >= 0 && strcmp($valueStr, $maxStr) <= 0;
    }

    protected function pluginLayout($fileName){
        $path = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $fileName));
        return new FileLayout($fileName,$path);
    }

}
