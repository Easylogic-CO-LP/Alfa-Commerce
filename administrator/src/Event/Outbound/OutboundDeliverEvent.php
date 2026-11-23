<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Outbound;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;
use LogicException;

/**
 * A batch of changes for a plugin consumer to tell its integrator (onAlfaOutboundDeliver), addressed to the one
 * plugin that consumes them, never raised on the shared dispatcher. The plugin delivers through its own client, with
 * the integrator's credentials it keeps in its own settings, and answers each change:
 *
 * - delivered($id): the integrator took it;
 * - retry($id, $reason): try again later, on the runner's backoff (after the sixth retry it is given up and shown in
 *   Tools, Tasks);
 * - refused($id, $reason): the integrator refuses it for good; it is given up at once.
 *
 * A change left unanswered is retried. A handler that throws has every change of the batch retried, and its failure
 * logged. The batch is handed over outside any transaction, so the handler may call another system.
 *
 * @since  2.3.0
 */
final class OutboundDeliverEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaOutboundDeliver';

    /**
     * The answers by change id: [delivered|retry|refused, reason].
     *
     * @var array<int, array{0: string, 1: string}>
     *
     * @since  2.3.0
     */
    private array $answers = [];

    /**
     * @param int $consumerId The consumer the plugin is.
     * @param list<OutboundChange> $changes The changes, oldest first.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly int $consumerId, private readonly array $changes)
    {
        parent::__construct(self::NAME, ['consumerId' => $consumerId]);
    }

    /**
     * The consumer the plugin is: what OutboundFeed::waiting() asks with.
     *
     * @since  2.3.0
     */
    public function getConsumerId(): int
    {
        return $this->consumerId;
    }

    /**
     * The changes, oldest first.
     *
     * @return list<OutboundChange>
     *
     * @since  2.3.0
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * The integrator took a change.
     *
     * @param int $id The change.
     *
     * @since  2.3.0
     */
    public function delivered(int $id): static
    {
        return $this->answer(id: $id, answer: 'delivered', reason: '');
    }

    /**
     * Try a change again later.
     *
     * @param int $id The change.
     * @param string $reason Why, for staff (never a credential).
     *
     * @since  2.3.0
     */
    public function retry(int $id, string $reason): static
    {
        return $this->answer(id: $id, answer: 'retry', reason: $reason);
    }

    /**
     * The integrator refuses a change for good.
     *
     * @param int $id The change.
     * @param string $reason Why, for staff (never a credential).
     *
     * @since  2.3.0
     */
    public function refused(int $id, string $reason): static
    {
        return $this->answer(id: $id, answer: 'refused', reason: $reason);
    }

    /**
     * The answers given, by change id.
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @since  2.3.0
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /**
     * Keep one answer of a change of the batch.
     *
     * @param int $id The change.
     * @param string $answer delivered, retry or refused.
     * @param string $reason Why.
     *
     * @throws LogicException When the change is not of the batch.
     *
     * @since  2.3.0
     */
    private function answer(int $id, string $answer, string $reason): static
    {
        foreach ($this->changes as $change) {
            if ($change->id === $id) {
                $this->answers[$id] = [$answer, substr($reason, 0, 255)];

                return $this;
            }
        }

        throw new LogicException('The change ' . $id . ' is not one of this batch.');
    }
}
