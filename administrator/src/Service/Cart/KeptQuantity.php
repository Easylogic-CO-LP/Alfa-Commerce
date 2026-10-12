<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Closure;

defined('_JEXEC') or die;

/**
 * The smallest quantity the cart's quantity policy keeps from a floor up.
 *
 * Asked when a quantity the policy allows is refused for another reason and
 * the refusal has to name one that would be accepted: the product's own
 * smallest sale when the floor is below it, the next quantity it is sold in
 * otherwise, and none when its maximum or the shelf ends below the floor.
 *
 * The policy decides a request; it does not list what it sells. Its answer
 * never falls as the request rises, and a quantity it answers is one it
 * keeps, so the first request it answers with at least the floor is found by
 * halving the range up to the line capacity, and its answer to that request
 * is the quantity. The rules themselves are never read here, and nothing is
 * decided by it: no quantity is stored from this answer.
 *
 * Pure, as the policy is: it is given the two shelf figures and looks nothing
 * up.
 *
 * @since  1.14.0
 */
final class KeptQuantity
{
    /**
     * The most whole units one cart line holds: the capacity of its quantity
     * column, and so the largest request worth putting to the policy.
     *
     * @since  1.14.0
     */
    private const LINE_CAPACITY = 4294967295;

    /**
     * The smallest quantity the policy keeps that is at least a floor.
     *
     * @param object $item The product, with its quantity rules and combinations.
     * @param int|null $combinationId The variant, when it has them.
     * @param int $floor The quantity it must reach.
     * @param float|null $purchasableCeiling What the shelf can supply; null when nothing limits it.
     * @param float $pooledDraw What the cart already draws from the same pool on its other lines.
     *
     * @return int|null The quantity, or null when the policy keeps none that large.
     *
     * @since  1.14.0
     */
    public static function smallestFrom(object $item, ?int $combinationId, int $floor, ?float $purchasableCeiling, float $pooledDraw): ?int
    {
        $policy = new CartQuantityPolicy();
        $kept = static fn (int $requested): int => $policy->decide($item, $combinationId, $requested, $purchasableCeiling, $pooledDraw)->quantity;

        // A line holds one unit or more, whatever the floor.
        $least = max(1, $floor);
        $low = $least;
        $high = self::LINE_CAPACITY;

        if ($low > $high || $kept($high) < $least) {
            return null;
        }

        while ($low < $high) {
            $middle = $low + intdiv($high - $low, 2);

            if ($kept($middle) >= $least) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $kept($low);
    }

    /**
     * The smallest quantity at or above a request that the policy keeps and
     * that is charged at the price of its own quantity tier.
     *
     * A product priced in quantity tiers has a unit per tier, so whether a
     * quantity is charged depends on the tier it falls in. Between two tier
     * starts the unit is one, and the quantities it charges are those from
     * one figure up ({@see ChargeableQuantity::smallest()}). So the tiers are
     * walked in order from the request: in each, the first quantity charged
     * at its unit is lifted to the first one the policy keeps, and that is the
     * answer while it is still inside the tier. When it lies beyond, every
     * quantity between was either not charged or not kept, and the walk goes
     * on from it. Each step passes a tier start or reaches a kept quantity, so
     * the walk ends within one step more than there are tier starts.
     *
     * @param object $item The product, with its quantity rules and combinations.
     * @param int|null $combinationId The variant, when it has them.
     * @param int $from The quantity asked for.
     * @param float|null $purchasableCeiling What the shelf can supply; null when nothing limits it.
     * @param float $pooledDraw What the cart already draws from the same pool on its other lines.
     * @param int[] $tierStarts Every quantity at which the unit may change: the first quantity
     *                          of each tier and the one after its last. More is harmless.
     * @param Closure(int):string $unitAt The unit that prices a quantity, as the caller reads it.
     * @param Currency $currency The currency the line is charged in.
     *
     * @return int|null The quantity, or null when the policy keeps none that is charged, up to the line capacity.
     *
     * @since  1.14.0
     */
    public static function smallestCharged(object $item, ?int $combinationId, int $from, ?float $purchasableCeiling, float $pooledDraw, array $tierStarts, Closure $unitAt, Currency $currency): ?int
    {
        $starts = array_values(array_unique(array_filter(
            array_map('intval', $tierStarts),
            static fn (int $start): bool => $start > 1 && $start <= self::LINE_CAPACITY,
        )));
        sort($starts);

        $at = max(1, $from);

        while ($at <= self::LINE_CAPACITY) {
            // The last quantity of the tier the walk stands in.
            $end = self::LINE_CAPACITY;

            foreach ($starts as $start) {
                if ($start > $at) {
                    $end = $start - 1;

                    break;
                }
            }

            $charge = ChargeableQuantity::ofUnit(unit: $unitAt($at), currency: $currency);
            $charged = $charge->refuses(quantity: $at) ? $charge->smallest() : $at;

            if ($charged > $end) {
                $at = $end + 1;

                continue;
            }

            $kept = self::smallestFrom(item: $item, combinationId: $combinationId, floor: $charged, purchasableCeiling: $purchasableCeiling, pooledDraw: $pooledDraw);

            // Nothing from this figure up is kept, and every later tier starts higher.
            if ($kept === null) {
                return null;
            }

            if ($kept <= $end) {
                return $kept;
            }

            $at = $kept;
        }

        return null;
    }
}
