<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Order Shipment Helper: Unified CRUD Engine + Fluent Builder
 *
 *  TWO APIs, ONE CLASS: accessible from anywhere
 *
 *  HIGH-LEVEL - Fluent Builder (recommended):
 *
 *    CREATE:  ShipmentLedger::for($order)->pending()->withAllItems()->cost(12.50)->save();
 *    TAXED:   ShipmentLedger::for($order)->pending()->withAllItems()->cost(10.00, 8.06, 24)->save();
 *    UPDATE:  ShipmentLedger::load($id)->shipped()->trackingNumber('TRACK123')->save();
 *    PLUGIN:  $this->shipment($order)->pending()->withAllItems()->cost(5)->save();
 *    PLUGIN:  $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
 *    ADMIN:   ShipmentLedger::for($order)->pending()->withItems([101])->cost(8)->save();
 *    CLI:     ShipmentLedger::load($id)->delivered()->save();
 *
 *  LOW-LEVEL - Static CRUD (escape hatch, batch operations):
 *
 *    ShipmentLedger::create(['id_order' => 1, 'status' => 'pending', ...]);
 *    ShipmentLedger::update(5, ['status' => 'shipped', 'tracking_number' => 'T123']);
 *    ShipmentLedger::delete(5, 1);
 *    ShipmentLedger::get(5);
 *    ShipmentLedger::getByOrder(1);
 *    ShipmentLedger::assignItems(5, 1, [101, 102]);
 *
 *  Auto-filled values:
 *
 *   By Builder (from $order):
 *     id_order, id_shipment_method, weight (SUM of items.weight x quantity)
 *
 *   By Status Shortcuts:
 *     ->shipped()   -> auto-sets 'shipped' timestamp
 *     ->delivered() -> auto-sets 'shipped' + 'delivered' timestamps
 *
 *   By static::create() (at DB save):
 *     added, id_employee, id_currency, shipment_method_name snapshot
 *
 * Path: administrator/components/com_alfa/src/Service/Order/ShipmentLedger.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Site\Service\Data\ValueException;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Pricing\AppliedTax;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Alfa\Component\Alfa\Site\Service\Shipping\ShipmentPricingContext;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingCharge;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingChargeBasis;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPriceEntryMode;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPriceEntryModeResolver;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingTaxResolver;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\UserFactoryInterface;
use RuntimeException;
use stdClass;
use Throwable;

class ShipmentLedger
{
    use LedgerSupport;

    //  CONSTANTS: Shipment Status
    //
    //  The ONLY valid values for #__alfa_order_shipments.status.
    //  Use builder shortcut methods for IDE autocomplete.

    /** @var string Created, not yet dispatched */
    public const STATUS_PENDING = 'pending';

    /** @var string Package dispatched to carrier */
    public const STATUS_SHIPPED = 'shipped';

    /** @var string Package received by customer */
    public const STATUS_DELIVERED = 'delivered';

    /** @var string Shipment cancelled before dispatch */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string Paused (stock issue, address verification, etc.) */
    public const STATUS_ON_HOLD = 'on_hold';

    /** @var string Package returned by customer or carrier */
    public const STATUS_RETURNED = 'returned';

    // Conditional-write verdicts ({@see self::updateIfStatus()})
    // Same vocabulary as PaymentLedger's, because the question is the same one:
    // did MY write land, or had the row already moved?

    /** @var int The write landed. */
    public const WRITE_OK = 1;

    /** @var int Another actor changed the status first; nothing was written. */
    public const WRITE_RACED = 0;

    /** @var int The write itself failed. */
    public const WRITE_FAILED = -1;

    //  CONSTANTS: Table Configuration

    /** @var string Database table for shipments */
    private const TABLE = '#__alfa_order_shipments';

    /** @var string Order lines, joined to parcels through SHIPMENT_ITEMS_TABLE */
    private const ITEMS_TABLE = '#__alfa_order_items';

    /** @var string Which lines ride in which parcel, and how many of each */
    private const SHIPMENT_ITEMS_TABLE = '#__alfa_order_shipment_items';

    /** @var string Primary key column */
    private const PK = 'id';

    /**
     * Nullable integer columns: form sends '', MySQL rejects for int.
     * Sanitized to null in saveToTable().
     */
    private const NULLABLE_INT_COLUMNS = ['id_order_invoice', 'id_employee'];

    /**
     * Nullable datetime columns: empty/zero -> null in saveToTable().
     */
    private const NULLABLE_DATE_COLUMNS = ['shipped', 'delivered'];

    /**
     * Nullable enum columns: none on shipments; declared for the
     * LedgerSupport persistence contract.
     */
    private const NULLABLE_ENUM_COLUMNS = [];

    //  BUILDER STATE (instance properties)

    /** @var array Data accumulator */
    protected array $data = [];

    /** @var object|null Order context (null in update mode) */
    protected ?Order $order = null;

    /** @var string 'create' or 'update' */
    protected string $mode = 'create';

    /** @var int|null Existing shipment PK (update mode) */
    protected ?int $shipmentId = null;

    /**
     * Item IDs to assign to this shipment.
     *   null  -> no item operation (default)
     *   []    -> explicitly empty (withNoItems)
     *   [1,2] -> specific items
     */
    protected ?array $itemQuantities = null;

    /**
     * The row's existing 'shipped' stamp, remembered by load() so
     * delivered() cannot overwrite it, WITHOUT putting it in $data,
     * which would defeat the no-op short-circuit in doBuilderUpdate().
     */
    protected ?string $existingShipped = null;

    //  Fluent builder API

    /**
     * CREATE mode: build a new shipment for an order.
     *
     * Auto-fills: id_order, id_shipment_method, weight (from items).
     *
     * Usage:
     *   ShipmentLedger::for($order)->pending()->withAllItems()->cost(12.50)->save();
     *   ShipmentLedger::for($order)->delivered()->withAllItems()->cost(0)->save();
     *
     * @param object $order The order: a loaded row, or the reader a plugin was handed.
     *
     * @return static Builder in create mode
     *
     * @since  1.0.0
     */
    public static function for(object $order): static
    {
        // READ THROUGH THE ORDER READER, NOT OFF THE ROW. A plugin is handed an
        // Order by $event->getOrder() and the admin hands a loaded row; both
        // arrive here, and reading `->id` off the first silently wrote every
        // shipment against order 0: a row nothing could find and no carriage
        // on the order it belonged to.
        $order = Order::of($order);

        $builder = new static();
        $builder->mode = 'create';
        $builder->order = $order;
        $builder->data = [
            'id_order' => $order->id(),
            'id_shipment_method' => $order->shipmentMethodId(),
            'weight' => self::calculateWeight($order),
        ];

        // At placement the order states how its carriage figures were formed;
        // the parcel the carrier writes from them keeps that. A parcel written
        // from an order that states none is the carrier's own statement.
        if ($order->shipmentChargeBasis() !== null) {
            $builder->data['shipping_charge_basis'] = $order->shipmentChargeBasis()->value;
        }

        // And the ordered rates its charge was calculated with.
        if ($order->shipmentTaxChain() !== null) {
            $builder->data['shipping_tax_chain'] = $order->shipmentTaxChain();
        }

        return $builder;
    }

    /**
     * UPDATE mode: modify an existing shipment.
     *
     * Loads existing shipment from DB to validate existence and get id_order.
     *
     * Usage:
     *   ShipmentLedger::load($id)->shipped()->trackingNumber('TRACK123')->save();
     *   ShipmentLedger::load($id)->delivered()->save();
     *
     * @param int $shipmentId Existing shipment row PK
     *
     * @return static Builder in update mode
     *
     * @throws RuntimeException If shipment not found
     *
     * @since  1.0.0
     */
    public static function load(int $shipmentId): static
    {
        $existing = self::getRaw($shipmentId);

        if (!$existing) {
            throw new RuntimeException(
                "ShipmentLedger::load(): Shipment #{$shipmentId} not found",
            );
        }

        $builder = new static();
        $builder->mode = 'update';
        $builder->shipmentId = $shipmentId;
        $builder->existingShipped = $existing->shipped ?? null;
        $builder->data = [
            'id_order' => (int) ($existing->id_order ?? 0),
        ];

        return $builder;
    }

    // Status Setters

    /**
     * Set status to PENDING: created, not dispatched.
     * @since  1.0.0
     */
    public function pending(): static
    {
        $this->data['status'] = self::STATUS_PENDING;
        return $this;
    }

    /**
     * Set status to SHIPPED: auto-sets 'shipped' timestamp.
     * @since  1.0.0
     */
    public function shipped(): static
    {
        $this->data['status'] = self::STATUS_SHIPPED;

        if (empty($this->data['shipped'])) {
            $this->data['shipped'] = Factory::getDate('now', 'UTC')->toSql();
        }

        return $this;
    }

