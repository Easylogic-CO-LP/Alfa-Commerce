<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
//use \Joomla\CMS\Object\CMSObject;
use \Joomla\CMS\Access\Access;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Alfa helper.
 *
 * @since  1.0.1
 */
class AlfaHelper
{

	// ========================================================================
// DATA DIFFING
// ========================================================================

	/**
	 * Build a diff between two data snapshots (old vs new).
	 *
	 * Accepts any combination of objects and arrays for both $old and $new.
	 * Compares only the specified $trackFields. Normalises numeric values
	 * so database decimals ("0.000000") and form values ("0") don't
	 * produce false-positive changes.
	 *
	 * This is the SINGLE source of truth for change detection across the
	 * entire component. Used by:
	 *   - OrderModel::saveOrderItem() / logOrderChanges()
	 *   - OrderPaymentHelper::update()
	 *   - OrderShipmentHelper::update()
	 *   - Any future helper that needs before/after comparison
	 *
	 * Usage examples:
	 *   // Object vs array (most common — DB row vs form data)
	 *   $changes = AlfaHelper::buildDiff($dbRow, $formData, ['amount', 'status']);
	 *
	 *   // Array vs array
	 *   $changes = AlfaHelper::buildDiff($oldArray, $newArray, ['name', 'qty']);
	 *
	 *   // Object vs object
	 *   $changes = AlfaHelper::buildDiff($oldObj, $newObj, ['price']);
	 *
	 *   // Return as object instead of array
	 *   $changes = AlfaHelper::buildDiff($old, $new, $fields, 'object');
	 *
	 * @param   object|array  $old          Old snapshot (DB row, previous state)
	 * @param   object|array  $new          New snapshot (form data, updated state)
	 * @param   array         $trackFields  Field names to compare
	 * @param   string        $returnType   'array' (default) or 'object'
	 *
	 * @return  array|object  Changes: ['field' => ['from' => old, 'to' => new], ...]
	 *                        Empty array/object when nothing changed.
	 *
	 * @since   3.5.0
	 */
	public static function buildDiff(
		object|array $old,
		object|array $new,
		array $trackFields,
		string $returnType = 'array'
	): array|object {
		// Normalise inputs — work with arrays internally
		$oldData = is_object($old) ? get_object_vars($old) : $old;
		$newData = is_object($new) ? get_object_vars($new) : $new;

		$changes = [];

		foreach ($trackFields as $field) {
			$oldVal = $oldData[$field] ?? '';
			$newVal = $newData[$field] ?? '';

			// Numeric comparison — avoids "0.000000" vs "0" false diffs
			if (is_numeric($oldVal) && is_numeric($newVal)) {
				if ((float) $oldVal === (float) $newVal) {
					continue;
				}
			} elseif ((string) $oldVal === (string) $newVal) {
				// Non-numeric → string comparison
				continue;
			}

			$changes[$field] = ['from' => $oldVal, 'to' => $newVal];
		}

		return ($returnType === 'object') ? (object) $changes : $changes;
	}

	/**
	 * Build a nested array of anything with depth level ( should contain id and parent_id to work ).
	 *
	 * @param array $items E.g. The list of categories.
	 * @param int $parentId The parent ID to start building from. ( Begins with zero, so we don't set it )
	 * @param int $depth The current depth level ( Auto setted while recursing )
	 * @return array The nested array of items with depth level ( e.g. the fixed categories with children and depth attached)
	 */
	public static function buildNestedArray($items, $childrenField = 'children', $parentId = 0, $depth = 0)
	{
		$tree = array();
		foreach ($items as $item) {
			if ($item->parent_id == $parentId) {
				$id = $item->id;
				$item->depth = $depth; // Assign the current depth level
				$item->{$childrenField} = self::buildNestedArray($items, $id, $depth + 1);
				$tree[$id] = $item;
			}
		}
		return $tree;
	}

