<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\KeptQuantity;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Closure;

defined('_JEXEC') or die;

/**
 * How much of one product this cart may hold.
 *
 * The rules are the quantity policy's; this gathers the two numbers the
 * policy may not look up (the inventory ceiling and what the cart already
 * holds of the same thing), hands them over, and tells the shopper what the
 * decision meant.
 *
 * @since  2.0.0
 */
final class LineQuantityClamp
{
    /** @since 2.0.0 */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartMessaging $messaging,
    ) {
    }

    /**
     * The quantity the cart may hold, after the policy has spoken.
     *
     * @param int $cartId The cart.
     * @param object $item The product.
     * @param int|null $combinationId The variant, when it has them.
     * @param int $quantity What was asked for.
     * @param int $excludeLineId A line not to count as already held.
     *
     * @since  2.0.0
     */
    public function allowed(int $cartId, object $item, ?int $combinationId, int $quantity, int $excludeLineId = 0): int
    {
        [$ceiling, $pooled] = $this->shelf(cartId: $cartId, item: $item, combinationId: $combinationId, excludeLineId: $excludeLineId);

        $decision = (new CartQuantityPolicy())->decide($item, $combinationId, $quantity, $ceiling, $pooled);

        $this->messaging->announce($decision);

        return $decision->quantity;
    }

    /**
     * The smallest quantity at or above a request that the cart may hold and
     * that is charged at the price of its own quantity tier.
     *
     * Asked when a quantity the policy allows is refused because its line
     * would charge nothing, and the refusal has to name one that would be
     * accepted. The shelf figures are gathered as for a decision and handed,
     * with the tiers, to {@see KeptQuantity::smallestCharged()}, which asks
     * the policy. Nothing is announced: this asks, it decides no write.
     *
     * @param int $cartId The cart.
     * @param object $item The product.
     * @param int|null $combinationId The variant, when it has them.
     * @param int $from The quantity asked for.
     * @param int[] $tierStarts Every quantity at which the product's unit may change.
     * @param Closure(int):string $unitAt The unit that prices a quantity, as the caller reads it.
     * @param Currency $currency The currency the line is charged in.
     * @param int $excludeLineId A line not to count as already held.
     *
     * @return int|null The quantity, or null when the cart may hold none that is charged.
     *
     * @since  2.0.0
     */
    public function smallestCharged(int $cartId, object $item, ?int $combinationId, int $from, array $tierStarts, Closure $unitAt, Currency $currency, int $excludeLineId = 0): ?int
    {
        [$ceiling, $pooled] = $this->shelf(cartId: $cartId, item: $item, combinationId: $combinationId, excludeLineId: $excludeLineId);

        return KeptQuantity::smallestCharged(
            item: $item,
            combinationId: $combinationId,
            from: $from,
            purchasableCeiling: $ceiling,
            pooledDraw: $pooled,
            tierStarts: $tierStarts,
            unitAt: $unitAt,
            currency: $currency,
        );
    }

    /**
     * The two figures the policy is handed: what the shelf can supply for the
     * unit, and what the cart's other lines already draw from it.
     *
     * @param int $cartId The cart.
     * @param object $item The product.
     * @param int|null $combinationId The variant, when it has them.
     * @param int $excludeLineId A line not to count as already held.
     *
     * @return array{0: float|null, 1: float} The ceiling (null when nothing limits it) and the pooled draw.
     *
     * @since  2.0.0
     */
    private function shelf(int $cartId, object $item, ?int $combinationId, int $excludeLineId): array
    {
        $itemId = (int) ($item->id ?? 0);

        $ceiling = (new InventoryService())
            ->purchasableCeiling(itemId: $itemId, combinationId: $combinationId ?: null);

        $pooled = 0.0;

        if ($ceiling !== null && $cartId > 0 && $itemId > 0) {
            $pooled = $this->repository->pooledQuantity($cartId, $itemId, $combinationId ?: null, $excludeLineId);
        }

        return [$ceiling, $pooled];
    }
}
