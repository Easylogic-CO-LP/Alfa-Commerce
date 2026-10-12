<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Checkout;

defined('_JEXEC') or die;

/**
 * What actually happened to a placement.
 *
 * A placement has three ends, not two, and the missing one is the dangerous
 * one. It can be refused before anything durable is written; it can succeed
 * outright; or the database can COMMIT and something afterwards can fail: the
 * order model reload, a notification, the credit cascade, a gateway hook, an
 * email. That third case is not a failure, and must not land in the catch the earlier
 * ones do: the order was
 * in the database, the stock was gone, the cart was consumed, and the caller was
 * told the placement had failed.
 *
 * On a storefront that is a shopper seeing an error page for an order that
 * exists. In a back office it is worse: the operator is invited to try again,
 * and trying again is a second commercial action.
 *
 * So COMMITTED IS A FACT, recorded the instant the commit returns, and it
 * survives anything that happens next. A caller can always ask whether the order
 * exists and what its id is; whether the follow-up work finished is a separate
 * question with a separate answer.
 *
 * Failures and warnings are keys and parameters, never exception text: the
 * words that come out of a database or a plugin are not for the person reading
 * the screen, and a caller that had to match on them would not have a contract.
 *
 * @since  1.10.0
 */
final class PlacementOutcome
{
    /** Nothing durable was written. There is no order. */
    public const REJECTED = 'rejected';

    /** The order exists and everything after the commit finished. */
    public const COMMITTED = 'committed';

    /** The order exists. Something after the commit did not finish. */
    public const COMMITTED_WITH_WARNINGS = 'committed_with_warnings';

    /**
     * @param string $state One of the three constants.
     * @param int $orderId The order, or 0 when there is none.
     * @param array $failures Why it was refused, as {code, params}.
     * @param array $warnings What did not finish afterwards, as {code, params}.
     *
     * @since  1.10.0
     */
    private function __construct(
        private string $state,
        private int $orderId,
        private array $failures,
        private array $warnings,
    ) {
    }

    /**
     * Refused. No order, no id, and the reasons.
     *
     * @param array $failures Why.
     *
     *
     * @since  1.10.0
     */
    public static function rejected(array $failures): self
    {
        return new self(self::REJECTED, 0, $failures, []);
    }

    /**
     * Committed. The order exists; the warnings say what did not finish.
     *
     * @param int $orderId The order that exists.
     * @param array $warnings What did not finish after the commit.
     *
     *
     * @since  1.10.0
     */
    public static function committed(int $orderId, array $warnings = []): self
    {
        return new self(
            $warnings === [] ? self::COMMITTED : self::COMMITTED_WITH_WARNINGS,
            max(0, $orderId),
            [],
            $warnings,
        );
    }

    /**
     * DOES THE ORDER EXIST? The only question a caller must never get wrong.
     *
     *
     * @since  1.10.0
     */
    public function isCommitted(): bool
    {
        return $this->state !== self::REJECTED;
    }

    /**
     * Did anything after the commit fail to finish?
     *
     *
     * @since  1.10.0
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * The order id, or 0 when nothing was committed.
     *
     *
     * @since  1.10.0
     */
    public function orderId(): int
    {
        return $this->orderId;
    }

    /**
     * @return string One of REJECTED, COMMITTED, COMMITTED_WITH_WARNINGS.
     *
     * @since  1.10.0
     */
    public function state(): string
    {
        return $this->state;
    }

    /**
     * @return array<int, array{code: string, params: array}>
     *
     * @since  1.10.0
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return array<int, array{code: string, params: array}>
     *
     * @since  1.10.0
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
