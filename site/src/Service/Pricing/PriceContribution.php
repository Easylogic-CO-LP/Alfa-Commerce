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
 * PriceContribution: one declarative price line a pricing plugin returns from
 * onCalculateItemPrice (the Stage-B "code" layer on top of the data-driven
 * combination/option deltas). The engine folds each in `sortOrder` through the
 * SAME primitive the option deltas use
 * (PriceComputationEngine::applyVariantAdjust), and each becomes a labeled step
 * in the price breakdown.
 *
 * Declarative by design: the plugin computes `operation` + `amount` from its own
 * config and the buyer's payload (NOT from the running net), so contributions
 * can be gathered before the net is known and composed across plugins. A
 * net-relative effect is expressed declaratively as a percent operation, which
 * the engine then applies to the net.
 *
 * @since  2.0.0
 */
final class PriceContribution
{
    /**
     * @param string $code Stable id for the line, e.g. 'configurator3d.images'.
     * @param string $label Human breakdown label, e.g. '3 images'.
     * @param string $operation '+', '-', '%' or '-%' (same vocabulary as option deltas).
     * @param float $amount Magnitude: currency for +/-, percent for %/-%.
     * @param string $target 'start' (apply on net) or 'final' (apply on gross).
     * @param int $sortOrder Fold order; lower folds first.
     * @param string $source Owning plugin element (the collector fills it when empty).
     * @param bool $suppliesBase The plugin's contributions are the whole price of the product: a product with no
     *                           price row that applies is priced from them. Without it, a product with no price row is
     *                           not priced whatever its contributions add, as it is not priced whatever its options add.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $operation,
        public readonly float $amount,
        public readonly string $target = 'final',
        public readonly int $sortOrder = 0,
        public readonly string $source = '',
        public readonly bool $suppliesBase = false,
    ) {
    }

    /**
     * The same contribution with its owning plugin named: the collector's copy for a contribution whose source is
     * empty, every other field kept.
     *
     * @param string $source The owning plugin element.
     *
     * @since  2.2.2
     */
    public function withSource(string $source): self
    {
        return new self(
            code:         $this->code,
            label:        $this->label,
            operation:    $this->operation,
            amount:       $this->amount,
            target:       $this->target,
            sortOrder:    $this->sortOrder,
            source:       $source,
            suppliesBase: $this->suppliesBase,
        );
    }
}
