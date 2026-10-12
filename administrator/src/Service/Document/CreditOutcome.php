<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

/**
 * What a credit calculation comes to.
 *
 * One answer for every entrance that issues a credit, so a screen chooses its
 * words from the outcome and never judges the money itself ("The UI
 * layer may inspect the resulting domain outcome to choose the appropriate
 * message, but it must not independently calculate whether the credit is
 * zero").
 *
 * @since  1.14.0
 */
enum CreditOutcome: string
{
    /** The total is not 0,00 at the corrected document's scale. */
    case Issuable = 'issuable';

    /** A 0,00 credit whose every unit is quantity the order no longer holds. */
    case QuantityCorrection = 'quantity_correction';

    /** No refundable money exists. */
    case NothingRefundable = 'nothing_refundable';

    /** Refundable money exists, but the current selections produce 0,00. */
    case SelectionZero = 'selection_zero';

    /** No credit may be calculated on the order. */
    case Unavailable = 'unavailable';

    /** The picks or the issuer refused the credit. */
    case Invalid = 'invalid';

    /**
     * Whether a credit with this outcome may be issued.
     *
     * @return bool True for Issuable and QuantityCorrection only.
     *
     * @since   1.14.0
     */
    public function issuable(): bool
    {
        return $this === self::Issuable || $this === self::QuantityCorrection;
    }
}
