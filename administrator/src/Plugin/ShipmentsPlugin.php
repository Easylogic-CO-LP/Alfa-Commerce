<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Base Shipment Plugin
 *
 * All shipment plugins extend this class. Provides the cart a carrier is asked
 * about, the pricing primitives carriers keep re-inventing, fluent builder
 * wrappers for parcels, and empty action hooks for the admin UI.
 *
 * Worked example with every hook explained: the Standard plugin at
 * plugins/alfa-shipments/standard/src/Extension/Standard.php.
 *
 *  QUOTING: the one hook you must implement
 *
 *    public function onCalculateShippingCost($event): void
 *    {
 *        $cost = $this->myRates($event->getMethod(), $event->getCart());
 *
 *        $cost === null
 *            ? $event->setUnavailable('PLG_MY_CARRIER_TOO_HEAVY')  // a KEY, never English
 *            : $event->setShippingCost($cost);                     // 0.0 IS free
 *    }
 *
 *  ANSWER, ALWAYS. Calling neither setter is SILENCE, and silence fails
 *  closed: the carrier comes off the cart. It is never read as free.
 *      setShippingCost(4.90)   priced      setUnavailable('KEY')  cannot carry
 *      setShippingCost(0.0)    free        (neither)              no answer
 *  A refusal outranks a price.
 *
 *  THE PRICE IS THE MERCHANT'S, AND SO IS ITS TAX. setShippingCost() hands
 *  over the price as configured; whether tax is inside it is the shop's
 *  shipping price entry mode, and the tax is the method's shipping taxes for
 *  the destination. Core applies both. A carrier whose own API already splits
 *  the tax answers with the canonical amounts instead, taken as they are:
 *      setShippingAmounts(taxExcl: '8.06', taxAmount: '1.94', taxIncl: '10.00', taxRate: 24.0)
 *  A parcel row records the charge with its rate:
 *      ->cost($order->shipmentTotal(), $order->shipmentTotalExcl(), $order->shipmentTaxRate())
 *
 *  THE CART: $event->getCart(), a settled snapshot with no setters
 *
 *    $cart->lines()          CartLine[]: quantity(), width/height/depth/weight()
 *                            RESOLVED per unit: product's own, overwritten by
 *                            the chosen variant. Centimetres and kilograms.
 *                            isVirtual(), isUnavailable(), itemId(), name()
 *    $cart->getSubtotal()    Money: the goods, settled before you were asked
 *    $cart->getTotal()       Money: payable; THROWS during a quote, because
 *                            carriage is what you are deciding
 *    $cart->isVirtual()  quantity()  itemCount()  currency()  customer()
 *    $cart->delivery()  invoice()    AddressView
 *
 *  THE ADDRESS: read any field the merchant pointed you at with a
 *  `checkoutfield` param. The core holds no postcode role and hardcodes no
 *  column; a postcode is your concern.
 *
 *    $cart->delivery()->field($id)->value()          string|null
 *    $cart->delivery()->field($id)->int()            int|null
 *    $cart->delivery()->firstFilled([$a, $b])        first one answered
 *    $cart->delivery()->getName()                   string, '' when absent
 *    $cart->delivery()->getEmail()                  the two guaranteed values
 *    $cart->delivery()->role('phone')               phone and country: roles,
 *                                                   but a shop may drop them
 *    $cart->delivery()->places()                     the shop's destination
 *    $cart->delivery()->places(fieldId: $x)          a field YOUR method names
 *
 *  What the shopper just typed wins over what was saved, INCLUDING a clear.
 *  Places are nearest-first; empty means unknown and unknown restricts. A field
 *  you named that is unanswered or deleted gives nothing and never falls back.
 *
 *  YOUR SETTINGS: declare them in params/params.xml inside
 *  <fields name="shipmentsparams">; a field outside it is silently discarded
 *  on save. Each <fieldset> is a tab, in document order.
 *
 *    $method->param('key', $default)   $method->params()
 *    $method->id()  type()  name()  description()  isSelected()
 *
 *  PRIMITIVES: so you do not write these again
 *
 *    $this->costFromZones(zones:, lines:, places:, postcode:, subtotal:, limits:)
 *        The zone/band cost table two shipped carriers share. Returns a price,
 *        0.0 for free, or NULL for a refusal; $this->refusal() names why.
 *    $this->parcelRefusal(lines:, limits:)
 *        Can you physically take it. Judged BEFORE any cost row and
 *        independently of one, or the two configurations that never reach a row
 *        (no zones; a matching free rate) skip the ceilings with it. Override it
 *        if your carrier has real geometry rather than merchant-typed maxima.
 *    $this->packageOf(lines:)              the consignment, quantity included
 *    $this->isWithinLimits($v, $min, $max) postcodes, amounts, dimensions;
 *                                          unset/empty/zero is NO limit
 *    $this->placesFor($placeId)            a place and what contains it
 *    $this->refusalKey('TOO_HEAVY')        PLG_ALFA_SHIPMENTS_<YOU>_TOO_HEAVY
 *
 *  PARCELS - FLUENT BUILDER API (recommended for programmatic use):
 *
 *    CREATE:
 *      $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
 *      $id = $this->shipment($order)->delivered()->withAllItems()->cost(0)->save();
 *
 *    UPDATE:
 *      $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
 *      $this->shipmentUpdate($id)->delivered()->save();
 *      $this->shipmentUpdate($id)->cancelled()->save();
 *
 *  READ / DELETE / ITEMS (no builder needed):
 *    $shipment  = $this->getShipment($id);
 *    $shipments = $this->getShipmentsByOrder($orderId);
 *    $this->deleteShipment($id, $orderId);
 *    $this->assignShipmentItems($shipmentId, $orderId, [101, 102]);
 *
 *  An order can carry several parcels: withItems([...]) builds a partial one, and
 *  the quantity on a shipment line is what went in THAT parcel.
 *
 *  WHEN SOMETHING GOES WRONG - three surfaces, and they are not the same:
 *
 *    $this->log([...])          YOUR audit trail: a row per parcel event,
 *                               rendered in the admin. loadLogs(), getLogsSchema()
 *    Log::add($msg, Log::ERROR, $this->logCategory())
 *                               The developer's file, already registered at
 *                               administrator/logs/alfa_shipments_<you>.php.
 *                               The carrier's status and body go here. Never a
 *                               credential.
 *    NotificationHelper::push($dedupKey, $title, [...])
 *                               The admin's notification centre, for a condition
 *                               that OUTLIVES the request: credentials that
 *                               stopped working, a booking that left an order
 *                               unfulfilled. Dedup-keyed
 *                               ('shipments.<you>.auth') and RETRACTED with
 *                               ::clear($dedupKey) on the next success; an alert
 *                               nobody withdraws is an alert nobody reads.
 *
 *  A failed admin ACTION belongs on the event: $event->setError(Text::_($key)),
 *  so the person who clicked it is told. A JsonResponse nobody reads is silence.
 *
 *  ADMIN ACTIONS (Fluent API on event):
 *    $event->add('mark_shipped', 'Mark as Shipped')
 *        ->icon('truck')->css('btn-primary')
 *        ->confirm('Ship this?')->priority(200);
 *
 * LOG IDENTIFIER: Sets logIdentifierField = 'id_order_shipment'
 * LOG METHOD:     Sets logMethodField = 'id_shipment_method' (method-level rows:
 *                 logForMethod() / loadMethodLogs())
 *
 * Path: administrator/components/com_alfa/src/Plugin/ShipmentsPlugin.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasOrderContextTrait;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasTransportUrlsTrait;
