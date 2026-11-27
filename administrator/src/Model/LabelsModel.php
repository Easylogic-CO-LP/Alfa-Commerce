<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * The list of the shop's labels: in their order, each with how many products tick it.
 *
 * @since  2.3.0
 */
class LabelsModel extends AlfaListModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('label').
     * @since  2.3.0
     */
    protected string $alfaEntity = 'label';

    /**
     * Constructor.
     *
     * @param array $config An optional associative array of configuration settings.
     * @param MVCFactoryInterface|null $factory The factory.
     *
     * @since  2.3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'ordering', 'a.ordering',
                'id', 'a.id',
                'state', 'a.state',
                'filterable', 'a.filterable',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                // Translatable: resolved via the lang-table COALESCE alias.
                'name',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to auto-populate the model state: the labels in their order, as they stand on a product.
     *
     * @param string $ordering Elements order
     * @param string $direction Order direction
     *
     * @return void
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'a.ordering', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string A store id.
     *
     * @since  2.3.0
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
     * @since  2.3.0
     */
    protected function getListQuery()
    {
        $db = $this->getDbo();
        $query = $db->getQuery(true);

        $query->select($this->getState('list.select', 'DISTINCT a.*'));
        $query->from('`#__alfa_labels` AS a');

        // Join over the users for the checked out user
        $query->select('uc.name AS uEditor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the user field 'created_by'
        $query->select('`created_by`.name AS `created_by`');
        $query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');

        // Join over the user field 'modified_by'
        $query->select('`modified_by`.name AS `modified_by`');
        $query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');

        // MULTILINGUAL: resolve the name in the active language from the per-language tables (LEFT JOIN + COALESCE
        // keeps untranslated rows).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_labels',
            langPrimaryColumn: 'id_label',
            fields:            ['name'],
        );

        // How many products tick the label: a correlated subquery, as the manufacturers list counts (DISTINCT a.*
        // plus a bare HAVING forbid a junction join); the item states mirror the Items list default so the badge and
        // the linked list agree.
        $query->select(
            '(SELECT COUNT(DISTINCT ' . $db->quoteName('il.item_id') . ')'
            . ' FROM ' . $db->quoteName('#__alfa_item_labels', 'il')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'li')
            . ' ON ' . $db->quoteName('li.id') . ' = ' . $db->quoteName('il.item_id')
            . ' WHERE ' . $db->quoteName('il.label_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . $db->quoteName('li.state') . ' IN (0, 1)'
            . ') AS ' . $db->quoteName('item_count'),
        );

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

        // Filter by whether the storefront filters offer the label
        $filterable = $this->getState('filter.filterable');

        if (is_numeric($filterable)) {
            $query->where('a.filterable = ' . (int) $filterable);
        }

        // Filter by search in name
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                // HAVING: `name` is the COALESCE alias from the lang join, not a real column at WHERE time.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'a.ordering');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        if ($orderCol && $orderDirn) {
            $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
        }

        return $query;
    }
}
