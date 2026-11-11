<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Cart;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\SelectionContributorsInterface;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartContribution;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartContributionBuilder;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ContributionRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Log\Log;
use LogicException;

defined('_JEXEC') or die;

/**
 * Cart totals collection event (onCartTotals): ONE event for the whole sequence (2.2.0), delivered in turn to
 * each `alfa` plugin the CUSTOMER's view levels admit, in their order, through the plugin's own targeted declaration
 * (never the shared dispatcher), so a cart costs the same whoever operates it. A plugin contributes declarative
 * CART-level charges, each naming the plugin (never HTML, never a computed total, no artifact imports):
 *
 *   public function onCartTotals(CartTotalsEvent $event): void
 *   {
 *       $event->contribution('alfa.subscriptions', 'signup_fee')
 *           ->label(Text::_('PLG_..._SIGNUP_FEE_LABEL'))
 *           ->amount(1.00)
 *           ->taxRate(24.0);
 *   }
 *
 * The harvest materializes every builder: a half-written charge drops that
 * plugin's charges with an ERROR naming the plugin and the charge, never
 * silently. The collector folds each result into the cart totals (amount ->
 * tax -> labeled segment) with the source `group.element.code`, and the
 * checkout freezes the rows to `#__alfa_order_contributions`. A charge naming
 * another plugin than the one answering is dropped and logged. With no
 * contributor the cart costs exactly what it costs today.
 *
 * @since  2.0.0
 */
class CartTotalsEvent extends AbstractImmutableEvent implements SelectionContributorsInterface
{
    /**
     * The charges authored during this dispatch, in the order they were added.
     *
     * Declared, and empty by default: a dispatch where nobody charges anything
     * is the ordinary case, and it has to answer with no charges rather than
     * with nothing at all.
     *
     * @var list<array{0: PluginId, 1: CartContribution|CartContributionBuilder}>
     *
     * @since  2.0.0
     */
    protected array $contributions = [];

    /**
     * The plugin the selection loop is calling now; null outside it.
     *
     * @since  2.2.0
     */
    private ?PluginId $answeringPlugin = null;

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
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $code Stable id for the line, e.g. 'signup_fee'.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function contribution(string $contributor, string $code): CartContributionBuilder
    {
        $builder = new CartContributionBuilder(code: $code);
        $this->keep(PluginId::of($contributor), $builder);

        return $builder;
    }

    /**
     * Contribute a ready-made charge (2.2.0).
     *
     * @param string $contributor The contributing plugin, group.element (attribution, never an authorisation).
     * @param CartContribution $contribution The declarative charge.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function addTotalAdjustment(string $contributor, CartContribution $contribution): void
    {
        $this->keep(PluginId::of($contributor), $contribution);
    }

    /**
     * The plugin whose handler runs now in the selection loop; null once it returned.
     *
     * @internal The plugin helper calls it around each handler of a selection loop; a plugin never calls it.
     *
     * @param PluginId|null $plugin The plugin.
     *
     * @since  2.2.0
     */
    public function answering(?PluginId $plugin): void
    {
        $this->answeringPlugin = $plugin;
    }

    /**
     * The contributed charges as [contributor (group.element), CartContribution] pairs, insertion order. A plugin with
     * a half-written charge contributes nothing: its charges are dropped with an ERROR naming it and the charge.
     *
     * @return list<array{0: string, 1: CartContribution}>
     *
     * @since  2.0.0
     */
    public function getContributions(): array
    {
        $built = [];
        $refused = [];

        foreach ($this->contributions as [$owner, $entry]) {
            if (isset($refused[$owner->key()])) {
                continue;
            }

            try {
                $built[] = [$owner->key(), $entry instanceof CartContributionBuilder ? $entry->build() : $entry];
            } catch (LogicException $e) {
                $refused[$owner->key()] = true;
                Log::add('Cart totals: ' . $owner . ' contributed nothing: ' . $e->getMessage(), Log::ERROR, 'com_alfa.cart');
            }
        }

        return array_values(array_filter($built, static fn (array $pair): bool => !isset($refused[$pair[0]])));
    }

    /**
     * Keep a contribution, unless it names another plugin than the one answering.
     *
     * @param PluginId $owner The contributor it names.
     * @param CartContribution|CartContributionBuilder $entry The charge.
     *
     * @since  2.2.0
     */
    private function keep(PluginId $owner, CartContribution|CartContributionBuilder $entry): void
    {
        if ($this->answeringPlugin !== null && $this->answeringPlugin->key() !== $owner->key()) {
            Log::add('Cart totals: ' . $this->answeringPlugin . ' contributed a charge in the name of ' . $owner . '; dropped.', Log::WARNING, 'com_alfa.cart');

            return;
        }

        $this->contributions[] = [$owner, $entry];
    }
}