use Alfa\Component\Alfa\Administrator\Service\Order\ShipmentLedger;
use Alfa\Component\Alfa\Site\Service\Cart\CartLine;
use Joomla\CMS\Log\Log;
use RuntimeException;

defined('_JEXEC') or die;

abstract class ShipmentsPlugin extends Plugin
{
    use HasOrderContextTrait;
    use HasTransportUrlsTrait;

    // Shipment status vocabulary
    // Re-exposed from ShipmentLedger so a gateway references self::STATUS_* through
    // its own base class and never names the ledger directly. ShipmentLedger stays
    // the single source of truth for the values.
    public const STATUS_PENDING = ShipmentLedger::STATUS_PENDING;
    public const STATUS_SHIPPED = ShipmentLedger::STATUS_SHIPPED;
    public const STATUS_DELIVERED = ShipmentLedger::STATUS_DELIVERED;
    public const STATUS_CANCELLED = ShipmentLedger::STATUS_CANCELLED;
    public const STATUS_ON_HOLD = ShipmentLedger::STATUS_ON_HOLD;
    public const STATUS_RETURNED = ShipmentLedger::STATUS_RETURNED;

    // Conditional-write verdicts (settleAttempt, updateIfStatus)
    public const WRITE_OK = ShipmentLedger::WRITE_OK;
    public const WRITE_RACED = ShipmentLedger::WRITE_RACED;
    public const WRITE_FAILED = ShipmentLedger::WRITE_FAILED;