	public static function sort_items($object1, $object2, $property, $order = 'asc')
	{
		if (is_numeric($object1->$property) && is_numeric($object2->$property)) {
			$result = ($object1->$property == $object2->$property) ? 0 : (($object1->$property > $object2->$property) ? 1 : -1);
		} else {
			$result = strcasecmp($object1->$property, $object2->$property);
		}
		return (strtolower($order) == 'asc') ? $result : -$result;
	}

	public static function sort_nested_items(&$items, $property = 'name', $order = 'asc', $childrenField = 'children')
	{
		// Sort the current level of items
		usort($items, function ($option1, $option2) use ($property, $order) {
			return self::sort_items($option1, $option2, $property, $order);
		});

		// Recursively sort the children
		foreach ($items as &$item) {
			if (isset($item->{$childrenField}) && is_array($item->{$childrenField})) {
				self::sort_nested_items($item->{$childrenField}, $property, $order);
			}
		}
	}


	public static function flatten_nested_items($items, $pathField = 'name', $pathSeparator = '/', $childrenField = 'children', $parentPath = '')
	{
		$flatArray = [];

		foreach ($items as $item) {
			// Clone the item to avoid modifying the original structure
			$flattenedItem = clone $item;

			// If pathField is provided, build the path
			if ($pathField) {
				// If parentPath is empty, avoid adding a leading separator
				$currentPath = $parentPath ? $parentPath . $pathSeparator . $item->{$pathField} : $item->{$pathField};

				// Add the current path to the flattened item
				$flattenedItem->path = $currentPath;
			}

			// Remove the children property to avoid recursion issues
			unset($flattenedItem->{$childrenField});

			// Add the item without children to the flat array
			$flatArray[] = $flattenedItem;

			// If the item has children, recursively flatten them, passing the current path as the parent path
			if (isset($item->{$childrenField}) && is_array($item->{$childrenField})) {
				$flatArray = array_merge($flatArray, self::flatten_nested_items($item->{$childrenField}, $pathField, $pathSeparator, $childrenField, $currentPath));
			}
		}

		return $flatArray;
	}


	public static function addHierarchyData($items, $pathField = 'name', $pathSeparator = '/', $parentPath = '', $parentId = 0, $depth = 0)
	{
		$hierarchyData = array();
		foreach ($items as $item) {
			if ($item->parent_id == $parentId) {
				$item->depth = $depth; // Assign the current depth level

				if ($pathField && property_exists($item, $pathField)) {
					// If parentPath is empty, avoid adding a leading separator
					$currentPath = $parentPath ? $parentPath . $pathSeparator . $item->{$pathField} : $item->{$pathField};
					// Add the current path to the flattened item
					$item->path = $currentPath;
				} else {
					// Handle cases where the property does not exist or $pathField is invalid
					$item->path = $parentPath; // or set some default value
				}

				$hierarchyData[] = $item; // Add the item to the array
				// Recursively add next items to the array
				$hierarchyData = array_merge($hierarchyData, self::addHierarchyData($items, $pathField, $pathSeparator, $currentPath, $item->id, $depth + 1));

			}
		}
		return $hierarchyData;
	}


	/**
	 * Saves the assocs data to to the database.
	 *
	 * @param $fieldId integer id of the field
	 * @param $userGroupArray
	 * @param $table
	 * @param $field
	 * @return void
	 */
	public static function setAssocsToDb($mainFieldId, $data, $table, $mainField, $dataField, $assignZeroIdIfDataEmpty = false)
	{

		if (intval($mainFieldId) <= 0 || empty($table) || empty($mainFieldId) || empty($dataField)) {
			return false;
		}


		$db = Factory::getContainer()->get('DatabaseDriver');
		// save users per category on categories_users
		$query = $db->getQuery(true);
		$query->delete($db->quoteName($table))->where($db->quoteName($mainField) . ' = ' . $mainFieldId);
		$db->setQuery($query);
		$db->execute();

		if (empty($data) && $assignZeroIdIfDataEmpty) {
			$data[0] = 0;
		}

		if(is_array($data)){
			foreach ($data as $curr) {
				$query = $db->getQuery(true);
				$query->insert($db->quoteName($table))
					->set($db->quoteName($mainField) . ' = ' . $mainFieldId)
					->set($db->quoteName($dataField) . ' = ' . intval($curr));
				$db->setQuery($query);
				$db->execute();
			}
		}
	}

