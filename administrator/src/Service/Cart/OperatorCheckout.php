<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Checkout\OrderPlacement;
use Alfa\Component\Alfa\Site\Service\Checkout\PlacementCart;
use Alfa\Component\Alfa\Site\Service\Checkout\PlacementCommand;
use Joomla\CMS\User\User;

defined('_JEXEC') or die;

/**
 * The boundary staff cross to place an order for somebody else.
 *
 * The administrator's cart.checkout task calls this and nothing else calls it.
 * That is the point of it existing separately from the controller: the contract
 * around an operator placement has one executable home, and it can be exercised
 * without a request.
 *
 * It exists at all because "the button is hidden" is not a permission. The
 * component already has a screen whose write endpoint accepts a weaker
 * permission than the control that posts to it, which is what happens when
 * authorisation lives in a template. So the check lives here, in front of the
 * work, where a crafted request meets it too, and the eventual controller
 * calls this rather than deciding for itself.
 *
 * The permission is alfa.carts.checkout and it is asked STRICTLY. It is not
 * ORed with the right that merely admits a person to com_alfa's back office, so
 * nobody who can only see the orders list can also charge a customer, and the
 * action documents a restriction it actually imposes. A cart a STOREFRONT
 * SHOPPER created asks alfa.carts.edit as well: placing it consumes the cart
 * the shopper is using ({@see CartTakeover}).
 *
 * Everything the placement needs is named by the caller: the cart, the
 * customer, the currency, the place, the language. None of it is discovered,
 * because every one of those has an ambient source that would answer with the
 * OPERATOR's version of it.
 *
 * @since  1.10.0
 */
final class OperatorCheckout
{
    public const PERMISSION = 'alfa.carts.checkout';

    /**
     * The delivery place named by the typed details, if any.
     *
     * Uses the same field lookup placement uses, so the two cannot disagree
     * about which field carries a place.
     *
     * @param array $delivery The typed delivery details.
     *
     *
     * @since  1.10.0
     */
    private static function placeFromDelivery(array $delivery): ?int
    {
        $field = FieldsHelper::deliveryPlaceField();

        if ($field === null) {
            return null;
        }

        $placeId = (int) ($delivery[$field->field_name] ?? 0);

        return $placeId > 0 ? $placeId : null;
    }

