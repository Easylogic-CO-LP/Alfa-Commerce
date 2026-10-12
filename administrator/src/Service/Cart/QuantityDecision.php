<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

/**
 * What the quantity policy decided, and why.
 *
 * The number alone is not enough. A caller that only learns "3" cannot tell a
 * request corrected down to a sellable multiple from one cut short by the
 * shelf, and a zero could mean either "nothing left" or "less than we sell":
 * two different sentences to two different shoppers. So the answer carries the
 * reasons in the order the rules fired, and the caller reads them rather than
 * re-deriving the decision from the number it got back. It also carries the
 * smallest sale and the whole-unit step of the rules it applied, so a refusal
 * the rules alone cause can name the quantities that would be accepted.
 *
 * Immutable, and free of anything that could talk to a shopper.
 *
 * @since  1.10.0
 */
final class QuantityDecision
{
    /**
     * @param int $requested The quantity that was asked for.
     * @param int $quantity The quantity allowed; 0 refuses the
     *                      sale.
     * @param QuantityReason[] $reasons Why, in the order the rules fired.
     * @param float|null $ceiling What the shelf could supply this
     *                            cart after its other lines were
     *                            counted; null when nothing limits
     *                            it.
     * @param int|null $saleMinimum The smallest quantity the rules
     *                              applied sell, in whole units; null
     *                              when they sell none or were not
     *                              read.
     * @param int $saleIncrement The whole-unit step of the rules
     *                           applied; 1 when they were not read.
     *
     * @since  1.10.0
     */
    private function __construct(
        public readonly int $requested,
        public readonly int $quantity,
        public readonly array $reasons,
        public readonly ?float $ceiling,
        public readonly ?int $saleMinimum,
        public readonly int $saleIncrement,
    ) {
    }

    /**
     * @param int $requested The quantity that was asked for.
     * @param int $quantity The quantity allowed.
     * @param QuantityReason[] $reasons Why, in firing order.
     * @param float|null $ceiling The effective ceiling, or null.
     * @param int|null $saleMinimum The smallest sale of the rules
     *                              applied, or null.
     * @param int $saleIncrement The whole-unit step of the rules
     *                           applied.
     *
     *
     * @since  1.10.0
     */
    public static function of(
        int $requested,
        int $quantity,
        array $reasons = [],
        ?float $ceiling = null,
        ?int $saleMinimum = null,
        int $saleIncrement = 1,
    ): self {
        return new self(
            requested: $requested,
            quantity: $quantity,
            reasons: array_values(array: $reasons),
            ceiling: $ceiling,
            saleMinimum: $saleMinimum,
            saleIncrement: $saleIncrement,
        );
    }

    /**
     * @return bool Nothing may be sold at all.
     *
     * @since  1.10.0
     */
    public function refused(): bool
    {
        return $this->quantity <= 0;
    }

    /**
     * @return bool The allowed quantity is not the one asked for.
     *
     * @since  1.10.0
     */
    public function adjusted(): bool
    {
        return $this->quantity !== $this->requested;
    }

    /**
     * @param QuantityReason $reason The rule to ask about.
     *
     * @return bool Whether that rule fired.
     *
     * @since  1.10.0
     */
    public function because(QuantityReason $reason): bool
    {
        return \in_array($reason, $this->reasons, true);
    }

    /**
     * @return string[] The reason codes, in firing order.
     *
     * @since  1.10.0
     */
    public function reasonCodes(): array
    {
        return array_map(static fn (QuantityReason $r): string => $r->value, $this->reasons);
    }
}
