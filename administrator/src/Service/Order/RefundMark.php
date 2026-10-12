<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

/**
 * Where a payment's refunds stand: the id of the newest refund row booked
 * against it, whatever that row's status.
 *
 * What is left to refund is not such a figure: a recorded reversal raises it
 * and a chargeback lowers it, so it can return to a value it had before. The
 * mark moves only when a refund of this payment is booked, and row ids are
 * never reused. A refund form carries the mark it was drawn with, so a form
 * from before a refund stays refused after it; a provider request key carries
 * it, so a retry after no answer is the same request, and two genuine refunds
 * are never one.
 *
 * It returns to an earlier value only when the newest refund row is deleted,
 * which the ledger allows only for a row that never moved money: the payment
 * is then back in that earlier state.
 *
 * @since  1.14.0
 */
final class RefundMark
{
    /**
     * The mark of a payment.
     *
     * @param int $paymentId The payment row (#__alfa_order_payments.id).
     *
     * @return int The newest refund row's id; 0 while the payment has none.
     *
     * @since  1.14.0
     */
    public static function of(int $paymentId): int
    {
        if ($paymentId <= 0) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $type = PaymentLedger::TYPE_REFUND;
        $query = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('id') . ')')
            ->from($db->quoteName('#__alfa_order_payments'))
            ->where($db->quoteName('id_refunded_payment') . ' = :payment')
            ->where($db->quoteName('payment_type') . ' = :type')
            ->bind(':payment', $paymentId, ParameterType::INTEGER)
            ->bind(':type', $type);

        return (int) $db->setQuery($query)->loadResult();
    }
}
