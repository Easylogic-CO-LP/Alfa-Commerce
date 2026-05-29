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
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

/**
 * Methods supporting a list of Alfa records.
 *
 * @since  1.0.1
 */
class ManufacturersModel extends UrlListModel
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
                'id', 'a.id',
                'state', 'a.state',
                'website', 'a.website',
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
     * @return DatabaseQuery
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

        $query->from('`#__alfa_manufacturers` AS a');

        // Join over the users for the checked out user.
        $query->select('uc.name AS uEditor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the created by field 'created_by'
        $query->join('LEFT', '#__users AS created_by ON created_by.id = a.created_by');

        // Join over the created by field 'modified_by'
        $query->join('LEFT', '#__users AS modified_by ON modified_by.id = a.modified_by');

        // MULTILINGUAL: resolve name / alias in the active language from the
        // per-language tables (LEFT JOIN + COALESCE keeps untranslated rows).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
            fields:            ['name', 'alias', 'desc'],
        );

        if (!Factory::getApplication()->getIdentity()->authorise('core.edit', 'com_alfa')) {
            $query->where('a.state = 1');
        } else {
            $query->where('(a.state IN (0, 1))');
        }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                // HAVING — `name` is the COALESCE alias from the lang join.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause. `name` is the translated COALESCE alias.
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
            // Batch media (one query for ALL manufacturers, grouped by id — avoids N+1).
            $mediaByManufacturer = MediaHelper::getMediaData(
                origin:         'manufacturer',
                itemIDs:        array_map(static fn ($m) => (int) $m->id, $items),
                usePlaceHolder: true,
            );

            foreach ($items as $manufacturer) {
                $manufacturer->medias = $mediaByManufacturer[$manufacturer->id] ?? [];

                // Generate links for manufacturers
                $manufacturer->details_link = Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $manufacturer->id);
                $manufacturer->link = Route::_('index.php?option=com_alfa&view=items&filter[manufacturer]=' . (int) $manufacturer->id);
            }
        }

        return $items;
    }
}
