<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * onAlfaDisputeChanged: a card dispute moved or its facts changed, told once after the committed save to every listener
 * of the alfa group (an ERP that must learn an order is owed again after a loss, a report). Listeners observe the books as
 * committed. A listener that throws is recorded and retried alone by staff (Run again under Tools, Tasks).
 *
 *     public function onAlfaDisputeChanged(DisputeChangedEvent $event): void
 *
 * @since  2.2.2
 */
final class DisputeChangedEvent extends AbstractImmutableEvent
{
    /**
     * The event's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'onAlfaDisputeChanged';

    /**
     * The dispute's record as committed: the whole row of #__alfa_order_disputes (among its columns id, id_order,
     * id_payment, state, outcome, stage, amount, stated_amount, stated_currency, reference, evidence_due_by, decided_at,
     * and gateway_response, the provider's last answer as stored); null when it no longer exists.
     *
     * @api READ ONLY: the record.
     *
     * @since  2.2.2
     */
    public function getDispute(): ?object
    {
        $record = $this->arguments['dispute'] ?? null;

        return $record === null ? null : (object) $record;
    }

    /**
     * The dispute's id.
     *
     * @since  2.2.2
     */
    public function getDisputeId(): int
    {
        return (int) $this->arguments['disputeId'];
    }

    /**
     * The order of the disputed payment.
     *
     * @since  2.2.2
     */
    public function getOrderId(): int
    {
        return (int) $this->arguments['orderId'];
    }

    /**
     * The disputed payment.
     *
     * @since  2.2.2
     */
    public function getPaymentId(): int
    {
        return (int) $this->arguments['paymentId'];
    }

    /**
     * The state before the change; '' for a new record.
     *
     * @since  2.2.2
     */
    public function getStateBefore(): string
    {
        return (string) $this->arguments['stateBefore'];
    }

    /**
     * The state after the change.
     *
     * @since  2.2.2
     */
    public function getStateAfter(): string
    {
        return (string) $this->arguments['stateAfter'];
    }

    /**
     * What the change booked in the order's currency, as decimal text: positive a chargeback (a loss), negative its
     * reversal, '0' when the books did not move.
     *
     * @since  2.2.2
     */
    public function getBooked(): string
    {
        return (string) $this->arguments['booked'];
    }
}
