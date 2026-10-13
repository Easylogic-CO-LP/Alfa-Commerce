<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

defined('_JEXEC') or die;

/**
 * Why a caller is reading a source's line state, and therefore whose stock
 * movements are that caller's to compensate.
 *
 * The ledger anchors every movement on the ORDER LINE, so a delivery note's
 * deduction and the order's own deduction sit on the same line id. Summing
 * them is right for one caller and wrong for the other, and the difference is
 * intent, not the shape of the query:
 *
 *   an ordinary order edit owns only what the ORDER claimed. An issued
 *   document moved goods as its own business act, and retyping a quantity is
 *   not a decision to undo it;
 *
 *   a cancellation owns everything the line holds, whoever moved it. That is
 *   the sanctioned reversal DocumentIssuer::void() defers to when it refuses
 *   to un-ship a parcel.
 *
 * Reading the intent rather than the caller keeps the rule in one place: a new
 * reconciliation path states which of these it is and inherits the right
 * answer, instead of the reader growing a special case per caller.
 *
 * @since  2.0.0
 */
enum ReconciliationIntent: string
{
    /**
     * An ordinary edit to the current order: quantity, unit, configuration.
     *
     * Sees only movements this source wrote. Document-origin movements stay
     * where they are: the edit changes what the order says today, and the
     * difference from what documents already stated is reconciliation drift,
     * reported elsewhere, never silently compensated on the shelf.
     */
    case OrderEdit = 'order_edit';

    /**
     * Cancellation, or another legitimate physical action that ends the
     * line's claim on the shelf.
     *
     * Sees every movement anchored on the line, including those another
     * domain wrote, because this IS the sanctioned path that brings those
     * units back.
     */
    case LifecycleRestore = 'lifecycle_restore';

    /**
     * Whether movements written by ANOTHER source against the same line
     * belong to this reconciliation.
     *
     *
     * @since  2.0.0
     */
    public function ownsForeignLineMovements(): bool
    {
        // match, not a comparison: a third intent added later must STATE its
        // answer. A `$this === self::LifecycleRestore` would silently hand it
        // order-edit semantics, which is the same silent default the required
        // $intent parameter exists to prevent, one level down.
        return match ($this) {
            self::OrderEdit => false,
            self::LifecycleRestore => true,
        };
    }
}
