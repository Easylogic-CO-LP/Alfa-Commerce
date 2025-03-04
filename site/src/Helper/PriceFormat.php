<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Helper;
use Joomla\CMS\Component\ComponentHelper;


defined('_JEXEC') or die;

use \Joomla\CMS\Factory;

/**
 * Class PriceFormat
 *
 * @since  1.0.1
 */
class PriceFormat
{

    /*
     * Inputs: the price value, and the format settings.
     * If there are no format settings provided, we look for cached settings.
     *      If there are also no cached settings, we then make a DB query.
     *      We cache the query settings.
     * Format the $value.
     * Returns: the formatted $value.
     */
    public static function format($value, $decimal_place=null, $decimal_symbol=null, $thousand_separator=null, $pattern = null){

        $symbol = '';
        //If settings have not been given, 
        if($decimal_place == null || $decimal_symbol == null || $thousand_separator == null || $pattern == null || !strpos($pattern, "{number}")) {

            //We check for cached settings.
            $cache = Factory::getCache('com_alfa','');
            $cache->setCaching(true);
            $cacheKey = "general_currency_settings";
            $generalCurrencySettings = $cache->get($cacheKey);

            //Applying the cached settings if they exist.
            if(!empty($generalCurrencySettings)) {
                $symbol = $generalCurrencySettings->symbol;
                $decimal_place = $generalCurrencySettings->decimal_place;
                $decimal_symbol = $generalCurrencySettings->decimal_symbol;
                $thousand_separator = $generalCurrencySettings->thousand_separator;
                $pattern = $generalCurrencySettings->format_pattern;
            }//If there are no cached settings, we make a DB query.
            else {

                // $settings = self::getGeneralSettings();
                $settings = ComponentHelper::getParams('com_alfa');

                $defaultCurrID = $settings->get('default_currency', '978'); //Euro

                try{
                    $db = Factory::getContainer()->get('DatabaseDriver');
                    $query = $db->getQuery(true);
                    $query->
                    select("symbol, decimal_place, decimal_symbol, thousand_separator, format_pattern")->
                    from('#__alfa_currencies')->
                    where($db->quoteName('number') . '=' . (int) $defaultCurrID);
                    $db->setQuery($query);
                }
                catch (\Exception $e) {
                    Factory::getApplication()->enqueueMessage($e->getMessage());
                    return false;
                }

                $DBSettings = $db->loadObject();

                //We cache the settings retrieved by the DB query.
                $cache->store($DBSettings, $cacheKey);

                //We apply the DB settings.
                $symbol = $DBSettings->symbol;
                $decimal_place = $DBSettings->decimal_place;
                $decimal_symbol = $DBSettings->decimal_symbol;
                $thousand_separator = $DBSettings->thousand_separator;
                $pattern = $DBSettings->format_pattern;
            }
        }

        //Finally, we apply all the settings to our value and return it.
        $value = number_format((float)$value, $decimal_place, $decimal_symbol, $thousand_separator);
        $value = str_replace("{number}", $value, $pattern);
        $value = str_replace("{symbol}", $symbol, $value);

        return $value;

    }

	
}
