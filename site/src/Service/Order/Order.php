<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Site\Service\Cart\AddressView;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingChargeBasis;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The order as a plugin reads it: the settled twin of {@see \Alfa\Component\Alfa\Site\Service\Cart\Cart}.
 *
 * NAMED ACCESSORS, AND NOTHING ELSE. A plugin used to be handed the raw model
 * row and read whatever property it hoped was there, which is silent when the
 * hope is wrong: five properties are read across the shipped plugins today that
 * nothing anywhere assigns: `total_shipping`, `total_shipping_price_tax_incl`,
 * `customer_phone`, `billing_firstname`, `billing_lastname`. Each reads as
 * empty, so a carrier plugin logged a carriage of 0 and a payment plugin has never sent a customer
 * phone to the gateway, and neither failed loudly enough to be noticed. There is
 * no property proxy here on purpose: a name this class does not answer is a
 * mistake at the call site, and it should say so.
 *
 * HISTORICAL, NOT CURRENT. Everything here is what the ORDER records. It never
 * reads the checkout session, never reads pending cart input, and never falls
 * back to the buyer's account as it stands today.
 *
 * @since  1.10.0
 */
final class Order
{
    /** @var AddressView|null Memoised, because a carrier asks repeatedly. @since 1.10.0 */
    private ?AddressView $delivery = null;

    /** @var AddressView|null @since 1.10.0 */
    private ?AddressView $invoice = null;

    /** @var Shipment[]|null Memoised; a carrier asks repeatedly. @since 1.10.0 */
    private ?array $shipments = null;

    /** @var Payment[]|null @since 1.10.0 */
    private ?array $payments = null;

    /**
     * @param object $row The order as the model loaded it.
     *
     * @since  1.10.0
     */
    private function __construct(private readonly object $row)
    {
    }

    /**
     * Read an order.
     *
     * IDEMPOTENT ON PURPOSE. Core services are handed an order from two
     * directions (the models load a row, the events hand a plugin this
     * reader) and a service that had to know which it was given would grow a
     * type check at every entry. Handing back what it was given when that is
     * already a reader keeps ONE canonical type at those boundaries without a
     * proxy underneath it.
     *
     * @param object $row The order row, or a reader already made of one.
     *
     *
     * @since  1.10.0
     */
    public static function of(object $row): self
    {
        return $row instanceof self ? $row : new self($row);
    }

    // identity

    /** @return int The order id. @since 1.10.0 */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    /** @return string The merchant-facing reference. @since 1.10.0 */
    public function reference(): string
    {
        return (string) ($this->row->reference ?? '');
    }

    /** @return string The key a return link is signed with. @since 1.10.0 */
    public function secureKey(): string
    {
        return (string) ($this->row->secure_key ?? '');
    }

    /**
     * The name of the person who PLACED this order, as the order recorded it.
     *
     * THE BUYER, NOT THE RECIPIENT. An order may be delivered to someone else
     * entirely (a gift, an office, a parcel locker booked for a friend) so a
     * carrier addressing a parcel wants {@see self::delivery()}, not this. This
     * is who the shop sold to.
     *
     * SAVED, NEVER THE ACCOUNT AS IT STANDS NOW. `customer_name` is written on
     * the order when it is placed. If the buyer later edits their profile, or
     * the account is deleted, this still says who bought, which is the point of
     * recording it. Nothing here consults the live account;
     * {@see \Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity} does
     * that deliberately for admin display, and it is the wrong answer for
     * anything that books, bills or ships.
     *
     * GUARANTEED TO HAVE BEEN ASKED FOR: `name` is a system role the field model
     * keeps published, required at checkout, and un-renamable. It can still be
     * empty on an order imported around the checkout, which is why a consumer
     * that requires it must check rather than assume.
     *
     *
     * @since  1.10.0
     */
    public function getName(): string
    {
        return trim((string) ($this->row->customer_name ?? ''));
    }

    /**
     * The e-mail of the person who PLACED this order, as the order recorded it.
     *
     * Same contract as {@see self::getName()}: the buyer, saved on the order,
     * never the current account profile.
     *
     *
     * @since  1.10.0
     */
    public function getEmail(): string
    {
        return trim((string) ($this->row->customer_email ?? ''));
    }

    /**
     * Whether this order was placed without an account.
     *
     * A guest is a first-class customer here, not a missing one. Note that this
     * is NOT "has no id_user": an account deleted after the order also has none,
     * and that is a different thing: the order was placed by a member who has
     * since gone. `customer_is_guest` records what was true at the time.
     *
     *
     * @since  1.10.0
     */
    public function isGuest(): bool
    {
        return (int) ($this->row->customer_is_guest ?? 0) === 1;
    }

    /** @return int The account that placed it, 0 for a guest or a deleted account. @since 1.10.0 */
    public function customerId(): int
    {
        return (int) ($this->row->id_user ?? 0);
    }

    // where it is going

