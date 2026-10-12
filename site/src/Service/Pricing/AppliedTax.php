<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

/**
 * Applied Tax
 *
 * Represents a tax that has been applied to a price
 * @since  1.0.0
 */
class AppliedTax
{
    public int $id;
    public string $name;
    public float $rate;         // Tax rate as percentage
    public string $jurisdiction;

    /**
     * The money this rate charged, once the engine's chain has run.
     *
     * Defaulted so a typed property is never uninitialised on an instance that
     * did not come from the constructor (unserialize, reflection, a fixture).
     *
     * @since  1.10.0
     */
    public ?Money $amount = null;

    public function __construct(
        int $id,
        string $name,
        float $rate,
        string $jurisdiction,
        ?Money $amount = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->rate = $rate;
        $this->jurisdiction = $jurisdiction;
        $this->amount = $amount;
    }

    /**
     * The same applied tax, carrying the money it actually produced.
     *
     * Rates are resolved before the base price is known, so the money is attached
     * by the engine's tax chain afterwards rather than at construction.
     *
     * @param Money $amount The tax this rate charged on this line.
     *
     *
     * @since  1.10.0
     */
    public function withAmount(Money $amount): self
    {
        return new self($this->id, $this->name, $this->rate, $this->jurisdiction, $amount);
    }

    /**
     * Export the applied tax as a plain array.
     *
     * The rate is its decimal text, read at the fifteen significant digits the
     * engine's float carries (every stored rate, and a sum of them, is stated
     * exactly), and the amount money text ({@see PublishedDecimal}).
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rate' => PublishedDecimal::decimal(ExactAmount::ofNumber($this->rate)),
            'jurisdiction' => $this->jurisdiction,
            // What this rate charged; null for a tax that never went through compute().
            'amount' => $this->amount === null ? null : PublishedDecimal::money($this->amount),
        ];
    }
}