    /**
     * Set status to DELIVERED: auto-sets 'shipped' + 'delivered' timestamps.
     *
     * Use for: digital products (instant delivery), local pickup.
     * @since  1.0.0
     */
    public function delivered(): static
    {
        $this->data['status'] = self::STATUS_DELIVERED;

        $now = Factory::getDate('now', 'UTC')->toSql();

        // Only stamp 'shipped' when the parcel never had one: going
        // straight to delivered (pickup, digital) is the case this covers.
        // In UPDATE mode the row's own stamp is the one that counts:
        // restamping it would rewrite when the parcel actually left.
        if (empty($this->data['shipped']) && empty($this->existingShipped)) {
            $this->data['shipped'] = $now;
        }
        if (empty($this->data['delivered'])) {
            $this->data['delivered'] = $now;
        }

        return $this;
    }

    /**
     * Set status to CANCELLED.
     * @since  1.0.0
     */
    public function cancelled(): static
    {
        $this->data['status'] = self::STATUS_CANCELLED;
        return $this;
    }

    /**
     * Set status to ON_HOLD.
     * Use for: waiting for stock, address verification, customs hold.
     * @since  1.0.0
     */
    public function onHold(): static
    {
        $this->data['status'] = self::STATUS_ON_HOLD;
        return $this;
    }

    /**
     * Set status to RETURNED.
     * @since  1.0.0
     */
    public function returned(): static
    {
        $this->data['status'] = self::STATUS_RETURNED;
        return $this;
    }

    // Item Assignment

    /**
     * Put every still-unshipped unit of the order in this parcel.
     *
     * "Still unshipped" is the whole point: on a fresh order that is
     * everything, and on an order that already has parcels it is only
     * what is left, so calling this on a second shipment cannot
     * double-ship a line.
     *
     * Works in UPDATE mode too. It used to silently clear every
     * assignment there, because load() carries no order object.
     *
     * @since  1.0.0
     */
    public function withAllItems(): static
    {
        $orderId = (int) ($this->data['id_order'] ?? ($this->order?->id() ?? 0));

        $this->itemQuantities = self::remainingQuantities(
            orderId: $orderId,
            exceptShipmentId: (int) ($this->shipmentId ?? 0),
        );

        return $this;
    }

    /**
     * Assign specific order LINES to this parcel, optionally with quantities.
     *
     * Two accepted shapes:
     *   ->withItems([101, 102])          - those lines, every unit still unshipped
     *   ->withItems([101 => 3, 102 => 1]) - exactly these many units of each
     *
     * A line may appear on several parcels, which is what makes
     * "3 now, 2 Monday" representable. Quantities are clamped to what is
     * actually left on the line, so an over-assignment is corrected, not
     * recorded.
     *
     * IDs are #__alfa_order_items.id: the ORDER LINE, not the product.
     *
     * @param array $items List of line PKs, or a [linePK => quantity] map
     * @since  1.0.0
     */
    public function withItems(array $items): static
    {
        $this->itemQuantities = self::normaliseItemMap($items);
        return $this;
    }

    /**
     * Create the parcel empty; lines get assigned later from the admin.
     * @since  1.0.0
     */
    public function withNoItems(): static
    {
        $this->itemQuantities = [];
        return $this;
    }

    // Data Setters

    /**
     * Set the shipping charge: its gross, its net and the VAT rate it was taxed at.
     *
     * A Money in the order's currency, or a decimal text, is written exactly;
     * a float is read as Money reads one. A charge stated without its net is
     * untaxed (the net is the gross, at rate 0). A taxed charge (gross above
     * net) names the rate it was taxed at, or it is refused when saved: the
     * rate cannot be recovered from the rounded amounts.
     *
     * @param Money|string|int|float $taxIncl Cost including tax
     * @param Money|string|int|float|null $taxExcl Cost excluding tax; null = untaxed (the gross)
     * @param string|int|float $taxRate VAT rate in percent, at most three places (24 = 24%)
     * @since  1.0.0
     */
    public function cost(Money|string|int|float $taxIncl, Money|string|int|float|null $taxExcl = null, string|int|float $taxRate = 0): static
    {
        $this->data['shipping_cost_tax_incl'] = $taxIncl;
        $this->data['shipping_cost_tax_excl'] = $taxExcl ?? $taxIncl;
        $this->data['shipping_tax_rate'] = $taxRate;
        return $this;
    }

    /**
     * Set carrier tracking number.
     *
     * @param string $number Carrier tracking reference
     * @since  1.0.0
     */
    public function trackingNumber(string $number): static
    {
        $this->data['tracking_number'] = $number;
        return $this;
    }

    // NOTE: there is deliberately no trackingUrl() setter, and no
    // tracking_url column. A tracking link is DERIVED from the carrier's URL
    // template (a shipment-method param) plus the stored tracking_number;
    // storing the built URL would leave every historic shipment holding a
    // dead link the day the carrier moves its endpoint. See the standard
    // shipment plugin's resolveTrackingUrl().

    /**
     * Set the carrier actually handling the parcel.
     *
     * Free text, independent of the shipment METHOD: one method ("Courier")
     * may hand parcels to one carrier today and another tomorrow, and the label on the
     * order has to say which.
     *
     * @param string $name Carrier name, e.g. 'Carrier A'
     * @since  1.3.0
     */
    public function carrier(string $name): static
    {
        $this->data['carrier_name'] = $name;
        return $this;
    }

    /**
     * Override the auto-calculated weight.
     *
     * @param float $weight Total weight in configured units
     * @since  1.0.0
     */
    public function weight(float $weight): static
    {
        $this->data['weight'] = $weight;
        return $this;
    }

    /**
     * Override the shipment method (normally from order).
     *
     * @param int $methodId Shipment method PK
     * @since  1.0.0
     */
    public function method(int $methodId): static
    {
        $this->data['id_shipment_method'] = $methodId;
        return $this;
    }

    /**
     * Escape hatch: set any column value directly.
     *
     * @param string $key Column name
     * @param mixed $value Value to set
     * @since  1.0.0
     */
    public function set(string $key, mixed $value): static
    {
        if ($key === 'plugin_data') {
            // Straight past the merge, the validation and the lock, and as a
            // pre-encoded string, which the next merge would double-encode.
            throw new ValueException(
                'ShipmentLedger::set(): write the payload with ->pluginData([...]) '
                . 'and clear keys with ->removePluginData([...]).',
            );
        }

        $this->data[$key] = $value;
        return $this;
    }

    // Terminal Operation

    /**
     * Validate and persist the shipment record.
     *
     * @return int|false Shipment ID on success, false on failure
     *
     * @throws RuntimeException If status not set (create mode)
     *
     * @since  1.0.0
     */
    public function save(): int|false
    {
        if ($this->mode === 'update') {
            return $this->doBuilderUpdate();
        }

        return $this->doBuilderCreate();
    }

    /**
     * Save, but ONLY while the row still holds an expected status.
     *
     * The conditional twin of {@see self::save()}, and the builder-side door
     * to {@see self::updateIfStatus()}. Answers a verdict rather than an id,
     * because "did my write land?" is the question a carrier webhook is
     * really asking: a redelivered notification must be able to tell
     * "I did it" from "someone already had".
     *
     * @param string $expectedStatus The status the row must still hold.
     *
     * @return int WRITE_OK | WRITE_RACED | WRITE_FAILED
     *
     * @since  1.10.0
     */
    public function saveVerdict(string $expectedStatus): int
    {
        if ($this->mode !== 'update' || !$this->shipmentId) {
            self::warn('saveVerdict(): only an update builder can compare-and-set');

            return self::WRITE_FAILED;
        }

        return self::updateIfStatus((int) $this->shipmentId, $this->toArray(), $expectedStatus);
    }

    // Introspection

    /**
     * Get all valid shipment status values.
     *
     * @return array ['pending', 'shipped', 'delivered', 'cancelled', 'on_hold', 'returned']
     * @since  1.0.0
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING, self::STATUS_SHIPPED, self::STATUS_DELIVERED,
            self::STATUS_CANCELLED, self::STATUS_ON_HOLD, self::STATUS_RETURNED,
        ];
    }

    /**
     * Get current builder mode.
     *
     * @return string 'create' or 'update'
     * @since  1.0.0
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Preview data without saving.
     * @since  1.0.0
     */
    public function toArray(): array
    {
        $data = $this->data;

        if ($this->itemQuantities !== null) {
            $data['items'] = $this->itemQuantities;
        }

        return $data;
    }

    // Builder Internal Methods

    /**
     * Execute CREATE flow.
     * @since  1.0.0
     */
    protected function doBuilderCreate(): int|false
    {
        if (empty($this->data['status'])) {
            throw new RuntimeException(
                'ShipmentLedger: status is required. '
                . 'Call ->pending(), ->shipped(), ->delivered(), ->cancelled(), '
                . '->onHold(), or ->returned() before ->save(). '
                . 'Valid values: ' . implode(', ', self::allStatuses()),
            );
        }

        // Attach item assignments for create()
        if ($this->itemQuantities !== null) {
            $this->data['items'] = $this->itemQuantities;
        }

        // No row to merge with yet, so the staged keys are the whole payload.
        $this->stagePluginDataForCreate();

        return static::create($this->data);
    }

