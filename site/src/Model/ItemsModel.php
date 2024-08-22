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
use \Joomla\Database\ParameterType;

/**
 * Alfa model.
 *
 * @since  1.0.1
 */
class ItemModel extends BaseItemModel
{
    public $_item;

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
                            $db->quoteName('a.name'),
                            $db->quoteName('a.short_desc'),
                            $db->quoteName('a.full_desc'),
                            $db->quoteName('a.sku'),
                            $db->quoteName('a.gtin'),
                            $db->quoteName('a.mpn'),
                            $db->quoteName('a.stock'),
                            $db->quoteName('a.stock_action'),
                            $db->quoteName('a.manage_stock'),
                            $db->quoteName('a.alias'),
                            $db->quoteName('a.meta_title'),
                            $db->quoteName('a.meta_desc'),
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

                $categories = $this->getItemCategories($pk);

                $data->categories = [];
                foreach ($categories as $category) {
                    $data->categories[$category['id']] = $category['name'];
                }

                $manufacturers = $this->getItemManufacturers($pk);

                foreach ($manufacturers as $manufacturer) {
                    $data->manufacturers[$manufacturer['id']] = $manufacturer['name'];
                }

                // TODO: getItemPrices()
                // $prices = $this->getItemPrices($pk);
                $this->_item[$pk] = $data;

            } catch (\Exception $e) {
                if ($e->getCode() == 404) {
                    // Need to go through the error handler to allow Redirect to work.
                    throw $e;
                }

                $this->setError($e);
                $this->_item[$pk] = false;
            }
        }

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
            ->where($db->quoteName('ic.product_id') . ' = ' . $db->quote($pk));

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
            ->where($db->quoteName('im.product_id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);

        return $db->loadAssocList();
    }

    public function getItemPrices($pk)
    {

    }

    /**
     * Get an instance of Table class
     *
     * @param string $type Name of the Table class to get an instance of.
     * @param string $prefix Prefix for the table class name. Optional.
     * @param array $config Array of configuration values for the Table object. Optional.
     *
     * @return  Table|bool Table if success, false on failure.
     */
    public function getTable($type = 'Item', $prefix = 'Administrator', $config = array())
    {
        return parent::getTable($type, $prefix, $config);
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

    /**
     * Method to check in an item.
     *
     * @param integer $id The id of the row to check out.
     *
     * @return  boolean True on success, false on failure.
     *
     * @since   1.0.1
     */
    public function checkin($id = null)
    {
        // Get the id.
        $id = (!empty($id)) ? $id : (int)$this->getState('item.id');

        if ($id) {
            // Initialise the table
            $table = $this->getTable();

            // Attempt to check the row in.
            if (method_exists($table, 'checkin')) {
                if (!$table->checkin($id)) {
                    return false;
                }
            }
        }

        return true;

    }

    /**
     * Method to check out an item for editing.
     *
     * @param integer $id The id of the row to check out.
     *
     * @return  boolean True on success, false on failure.
     *
     * @since   1.0.1
     */
    public function checkout($id = null)
    {
        // Get the user id.
        $id = (!empty($id)) ? $id : (int)$this->getState('item.id');


        if ($id) {
            // Initialise the table
            $table = $this->getTable();

            // Get the current user object.
            $user = Factory::getApplication()->getIdentity();

            // Attempt to check the row out.
            if (method_exists($table, 'checkout')) {
                if (!$table->checkout($user->get('id'), $id)) {
                    return false;
                }
            }
        }

        return true;

    }

    /**
     * Publish the element
     *
     * @param int $id Item id
     * @param int $state Publish state
     *
     * @return  boolean
     */
    public function publish($id, $state)
    {
        $table = $this->getTable();

        $table->load($id);
        $table->state = $state;

        return $table->store();

    }

    /**
     * Method to delete an item
     *
     * @param int $id Element id
     *
     * @return  bool
     */
    public function delete($id)
    {
        $table = $this->getTable();


        return $table->delete($id);

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