    /**
     * This family answers on the shipment.* controller.
     *
     * @since  2.0.0
     */
    protected function transportTask(): string
    {
        return 'shipment';
    }

    /**
     * Events this plugin subscribes to.
     *
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onItemView' => 'onItemView',
            'onCartView' => 'onCartView',
            'onCartViewAssets' => 'onCartViewAssets',
        ];
    }

    /**
     * Constructor.
     *
     * Sets logIdentifierField so $this->loadLogs($orderId, $shipmentId)
     * correctly filters by id_order_shipment in the plugin's log table,
     * logMethodField so method-level rows land in id_shipment_method, and
     * registers the carrier's own text logger: same contract the payment
     * gateways get, so a shipment plugin's operator-facing lines are not
     * hostage to whichever other plugin happens to be installed.
     *
     * @param array $config Plugin configuration
     * @since  1.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        [$this->logIdentifierField, $this->logMethodField] = PluginLogTable::FAMILY_COLUMNS['alfa-shipments'];

        $this->registerLogger();
    }

    //  Shipment fluent builder wrappers
    //
    //  These return an ShipmentLedger builder instance.
    //  The builder validates, auto-fills, and delegates to
    //  static CRUD methods internally.
    //
    //  For direct CRUD access (batch ops, admin form saves),
    //  use ShipmentLedger::create/update/delete() directly.

    /**
     * Create a new shipment (returns fluent builder in CREATE mode).
     *
     * Auto-fills from order: id_order, id_shipment_method, weight.
     * Chain ->withAllItems() or ->withItems([...]) to assign items.
     *
     * Usage:
     *   $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
     *   $id = $this->shipment($order)->delivered()->withAllItems()->cost(0)->save();
     *   $id = $this->shipment($order)->pending()->withItems([101])->cost(8)->save();
     *
     * @param object $order Full order object (->id, ->items, ->id_shipment_method)
     *
     * @return ShipmentLedger Builder: chain setters, finish with ->save()
     *
     * @since  1.0.0
     */
    protected function shipment(object $order): ShipmentLedger
    {
        return ShipmentLedger::for($order);
    }

    /**
     * Update an existing shipment (returns fluent builder in UPDATE mode).
     *
     * Only fields you explicitly set are sent to the database.
     * Status shortcuts auto-set timestamps:
     *   ->shipped()   -> sets 'shipped' timestamp
     *   ->delivered() -> sets 'shipped' + 'delivered' timestamps
     *
     * Usage:
     *   $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
     *   $this->shipmentUpdate($id)->delivered()->save();
     *   $this->shipmentUpdate($id)->cancelled()->save();
     *
     * Note that order transitions mapped in the method's params
     * (status_on_<status> in params.xml) fire automatically when a shipment
     * status changes; never move the order yourself for a mapped phase.
     *
     * @param int $shipmentId Existing shipment row PK
     *
     * @return ShipmentLedger Builder: chain setters, finish with ->save()
     *
     * @throws RuntimeException If shipment not found in DB
     *
     * @since  1.0.0
     */
    protected function shipmentUpdate(int $shipmentId): ShipmentLedger
    {
        return ShipmentLedger::load($shipmentId);
    }

    //  SHIPMENT READ / DELETE / ITEMS (no builder needed)

