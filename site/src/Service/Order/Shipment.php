<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Site\Service\Data\PluginPayload;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;

/**
 * One shipment on an order, read.
 *
 * The parcel-side counterpart of {@see Order}: a carrier asks it what it is
 * carrying, where the parcel is, and what the carrier itself recorded about it.
 *
 *     $shipment->pluginData()->getString(key: 'locker_id', default: '')
 *
 * READ-ONLY. Writing goes through ShipmentLedger (`$this->shipmentUpdate($id)`
 * in a plugin), which is what keeps merging, validation and locking in the
 * persistence layer instead of in every caller.
 *
 * @since  1.10.0
 */
final class Shipment
{
    /**
     * @param object $row The `#__alfa_order_shipments` record.
     *
     * @since  1.10.0
     */
    private function __construct(private readonly object $row)
    {
    }

    /**
     * Read a shipment.
     *
     * Idempotent, for the same reason {@see Order::of()} is: services are handed
     * a row from the models and this reader from the events, and one canonical
     * type at those boundaries beats a type check at every entry.
     *
     * @param object $row The row, or a reader already made of one.
     *
     *
     * @since  1.10.0
     */
    public static function of(object $row): self
    {
        return $row instanceof self ? $row : new self($row);
    }

    /** @return int The shipment's own id. @since 1.10.0 */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    /** @return int The order it belongs to. @since 1.10.0 */
    public function orderId(): int
    {
        return (int) ($this->row->id_order ?? 0);
    }

    /** @return int The shipment method it was placed with, 0 when none. @since 1.10.0 */
    public function methodId(): int
    {
        return (int) ($this->row->id_shipment_method ?? 0);
    }

    /**
     * The plugin behind the method, as the order recorded it.
     *
     * Snapshotted so a shipment still says which carrier carried it after the
     * method row is deleted.
     *
     *
     * @since  1.10.0
     */
    public function methodType(): string
    {
        return trim((string) ($this->row->shipment_method_type ?? ''));
    }

    /** @return string pending, shipped, delivered, cancelled, on_hold or returned. @since 1.10.0 */
    public function status(): string
    {
        return trim((string) ($this->row->status ?? ''));
    }

    /**
     * The carrier's own handle for the parcel.
     *
     * Indexed, which is why a webhook resolves a shipment through it.
     *
     *
     * @since  1.10.0
     */
    public function trackingNumber(): string
    {
        return trim((string) ($this->row->tracking_number ?? ''));
    }

    /** @return float Total weight as the ledger computed it. @since 1.10.0 */
    public function weight(): float
    {
        return (float) ($this->row->weight ?? 0);
    }

    /** @return Money Carriage charged for this shipment, tax included. @since 1.10.0 */
    public function cost(): Money
    {
        return $this->money($this->row->shipping_cost_tax_incl ?? null);
    }

    /** @return Money Carriage charged for this shipment, tax excluded. @since 1.10.0 */
    public function costExcl(): Money
    {
        return $this->money($this->row->shipping_cost_tax_excl ?? null);
    }

    /**
     * What the carrier plugin recorded about this parcel.
     *
     *     $shipment->pluginData()->getString(key: 'locker_id', default: '')
     *     $shipment->pluginData()->has('parcel_id')
     *
     * The core never looks inside it. Write it back through the ledger, which
     * merges: `$this->shipmentUpdate($id)->pluginData([...])->save()`.
     *
     * @return PluginPayload Empty when the carrier has written nothing yet.
     *
     * @throws \Alfa\Component\Alfa\Site\Service\Data\ValueException
     *                                                               When what is stored is not readable JSON; never silently empty.
     *
     * @since  1.10.0
     */
    public function pluginData(): PluginPayload
    {
        return PluginPayload::of(
            $this->row->plugin_data ?? null,
            'shipment #' . $this->id() . ' plugin_data',
        );
    }

    /**
     * The row as the model loaded it.
     *
     * For the writers that still take one, and for a carrier reading a column
     * this reader has no accessor for yet.
     *
     *
     * @since  1.10.0
     */
    public function row(): object
    {
        return $this->row;
    }

    /**
     * A money value the model may have attached as Money or as a bare number.
     *
     * A bare number is money in the shipment's order's currency, and in no
     * other.
     *
     * @param mixed $value The stored figure.
     *
     *
     * @throws OrderCurrencyException When the figure is bare and the order names no currency.
     *
     * @since  1.10.0
     */
    private function money(mixed $value): Money
    {
        return $value instanceof Money ? $value : Money::of((float) $value, OrderCurrency::of(orderId: $this->orderId()));
    }
}
