<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Orders;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * A storefront order has just been PLACED: broadcast, for anyone who cares.
 *
 * `onAlfaOrderPlaced`, fired by {@see \Alfa\Component\Alfa\Site\Service\Checkout\OrderPlacement}
 * at its terminal position: order, lines, payment and shipment records are all persisted, the
 * cart is cleared, the placement email is sent; listeners observe FINAL state, exactly the
 * discipline {@see \Alfa\Component\Alfa\Administrator\Service\Order\Transition} applies to
 * `onOrderStatusChange`.
 *
 * WHY THIS EXISTS WHEN `onOrderStatusChange` ALREADY DOES: placement deliberately does NOT run
 * the transition machinery (it is a "no status -> initial status" step that mimics only the
 * email), so no broadcast fires at the one moment that is unlike every later transition: THE
 * SHOPPER IS STILL IN THE REQUEST. A later status change happens in a payment gateway's
 * callback or an administrator's session, where the shopper's identity does not exist. Anything
 * that must observe the order *in the shopper's own context* (search-conversion attribution is
 * the first consumer) can only do it here.
 *
 * WHY IT IS A BROADCAST when `onOrderAfterPlace` already fires at placement: those are TARGETED
 * calls at the selected payment and shipment plugins, and they are constructive (they create
 * the payment/shipment records). This is an announcement: read-only, observed by whoever
 * subscribed, and a listener failure is swallowed by the dispatch site; it can never block or
 * alter a placement that has already happened.
 *
 * Line items are NOT carried: they live in `#__alfa_order_items` (`id_item`, `id_order`), and
 * a listener that wants them reads them; carrying a copy here would freeze a second shape of
 * the same rows into a contract.
 *
 * @since  2.0.0
 */
class OrderPlacedEvent extends AbstractImmutableEvent
{
    /**
     * @param object $order The persisted order row, as placement loaded it.
     * @param int $orderId The order id, separately so no listener guesses which property
     *                     of the row is the key.
     *
     * @since  2.0.0
     */
    public function __construct(
        protected object $order,
        protected int $orderId,
    ) {
        parent::__construct('onAlfaOrderPlaced', [
            'order' => $order,
            'orderId' => $orderId,
        ]);
    }

    /**
     * The placed order row.
     *
     * @return object
     *
     * @since  2.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->order);
    }

    /**
     * The placed order's id.
     *
     *
     * @since  2.0.0
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }
}
