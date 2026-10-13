<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\Method;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;

/**
 * What a payment or shipping method is asked to show the shopper.
 *
 * The cart page offers every method it can, and a method may want to say
 * something about itself while it is being chosen: instalments, a warning, a
 * logo. It is told which method it is, how it was configured, whether it is the
 * one currently chosen, what the cart comes to, and (for the method whose
 * question the first four do not answer) the cart itself.
 *
 * The named fields are this METHOD's own facts: which one it is, how it was
 * configured, whether it is chosen, what it is called. Everything about the
 * CART is on $cart, including the money: a gateway drawing "3 x €16.07"
 * reads $cart->getSubtotal(). One value, one place to read it.
 *
 * THE NAME AND DESCRIPTION TRAVEL WITH IT. They are on the offered row the
 * dispatch is already holding, multilingual-resolved by the query that loaded
 * it, so carrying them costs nothing, and without them a plugin that wants to
 * print its own name has to boot an admin model to re-read a string it was
 * standing next to. One gateway's cart layout printed an empty <strong> for exactly
 * that reason.
 *
 * A RICH SNAPSHOT, NOT A LIVE HANDLE. Cart wraps a settled HydratedCart, whose properties are
 * private readonly and whose mutators return copies, so a plugin that keeps
 * this reference reads the cart AS IT WAS AT DISPATCH, not as it is later. That is
 * the property that makes "rendering must not mutate the cart" true by
 * construction rather than by convention, and it is also why nothing here can
 * re-enter the totals: HydratedCart is the settled state, never CartComputation.
 *
 * @since  2.0.0
 */
final class MethodViewRequest
{
    /**
     * @param Method $method The method being rendered, entire.
     * @param Cart $cart The cart being offered, entire.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly Method $method,
        public readonly Cart $cart,
    ) {
    }
}
