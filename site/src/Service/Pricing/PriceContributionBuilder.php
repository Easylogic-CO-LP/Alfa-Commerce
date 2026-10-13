<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use InvalidArgumentException;
use LogicException;

defined('_JEXEC') or die;

/**
 * Fluent author of ONE item price contribution: the event-side entry point
 * of onCalculateItemPrice, so a field plugin never imports or news the
 * artifact class and cannot hold an invalid line:
 *
 *   $event->contribution('configurator3d.area.0')
 *       ->label('3 images')
 *       ->add(5.00)          // OR ->subtract() / ->percent() / ->subtractPercent()
 *       ->onGross()          // default; ->onNet() applies before tax
 *       ->sortOrder(1);
 *
 * The operation vocabulary is the METHOD NAME: an invalid operation string
 * cannot exist. Magnitudes are always positive (the direction IS the
 * method). build() (run by the collector's harvest) throws naming the code
 * when a required part is missing, so a half-written line fails loudly
 * instead of silently dropping money.
 *
 * @since  2.0.0
 */
final class PriceContributionBuilder
{
    /**
     * @var string The breakdown label.
     * @since  2.0.0
     */
    private string $label = '';

    /**
     * @var string|null '+' | '-' | '%' | '-%', chosen by the typed op methods.
     * @since  2.0.0
     */
    private ?string $operation = null;

    /**
     * @var float The magnitude (currency for +/-, percent for %/-%).
     * @since  2.0.0
     */
    private float $amount = 0.0;

    /**
     * @var string 'start' (net) or 'final' (gross).
     * @since  2.0.0
     */
    private string $target = 'final';

    /**
     * @var int Fold order.
     * @since  2.0.0
     */
    private int $sortOrder = 0;

    /**
     * @param string $code Stable id for the line, e.g. 'configurator3d.images'.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly string $code)
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('A price contribution needs a non-empty code.');
        }
    }

    /**
     * The human breakdown label.
     *
     * @param string $label Translated label, e.g. '3 images'.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Add a fixed currency amount.
     *
     * @param float $amount Currency magnitude, > 0.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function add(float $amount): static
    {
        return $this->operation('+', $amount, 'add');
    }

    /**
     * Subtract a fixed currency amount.
     *
     * @param float $amount Currency magnitude, > 0.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function subtract(float $amount): static
    {
        return $this->operation('-', $amount, 'subtract');
    }

    /**
     * Add a percentage of the running price.
     *
     * @param float $percent Percent magnitude, > 0.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function percent(float $percent): static
    {
        return $this->operation('%', $percent, 'percent');
    }

    /**
     * Subtract a percentage of the running price.
     *
     * @param float $percent Percent magnitude, > 0.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function subtractPercent(float $percent): static
    {
        return $this->operation('-%', $percent, 'subtractPercent');
    }

    /**
     * Apply on the NET price (before tax).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function onNet(): static
    {
        $this->target = 'start';

        return $this;
    }

    /**
     * Apply on the GROSS price (after tax): the default.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function onGross(): static
    {
        $this->target = 'final';

        return $this;
    }

    /**
     * Fold order (lower folds first).
     *
     * @param int $sortOrder The order.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function sortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * Materialize the immutable artifact: the collector's harvest calls
     * this; a missing required part throws, naming the contribution.
     *
     *
     * @since  2.0.0
     */
    public function build(): PriceContribution
    {
        if ($this->label === '') {
            throw new LogicException($this->who() . ': missing label().');
        }

        if ($this->operation === null) {
            throw new LogicException($this->who() . ': missing add()/subtract()/percent()/subtractPercent().');
        }

        return new PriceContribution(
            code:      $this->code,
            label:     $this->label,
            operation: $this->operation,
            amount:    $this->amount,
            target:    $this->target,
            sortOrder: $this->sortOrder,
        );
    }

    /**
     * Record the operation: exactly one op method may run.
     *
     * @param string $operation The internal operation token.
     * @param float $amount The magnitude, > 0.
     * @param string $method The calling method name (for the message).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    private function operation(string $operation, float $amount, string $method): static
    {
        if ($this->operation !== null && $this->operation !== $operation) {
            throw new LogicException($this->who() . ': the operation methods are mutually exclusive.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                $this->who() . ': ' . $method . '() takes a positive magnitude, got ' . $amount
                . '; the direction is the method.',
            );
        }

        $this->operation = $operation;
        $this->amount = $amount;

        return $this;
    }

    /**
     * The builder's identity for error messages.
     *
     *
     * @since  2.0.0
     */
    private function who(): string
    {
        return 'Price contribution "' . $this->code . '"';
    }
}
