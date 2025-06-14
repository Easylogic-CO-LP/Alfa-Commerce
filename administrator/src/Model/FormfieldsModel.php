<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2025 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;

/**
 * Methods supporting a list of Items records.
 *
 * @since  1.0.1
 */
class FormFieldsModel extends ListModel
{
    protected $orderUserInfoTableName = "#__alfa_user_info";

    /**
     * Constructor.
     *
     * @param   array  $config  An optional associative array of configuration settings.
     *
     * @see        JController
     * @since      1.6
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                'name', 'a.name',
                'state', 'a.state',
                'id', 'a.id'
            ];
        }

        parent::__construct($config);
    }


    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param   string  $ordering   Elements order
     * @param   string  $direction  Order direction
     *
     * @return void
     *
     * @throws Exception
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState('id', 'DESC');

        $context = $this->getUserStateFromRequest($this->context.'.filter.search', 'filter_search');
        $this->setState('filter.search', $context);

        // Split context into component and optional section
        //        if (!empty($context))
        //        {
        //            $parts = FieldsHelper::extract($context);
        //
        //            if ($parts)
        //            {
        //                $this->setState('filter.component', $parts[0]);
        //                $this->setState('filter.section', $parts[1]);
        //            }
        //        }
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * This is necessary because the model is used by the component and
     * different modules that might need different sets of data or different
     * ordering requirements.
     *
     * @param   string  $id  A prefix for the store id.
     *
     * @return  string A store id.
     *
     * @since   1.0.1
     */
    protected function getStoreId($id = '')
    {
        // Compile the store id.
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');


        return parent::getStoreId($id);

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
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*'
            )
        );

        $query->from($db->qn("#__alfa_form_fields", "a"))
            ->order("ordering", "ASC");


        // Select the required fields from the table.
        //        $query->select(
        //            $this->getState(
        //                'list.select', 'DISTINCT a.*'
        //            )
        //        );
        //        $query->from('`#__alfa_form_fields` AS a');
        //
        //        // Join over the users for the checked out user
        //        $query->select("uc.name AS uEditor");
        //        $query->join("LEFT", "#__users AS uc ON uc.id=a.checked_out");
        //
        //        // Join over the user field 'created_by'
        //        $query->select('`created_by`.name AS `created_by`');
        //        $query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');
        //
        //        // Join over the user field 'modified_by'
        //        $query->select('`modified_by`.name AS `modified_by`');
        //        $query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');


        // Filter by published state
        $published = $this->getState('filter.state');



        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        }
        //        elseif (empty($published))
        //        {
        //            $query->where('(a.state IN (0, 1))');
        //        }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                $query->where('( a.name LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause.
        $orderCol  = $this->state->get('list.ordering', 'id');
        $orderDirn = $this->state->get('list.direction', 'DESC');

        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }


        return $query;
    }

    /**
     * Get an array of data items
     *
     * @return mixed Array of data items on success, false on failure.
     */
    public function getItems()
    {

        $items = parent::getItems();

        return $items;
    }


    public function delete(&$pks)
    {

        $app = Factory::getApplication();
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $fieldNames = self::getFieldNames($pks);


        $query
            ->delete("#__alfa_form_fields")
            ->whereIn($db->qn("id"), $pks);

        $db->setQuery($query);
        if ($db->execute()) {
            $app->enqueueMessage("Entry was deleted successfully!", "success");
        } else {
            $app->enqueueMessage("Entry was could not be deleted.", "error");
        }


    }


    /**
     *
     *  @param $columnName string the name of the column to delete.
     *  @return void
     */
    protected function deleteUserInfoTableColumn($columnName)
    {

        $formFieldModel = $this->app->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Formfield', 'Admin', ['ignore_request' => true]);

        // Delete column if it exists.
        if ($formFieldModel->getTableColumn($this->orderUserInfoTableName, $columnName) != null) {
            $db = self::getDatabase();
            $query = $db->getQuery(true);
            $query = 'ALTER TABLE ' . $db->quoteName($this->orderUserInfoTableName) . " DROP COLUMN " . $db->qn($columnName);

            $db->setQuery($query);
            $db->execute();
        }

    }

    protected function getFieldNames($pks)
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select($db->qn("field_name"))
            ->from($db->qn("#__alfa_form_fields"))
            ->whereIn($db->qn("id"), $pks);
        $db->setQuery($query);

        return $db->loadObjectList();
    }




}
