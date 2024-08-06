<?php

/**
 * @version     CVS: 1.0.1
 * @package     com_alfa
 * @subpackage  mod_alfa
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2024 Easylogic CO LP
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Module\Alfa\Site\Helper;

\defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Language\Language;
use \Joomla\CMS\User\UserFactoryInterface;

/**
 * Helper for mod_alfa
 *
 * @package     com_alfa
 * @subpackage  mod_alfa
 * @since       1.0.1
 */
Class AlfaHelper
{
	/**
	 * Retrieve component items
	 *
	 * @param   Joomla\Registry\Registry &$params module parameters
	 *
	 * @return array Array with all the elements
	 *
	 * @throws Exception
	 */
	public static function getList(&$params)
	{
		$app   = Factory::getApplication();
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true);

		$tableField = explode(':', $params->get('field'));
		$table_name = !empty($tableField[0]) ? $tableField[0] : '';

		/* @var $params Joomla\Registry\Registry */
		$query
			->select('*')
			->from($table_name)
			->where('state = 1');

		$db->setQuery($query, $app->input->getInt('offset', (int) $params->get('offset')), $app->input->getInt('limit', (int) $params->get('limit')));
		$rows = $db->loadObjectList();

		return $rows;
	}

	/**
	 * Retrieve component items
	 *
	 * @param   Joomla\Registry\Registry &$params module parameters
	 *
	 * @return mixed stdClass object if the item was found, null otherwise
	 */
	public static function getItem(&$params)
	{
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true);

		/* @var $params Joomla\Registry\Registry */
		$query
			->select('*')
			->from($params->get('item_table'))
			->where('id = ' . intval($params->get('item_id')));

		$db->setQuery($query);
		$element = $db->loadObject();

		return $element;
	}

	/**
	 * Render element
	 *
	 * @param   Joomla\Registry\Registry $table_name  Table name
	 * @param   string                   $field_name  Field name
	 * @param   string                   $field_value Field value
	 *
	 * @return string
	 */
	public static function renderElement($table_name, $field_name, $field_value)
	{
		$result = '';
		
		if(strpos($field_name, ':'))
		{
			$tableField = explode(':', $field_name);
			$table_name = !empty($tableField[0]) ? $tableField[0] : '';
			$field_name = !empty($tableField[1]) ? $tableField[1] : '';
		}
		
		switch ($table_name)
		{
			
		case '#__alfa_manufacturers':
		switch($field_name){
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'id':
		$result = $field_value;
		break;
		case 'alias':
		$result = $field_value;
		break;
		case 'desc':
		$result = $field_value;
		break;
		case 'meta_title':
		$result = $field_value;
		break;
		case 'meta_desc':
		$result = $field_value;
		break;
		case 'website':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_categories':
		switch($field_name){
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'parent_id':
		$result = $field_value;
		break;
		case 'id':
		$result = $field_value;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'alias':
		$result = $field_value;
		break;
		case 'meta_title':
		$result = $field_value;
		break;
		case 'meta_desc':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_items':
		switch($field_name){
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'id':
		$result = $field_value;
		break;
		case 'short_desc':
		$result = $field_value;
		break;
		case 'full_desc':
		$result = $field_value;
		break;
		case 'sku':
		$result = $field_value;
		break;
		case 'gtin':
		$result = $field_value;
		break;
		case 'mpn':
		$result = $field_value;
		break;
		case 'stock':
		$result = $field_value;
		break;
		case 'stock_action':
		$result = $field_value;
		break;
		case 'manage_stock':
		$result = $field_value;
		break;
		case 'alias':
		$result = $field_value;
		break;
		case 'meta_title':
		$result = $field_value;
		break;
		case 'meta_desc':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_items_prices':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'value':
		$result = $field_value;
		break;
		case 'override':
		$result = $field_value;
		break;
		case 'start_date':
		$result = $field_value;
		break;
		case 'quantity_start':
		$result = $field_value;
		break;
		case 'end_date':
		$result = $field_value;
		break;
		case 'quantity_end':
		$result = $field_value;
		break;
		case 'tax_id':
		$result = $field_value;
		break;
		case 'discount_id':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_items_manufacturers':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'product_id':
		$result = $field_value;
		break;
		case 'manufacturer_id':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_items_categories':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'product_id':
		$result = $field_value;
		break;
		case 'manufacturer_id':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_users':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		}
		break;
		case '#__alfa_usergroups':
		switch($field_name){
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'id':
		$result = $field_value;
		break;
		case 'prices_display':
		$result = $field_value;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'prices_enable':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_customs':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'type':
		$result = $field_value;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'desc':
		$result = $field_value;
		break;
		case 'required':
		$result = $field_value;
		break;
		case 'categories':
		$result = self::loadValueFromExternalTable('#__alfa_categories', 'id', 'name', $field_value);
		break;
		case 'items':
		$result = self::loadValueFromExternalTable('#__alfa_items', 'id', 'name', $field_value);
		break;
		}
		break;
		case '#__alfa_currencies':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'code':
		$result = $field_value;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'symbol':
		$result = $field_value;
		break;
		case 'number':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_coupons':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'coupon_code':
		$result = $field_value;
		break;
		case 'num_of_uses':
		$result = $field_value;
		break;
		case 'value_type':
		$result = $field_value;
		break;
		case 'value':
		$result = $field_value;
		break;
		case 'min_value':
		$result = $field_value;
		break;
		case 'max_value':
		$result = $field_value;
		break;
		case 'hidden':
		$result = $field_value;
		break;
		case 'start_date':
		$result = $field_value;
		break;
		case 'end_date':
		$result = $field_value;
		break;
		case 'associate_to_new_users':
		$result = $field_value;
		break;
		case 'user_associated':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_shipments':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'name':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_payments':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'name':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_places':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'name':
		$result = $field_value;
		break;
		case 'number':
		$result = $field_value;
		break;
		case 'parent_id':
		$result = $field_value;
		break;
		case 'code2':
		$result = $field_value;
		break;
		case 'code3':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_settings':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'currency':
		$result = self::loadValueFromExternalTable('#__alfa_currencies', 'id', 'name', $field_value);
		break;
		case 'currency_display':
		$result = $field_value;
		break;
		case 'terms_accept':
		$result = $field_value;
		break;
		case 'allow_guests':
		$result = $field_value;
		break;
		case 'manage_stock':
		$result = $field_value;
		break;
		case 'stock_action':
		$result = $field_value;
		break;
		}
		break;
		case '#__alfa_orders':
		switch($field_name){
		case 'id':
		$result = $field_value;
		break;
		case 'created_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'modified_by':
		$container = \Joomla\CMS\Factory::getContainer();
		$userFactory = $container->get(UserFactoryInterface::class);
		$user = $userFactory->loadUserById($field_value);
		$result = $user->name;
		break;
		case 'currency':
		$result = $field_value;
		break;
		case 'payment':
		$result = $field_value;
		break;
		case 'total':
		$result = $field_value;
		break;
		}
		break;
		}

		return $result;
	}

	/**
	 * Returns the translatable name of the element
	 *
	 * @param   string .................. $table_name table name
	 * @param   string                   $field   Field name
	 *
	 * @return string Translatable name.
	 */
	public static function renderTranslatableHeader($table_name, $field)
	{
		return Text::_(
			'MOD_ALFA_HEADER_FIELD_' . str_replace('#__', '', strtoupper($table_name)) . '_' . strtoupper($field)
		);
	}

	/**
	 * Checks if an element should appear in the table/item view
	 *
	 * @param   string $field name of the field
	 *
	 * @return boolean True if it should appear, false otherwise
	 */
	public static function shouldAppear($field)
	{
		$noHeaderFields = array('checked_out_time', 'checked_out', 'ordering', 'state');

		return !in_array($field, $noHeaderFields);
	}

	

    /**
     * Method to get a value from a external table
     * @param string $source_table Source table name
     * @param string $key_field Source key field 
     * @param string $value_field Source value field
     * @param mixed  $key_value Value for the key field
     * @return mixed The value in the external table or null if it wasn't found
     */
    private static function loadValueFromExternalTable($source_table, $key_field, $value_field, $key_value) {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
                ->select($db->quoteName($value_field))
                ->from($source_table)
                ->where($db->quoteName($key_field) . ' = ' . $db->quote($key_value));


        $db->setQuery($query);
        return $db->loadResult();
    }
}
