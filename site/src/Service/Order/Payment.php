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
 * One money movement on an order, read.
 *
 * A payment, a refund or an authorization: the ledger's own row, as a gateway
 * reads it back on a return leg or a webhook.
 *
 *     $payment->pluginData()->getInt(key: 'installments', default: 1)
 *
 * READ-ONLY. Writing goes through PaymentLedger: `$this->paymentUpdate($id)`
 * in a plugin.
 *
 * @since  2.0.0
 */
final class Payment
{
    /**
     * @param object $row The `#__alfa_order_payments` record.
     *
     * @since  2.0.0
     */
    private function __construct(private readonly object $row)
    {
    }

    /**
     * Read a payment; idempotent, as the other readers are.
     *
     * @param object $row The row, or a reader already made of one.
     *
     *
     * @since  2.0.0
     */
    public static function of(object $row): self
    {
        return $row instanceof self ? $row : new self($row);
    }

    /** @return int The payment row's own id. @since 2.0.0 */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    /** @return int The order it belongs to. @since 2.0.0 */
    public function orderId(): int
    {
        return (int) ($this->row->id_order ?? 0);
    }

    /** @return int The payment method it was taken with, 0 when none. @since 2.0.0 */
    public function methodId(): int
    {
        return (int) ($this->row->id_payment_method ?? 0);
    }

    /** @return string The gateway plugin, as the order recorded it. @since 2.0.0 */
    public function methodType(): string
    {
        return trim((string) ($this->row->payment_method_type ?? ''));
    }

    /** @return string payment, refund, authorization, reversal or chargeback. @since 2.0.0 */
    public function type(): string
    {
        return trim((string) ($this->row->payment_type ?? ''));
    }

    /** @return string pending, authorized, completed, failed, cancelled or refunded. @since 2.0.0 */
    public function status(): string
    {
        return trim((string) ($this->row->transaction_status ?? ''));
    }

    /** @return string The gateway's own transaction handle. @since 2.0.0 */
    public function transactionId(): string
    {
        return trim((string) ($this->row->transaction_id ?? ''));
    }

    /**
     * What this row moves.
     *
     * A row the ledger loaded carries Money; a bare row carries a number,
     * which is money in its order's currency and in no other.
     *
     *
     * @throws OrderCurrencyException When the row is bare and its order names no currency.
     *
     * @since  2.0.0
     */
    public function amount(): Money
    {
        $value = $this->row->amount ?? null;

        return $value instanceof Money ? $value : Money::of((float) $value, OrderCurrency::of(orderId: $this->orderId()));
    }

    /**
     * What the gateway plugin recorded about this attempt.
     *
     *     $payment->pluginData()->getInt(key: 'installments', default: 1)
     *
     * Write it back through the ledger, which merges:
     * `$this->paymentUpdate($id)->pluginData([...])->save()`.
     *
     * @return PluginPayload Empty when the gateway has written nothing yet.
     *
     * @throws \Alfa\Component\Alfa\Site\Service\Data\ValueException
     *                                                               When what is stored is not readable JSON; never silently empty.
     *
     * @since  2.0.0
     */
    public function pluginData(): PluginPayload
    {
        return PluginPayload::of(
            $this->row->plugin_data ?? null,
            'payment #' . $this->id() . ' plugin_data',
        );
    }

    /**
     * The row as the ledger loaded it.
     *
     *
     * @since  2.0.0
     */
    public function row(): object
    {
        return $this->row;
    }
}