    /**
     * Delete an order shipment record.
     *
     * Automatically clears item assignments before deletion.
     *
     * @param int $id Shipment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    protected function deleteShipment(int $id, int $orderId): bool
    {
        return ShipmentLedger::delete($id, $orderId);
    }

    /**
     * Load a single shipment with method params attached.
     *
     * @param int $id Shipment row PK
     *
     * @return object|null Shipment record with ->params, or null
     *
     * @since  1.0.0
     */
    protected function getShipment(int $id): ?object
    {
        return ShipmentLedger::get($id);
    }

    /**
     * Load all shipments for an order (lightweight, no params).
     *
     * @param int $orderId Order PK
     *
     * @return array Array of shipment row objects
     *
     * @since  1.0.0
     */
    protected function getShipmentsByOrder(int $orderId): array
    {
        return ShipmentLedger::getByOrder($orderId);
    }

    /**
     * Assign order items to a shipment.
     *
     * Clears previous assignments for this shipment, then sets new ones.
     * Scoped to order to prevent cross-order item assignment.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $itemIds Array of order_items.id values
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    protected function assignShipmentItems(int $shipmentId, int $orderId, array $itemIds): bool
    {
        return ShipmentLedger::assignItems($shipmentId, $orderId, $itemIds);
    }

    //  FRONTEND HOOKS (abstract: plugin MUST implement)

    /**
     * Calculate shipping cost for the cart.
     *
     * @since  1.0.0
     */
    /**
     * Move a shipment to a new status EXACTLY ONCE, and say what happened.
     *
     * The shipment counterpart of the payments settle. A carrier redelivers
     * webhooks (that is normal, not an error), so "the parcel was delivered"
     * can arrive twice, or once while an admin is marking it delivered by
     * hand. Both must produce one transition: the second gets WRITE_RACED and
     * the caller answers the carrier 200 without doing the work again.
     *
     * WHAT THIS IS NOT: the payments version settles MONEY and shouts when the
     * books and the gateway disagree, because a lost payment row is a customer
     * charged for nothing. A shipment carries no money, so a raced transition
     * is a duplicate notification and nothing more; it is logged at INFO, not
     * CRITICAL, and there is no reconciliation to demand.
     *
     * Usage:
     *   $write   = $this->shipmentUpdate($id)->delivered();
     *   $verdict = $this->settleAttempt($write, $orderId, self::STATUS_SHIPPED);
     *
     *   if ($verdict === self::WRITE_RACED) { ... already delivered, answer 200 ... }
     *
     * @param ShipmentLedger $write Builder carrying the new status.
     * @param int $orderId Order PK.
     * @param string $expected The status the row must still hold.
     * @param string $reference Carrier reference, for the log line.
     *
     * @return int WRITE_OK | WRITE_RACED | WRITE_FAILED
     *
     * @since  2.0.0
     */
    protected function settleAttempt(
        ShipmentLedger $write,
        int $orderId,
        string $expected,
        string $reference = '',
    ): int {
        // Through the builder, which owns its own row id: the same door
        // PaymentsPlugin::settleAttempt() uses on its side.
        $verdict = $write->saveVerdict($expected);

        if ($verdict === self::WRITE_RACED) {
            Log::add(
                sprintf(
                    'A shipment on order #%d had already left "%s"%s: duplicate carrier notification, nothing written.',
                    $orderId,
                    $expected,
                    $reference !== '' ? ' (' . $reference . ')' : '',
                ),
                Log::INFO,
                $this->logCategory(),
            );
        }

        return $verdict;
    }

    /**
     * THE ROW READS ITS OWN PAYLOAD. `$this->pluginData($shipment)` used to live
     * here and was a second way to ask what the carrier itself now answers:
     *
     *     $shipment = $event->getOrderShipment();
     *     $shipment->pluginData()->getString(key: 'parcel_id', default: '');
     *     $shipment->pluginData()->has('parcel_id');
     *
     * WRITE IT BACK THROUGH THE LEDGER, WHICH MERGES:
     *
     *     $this->shipmentUpdate($id)->pluginData(['parcel_id' => $v])->save();
     *     $this->shipmentUpdate($id)->removePluginData(['parcel_id'])->save();
     *
     * Pass PHP arrays, never JSON: encoding belongs to the ledger. Only the
     * keys you name change, and the merge happens with the row held, so a
     * webhook writing one key and an admin action writing another cannot erase
     * each other. Storing null KEEPS the key (has() stays true, a typed getter
     * answers with its default); removing takes it away. The merge is one level
     * deep: a key you write replaces what was under it, wholesale.
     *
     * The core never looks inside any of it.
     */

