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

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Exception;
use Joomla\CMS\Router\Route;

/**
 * Methods supporting a list of Alfa records.
 *
 * @since  1.0.1
 */
class CategoriesModel extends UrlListModel
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
                'state', 'a.state',
                // Translatable — resolved via the lang-table COALESCE alias.
                'name',
                'alias',
            ];
        }

        parent::__construct($config);
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

        // Resolve name / alias in the active language (current → default → '')
        // from the per-language tables. LEFT JOIN keeps untranslated rows visible.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            fields:            ['name', 'alias', 'desc'],
        );

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
                // HAVING (not WHERE) because `name` is the COALESCE alias built
                // from the lang join — it is not a real column at WHERE time.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause. `name` is the translated COALESCE alias
        // from the lang join (the main-table name column no longer exists).
        $orderCol = $this->state->get('list.ordering', 'name');
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

        if (!empty($items)) {
            // Batch media (one query for ALL categories, grouped by id — avoids N+1).
            $mediaByCategory = MediaHelper::getMediaData(
                origin:         'category',
                itemIDs:        array_map(static fn ($c) => (int) $c->id, $items),
                usePlaceHolder: true,
            );

            foreach ($items as $category) {
                $category->medias = $mediaByCategory[$category->id] ?? [];

                // Generate links for categories
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