    /**
     * May this person place orders on a customer's behalf?
     *
     * CHECKOUT AUTHORITY ONLY, AND THAT IS NOT THE WHOLE ANSWER FOR place():
     * placing a cart a storefront shopper created additionally requires
     * {@see CartTakeover::authorized()}, which place() asks for itself. This
     * method answers the narrower question a caller asks to decide whether to
     * offer operator checkout at all.
     *
     * @param User $actor The member of staff.
     *
     *
     * @since  1.10.0
     */
    public static function authorized(User $actor): bool
    {
        // self::PERMISSION names alfa.carts.checkout; asked through the one Alfa check.
        return AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor);
    }

    /**
     * Place an order for a customer, as staff.
     *
     * Answers the placement's own verdict; ask the returned OrderPlacement for
     * getFailures() to learn why a refusal happened, in keys a caller can
     * translate for whoever is reading.
     *
     * @param User $actor The member of staff, who must hold the permission.
     * @param User $customer Whose order this is.
     * @param int $cartId The cart, named explicitly, never discovered.
     * @param array $delivery Typed delivery details.
     * @param array $billing Typed invoice details, or [].
     * @param int $payment The chosen payment method id.
     * @param int $shipment The chosen shipment method id.
     * @param string|null $language The customer's language tag.
     * @param int|null $placeId The delivery place, or null.
     * @param bool $useCredit Spend the customer's credit.
     * @param int $initialStatus The status the order should land in;
     *                           0 leaves the store's initial status.
     *
     * @return array{placed: bool, committed: bool, orderId: int, failures: array, warnings: array}
     *
     * @since  1.10.0
     */
    public static function place(
        User $actor,
        User $customer,
        int $cartId,
        array $delivery,
        array $billing = [],
        int $payment = 0,
        int $shipment = 0,
        ?string $language = null,
        ?int $placeId = null,
        bool $useCredit = false,
        int $initialStatus = 0,
    ): array {
        if (!self::authorized($actor)) {
            return self::refused([['code' => 'COM_ALFA_ACCESS_CARTS_CHECKOUT_DENIED', 'params' => []]]);
        }

        if ($cartId <= 0) {
            return self::refused([['code' => 'COM_ALFA_ORDER_PLACE_OPERATOR_CART_REQUIRED', 'params' => []]]);
        }

        // A SHOPPER'S CART IS TAKEN OVER, NOT MERELY PLACED. Placing consumes
        // the cart the shopper is using, so it is asked HERE, before the
        // carrier below is written to that cart, and where a caller that never
        // came through the controller meets it too.
        $source = CartTakeover::sourceOf($cartId);

        if ($source !== null && !CartTakeover::authorized(actor: $actor, source: $source)) {
            return self::refused([['code' => 'COM_ALFA_ACCESS_CARTS_EDIT_DENIED', 'params' => []]]);
        }

        // THE PLACE COMES OUT OF THE DELIVERY DETAILS, never out of the
        // operator's session. Placement derives it the same way from the same
        // field when it writes the order; deriving it here too is what lets the
        // cart offer the right shipping methods BEFORE one is chosen, which is
        // the only window in which that choice can still be made correctly.
        $placeId ??= self::placeFromDelivery($delivery);

        // The currency is deliberately NOT named here. The cart takes it off
        // the cart row before it prices anything, which is the only source that
        // is a fact about this order rather than about whoever is signed in.
        $subject = CartSubject::operator($customer, $actor)
            ->withMoneyContext(null, $placeId);

        // THE OPERATOR'S CARRIER IS WRITTEN FIRST, to the cart they named: it
        // is a fact about that cart whether or not the placement below
        // succeeds. Whether the write took is not this flow's question, and
        // never was.
        if ($shipment > 0) {
            CartService::forSubject($subject)->editCart($cartId)->chooseShipment($shipment, $placeId);
        }

        $cart = PlacementCart::named($cartId, $subject);

        if ($cart->cartId() !== $cartId) {
            // Refused by the ownership check, or gone.
            return self::refused([['code' => 'COM_ALFA_CART_LOAD_FAILED', 'params' => []]]);
        }

        if ($placeId !== null && $placeId > 0) {
            $cart->bindDeliveryPlace($placeId);
        }

        $placement = new OrderPlacement($cart, null, $subject);

        $placed = $placement->place(new PlacementCommand(
            delivery: $delivery,
            billing: $billing,
            paymentMethodId: $payment,
            shipmentMethodId: $shipment,
            useCredit: $useCredit,
            languageTag: $language,
            source: PlacementCommand::SOURCE_ADMIN,
            initialStatusId: $initialStatus,
        ));

        // THE OUTCOME, NOT THE BOOL. place() answers "did this work", which is
        // the wrong question the moment the commit has already happened: the
        // order exists whatever came after it, and the id has to come from the
        // commit rather than from getOrder(), which is a reload that can fail on
        // its own and leave a real order looking like no order at all.
        $outcome = $placement->getOutcome();

        $warnings = $outcome->warnings();

        // The order that consumed a shopper's cart, under the operator's name.
        // Recorded only on a commit, because only then did it happen.
        //
        // AND SAID OUT LOUD WHEN IT IS NOT THERE. The order is committed by the
        // time this runs, so a missing record cannot be repaired by refusing;
        // the only remaining duty is to tell the operator, exactly as every
        // mutating endpoint does. Silence here would make placement the one act
        // whose lost audit row nobody is told about, and it is the act the
        // owner singled out as needing its consequence made clearest.
        if ($source !== null && $outcome->isCommitted()) {
            $recorded = CartTakeover::record(
                source: $source,
                cartId: $cartId,
                actor: $actor,
                operation: 'checkout',
                facts: [
                    'order' => $outcome->orderId(),
                    'customer' => (int) $customer->id,
                    'orderlink' => 'index.php?option=com_alfa&task=order.edit&id=' . $outcome->orderId(),
                ],
            );

            if (!$recorded) {
                $warnings[] = ['code' => 'COM_ALFA_CART_PLACEMENT_NOT_RECORDED', 'params' => []];
            }
        }

        return [
            'placed' => $placed,
            'committed' => $outcome->isCommitted(),
            'orderId' => $outcome->orderId(),
            'failures' => $outcome->failures(),
            'warnings' => $warnings,
        ];
    }

    /**
     * A refusal, in the shape callers read.
     *
     * @param array $failures Why.
     *
     * @return array{placed: bool, committed: bool, orderId: int, failures: array, warnings: array}
     *
     * @since  1.10.0
     */
    private static function refused(array $failures): array
    {
        return ['placed' => false, 'committed' => false, 'orderId' => 0, 'failures' => $failures, 'warnings' => []];
    }
}