    //  Zone / band carriage pricing
    //
    //  The cost table two shipped carriers already share, byte for byte: a
    //  list of zones, each a set of places and a set of rows, each row a
    //  price under a postcode range, an order-value band and four size
    //  ceilings. It is here rather than in one of them because a third
    //  carrier configured the same way would otherwise copy it a third time,
    //  and the copy that mattered was the one where the refusal semantics had
    //  been fixed in one place and not the other.
    //
    //  A carrier that prices by an API of its own overrides
    //  onCalculateShippingCost() and never calls any of this.

    /**
     * Why this carrier refused, as a language key, set by the pricing below.
     *
     * PER REQUEST, NOT PER PROCESS. It was static once, so one cart's refusal
     * reason leaked into the next cart quoted in the same request.
     *
     * @since  2.0.0
     */
    private string $refusal = '';

    /**
     * The reason a carrier gives, in its own plugin's language namespace.
     *
     * `PLG_ALFA_SHIPMENTS_STANDARD_NO_BAND_FITS` for the standard carrier,
     * `PLG_ALFA_SHIPMENTS_MYCARRIER_NO_BAND_FITS` for another carrier: the same refusal,
     * worded by whoever is refusing, so a shopper is told which carrier will
     * not take the parcel.
     *
     * @param string $reason The refusal, unqualified.
     *
     * @return string The language key.
     *
     * @since  2.0.0
     */
    final protected function refusalKey(string $reason): string
    {
        return 'PLG_' . strtoupper(str_replace('-', '_', $this->_type . '_' . $this->_name)) . '_' . $reason;
    }

    /**
     * The refusal the last pricing call recorded, for setUnavailable().
     *
     * Public so a test can assert WHICH way a parcel failed: "too heavy" and
     * "too large" send a shopper to do different things, and a suite that could
     * only see `null` would pass while the carrier blamed the wrong dimension.
     *
     *
     * @since  2.0.0
     */
    final public function refusal(): string
    {
        return $this->refusal;
    }

