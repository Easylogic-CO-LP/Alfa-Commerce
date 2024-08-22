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
                            // from categories
                            $db->quoteName('c.name', 'category_name'),
                            $db->quoteName('c.id', 'category_id'),
                        ]
                    )
                )
                    ->from($db->quoteName('#__alfa_items', 'a'))
                    ->join(
                        'INNER',
                        $db->quoteName('#__alfa_items_categories', 'ic'),
                        $db->quoteName('a.id') . ' = ' . $db->quoteName('ic.product_id')
                    )
                    ->join(
                        'INNER',
                        $db->quoteName('#__alfa_categories', 'c'),
                        $db->quoteName('ic.category_id') . ' = ' . $db->quoteName('c.id')
                    )
                    ->where(
                        [
                            $db->quoteName('a.id') . ' = :pk',
                            // $db->quoteName('c.published') . ' > 0',
                        ]
                    )
                    ->bind(':pk', $pk, ParameterType::INTEGER);
                $db->setQuery($query);
                $data = $db->loadObjectList();

                $categories = [];

                foreach ($data as $item) {
                    $categories[$item->category_id] = $item->category_name;
                }

                if (!empty($data)) {
                    $data[0]->categories = $categories;
                    $data = $data[0];
                }

                unset($data->category_name);
                unset($data->category_id);

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
//                     ->select(
//                         [
//                             $db->quoteName('c.id','category_id'),
//                             $db->quoteName('c.name/title','category_title'),
//                             $db->quoteName('c.title', 'category_title'),
//                             $db->quoteName('c.alias', 'category_alias'),
//                             $db->quoteName('c.access', 'category_access'),
//                             $db->quoteName('c.language', 'category_language'),
//                             $db->quoteName('fp.ordering'),
//                             $db->quoteName('u.name', 'author'),
//                             $db->quoteName('parent.title', 'parent_title'),
//                             $db->quoteName('parent.id', 'parent_id'),
//                             $db->quoteName('parent.path', 'parent_route'),
//                             $db->quoteName('parent.alias', 'parent_alias'),
//                             $db->quoteName('parent.language', 'parent_language'),
//                             'ROUND(' . $db->quoteName('v.rating_sum') . ' / ' . $db->quoteName('v.rating_count') . ', 1) AS '
//                                 . $db->quoteName('rating'),
//                             $db->quoteName('v.rating_count', 'rating_count'),
//                         ]
//                     )

//                     ->join('LEFT', $db->quoteName('#__alfa_categories', 'c'), $db->quoteName('parent.id') . ' = ' . $db->quoteName('c.parent_id'))
//                     ->join('LEFT', $db->quoteName('#__alfa_items_categories', 'ca'), $db->quoteName('parent.id') . ' = ' . $db->quoteName('c.parent_id'))

        //
        //


