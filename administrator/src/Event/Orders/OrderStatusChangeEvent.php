<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Order Status Change Event
 *
 * Dispatched by Transition::save() AFTER the transition has fully
 * persisted and every core side effect (stock, download entitlements,
 * activity log, email) has run; listeners always observe the FINAL state.
 *
 * READ-ONLY BY CONTRACT: this event deliberately defines NO set* methods and
 * extends AbstractImmutableEvent: listeners can observe the transition, they
 * can never alter it (and no setX magic can silently swallow constructor
 * arguments). To react, listeners do their own work (ledger entries,
 * documents, subscription state); they do not steer the transition itself.
 *
 * A listener exception NEVER blocks the transition: the dispatcher call site
 * catches Throwable and logs plugin/order/old->new/stack trace.
 *
 * Consumers: subscriptions plugin (activate/cancel on family entry), sales
 * documents plugin, vendor ledger accrual.
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Orders;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class OrderStatusChangeEvent extends AbstractImmutableEvent
{
    /**
     * The order row (`#__alfa_orders` columns) AFTER the transition.
     *
     * Row-level data only; listeners needing lines/payments query on demand.
     *
     * @since  2.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * Status id the order left.
     *
     * @since  2.0.0
     */
    public function getPreviousStatusId(): int
    {
        return (int) $this->arguments['previousStatusId'];
    }

    /**
     * Status id the order entered.
     *
     * @since  2.0.0
     */
    public function getNewStatusId(): int
    {
        return (int) $this->arguments['newStatusId'];
    }

    /**
     * The order just ENTERED the is_completed family (was not in it before).
     *
     * @since  2.0.0
     */
    public function enteredCompleted(): bool
    {
        return (bool) $this->arguments['enteredCompleted'];
    }

    /**
     * The order just ENTERED the is_cancelled family.
     *
     * @since  2.0.0
     */
    public function enteredCancelled(): bool
    {
        return (bool) $this->arguments['enteredCancelled'];
    }

    /**
     * The order just LEFT the is_completed family.
     *
     * @since  2.0.0
     */
    public function leftCompleted(): bool
    {
        return (bool) $this->arguments['leftCompleted'];
    }

    /**
     * The order just LEFT the is_cancelled family (forced admin act).
     *
     * @since  2.0.0
     */
    public function leftCancelled(): bool
    {
        return (bool) $this->arguments['leftCancelled'];
    }

    /**
     * Who drove the transition: "user:<id>" or "plugin:<element>".
     *
     * @since  2.0.0
     */
    public function getActor(): string
    {
        return (string) $this->arguments['actor'];
    }

    /**
     * Free-text transition note (may be '').
     *
     * @since  2.0.0
     */
    public function getNote(): string
    {
        return (string) $this->arguments['note'];
    }

    /**
     * UTC SQL datetime the transition ran.
     *
     * @since  2.0.0
     */
    public function getTransitionTime(): string
    {
        return (string) $this->arguments['transitionTime'];
    }
}
