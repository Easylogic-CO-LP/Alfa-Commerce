<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartIdentity;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Throwable;

defined('_JEXEC') or die;

/**
 * The cart as loaded: one immutable value.
 *
 * Which cart it is and on whose terms, the row's columns, the two address
 * rows it points at, and its lines hydrated and priced in repository order:
 * the same line objects the layouts render, never copies. Nothing here is
 * edited in place: a write produces a new value through one of the with*()
 * methods, and the holder replaces the old one.
 *
 * The identity rides on the value because it is what the value is a reading
 * OF, and because it moves exactly where the reading does: after the row is
 * read, after a cart is created, after one is discarded.
 *
 * @since  1.10.0
 */
final class HydratedCart
{
    /**
     * @param CartIdentity $identity Which cart, resolved.
     * @param CartSubject $subject Whose money and whose terms.
     * @param object|null $row The cart row, or null when there is none.
     * @param object[] $lines The hydrated lines, in repository order.
     * @param object|null $deliveryAddress The delivery address row the cart points at.
     * @param object|null $invoiceAddress The invoice address row the cart points at.
     *
     * @since  1.10.0
     */
    private function __construct(
        private readonly CartIdentity $identity,
        private readonly CartSubject $subject,
        private readonly ?object $row,
        private readonly array $lines,
        private readonly ?object $deliveryAddress,
        private readonly ?object $invoiceAddress,
    ) {
    }

    /**
     * A reading of a cart that has no row: nobody yet, or a load that failed.
     *
     * @since  1.10.0
     */
    public static function none(CartIdentity $identity, CartSubject $subject): self
    {
        return new self($identity, $subject, null, [], null, null);
    }

    /**
     * A reading of a loaded cart.
     *
     * @since  1.10.0
     */
    public static function of(
        CartIdentity $identity,
        CartSubject $subject,
        ?object $row,
        array $lines,
        ?object $deliveryAddress = null,
        ?object $invoiceAddress = null,
    ): self {
        return new self($identity, $subject, $row, array_values($lines), $deliveryAddress, $invoiceAddress);
    }

    // which cart

    /** @since 1.10.0 */
    public function identity(): CartIdentity
    {
        return $this->identity;
    }

    /** @since 1.10.0 */
    public function subject(): CartSubject
    {
        return $this->subject;
    }

    /** @return bool Whether a row was loaded. @since 1.10.0 */
    public function exists(): bool
    {
        return $this->row !== null;
    }

    /** @return int The row's id; 0 when there is no row. @since 1.10.0 */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    // the row's columns

    /** @since 1.10.0 */
    public function currencyId(): int
    {
        return (int) ($this->row->id_currency ?? 0);
    }

    /** @since 1.10.0 */
    public function shipmentId(): int
    {
        return (int) ($this->row->id_shipment ?? 0);
    }

    /** @since 1.10.0 */
    public function paymentId(): int
    {
        return (int) ($this->row->id_payment ?? 0);
    }

    /** @since 1.10.0 */
    public function deliveryAddressId(): int
    {
        return (int) ($this->row->id_user_info_delivery ?? 0);
    }

    /** @since 1.10.0 */
    public function invoiceAddressId(): int
    {
        return (int) ($this->row->id_user_info_invoice ?? 0);
    }

    /** @since 1.10.0 */
    public function deliveryAddress(): ?object
    {
        return $this->deliveryAddress;
    }

    /** @since 1.10.0 */
    public function invoiceAddress(): ?object
    {
        return $this->invoiceAddress;
    }

    /**
     * The currency the cart is priced in: the row's, else the first priced
     * line's, else the shop's default.
     *
     * @since  1.10.0
     */
    public function currency(): Currency
    {
        $currencyId = $this->currencyId();

        if ($currencyId > 0) {
            try {
                return Currency::loadById($currencyId);
            } catch (Throwable $e) {
            }
        }

        if ($this->lines !== []) {
            // Read by key: reset() moves the array pointer, which PHP refuses on a read-only property.
            $firstItem = $this->lines[array_key_first($this->lines)];

            if (isset($firstItem->data->price) && is_object($firstItem->data->price)) {
                return $firstItem->data->price->getCurrency();
            }
        }

        return Currency::getDefault();
    }

