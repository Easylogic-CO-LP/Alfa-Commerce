<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\MethodChoice;
use Closure;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Bring the cart's chosen methods in line with what is offered right now.
 *
 * A chosen carrier no longer offered is dropped, with a notice unless the cart
 * is virtual; a sole survivor is chosen instead. Then the same for payment,
 * against the carrier that was just settled. Both columns written in one act
 * stamp the same instant.
 *
 * @since  2.0.0
 */
final class MethodReconciliation
{
    /**
     * @param CartRepository $repository For the one instant of the act.
     * @param CartComputation $computation The offered lists and their notices.
     * @param Closure $state (): HydratedCart - the cart as currently loaded.
     * @param CartRowWriter $rows Writes the settled choices.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartComputation $computation,
        private readonly Closure $state,
        private readonly CartRowWriter $rows,
    ) {
    }

    /**
     * @return array<string, string[]> The notices, keyed 'shipment' / 'payment'.
     *
     * @since  2.0.0
     */
    public function reconcile(): array
    {
        $methods = $this->computation->methods();
        $methods->clearNotices();

        $state = ($this->state)();

        if ($state->identity()->cartId <= 0 || $state->isEmpty()) {
            return $methods->allNotices();
        }

        // ONE INSTANT FOR ONE ACT. Reconciliation can write both columns:
        // drop a method, then take the only one left, and a clock read per
        // write would stamp the same act at up to four different times.
        $at = $this->repository->now();

        $shipments = $methods->shipments();
        $chosenShipment = $state->shipmentId();
        $shipmentChoice = MethodChoice::reconcile($shipments, $chosenShipment);

        if ($shipmentChoice->dropCurrent) {
            // A cart that stopped shipping drops its shipping method silently.
            if (!$state->isVirtual()) {
                $methods->addNotice('shipment', Text::sprintf(
                    'COM_ALFA_CART_SHIPMENT_NO_LONGER_AVAILABLE',
                    $methods->name('shipment', $chosenShipment),
                ));
            }

            $this->rows->applyShipment(0, $at);
            $chosenShipment = 0;
        }

        if ($shipmentChoice->chooseInstead !== null) {
            $chosenShipment = $shipmentChoice->chooseInstead;
            $this->rows->applyShipment($chosenShipment, $at);
        }

        // The carrier's own refusal
        // Being offered and being able to carry THIS cart are different
        // questions, and only the second can be answered by asking the carrier.
        // The offered list is settled by destination, group and line scope; a
        // locker network that cannot take a 30 kg item is still perfectly
        // offered by all of those, and until it was asked, the cart shipped at
        // 0.00 because a carrier with nothing to quote returned the same float
        // as a carrier shipping free.
        //
        // Asked once, of the chosen carrier only, and only after the list has
        // settled, so this never decides which methods are offered, and the
        // list never causes a quote. That is what keeps the two from calling
        // each other.
        if ($chosenShipment > 0) {
            $quote = $this->computation->shipmentQuote();

            if (!$quote->available) {
                // SILENCE IS NOT A REFUSAL, AND MUST NOT BE READ ALOUD AS ONE.
                // A carrier whose hook ran and answered nothing has said
                // nothing about this parcel, so telling the shopper it "cannot
                // carry this order" is a statement the shop has no grounds for,
                // and it sends the merchant to inspect a zone table that is not
                // at fault. The selection is still dropped, because there is no
                // price to charge; only the sentence differs.
                $methods->addNotice('shipment', match (true) {
                    !$quote->answered => Text::sprintf(
                        'COM_ALFA_CART_SHIPMENT_NOT_PRICED',
                        $methods->name('shipment', $chosenShipment),
                    ),
                    $quote->reasonKey !== '' => Text::sprintf(
                        'COM_ALFA_CART_SHIPMENT_CANNOT_CARRY_REASON',
                        $methods->name('shipment', $chosenShipment),
                        Text::_($quote->reasonKey),
                    ),
                    default => Text::sprintf(
                        'COM_ALFA_CART_SHIPMENT_CANNOT_CARRY',
                        $methods->name('shipment', $chosenShipment),
                    ),
                });

                $this->rows->applyShipment(0, $at);
                $chosenShipment = 0;
            }
        }

        $methods = $this->computation->methods();
        $payments = $methods->payments();
        $chosenPayment = ($this->state)()->paymentId();
        $paymentChoice = MethodChoice::reconcile($payments, $chosenPayment);

        if ($paymentChoice->dropCurrent) {
            $refusedByShipping = $chosenShipment > 0 && isset($methods->paymentsIgnoringShipping()[$chosenPayment]);

            $methods->addNotice('payment', $refusedByShipping
                ? Text::sprintf(
                    'COM_ALFA_CART_PAYMENT_NOT_AVAILABLE_WITH_SHIPMENT',
                    $methods->name('payment', $chosenPayment),
                    $methods->name('shipment', $chosenShipment),
                )
                : Text::sprintf(
                    'COM_ALFA_CART_PAYMENT_NO_LONGER_AVAILABLE',
                    $methods->name('payment', $chosenPayment),
                ));

            $this->rows->applyPayment(0, $at);
        }

        if ($paymentChoice->chooseInstead !== null) {
            $this->rows->applyPayment($paymentChoice->chooseInstead, $at);
        }

        return $this->computation->methods()->allNotices();
    }
}