	/**
	 * Retrieves all the assocs data from the database. Reusable for all forms that have allowed user groups as a field.
	 *
	 * @param $fieldId
	 * @param $table
	 * @param $field
	 * @return mixed
	 */
	public static function getAssocsFromDb($mainFieldId, $table, $mainField, $dataField)
	{

		if (intval($mainFieldId) <= 0 || empty($table) || empty($mainField) || empty($dataField)) {
			return [];
		}

		// load selected categories for item
		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true);
		$query
			->select($db->quoteName($dataField))
			->from($db->quoteName($table))
			->where($db->quoteName($mainField) . ' = ' . intval($mainFieldId));

		$db->setQuery($query);

		return $db->loadColumn();
	}

	// ========================================================================
	// LOOKUP TABLES — Cached Static Methods
	//
	// Small reference tables (3-10 rows) loaded once per request.
	// Used everywhere: OrderModel, OrdersModel, views, helpers.
	//
	// Single source of truth — no more duplicate methods in models.
	// Static cache means: first call queries DB, all subsequent calls
	// return the cached result. Cache lives for the request only.
	//
	// All methods return arrays keyed by ID for O(1) lookup:
	//   $statuses[$id]->name, $statuses[$id]->color, etc.
	// ========================================================================

	/** @var array|null Cached order statuses keyed by ID */
	private static ?array $orderStatusesCache = null;

	/** @var array|null Cached payment methods keyed by ID */
	private static ?array $paymentMethodsCache = null;

	/** @var array|null Cached shipment methods keyed by ID */
	private static ?array $shipmentMethodsCache = null;

	/**
	 * Get all order statuses keyed by ID (cached).
	 *
	 * Returns ALL statuses (active + inactive) because:
	 *   - Existing orders may reference inactive statuses
	 *   - The edit view needs to show the current status even if disabled
	 *   - Filtering for active-only is done in the view (dropdown options)
	 *
	 * Each status has: id, name, color, bg_color, stock_operation, state, ordering
	 *
	 * Used by:
	 *   - OrdersModel::getItems() → attach names/colors to list rows
	 *   - Orders list view → status column display + dropdown
	 *   - OrderModel::handleStockOperation() → stock_operation lookup
	 *   - OrderModel::logOrderChanges() → status name enrichment
	 *
	 * @return  array  Statuses indexed by ID
	 *
	 * @since   4.1.0
	 */
	public static function getOrderStatuses(): array
	{
		if (self::$orderStatusesCache !== null) {
			return self::$orderStatusesCache;
		}

		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__alfa_orders_statuses'))
			->order('ordering ASC');

		$db->setQuery($query);

		self::$orderStatusesCache = $db->loadObjectList('id') ?: [];

		return self::$orderStatusesCache;
	}

	/**
	 * Get all active payment methods keyed by ID (cached).
	 *
	 * Each method has: id, name, color, bg_color
	 *
	 * Used by:
	 *   - OrdersModel::getItems() → attach names/colors to list rows
	 *   - Orders list view → payment column display
	 *
	 * @return  array  Payment methods indexed by ID
	 *
	 * @since   4.1.0
	 */
	public static function getPaymentMethods(): array
	{
		if (self::$paymentMethodsCache !== null) {
			return self::$paymentMethodsCache;
		}

		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select('id, name, color, bg_color')
			->from($db->quoteName('#__alfa_payments'))
			->where($db->quoteName('state') . ' = 1')
			->order('name ASC');

		$db->setQuery($query);

		self::$paymentMethodsCache = $db->loadObjectList('id') ?: [];

		return self::$paymentMethodsCache;
	}

	/**
	 * Get all active shipment methods keyed by ID (cached).
	 *
	 * Each method has: id, name, color, bg_color
	 *
	 * Used by:
	 *   - OrdersModel::getItems() → attach names/colors to list rows
	 *   - Orders list view → shipment column display
	 *
	 * @return  array  Shipment methods indexed by ID
	 *
	 * @since   4.1.0
	 */
	public static function getShipmentMethods(): array
	{
		if (self::$shipmentMethodsCache !== null) {
			return self::$shipmentMethodsCache;
		}

		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select('id, name, color, bg_color')
			->from($db->quoteName('#__alfa_shipments'))
			->where($db->quoteName('state') . ' = 1')
			->order('name ASC');

		$db->setQuery($query);

		self::$shipmentMethodsCache = $db->loadObjectList('id') ?: [];

		return self::$shipmentMethodsCache;
	}





	//    FUNCTION TO BE DELETED
	/* public static function iterateNestedArray($tree, $callback, $fullPath = false, $parentNames = '')
	   {
		   foreach ($tree as $node) {
			   // Build the full path or hierarchical representation of category names based on the format
			   if ($fullPath) {
				   $currentPath = empty($parentNames) ? $node->name : $parentNames . ' / ' . $node->name;
			   } else {
				   $currentPath = str_repeat('- ', $node->depth) . $node->name;
			   }

			   // Apply the callback function to the current node with the full path or hierarchical representation
			   $callback($node, $currentPath);

			   // If the node has children, recursively iterate through them
			   if (!empty($node->children)) {
				   self::iterateNestedArray($node->children, $callback, $fullPath, $currentPath);
			   }
		   }
	   }*/

	/**
	 * Gets the files attached to an item
	 *
	 * @param int $pk The item's id
	 *
	 * @param string $table The table's name
	 *
	 * @param string $field The field's name
	 *
	 * @return  array  The files
	 */
	public static function getFiles($pk, $table, $field)
	{
		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true);

		$query
			->select($field)
			->from($table)
			->where('id = ' . (int)$pk);

		$db->setQuery($query);

		return explode(',', $db->loadResult());
	}


	/**
	 * Loads the fields plugins and returns an array of field types from the plugins.
	 *
	 * The returned array contains arrays with the following keys:
	 * - label: The label of the field
	 * - type:  The type of the field
	 * - path:  The path of the folder where the field can be found
	 *
	 * @return  array
	 *
	 * @since   3.7.0
	 */
	public static function getFieldTypes($type)
	{
		$plugin_types = [];

		$pluginGroup = 'alfa-'.$type;

		$plugins = PluginHelper::getPlugin($pluginGroup);// Get a list of all plugins in the specified group

		foreach ($plugins as $plugin) {// Process each shipment payment group plugin.
			$plugin_types[] =
				[
					'id' => $plugin->id,
					'name' => $plugin->name,
					'params' => $plugin->params
				];
		}
		return $plugin_types;
	}

	public static function addPluginForm(&$form ,$data , $item , $type){
		$app = Factory::getApplication();

		$pluginGroup = 'alfa-'.$type; //alfa-payments , alfa-shipments etc...
		$pluginName = $data['type'] ?? $form->getValue('type'); // $data when we save but when we open the form we get it from getValue

		// New entry - Auto select the default value for first time
		if(empty($pluginName)) {
			if ($type == "fields")   // Fields plugin type, use text as default.
				$pluginName = "text";
			else if ($type == "shipments" || $type == "payments")    // Shipments/Payments plugin type, use standard as default.
				$pluginName = "standard";
		}

		$paramsFile = JPATH_ROOT . '/plugins/'.$pluginGroup.'/'.$pluginName.'/params/params.xml';
		$paramsFile2 = JPATH_ROOT . '/plugins/'.$pluginGroup.'/'.$pluginName.'/params/'.$pluginName.'.xml';

		$app->getLanguage()->load('plg_'.$pluginGroup.'_'.$pluginName);

		if (file_exists($paramsFile)) {
			// Load the XML file into the form.
			$form->loadFile($paramsFile, false);
		}else if (file_exists($paramsFile2)) {
			// Load the XML file into the form.
			$form->loadFile($paramsFile2, false);
		}

//        echo "<pre>";
//
//        echo $paramsFile;
//        echo "<br>";
//        echo $paramsFile2;
//        print_r(file_get_contents($paramsFile2));
//
//        exit;

		//set the plugin data

		$form_params = $item->params; //params column from db

		$fieldSetName = $type.'params'; //paymentsparams , shipmentsparams etc...

		foreach($form_params as $index=>$param){
			$form->setValue($index, $fieldSetName, $param);
		}
	}


	/**
	 * Triggers a specified plugin function with proper validation and error handling
	 *
	 * @return mixed The result of the plugin function or false on failure
	 * @throws \Exception When required parameters are missing or plugin fails to load
	 */
	// public static function triggerPlugin($type='',$name='',$func='')
	// {
	//     $app = Factory::getApplication();
	//     $input = $app->input;

	//     $response_error = false;
	//     $response_data = null;
	//     $response_message = '';

	//     try {
	//         // Get and validate required parameters
	//         $requiredParams = [
	//             'type' => !empty($type)? $type : $input->getString('type', ''),
	//             'name' => !empty($name)? $name : $input->getString('name', ''),
	//             'func' => !empty($func)? $func : $input->getString('func', ''),
	//         ];

	//         // Check for empty required parameters
	//         $missingParams = array_filter($requiredParams, function($value) {
	//             return empty($value);
	//         });

	//         if (!empty($missingParams)) {
	//             throw new \Exception(
	//                 'Missing required parameters: ' . implode(', ', array_keys($missingParams)),
	//                 400
	//             );
	//         }

	//         // Boot the plugin
	//         $plugin = $app->bootPlugin($requiredParams['name'], $requiredParams['type']);
	//         // var_dump($plugin); exit;
	//         // if (!$plugin) {
	//         //     throw new \Exception(
	//         //         sprintf('Plugin %s of type %s not found', $requiredParams['name'], $requiredParams['type']),
	//         //         404
	//         //     );
	//         // }

	//         // Check if the method exists and is callable
	//         if (!method_exists($plugin, $requiredParams['func']) || !is_callable([$plugin, $requiredParams['func']])) {
	//             throw new \Exception(
	//                 "Plugin type {$requiredParams['type']}, name {$requiredParams['name']} or Method {$requiredParams['func']} not found or method not callable in plugin",
	//                 405
	//             );
	//         }

	//         // Call the plugin function
	//         $response_data = $plugin->{$requiredParams['func']}();

	//     } catch (\Exception $e) {
	//         // Log the error
	//         // $app->getLogger()->error($e->getMessage(), ['trace' => $e->getTrace()]);

	//         $app->enqueueMessage($e->getMessage(), 'error');
	//         $response_error = true;

	//     }

	//     $response = new \Joomla\CMS\Response\JsonResponse($response_data, $response_message, $response_error);
	//     return $response;
	// }


	/*
		public static function addXMLToForm($xmlPath, &$form, $fieldGroup, $fieldValues = null){

			if (file_exists($xmlPath))
				$form->loadFile($xmlPath, false);
			else
				return false;

			if(empty($fieldValues))
				return true;

			foreach($fieldValues as $index => $value){
				$form->setValue($index, $fieldGroup, $value);
			}

			return true;
		}
	*/




}