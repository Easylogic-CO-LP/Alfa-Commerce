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
 * How a shipping charge's figures were formed, frozen with the parcel.
 *
 * Tax included or tax excluded: derived from a price under that entry mode, by
 * the calculation every shipping charge is derived by; a change entered later is
 * judged the same way. Carrier-stated: a carrier's own figures, taken as stated
 * and never derived again. Only a carrier's answer, or a carrier's own write,
 * makes a charge carrier-stated; nobody chooses it.
 *
 * @since  1.14.0
 */
enum ShippingChargeBasis: string
{
    case TaxIncluded = 'tax_included';
    case TaxExcluded = 'tax_excluded';
    case CarrierStated = 'carrier_stated';

    /**
     * The basis of a charge derived under an entry mode.
     *
     * @param ShippingPriceEntryMode $mode The mode the price was read under.
     *
     *
     * @since  1.14.0
     */
    public static function ofMode(ShippingPriceEntryMode $mode): self
    {
        return self::from($mode->value);
    }

    /**
     * The entry mode the figures were derived under; null for a carrier's own.
     *
     *
     * @since  1.14.0
     */
    public function mode(): ?ShippingPriceEntryMode
    {
        return ShippingPriceEntryMode::tryFrom($this->value);
    }
}
