<?php

/**
 * Shipping cost calculation event.
 *
 * Fired by CartHelper::computeShipmentCosts() to let the active
 * shipment plugin provide shipping cost for the current cart.
 *
 * Plugins MUST call setShippingCost() with the tax-inclusive amount.
 * Plugins SHOULD call setShippingCostTaxExcl() with the tax-exclusive
 * amount when shipping is taxable. If not set, excl defaults to incl
 * (zero-tax shipping — the common case).
 *
 * Usage in plugin:
 *   public function onCalculateShippingCost($event): void
 *   {
 *       $cart   = $event->getCart();
 *       $method = $event->getMethod();
 *
 *       $costIncl = $this->calculateCost($cart, $method);
 *       $event->setShippingCost($costIncl);
 *
 *       // Optional: only if shipping is taxable
 *       $event->setShippingCostTaxExcl($costExcl);
 *   }
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use BadMethodCallException;

\defined('_JEXEC') or die;

class CalculateShippingCostEvent extends ShipmentsEvent
{
    /**
     * Constructor — requires 'method' argument.
     *
     * @param string $name Event name
     * @param array $arguments Must include 'method' and 'shippingCost'
     *
     * @throws BadMethodCallException If 'method' is missing
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('method', $this->arguments)) {
            throw new BadMethodCallException(
                "Argument 'method' of event {$name} is required but has not been provided",
            );
        }
    }

    /**
     * Get the cart helper instance (subject).
     *
     * @return \Alfa\Component\Alfa\Site\Helper\CartHelper
     */
    public function getCart()
    {
        return $this->getSubject();
    }

    /**
     * Setter validator for the 'method' argument.
     *
     * @param mixed $value Shipment method object
     * @return mixed
     */
    protected function onSetMethod($value)
    {
        return $value;
    }

    /**
     * Get the shipment method configuration.
     *
     * @return object Shipment method with params, type, etc.
     */
    public function getMethod()
    {
        return $this->arguments['method'];
    }

    // =====================================================================
    // SHIPPING COST — TAX INCLUSIVE
    // =====================================================================

    /**
     * Set shipping cost (tax inclusive).
     *
     * Plugins MUST call this. This is the primary shipping cost value.
     *
     * @param float $cost Shipping cost including tax
     */
    public function setShippingCost(float $cost): void
    {
        $this->arguments['shippingCost'] = $cost;
    }

    /**
     * Joomla event setter hook — delegates to setShippingCost().
     *
     * @param float $cost Shipping cost including tax
     */
    public function onSetShippingCost(float $cost): void
    {
        $this->setShippingCost($cost);
    }

    /**
     * Get shipping cost (tax inclusive).
     */
    public function getShippingCost(): float
    {
        return (float) ($this->arguments['shippingCost'] ?? 0.0);
    }

    // =====================================================================
    // SHIPPING COST — TAX EXCLUSIVE
    //
    // Optional. If the plugin doesn't set this, it defaults to the
    // tax-inclusive value (assumes zero tax on shipping — the common case).
    // =====================================================================

    /**
     * Set shipping cost (tax exclusive).
     *
     * Call this when shipping is taxable and you know the pre-tax amount.
     * If not called, getShippingCostTaxExcl() returns the incl value
     * (zero-tax default).
     *
     * @param float $cost Shipping cost excluding tax
     */
    public function setShippingCostTaxExcl(float $cost): void
    {
        $this->arguments['shippingCostTaxExcl'] = $cost;
    }

    /**
     * Get shipping cost (tax exclusive).
     *
     * Falls back to tax-inclusive value if not explicitly set.
     */
    public function getShippingCostTaxExcl(): float
    {
        // If plugin set excl explicitly, use it
        if (isset($this->arguments['shippingCostTaxExcl'])) {
            return (float) $this->arguments['shippingCostTaxExcl'];
        }

        // Default: same as incl (zero tax on shipping)
        return $this->getShippingCost();
    }
}
