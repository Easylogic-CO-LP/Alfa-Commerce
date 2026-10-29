<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\ShipmentLedger;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * The label an order screen shows for a stored code: one lookup per family.
 *
 * Each family maps every value core writes to its language key. A value the
 * map does not know (a plugin's own status or event) is returned as stored.
 * The result is plain text: the caller escapes it.
 *
 * @since  2.1.4
 */
final class OrderLabels
{
    /**
     * A shipment's status.
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const SHIPMENT_STATUS = [
        ShipmentLedger::STATUS_PENDING => 'COM_ALFA_STATUS_PENDING',
        ShipmentLedger::STATUS_SHIPPED => 'COM_ALFA_STATUS_SHIPPED',
        ShipmentLedger::STATUS_DELIVERED => 'COM_ALFA_STATUS_DELIVERED',
        ShipmentLedger::STATUS_CANCELLED => 'COM_ALFA_STATUS_CANCELLED',
        ShipmentLedger::STATUS_ON_HOLD => 'COM_ALFA_STATUS_ON_HOLD',
        ShipmentLedger::STATUS_RETURNED => 'COM_ALFA_STATUS_RETURNED',
    ];

    /**
     * An order line's shipping state on the orders list: its first parcel's
     * status, partial while only some of its units have gone, or none yet.
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const FULFILMENT = self::SHIPMENT_STATUS + [
        '' => 'COM_ALFA_UNASSIGNED',
        'partial' => 'COM_ALFA_STATUS_PARTIAL',
    ];

    /**
     * A payment row's transaction status.
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const TRANSACTION_STATUS = [
        PaymentLedger::STATUS_PENDING => 'COM_ALFA_STATUS_PENDING',
        PaymentLedger::STATUS_AUTHORIZED => 'COM_ALFA_STATUS_AUTHORIZED',
        PaymentLedger::STATUS_COMPLETED => 'COM_ALFA_STATUS_COMPLETED',
        PaymentLedger::STATUS_FAILED => 'COM_ALFA_STATUS_FAILED',
        PaymentLedger::STATUS_CANCELLED => 'COM_ALFA_STATUS_CANCELLED',
        PaymentLedger::STATUS_REFUNDED => 'COM_ALFA_STATUS_REFUNDED',
    ];

    /**
     * A refund row's refund type.
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const REFUND_TYPE = [
        PaymentLedger::REFUND_FULL => 'COM_ALFA_REFUND_TYPE_FULL',
        PaymentLedger::REFUND_PARTIAL => 'COM_ALFA_REFUND_TYPE_PARTIAL',
    ];

    /**
     * The entity part of an order history event (entity.action).
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const HISTORY_ENTITY = [
        'order' => 'COM_ALFA_ORDER_HISTORY_ENTITY_ORDER',
        'item' => 'COM_ALFA_PRODUCT',
        'payment' => 'COM_ALFA_PAYMENT',
        'shipment' => 'COM_ALFA_SHIPMENT',
        'document' => 'COM_ALFA_ORDER_HISTORY_ENTITY_DOCUMENT',
        'stock' => 'COM_ALFA_STOCK',
        'downloads' => 'COM_ALFA_ORDER_HISTORY_ENTITY_DOWNLOADS',
    ];

    /**
     * The action part of an order history event: every action core writes,
     * and the ones the payment and shipment plugins are documented to write.
     *
     * @var array<string, string>
     *
     * @since  2.1.4
     */
    private const HISTORY_ACTION = [
        'created' => 'COM_ALFA_CREATED',
        'edited' => 'COM_ALFA_ORDER_HISTORY_ACTION_EDITED',
        'added' => 'COM_ALFA_ORDER_HISTORY_ACTION_ADDED',
        'deleted' => 'COM_ALFA_ORDER_HISTORY_ACTION_DELETED',
        'customer_changed' => 'COM_ALFA_ORDER_HISTORY_ACTION_CUSTOMER_CHANGED',
        'status_changed' => 'COM_ALFA_ORDER_HISTORY_ACTION_STATUS_CHANGED',
        'transition_refused' => 'COM_ALFA_ORDER_HISTORY_ACTION_TRANSITION_REFUSED',
        'status_followup_failed' => 'COM_ALFA_ORDER_HISTORY_ACTION_STATUS_FOLLOWUP_FAILED',
        'unpaid_exception' => 'COM_ALFA_ORDER_HISTORY_ACTION_UNPAID_EXCEPTION',
        'stock_failed' => 'COM_ALFA_ORDER_HISTORY_ACTION_STOCK_FAILED',
        'captured' => 'COM_ALFA_ORDER_HISTORY_ACTION_CAPTURED',
        'failed' => 'COM_ALFA_STATUS_FAILED',
        'refunded' => 'COM_ALFA_STATUS_REFUNDED',
        'refund_reserved' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_RESERVED',
        'charged_back' => 'COM_ALFA_ORDER_HISTORY_ACTION_CHARGED_BACK',
        'refund_reversed' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_REVERSED',
        'chargeback_reversed' => 'COM_ALFA_ORDER_HISTORY_ACTION_CHARGEBACK_REVERSED',
        'refund_reevaluated' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_REEVALUATED',
        'refund_unreconciled' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_UNRECONCILED',
        'refund_word_added' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_WORD_ADDED',
        'refund_identified' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_IDENTIFIED',
        'refund_beyond_decision' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_BEYOND_DECISION',
        'refund_unbooked_reported' => 'COM_ALFA_ORDER_HISTORY_ACTION_REFUND_UNBOOKED_REPORTED',
        'tracking_updated' => 'COM_ALFA_ORDER_HISTORY_ACTION_TRACKING_UPDATED',
        'delivered' => 'COM_ALFA_STATUS_DELIVERED',
        'issued' => 'COM_ALFA_DOCUMENT_STATUS_ISSUED',
        'voided' => 'COM_ALFA_DOCUMENT_STATUS_VOIDED',
        'replaced' => 'COM_ALFA_ORDER_HISTORY_ACTION_REPLACED',
        'credit_charges' => 'COM_ALFA_ORDER_HISTORY_ACTION_CREDIT_CHARGES',
        'deducted' => 'COM_ALFA_ORDER_HISTORY_ACTION_DEDUCTED',
        'reserved' => 'COM_ALFA_ORDER_HISTORY_ACTION_RESERVED',
        'restored' => 'COM_ALFA_ORDER_HISTORY_ACTION_RESTORED',
        'reconciled' => 'COM_ALFA_ORDER_HISTORY_ACTION_RECONCILED',
        'granted' => 'COM_ALFA_ORDER_HISTORY_ACTION_GRANTED',
        'revoked' => 'COM_ALFA_ORDER_HISTORY_ACTION_REVOKED',
        // The work an order's change owes after its save (2.1.4).
        'email_held' => 'COM_ALFA_ORDER_HISTORY_ACTION_EMAIL_HELD',
        'email_failed' => 'COM_ALFA_ORDER_HISTORY_ACTION_EMAIL_FAILED',
        'email_resent' => 'COM_ALFA_ORDER_HISTORY_ACTION_EMAIL_RESENT',
        'hook_held' => 'COM_ALFA_ORDER_HISTORY_ACTION_HOOK_HELD',
        'hook_failed' => 'COM_ALFA_ORDER_HISTORY_ACTION_HOOK_FAILED',
        'hook_rerun' => 'COM_ALFA_ORDER_HISTORY_ACTION_HOOK_RERUN',
        'task_resolved' => 'COM_ALFA_ORDER_HISTORY_ACTION_TASK_RESOLVED',
    ];

    /**
     * The label of a shipment status.
     *
     * @param string $status The stored status.
     *
     * @return string The label, or the status as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function shipmentStatus(string $status): string
    {
        return self::label(value: $status, keys: self::SHIPMENT_STATUS);
    }

    /**
     * The label of an order line's shipping state on the orders list.
     *
     * @param string $state A shipment status, 'partial', or '' when no parcel carries the line.
     *
     * @return string The label, or the state as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function fulfilment(string $state): string
    {
        return self::label(value: $state, keys: self::FULFILMENT);
    }

    /**
     * The label of a payment row's transaction status.
     *
     * @param string $status The stored status.
     *
     * @return string The label, or the status as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function transactionStatus(string $status): string
    {
        return self::label(value: $status, keys: self::TRANSACTION_STATUS);
    }

    /**
     * The label of a refund row's refund type.
     *
     * @param string $type The stored refund type.
     *
     * @return string The label, or the type as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function refundType(string $type): string
    {
        return self::label(value: $type, keys: self::REFUND_TYPE);
    }

    /**
     * The label of an order history event's action.
     *
     * @param string $action The part of the stored event after its first dot.
     *
     * @return string The label, or the action as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function historyAction(string $action): string
    {
        return self::label(value: $action, keys: self::HISTORY_ACTION);
    }

    /**
     * The label of an order history event's entity.
     *
     * @param string $entity The part of the stored event before its first dot.
     *
     * @return string The label, or the entity as stored when it is not known.
     *
     * @since  2.1.4
     */
    public static function historyEntity(string $entity): string
    {
        return self::label(value: $entity, keys: self::HISTORY_ENTITY);
    }

    /**
     * The translated label of a known value, or the value as stored.
     *
     * @param string $value The stored value.
     * @param array<string, string> $keys The family's map, value => language key.
     *
     *
     * @since  2.1.4
     */
    private static function label(string $value, array $keys): string
    {
        return isset($keys[$value]) ? Text::_($keys[$value]) : $value;
    }
}
