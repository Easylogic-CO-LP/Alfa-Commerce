<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * Cart rules list model.
 *
 * @since  1.3.0
 */
class CartrulesModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'cartrule';

    /**
     * Constructor: registers sortable/filterable fields.
     *
     * @param array $config Configuration array.
     * @param MVCFactoryInterface|null $factory MVC factory.
     *
     * @since  1.3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                // Translatable: resolved through the lang-table COALESCE alias.
                'name',
                'code', 'a.code',
                'discount_class', 'a.discount_class',
                'action', 'a.action',
                'priority', 'a.priority',
                'state', 'a.state',
                'publish_up', 'a.publish_up',
                'publish_down', 'a.publish_down',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Build the list query.
     *
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since  1.3.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();

        // Redemptions are COUNTED from the order freeze: there is no counter
        // column by design. A correlated subquery keeps it one row per rule, so
        // pagination totals stay correct (no GROUP BY), same as the document
        // series grid. Matches CartRulesEngine::filterByUsage()'s definition of
        // a redemption: live order, not soft-deleted.
        $usedCount = '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_order_cart_rule', 'ocr')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_orders', 'o')
            . ' ON o.' . $db->quoteName('id') . ' = ocr.' . $db->quoteName('id_order')
            . ' AND o.' . $db->quoteName('state') . ' = 1'
            . ' WHERE ocr.' . $db->quoteName('id_cart_rule') . ' = a.' . $db->quoteName('id')
            . ' AND ocr.' . $db->quoteName('deleted') . ' = 0) AS ' . $db->quoteName('used_count');

        $query = $db->getQuery(true)
            ->select('a.*')
            ->select($usedCount)
            ->from($db->quoteName('#__alfa_cart_rules', 'a'));

        // MULTILINGUAL: the name lives only in the per-language tables, so it
        // arrives as a COALESCE alias, not a column of `a`.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_cart_rules',
            langPrimaryColumn: 'id_cart_rule',
            fields:            ['name'],
        );

        $state = $this->getState('filter.state');

        if (is_numeric($state)) {
            $query->where($db->quoteName('a.state') . ' = ' . (int) $state);
        }

        // Activation filter: coupons (with code) vs automatic rules.
        $activation = (string) $this->getState('filter.activation', '');

        if ($activation === 'code') {
            $query->where($db->quoteName('a.code') . ' IS NOT NULL');
        } elseif ($activation === 'automatic') {
            $query->where($db->quoteName('a.code') . ' IS NULL');
        }

        $class = (string) $this->getState('filter.discount_class', '');

        if ($class !== '') {
            $query->where($db->quoteName('a.discount_class') . ' = ' . $db->quote($class));
        }

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $query->where($db->quoteName('a.id') . ' = ' . (int) substr($search, 3));
            } else {
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                // Both halves go in HAVING, not WHERE: `name` is a computed
                // COALESCE alias and cannot be referenced in WHERE, and passing
                // an 'OR' glue to where() would re-glue the state/activation
                // filters too. MySQL allows a bare HAVING without GROUP BY.
                $query->having(
                    '(' . $db->quoteName('name') . ' LIKE ' . $like
                    . ' OR ' . $db->quoteName('a.code') . ' LIKE ' . $like . ')',
                );
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.priority');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }

    /**
     * Get store id.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.3.0
     */
    protected function getStoreId($id = '')
    {
        // Serialise EVERY filter into the cache identity, including filters a plugin
        // declared through onAlfaAdminListFilters, which a hand-enumerated list would
        // silently omit (stale cache + the contract's list phases skipping re-runs).
        // Same discipline as the orders grid.
        $filters = [];

        // toArray(), not (array): the model State keeps LITERAL dotted keys, so an
        // object cast returns its protected properties and matches nothing.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                $filters[$key] = \is_array($value) ? implode(',', $value) : (string) $value;
            }
        }

        ksort($filters);

        foreach ($filters as $key => $value) {
            $id .= ':' . $key . '=' . $value;
        }

        return parent::getStoreId($id);
    }
}
