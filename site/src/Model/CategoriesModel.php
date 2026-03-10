<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Router\Route;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

/**
 * Methods supporting a list of Alfa records.
 *
 * @since  1.0.1
 */
class CategoriesModel extends ListModel
{
    /**
     * Constructor.
     *
     * @param array $config An optional associative array of configuration settings.
     *
     * @see    JController
     * @since  1.0.1
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                'parent_id', 'a.parent_id',
                'id', 'a.id',
                'name', 'a.name',
                'state', 'a.state',
                'alias', 'a.alias',
                'meta_title', 'a.meta_title',
                'meta_desc', 'a.meta_desc',
            ];
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
     * @return void
     *
     * @throws Exception
     *
     * @since   1.0.1
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState('a.name', 'ASC');

        $app = Factory::getApplication();
        $list = $app->getUserState($this->context . '.list');

        $value = $app->getUserState($this->context . '.list.limit', $app->get('list_limit', 25));
        $list['limit'] = $value;

        $this->setState('list.limit', $value);

        $value = $app->input->get('limitstart', 0, 'uint');
        $this->setState('list.start', $value);

        $ordering = $this->getUserStateFromRequest($this->context . '.filter_order', 'filter_order', 'a.name');
        $direction = strtoupper($this->getUserStateFromRequest($this->context . '.filter_order_Dir', 'filter_order_Dir', 'ASC'));

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
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*',
            ),
        );

        $query->from('`#__alfa_categories` AS a');

        $parentCategoryFilter = $this->getState('filter.parent_id');
        if ($parentCategoryFilter !== null) {
            $query->where('a.parent_id = ' . (int) $parentCategoryFilter);
        }

        // FILTER BY CATEGORy/CATEGORIES
        $categoriesFilter = $this->getState('filter.categories');

        if ($categoriesFilter !== null) {
            // Normalize to array
            if (!is_array($categoriesFilter)) {
                if (is_numeric($categoriesFilter)) {
                    $categoriesFilter = [(int) $categoriesFilter];
                } else {
                    $categoriesFilter = null;
                }
            }

            if ($categoriesFilter !== null) {
                // Sanitize: convert to int, keep only positive values
                $categoriesFilter = array_map('intval', $categoriesFilter);
                $categoriesFilter = array_filter($categoriesFilter, function ($id) {
                    return $id > 0;
                });

                if (!empty($categoriesFilter)) {
                    $query->whereIn('a.id', $categoriesFilter);
                }
            }
        }

        $query->where('a.state = 1');

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('( a.name LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'a.name');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        return $query;
    }

    /**
     * Method to get an array of data items
     *
     * @return mixed An array of data on success, false on failure.
     */
    public function getItems()
    {
        $items = parent::getItems();

        // Generate links for categories
        if (!empty($items)) {
            foreach ($items as $category) {
                $category->link = Route::_('index.php?option=com_alfa&view=items&category_id=' . (int) $category->id);
            }
        }

        return $items;
    }

    /**
     * Get category path from a category to root
     *
     * Delegates to AlfaHelper for actual implementation
     *
     * @param int $categoryId Category ID
     *
     * @return array Array of categories from root to current
     *
     * @since   1.0.1
     */
    public function getCategoryPath($categoryId)
    {
        return CategoryHelper::getCategoryPath($categoryId);
    }
}