    /**
     * Whether this carrier can physically take what is being sent, and if
     * not, which way it fails.
     *
     * A CEILING ON A COST ROW IS A PRICE BAND; THIS IS NOT. "This price covers
     * up to 2 kg" lets a heavier parcel fall through to the next row. "The
     * compartment door will not close" is a different kind of fact, and it has
     * to hold in the two configurations that never reach a row at all: no zones
     * configured, and a row priced at nothing that the order matches. Both
     * returned a price without measuring anything.
     *
     * WEIGHT IS THE WHOLE CONSIGNMENT; THE DIMENSIONS ARE ONE ITEM.
     *
     * They aggregate differently because they fail differently. Weight is
     * cumulative (six copies of a 0.4 kg book are 2.4 kg and a 2 kg locker
     * refuses them, though not one of them is over the limit alone), so it is
     * summed across every unit of every line, quantity included.
     *
     * A dimension is not cumulative. {@see self::packageOf()} sums widths
     * because it models goods laid side by side for the PRICING bands, and
     * measuring a physical ceiling that way would refuse a locker for six
     * 10 cm items on the grounds that they are "60 cm wide"; they are not,
     * they are six small parcels. What actually cannot be posted is a single
     * item that will not go through the door, so each of the three dimensions
     * is judged against the largest SINGLE unit in the order.
     *
     * VIRTUAL LINES ARE NOT IN THE BOX and are skipped, so a download bundled
     * with a physical item cannot push an order over anything.
     *
     * AN UNMEASURED PRODUCT PASSES EVERY CEILING. A width of 0.0 is numeric and
     * non-empty, so it is inside every maximum. That is deliberate and it is
     * the same reading everywhere else: the shop refuses a sale on measurements
     * a merchant recorded, never on measurements they never took.
     *
     * An unset, empty or zero ceiling is NO ceiling (the reading
     * {@see Plugin::isWithinLimits()} gives every configured limit), so a
     * carrier that has not been told its maxima behaves exactly as before.
     *
     * OVERRIDABLE, because this is the generic answer for a carrier configured
     * by numbers. A carrier that knows its own cabinet (a locker network with
     * published compartment clearances) answers this itself and does not have
     * to flatten real geometry into four maxima. What it MUST NOT do is skip
     * the question: costFromZones() asks it before every early return, which is
     * the only reason the ceilings hold where no cost row is consulted.
     *
     * @param CartLine[] $lines What there is to carry.
     * @param array $limits ['width' =>, 'height' =>, 'depth' =>, 'weight' =>].
     *
     * @return string|null The refusal language key, or null when it can be carried.
     *
     * @since  2.0.0
     */
    public function parcelRefusal(array $lines, array $limits): ?string
    {
        $limits = array_filter(
            $limits,
            static fn ($limit): bool => $limit !== null && $limit !== '' && (!is_numeric($limit) || (float) $limit > 0),
        );

        // Nothing configured is the shipped state of every carrier that has not
        // been told its maxima, and it must cost nothing to ask.
        if ($limits === []) {
            return null;
        }

        // Weight is asked first because it is the ceiling merchants actually
        // set and the one a shopper can act on; telling them the box is too big
        // when it is really too heavy sends them to fix the wrong thing.
        if (!$this->isWithinLimits($this->packageOf($lines)['weight'], max: $limits['weight'] ?? null)) {
            return $this->refusalKey('PARCEL_TOO_HEAVY');
        }

        foreach ($lines as $line) {
            if ($line->isVirtual()) {
                continue;
            }

            foreach (['width' => $line->width(), 'height' => $line->height(), 'depth' => $line->depth()] as $axis => $measure) {
                if (!$this->isWithinLimits($measure, max: $limits[$axis] ?? null)) {
                    return $this->refusalKey('PARCEL_TOO_LARGE');
                }
            }
        }

        return null;
    }