    /**
     * Execute UPDATE flow.
     * @since  1.0.0
     */
    protected function doBuilderUpdate(): int|false
    {
        if (!$this->shipmentId) {
            throw new RuntimeException(
                'ShipmentLedger: No shipment ID for update. Use ::load($id) first.',
            );
        }

        // Attach items to data so update() handles assignment + logging
        if ($this->itemQuantities !== null) {
            $this->data['items'] = $this->itemQuantities;
        }

        $changes = array_diff_key($this->data, ['id_order' => true]);

        // A save whose only change is a payload key is still a save: the staged
        // keys are not in $this->data yet, because they are merged against the
        // stored row at write time rather than at build time.
        if (empty($changes) && !$this->hasStagedPluginData()) {
            return $this->shipmentId; // No-op
        }

        return $this->writeWithPluginData(
            $this->shipmentId,
            fn (): int|false => static::update($this->shipmentId, $this->data)
                ? $this->shipmentId
                : false,
        );
    }

    /**
     * Sum weight x quantity from order items.
     *
     * @param Order $order The order being shipped.
     * @since  1.0.0
     */
    protected static function calculateWeight(Order $order): float
    {
        $weight = 0.0;

        foreach ($order->items() as $item) {
            $weight += (float) ($item->weight ?? 0) * (int) ($item->quantity ?? 1);
        }

        return round($weight, 4);
    }

    //  Static CRUD engine

    /**
     * Create a new order shipment record.
     *
     * If 'items' key is present, assigns those order items after creation.
     *
     * The parcel records how its charge's figures were formed and the ordered
     * rates they were calculated with. A charge an operator or a REST client
     * enters is taxed with the shipping taxes the canonical resolver gives for
     * the order (as checkout resolves them); its stated rate must be exactly
     * their combined rate, and an untaxed entry stands only where none applies.
     * It is read under the shop's entry mode at that moment (tax included: the
     * gross is the input; tax excluded: the net is), and it must be exactly the
     * charge that input makes through that chain: 10.00 entered with tax
     * included at 24% is 8.06 + 1.94, and 8.07 or 8.05 excluding tax is
     * refused. The mode and the chain are frozen with it; such a writer never
     * chooses either. A carrier's write is taken as stated: with what its order
     * froze at placement, or as the carrier's own statement.
     *
     * @param array $data Shipment data. 'id_order' is REQUIRED.
     * @param bool $entered True for a charge an operator or a REST client entered.
     *
     * @return int|false New shipment row ID, or false on failure
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function create(array $data, bool $entered = false): int|false
    {
        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('create(): id_order is required');
            return false;
        }

        // A shipment's cost is money in its order's currency: an order that
        // names none takes no shipment row.
        OrderCurrency::of(orderId: $orderId);

        // Extract item assignments (not a DB column)
        $selectedItems = $data['items'] ?? [];
        unset($data['items']);

        // Ensure insert; an operator or a REST client never states how a charge was formed.
        unset($data[self::PK]);

        if ($entered) {
            unset($data['shipping_charge_basis'], $data['shipping_tax_chain']);
        }

        // A charge stated without its net is untaxed; the rate is read as a rate.
        $refusal = self::readCharge(data: $data, stored: null);

        if ($refusal !== null) {
            self::warn(message: $refusal, context: 'create()');
            return false;
        }

        // Money -> the column's decimal, refusing a currency the order is not in
        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl'] as $costField) {
            $stored = self::moneyForStorage(value: $data[$costField] ?? 0, orderId: $orderId, field: $costField);

            if ($stored === false) {
                return false;
            }

            $data[$costField] = $stored;
        }

        // The charge is judged as it was stated, before the charged-once rule
        // below can set it to nothing: an inexact entered charge is refused even
        // on a parcel that would go out at zero.
        $basis = $entered
            ? ShippingChargeBasis::ofMode(self::entryMode(methodId: (int) ($data['id_shipment_method'] ?? 0), orderId: $orderId))
            : self::carrierBasis(data: $data);
        $data['shipping_charge_basis'] = $basis?->value;
        $chain = $entered
            ? self::resolvedChain(methodId: (int) ($data['id_shipment_method'] ?? 0), orderId: $orderId)
            : self::carrierChain(data: $data);
        $data['shipping_tax_chain'] = $chain === null ? null : self::chainText(chain: $chain);
        $refusal = ($basis === null ? self::basisRefusal(data: $data) : null)
            ?? ($chain === null ? self::chainRefusal(data: $data) : null)
            ?? ($entered ? self::resolvedRateRefusal(data: $data, chain: $chain) : null)
            ?? self::chargeRefusal(data: $data, orderId: $orderId)
            ?? ($entered ? self::enteredChargeRefusal(data: $data, mode: $basis->mode(), chain: $chain, orderId: $orderId) : null);

        if ($refusal !== null) {
            self::warn(message: $refusal, context: 'create()');
            return false;
        }

        // Shipping is quoted ONCE per order
        // Order totals SUM the cost of every shipment row, so a second parcel
        // that carries the charge again silently raises the grand total and
        // flips a paid order back to 'partial'. Splitting a delivery is a
        // logistics decision, not a second sale; the charge rides the first
        // parcel and later ones go out at zero.
        //
        // Only create() is guarded. update() is a deliberate human edit in
        // the admin form, where the admin can see every row's cost and may
        // legitimately be correcting one.
        if (
            ($data['shipping_cost_tax_incl'] > 0 || $data['shipping_cost_tax_excl'] > 0)
            && self::orderAlreadyChargesShipping($orderId)
        ) {
            $data['shipping_cost_tax_incl'] = self::moneyForStorage(value: 0, orderId: $orderId, field: 'shipping_cost_tax_incl');
            $data['shipping_cost_tax_excl'] = self::moneyForStorage(value: 0, orderId: $orderId, field: 'shipping_cost_tax_excl');
            $data['shipping_tax_rate'] = StoredDecimal::of(ExactAmount::of('0'), precision: 10, scale: 3);
            $data['shipping_tax_chain'] = self::chainText(chain: []);

            self::warn(message: Text::sprintf('COM_ALFA_SHIPMENT_CHARGED_ONCE', $orderId), context: 'create()');
        }

        // Snapshot method name (survives deletion)
        if (!empty($data['id_shipment_method']) && empty($data['shipment_method_name'])) {
            $data['shipment_method_name'] = self::resolveMethodName(
                'Shipment',
                (int) $data['id_shipment_method'],
            );
        }

        // Snapshot the plugin element. Identity, not a label: always from
        // the id, so a stale posted field can never name the wrong plugin.
        if (!empty($data['id_shipment_method'])) {
            $data['shipment_method_type'] = self::resolveMethodType(
                'Shipment',
                (int) $data['id_shipment_method'],
            );
        }

        // Auto-fill defaults
        if (empty($data['added'])) {
            $data['added'] = Factory::getDate('now', 'UTC')->toSql();
        }
        if (empty($data['id_employee'])) {
            $data['id_employee'] = self::getCurrentUserId();
        }
        if (empty($data['id_currency']) && $orderId > 0) {
            $data['id_currency'] = OrderCurrency::idOf(orderId: $orderId);
        }

        $success = self::saveToTable($data);

        if (!$success) {
            return false;
        }

        $newId = (int) $data[self::PK];

        // Assign items
        if (!empty($selectedItems)) {
            self::assignItems($newId, $orderId, $selectedItems);
        }

        // Log activity
        $methodName = $data['shipment_method_name'] ?? '';
        $costIncl = number_format((float) $data['shipping_cost_tax_incl'], 2);
        $itemNames = self::getItemNames($newId);
        $logSuffix = $itemNames ? " [{$itemNames}]" : '';

        OrderModel::logOrderActivity(
            $orderId,
            'shipment.added',
            "Added shipment: {$methodName} - {$costIncl}{$logSuffix}",
            [
                'shipping_cost_tax_incl' => (float) $data['shipping_cost_tax_incl'],
                'shipping_cost_tax_excl' => (float) $data['shipping_cost_tax_excl'],
                'shipping_tax_rate' => (string) $data['shipping_tax_rate'],
                'shipping_charge_basis' => (string) $data['shipping_charge_basis'],
                'shipping_tax_chain' => (string) $data['shipping_tax_chain'],
                'shipment_method_name' => $methodName,
                'tracking_number' => $data['tracking_number'] ?? '',
                // What was actually recorded (post-clamp), as [linePK => qty];
                // $selectedItems may carry requested quantities, not line ids.
                'assigned_items' => self::getItemQuantities($newId),
            ],
            $newId,
        );

        return $newId;
    }

    /**
     * Read the charge a call states: net, gross and VAT rate, completed.
     *
     * A gross stated on a new parcel with no net beside it, or beside a net
     * left blank, is an untaxed charge: the net is the gross and the rate 0 (a
     * rate stated with no net is refused, there being no net to tax). On an
     * update a figure the call omits is the row's own, so what is judged is the
     * whole charge the row will hold; a figure stated as 0 is 0, never omitted.
     * The rate is read as a percentage of at most three places and written as
     * the column holds it. A call that carries none of the three changes no
     * charge.
     *
     * @param array $data The call's fields, completed in place.
     * @param object|null $stored The row being updated; null on create.
     *
     * @return string|null Why the charge cannot be stored, as the operator reads it; null when it can.
     *
     * @since  1.14.0
     */
    private static function readCharge(array &$data, ?object $stored): ?string
    {
        $carried = static fn (string $field): bool => \array_key_exists($field, $data);
        $blank = static fn (string $field): bool => $carried($field) && ($data[$field] === null || $data[$field] === '');

        if ($stored !== null && !$carried('shipping_cost_tax_incl') && !$carried('shipping_cost_tax_excl') && !$carried('shipping_tax_rate')) {
            return null;
        }

        $rate = $carried('shipping_tax_rate')
            ? self::rateText(value: $data['shipping_tax_rate'])
            : self::rateText(value: $stored !== null ? (string) ($stored->shipping_tax_rate ?? '0') : 0);

        if ($rate === null) {
            return Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_RATE_INVALID',
                \is_scalar($data['shipping_tax_rate']) ? (string) $data['shipping_tax_rate'] : get_debug_type($data['shipping_tax_rate']),
            );
        }

