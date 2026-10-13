<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Joomla\CMS\Document\HtmlDocument;

defined('_JEXEC') or die;

/**
 * The cart, as the checkout page needs it.
 *
 * The page has to know two things in a fixed order: whether there is anything
 * to check out (which decides the layout, and must cost nothing) and then,
 * only for a cart with something in it and a visitor allowed to proceed, the
 * settled reading the panels render from. Both come from the same opened
 * cart, so a guest cart adopted on the way in is adopted once and re-clamped
 * once.
 *
 * Settling is the one terminal act, and it is not free of consequence: a
 * chosen method the cart can no longer offer is reconciled away, and the
 * corrected selection is written to the cart row before the reading is taken.
 *
 * @since  2.0.0
 */
final class CheckoutCart
{
    /** @since 2.0.0 */
    public function __construct(
        private readonly OpenedCart $cart,
        private readonly CartSnapshotFactory $snapshots,
    ) {
    }

    /**
     * Whether there is anything to check out. Costs nothing beyond the cart
     * already loaded: no totals, no contributions, no method plugins.
     *
     * @since  2.0.0
     */
    public function isEmpty(): bool
    {
        return $this->cart->hydrator->current()->isEmpty();
    }

    /**
     * The settled reading, with the offered methods reconciled and asked what
     * to show.
     *
     * THE ORDER IS THE CONTRACT. Where the order is going is bound first:
     * the place, because every offered method is filtered by it and the same
     * destination must then be the one it is quoted against; the chosen methods
     * are reconciled against what is offered; the method plugins are asked what
     * to show, once, and only for a cart that has something to offer them; and
     * one reading is taken of all of it.
     *
     * A postcode is not bound here any more. The component prices nothing by
     * one and knows no field that holds one; a carrier that does names its own
     * field and reads it off the cart it is handed.
     *
     * @param int|null $placeId Where the checkout form says it is going.
     *
     * @since  2.0.0
     */
    public function settle(?int $placeId): CartSnapshot
    {
        $computation = $this->cart->computation;
        $computation->bindDeliveryPlace($placeId);
        $this->cart->reconciliation->reconcile();

        $state = $this->cart->hydrator->current();
        $empty = $state->isEmpty();
        $paymentViews = [];
        $shipmentViews = [];

        if (!$empty) {
            $methods = $computation->methods();
            $total = $computation->totals()->total();

            // THE PAYABLE AMOUNT, WHERE IT GENUINELY EXISTS. Carriage and the
            // cart-level charges are decided by the time the offers are drawn,
            // so this stage, and only this stage, can hand a gateway the
            // figure it will actually collect. Read once, before the plugins
            // are asked anything, so what they receive is prepared rather than
            // computed while they hold it.
            $payable = $computation->totals()->grandTotal();
            $paymentViews = $this->cart->plugins->methodViews('payment', $methods->payments(), $state->paymentId(), $total, $state, $payable);
            $shipmentViews = $this->cart->plugins->methodViews('shipment', $methods->shipments(), $state->shipmentId(), $total, $state, $payable);
        }

        return $this->snapshots->from($this->cart->hydrator->current(), $computation, $paymentViews, $shipmentViews);
    }

    /**
     * What the method plugins need on this page, declared while it is built.
     *
     * BEFORE THE HEAD, FROM THE PUBLISHED LIST, ON AN HTML RENDER ONLY. A page
     * cannot take a script once its head is compiled and a JSON answer has no
     * head; so this runs during the page render, asks every plugin behind a
     * published method rather than only the offered ones, and the AJAX tasks
     * never call it. An empty cart draws no panels and declares nothing.
     *
     * @param HtmlDocument $document The page being built.
     *
     *
     * @since  2.0.0
     */
    public function declareAssets(HtmlDocument $document): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $methods = $this->cart->computation->methods();

        $this->cart->plugins->methodAssets(kind: 'payment', published: $methods->published('payment'), document: $document);
        $this->cart->plugins->methodAssets(kind: 'shipment', published: $methods->published('shipment'), document: $document);
    }
}
