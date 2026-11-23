<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

/**
 * What a payment's settle by staff came to ({@see PaymentLedger::staffSettle()}): moved (or already in the
 * status asked), or refused, with the outcome that says why and the sentence the caller reads. Each door words the
 * outcome in its own terms: the REST settle verb answers 400, 409 or 422.
 *
 * @since  2.3.0
 */
final class StaffSettleResult
{
    /**
     * The payment is in the status asked: moved now, or already there.
     *
     * @since  2.3.0
     */
    public const SETTLED = 'settled';

    /**
     * A reversal or a chargeback: a provider's fact, born completed, with no status to settle.
     *
     * @since  2.3.0
     */
    public const FACT = 'fact';

    /**
     * The status asked is not a payment status.
     *
     * @since  2.3.0
     */
    public const UNKNOWN_STATUS = 'unknown_status';

    /**
     * Refunded was asked: money is returned by the refund, never by a status write.
     *
     * @since  2.3.0
     */
    public const REFUND_VERB = 'refund_verb';

    /**
     * The payment is not in the status the caller said it is in.
     *
     * @since  2.3.0
     */
    public const STALE = 'stale';

    /**
     * The refund block, or what the order's pending provider refunds reserve.
     *
     * @since  2.3.0
     */
    public const REFUND_BLOCKED = 'refund_blocked';

    /**
     * Another writer changed the payment first.
     *
     * @since  2.3.0
     */
    public const RACED = 'raced';

    /**
     * The ledger refused the write (its staff guard).
     *
     * @since  2.3.0
     */
    public const REFUSED = 'refused';

    /**
     * @param string $outcome One of the constants.
     * @param string $sentence What the caller reads; '' when settled.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $sentence = '',
    ) {
    }

    /**
     * Whether the payment is in the status asked.
     *
     * @since  2.3.0
     */
    public function settled(): bool
    {
        return $this->outcome === self::SETTLED;
    }
}
