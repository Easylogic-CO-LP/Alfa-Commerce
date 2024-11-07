<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use \Joomla\CMS\Component\ComponentHelper;

class PriceFormatter
{
    /**
     * Formats the price based on the provided settings or component defaults.
     *
     * @param float $amount The amount to format.
     * @param array $settings Optional settings for currency formatting.
     * @return string The formatted price.
     */
    public static function format(float $amount, array $settings = []): string
    {
        // Get Joomla component configuration
        $config = ComponentHelper::getParams('com_alfa');

        // Set each property, using settings if provided, otherwise falling back to component defaults
        $currencySymbol = $settings['currencySymbol'] ?? $config->get('currencySymbol', "$");
        $decimalSeparator = $settings['decimalSeparator'] ?? $config->get('decimalSeparator', ".");
        $thousandSeparator = $settings['thousandSeparator'] ?? $config->get('thousandSeparator', ",");
        $decimalPlaces = $settings['decimalPlaces'] ?? $config->get('decimalPlaces', 2);
        $formatPattern = $settings['formatPattern'] ?? $config->get('formatPattern', "{symbol}{number}");

        $formattedNumber = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandSeparator
        );

        return str_replace(
            ['{number}', '{symbol}'],
            [$formattedNumber, $currencySymbol],
            $formatPattern
        );
    }
}
