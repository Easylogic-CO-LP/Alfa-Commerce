<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

use InvalidArgumentException;
use LogicException;

defined('_JEXEC') or die;

/**
 * Fluent author of ONE cart contribution: the event-side entry point, so a
 * plugin never imports or news the artifact class and cannot hold an invalid
 * charge:
 *
 *   $event->contribution('signup_fee')
 *       ->label(Text::_('PLG_..._SIGNUP_FEE_LABEL'))
 *       ->amount(1.00)      // OR ->percent(2.0) - typed, mutually exclusive
 *       ->taxRate(24.0);
 *
 * The operation vocabulary is the METHOD NAME (amount()/percent()): an
 * invalid operation string cannot exist. Each setter validates at the call
 * site (range, sign, double operation), and build(), run by the collector's
 * harvest, throws naming the code when a required part is missing, so a
 * half-written charge fails loudly instead of silently dropping money.
 *
 * @since  1.3.0
 */
final class CartContributionBuilder
{
    /**
     * @var string The totals label.
     * @since  1.3.0
     */
    private string $label = '';

    /**
     * @var string|null '+' | '%', chosen by amount()/percent(), exactly once.
     * @since  1.3.0
     */
    private ?string $operation = null;

    /**
     * @var float The magnitude (net currency for '+', percent for '%').
     * @since  1.3.0
     */
    private float $amount = 0.0;

    /**
     * @var float The contribution's own tax rate (percent).
     * @since  1.3.0
     */
    private float $taxRate = 0.0;

    /**
     * @var int Fold/display order.
     * @since  1.3.0
     */
    private int $sortOrder = 0;

    /**
     * @param string $code Stable id for the line, e.g. 'signup_fee'.
     *
     * @since  1.3.0
     */
    public function __construct(private readonly string $code)
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('A cart contribution needs a non-empty code.');
        }
    }

    /**
     * The human totals label.
     *
     * @param string $label Translated label, e.g. 'Sign-up fee'.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Charge a fixed NET currency amount.
     *
     * @param float $amount Tax-exclusive currency amount, > 0.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function amount(float $amount): static
    {
        $this->operation('+');

        if ($amount <= 0) {
            throw new InvalidArgumentException($this->who() . ': amount must be positive, got ' . $amount . '.');
        }

        $this->amount = $amount;

        return $this;
    }

    /**
     * Charge a percentage of what remains chargeable after the discounts: the
     * goods' net after the cart rules plus the shipping's net after the
     * shipping rules.
     *
     * @param float $percent Percent, > 0 and <= 100.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function percent(float $percent): static
    {
        $this->operation('%');

        if ($percent <= 0 || $percent > 100) {
            throw new InvalidArgumentException($this->who() . ': percent must be within (0, 100], got ' . $percent . '.');
        }

        $this->amount = $percent;

        return $this;
    }

    /**
     * The contribution's own tax rate.
     *
     * @param float $rate Percent, 0 to 100.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function taxRate(float $rate): static
    {
        if ($rate < 0 || $rate > 100) {
            throw new InvalidArgumentException($this->who() . ': tax rate must be within [0, 100], got ' . $rate . '.');
        }

        $this->taxRate = $rate;

        return $this;
    }

    /**
     * Fold/display order (lower first).
     *
     * @param int $sortOrder The order.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
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
     * @since  1.3.0
     */
    public function build(): CartContribution
    {
        if ($this->label === '') {
            throw new LogicException($this->who() . ': missing label().');
        }

        if ($this->operation === null) {
            throw new LogicException($this->who() . ': missing amount() or percent().');
        }

        return new CartContribution(
            code:      $this->code,
            label:     $this->label,
            operation: $this->operation,
            amount:    $this->amount,
            taxRate:   $this->taxRate,
            sortOrder: $this->sortOrder,
        );
    }

    /**
     * Record the operation: exactly one of amount()/percent() may run.
     *
     * @param string $operation '+' or '%'.
     *
     *
     * @since  1.3.0
     */
    private function operation(string $operation): void
    {
        if ($this->operation !== null && $this->operation !== $operation) {
            throw new LogicException($this->who() . ': amount() and percent() are mutually exclusive.');
        }

        $this->operation = $operation;
    }

    /**
     * The builder's identity for error messages.
     *
     *
     * @since  1.3.0
     */
    private function who(): string
    {
        return 'Cart contribution "' . $this->code . '"';
    }
}
