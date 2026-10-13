<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

use RuntimeException;
use Throwable;

\defined('_JEXEC') or die;

/**
 * A multi-line stock command was refused whole because at least one of its lines
 * cannot act on the stock identity it names, while that line still has quantity to
 * move.
 *
 * Per line, the reason is one of:
 *   identity_gone  the identity no longer exists (the combination row is gone or
 *                  belongs to another item, or the item is gone) and the line has no
 *                  recorded resolution: fulfilment, cancellation and returns of it
 *                  are refused until the operator resolves it;
 *   not_current    the identity still exists as order history (an archived
 *                  combination, the bare item of a product with combinations) but is
 *                  no longer a unit that can be sold or reserved: only reserve
 *                  refuses for this reason.
 *
 * Raised by the command's pre-flight before any movement is written, or rethrown
 * from an identity failure detected under the unit's row locks, after which the
 * command's savepoint rolls back everything it wrote. Either way no line of the
 * command moved: a command never completes partially while silently skipping a
 * line.
 *
 * @since  2.0.0
 */
final class InventoryLineUnresolvedException extends RuntimeException
{
    /** The line's stock identity no longer exists and has no recorded resolution. */
    public const REASON_IDENTITY_GONE = 'identity_gone';

    /** The line's stock identity exists but can no longer be sold or reserved. */
    public const REASON_NOT_CURRENT = 'not_current';

    /**
     * @param string $command The refused command (reserve, commit, restore, reverse_restore).
     * @param array $lines One entry per refused line: line_id, item_id,
     *                     combination_id, quantity, reason (a REASON_*
     *                     constant; identity_gone when absent).
     * @param Throwable $previous The identity failure detected under lock, if any.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $command,
        public readonly array $lines,
        ?Throwable $previous = null,
    ) {
        $message = sprintf('Inventory: %s refused and nothing was moved.', $command);

        foreach ($lines as $line) {
            $combinationId = (int) ($line['combination_id'] ?? 0);
            $identity = sprintf(
                ' Order line #%d refers to %s of item #%d',
                (int) ($line['line_id'] ?? 0),
                $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
                (int) ($line['item_id'] ?? 0),
            );

            $message .= self::lineReason(line: $line) === self::REASON_NOT_CURRENT
                ? $identity . ', which can no longer be sold or reserved.'
                : $identity . sprintf(
                    ', which no longer exists and has no recorded resolution (%s to move).',
                    InventoryAvailabilityService::formatQuantity(value: (float) ($line['quantity'] ?? 0)),
                );
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * The refusal reason of one line (identity_gone when the entry carries none).
     *
     * @param array $line One entry of $lines.
     *
     * @return string A REASON_* constant.
     *
     * @since  2.0.0
     */
    public static function lineReason(array $line): string
    {
        return ($line['reason'] ?? self::REASON_IDENTITY_GONE) === self::REASON_NOT_CURRENT
            ? self::REASON_NOT_CURRENT
            : self::REASON_IDENTITY_GONE;
    }

    /**
     * The order line ids named by the refusal.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function lineIds(): array
    {
        return array_map(static fn (array $line): int => (int) ($line['line_id'] ?? 0), $this->lines);
    }
}
