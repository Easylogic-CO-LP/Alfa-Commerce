<?php

/**
 *  STANDARD SHIPMENT PLUGIN: Reference Implementation
 *
 * This file is the REFERENCE IMPLEMENTATION for all Alfa shipment plugins.
 * Copy this file as a starting point when building a new plugin (a courier,
 * a locker network, a postal service, etc.). Every method, constant, and pattern
 * used here is explained in detail.
 *
 *  What this plugin does
 *
 * The Standard plugin handles offline shipments: manual fulfillment,
 * local delivery, in-store pickup. It includes a dimension-based shipping
 * cost calculator with zone/weight/zip matching. There is no external
 * carrier API: the admin manually marks shipments as shipped/delivered.
 *
 *  PLUGIN ANATOMY: Files & Structure
 *
 * plugins/alfa-shipments/standard/
 * |-- standard.xml                      <- Manifest (group + params + language)
 * |-- src/Extension/Standard.php        <- THIS FILE (main plugin class)
 * |-- params/
 * |   |-- params.xml                    <- Method config (cost zones)
 * |   `-- logs.xml                      <- Plugin-specific log table schema
 * |-- tmpl/                             <- Layout templates
 * |   |-- default_item_view.php         <- Product page (shipping info)
 * |   |-- default_cart_view.php         <- Cart page (method selector)
 * |   |-- action_view_details.php       <- Admin: shipment details modal
 * |   |-- action_tracking.php           <- Admin: tracking info modal
 * |   `-- default_order_logs_view.php   <- Admin: plugin logs modal
 * `-- services/provider.php             <- Joomla DI service provider
 *
 * Every template here answers a setLayout() call in this file. Three stubs
 * that answered nothing - default_empty.php, default_order_view.php and
 * standard.php (form-field skeleton residue) - were deleted in 1.9.1.
 *
 * Language files are packaged under language/en-GB/ in the plugin's own
 * ZIP but INSTALL to administrator/language/en-GB/, which is where they
 * live on a running site:
 *   administrator/language/en-GB/plg_alfa-shipments_standard.ini
 *   administrator/language/en-GB/plg_alfa-shipments_standard.sys.ini
 * Note the HYPHEN: the file name is plg_<group>_<element>, and the group
 * is "alfa-shipments". The Text:: KEYS drop it
 * (PLG_ALFA_SHIPMENTS_STANDARD_*); the FILE name keeps it.
 *
 *  Inheritance chain
 *
 * Standard extends ShipmentsPlugin extends Plugin extends CMSPlugin
 *
 *   CMSPlugin (Joomla)
 *     `- Plugin (Alfa base)
 *          - Logging: log(), loadLogs(), deleteLog(), getLogsSchema()
 *          - Auto-creates plugin-specific log table from logs.xml
 *          - Utility: isWithinLimits() for postcode, amount and dimension limits
 *          - Abstract hooks: onCartView(), onItemView()
 *
 *        `- ShipmentsPlugin (Alfa shipments base)
 *              - Fluent builder: shipment(), shipmentUpdate()
 *              - Read/delete: getShipment(), getShipmentsByOrder(),
 *                deleteShipment()
 *              - Item assignment: assignShipmentItems()
 *              - Those wrappers return a ShipmentLedger builder. That is the
 *                ONLY place the class name matters: from inside a plugin you
 *                always go through $this->..., never ShipmentLedger::....
 *              - Pricing primitives, so a carrier does not rewrite them:
 *                  costFromZones()      the zone/band cost table, shared with
 *                                       a locker carrier plugin; returns a price, 0.0 for free,
 *                                       or NULL for a refusal
 *                  parcelRefusal()      can you physically take it, judged
 *                                       BEFORE any cost row and independently
 *                                       of one, because two configurations
 *                                       (no zones; a matching free rate) never
 *                                       reach a row at all. Overridable.
 *                  packageOf()          the consignment, quantity included
 *                  placesFor()          a place and everything containing it
 *                  refusalKey()/refusal()  your own language namespace
 *              - Sets logIdentifierField = 'id_order_shipment'
 *              - Abstract: onCalculateShippingCost()
 *              - onGetActions() is empty; onExecuteAction() defaults to
 *                setError('Unknown action'); override BOTH
 *
 *              THE QUOTE HAS FOUR ANSWERS, AND SILENCE IS ONE OF THEM.
 *              setShippingCost(0.0) is FREE; setUnavailable('KEY') is CANNOT
 *              CARRY; and a hook that calls neither has answered nothing, which
 *              fails closed rather than being read as free. One carrier plugin shipped for
 *              months with its pricing hook commented out precisely because the
 *              fourth case used to look like the first.
 *
 *              THE CART IS ONE OBJECT: $event->getCart(). Lines with resolved
 *              per-unit measurements, the settled subtotal, the customer, and
 *              delivery()/invoice() for any checkout field your params.xml
 *              pointed you at. Where it is going is
 *              $cart->delivery()->places(), or ->places(fieldId: $x) for a
 *              field your own method names: the same answer the offer filter
 *              used and placement will re-check.
 *
 *           `- Standard (THIS: concrete implementation)
 *                 - Implements all hooks
 *                 - Dimension-based cost calculator
 *                 - Admin actions: Mark Shipped, Mark Delivered, Cancel
 *                 - Tracking modal
 *                 - Structured plugin logs
 *
 *  THE EVENT SUBJECT: getSubject() and its typed aliases
 *
 * Every Alfa event carries ONE main object under the fixed argument
 * key "subject": whatever the hook is about at that moment. The base
 * event class exposes it untyped:
 *
 *     $event->getSubject()   -> the event's main object
 *
 * Because "the main object" means something different in every hook,
 * each event also declares a DOMAIN-NAMED ALIAS returning the very same
 * thing (the body is literally `return $this->getSubject();`):
 *
 *   ItemViewEvent                getItem()   -> product being viewed
 *   CartViewEvent                getRequest() -> MethodViewRequest
 *   CalculateShippingCostEvent   getRequest() -> ShippingCostRequest
 *   OrderBeforePlaceEvent        getContext()  -> PlacementEventContext of the cart becoming an order
 *   OrderAfterPlaceEvent         getOrder()  -> the committed order
 *   AdminOrderDeleteEvent        getOrder()  -> order being deleted in the admin
 *
 * BOTH work. ALWAYS PREFER THE ALIAS: it names what you actually got,
 * so the hook reads correctly and an IDE can follow the type.
 *
 * Not every event has a subject. GetShipmentActionsEvent and
 * ExecuteShipmentActionEvent extend Joomla's AbstractImmutableEvent
 * directly and carry named arguments (getShipment() and getOrder())
 * with NO getSubject() at all (calling it is a fatal "undefined
 * method"). Their getOrder() is that event's own 'order' argument, not
 * an alias.
 *
 * Writing your own event: never name an accessor after an argument KEY.
 * Joomla's AbstractEvent routes get<Key>()/set<Key>() through
 * getArgument()/setArgument() as deprecated value hooks. Aliasing the
 * subject as getOrder() is safe precisely because no argument is
 * called "order"; the key is always "subject".
 *
 *  SURFACES & LAYOUTS: where your HTML actually lands
 *
 * A shipment plugin NEVER echoes HTML. It names a template and hands
 * over the data; the caller renders it:
 *
 *     $event->setLayout('default_cart_view');        // tmpl/<name>.php
 *     $event->setLayoutData(['method' => ..., 'item' => ...]);
 *
 * The file goes through Joomla's plugin layout chain, so a site
 * template can override any of them without touching the plugin:
 *
 *   templates/<template>/html/plg_alfa-shipments_<element>/<layout>.php
 *   `- parent template (child templates inherit)
 *      `- plugins/alfa-shipments/<element>/tmpl/<layout>.php   <- yours
 *
 * WHERE EACH LAYOUT APPEARS
 *   onItemView()           product page, shipment-methods area
 *   onCartView()           cart / checkout, inside the method selector
 *   onExecuteAction()      the action page (view=action), in a modal
 *   onCalculateShippingCost()  NOTHING: it returns a number, not HTML
 *
 * SURFACES (product page only). Beyond its default slot the item hook
 * can push into shared page slots, so a "Free shipping" badge can sit
 * beside the title instead of down in the methods list:
 *
 *     $event->shipmentMethods()->setLayout('x')->setData([...]);  // default
 *     $event->title()->setLayout('badge')->setData([...]);        // slot
 *     $event->nearAddToCart()->setLayout('badge')->setData([...]);
 *
 * ADMIN ACTION LAYOUTS are not rendered inline. The button opens
 * index.php?option=com_alfa&view=action&tmpl=component... in an iframe
 * modal; that page dispatches onExecuteAction ONCE and echoes whatever
 * layout you set, so your layout is a real page and may load its own
 * scripts and styles (action_tracking.php is the example here).
 *
 *  SHIPMENT LIFECYCLE: State Machine
 *
 *   PENDING --(Mark Shipped)--> SHIPPED --(Mark Delivered)--> DELIVERED
 *   PENDING --(Cancel)--> CANCELLED
 *
 * Valid transitions for Standard (offline):
 *   pending   -> shipped    (handleMarkShipped)  - sets 'shipped' timestamp
 *   shipped   -> delivered  (handleMarkDelivered) - sets 'delivered' timestamp
 *   pending   -> cancelled  (handleCancel)
 *
 * Builder auto-timestamps:
 *   ->shipped()   automatically sets the 'shipped' datetime column
 *   ->delivered() automatically sets BOTH 'shipped' + 'delivered' columns
 *                 (in case shipment goes straight to delivered)
 *
 * Carrier plugins (e.g. express couriers) may have additional transitions:
 *   pending -> in_transit -> out_for_delivery -> delivered
 *   pending -> exception -> returned
 *   (on_hold and returned already exist as ledger statuses)
 *
 *  SPLIT SHIPMENTS: one order, several parcels
 *
 * The diagram above shows ONE shipment because that is all Standard
 * creates. An order may hold as many shipment rows as it needs (
 * #__alfa_order_shipments has no unique key on id_order) and which
 * lines ride in which parcel, and HOW MANY of each, lives in the join
 * table #__alfa_order_shipment_items (id_order_shipment, id_order_item,
 * quantity).
 *
 * Because the quantity is on the JOIN, one order line can be split
 * across parcels: "3 now, 2 Monday" is two rows against the same line.
 *
 * Build a split with ->withItems() instead of ->withAllItems():
 *
 *   // whole lines
 *   $this->shipment($order)->pending()->withItems([101, 102])->save();
 *
 *   // part of a line: 3 units now...
 *   $this->shipment($order)->pending()->withItems([101 => 3])->save();
 *   // ...and the remaining 2 in a later parcel
 *   $this->shipment($order)->pending()->withItems([101 => 2])->save();
 *
 * A plain list means "every unit of these lines that is still
 * unshipped"; a map asks for an exact number. Either way the quantity
 * is CLAMPED to what is actually left, so an over-assignment is
 * corrected rather than recorded, and ->withAllItems() on a second
 * parcel picks up only the remainder.
 *
 * ->withItems() REPLACES that parcel's contents. The admin item picker
 * offers every line with units left, plus whatever this parcel already
 * holds, and its "xN" is what is AVAILABLE, not what was ordered.
 *
 * Nothing marks an order or a shipment as "partial": like payments,
 * fulfilment is DERIVED at read time, and counted in UNITS.
 *
 *   fulfilled_items       units on parcels that are shipped|delivered
 *   total_items           units ordered
 *   fulfillment_status    unfulfilled -> partial -> fulfilled
 *   fulfillment_percentage  units shipped / units ordered x 100
 *
 * So marking the first parcel shipped moves the order to "partial" on
 * its own. There is no refund_type-style column on shipments and no
 * parent/child link between them; the parcels are siblings.
 *
 * Shipping is quoted ONCE per order. Order totals SUM the cost of every
 * shipment row, so a second parcel that charged again would raise the
 * grand total and flip a paid order back to "partial". The ledger
 * enforces this: create() zeroes the cost when some parcel on the order
 * already carries it. Correcting a charge is still possible through the
 * admin form, which edits rather than creates.
 *
 * A worked example lives in the SPLIT SHIPMENT block right after
 * onOrderAfterPlace().
 *
 *  SHIPPING COST CALCULATION: Dimension-Based Engine
 *
 * The Standard plugin calculates shipping by matching cart dimensions
 * against cost-per-place zones defined in the shipping method config:
 *
 *   1. Resolve the delivery places (the destination and what contains it)
 *   2. Find matching zone in cost-per-place[] by country
 *   3. Aggregate product dimensions into single package:
 *      width:  summed, x line quantity (stacked side by side)
 *      height: max (tallest product)
 *      depth:  max (deepest product)
 *      weight: summed (all items x quantity)
 *   4. Sort zone's cost packages by price ascending
 *   5. First package where ALL dimensions fit = cheapest valid option
 *   6. Zip code must be in range (zip-start <= zip <= zip-end)
 *   7. Cart value must be in the amount band (amount-start <= total <=
 *      amount-end); 0..0 means no limit, and amount-end 0 means "and above".
 *      A 0.00 row starting at 50 is how "free shipping over 50" is set up.
 *
 * Carrier plugins typically replace this with API calls:
 *   Carrier A: $carrierA->getRates($origin, $destination, $packages)
 *   Carrier B: $carrierB->requestQuote($shipmentDetails)
 *
 *  FLUENT BUILDER API: Quick Reference
 *
 * CREATE (new shipment record):
 *   $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
 *   $id = $this->shipment($order)->pending()->withItems([101,102])->save();
 *   $id = $this->shipment($order)->shipped()->withAllItems()->cost(0)->save();
 *
 * UPDATE (modify existing):
 *   $this->shipmentUpdate($id)->shipped()->save();
 *   $this->shipmentUpdate($id)->delivered()->save();
 *   $this->shipmentUpdate($id)->cancelled()->save();
 *   $this->shipmentUpdate($id)->trackingNumber('TRACK123')->carrier('Carrier A')->save();
 *
 * READ (no builder needed):
 *   $shipment  = $this->getShipment($id);
 *   $shipments = $this->getShipmentsByOrder($orderId);
 *
 * DELETE / ITEMS (no builder needed):
 *   $this->deleteShipment($id, $orderId);
 *   $this->assignShipmentItems($shipmentId, $orderId, [101, 102]);
 *
 *  BUILDER SETTERS: Full List
 *
 * Status:   ->pending(), ->shipped(), ->delivered(), ->cancelled(),
 *           ->onHold(), ->returned()
 *           (->shipped() auto-sets 'shipped' timestamp)
 *           (->delivered() auto-sets 'shipped' + 'delivered' timestamps)
 *
 * Items:    ->withAllItems(), ->withItems([101, 102]), ->withNoItems()
 *           (->withItems() is how you split an order across parcels)
 *
 * Cost:     ->cost($incl, $excl, $rate), ->cost($incl)  (gross alone = untaxed)
 *
 * Carrier:  ->trackingNumber($num), ->carrier($name)
 *           (->carrier() writes carrier_name: the courier actually
 *            handling the parcel, independent of the shipment METHOD)
 *           There is no ->trackingUrl(): the link is DERIVED from the
 *           method's tracking_url_template param, never stored.
 *
 * Other:    ->weight($kg), ->method($methodId), ->set($key, $value)
 *
 * Terminal: ->save() -> int|false (shipment ID or false)
 * Debug:    ->toArray() -> array (preview without saving)
 *
 *  LOGGING SYSTEM: Plugin-Specific Logs
 *
 * Log table: #__alfa_shipments_standard_logs
 *
 * Auto-created columns (by Plugin.php):
 *   id, id_order, id_order_shipment
 *
 * Plugin columns (from params/logs.xml):
 *   action, status, shipment_total, currency,
 *   tracking_number, carrier_name, note,
 *   created_on, created_by
 *
 * NOTE: Plugin logs are SEPARATE from the unified order_activity_log.
 * The activity log is written automatically by ShipmentLedger.
 * Plugin logs are for carrier-specific debugging data.
 *
 *  CREATING YOUR OWN SHIPMENT PLUGIN: Step by Step
 *
 * 1. Copy plugins/alfa-shipments/standard/ -> plugins/alfa-shipments/yourname/
 * 2. Update namespace: Alfa\Plugin\AlfaShipments\YourName\Extension
 * 3. Rename class: final class YourName extends ShipmentsPlugin
 * 4. Update services/provider.php
 *
 * 5. Key differences for carrier plugins:
 *    - onCalculateShippingCost(): call carrier API for real-time rates
 *    - onOrderAfterPlace(): optionally create shipment label via API
 *    - handleMarkShipped(): call carrier pickup/manifest API
 *    - handleTrack(): fetch live tracking from carrier API
 *    - logs.xml: add carrier-specific columns (api_request_id, label_url)
 *    - params.xml: carrier credentials instead of cost zones
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Path: plugins/alfa-shipments/standard/src/Extension/Standard.php
 *
 * @since  1.0.0
 */

