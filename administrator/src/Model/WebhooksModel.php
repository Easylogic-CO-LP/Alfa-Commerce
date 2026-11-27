<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;

/**
 * The Webhooks list: each webhook with its address, topics and state, what waits for it and what was given up, and
 * its last delivery with its result. The secret is never read.
 *
 * @since  2.3.0
 */
class WebhooksModel extends AlfaListModel
{
    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'webhook';

    /**
     * @param array $config The configuration.
     *
     * @since  2.3.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'a.id', 'name', 'a.name', 'state', 'a.state'];
        }

        parent::__construct($config);
    }

    /**
     * The list's state: by name by default.
     *
     * @param string $ordering The default ordering.
     * @param string $direction The default direction.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'a.name', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * The webhooks with their counts.
     *
     * @return \Joomla\Database\QueryInterface
     *
     * @since  2.3.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $pending = OutboundDelivery::STATE_PENDING;
        $dead = OutboundDelivery::STATE_DEAD;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.name', 'a.url', 'a.topics', 'a.state', 'a.last_attempt_at', 'a.last_ok', 'a.last_result']))
            ->select('COALESCE(' . $db->quoteName('d.waiting') . ', 0) AS ' . $db->quoteName('waiting') . ', COALESCE(' . $db->quoteName('d.dead') . ', 0) AS ' . $db->quoteName('dead'))
            ->from($db->quoteName(OutboundFeed::CONSUMERS, 'a'))
            ->join(
                'LEFT',
                '(SELECT ' . $db->quoteName('consumer_id') . ', SUM(' . $db->quoteName('state') . ' = ' . $db->quote($pending) . ') AS ' . $db->quoteName('waiting')
                . ', SUM(' . $db->quoteName('state') . ' = ' . $db->quote($dead) . ') AS ' . $db->quoteName('dead')
                . ' FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('state') . ' IN (' . $db->quote($pending) . ', ' . $db->quote($dead) . ')'
                . ' GROUP BY ' . $db->quoteName('consumer_id') . ') AS ' . $db->quoteName('d'),
                $db->quoteName('d.consumer_id') . ' = ' . $db->quoteName('a.id'),
            )
            ->where($db->quoteName('a.kind') . ' = ' . $db->quote(OutboundConsumers::KIND_WEBHOOK));

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            $like = '%' . $db->escape($search, true) . '%';
            $query->extendWhere('AND', [
                $db->quoteName('a.name') . ' LIKE ' . $db->quote($like),
                $db->quoteName('a.url') . ' LIKE ' . $db->quote($like),
            ], 'OR');
        }

        $state = $this->getState('filter.state', '');

        if ($state !== '' && $state !== null) {
            $query->where($db->quoteName('a.state') . ' = ' . (int) $state);
        }

        $query->order($db->escape((string) $this->getState('list.ordering', 'a.name')) . ' ' . $db->escape((string) $this->getState('list.direction', 'ASC')));

        $this->orderByKeyLast($query, (string) $this->getState('list.ordering', 'a.name'), (string) $this->getState('list.direction', 'ASC'));

        return $query;
    }
}