//                    ->innerJoin('#__alfa_categories as c on (c.id = ic.category_id)')
        //     ->where(sprintf('ic.product_id = %d', $id));
        // ->order('c.name asc');

        // ->join('LEFT', $db->quoteName('#__content_frontpage', 'fp'), $db->quoteName('fp.content_id') . ' = ' . $db->quoteName('a.id'))
        // ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by'))
        // ->join('LEFT', $db->quoteName('#__categories', 'parent'), $db->quoteName('parent.id') . ' = ' . $db->quoteName('c.parent_id'))
        // ->join('LEFT', $db->quoteName('#__content_rating', 'v'), $db->quoteName('a.id') . ' = ' . $db->quoteName('v.content_id'))


        // Filter by language
        // if ($this->getState('filter.language')) {
        //     $query->whereIn($db->quoteName('a.language'), [Factory::getLanguage()->getTag(), '*'], ParameterType::STRING);
        // }

        // if (
        //     !$user->authorise('core.edit.state', 'com_content.article.' . $pk)
        //     && !$user->authorise('core.edit', 'com_content.article.' . $pk)
        // ) {
        //     // Filter by start and end dates.
        //     $nowDate = Factory::getDate()->toSql();

        //     $query->extendWhere(
        //         'AND',
        //         [
        //             $db->quoteName('a.publish_up') . ' IS NULL',
        //             $db->quoteName('a.publish_up') . ' <= :publishUp',
        //         ],
        //         'OR'
        //     )
        //         ->extendWhere(
        //             'AND',
        //             [
        //                 $db->quoteName('a.publish_down') . ' IS NULL',
        //                 $db->quoteName('a.publish_down') . ' >= :publishDown',
        //             ],
        //             'OR'
        //         )
        //         ->bind([':publishUp', ':publishDown'], $nowDate);
        // }

        // Filter by published state.
        // $published = $this->getState('filter.published');
        // $archived  = $this->getState('filter.archived');

        // if (is_numeric($published)) {
        //     $query->whereIn($db->quoteName('a.state'), [(int) $published, (int) $archived]);
        // }


        // if (empty($data)) {
        //     throw new \Exception(Text::_('COM_CONTENT_ERROR_ARTICLE_NOT_FOUND'), 404);
        // }

        // // Check for published state if filter set.
        // if ((is_numeric($published) || is_numeric($archived)) && ($data->state != $published && $data->state != $archived)) {
        //     throw new \Exception(Text::_('COM_CONTENT_ERROR_ARTICLE_NOT_FOUND'), 404);
        // }

        // Convert parameter fields to objects.
        // $registry = new Registry($data->attribs);

        // $data->params = clone $this->getState('params');
        // $data->params->merge($registry);

        // $data->metadata = new Registry($data->metadata);

        // Technically guest could edit an article, but lets not check that to improve performance a little.
        // if (!$user->get('guest')) {
        //     $userId = $user->get('id');
        //     $asset  = 'com_content.article.' . $data->id;

        //     // Check general edit permission first.
        //     if ($user->authorise('core.edit', $asset)) {
        //         $data->params->set('access-edit', true);
        //     } elseif (!empty($userId) && $user->authorise('core.edit.own', $asset)) {
        //         // Now check if edit.own is available.
        //         // Check for a valid user and that they are the owner.
        //         if ($userId == $data->created_by) {
        //             $data->params->set('access-edit', true);
        //         }
        //     }
        // }

        // Compute view access permissions.
        // if ($access = $this->getState('filter.access')) {
        //     // If the access filter has been set, we already know this user can view.
        //     $data->params->set('access-view', true);
        // } else {
        //     // If no access filter is set, the layout takes some responsibility for display of limited information.
        //     $user   = $this->getCurrentUser();
        //     $groups = $user->getAuthorisedViewLevels();

        //     if ($data->catid == 0 || $data->category_access === null) {
        //         $data->params->set('access-view', \in_array($data->access, $groups));
        //     } else {
        //         $data->params->set('access-view', \in_array($data->access, $groups) && \in_array($data->category_access, $groups));
        //     }
        // }


        // OLD CODE


        // if ($this->_item === null)
        // {
        // 	$this->_item = false;

        // 	if (empty($id))
        // 	{
        // 		$id = $this->getState('item.id');
        // 	}

        // 	// Get a level row instance.
        // 	$table = $this->getTable();

        // 	// Attempt to load the row.
        // 	if ($table && $table->load($id))
        // 	{


        // 		// Check published state.
        // 		if ($published = $this->getState('filter.published'))
        // 		{
        // 			if (isset($table->state) && $table->state != $published)
        // 			{
        // 				throw new \Exception(Text::_('COM_ALFA_ITEM_NOT_LOADED'), 403);
        // 			}
        // 		}

        // 		// Convert the Table to a clean CMSObject.
        // 		$properties  = $table->getProperties(1);
        // 		$this->_item = ArrayHelper::toObject($properties, CMSObject::class);


        // 	}

        // 	if (empty($this->_item))
        // 	{
        // 		throw new \Exception(Text::_('COM_ALFA_ITEM_NOT_LOADED'), 404);
        // 	}
        // }

        //  $container = \Joomla\CMS\Factory::getContainer();

        //  $userFactory = $container->get(UserFactoryInterface::class);

        // if (isset($this->_item->created_by))
        // {
        // 	$user = $userFactory->loadUserById($this->_item->created_by);
        // 	$this->_item->created_by_name = $user->name;
        // }

        //  $container = \Joomla\CMS\Factory::getContainer();

        //  $userFactory = $container->get(UserFactoryInterface::class);

        // if (isset($this->_item->modified_by))
        // {
        // 	$user = $userFactory->loadUserById($this->_item->modified_by);
        // 	$this->_item->modified_by_name = $user->name;
        // }

        // $db = Factory::getDbo();
        // // load selected categories for item
        // $query = $db->getQuery(true);
        // $query
        //     ->select('c.id')
        //     ->from('#__alfa_items_categories as ic')
        //     ->innerJoin('#__alfa_categories as c on (c.id = ic.category_id)')
        //     ->where(sprintf('ic.product_id = %d', $id));
        // // ->order('c.name asc');

        // $db->setQuery($query);
        // $this->_item->categories = $db->loadColumn();

        // // load selected categories for item
        // $query = $db->getQuery(true);
        // $query
        //     ->select('c.id')
        //     ->from('#__alfa_items_manufacturers as ic')
        //     ->innerJoin('#__alfa_manufacturers as c on (c.id = ic.manufacturer_id)')
        //     ->where(sprintf('ic.product_id = %d', $id));
        // // ->order('c.name asc');

        // $db->setQuery($query);
        // $this->_item->manufacturers = $db->loadColumn();

        // return $this->_item;
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