namespace Alfa\Plugin\AlfaShipments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\CartLine;
use Alfa\Component\Alfa\Site\Service\Cart\Method;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

final class Standard extends ShipmentsPlugin
{
    //  FRONTEND HOOKS: Customer-Facing Pages
    //
    //  These render HTML on the storefront. Each hook receives an event
    //  with the relevant context and the shipment method record.
    //  You MUST call setLayout(), even for an empty layout.

    /**
     * Product page: show shipping info (e.g. "Free shipping", "Ships in 2 days").
     *
     * For Standard: shows basic shipping text.
     * For an API carrier: could show estimated delivery date from API.
     * For an express courier: could show "Express delivery available" badge.
     *
     * @param object $event ItemViewEvent
     *                      $event->getItem()   -> product object
     *                      $event->getMethod() -> shipment method record
     * @since  1.0.0
     */
    public function onItemView($event): void
    {
        $event->setLayout('default_item_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item' => $event->getItem(),
        ]);
    }

    /**
     * Cart page: show shipping method selector or cost estimate.
     *
     * For Standard: shows method name and calculated cost.
     * For an API carrier: could show real-time rates for multiple service levels.
     *
     * @param object $event CartViewEvent
     *                      $event->getMethod() -> this method, entire: name(),
     *                      description(), params(), param(), id(), type(),
     *                      isSelected(), and get() for anything else it carries
     *                      $event->getCart() -> the whole cart, read-only
     *                      $event->getCart()->getSubtotal() -> the cart-item amount (Money)
     * @since  1.0.0
     */
    public function onCartView($event): void
    {
        $event->setLayout('default_cart_view');
        // NOTHING IS PASSED BECAUSE NOTHING IS RENDERED. This layout emits no
        // bytes; handing it the whole view request would put an entire cart (
        // its priced lines, its customer and its raw address rows) into layout
        // data that is then carried on the cart snapshot. A presentation payload
        // carries what the template prints, and this one prints nothing.
        $event->setLayoutData([]);
    }

    /**
     * Calculate shipping cost for the current cart and method.
     *
     * This is the CORE hook for shipping cost calculation.
     * Called during cart totals calculation for each active method.
     *
     * For Standard: dimension-based matching against cost zones.
     * For carrier A: call its rate API with package dimensions.
     * For carrier B: call its express rating service with shipment details.
     *
     * Available on $event:
     *   $event->getCart()->lines()         -> CartLine[] - what there is to carry
     *   $event->getCart()->delivery()      -> the address: fields, and where it is going
     *   $event->getMethod()               -> shipment method record (with params)
     *   $event->setShippingCost($cost)     -> the price as configured; core
     *                                       reads it through the shop's entry
     *                                       mode and taxes it for the destination
     *   $event->setShippingAmounts(...)    -> only for a carrier that splits the
     *                                       tax itself (net, tax, gross, rate)
     *
     * @param object $event CalculateShippingCostEvent
     * @since  1.0.0
     */
    public function onCalculateShippingCost($event): void
    {
        // THE HOOK UNWRAPS THE EVENT; NOTHING BELOW IT SEES ONE. Everything the
        // calculation depends on is named in its signature, so it can be read,
        // reasoned about and tested without building a cart.
        $cost = $this->carriageFor($event->getMethod(), $event->getCart());

        // NULL IS "I CANNOT CARRY THIS", 0.0 IS "FREE". Say which through the
        // event; a carrier never needs to name a component class to answer.
        if ($cost === null) {
            $event->setUnavailable($this->refusal());

            return;
        }

        // The price the merchant typed into this method's zones. What it means
        // (tax inside or on top) and which tax it carries are core's to apply.
        $event->setShippingCost($cost);
    }

    /**
     * What this carrier charges to carry that cart, or null, meaning it will
     * not carry it at all.
     *
     * TYPED, AND THAT IS THE POINT. Everything the calculation depends on is
     * named and typed in the signature, so it can be read, reasoned about and
     * tested without building an event, and so the component can prove, from
     * the calling side, that every method this plugin calls on a cart actually
     * exists on the cart it was handed. A `$cart` of no declared type is a
     * plugin that will fatal on a checkout the day the reader is renamed.
     *
     * @param Method $method The carrier being asked, entire.
     * @param Cart $cart The cart being quoted, entire.
     *
     * @return float|null The price, or null when this cart cannot be carried.
     *
     * @since  1.10.0
     */
    private function carriageFor(Method $method, Cart $cart): ?float
    {
        return $this->costFromZones(
            zones: (array) $method->param('cost-per-place', []),
            lines: $cart->lines(),
            // WHERE THIS CARRIER IS BEING JUDGED. The argument is the FIELD
            // this method was configured to read the destination from; leaving
            // it unset inherits the shop's. A field that is set but unanswered,
            // cleared or deleted yields nothing rather than falling back;
            // reverting would price the parcel against an address the merchant
            // did not choose. Same rule the offer filter applied and placement
            // will re-check.
            places: $cart->delivery()->places(fieldId: $method->param('delivery_place_field')),
            postcode: $cart->delivery()->field((int) $method->param('postcode_field'))->value(),
            subtotal: (float) $cart->getSubtotal()->getAmount(),
        );
    }

    //  Order placement hook
    //
    //  Called by OrderPlacement::triggerAfterPlaceEvents() once the order,
    //  its items and the payment row are committed.
    //
    //  This is where you create the INITIAL shipment record.

    /**
     * Create the initial shipment record when an order is placed.
     *
     * WHEN: Called once per order, right after checkout completes.
     * WHO:  Triggered by the frontend OrderPlacement service.
     * WHAT: Creates ONE shipment with ALL items assigned.
     *
     * The fluent builder auto-fills:
     *   - id_order, id_shipment_method -> from $order
     *   - weight -> SUM(items.weight x quantity) auto-calculated
     *   - items -> assigned via ->withAllItems()
     *   - added, id_employee, id_currency -> by the CRUD engine
     *   - method name snapshot -> survives method deletion
     *
     * Shipping cost comes from OrderPlacement, which already overrides
     * total_shipping_tax_incl/excl with cart values before this fires.
     *
     * For carrier plugins that auto-generate labels:
     *   $label = $carrier->createShipment($packageDetails);
     *   $id = $this->shipment($order)
     *       ->pending()
     *       ->withAllItems()
     *       ->cost($shippingCostIncl, $shippingCostExcl, $order->shipmentTaxRate())
     *       ->trackingNumber($label->trackingNumber)
     *       ->carrier('Carrier A')
     *       ->save();
     *
     * @param object $event OrderAfterPlaceEvent
     *                      $event->getOrder() -> full order object
     * @since  1.0.0
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        if (!$order || empty($order->id())) {
            return;
        }

        // Extract shipping cost from order totals
        // OrderPlacement already overrides these from the placement cart
        // before firing this event. No recalculation needed.
        // The reader always answers with Money, so the old isset/is_object
        // dance around a property that might not have been attached is gone.
        $shippingCostIncl = $order->shipmentTotal();
        $shippingCostExcl = $order->shipmentTotalExcl();
        // The rate the charge was taxed at, frozen beside it (valid during
        // onOrderAfterPlace, like the two totals).
        $shippingTaxRate = $order->shipmentTaxRate();

        // Create pending shipment via fluent builder
        // ->pending() sets status = 'pending'
        // ->withAllItems() collects all order item IDs and assigns them
        // ->cost() sets shipping_cost_tax_incl + shipping_cost_tax_excl + shipping_tax_rate
        // ->save() auto-fills: weight, added, id_employee, id_currency, method name
        $shipmentId = $this->shipment($order)
            ->pending()
            ->withAllItems()
            ->cost($shippingCostIncl, $shippingCostExcl, $shippingTaxRate)
            ->save();

        if (!$shipmentId) {
            Log::add(
                'Standard shipment: Failed to create initial shipment for order #' . $order->id(),
                Log::ERROR,
                $this->logCategory(),
            );
        }
    }

    //  THE CART PAGE: code in onCartViewAssets(), markup in onCartView()
    //
    //  TWO HOOKS, BECAUSE THE PAGE AND ITS AJAX ANSWERS ARE NOT THE SAME THING.
    //
    //  onCartViewAssets($event): once per plugin, while the page is BUILT.
    //     The storefront cart and the order builder call it during their HTML
    //     render, for every plugin that owns a PUBLISHED method (offered for
    //     this cart or not) and before Joomla compiles the head. Declare here:
    //
    //         $wa = $event->getWebAssetManager();
    //         $wa->getRegistry()->addRegistryFile('media/plg_alfa-shipments_x/joomla.asset.json');
    //         $wa->useScript('plg_alfa-shipments_x.cart')->useStyle('plg_alfa-shipments_x.cart');
    //
    //         Text::script('PLG_ALFA_SHIPMENTS_X_SAVE_FAILED');
    //         $event->getDocument()->addScriptOptions('plg_alfa-shipments_x', ['saveUrl' => $url]);
    //
    //     Give the script a dependency on com_alfa.cart in joomla.asset.json,
    //     so it is ordered after the cart's own, and name no other dependency
    //     from a registry file this hook does not add itself: a dependency
    //     nobody registered is raised while the head is compiled, outside any
    //     isolation, and takes the page down. Keep it to your bootstrap: a
    //     vendor SDK is that script's job to fetch on first use, and Alfa ships
    //     no loader for it. Standard declares nothing; it has no script.
    //
    //  onCartView($event): once per OFFERED method, on EVERY settle.
    //     That includes the JSON answers to cart.updateShipment,
    //     cart.updatePayment and cart.refreshMethods, which replace the whole
    //     [data-cart-shipments] / [data-cart-payments] block by outerHTML. So
    //     the layout is markup and data-* attributes only. A useScript() there
    //     reaches the head on the first HTML render and is lost on every JSON
    //     swap after it: the panel works once, then stops (under JDEBUG the
    //     cart log names the asset); a <script> tag there never runs after a
    //     swap. Values that
    //     change during checkout (a chosen locker, an amount) go on the panel
    //     as data-* so the swap refreshes them; suffix ids with the method id,
    //     because two methods of one plugin may be offered at once.
    //
    //  YOUR SCRIPT, on the page from the first render:
    //     * bind once, delegated on document: the panel it targets is
    //       replaced on every change;
    //     * initialise from the panel's data-* on alfaCartShipmentChanged,
    //       alfaCartPaymentChanged and alfaCartMethodsRefreshed, dispatched on
    //       document with detail.data after every successful update, and be
    //       idempotent: already mounted -> update, else mount;
    //     * clean up whatever you attached when the panel goes.

    //  HEARING ANYTHING ELSE: getSubscribedEvents() and com_ajax
    //
    //  TWO KINDS OF HOOK, AND ONLY ONE NEEDS DECLARING.
    //
    //  1. The carrier hooks (onCartViewAssets(), onOrderAfterPlace(),
    //     onGetActions(), onExecuteAction(), onShipmentWebhook()) are called
    //     DIRECTLY by the component, which boots this plugin by element and
    //     checks method_exists(). Writing the method IS the subscription;
    //     declaring it changes nothing. The base class subscribes
    //     onItemView/onCartView/onCartViewAssets for you and nothing else is
    //     needed.
    //
    //  2. Anything that travels through Joomla's DISPATCHER (a system event
    //     like onBeforeCompileHead or onAfterRender, or com_ajax) reaches this
    //     plugin ONLY if it is declared, and only once the plugin has been
    //     subscribed: the cart page subscribes it when one of its methods is
    //     offered, and com_ajax imports the whole group itself. Anything you
    //     need on every page is a system plugin's job, not a carrier's.
    //     CMSPlugin::registerListeners() reads:
    //
    //         if ($this instanceof SubscriberInterface) {
    //             $this->getDispatcher()->addSubscriber($this);
    //             return;                  // nothing else is registered, ever
    //         }
    //
    //     These plugins ARE SubscriberInterface implementations, so an
    //     undeclared on*() method is simply never called. Merge, never replace:
    //
    //         public static function getSubscribedEvents(): array
    //         {
    //             return array_merge(parent::getSubscribedEvents(), [
    //                 'onBeforeCompileHead' => 'onBeforeCompileHead',
    //                 'onAjaxMycarrier' => 'onAjaxMycarrier',
    //             ]);
    //         }
    //
    //  AN AJAX ENDPOINT OF YOUR OWN, without adding a controller: com_ajax
    //  calls onAjax<PluginName> on plugins of the group you name. It is also
    //  how your own cart script fetches something for itself mid-checkout:
    //  a locker carrier plugin saves the locker the shopper picked that way; a live quote or a
    //  pickup-point search would go the same road.
    //
    //      index.php?option=com_ajax&group=alfa-shipments&plugin=<yourplugin>&format=json
    //
    //  Append what you answer to the event's result; a listener's return
    //  value is discarded:
    //
    //      $result = (array) $event->getArgument('result', []);
    //      $result[] = ['saved' => true];
    //      $event->setArgument('result', $result);
    //
    //  Three things it will not do for you:
    //
    //    * IT CHECKS NO CSRF TOKEN. If your handler writes anything (session
    //      state, a row), check one yourself: Session::checkToken('request').
    //    * IT CANNOT TELL "nobody handled it" FROM "handled, returned nothing".
    //      An undeclared method answers {"success":true,...,"data":[]}: a 200
    //      that did nothing at all. That empty data is the tell.
    //    * IT IS NOT SEF-AWARE FROM THE BROWSER. A relative
    //      fetch('index.php?option=com_ajax...') posted from /en/some-page
    //      resolves to /en/index.php and the router answers 404. Build the URL
    //      from Joomla.getOptions('system.paths').rootFull, and check
    //      response.ok; a fetch().catch() sees network errors only, never a
    //      404, so a dead endpoint fails invisibly.

    //  REMEMBERING SOMETHING ABOUT A SHIPMENT: the plugin payload
    //
    //  A carrier ends up with facts of its own to keep: a locker id, a service
    //  code, the URL of a label it printed, the last event a webhook reported.
    //  They go in the shipment row's plugin payload. Standard has none of its
    //  own (it books nothing and prints nothing), so it writes none, and this
    //  is here to say how when yours does.
    //
    //  WRITE: pass arrays, never JSON. Encoding is the ledger's job:
    //
    //      $this->shipmentUpdate($shipmentId)
    //          ->pluginData(['parcel_id' => $parcelId, 'status' => 'booked'])
    //          ->save();
    //
    //  Only the keys you name change. The merge happens at save(), against the
    //  row as it is then and with the row held, so a webhook that lands while
    //  your API call is in flight keeps whatever it wrote, and you keep yours.
    //  It is one level deep: a key you write REPLACES what was under it, so to
    //  change one field of a nested array, read it, change it, write it back.
    //
    //  REMOVE: a key that no longer applies goes away entirely:
    //
    //      $this->shipmentUpdate($shipmentId)
    //          ->removePluginData(['label_url'])
    //          ->save();
    //
    //  That is NOT the same as writing null. Null keeps the key ("asked, no
    //  answer") and has() stays true. Removing says the fact is gone.
    //
    //  READ (typed, and checked):
    //
    //      $data = $event->getOrderShipment()->pluginData();
    //
    //      $data->has('parcel_id')                             // stored, even as null
    //      $data->getString(key: 'parcel_id', default: '')     // '0077...', zeros kept
    //      $data->getInt(key: 'compartment', default: 2)
    //      $data->getBool(key: 'insured', default: false)
    //      $data->get(key: 'events', default: [], filter: 'array')
    //      $data->get(key: 'whatever')                         // as stored
    //
    //  A default answers a key that is absent or null. It does NOT answer a key
    //  holding something that will not convert; that throws, because a payload
    //  saying 'six' where a number belongs is a fault to fix, not a 1 to act on.
    //  Under a shopper, catch it: the cart's dispatcher already does, and drops
    //  the method with a message rather than showing a stack trace.
    //
    //  The core never looks inside any of this.

    //  SPLIT SHIPMENT: one order, several parcels
    //
    //  Standard puts everything in one parcel (->withAllItems() above).
    //  A plugin that ships from several warehouses, or splits backordered
    //  units off, creates one shipment row per parcel, and because the
    //  quantity lives on the join table, it can split a LINE, not just
    //  whole lines:
    //
    //      $now   = [];   // [orderItemId => units]
    //      $later = [];
    //
    //      foreach ($order->items as $item) {
    //          $ordered  = (int) $item->quantity;
    //          $inStock  = min($ordered, $this->stockOnHand($item));
    //
    //          if ($inStock > 0) {
    //              $now[(int) $item->id] = $inStock;
    //          }
    //          if ($inStock < $ordered) {
    //              $later[(int) $item->id] = $ordered - $inStock;
    //          }
    //      }
    //
    //      // Parcel 1 carries the shipping charge.
    //      $this->shipment($order)
    //          ->pending()
    //          ->withItems($now)
    //          ->cost($shippingCostIncl, $shippingCostExcl, $order->shipmentTaxRate())
    //          ->save();
    //
    //      // The backorder follows. Its cost is zeroed by the ledger
    //      // whatever you pass; shipping is quoted once per order.
    //      if ($later) {
    //          $this->shipment($order)->pending()->withItems($later)->save();
    //      }
    //
    //  Quantities are clamped to what is still unshipped, so asking for
    //  more than remains is corrected rather than recorded, and a bare
    //  ->withItems([101]) means "all of line 101 that is still to go".
    //
    //  Re-splitting later, outside the builder:
    //
    //      $this->assignShipmentItems($shipmentId, (int) $order->id, [101 => 2]);
    //
    //  It REPLACES that parcel's contents (and only touches lines of the
    //  given order), so moving units is two calls: set the new parcel,
    //  then re-set the old one without them.
    //
    //  Fulfilment is derived, never stored, and counted in UNITS: marking
    //  one parcel shipped is what moves the order to "partial". Nothing
    //  else to write.

    //  ADMIN ACTION HOOKS: Button Registration + Execution
    //
    //  Two hooks control the admin action buttons next to each shipment
    //  in the order edit view:
    //
    //  1. onGetActions()    - register buttons based on current status
    //  2. onExecuteAction() - handle button clicks

    /**
     * Register action buttons based on the current shipment status.
     *
     * Called when the shipments list is rendered in the order edit view.
     *
     * Status logic:
     *   PENDING:   Mark Shipped + Cancel
     *   SHIPPED:   Mark Delivered + Track (if tracking number)
     *   DELIVERED: (no state changes; View Details/Logs still show)
     *   CANCELLED: (no state changes; View Details/Logs still show)
     *
     * An action changes state unless it is declared ->readOnly(): the views
     * declare it, and the transitions are submitted as a POST with a one-time
     * key, never opened as a URL.
     *
     * @param object $event GetShipmentActionsEvent
     *                      $event->getShipment() -> shipment record
     *                      $event->getOrder()    -> order object
     *                      $event->add(name, label) -> register button
     * @since  1.0.0
     */
    public function onGetActions($event): void
    {
        $shipment = $event->getShipment();
        $status = $shipment->status ?? 'pending';

        // Always available

        // View Details: opens a modal with shipment information
        $event->add('view_details', Text::_('COM_ALFA_VIEW_DETAILS'))
            ->icon('eye')->css('btn-outline-secondary')
            ->modal('action_view_details', Text::_('COM_ALFA_SHIPMENT_DETAILS') . ' #' . $shipment->id)
            ->readOnly()
            ->priority(10);

        // View Logs: opens a modal with this shipment's plugin log history
        $event->add('view_logs', Text::_('COM_ALFA_VIEW_LOGS'))
            ->icon('list')->css('btn-outline-info')
            ->modal('default_order_logs_view', Text::_('COM_ALFA_SHIPMENT_LOGS') . ' #' . $shipment->id, 'lg')
            ->readOnly()
            ->priority(5);

        // Pending state

        if (in_array($status, [self::STATUS_PENDING, ''])) {
            // Mark Shipped: transitions to shipped, auto-sets timestamp
            $event->add('mark_shipped', Text::_('PLG_ALFA_SHIPMENTS_STANDARD_MARK_SHIPPED'))
                ->icon('truck')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_SHIPMENTS_STANDARD_CONFIRM_MARK_SHIPPED'))
                ->priority(200);

            // Cancel: transitions to cancelled
            $event->add('cancel', Text::_('COM_ALFA_CANCEL'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_SHIPMENTS_STANDARD_CONFIRM_CANCEL'))
                ->priority(50);
        }

        // Shipped state

        if ($status === self::STATUS_SHIPPED) {
            // Mark Delivered: transitions to delivered, auto-sets timestamp
            $event->add('mark_delivered', Text::_('PLG_ALFA_SHIPMENTS_STANDARD_MARK_DELIVERED'))
                ->icon('checkmark-circle')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_SHIPMENTS_STANDARD_CONFIRM_MARK_DELIVERED'))
                ->priority(200);

            // Track: only if tracking number exists
            $trackingNumber = $shipment->tracking_number ?? '';
            if (!empty($trackingNumber)) {
                $event->add('track', Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACK'))
                    ->icon('location')->css('btn-outline-primary')
                    ->modal('action_tracking', Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACKING') . ' #' . $shipment->id)
                    ->readOnly()
                    ->priority(100);
            }
        }

        // TERMINAL STATES (delivered, cancelled)
        // No STATE-CHANGING actions: the lifecycle is complete. View
        // Details and View Logs are still there: they were registered
        // unconditionally above, before any status branch.
        // Carrier plugins might add "Return Shipment" for delivered.
    }

    /**
     * Handle an admin action button click.
     *
     * Routes the action name to the appropriate handler method.
     *
     * @param object $event ExecuteShipmentActionEvent
     *                      $event->getAction()   -> action name string
     *                      $event->getShipment() -> shipment record
     *                      $event->getOrder()    -> order object
     *                      $event->addButton()   -> footer button on the action
     *                      page; with ['action' => 'x']
     *                      it runs another action of
     *                      THIS shipment in the popup
     *                      $event->getData()     -> ALWAYS [] for shipments and
     *                      payments; ActionRegistry
     *                      passes 'data' => []. Read
     *                      values a layout sent with
     *                      Factory::getApplication()
     *                      ->getInput().
     *
     * This event has NO subject: shipment and order are separate named
     * arguments, so getSubject() does not exist on it.
     *
     * @since  1.0.0
     */
    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'mark_shipped' => $this->handleMarkShipped($event),
            'mark_delivered' => $this->handleMarkDelivered($event),
            'cancel' => $this->handleCancel($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs' => $this->handleViewLogs($event),
            'track' => $this->handleTrack($event),
            default => $event->setError(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_ERROR_UNKNOWN_ACTION', $event->getAction())),
        };
    }

    //  ACTION HANDLERS: Business Logic
    //
    //  Each handler follows the same pattern:
    //    1. Get shipment + order from event
    //    2. Use fluent builder to modify the shipment record
    //    3. Write a structured log entry with ALL schema columns
    //    4. Set success/error message on the event
    //    5. Request page refresh if data changed
    //
    //  Log schema columns (9 plugin-specific + 3 auto):
    //    Auto:    id, id_order, id_order_shipment
    //    Plugin:  action, status, shipment_total, currency,
    //             tracking_number, carrier_name, note,
    //             created_on, created_by

    /**
     * Mark shipment as shipped.
     *
     * Transition: pending -> shipped
     *
     * What happens:
     *   1. Updates status to 'shipped' in #__alfa_order_shipments
     *   2. Builder auto-sets 'shipped' datetime column to now
     *   3. Items in this shipment now count as "fulfilled" in the
     *      orders list view (fulfillment = shipped OR delivered)
     *   4. Writes plugin log + automatic activity log (via helper)
     *
     * For carrier plugins, this might also:
     *   - Call carrier API to create manifest/pickup request
     *   - Generate shipping label PDF
     *   - Send tracking email to customer
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleMarkShipped($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        // The log's shipment_total is handed the exact decimal of the cost.
        $cost = StoredDecimal::of($shipment->shipping_cost_tax_incl);
        $now = Factory::getDate('now', 'UTC')->toSql();

        // ->shipped() sets status = 'shipped' AND auto-sets 'shipped' timestamp
        $result = $this->shipmentUpdate((int) $shipment->id)
            ->shipped()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_ERROR_MARK_SHIPPED', $shipment->id));
            return;
        }

        // Write to the PLUGIN-SPECIFIC log table
        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'mark_shipped',
            'status' => self::STATUS_SHIPPED,
            'shipment_total' => $cost,
            'currency' => $order->currency()?->getCode() ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFA_SHIPMENTS_STANDARD_LOG_MARKED_SHIPPED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_MSG_MARKED_SHIPPED', $shipment->id));
        $event->setRefresh(true);
    }

    /**
     * Mark shipment as delivered.
     *
     * Transition: shipped -> delivered
     *
     * What happens:
     *   1. Updates status to 'delivered' in #__alfa_order_shipments
     *   2. Builder auto-sets BOTH 'shipped' + 'delivered' timestamps
     *      (shipped is set defensively, in case it was missed)
     *   3. Items remain "fulfilled" in the orders list
     *   4. Writes plugin log + automatic activity log
     *
     * For carrier plugins:
     *   - This might be triggered by a webhook instead of admin click
     *   - e.g. a carrier's DELIVERED webhook -> auto-call this handler
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleMarkDelivered($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        // The log's shipment_total is handed the exact decimal of the cost.
        $cost = StoredDecimal::of($shipment->shipping_cost_tax_incl);
        $now = Factory::getDate('now', 'UTC')->toSql();

        // ->delivered() sets status + both shipped/delivered timestamps
        $result = $this->shipmentUpdate((int) $shipment->id)
            ->delivered()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_ERROR_MARK_DELIVERED', $shipment->id));
            return;
        }

        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'mark_delivered',
            'status' => self::STATUS_DELIVERED,
            'shipment_total' => $cost,
            'currency' => $order->currency()?->getCode() ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFA_SHIPMENTS_STANDARD_LOG_MARKED_DELIVERED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_MSG_MARKED_DELIVERED', $shipment->id));
        $event->setRefresh(true);
    }

    /**
     * Cancel a pending shipment.
     *
     * Transition: pending -> cancelled
     *
     * What happens:
     *   1. Updates status to 'cancelled'
     *   2. Items in this shipment become "unfulfilled" again
     *   3. Admin can re-assign items to a new shipment
     *
     * For carrier plugins:
     *   - Call carrier API to void the shipment/label
     *   - e.g. $carrier->cancelShipment($trackingNumber)
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleCancel($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        // The log's shipment_total is handed the exact decimal of the cost.
        $cost = StoredDecimal::of($shipment->shipping_cost_tax_incl);
        $now = Factory::getDate('now', 'UTC')->toSql();

        $result = $this->shipmentUpdate((int) $shipment->id)
            ->cancelled()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_ERROR_CANCEL', $shipment->id));
            return;
        }

        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'cancel',
            'status' => self::STATUS_CANCELLED,
            'shipment_total' => $cost,
            'currency' => $order->currency()?->getCode() ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFA_SHIPMENTS_STANDARD_LOG_CANCELLED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_SHIPMENTS_STANDARD_MSG_CANCELLED', $shipment->id));
        $event->setRefresh(true);
    }

    //  Modal action handlers

    /**
     * Show shipment details in a modal.
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleViewDetails($event): void
    {
        $shipment = $event->getShipment();

        $event->setLayout('action_view_details');
        $event->setLayoutData([
            'shipment' => $shipment,
            'order' => $event->getOrder(),
        ]);
        $event->setModalTitle(Text::_('COM_ALFA_SHIPMENT_DETAILS') . ' #' . $shipment->id);
    }

    /**
     * Show plugin-specific logs in a modal.
     *
     * loadLogs() automatically filters by id_order_shipment because
     * ShipmentsPlugin sets logIdentifierField = 'id_order_shipment'.
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleViewLogs($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();

        $logData = $this->loadLogs((int) $order->id(), (int) $shipment->id);

        $event->setLayout('default_order_logs_view');
        $event->setLayoutData([
            'logData' => $logData ?? [],
            'xml' => $this->getLogsSchema(),
        ]);
        $event->setModalTitle(Text::_('COM_ALFA_SHIPMENT_LOGS') . ' #' . $shipment->id);
    }

    /**
     * Show tracking info in a modal.
     *
     * For Standard: just displays the tracking number.
     * For carrier plugins: could fetch live tracking from API:
     *   $tracking = $carrier->track($shipment->tracking_number);
     *   $event->setLayoutData(['events' => $tracking->events, ...]);
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since  1.0.0
     */
    private function handleTrack($event): void
    {
        $shipment = $event->getShipment();

        $event->setLayout('action_tracking');
        $event->setLayoutData([
            'shipment' => $shipment,
            'order' => $event->getOrder(),
            'tracking_number' => $shipment->tracking_number ?? '',
            'tracking_url' => $this->resolveTrackingUrl($shipment),
        ]);
        $event->setModalTitle(Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACKING') . ' #' . $shipment->id);
    }

    /**
     * Build the carrier's tracking link from the method's URL template.
     *
     * NOT a stored column, on purpose: the shipment row holds the two things
     * that are facts about the parcel (the tracking number and the carrier)
     * while the endpoint is configuration. When a carrier moves its lookup
     * URL, editing the template repairs every order ever placed; a stored URL
     * would leave every historic shipment holding a dead link.
     *
     * Placeholders: {tracking_number}, {carrier_name}.
     *
     * @param object $shipment Shipment record (with ->params from the method)
     *
     * @return string The tracking URL, or '' when there is nothing to link to
     * @since  1.9.0
     */
    private function resolveTrackingUrl(object $shipment): string
    {
        $number = trim((string) ($shipment->tracking_number ?? ''));

        if ($number === '') {
            return '';
        }

        // The action event hands us the method RECORD on ->params; its own
        // configured values sit one level down, under ->params: an array, as
        // everywhere else a method record is handed out.
        $template = (string) ($shipment->params->params['tracking_url_template'] ?? '');

        if (trim($template) === '') {
            return '';
        }

        return strtr($template, [
            '{tracking_number}' => rawurlencode($number),
            '{carrier_name}' => rawurlencode((string) ($shipment->carrier_name ?? '')),
        ]);
    }

    //  SHIPPING COST CALCULATION: Dimension-Based Engine
    //
    //  Products are aggregated by dimensions, then matched against
    //  cost zones sorted by price. Cheapest valid match wins.
    //
    //  Carrier plugins typically REPLACE this entire section with
    //  real-time API rate calls.

    //  Utility methods
}