    /**
     * Calculate shipping cost for the current cart and method.
     *
     * Resolves the delivery country, finds the matching cost-per-place
     * zone, then delegates to cheapestMatchingCost() for limit matching.
     *
     * THREE OUTCOMES, NOT TWO. A configured price; carriage at no charge; and
     * a refusal: the merchant drew zones and this cart fits none of their
     * rows, so they have said by omission that they do not carry it. Before
     * 1.10 all three were the float 0.00, and the third one shipped free.
     *
     * @param array $zones The method's configured cost-per-place zones.
     * @param CartLine[] $lines What there is to carry.
     * @param int[] $places The destination and the places it belongs to.
     * @param string|null $postcode The delivery postcode, or NULL when the checkout
     *                              has not supplied one. Never substituted.
     * @param float $subtotal The settled merchandise total, for the amount band.
     * @param array $limits What this carrier can physically take, as
     *                      ['width' =>, 'height' =>, 'depth' =>, 'weight' =>].
     *                      Unset, empty or zero is no limit. Judged BEFORE the
     *                      zone table and independently of it; see below.
     *
     * @return float|null The price, or NULL when this carrier cannot carry the cart.
     *
     * @since  2.0.0
     */
    final public function costFromZones(
        array $zones,
        array $lines,
        array $places,
        ?string $postcode = null,
        float $subtotal = 0.0,
        array $limits = [],
    ): ?float {
        // What the carrier can take is not a price
        //
        // The size and weight ceilings on a cost ROW are a PRICING band: "this
        // price covers up to 2 kg", and a parcel outside it simply falls to the
        // next row. What a locker compartment physically holds is a different
        // kind of fact, and it has to be tested even where no row is ever
        // consulted; otherwise the two configurations that skip the band
        // matching skip the limits with it:
        //
        //   - NO ZONES AT ALL, which means free carriage and returned 0.00
        //     below without measuring anything;
        //   - A ROW PRICED AT NOTHING that the parcel matches ("free over 50"),
        //     which is a real answer and does not fall through to anything.
        //
        // In both, a 40 kg parcel was accepted for a locker at no charge. So
        // the envelope is judged first, on its own, and a parcel outside it is
        // refused whatever the zone table says or does not say.
        $refusal = $this->parcelRefusal($lines, $limits);

        if ($refusal !== null) {
            $this->refusal = $refusal;

            return null;
        }

        // NO ZONES AT ALL IS A CHOICE, NOT A FAILURE. A merchant who published
        // this method and configured no cost rows is offering carriage at no
        // charge, and has been since 1.0. That is the one zero here that still
        // means free, for a parcel the carrier can actually take.
        if ($zones === []) {
            return 0.0;
        }

        // Find the cost zone matching the delivery country. A country-specific match
        // WINS OUTRIGHT: the catch-all (no country restriction) is only a fallback,
        // so it must not overwrite a country match that appears earlier in the list.
        $calculationData = null;
        $fallbackZone = null;
        foreach ($zones as $entry) {
            if (isset($entry['places'])) {
                foreach ($entry['places'] as $place) {
                    if (in_array((int) $place, $places, true)) {
                        $calculationData = $entry;
                        break 2;
                    }
                }
            } else {
                // Remember the catch-all; use it only if no country-specific zone matches.
                $fallbackZone = $entry;
            }
        }

        $calculationData ??= $fallbackZone;

        // THE MERCHANT DREW ZONES AND THIS DESTINATION IS IN NONE OF THEM.
        // Not even the catch-all matched, so there is no configured price for
        // where this cart is going. Quoting 0 here shipped it free.
        if (empty($calculationData)) {
            $this->refusal = $this->refusalKey('NO_ZONE_FOR_DESTINATION');

            return null;
        }

        // Cart value, for the zone's amount band ("free over 50"). The
        // MERCHANDISE total, settled before the question was put; a cart with
        // no priced lines gives 0.0, which only matches a band starting at 0.
        $cost = $this->cheapestMatchingCost($lines, $calculationData['costs'], $postcode, $subtotal);

        // A ZONE WITH NO ROW THAT FITS IS A REFUSAL. The cart is heavier,
        // larger, outside the postcode range or outside the amount band of
        // every row the merchant wrote for this zone, so the merchant has
        // said, by omission, that they do not carry it. Until 1.10 this
        // returned 0 and the order shipped free.
        if ($cost === null) {
            $this->refusal = $this->refusalKey('NO_BAND_FITS');
        }

        return $cost;
    }

    /**
     * Find the cheapest shipping package that fits the cart dimensions.
     *
     * Algorithm:
     *   1. Aggregate all product dimensions into one package
     *   2. Sort available methods by cost ascending
     *   3. First row whose every limit admits the package, the postcode and
     *      the cart value = result
     *
     * The amount band is what expresses "free shipping over 50": a 0.00 row
     * whose amount-start is 50 sorts first and wins for qualifying carts.
     * Every limit is read the same way by isWithinLimits(): unset (missing,
     * empty, or 0) means no limit, so zones configured before a field existed
     * keep matching exactly as they did.
     *
     * @param CartLine[] $products What there is to carry.
     * @param array $shippingMethods Cost rows with their limits.
     * @param string|null $postcode The delivery postcode, or NULL when the
     *                              checkout has not supplied one. NULL is a
     *                              real answer and is never substituted: it
     *                              matches a band with no floor and fails a
     *                              band that names postcodes.
     * @param float $cartTotal Cart value for the amount band.
     * @return float|null The cost, or NULL when no row fits.
     *
     *                    NULL AND 0.0 ARE DIFFERENT ANSWERS. 0.0 is a real
     *                    price: the "free over 50" row is a 0.00 row and it is
     *                    meant to win. NULL is "no row fits", which the caller
     *                    turns into a refusal. Returning 0 for both is what
     *                    made an oversized cart ship free.
     * @since  2.0.0
     */
    final public function cheapestMatchingCost(
        array $products,
        array $shippingMethods,
        ?string $postcode = null,
        float $cartTotal = 0.0,
    ): ?float {
        // Nothing to carry is not a refusal: an empty package has no dimension
        // that can fail, and a zone with no rows was handled by the caller.
        if (empty($products) || empty($shippingMethods)) {
            return 0.0;
        }

        $package = $this->packageOf($products);

        // Sort by cost ascending: first valid match is cheapest
        usort($shippingMethods, fn ($a, $b) => $a['cost'] <=> $b['cost']);

        // SIX LIMITS, ONE TEST. Each was written differently before, and three
        // of the six disagreed about what an unset field meant, which is how a
        // cleared weight ceiling came to match nothing and a corrupt one came to
        // match everything.
        foreach ($shippingMethods as $row) {
            if (
                $this->isWithinLimits($package['width'], max: $row['width-max'] ?? null)
                && $this->isWithinLimits($package['height'], max: $row['height-max'] ?? null)
                && $this->isWithinLimits($package['depth'], max: $row['depth-max'] ?? null)
                && $this->isWithinLimits($package['weight'], max: $row['weight-max'] ?? null)
                && $this->isWithinLimits($postcode, $row['zip-start'] ?? null, $row['zip-end'] ?? null)
                && $this->isWithinLimits($cartTotal, $row['amount-start'] ?? null, $row['amount-end'] ?? null)
            ) {
                return (float) $row['cost'];
            }
        }

        return null;
    }