        $grossStated = $carried('shipping_cost_tax_incl') && !$blank('shipping_cost_tax_incl');

        if (!$grossStated && $stored !== null) {
            $data['shipping_cost_tax_incl'] = (string) ($stored->shipping_cost_tax_incl ?? '0');
        }

        if ($blank('shipping_cost_tax_excl') || ($stored === null && $grossStated && !$carried('shipping_cost_tax_excl'))) {
            if ($carried('shipping_tax_rate') && ExactAmount::of($rate)->isPositive()) {
                return Text::sprintf('COM_ALFA_SHIPMENT_CHARGE_RATE_WITHOUT_NET', ExactAmount::of($rate)->text());
            }

            $data['shipping_cost_tax_excl'] = $data['shipping_cost_tax_incl'] ?? 0;
            $rate = self::rateText(value: 0);
        } elseif (!$carried('shipping_cost_tax_excl') && $stored !== null) {
            $data['shipping_cost_tax_excl'] = (string) ($stored->shipping_cost_tax_excl ?? '0');
        }

        $data['shipping_tax_rate'] = $rate;

        return null;
    }

    /**
     * Whether a call changes the row's charge.
     *
     * @param array $data The call's fields, as they will be written.
     * @param object|null $stored The row being updated.
     *
     *
     * @since  1.14.0
     */
    private static function chargeChanges(array $data, ?object $stored): bool
    {
        if ($stored === null) {
            return true;
        }

        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl', 'shipping_tax_rate'] as $field) {
            if (!\array_key_exists($field, $data)) {
                continue;
            }

            if (ExactAmount::of((string) $data[$field])->compare(ExactAmount::of((string) ($stored->{$field} ?? '0'))) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a shipping charge may not be stored, or null when it may.
     *
     * Whoever states the figures (an operator, a carrier, a REST client), what
     * is refused is a charge that cannot be one: a net above its gross, or a
     * taxed charge (gross above net) that names no rate, whose invoice line
     * would state a tax at 0%.
     *
     * @param array $data The charge as it will be written (decimal texts).
     * @param int $orderId Its order, whose money the figures are shown in.
     *
     * @return string|null The refusal as the operator reads it; null when the charge may be stored.
     *
     * @since  1.14.0
     */
    private static function chargeRefusal(array $data, int $orderId): ?string
    {
        if (!isset($data['shipping_cost_tax_incl'], $data['shipping_cost_tax_excl'], $data['shipping_tax_rate'])) {
            return null;
        }

        $currency = OrderCurrency::of(orderId: $orderId);
        $incl = Money::of((string) $data['shipping_cost_tax_incl'], $currency);
        $excl = Money::of((string) $data['shipping_cost_tax_excl'], $currency);

        return match (true) {
            $excl->greaterThan($incl) => Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_NET_ABOVE_GROSS',
                self::shown(money: $excl),
                self::shown(money: $incl),
            ),
            $incl->greaterThan($excl) && !ExactAmount::of((string) $data['shipping_tax_rate'])->isPositive() => Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_TAXED_WITHOUT_RATE',
                self::shown(money: $incl),
                self::shown(money: $excl),
            ),
            default => null,
        };
    }

    /**
     * Why a charge an operator or a REST client entered is not the charge its
     * input makes, or null when it is.
     *
     * The charge is derived again from its input under the entry mode it is
     * entered on, through the ordered rates it is taxed with, by the
     * calculation that makes every shipping charge
     * (ShippingCharge::fromEntered()), and compared exactly: tax included, the
     * gross is the input, the net is the gross over the chain's factor rounded
     * once, and the VAT is what remains; tax excluded, the net is the input and
     * each rate charges once, rounded, on the exact running amount. Never the
     * combined rate. No entered charge is exempt.
     *
     * @param array $data The charge as it will be written.
     * @param ShippingPriceEntryMode $mode The entry mode it is entered on.
     * @param string[] $chain The rates it is taxed with, in order; empty when untaxed.
     * @param int $orderId Its order, whose money the figures are.
     *
     * @return string|null The refusal as the operator reads it, naming the figures the input makes; null when exact.
     *
     * @since  1.14.0
     */
    private static function enteredChargeRefusal(array $data, ShippingPriceEntryMode $mode, array $chain, int $orderId): ?string
    {
        $currency = OrderCurrency::of(orderId: $orderId);
        $gross = Money::of((string) $data['shipping_cost_tax_incl'], $currency);
        $net = Money::of((string) $data['shipping_cost_tax_excl'], $currency);
        $applied = self::applied(chain: $chain);
        $expected = ShippingCharge::fromEntered(
            entered: $mode === ShippingPriceEntryMode::TaxIncluded ? $gross : $net,
            mode: $mode,
            chain: $applied,
            rate: PriceComputationEngine::chainRate(appliedTaxes: $applied),
        );

        if ($expected->taxExcl->equals($net) && $expected->taxIncl->equals($gross)) {
            return null;
        }

        return $mode === ShippingPriceEntryMode::TaxIncluded
            ? Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_NOT_EXACT_INCLUDED',
                self::chainShown(chain: $chain),
                self::shown(money: $gross),
                self::shown(money: $expected->taxExcl),
                self::shown(money: $expected->tax),
                self::shown(money: $net),
            )
            : Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_NOT_EXACT_EXCLUDED',
                self::chainShown(chain: $chain),
                self::shown(money: $net),
                self::shown(money: $expected->tax),
                self::shown(money: $expected->taxIncl),
                self::shown(money: $gross),
            );
    }

    /**
     * The ordered rates a carrier's write says its charge was calculated with:
     * the chain its order froze at placement when the write carries it, and
     * otherwise the one rate the carrier states. Null when the write names a
     * chain that is none, or one whose combined rate is not the rate it states.
     *
     * @param array $data The carrier's write.
     *
     * @return string[]|null
     *
     * @since  1.14.0
     */
    private static function carrierChain(array $data): ?array
    {
        $rate = (string) $data['shipping_tax_rate'];

        if (!\array_key_exists('shipping_tax_chain', $data)) {
            return ExactAmount::of($rate)->isPositive() ? [ExactAmount::of($rate)->text()] : [];
        }

        $chain = self::chainOf(value: $data['shipping_tax_chain']);

        return $chain !== null && ($chain === [] ? !ExactAmount::of($rate)->isPositive() : self::chainRateText(chain: $chain) === $rate)
            ? $chain
            : null;
    }

    /**
     * The shipping taxes that apply to a new parcel of an order: the one
     * canonical resolver checkout taxes a carriage with
     * (ShippingTaxResolver::resolve()), asked with the context checkout builds.
     *
     * The same method; its destination read from the order's delivery address
     * by the cart's own address API (AddressView::places(), on the field the
     * method names, or the shop's); and the audience an operator placement
     * prices with (CartSubject::operator()->withMoneyContext()->priceContext():
     * the customer's groups and user, at this moment) in the order's currency.
     *
     * @param int $methodId The parcel's shipping method.
     * @param int $orderId Its order.
     *
     * @return string[] Exact decimal rate texts, in the order they apply; empty when no shipping tax applies.
     *
     * @since  1.14.0
     */
    private static function resolvedChain(int $methodId, int $orderId): array
    {
        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        )->loadObject();
        $params = $methodId > 0 ? $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__alfa_shipments'))
                ->where($db->quoteName('id') . ' = ' . $methodId),
        )->loadResult() : null;

        if ($row === null) {
            return [];
        }

        $order = Order::of($row);
        $destination = $order->delivery()->places(
            fieldId: FieldsHelper::methodDestinationFieldId(method: (object) ['params' => (array) json_decode((string) $params, true)]),
        );
        $currency = OrderCurrency::of(orderId: $orderId);
        $users = Factory::getContainer()->get(UserFactoryInterface::class);
        $audience = CartSubject::operator(
            customer: $users->loadUserById($order->customerId()),
            actor: Factory::getApplication()->getIdentity() ?? $users->loadUserById(0),
        )->withMoneyContext(currencyId: (int) $currency->getId(), placeId: $destination[0] ?? null)->priceContext();

        $chain = (new ShippingTaxResolver())->resolve(new ShipmentPricingContext(
            methodId: $methodId,
            destination: $destination,
            audience: $audience,
            currency: $currency,
        ));

        return array_map(static fn (AppliedTax $tax): string => ExactAmount::ofNumber($tax->rate)->text(), array_values($chain));
    }

    /**
     * Why an operator's change of an existing parcel's charge cannot be taxed
     * as the parcel was, or null when it can: its rate is the one it holds
     * (its frozen chain is not changed by an edit), and a taxed parcel holds
     * the chain it was calculated with.
     *
     * @param int $id The parcel.
     * @param array $data The charge as it will be written.
     * @param object $stored The parcel as it stands.
     * @param string[] $chain Its frozen chain.
     *
     * @return string|null The refusal as the operator reads it.
     *
     * @since  1.14.0
     */
    private static function frozenRateRefusal(int $id, array $data, object $stored, array $chain): ?string
    {
        $stated = (string) $data['shipping_tax_rate'];
        $frozen = (string) $stored->shipping_tax_rate;

        if ($stated !== $frozen) {
            return Text::sprintf(
                'COM_ALFA_SHIPMENT_CHARGE_RATE_FROZEN',
                $id,
                $chain === [] ? ExactAmount::of($frozen)->text() . '%' : self::chainShown(chain: $chain),
                ExactAmount::of($stated)->text(),
            );
        }

        return $chain === [] && ExactAmount::of($frozen)->isPositive()
            ? Text::sprintf('COM_ALFA_SHIPMENT_CHARGE_NO_CHAIN', $id, ExactAmount::of($frozen)->text())
            : null;
    }

    /**
     * Why a new charge an operator or a REST client enters does not state the
     * tax that applies, or null when it does: its rate is only an input and
     * must be exactly the combined rate of the resolved chain, and an untaxed
     * entry stands only where no shipping tax applies. One rate is named as
     * itself; a chain by its rates in order and their combined rate.
     *
     * @param array $data The charge as it will be written.
     * @param string[] $chain The resolved chain.
     *
     * @return string|null The refusal as the operator reads it, naming the tax that applies.
     *
     * @since  1.14.0
     */
    private static function resolvedRateRefusal(array $data, array $chain): ?string
    {
        $stated = (string) $data['shipping_tax_rate'];
        $resolved = $chain === [] ? (string) self::rateText(value: 0) : self::chainRateText(chain: $chain);

        if ($stated === $resolved) {
            return null;
        }

        return match (\count($chain)) {
            0 => Text::sprintf('COM_ALFA_SHIPMENT_CHARGE_NO_TAX_APPLIES', ExactAmount::of($stated)->text()),
            1 => Text::sprintf('COM_ALFA_SHIPMENT_CHARGE_TAX_APPLIES_ONE', $chain[0], ExactAmount::of($stated)->text()),
            default => Text::sprintf('COM_ALFA_SHIPMENT_CHARGE_TAX_APPLIES', self::chainShown(chain: $chain), ExactAmount::of($resolved)->text(), ExactAmount::of($stated)->text()),
        };
    }

    /**
     * A chain as written: a list of exact decimal rate texts, each above zero.
     *
     * @param mixed $value A list, or its JSON text.
     *
     * @return string[]|null Null when it is not one.
     *
     * @since  1.14.0
     */
    private static function chainOf(mixed $value): ?array
    {
        $rates = \is_string($value) ? json_decode($value, true) : $value;

        if (!\is_array($rates) || !array_is_list($rates)) {
            return null;
        }

        foreach ($rates as $rate) {
            if (!\is_string($rate) || preg_match('/^\d+(?:\.\d+)?$/D', $rate) !== 1 || !ExactAmount::of($rate)->isPositive()) {
                return null;
            }
        }

        return array_map(static fn (string $rate): string => ExactAmount::of($rate)->text(), $rates);
    }

    /**
     * A chain as the column holds it.
     *
     * @param string[] $chain Exact decimal rate texts, in order.
     *
     *
     * @since  1.14.0
     */
    private static function chainText(array $chain): string
    {
        return (string) json_encode(array_values($chain));
    }

    /**
     * A chain's rates as the tax calculation is handed them, in order.
     *
     * @param string[] $chain Exact decimal rate texts.
     *
     * @return AppliedTax[]
     *
     * @since  1.14.0
     */
    private static function applied(array $chain): array
    {
        return array_map(static fn (string $rate): AppliedTax => new AppliedTax(id: 0, name: '', rate: (float) $rate, jurisdiction: 'national'), $chain);
    }

    /**
     * A chain's combined rate as the rate column holds it ('15.500' for 10%
     * then 5%), for display, reporting and reconciliation only.
     *
     * @param string[] $chain Exact decimal rate texts, in order.
     *
     *
     * @since  1.14.0
     */
    private static function chainRateText(array $chain): string
    {
        return (string) self::rateText(value: round(PriceComputationEngine::chainRate(appliedTaxes: self::applied(chain: $chain)), 3));
    }

    /**
     * A chain as an operator reads it: '24%', or '10% then 5%'.
     *
     * @param string[] $chain Exact decimal rate texts, in order.
     *
     *
     * @since  1.14.0
     */
    private static function chainShown(array $chain): string
    {
        $shown = '';

        foreach ($chain as $rate) {
            $shown = $shown === '' ? $rate . '%' : Text::sprintf('COM_ALFA_SHIPMENT_TAX_CHAIN_THEN', $shown, $rate . '%');
        }

        return $shown === '' ? '0%' : $shown;
    }

    /**
     * The refusal of a write that names a chain that is none.
     *
     * @param array $data The write.
     *
     *
     * @since  1.14.0
     */
    private static function chainRefusal(array $data): string
    {
        return 'the tax chain ' . var_export($data['shipping_tax_chain'] ?? null, true)
            . ' is not an ordered list of rates whose combined rate is the rate stated; nothing was written';
    }

    /**
     * The entry mode a charge is entered under when the parcel holds none of
     * its own: the shop's, for its method, at this moment; frozen with it.
     *
     * @param int $methodId The parcel's shipping method.
     * @param int $orderId Its order.
     *
     *
     * @since  1.14.0
     */
    private static function entryMode(int $methodId, int $orderId): ShippingPriceEntryMode
    {
        $currency = OrderCurrency::of(orderId: $orderId);

        return (new ShippingPriceEntryModeResolver())->resolve(new ShipmentPricingContext(
            methodId: $methodId,
            destination: [],
            audience: PriceContext::forIndex(currencyId: (int) $currency->getId(), placeId: 0, usergroupId: 0),
            currency: $currency,
        ));
    }

    /**
     * How a carrier's write says its figures were formed: the basis the order
     * froze at placement when the write carries it, and otherwise the carrier's
     * own statement. Null when the write names a basis that is none.
     *
     * @param array $data The carrier's write.
     *
     *
     * @since  1.14.0
     */
    private static function carrierBasis(array $data): ?ShippingChargeBasis
    {
        return \array_key_exists('shipping_charge_basis', $data)
            ? ShippingChargeBasis::tryFrom((string) $data['shipping_charge_basis'])
            : ShippingChargeBasis::CarrierStated;
    }

    /**
     * The refusal of a write that names a basis that is none.
     *
     * @param array $data The write.
     *
     *
     * @since  1.14.0
     */
    private static function basisRefusal(array $data): string
    {
        return 'the charge basis ' . var_export($data['shipping_charge_basis'] ?? null, true)
            . ' is none of tax_included, tax_excluded or carrier_stated; nothing was written';
    }

    /**
     * An amount as an operator reads it: at its money's places.
     *
     * @param Money $money The amount.
     *
     *
     * @since  1.14.0
     */
    private static function shown(Money $money): string
    {
        return number_format($money->getAmount(), $money->getCurrency()->getDecimalPlaces(), '.', '');
    }

    /**
     * A VAT rate as the column holds it, or null when the value is not one.
     *
     * @param mixed $value A rate as stated: a number or a decimal text; blank = 0.
     *
     * @return string|null The rate at three places ('24.000'); null when it is not a
     *                     percentage of zero or more with at most three decimal places.
     *
     * @since  1.14.0
     */
    private static function rateText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }

        if ((\is_float($value) && !is_finite($value))
            || !(\is_int($value) || \is_float($value) || (\is_string($value) && preg_match('/^\d+(?:\.\d+)?$/D', $value) === 1))) {
            return null;
        }

        $exact = ExactAmount::ofNumber($value);

        if ($exact->isNegative() || StoredDecimal::refusal(value: $exact, precision: 10, scale: 3) !== null) {
            return null;
        }

        return StoredDecimal::of($exact, precision: 10, scale: 3);
    }

    /**
     * Update an existing order shipment record.
     *
     * If 'items' key is present, item assignments are updated.
     *
     * A charge an operator or a REST client changes is judged whole: a figure
     * the call omits is the row's own, and the charge is checked through the
     * ordered rates the parcel froze, under the entry mode it froze (the
     * shop's, which it then keeps, when it held a carrier's statement); its
     * rate cannot change, and a taxed parcel with no frozen rates refuses the
     * change. A change of nothing in the charge (tracking, status) is not
     * judged and leaves it as it stands. A carrier's change is taken as stated
     * and makes the parcel's charge carrier-stated.
     *
     * @param int $id Shipment row PK
     * @param array $data Fields to update. 'id_order' is REQUIRED.
     * @param bool $entered True for a change an operator or a REST client entered.
     *
     * @return bool True on success
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function update(int $id, array $data, bool $entered = false): bool
    {
        if ($id <= 0) {
            self::warn('update(): invalid shipment ID');
            return false;
        }

        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('update(): id_order is required for activity logging');
            return false;
        }

        // No shipment row of an order that names no currency is written.
        OrderCurrency::of(orderId: $orderId);

        $written = self::writeUpdate(id: $id, orderId: $orderId, data: $data, entered: $entered);

        if ($written === null) {
            return false;
        }

        self::afterUpdate(orderId: $orderId, written: $written);

        return true;
    }

    /**
     * The row half of an update: the shipment row and its item assignments.
     *
     * {@see self::updateIfStatus()} runs it under the row lock, so the status
     * it checked is the status it replaces.
     *
     * @param int $id Shipment row PK.
     * @param int $orderId Its order.
     * @param array $data Fields to update; 'items' re-assigns the parcel's lines.
     * @param bool $entered True for a change an operator or a REST client entered.
     *
     * @return array|null What afterUpdate() reports on, or null when nothing was written.
     *
     * @since  1.14.0
     */
    private static function writeUpdate(int $id, int $orderId, array $data, bool $entered = false): ?array
    {
        // Extract items before DB save; how a charge was formed, and with which
        // rates, is decided below, never by a caller.
        $selectedItems = $data['items'] ?? null;
        unset($data['items'], $data['shipping_charge_basis'], $data['shipping_tax_chain']);

        $data[self::PK] = $id;

        $oldSnapshot = self::getRaw($id);

        // A row's charge is completed and judged from the row itself.
        if ($oldSnapshot === null) {
            self::warn(message: Text::sprintf('COM_ALFA_SHIPMENT_NOT_FOUND', $id), context: 'update()');
            return null;
        }

        $oldItemQuantities = self::getItemQuantities($id);

        // A net left blank is untaxed; a figure this call does not carry is the row's own.
        $refusal = self::readCharge(data: $data, stored: $oldSnapshot);

        if ($refusal !== null) {
            self::warn(message: $refusal, context: 'update()');
            return null;
        }

        // Money -> the column's decimal, refusing a currency the order is not in
        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl'] as $costField) {
            if (!array_key_exists($costField, $data)) {
                continue;
            }

            $stored = self::moneyForStorage(value: $data[$costField], orderId: $orderId, field: $costField);

            if ($stored === false) {
                return null;
            }

            $data[$costField] = $stored;
        }

        // A charge is judged when this call changes it: an edit of the tracking
        // or the status leaves the row's charge as it stands.
        if (self::chargeChanges(data: $data, stored: $oldSnapshot)) {
            // An operator's change is judged on the entry mode the parcel froze,
            // and on the shop's when the parcel holds a carrier's statement: a
            // changed charge is no longer the carrier's. A carrier's change is
            // its own statement. A change of nothing in the charge keeps it,
            // and its basis, as they stand.
            $frozen = ShippingChargeBasis::from((string) $oldSnapshot->shipping_charge_basis);
            $mode = $frozen->mode() ?? self::entryMode(methodId: (int) ($data['id_shipment_method'] ?? $oldSnapshot->id_shipment_method), orderId: $orderId);
            $data['shipping_charge_basis'] = $entered ? ShippingChargeBasis::ofMode($mode)->value : ShippingChargeBasis::CarrierStated->value;
            // An operator's change is taxed with the ordered rates the parcel
            // froze, never the combined rate and never today's rules, and it
            // cannot change them: its rate stays. A carrier's change is taxed at
            // the one rate it states.
            $frozenChain = self::chainOf(value: (string) $oldSnapshot->shipping_tax_chain) ?? [];
            $chain = $entered ? $frozenChain : self::carrierChain(data: $data);
            $data['shipping_tax_chain'] = self::chainText(chain: $chain ?? []);
            $refusal = ($entered ? self::frozenRateRefusal(id: $id, data: $data, stored: $oldSnapshot, chain: $frozenChain) : null)
                ?? self::chargeRefusal(data: $data, orderId: $orderId)
                ?? ($entered ? self::enteredChargeRefusal(data: $data, mode: $mode, chain: $chain, orderId: $orderId) : null);

            if ($refusal !== null) {
                self::warn(message: $refusal, context: 'update()');
                return null;
            }
        }

        // Re-snapshot when the method CHANGES: the old guard filled a
        // BLANK name only, so an edit that re-pointed the method kept the
        // previous one's name against the new id.
        if (!empty($data['id_shipment_method'])) {
            $movedTo = (int) $data['id_shipment_method'];
            $movedFrom = (int) ($oldSnapshot->id_shipment_method ?? 0);

            if ($movedFrom !== $movedTo || empty($data['shipment_method_name'])) {
                $data['shipment_method_name'] = self::resolveMethodName('Shipment', $movedTo);
            }

            $data['shipment_method_type'] = self::resolveMethodType('Shipment', $movedTo);
        }

        $success = self::saveToTable($data);

        if (!$success) {
            return null;
        }

        // Reassign items
        if ($selectedItems !== null) {
            self::assignItems($id, $orderId, $selectedItems);
        }

        return ['data' => $data, 'old' => $oldSnapshot, 'oldItems' => $oldItemQuantities, 'items' => $selectedItems];
    }

    /**
     * What follows a written update: its timeline entry and the order's
     * status automation.
     *
     * Never inside a transaction this class holds: the automation moves the
     * order through Transition and the plugins, whose own transactions would
     * run inside it.
     *
     * @param int $orderId The shipment's order.
     * @param array $written What writeUpdate() answered.
     *
     *
     * @since  1.14.0
     */
    private static function afterUpdate(int $orderId, array $written): void
    {
        $id = (int) $written['data'][self::PK];
        $data = $written['data'];
        $oldSnapshot = $written['old'];
        $oldItemQuantities = $written['oldItems'];
        $selectedItems = $written['items'];

        // Log changes
        if ($oldSnapshot) {
            // Diff ONLY the fields this call actually carries. The builder sends just
            // what was set, so diffing the whole list would read every absent column
            // as '' (buildDiff defaults a miss to '') and log a phantom change.
            $trackFields = array_intersect([
                'shipping_cost_tax_incl', 'shipping_cost_tax_excl', 'shipping_tax_rate', 'shipping_charge_basis', 'shipping_tax_chain',
                'id_shipment_method', 'shipment_method_name',
                'tracking_number', 'carrier_name', 'weight', 'status',
                'shipped', 'delivered',
            ], array_keys($data));

            $changes = AlfaHelper::buildDiff($oldSnapshot, $data, $trackFields);

            if ($selectedItems !== null) {
                // Diff [linePK => qty] maps of what was actually recorded, so a
                // split-quantity change on the same lines is visible too.
                $newItemQuantities = self::getItemQuantities($id);
                ksort($oldItemQuantities);
                ksort($newItemQuantities);

                if ($oldItemQuantities !== $newItemQuantities) {
                    $changes['assigned_items'] = [
                        'from' => $oldItemQuantities,
                        'to' => $newItemQuantities,
                    ];
                }
            }

            if (!empty($changes)) {
                $changedKeys = implode(', ', array_keys($changes));
                OrderModel::logOrderActivity(
                    $orderId,
                    'shipment.edited',
                    "Edited shipment #{$id}: {$changedKeys}",
                    $changes,
                    $id,
                );
            }

            // Method-mapped order automation: AFTER the activity log so the
            // edit row exists before the transition writes its own entries.
            if (array_key_exists('status', $data)) {
                self::applyStatusAutomation(
                    orderId:   $orderId,
                    shipment:  $oldSnapshot,
                    oldStatus: (string) ($oldSnapshot->status ?? ''),
                    newStatus: (string) $data['status'],
                );
            }
        }
    }

    /**
     * Shipment-method automation: move the ORDER when a shipment enters a
     * phase the method has mapped.
     *
     * The map is PLUGIN-DECLARED: each shipment plugin's params/params.xml
     * exposes only the phases its lifecycle reaches, as fields named
     * `status_on_<status>` (stored in the method's params JSON). This helper
     * just reads the conventional key and executes through Transition, so
     * stock/downloads/emails/activity-log can never diverge from a manual
     * admin change. Guards inherited from the builder: same-status = no-op
     * (duplicate callbacks harmless) and no automatic exit from the
     * cancelled family (no force() here, ever).
     *
     * Drives ONLY #__alfa_orders.id_order_status via Transition; fulfilment
     * fields derive from the shipment status write itself (OrdersModel) and
     * must never be touched here. update()-only, like payments: create()
     * stays clean.
     *
     * Never throws; automation failure must not break the shipment write.
     *
     * @param int $orderId Order PK.
     * @param object $shipment Pre-update shipment snapshot (carries id and
     *                         id_shipment_method).
     * @param string $oldStatus Previous status.
     * @param string $newStatus New status.
     *
     * @since  1.9.0
     */
    private static function applyStatusAutomation(
        int $orderId,
        object $shipment,
        string $oldStatus,
        string $newStatus,
    ): void {
        $nested = TransactionState::isOpen(self::db());

        try {
            $methodId = (int) ($shipment->id_shipment_method ?? 0);

            if ($newStatus === '' || $newStatus === $oldStatus || $methodId <= 0 || $orderId <= 0) {
                return;
            }

            $db = self::db();

            $query = $db->getQuery(true)
                ->select($db->quoteName(['type', 'params']))
                ->from($db->quoteName('#__alfa_shipments'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $methodId);
            $db->setQuery(query: $query);
            $method = $db->loadObject();

            if ($method === null) {
                return;
            }

            $params = json_decode((string) $method->params, true) ?: [];
            $targetStatusId = (int) ($params['status_on_' . $newStatus] ?? 0);

            if ($targetStatusId <= 0) {
                return;
            }

            $moved = Transition::for($orderId)
                ->to($targetStatusId)
                ->byPlugin((string) $method->type)
                ->note('Shipment #' . (int) ($shipment->id ?? 0) . ' -> ' . $newStatus)
                ->save();

            if (!$moved) {
                self::reportMoveNotMade(
                    orderId:        $orderId,
                    targetStatusId: $targetStatusId,
                    cause:          'shipment #' . (int) ($shipment->id ?? 0) . ' -> ' . $newStatus,
                );
            }
        } catch (Throwable $e) {
            // A deadlock that ended the caller's transaction is the caller's
            // to hear: answered here, the caller would carry on as if its
            // work were still pending.
            if ($nested && !TransactionState::isOpen(self::db())) {
                throw $e;
            }

            Log::add(
                entry:    'Shipment status automation failed for order ' . $orderId . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orders',
            );
        }
    }

    /**
     * Record that a status automation's move was not made.
     *
     * The transition refused it (the reason is on the order's timeline and in
     * the log) or another writer held the order. An order that already stands
     * in the target status lost nothing, and is not reported.
     *
     * @param int $orderId The order.
     * @param int $targetStatusId The status the automation asked for.
     * @param string $cause What asked for it.
     *
     *
     * @since  1.14.0
     */
    private static function reportMoveNotMade(int $orderId, int $targetStatusId, string $cause): void
    {
        try {
            $db = self::db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('id_order_status') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId,
            );

            if ((int) $db->loadResult() === $targetStatusId) {
                return;
            }

            Log::add(
                entry:    "Status automation did not move order {$orderId} to status {$targetStatusId} ({$cause}).",
                priority: Log::WARNING,
                category: 'com_alfa.orders',
            );
        } catch (Throwable) {
            // A report that cannot be written changes nothing that happened.
        }
    }

    /**
     * Compare-and-set: apply this update ONLY while the row still holds an
     * expected status.
     *
     * The shipment answer to "did my write land, or had the row already
     * moved?". Carriers redeliver webhooks: a second 'delivered' push, or one
     * racing an admin who marked it delivered by hand, must not run the
     * delivered automation twice: the customer gets two emails, and on an
     * order carrying downloads the grant fires again.
     *
     * NOT a general "update with a guard": the expected status is the whole
     * point, so a caller that does not care what it is replacing wants
     * {@see self::update()} instead.
     *
     * @param int $id Shipment row PK
     * @param array $data Update payload; must carry id_order
     * @param string $expectedStatus The status the row must still hold
     *
     * @return int WRITE_OK | WRITE_RACED (row moved, nothing written) | WRITE_FAILED
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.10.0
     */
    public static function updateIfStatus(int $id, array $data, string $expectedStatus): int
    {
        $orderId = (int) ($data['id_order'] ?? 0);

        if ($id <= 0 || $orderId <= 0) {
            self::warn('updateIfStatus(): id and id_order are required');

            return self::WRITE_FAILED;
        }

        // No shipment row of an order that names no currency is written.
        OrderCurrency::of(orderId: $orderId);

        $db = self::db();
        $nested = TransactionState::isOpen($db);

        $db->transactionStart(true);

        try {
            // Read the CURRENT status inside the transaction, with the row
            // held. Read outside it and a carrier's second delivery
            // notification can pass the check while the first is still
            // committing: both then fire the delivered automation.
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('status'))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName(self::PK) . ' = ' . $id)
                . ' FOR UPDATE',
            );

            $current = (string) $db->loadResult();

            if ($current !== $expectedStatus) {
                TransactionState::rollBackLevel($db);

                return self::WRITE_RACED;
            }

            // The row is written while it is still held, the same write
            // update() makes: a second delivery queued on the lock then reads
            // the status this one wrote, and is the lost race.
            $written = self::writeUpdate(id: $id, orderId: $orderId, data: $data);

            if ($written === null) {
                TransactionState::rollBackLevel($db);

                return self::WRITE_FAILED;
            }
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction too is the
            // caller's to hear: a refusal would let it commit work the server
            // has already discarded.
            if (TransactionState::rollBackLevel($db, $e) && $nested) {
                throw $e;
            }

            self::warn('updateIfStatus() failed: ' . $e->getMessage());

            return self::WRITE_FAILED;
        }

        // After the try: a COMMIT that fails is closed by commitLevel() itself,
        // and reaches the caller.
        TransactionState::commitLevel($db);

        // After the commit: the timeline entry and the order's status
        // automation, which runs through Transition and the plugins.
        self::afterUpdate(orderId: $orderId, written: $written);

        return self::WRITE_OK;
    }

    /**
     * Delete a shipment row.
     *
     * @param int $id Shipment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is removed.
     *
     * @since  1.0.0
     */
    public static function delete(int $id, int $orderId): bool
    {
        $shipment = self::getRaw($id);

        // No shipment row of an order that names no currency is removed.
        OrderCurrency::of(orderId: (int) ($shipment->id_order ?? $orderId));

        // Clear item assignments first (FK integrity)
        self::clearItemAssignments($id, $orderId);

        $success = self::deleteFromTable($id);

        if ($success && $shipment) {
            $methodName = $shipment->shipment_method_name ?? '';
            $costIncl = number_format((float) ($shipment->shipping_cost_tax_incl ?? 0), 2);

            OrderModel::logOrderActivity(
                $orderId,
                'shipment.deleted',
                "Deleted shipment: {$methodName} - {$costIncl}",
                [
                    'shipping_cost_tax_incl' => (float) ($shipment->shipping_cost_tax_incl ?? 0),
                    'shipment_method_name' => $methodName,
                    'tracking_number' => $shipment->tracking_number ?? '',
                ],
                $id,
            );
        }

        return $success;
    }

    //  Static read API

    /**
     * Load a single shipment with method params.
     *
     * @param int $id Shipment row PK
     *
     * @return object|null Shipment with ->params, or null
     *
     * @since  1.0.0
     */
    public static function get(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->quoteName(self::TABLE, 'a'))
            ->where($db->quoteName('a.id') . ' = ' . (int) $id);

        $db->setQuery($query);
        $shipment = $db->loadObject();

        if (!$shipment) {
            return null;
        }

        try {
            $model = self::getRelatedModel('Shipment');
            $shipment->params = $model->getItem($shipment->id_shipment_method);
        } catch (Exception $e) {
            $shipment->params = null;
        }

        return $shipment;
    }

    /**
     * Load all shipments for an order (lightweight).
     *
     * @param int $orderId Order PK
     *
     * @return array Array of shipment row objects
     *
     * @since  1.0.0
     */
    public static function getByOrder(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from(self::TABLE)
            ->where('id_order = ' . (int) $orderId);

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    //  Static item assignment API

    /**
     * Put order lines in a parcel, replacing whatever was in it.
     *
     * Accepts a plain list of line PKs (every unit still unshipped) or a
     * [linePK => quantity] map. Quantities are CLAMPED to what is actually
     * left on the line across all other parcels, so an over-assignment is
     * corrected rather than recorded; the same line can legitimately appear
     * on several parcels, but never for more units than were ordered.
     *
     * Scoped to the order: a line belonging to another order is ignored.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $items List of line PKs, a [linePK => quantity] map, or
     *                     the published API body's [{id_order_item, quantity}] list
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    public static function assignItems(int $shipmentId, int $orderId, array $items): bool
    {
        if ($shipmentId <= 0 || $orderId <= 0) {
            self::warn('assignItems(): shipment and order are both required');
            return false;
        }

        $db = self::db();

        try {
            // What is left on each line once THIS parcel is set aside: the
            // ceiling every requested quantity is clamped to.
            $remaining = self::remainingQuantities(
                orderId: $orderId,
                exceptShipmentId: $shipmentId,
            );

            // What the parcel holds RIGHT NOW. A plain list carries no
            // quantities (the admin form posts one), so a line already in the
            // parcel must keep the quantity it has; otherwise saving the form
            // to change a tracking number would silently swallow the units
            // being held back for a later parcel.
            $current = self::getItemQuantities($shipmentId);

            self::clearItemAssignments($shipmentId, $orderId);

            foreach (self::normaliseItemMap($items) as $itemId => $quantity) {
                // Not a line of this order, or nothing left to ship on it.
                if (!isset($remaining[$itemId])) {
                    continue;
                }

                // A map asks for an exact number. A plain list means "leave
                // this line as it is", or (for a line being added now) "all
                // of it that is still unshipped".
                $quantity = $quantity > 0
                    ? min($quantity, $remaining[$itemId])
                    : min($current[$itemId] ?? $remaining[$itemId], $remaining[$itemId]);

                if ($quantity <= 0) {
                    continue;
                }

                $row = (object) [
                    'id_order_shipment' => $shipmentId,
                    'id_order_item' => $itemId,
                    'id_order' => $orderId,
                    'quantity' => $quantity,
                ];

                $db->insertObject(self::SHIPMENT_ITEMS_TABLE, $row, 'id');
            }

            return true;
        } catch (Exception $e) {
            if (TransactionState::endedBy($db, $e)) {
                throw $e;
            }

            self::warn('assignItems() failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Order-line PKs carried by a parcel.
     *
     * @param int $shipmentId Shipment PK
     *
     * @return array Array of #__alfa_order_items.id values
     *
     * @since  1.0.0
     */
    public static function getItemIds(int $shipmentId): array
    {
        return array_keys(self::getItemQuantities($shipmentId));
    }

    /**
     * How many units of each order line a parcel carries.
     *
     * @param int $shipmentId Shipment PK
     *
     * @return array [ orderItemId => quantity ]
     *
     * @since  1.9.0
     */
    public static function getItemQuantities(int $shipmentId): array
    {
        if ($shipmentId <= 0) {
            return [];
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id_order_item'), $db->quoteName('quantity')])
            ->from($db->quoteName(self::SHIPMENT_ITEMS_TABLE))
            ->where($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId);

        $db->setQuery($query);

        $map = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $map[(int) $row->id_order_item] = (int) $row->quantity;
        }

        return $map;
    }

    /**
     * Get human-readable names of assigned items.
     *
     * Format: "T-Shirt x2, Jeans x1"; the xN is the quantity in THIS
     * parcel, not the quantity ordered.
     *
     * @param int $shipmentId Shipment PK
     *
     * @return string Comma-separated names, or empty string
     *
     * @since  1.0.0
     */
    public static function getItemNames(int $shipmentId): string
    {
        if ($shipmentId <= 0) {
            return '';
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('oi.name'), $db->quoteName('si.quantity')])
            ->from($db->quoteName(self::SHIPMENT_ITEMS_TABLE, 'si'))
            ->join(
                'INNER',
                $db->quoteName(self::ITEMS_TABLE, 'oi'),
                $db->quoteName('si.id_order_item') . ' = ' . $db->quoteName('oi.id'),
            )
            ->where($db->quoteName('si.id_order_shipment') . ' = ' . (int) $shipmentId);

        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (empty($items)) {
            return '';
        }

        $parts = [];

        foreach ($items as $item) {
            // Frozen names are {lang:text} snapshots; resolve to the current
            // language (raw JSON would also break the comma-separated format).
            $name = MultilingualHelper::resolveLangMap($item->name ?? '');
            $parts[] = ($name !== '' ? $name : 'Item') . ' x' . (int) ($item->quantity ?? 1);
        }

        return implode(', ', $parts);
    }

    /**
     * Units of each order line not yet in any parcel.
     *
     * The ceiling for every assignment. One parcel may be set aside (the one
     * being written), so that re-saving it sees its own units as available
     * instead of as already taken.
     *
     * Lines with nothing left are omitted entirely, so the result doubles as
     * "what may still be shipped".
     *
     * @param int $orderId Order PK
     * @param int $exceptShipmentId Parcel to ignore (0 = count them all)
     *
     * @return array [ orderItemId => unitsLeft ], omitting fully-shipped lines
     *
     * @since  1.9.0
     */
    public static function remainingQuantities(int $orderId, int $exceptShipmentId = 0): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();

        // Ordered units per line.
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('quantity')])
            ->from($db->quoteName(self::ITEMS_TABLE))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId);

        $db->setQuery($query);

        $remaining = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $remaining[(int) $row->id] = (int) $row->quantity;
        }

        if (empty($remaining)) {
            return [];
        }

        // Units already in other parcels. A cancelled or returned parcel will
        // never move, so its units go back in the pool: the same rule
        // OrderTotalHelper applies to shipping cost, which is what makes
        // cancel-and-replace work. The INNER JOIN also drops rows orphaned by
        // a shipment that no longer exists.
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('si.id_order_item'),
                'SUM(' . $db->quoteName('si.quantity') . ') AS ' . $db->quoteName('taken'),
            ])
            ->from($db->quoteName(self::SHIPMENT_ITEMS_TABLE, 'si'))
            ->join(
                'INNER',
                $db->quoteName(self::TABLE, 'os'),
                $db->quoteName('si.id_order_shipment') . ' = ' . $db->quoteName('os.id'),
            )
            ->where($db->quoteName('si.id_order') . ' = ' . (int) $orderId)
            ->where(
                $db->quoteName('os.status') . ' NOT IN ('
                . $db->quote(self::STATUS_CANCELLED) . ', ' . $db->quote(self::STATUS_RETURNED) . ')',
            )
            ->group($db->quoteName('si.id_order_item'));

        if ($exceptShipmentId > 0) {
            $query->where($db->quoteName('si.id_order_shipment') . ' <> ' . (int) $exceptShipmentId);
        }

        $db->setQuery($query);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $itemId = (int) $row->id_order_item;

            if (isset($remaining[$itemId])) {
                $remaining[$itemId] -= (int) $row->taken;
            }
        }

        return array_filter($remaining, static fn ($left) => $left > 0);
    }

    /**
     * Accept any of the three item shapes and return a [linePK => quantity] map.
     *
     * A plain list carries no quantities, so each entry maps to 0, which every
     * caller reads as "whatever is left on that line". An entry that names its
     * own quantity is honoured exactly, whichever shape carried it, so a
     * `{id_order_item, quantity}` element and a `[linePK => quantity]` pair
     * mean the same thing, including when the quantity is 0.
     *
     * @param array $items One of:
     *                     - [linePK, linePK, ...]                  (the admin form)
     *                     - [linePK => quantity, ...]              (internal callers)
     *                     - [{id_order_item, quantity}, ...]       (the published API body)
     *
     * @return array [ orderItemId => quantity ]
     *
     * @since  1.9.0
     */
    private static function normaliseItemMap(array $items): array
    {
        $map = [];

        if (array_is_list($items)) {
            foreach ($items as $entry) {
                // THE PUBLISHED API BODY IS A LIST OF OBJECTS, NOT OF IDS.
                //
                // OrderopsController documents
                // `items?: [{id_order_item, quantity}]` and forwards the body
                // verbatim, so it arrives here as a list whose elements are
                // arrays. `(int)` on an array is 1, so every element used to
                // collapse onto line PK 1 with quantity 0, which assignItems()
                // reads as "all of line 1 that is still unshipped". A caller
                // who named two lines and exact quantities got the whole of a
                // line it never mentioned, or (when line 1 belongs to another
                // order) an empty parcel.
                //
                // On UPDATE that was destructive rather than merely wrong:
                // assignItems() clears the parcel BEFORE this loop, and PATCH
                // forwards the same body, so a documented update EMPTIED a
                // live parcel and re-added only the phantom line.
                if (\is_array($entry) || $entry instanceof stdClass) {
                    $entry = (array) $entry;
                    $lineId = (int) ($entry['id_order_item'] ?? 0);

                    if ($lineId > 0) {
                        $map[$lineId] = max(0, (int) ($entry['quantity'] ?? 0));
                    }

                    continue;
                }

                if ((int) $entry > 0) {
                    $map[(int) $entry] = 0;
                }
            }

            return $map;
        }

        foreach ($items as $id => $quantity) {
            if ((int) $id > 0) {
                $map[(int) $id] = max(0, (int) $quantity);
            }
        }

        return $map;
    }

    //  INTERNAL: Database Operations

    /**
     * Does this order already have a shipment row carrying the shipping charge?
     *
     * @param int $orderId Order PK
     *
     * @return bool True when some parcel already charges for shipping
     *
     * @since  1.9.0
     */
    private static function orderAlreadyChargesShipping(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
            // A cancelled or returned parcel's cost is excluded from the order
            // total (OrderTotalHelper), so it is NOT charging anything; if it
            // blocked the replacement parcel the order would lose the charge
            // from both rows.
            ->where(
                $db->quoteName('status') . ' NOT IN ('
                . $db->quote(self::STATUS_CANCELLED) . ', ' . $db->quote(self::STATUS_RETURNED) . ')',
            )
            ->where(
                '(' . $db->quoteName('shipping_cost_tax_incl') . ' <> 0'
                . ' OR ' . $db->quoteName('shipping_cost_tax_excl') . ' <> 0)',
            )
            ->setLimit(1);

        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Empty a parcel: delete its lines from the join table.
     *
     * Used before delete and before reassignment. The order scope is a
     * security bound, not a filter: a shipment id from another order finds
     * nothing rather than clearing someone else's parcel.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (scope)
     *
     *
     * @since  1.0.0
     */
    private static function clearItemAssignments(int $shipmentId, int $orderId): void
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::SHIPMENT_ITEMS_TABLE))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
            ->where($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId);

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            if (TransactionState::endedBy($db, $e)) {
                throw $e;
            }

            self::warn('clearItemAssignments() failed: ' . $e->getMessage());
        }
    }

    //  INTERNAL: Utilities
}