    /**
     * The delivery address this order was placed against.
     *
     * WHO THE PARCEL IS FOR. Read it the way a cart's address is read: by role
     * for what the shop knows, by field id for what a plugin was configured to
     * name:
     *
     *     $order->delivery()->role('name')->value()     the recipient
     *     $order->delivery()->role('email')->value()
     *     $order->delivery()->field($configuredId)->value()
     *     $order->delivery()->places()
     *
     * HISTORICAL, AND ENFORCED AS SUCH. The order points at a
     * `#__alfa_user_info` row, and once it does the component's own writers
     * refuse to touch it: completing its blanks lands on a new row, merging into
     * it is refused, and deleting it is refused. So these values are what the
     * order was placed with, not what the customer's address book says today.
     *
     * NO PENDING INPUT, EVER. The AddressView is built with an empty pending
     * array, so a checkout still in progress in the same request cannot leak
     * into a historical read. That is the one thing this must never do.
     *
     * LOADED HERE IF THE MODEL DID NOT. Only OrderModel::getItem() attaches
     * ->user_info, and plugins are handed orders on paths that do not, so the
     * row is fetched on demand rather than answering "no address" on half the
     * hooks.
     *
     * @return AddressView Missing throughout when the order names no address.
     *
     * @since  1.10.0
     */
    public function delivery(): AddressView
    {
        return $this->delivery ??= new AddressView(
            $this->row->user_info ?? $this->addressRow((int) ($this->row->id_address_delivery ?? 0)),
        );
    }

    /**
     * The invoice address, read the same way; the delivery one when it billed
     * to itself.
     *
     *
     * @since  1.10.0
     */
    public function invoice(): AddressView
    {
        if ($this->invoice !== null) {
            return $this->invoice;
        }

        $invoiceId = (int) ($this->row->id_address_invoice ?? 0);

        if ($invoiceId <= 0 || $invoiceId === (int) ($this->row->id_address_delivery ?? 0)) {
            return $this->invoice = $this->delivery();
        }

        return $this->invoice = new AddressView(
            $this->row->user_info_invoice ?? $this->addressRow($invoiceId),
        );
    }

    // what is being sent, and what has been paid

    /**
     * The shipments on this order, as canonical readers.
     *
     *     foreach ($order->shipments() as $shipment) {
     *         $locker = $shipment->pluginData()->getString(key: 'locker_id', default: '');
     *     }
     *
     * PLURAL, BECAUSE AN ORDER IS. One order can be split across parcels (two
     * warehouses, a backorder following on) so there is no "the" shipment to
     * ask for at the order level, and a helper that quietly returned the first
     * would be right until the day a shop split an order and then silently
     * wrong. An event that concerns ONE shipment hands that one over itself,
     * through getOrderShipment().
     *
     * Newest last, in creation order, which is the order the parcels were made.
     *
     * @return Shipment[] Empty when nothing has been shipped yet.
     *
     * @since  1.10.0
     */
    public function shipments(): array
    {
        return $this->shipments ??= array_map(
            static fn (object $row): Shipment => Shipment::of($row),
            $this->rowsFor('#__alfa_order_shipments'),
        );
    }

    /**
     * The money movements on this order, as canonical readers.
     *
     *     $term = $order->payments()[0]->pluginData()->getInt(key: 'installments', default: 1);
     *
     * Payments, refunds and authorizations alike: the ledger's rows in the
     * order they were written.
     *
     * @return Payment[] Empty when nothing has been taken yet.
     *
     * @since  1.10.0
     */
    public function payments(): array
    {
        return $this->payments ??= array_map(
            static fn (object $row): Payment => Payment::of($row),
            $this->rowsFor('#__alfa_order_payments'),
        );
    }

    // money and contents

    /**
     * The currency the order was priced in.
     *
     * A loaded order carries it; a bare row names it, and it is read from
     * what the row names ({@see OrderCurrency}). Never another currency in
     * its place: a gateway asked to charge in one the order does not name
     * would take money the order does not owe.
     *
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  1.10.0
     */
    public function currency(): Currency
    {
        $loaded = $this->row->currency ?? null;

        return $loaded instanceof Currency
            ? $loaded
            : OrderCurrency::ofRow(
                orderId:    $this->id(),
                currencyId: $this->row->id_currency ?? null,
                scale:      $this->row->currency_scale ?? null,
            );
    }

    /**
     * What the customer pays in total, tax included.
     *
     *
     * @since  1.10.0
     */
    public function total(): Money
    {
        return $this->money($this->row->total_paid_tax_incl ?? null);
    }

    /**
     * Whether the order carries a COMPUTED total, not merely a stored column.
     *
     * THE TWO ARE NOT INTERCHANGEABLE AT PLACEMENT, which is the only reason
     * this exists. A hydrated order carries `total_paid_tax_incl` as Money that
     * the placement has already reconciled; a row read straight from the table
     * carries a number that, at the moment of placement, is short by the
     * carriage: the shipment row does not exist yet. So a caller deciding what
     * to charge must know which it is holding and recompute in the second case,
     * rather than quietly asking a gateway for a total missing its shipping.
     *
     *
     * @since  1.10.0
     */
    public function hasComputedTotal(): bool
    {
        return ($this->row->total_paid_tax_incl ?? null) instanceof Money;
    }

