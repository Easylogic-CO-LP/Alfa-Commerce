<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Model;
// No direct access.
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\MVC\Model\ListModel;
use \Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Layout\FileLayout;
use \Joomla\Database\ParameterType;
use \Joomla\Utilities\ArrayHelper;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\ProductHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;

/**
 * Methods supporting a list of Alfa records.
 *
 * @since  1.0.1
 */
class ItemsModel extends ListModel
{
    /**
     * Constructor.
     *
     * @param array $config An optional associative array of configuration settings.
     *
     * @see    JController
     * @since  1.0.1
     */
    public function __construct($config = array())
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = array(
                'state', 'a.state',
                'ordering', 'a.ordering',
//				'created_by', 'a.created_by',
//				'modified_by', 'a.modified_by',
                'name', 'a.name',
                'id', 'a.id',
                'short_desc', 'a.short_desc',
                'full_desc', 'a.full_desc',
                'sku', 'a.sku',
                'gtin', 'a.gtin',
                'mpn', 'a.mpn',
                'stock', 'a.stock',
                'stock_action', 'a.stock_action',
                'manage_stock', 'a.manage_stock',
                'alias', 'a.alias',
                'meta_title', 'a.meta_title',
                'meta_desc', 'a.meta_desc',
            );
        }

        parent::__construct($config);
    }


    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param string $ordering Elements order
     * @param string $direction Order direction
     *
     * @return  void
     *
     * @throws  Exception
     *
     * @since   1.0.1
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState('a.id', 'DESC');

        $app = Factory::getApplication();
        $list = $app->getUserState($this->context . '.list');

        $value = $app->getUserState($this->context . '.list.limit', $app->get('list_limit', 25));
        $list['limit'] = $value;

        $this->setState('list.limit', $value);

        $value = $app->input->get('limitstart', 0, 'uint');
        $this->setState('list.start', $value);

        $ordering = $this->getUserStateFromRequest($this->context . '.filter_order', 'filter_order', 'a.id');
        $direction = strtoupper($this->getUserStateFromRequest($this->context . '.filter_order_Dir', 'filter_order_Dir', 'DESC'));

        if (!empty($ordering) || !empty($direction)) {
            $list['fullordering'] = $ordering . ' ' . $direction;
        }

        $app->setUserState($this->context . '.list', $list);


        $context = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
        $this->setState('filter.search', $context);

        // Split context into component and optional section
        if (!empty($context)) {
            $parts = FieldsHelper::extract($context);

            if ($parts) {
                $this->setState('filter.component', $parts[0]);
                $this->setState('filter.section', $parts[1]);
            }
        }
    }

    /**
     * Build an SQL query to load the list data.
     *
     * @return  DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db = $this->getDbo();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState('list.select',
                'DISTINCT a.*,
								GROUP_CONCAT(cat.category_id ORDER BY cat.category_id ASC) AS category_ids,
								GROUP_CONCAT(man.manufacturer_id ORDER BY man.manufacturer_id ASC) AS manufacturer_ids,
                                GROUP_CONCAT(pr.id ORDER BY pr.id ASC) AS price_ids'
            )
        );

        $query->from('#__alfa_items AS a');


        // Join the `#__items_categories` table to get category IDs.
        $query->join('LEFT', '#__alfa_items_categories AS cat ON a.id = cat.item_id');
        $query->join('LEFT', '#__alfa_items_manufacturers AS man ON a.id = man.item_id');
        $query->join('LEFT', '#__alfa_items_prices AS pr ON a.id = pr.item_id');

        $query->where('a.state = 1');

        // if (!Factory::getApplication()->getIdentity()->authorise('core.edit', 'com_alfa'))
        // {
        // $query->where('a.state = 1');
        // }
        // else
        // {
        // 	$query->where('(a.state IN (0, 1))');
        // }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int)substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                $query->where('( a.name LIKE ' . $search . ' )');
            }
        }

        $category_filter = $this->getState('filter.category_id');
        // TODO: gia na mas deixnei ta proionta kathgoriwn kai upokathgoriwn tha prepei na einai sto category_filter to array olwn autwn
        if (!empty($category_filter)) {
            if (is_array($category_filter)) {
                // If category_filter is an array, join it as a comma-separated list for the query
                $query->whereIn('cat.category_id', $category_filter);
            } else {
                // If category_filter is a single value
                $query->where('cat.category_id = ' . (int) $category_filter);
            }
        }


        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');

        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        // Group by item ID to ensure GROUP_CONCAT works correctly.
        $query->group('a.id');

        return $query;
    }

    /**
     * Method to get an array of data items
     *
     * @return  mixed An array of data on success, false on failure.
     */


   public function getItems(){

        $items = parent::getItems();

        $allCategoryIds = $allManufacturerIds = $allPriceIds = [];

        foreach ($items as $item) {
            // Extract IDs from a comma-separated string, trimming whitespace and merge into a single array
            $allCategoryIds = array_merge($allCategoryIds, $this->extractIds($item->category_ids));
            $allManufacturerIds = array_merge($allManufacturerIds, $this->extractIds($item->manufacturer_ids));
        }

        // Get unique category and manufacturer IDs and map them to their names
        $categoriesMapping = $this->getRecordsByIds($allCategoryIds, '#__alfa_categories', ['name']);
        $manufacturersMapping = $this->getRecordsByIds($allManufacturerIds, '#__alfa_manufacturers', ['name']);

        // $pricesMapping = $this->getRecordsByIds($allPriceIds, '#__alfa_items_prices', ['value']);

        $quantity = 1;
        $userGroupId = 1;
        $currencyId = 1;

        $settings = AlfaHelper::getGeneralSettings();

        // Assign mapped names to items
        foreach ($items as $item) {
            // calculate price
            $priceCalculator = new PriceCalculator($item->id, $quantity, $userGroupId, $currencyId);
            $item->price = $priceCalculator->calculatePrice();

            // convert category ids to category names
            $item->categories = $this->mapIdsToNames($item->category_ids, $categoriesMapping);

            // convert manufacturer ids to category names
            $item->manufacturers = $this->mapIdsToNames($item->manufacturer_ids, $manufacturersMapping);

            //Setting correct stock action settings in case they are to be retrieved from general settings (global configuration).
            if($item->stock_action == -1) {
                $item->stock_action = $settings->get("stock_action");
                $item->stock_low_message = $settings->get("stock_low_message");
                $item->stock_zero_message = $settings->get("stock_zero_message");
            }

            if(empty($item->stock_low_message))
                $item->stock_low_message = $settings->get("stock_low_message");

            if(empty($item->stock_zero_message))
                $item->stock_zero_message = $settings->get("stock_zero_message");
            
        }


        return $items;
    }


    // e.g. $selectFields =['name', 'alias']
    public function getRecordsByIds($ids, $table, $selectFields = ['name'], $idFieldName = 'id')
    {
        // Check if $ids is empty or null, and return an empty array if so
        if (empty($ids) || !is_array($ids)) {
            return [];
        }

        $ids = array_unique($ids);//make sure ids array for where in are unique

        // Get the database connection and a query object
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Ensure that $idFieldName is included in the select fields
        if (!in_array($idFieldName, $selectFields)) {
            array_unshift($selectFields, $idFieldName); //adds before the 'id' field
        }

        // Build the select clause dynamically
        $selectClause = implode(', ', array_map([$db, 'quoteName'], $selectFields));

        $query->select($selectClause)
            ->from($db->quoteName($table))
            ->whereIn($idFieldName, $ids); // Assuming first field is always the ID

        $db->setQuery($query);
        $results = $db->loadAssocList($idFieldName); // Get associative array based on the ID

        return $results;
    }

    /**
     * Map a comma-separated string of IDs to their corresponding names
     * @param string $ids
     * @param array $mapping
     * @return array
     */
    private function mapIdsToNames($ids, $mapping)
    {
        $result = [];
        $idArray = $this->extractIds($ids);

        foreach ($idArray as $id) {
            // Always set the 'id' field
            $result[$id]['id'] = $id;

            // If the ID exists in the mapping, assign all mapped fields (e.g., name, alias)
            if (isset($mapping[$id])) {
                $result[$id] = array_merge(['id' => $id], $mapping[$id]);
            }
        }

        // print_r($result);

        return $result;
    }

    /**
     * Extract IDs from a comma-separated string, trimming whitespace
     * @param string $ids
     * @return array
     */
    private function extractIds($ids)
    {
        // Check if $ids is null or empty
        if (is_null($ids) || $ids === '') {
            return [];
        }

        return array_map('trim', explode(',', $ids));
    }


    /**
     * Overrides the default function to check Date fields format, identified by
     * "_dateformat" suffix, and erases the field if it's not correct.
     *
     * @return void
     */
    protected function loadFormData()
    {
        $app = Factory::getApplication();
        $filters = $app->getUserState($this->context . '.filter', array());
        $error_dateformat = false;

        foreach ($filters as $key => $value) {
            if (strpos($key, '_dateformat') && !empty($value) && $this->isValidDate($value) == null) {
                $filters[$key] = '';
                $error_dateformat = true;
            }
        }

        if ($error_dateformat) {
            $app->enqueueMessage(Text::_("COM_ALFA_SEARCH_FILTER_DATE_FORMAT"), "warning");
            $app->setUserState($this->context . '.filter', $filters);
        }

        return parent::loadFormData();
    }

    /**
     * Checks if a given date is valid and in a specified format (YYYY-MM-DD)
     *
     * @param string $date Date to be checked
     *
     * @return bool
     */
    private function isValidDate($date)
    {
        $date = str_replace('/', '-', $date);
        return (date_create($date)) ? Factory::getDate($date)->format("Y-m-d") : null;
    }
}
