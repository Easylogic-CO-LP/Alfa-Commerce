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
 * Which side of the tax a priced line's stated unit is on, as the engine derived it.
 *
 * Runtime provenance for presentation only: it is not stored with a price row
 * and no amount is ever computed from it.
 *
 * @since  2.0.0
 */
enum UnitAxis: string
{
    /** The unit is the net a piece; the gross is reached through the tax. */
    case Net = 'net';

    /** The unit is the gross a piece; the net is split out of it. */
    case Gross = 'gross';

    /** No tax changes the unit: it is the net and the gross alike. */
    case Both = 'both';
}
