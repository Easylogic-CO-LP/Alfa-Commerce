<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * Where a priced line's stated unit comes from, as the engine priced it.
 *
 * Runtime provenance for presentation only: it says how a unit may be shown,
 * and no amount is ever computed from it.
 *
 * @since  1.14.0
 */
enum UnitOrigin: string
{
    /** A price row's own stored value: the canonical base price. */
    case PriceRow = 'price_row';

    /** A sale's own stated price, on the axis the sale states it. */
    case Sale = 'sale';

    /** An agreed custom rate for the line. */
    case AgreedRate = 'agreed_rate';

    /** A unit computed for a variant: a combination or option adjust, or a gross target. */
    case Computed = 'computed';
}
