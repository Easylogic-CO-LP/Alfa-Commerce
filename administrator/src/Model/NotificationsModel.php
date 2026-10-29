<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * List model for backend notifications. The read side of the notification system:
 * the quick panel sets `filter.scope = active` (not-yet-dismissed), the history view
 * uses `filter.scope = history` (everything still retained) with the usual list
 * filters + pagination. Writes go through {@see NotificationHelper}.
 *
 * Programmatic use (the quick panel, NotificationHelper::renderPanel()):
 *   $model = $component->getMVCFactory()->createModel('Notifications', 'Administrator', ['ignore_request' => true]);
 *   $model->setState('filter.scope', 'active');
 *   $model->setState('filter.view_pairs', $visiblePairsAsJson);  // null = the reader sees every rule
 *   $model->setState('list.limit', NotificationHelper::PANEL_LIMIT);
 *   $model->setState('list.first_page', true);                    // the first page only, read by index
 *   $items = $model->getItems();
 *
 * @since  1.0.0
 */
class NotificationsModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'notification';

    /**
     * Constructor.
     *
     * @param array $config Configuration array.
     * @param MVCFactoryInterface|null $factory The factory.
     *
     * @since  1.0.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'severity', 'a.severity',
                'notify_group', 'a.notify_group',
                'created', 'a.created',
                'readed', 'a.readed',
                'dismissed', 'a.dismissed',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Autopopulate the model state from the request.
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default ordering direction.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.created', $direction = 'DESC')
    {
        $app = Factory::getApplication();

        $this->setState('filter.scope', $app->getUserStateFromRequest($this->context . '.filter.scope', 'filter_scope', 'history', 'cmd'));
        $this->setState('filter.search', $app->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string'));
        $this->setState('filter.group', $app->getUserStateFromRequest($this->context . '.filter.group', 'filter_group', '', 'cmd'));
        $this->setState('filter.severity', $app->getUserStateFromRequest($this->context . '.filter.severity', 'filter_severity', '', 'cmd'));
        $this->setState('filter.read', $app->getUserStateFromRequest($this->context . '.filter.read', 'filter_read', '', 'cmd'));

        parent::populateState($ordering, $direction);
    }

    /**
     * Build the store id so different filter/scope combinations cache separately.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.0.0
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

    /**
     * The severities in the order the default list shows them, worst first. A severity
     * outside this list (none is written: push() and the form allow only these) sorts
     * before them, as FIELD() puts it.
     *
     * @var string[]
     * @since  2.1.0
     */
    private const RANK_ORDER = ['danger', 'warning', 'info', 'success'];

    /**
     * Build the list query.
     *
     * Every filter is one condition of a single set, so that the first page of the default
     * order (state list.first_page, the bell's panel) can be read through the index on
     * dismissed, severity and created: the newest rows of each severity, at most as many
     * as the page needs, are the only candidates, and the page is taken from them. The
     * total of such a query is not the list's total; the panel never asks for it.
     *
     * @return QueryInterface
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->createQuery();
        $table = $db->quoteName('#__alfa_notifications', 'a');

        // An expired row is gone, deleted or not yet (NotificationHelper::purgeThrottled()).
        $set = [NotificationHelper::unexpired(now: Factory::getDate()->toSql(), column: 'a.expires')];

        // Scope: active = not dismissed; history = everything still retained.
        if ($this->getState('filter.scope', 'history') === 'active') {
            $set[] = $db->quoteName('a.dismissed') . ' IS NULL';
        }

        // Only the view rules the reader may see (the bell's panel sets this from
        // NotificationHelper::visiblePairs(), one JSON pair per rule; unset = no filter),
        // so a page of rows is a page of rows the reader is shown.
        $pairs = $this->getState('filter.view_pairs');

        if (\is_array($pairs)) {
            $pairs = array_map(static fn (string $pair): array => (array) json_decode($pair, true), $pairs);
            $set[] = $pairs === [] ? '0 = 1' : NotificationHelper::visibilityCondition(pairs: $pairs, alias: 'a');
        }

        $group = $this->getState('filter.group');

        if ($group !== '' && $group !== null) {
            $set[] = $db->quoteName('a.notify_group') . ' = ' . $db->quote((string) $group);
        }

        $severity = $this->getState('filter.severity');

        if ($severity !== '' && $severity !== null) {
            $set[] = $db->quoteName('a.severity') . ' = ' . $db->quote((string) $severity);
        }

        $read = $this->getState('filter.read');

        if ($read === 'read') {
            $set[] = $db->quoteName('a.readed') . ' IS NOT NULL';
        } elseif ($read === 'unread') {
            $set[] = $db->quoteName('a.readed') . ' IS NULL';
        }

        $search = $this->getState('filter.search');

        if (!empty($search)) {
            // The snapshot (the text in the shop's default administrator language) and the keys' parameter values (a
            // product name in every content language, a provider's words), the same for every reader. Only the values
            // count: of the paths JSON_SEARCH answers with, one that ends at an array element or under a content
            // language ("ml") is a value; a key's or a joiner's name ("k", "p", "and", "sep") and the JSON's own words
            // never match. Both sides are lowered, as the snapshot's collation compares.
            $pattern = '%' . str_replace(' ', '%', trim($search)) . '%';
            $like = $db->quote($pattern);
            $args = $db->quoteName('a.text_args');
            $set[] = '(' . $db->quoteName('a.title') . ' LIKE ' . $like . ' OR ' . $db->quoteName('a.message') . ' LIKE ' . $like
                . ' OR IFNULL(CASE WHEN JSON_VALID(' . $args . ') THEN JSON_SEARCH(LOWER(' . $args . "), 'all', " . $db->quote(mb_strtolower($pattern)) . ') END, \'\')'
                . ' REGEXP ' . $db->quote('\\]"|[.]ml[.]') . ')';
        }

        $query->select($this->getState('list.select', 'a.*'))
            ->from($table);

        foreach ($set as $condition) {
            $query->where($condition);
        }

        // Ordering: an explicit column if the list asked for one, else worst-severity-first then newest.
        $orderCol = $this->state->get('list.ordering', '');
        $orderDir = $this->state->get('list.direction', 'DESC');

        if ($orderCol !== '') {
            $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDir) === 'DESC' ? 'DESC' : 'ASC'));

            return $query;
        }

        $ranks = implode(', ', array_map([$db, 'quote'], self::RANK_ORDER));

        // The id last: rows created in the same second keep one order, newest first,
        // so a page of the panel is always the same page.
        $query->order('FIELD(' . $db->quoteName('a.severity') . ', ' . $ranks . ') ASC, '
            . $db->quoteName('a.created') . ' DESC, ' . $db->quoteName('a.id') . ' DESC');

        $depth = (int) $this->getState('list.start', 0) + (int) $this->getState('list.limit', 0);

        if ($this->getState('list.first_page') && $depth > 0) {
            $branches = [];

            foreach (array_merge([null], self::RANK_ORDER) as $rank) {
                $branches[] = '(SELECT ' . $db->quoteName('a.id') . ' FROM ' . $table
                    . ' WHERE ' . implode(' AND ', $set) . ' AND ' . $db->quoteName('a.severity')
                    . ($rank === null ? ' NOT IN (' . $ranks . ')' : ' = ' . $db->quote($rank))
                    . ' ORDER BY ' . $db->quoteName('a.created') . ' DESC, ' . $db->quoteName('a.id') . ' DESC'
                    . ' LIMIT ' . $depth . ')';
            }

            $query->where($db->quoteName('a.id') . ' IN (SELECT ' . $db->quoteName('first.id')
                . ' FROM (' . implode(' UNION ALL ', $branches) . ') AS ' . $db->quoteName('first') . ')');
        }

        return $query;
    }
}
