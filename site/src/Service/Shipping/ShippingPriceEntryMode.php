<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

defined('_JEXEC') or die;

/**
 * What a shipping price the merchant entered means.
 *
 * The meaning of the entered figure, and nothing about the tax: which rate
 * applies is the destination's question ({@see ShippingTaxResolver}). The same
 * entered price therefore means the same thing in every jurisdiction.
 *
 * @since  2.0.0
 */
enum ShippingPriceEntryMode: string
{
    /** The entered price is what the customer pays; the tax is inside it. */
    case TaxIncluded = 'tax_included';

    /** The entered price is before tax; the tax is added on top. */
    case TaxExcluded = 'tax_excluded';
}