    /**
     * Carriage on this order, tax included.
     *
     * The plugins reached for `total_shipping` and
     * `total_shipping_price_tax_incl` for this, and NOTHING assigns either, so
     * a carrier plugin's log row has been recording a carriage of 0 since it was written.
     *
     *
     * @since  1.10.0
     */
    public function shipmentTotal(): Money
    {
        return $this->money($this->row->total_shipping_tax_incl ?? null);
    }

    /** @return Money Carriage, tax excluded. @since 1.10.0 */
    public function shipmentTotalExcl(): Money
    {
        return $this->money($this->row->total_shipping_tax_excl ?? null);
    }

    /**
     * The rate the order's carriage was taxed at, in percent; 0.0 untaxed.
     *
     * Placement states it beside the two carriage totals for the carrier that
     * writes the parcel row in onOrderAfterPlace, so the row freezes the rate
     * the charge was taxed at. An order read anywhere else may carry several
     * parcels at different rates and states no single one: it answers 0.0, and
     * a parcel created later states its own rate.
     *
     *
     * @since  1.14.0
     */
    public function shipmentTaxRate(): float
    {
        return (float) ($this->row->shipping_tax_rate ?? 0.0);
    }

    /**
     * How the order's carriage figures were formed: under the shop's entry
     * mode, or as the carrier stated them; null when the order states none.
     *
     * Placement states it beside the rate, for the parcel row the carrier
     * writes in onOrderAfterPlace. An order read anywhere else states none.
     *
     *
     * @since  1.14.0
     */
    public function shipmentChargeBasis(): ?ShippingChargeBasis
    {
        $basis = $this->row->shipping_charge_basis ?? null;

        return $basis === null ? null : ShippingChargeBasis::from((string) $basis);
    }

    /**
     * The rates the order's carriage was calculated with, in the order they
     * apply, as exact decimal texts; null when the order states none.
     *
     * Placement states it beside the basis, for the parcel row the carrier
     * writes in onOrderAfterPlace. An order read anywhere else states none.
     *
     * @return string[]|null
     *
     * @since  1.14.0
     */
    public function shipmentTaxChain(): ?array
    {
        $chain = $this->row->shipping_tax_chain ?? null;

        return $chain === null ? null : array_values(array_map('strval', (array) $chain));
    }

    /** @return int The chosen gateway's method id. @since 1.10.0 */
    public function paymentMethodId(): int
    {
        return (int) ($this->row->id_payment_method ?? 0);
    }

    /** @return int The shipment method the order was placed with, 0 when none. @since 1.10.0 */
    public function shipmentMethodId(): int
    {
        return (int) ($this->row->id_shipment_method ?? 0);
    }

    /**
     * The language the BUYER is to be addressed in, as the order recorded it.
     *
     * Not the admin's language and not the storefront's: a webhook, a carrier
     * callback or a cron-driven retry runs with no customer session at all, and
     * the buyer still gets their own language on a payment page or an e-mail.
     *
     * @return int The `#__languages` id, 0 when the order names none.
     *
     * @since  1.10.0
     */
    public function languageId(): int
    {
        return (int) ($this->row->id_language ?? 0);
    }

    /** @return object|null The chosen payment method record. @since 1.10.0 */
    public function paymentMethod(): ?object
    {
        return \is_object($this->row->selected_payment ?? null) ? $this->row->selected_payment : null;
    }

    /** @return array The ordered lines. @since 1.10.0 */
    public function items(): array
    {
        return (array) ($this->row->items ?? []);
    }

    /**
     * The order row underneath, for the component's own code.
     *
     * Not part of the plugin-facing contract: a plugin reaching for this is
     * asking for something this reader should have offered, and that is a gap to
     * close here rather than to route around.
     *
     *
     * @since  1.10.0
     */
    public function row(): object
    {
        return $this->row;
    }

    // internals

    /**
     * The child rows of one table for this order.
     *
     * Read here rather than trusting what the model attached: plugins are
     * handed orders on paths that attach nothing, and an accessor that answered
     * "no shipments" on half of them would be worse than one that costs a
     * query.
     *
     * @param string $table The child table.
     *
     * @return object[] In creation order.
     *
     * @since  1.10.0
     */
    private function rowsFor(string $table): array
    {
        $orderId = $this->id();

        if ($orderId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        return $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName($table))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];
    }

    /**
     * One `#__alfa_user_info` row, when the model did not attach it.
     *
     * @param int $id The address id.
     *
     *
     * @since  1.10.0
     */
    private function addressRow(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        return $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . $id),
        )->loadObject() ?: null;
    }

    /**
     * A money value the model may have attached as Money or as a bare number.
     *
     * @param mixed $value The stored total.
     *
     *
     * @since  1.10.0
     */
    private function money(mixed $value): Money
    {
        return $value instanceof Money ? $value : Money::of((float) $value, $this->currency());
    }
}