    /**
     * Aggregate product dimensions into a single package.
     *
     * Width:  summed (products stacked side by side)
     * Height: max (tallest product determines box height)
     * Depth:  max (deepest product determines box depth)
     * Weight: summed (all items x quantity)
     *
     * @param CartLine[] $products What there is to carry.
     * @return array ['width' => float, 'height' => float, 'depth' => float, 'weight' => float]
     * @since  2.0.0
     */
    final public function packageOf(array $products): array
    {
        $totalWidth = 0;
        $maxHeight = 0;
        $maxDepth = 0;
        $totalWeight = 0;

        foreach ($products as $product) {
            // Virtual items ship nothing: skip them in package sizing.
            if ($product->isVirtual()) {
                continue;
            }

            $quantity = $product->quantity();

            $totalWidth += $product->width() * $quantity;
            $maxHeight = max($maxHeight, $product->height());
            $maxDepth = max($maxDepth, $product->depth());
            $totalWeight += $product->weight() * $quantity;
        }

        return [
            'width' => $totalWidth,
            'height' => $maxHeight,
            'depth' => $maxDepth,
            'weight' => $totalWeight,
        ];
    }

    abstract public function onCalculateShippingCost(CalculateShippingCostEvent $event): void;

    //  ADMIN ACTION HOOKS (empty: plugin overrides as needed)

    /**
     * Register available action buttons for a shipment.
     *
     * Override this to add buttons using the fluent API:
     *
     *   $event->add('mark_shipped', 'Mark as Shipped')
     *       ->icon('truck')->css('btn-primary')
     *       ->confirm('Ship this?');
     *
     * Default: no actions.
     *
     * @param object $event GetShipmentActionsEvent
     * @since  1.0.0
     */
    public function onGetActions($event): void
    {
        // Empty: plugin overrides to register actions
    }

    /**
     * Handle an action button click for a shipment.
     *
     * Override and use match() to route actions:
     *
     *   match ($event->getAction()) {
     *       'mark_shipped' => $this->handleMarkShipped($event),
     *       'view_logs'    => $this->handleViewLogs($event),
     *       default        => $event->setError('Unknown action'),
     *   };
     *
     * Default: sets error "Unknown action".
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    public function onExecuteAction($event): void
    {
        $event->setError('Unknown action: ' . $event->getAction());
    }

    /**
     * Veto hook fired before an order is deleted in the admin.
     *
     * The default allows deletion. Override to block it via
     * $event->setResult(false), or to run carrier-side cleanup before the order
     * and its related rows are removed.
     *
     * @param object $event AdminOrderDeleteEvent
     *
     * @since  1.0.0
     */
    public function onAdminOrderDelete($event): void
    {
        $event->setResult(true);
    }
}
