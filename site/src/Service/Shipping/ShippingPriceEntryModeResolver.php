<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * What an entered shipping price means for one shipment.
 *
 * THE ONLY READER OF THE SETTING. Shipping money asks this service and never
 * the component configuration, so the answer can come from a narrower scope
 * later (shop default, then a seller's own mode, then a method's) by a change
 * here alone. Today the shop default is the only scope.
 *
 * @since  2.0.0
 */
final class ShippingPriceEntryModeResolver
{
    /**
     * The component option holding the shop default.
     *
     * @since  2.0.0
     */
    public const SHOP_DEFAULT = 'shipping_price_entry_mode';

    /**
     * The entry mode for this shipment.
     *
     * An absent option is the declared default, tax included. A stored value
     * the list does not offer can only come from a hand edit: it is read as the
     * declared default and logged, so the shop keeps quoting.
     *
     * @param ShipmentPricingContext $context The shipment being priced.
     *
     * @since  2.0.0
     */
    public function resolve(ShipmentPricingContext $context): ShippingPriceEntryMode
    {
        $stored = (string) ComponentHelper::getParams('com_alfa')
            ->get(self::SHOP_DEFAULT, ShippingPriceEntryMode::TaxIncluded->value);
        $mode = ShippingPriceEntryMode::tryFrom($stored);

        if ($mode === null) {
            Log::add(
                'Unknown shipping price entry mode "' . $stored . '" (method #' . $context->methodId
                . '); read as tax included.',
                Log::WARNING,
                'com_alfa.cart',
            );

            return ShippingPriceEntryMode::TaxIncluded;
        }

        return $mode;
    }
}