    // the lines

    /** @return object[] @since 1.10.0 */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @since 1.10.0 */
    public function line(int $lineId): ?object
    {
        foreach ($this->lines as $cartItem) {
            if ((int) $cartItem->id === $lineId) {
                return $cartItem;
            }
        }

        return null;
    }

    /** @since 1.10.0 */
    public function isEmpty(): bool
    {
        return count($this->lines) === 0;
    }

    /** @since 1.10.0 */
    public function itemCount(): int
    {
        return count($this->lines);
    }

    /** @since 1.10.0 */
    public function quantity(): int
    {
        $quantity = 0;

        foreach ($this->lines as $cartItem) {
            $quantity += $cartItem->quantity ?? 0;
        }

        return $quantity;
    }

    /**
     * The lines the shopper pays for and the carrier carries.
     *
     * Every line not flagged unavailable, whatever flagged it: a withdrawn
     * product, or a unit that is out of stock and still priced. A flagged line
     * stays in lines() and on the page so the shopper sees what became
     * unavailable; it adds no money and needs no carriage.
     *
     * @return object[] The same line objects as lines(), in the same order.
     *
     * @since  1.14.0
     */
    public function payableLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (object $line): bool => empty($line->unavailable),
        ));
    }

    /**
     * Whether every payable line is a virtual product. A cart with no payable
     * line is not virtual, as an empty cart is not.
     *
     * @since  1.10.0
     */
    public function isVirtual(): bool
    {
        $lines = $this->payableLines();

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (empty($line->data->is_virtual)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The category and manufacturer scopes of each line, for method
     * eligibility; a carrier is not asked about virtual lines.
     *
     * @since  1.10.0
     */
    public function lineScopes(string $kind): array
    {
        $scopes = [];

        foreach ($this->lines as $line) {
            if ($kind === 'shipment' && !empty($line->data->is_virtual)) {
                continue;
            }

            $scopes[] = [
                'categories' => array_map('intval', array_keys((array) ($line->data->categories ?? []))),
                'manufacturers' => array_map('intval', array_keys((array) ($line->data->manufacturers ?? []))),
            ];
        }

        return $scopes;
    }

    // the next value

    /** @since 1.10.0 */
    public function withIdentity(CartIdentity $identity): self
    {
        return new self($identity, $this->subject, $this->row, $this->lines, $this->deliveryAddress, $this->invoiceAddress);
    }

    /** @since 1.10.0 */
    public function withLines(array $lines): self
    {
        return new self($this->identity, $this->subject, $this->row, array_values($lines), $this->deliveryAddress, $this->invoiceAddress);
    }

    /** @since 1.10.0 */
    public function withShipment(int $shipmentId): self
    {
        return $this->withColumn('id_shipment', $shipmentId);
    }

    /** @since 1.10.0 */
    public function withPayment(int $paymentId): self
    {
        return $this->withColumn('id_payment', $paymentId);
    }

    /** @since 1.10.0 */
    public function withPointers(int $deliveryId, ?int $invoiceId): self
    {
        $next = $this->withColumn('id_user_info_delivery', $deliveryId);

        return $invoiceId !== null && $invoiceId > 0 ? $next->withColumn('id_user_info_invoice', $invoiceId) : $next;
    }

    /** @since 1.10.0 */
    public function withUpdated(string $at): self
    {
        return $this->withColumn('updated', $at);
    }

    /**
     * The row with one column changed. A reading with no row keeps having
     * none: there is nothing to mirror a write onto.
     */
    private function withColumn(string $column, mixed $value): self
    {
        if ($this->row === null) {
            return $this;
        }

        $row = clone $this->row;
        $row->{$column} = $value;

        return new self($this->identity, $this->subject, $row, $this->lines, $this->deliveryAddress, $this->invoiceAddress);
    }
}
