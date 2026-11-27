<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

/**
 * One webhook's deliveries: each change the shop owed its address, newest first, by status (delivered, failed or
 * pending). The webhook is named by webhook_id in every request of the list, never kept as a filter, and only a
 * webhook's changes are listed: a plugin consumer's are not.
 *
 * @since  2.3.0
 */
class WebhookdeliveriesModel extends AlfaListModel
{
    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'webhookdelivery';

    /**
     * The status filter's values, and the delivery states each one covers.
     *
     * @var array<string, string[]>
     *
     * @since  2.3.0
     */
    public const STATUSES = [
        'delivered' => [OutboundDelivery::STATE_SENT],
        'failed' => [OutboundDelivery::STATE_DEAD, OutboundDelivery::STATE_DISCARDED],
        'pending' => [OutboundDelivery::STATE_PENDING],
    ];

    /**
     * @param array $config The configuration.
     *
     * @since  2.3.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'a.id', 'state', 'a.state'];
        }

        parent::__construct($config);
    }

    /**
     * The list's state: the webhook from the request, the rows newest first by default.
     *
     * @param string $ordering The default ordering.
     * @param string $direction The default direction.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        $this->setState('webhook.id', RequestScalar::int(Factory::getApplication()->getInput(), 'webhook_id'));

        parent::populateState($ordering, $direction);
    }

    /**
     * The webhook's deliveries, by status when one is chosen.
     *
     * @return \Joomla\Database\QueryInterface
     *
     * @since  2.3.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $webhook = (int) $this->getState('webhook.id', 0);
        $kind = OutboundConsumers::KIND_WEBHOOK;
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'a.id', 'a.topic', 'a.subject_id', 'a.unit_id', 'a.payload', 'a.state', 'a.attempts', 'a.next_attempt_at',
                'a.last_error', 'a.created', 'a.sent_at', 'a.dead_at',
            ]))
            ->from($db->quoteName(OutboundFeed::DELIVERIES, 'a'))
            ->join(
                'INNER',
                $db->quoteName(OutboundFeed::CONSUMERS, 'c'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('a.consumer_id') . ' AND ' . $db->quoteName('c.kind') . ' = :kind',
            )
            ->where($db->quoteName('a.consumer_id') . ' = :webhook')
            ->bind(':kind', $kind)
            ->bind(':webhook', $webhook, ParameterType::INTEGER);

        $states = self::STATUSES[(string) $this->getState('filter.state', '')] ?? [];

        if ($states !== []) {
            $query->whereIn($db->quoteName('a.state'), $states, ParameterType::STRING);
        }

        $query->order($db->escape((string) $this->getState('list.ordering', 'a.id')) . ' ' . $db->escape((string) $this->getState('list.direction', 'DESC')));

        $this->orderByKeyLast($query, (string) $this->getState('list.ordering', 'a.id'), (string) $this->getState('list.direction', 'DESC'));

        return $query;
    }
}
