<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

use RuntimeException;

\defined('_JEXEC') or die;

/**
 * An operator's resolution of an order line's stock identity, or a correction of
 * its effective resolution, was refused.
 *
 * Thrown by InventoryService::resolveLine() before anything is written, or from
 * inside its transaction, which then rolls back: no resolution row, operation or
 * re-armed stock effect remains. The reason tells the caller which sentence to
 * show; the message names the order line.
 *
 * @since  1.14.0
 */
final class InventoryLineResolutionException extends RuntimeException
{
    /** No reason was given, or it is longer than 255 characters. */
    public const REASON_REQUIRED = 'reason_required';

    /** No signed-in operator. */
    public const OPERATOR_REQUIRED = 'operator_required';

    /** The action is not one the kernel knows. */
    public const ACTION_UNKNOWN = 'action_unknown';

    /** The order line no longer exists. */
    public const LINE_GONE = 'line_gone';

    /**
     * The line's effective resolution is not the one the correction names: it was
     * recorded or corrected after the operator looked at it, or none was named
     * while one exists.
     */
    public const RESOLUTION_CHANGED = 'resolution_changed';

    /** Stock has already moved for the line on the effective resolution's identity. */
    public const RESOLUTION_CONSUMED = 'resolution_consumed';

    /** The line's product no longer exists, so no stock identity of it can be chosen. */
    public const ITEM_GONE = 'item_gone';

    /** The line's own stock identity still exists, so there is nothing to resolve. */
    public const IDENTITY_RESOLVABLE = 'identity_resolvable';

    /** A line sold on the product itself cannot be bound to a combination. */
    public const PARENT_LINE_NOT_REBOUND = 'parent_line_not_rebound';

    /** The chosen identity is not a stock identity of the line's product. */
    public const TARGET_NOT_UNIT = 'target_not_unit';

    /** Stock is still out or reserved on the line's original identity. */
    public const HISTORY_ON_ORIGINAL = 'history_on_original';

    /**
     * @param int $lineId The order line.
     * @param string $reason One of the class constants.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $lineId,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Inventory: the stock identity of order line #%d was not resolved (%s); nothing was recorded.',
            $lineId,
            match ($reason) {
                self::REASON_REQUIRED => 'a reason of 1 to 255 characters is required',
                self::OPERATOR_REQUIRED => 'a signed-in operator is required',
                self::ACTION_UNKNOWN => 'the action is not known',
                self::LINE_GONE => 'the order line no longer exists',
                self::RESOLUTION_CHANGED => 'its recorded resolution is not the one this correction names',
                self::RESOLUTION_CONSUMED => 'stock has already moved for it on the recorded stock identity',
                self::ITEM_GONE => 'the product it was sold as no longer exists',
                self::IDENTITY_RESOLVABLE => 'the stock identity it was sold as still exists',
                self::PARENT_LINE_NOT_REBOUND => 'a line sold on the product itself cannot be bound to a combination',
                self::TARGET_NOT_UNIT => 'the chosen identity is not a stock identity of the same product',
                self::HISTORY_ON_ORIGINAL => 'stock is still out or reserved on the identity it was sold as',
                default => $reason,
            },
        ));
    }
}
