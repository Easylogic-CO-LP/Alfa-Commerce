<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use UnexpectedValueException;

defined('_JEXEC') or die;

/**
 * What an entered shipping price means for one shipment.
 *
 * THE ONLY READER OF THE SETTING. Each shipping method owns its mode (Shipping
 * prices entered, #__alfa_shipments.price_entry_mode), and shipping money asks
 * this service, never the method row itself. There is no shop-level default
 * and no inheritance: two methods of one shop may enter their prices
 * differently.
 *
 * @since  2.0.0
 */
final class ShippingPriceEntryModeResolver
{
    /**
     * The entry mode for this shipment: its method's own.
     *
     * A shipment that names no method, or a method that no longer exists, owns
     * no taxes either (ShippingTaxResolver): its charge is untaxed, and the
     * entered price is what the customer pays. A stored value that is neither
     * mode can only come from a write outside Alfa: it is refused, so the
     * method prices nothing rather than a price read with a meaning nobody
     * chose (checkout withdraws the method and logs why).
     *
     * @param ShipmentPricingContext $context The shipment being priced.
     *
     * @throws UnexpectedValueException When the method stores a mode that is neither.
     *
     * @since  2.0.0
     */
    public function resolve(ShipmentPricingContext $context): ShippingPriceEntryMode
    {
        if ($context->methodId <= 0) {
            return ShippingPriceEntryMode::TaxIncluded;
        }

        $methodId = $context->methodId;
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('price_entry_mode'))
                ->from($db->quoteName('#__alfa_shipments'))
                ->where($db->quoteName('id') . ' = :methodId')
                ->bind(':methodId', $methodId, ParameterType::INTEGER),
        )->loadResult();

        if ($stored === null) {
            return ShippingPriceEntryMode::TaxIncluded;
        }

        return ShippingPriceEntryMode::tryFrom((string) $stored)
            ?? throw new UnexpectedValueException(sprintf(
                'Shipping method #%d stores the price entry mode "%s", which is neither tax included nor tax excluded.',
                $methodId,
                (string) $stored,
            ));
    }
}
