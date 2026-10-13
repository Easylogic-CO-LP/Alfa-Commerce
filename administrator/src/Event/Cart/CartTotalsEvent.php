<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartContribution;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartContributionBuilder;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ContributionRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Cart totals collection event: BOOT-AND-CALL, one fresh event per feature
 * plugin while the cart's totals are assembled. A plugin contributes
 * declarative CART-level charges through the fluent author (never HTML,
 * never a computed total, no artifact imports):
 *
 *   public function onCartTotals(CartTotalsEvent $event): void
 *   {
 *       $event->contribution('signup_fee')
 *           ->label(Text::_('PLG_..._SIGNUP_FEE_LABEL'))
 *           ->amount(1.00)
 *           ->taxRate(24.0);
 *   }
 *
 * The harvest materializes every builder: a half-written charge THROWS with
 * its code named, it never drops silently. The collector folds each result
 * into the cart totals (amount -> tax -> labeled segment), defaults the source
 * to the invoked plugin, and the checkout freezes the rows to
 * `#__alfa_order_contributions`. With no listeners the cart costs exactly
 * what it costs today.
 *
 * @since  2.0.0
 */
class CartTotalsEvent extends AbstractImmutableEvent
{
    /**
     * The charges authored during this dispatch, in the order they were added.
     *
     * Declared, and empty by default: a dispatch where nobody charges anything
     * is the ordinary case, and it has to answer with no charges rather than
     * with nothing at all.
     *
     * @var array<CartContribution|CartContributionBuilder>
     *
     * @since  2.0.0
     */
    protected array $contributions = [];

    /**
     * What this contributor is being asked to charge for.
     *
     * The cart's lines, and deliberately no money: contributions are folded
     * into the cart's total after they are collected, so a contributor that
     * could read that total would be reading a number it is about to change.
     *
     *
     * @since  2.0.0
     */
    public function getRequest(): ContributionRequest
    {
        return $this->getArgument('subject');
    }

    /**
     * The cart's lines, as values.
     *
     *
     * @since  2.0.0
     */
    public function getLines(): array
    {
        return $this->getRequest()->lines;
    }

    /**
     * The cart being charged against.
     *
     * A convenience over `getRequest()->cart`, for the contributor whose charge
     * depends on more than line ids and tax rates: a gift-wrap fee that only
     * applies to physical goods, a small-basket surcharge that must count units.
     *
     * STILL NO MONEY, AND THAT IS THE POINT. The cart handed over is the settled
     * state, not the computation: it can say what is in the basket and where it
     * is going, and it cannot say what the basket comes to. A contributor
     * reading the total it is about to change is exactly the cycle this event
     * was shaped to prevent, and handing over HydratedCart does not open it.
     *
     *
     * @since  2.0.0
     */
    public function getCart(): Cart
    {
        return $this->getRequest()->cart;
    }

    /**
     * Author one cart-level charge fluently.
     *
     * @param string $code Stable id for the line, e.g. 'signup_fee'.
     *
     *
     * @since  2.0.0
     */
    public function contribution(string $code): CartContributionBuilder
    {
        return $this->contributions[] = new CartContributionBuilder(code: $code);
    }

    /**
     * Contribute a ready-made artifact (programmatic callers).
     *
     * @param CartContribution $contribution The declarative charge.
     *
     *
     * @since  2.0.0
     */
    public function add(CartContribution $contribution): void
    {
        $this->contributions[] = $contribution;
    }

    /**
     * The contributed charges as immutable artifacts, insertion order.
     * Materializing a half-written builder throws (LogicException naming the
     * contribution); the collector surfaces it loudly in development.
     *
     * @return CartContribution[]
     *
     * @since  2.0.0
     */
    public function getContributions(): array
    {
        return array_map(
            static fn (CartContribution|CartContributionBuilder $entry): CartContribution => $entry instanceof CartContributionBuilder
                ? $entry->build()
                : $entry,
            $this->contributions,
        );
    }
}
