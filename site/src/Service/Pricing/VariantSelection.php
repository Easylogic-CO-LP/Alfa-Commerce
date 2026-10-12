<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * VariantSelection: the buyer's variant choice handed to the pricing engine.
 *
 * Carries the chosen purchasable combination (a row of #__alfa_item_combinations)
 * and any selected option value ids (#__alfa_item_field_values offered through
 * #__alfa_item_option_values). The engine turns this into the effective net
 * unit price: a combination price replaces the item base, a combination adjust
 * or an option delta shifts it, and the item's regular taxes and discounts then
 * apply through the unchanged pipeline.
 *
 * Immutable: a plain data holder with no behaviour beyond emptiness reporting.
 *
 * @since  1.3.0
 */
final class VariantSelection
{
    /**
     * @param int $combinationId #__alfa_item_combinations.id, or 0 for none.
     * @param int[] $optionValueIds Selected option value ids (each may carry a delta).
     * @param PriceContribution[] $contributions Plugin-computed declarative price lines (onCalculateItemPrice).
     *
     * @since  1.3.0
     */
    public function __construct(
        public readonly int $combinationId = 0,
        public readonly array $optionValueIds = [],
        public readonly array $contributions = [],
    ) {
    }

    /**
     * True when nothing is selected: pricing is identical to the plain item.
     * Plugin contributions count: a configured field with no combination/option
     * still changes the price, so it must route through the variant path.
     *
     * @since  1.3.0
     */
    public function isEmpty(): bool
    {
        return $this->combinationId <= 0 && $this->optionValueIds === [] && $this->contributions === [];
    }
}
