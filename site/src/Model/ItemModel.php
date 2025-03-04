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
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\MVC\Model\ItemModel as BaseItemModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Object\CMSObject;
use \Joomla\CMS\User\UserFactoryInterface;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use \Joomla\Database\ParameterType;

/**
 * Alfa model.
 *
 * @since  1.0.1
 */
class ItemModel extends BaseItemModel
{
	/**
	 * Model context string.
	 *
	 * @var        string
	 */
	protected $_context = 'com_alfa.item';

    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @return  void
     *
     * @throws Exception
     * @since   1.0.1
     *
     */
    protected function populateState()
    {
        $app = Factory::getApplication('com_alfa');
        $user = $app->getIdentity();

        // Check published state
        if ((!$user->authorise('core.edit.state', 'com_alfa')) && (!$user->authorise('core.edit', 'com_alfa'))) {
            $this->setState('filter.published', 1);
            $this->setState('filter.archived', 2);
        }

        // Load state from the request userState on edit or from the passed variable on default
        if (Factory::getApplication()->input->get('layout') == 'edit') {
            $id = Factory::getApplication()->getUserState('com_alfa.edit.item.id');
        } else {
            $id = Factory::getApplication()->input->get('id');
            Factory::getApplication()->setUserState('com_alfa.edit.item.id', $id);
        }

        $this->setState('item.id', $id);

        // Load the parameters.
        $params = $app->getParams();
        $params_array = $params->toArray();

        if (isset($params_array['item_id'])) {
            $this->setState('item.id', $params_array['item_id']);
        }

        $this->setState('params', $params);

    }

    /**
     * Method to get an object.
     *
     * @param integer $id The id of the object to get.
     *
     * @return  mixed    Object on success, false on failure.
     *
     * @throws Exception
     */
    public function getItem($pk = null)
    {
        $user = $this->getCurrentUser();
//      $user->id
//		$user->groups erxetai ws array [1,2,3]

        $pk = (int)($pk ?: $this->getState('item.id'));

        if ($this->_item === null) {
            $this->_item = [];
        }

        if (!isset($this->_item[$pk])) {
            try {
                $db = $this->getDatabase();
                $query = $db->getQuery(true);
                $query->select(
                    $this->getState(
                        'item.select',
                        [
                            // from items
                            'a.*',
                        ]
                    )
                )
                    ->from($db->quoteName('#__alfa_items', 'a'))
                    ->where(
                        [
                            $db->quoteName('a.id') . ' = :pk',
                            // $db->quoteName('c.published') . ' > 0',
                        ]
                    )
                    ->bind(':pk', $pk, ParameterType::INTEGER);
                $db->setQuery($query);
                $data = $db->loadObject();


                //Setting correct stock action settings in case they are to be retrieved from general settings (global configuration).
                $settings = AlfaHelper::getGeneralSettings();
                if($data->stock_action == -1) {
                    $data->stock_action = $settings->get("stock_action");
                    $data->stock_low_message = $settings->get("stock_low_message");
                    $data->stock_zero_message = $settings->get("stock_zero_message");
                }

                if(empty($data->stock_low_message))
                    $data->stock_low_message = $settings->get("stock_low_message");

                if(empty($data->stock_zero_message))
                    $data->stock_zero_message = $settings->get("stock_zero_message");

                $categories = $this->getItemCategories($pk);

                $data->categories = [];
                foreach ($categories as $category) {
                    $data->categories[$category['id']] = $category['name'];
                }

                $manufacturers = $this->getItemManufacturers($pk);

                $data->manufacturers = [];
                foreach ($manufacturers as $manufacturer) {
                    $data->manufacturers[$manufacturer['id']] = $manufacturer['name'];
                }

                // Calculate the dynamic price
                $quantity = (int)$this->getState('quantity', 1); // Default to 1 if not set
                // $quantity = 1; // You can pass a different quantity based on user input
                $userGroupId=0;
                $currencyId=0;
                $priceCalculator = new PriceCalculator($pk, $quantity, $userGroupId, $currencyId);
                $data->price = $priceCalculator->calculatePrice();

                // $data->prices = $this->getPrices($pk);

                // $data->prices = [];
                // foreach ($prices as $price) {
                //     $data->prices[$price['id']] = $price['value'];
                // }

                $this->_item[$pk] = $data;

                $categoryIDs = empty($data->categories) ? [] : array_keys($data->categories);
                $manufacturerIDs = empty($data->manufacturers) ? [] : array_keys($data->manufacturers);
                $this->_item[$pk]->payment_methods = AlfaHelper::getFilteredMethods($categoryIDs,$manufacturerIDs,$user->groups,$user->id);

            } catch (\Exception $e) {
                if ($e->getCode() == 404) {
                    // Need to go through the error handler to allow Redirect to work.
                    throw $e;
                }

                $this->setError($e);
                $this->_item[$pk] = false;
            }
        }
//            echo "<pre>";
//            print_r($this->_item[$pk]->id);
//            echo "</pre>";
//            exit;



        return $this->_item[$pk];
    }

    public function getItemCategories($pk)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select(
            [
                $db->quoteName('c.name'),
                $db->quoteName('c.id'),
            ]
        )
            ->from($db->quoteName('#__alfa_categories', 'c'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_categories', 'ic'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('ic.category_id'),
            )
            ->where($db->quoteName('ic.item_id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);

        return $db->loadAssocList();
    }


    public function getItemManufacturers($pk)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select(
            [
                $db->quoteName('m.name'),
                $db->quoteName('m.id'),
            ]
        )
            ->from($db->quoteName('#__alfa_manufacturers', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_manufacturers', 'im'),
                $db->quoteName('m.id') . ' = ' . $db->quoteName('im.manufacturer_id'),
            )
            ->where($db->quoteName('im.item_id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);

        return $db->loadAssocList();
    }


    /**
     * Get the id of an item by alias
     * @param string $alias Item alias
     *
     * @return  mixed
     *
     * @deprecated  No replacement
     */
    public function getItemIdByAlias($alias)
    {
        $table = $this->getTable();
        $properties = $table->getProperties();
        $result = null;
        $aliasKey = null;
        if (method_exists($this, 'getAliasFieldNameByView')) {
            $aliasKey = $this->getAliasFieldNameByView('item');
        }


        if (key_exists('alias', $properties)) {
            $table->load(array('alias' => $alias));
            $result = $table->id;
        } elseif (isset($aliasKey) && key_exists($aliasKey, $properties)) {
            $table->load(array($aliasKey => $alias));
            $result = $table->id;
        }

        return $result;

    }

    public function getAliasFieldNameByView($view)
    {
        switch ($view) {
            case 'manufacturer':
            case 'manufacturerform':
                return 'alias';
                break;
            case 'category':
            case 'categoryform':
                return 'alias';
                break;
            case 'item':
            case 'itemform':
                return 'alias';
                break;
        }
    }
}
