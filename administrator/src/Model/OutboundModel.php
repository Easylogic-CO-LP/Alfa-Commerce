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
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * The Background screen's Outgoing notifications tab: every change the shop owes or owed a consumer (a webhook, or an
 * extension that tells another system), each in one of four states of work: running now (its consumer's delivery job
 * is sending), waiting, needs attention (given up after the last retry) or finished (taken, discarded, or of a plugin
 * that takes none). Filters: a search (an id, a product or order, a consumer's name or an error), the state of work,
 * the destination's kind (a webhook address, an extension), the consumer told and when the change was recorded.
 *
 * @since  2.3.0
 */
class OutboundModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'delivery';

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config The model's configuration.
     * @param MVCFactoryInterface|null $factory The MVC factory.
     *
     * @since  2.3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'd.id', 'rank', 'created', 'd.created', 'finished'];
        }

        parent::__construct($config, $factory);
    }

    /**
     * The list's state: waiting first by default (running while its job runs), then given up and finished, oldest
     * first within each (the stored rank's index); the date window against the moment the change was recorded.
     *
     * @param string $ordering The default ordering.
     * @param string $direction The default direction.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'rank', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);

        // After the parent: the trait reads the registry the parent fills.
        $this->resolveDateRangeFilter();
    }

    /**
     * The rows of the page, each told for staff: who is told and how, what the change is about, its state of work,
     * its topic's label and when it ended.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function getItems()
    {
        $rows = parent::getItems();

        if (!\is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $row->consumerText = (string) $row->kind === OutboundConsumers::KIND_PLUGIN
                ? Text::sprintf('COM_ALFA_OUTBOUND_KIND_PLUGIN', (string) $row->plugin)
                : Text::sprintf('COM_ALFA_OUTBOUND_KIND_WEBHOOK', (string) (parse_url((string) $row->url, PHP_URL_HOST) ?: ''));
            $row->topicText = Text::_(OutboundTopics::label(topic: (string) $row->topic));
            $row->aboutText = \in_array((string) $row->topic, OutboundTopics::MERGED, true)
                ? ((int) $row->unit_id > 0
                    ? Text::sprintf('COM_ALFA_OUTBOUND_ABOUT_COMBINATION', (int) $row->subject_id, (int) $row->unit_id)
                    : Text::sprintf('COM_ALFA_OUTBOUND_ABOUT_PRODUCT', (int) $row->subject_id))
                : Text::sprintf('COM_ALFA_TASKS_SUBJECT_ORDER', (int) $row->subject_id);
            $row->finished = $row->sent_at ?? $row->dead_at;
        }

        return $rows;
    }

    /**
     * The list's query: the deliveries with their consumer.
     *
     * @since  2.3.0
     */
    protected function getListQuery(): QueryInterface
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'd.id', 'd.consumer_id', 'd.topic', 'd.subject_id', 'd.unit_id', 'd.payload', 'd.state', 'd.attempts', 'd.next_attempt_at',
                'd.last_error', 'd.created', 'd.sent_at', 'd.dead_at',
            ]))
            ->select($db->quoteName(['c.name', 'c.kind', 'c.plugin', 'c.url']))
            ->select($db->quoteName('c.state', 'consumer_state'))
            ->select(OutboundConsumers::workSql(db: $db, alias: 'd') . ' AS ' . $db->quoteName('work'))
            ->from($db->quoteName(OutboundFeed::DELIVERIES, 'd'))
            ->join('LEFT', $db->quoteName(OutboundFeed::CONSUMERS, 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('d.consumer_id'));

        $work = (string) $this->getState('filter.work', '');

        if ($work !== '' && \in_array($work, TaskStaff::WORK_STATES, true)) {
            $query->where(OutboundConsumers::workSql(db: $db, alias: 'd') . ' = :work')->bind(':work', $work);
        } elseif ($work !== '') {
            $query->where('1 = 0');
        }

        $consumer = (int) $this->getState('filter.kind', 0);

        if ($consumer > 0) {
            $query->where($db->quoteName('d.consumer_id') . ' = ' . $consumer);
        }

        // The destination's kind: a webhook address, or an extension that tells another system.
        $type = (string) $this->getState('filter.type', '');

        if (\in_array($type, [OutboundConsumers::KIND_WEBHOOK, OutboundConsumers::KIND_PLUGIN], true)) {
            $query->where($db->quoteName('c.kind') . ' = :type')->bind(':type', $type);
        } elseif ($type !== '') {
            $query->where('1 = 0');
        }

        // The change is recorded as UTC; the state holds the shop's local bounds.
        AlfaHelper::applyDateRange(
            query: $query,
            column: 'd.created',
            localStart: (string) $this->getState('filter.date_start', ''),
            localEnd: (string) $this->getState('filter.date_end', ''),
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            if (preg_match('/^#?(\d+)$/', $search, $matches) === 1) {
                $query->where($db->quoteName('d.id') . ' = ' . (int) $matches[1]);
            } elseif (preg_match('/^(order|product):(\d+)$/i', $search, $matches) === 1) {
                $topics = strtolower($matches[1]) === 'product' ? OutboundTopics::MERGED : OutboundTopics::ORDERS;
                $query->whereIn($db->quoteName('d.topic'), $topics, ParameterType::STRING)
                    ->where($db->quoteName('d.subject_id') . ' = ' . (int) $matches[2]);
            } else {
                // One clause of its own: the search may be the only condition of the query.
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('(' . implode(' OR ', [
                    $db->quoteName('c.name') . ' LIKE ' . $like,
                    $db->quoteName('d.topic') . ' LIKE ' . $like,
                    $db->quoteName('d.last_error') . ' LIKE ' . $like,
                ]) . ')');
            }
        }

        $ordering = (string) $this->getState('list.ordering', 'rank');
        $direction = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $query->order(match ($ordering) {
            // The stored rank with the id, one direction: the index the table keeps for this order.
            'rank' => $db->quoteName('d.rank') . ' ' . $direction . ', ' . $db->quoteName('d.id') . ' ' . $direction,
            'finished' => 'COALESCE(' . $db->quoteName('d.sent_at') . ', ' . $db->quoteName('d.dead_at') . ') ' . $direction,
            default => $db->quoteName($ordering) . ' ' . $direction,
        });
        $this->orderByKeyLast($query, $ordering, $direction, 'd.id');

        return $query;
    }
}
