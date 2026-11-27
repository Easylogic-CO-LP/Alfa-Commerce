<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * Methods supporting the list of product item-field definitions.
 *
 * @since  2.0.0
 */
class ItemfieldsModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'itemfield';

    /**
     * Constructor.
     *
     * @param array $config An optional associative array of configuration settings.
     * @param MVCFactoryInterface|null $factory The MVC factory.
     *
     * @since  2.0.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'ordering', 'a.ordering',
                'state', 'a.state',
                'type', 'a.type',
                'informational', 'a.informational',
                'field_name', 'a.field_name',
                // Translatable: resolved via the lang-table COALESCE alias.
                'name',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string A store id.
     *
     * @since  2.0.0
     */
    protected function getStoreId($id = '')
    {
        // Every filter of the state goes into the identity, nested ones as JSON.
        return parent::getStoreId(AlfaHelper::listStoreId($id, $this->getState()->toArray()));
    }

    /**
     * Build an SQL query to load the list data.
     *
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since  2.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*',
            ),
        );

        $query->from($db->qn('#__alfa_item_fields', 'a'));

        // MULTILINGUAL: resolve the field name in the active language from the
        // per-language tables (LEFT JOIN + COALESCE keeps untranslated rows).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_item_fields',
            langPrimaryColumn: 'id_item_field',
            fields:            ['name'],
        );

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

        // Filter by plugin type (choice, color, ...).
        $type = $this->getState('filter.type');
        if ($type !== null && $type !== '') {
            $query->where('a.type = ' . $db->quote($type));
        }

        // Filter by informational flag.
        $informational = $this->getState('filter.informational');
        if ($informational !== null && $informational !== '') {
            $query->where('a.informational = ' . (int) $informational);
        }

        // Filter by search in name / machine key.
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                // HAVING: `name` is the COALESCE alias from the lang join.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search
                    . ' OR a.field_name LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'a.ordering');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        if ($orderCol && $orderDirn) {
            $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
            $this->orderByKeyLast($query, (string) $orderCol, (string) $orderDirn);
        }

        return $query;
    }
}
