<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Outbound;

\defined('_JEXEC') or die;

/**
 * One change a plugin consumer is told of ({@see OutboundDeliverEvent}): the change's id (unique, the same id a
 * webhook's body carries), its topic, what it is about, when it happened, how many attempts to tell it failed before,
 * and its values read now, as a webhook's body states them.
 *
 * @since  2.2.2
 */
final readonly class OutboundChange
{
    /**
     * @param int $id The change.
     * @param string $topic stock, price, order.created, order.status or order.dispute.
     * @param int $subjectId The product (stock, price) or the order.
     * @param int $unitId The combination; 0 for a product without combinations, and for an order.
     * @param string $occurredAt When the change was made, UTC, ISO 8601 with microseconds.
     * @param int $attempts The attempts that failed before this one.
     * @param array<string, mixed> $values The change as a webhook's body states it (its id, topic and moment among them).
     *
     * @since  2.2.2
     */
    public function __construct(
        public int $id,
        public string $topic,
        public int $subjectId,
        public int $unitId,
        public string $occurredAt,
        public int $attempts,
        public array $values,
    ) {
    }
}
