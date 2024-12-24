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
use \Joomla\CMS\MVC\Model\BaseDatabaseModel;
use \Joomla\Database\DatabaseDriver;

/**
 * Class AlfaFrontendHelper
 *
 * @since  1.0.1
 */
class AlfaHelper
{

    /*
     * Inputs: the price value, and the format settings.
     * If there are no format settings provided, we look for cached settings.
     *      If there are also no cached settings, we then make a DB query.
     *      We cache the query settings.
     * Format the $value.
     * Returns: the formatted $value.
     */
    public static function formattedPrice($value, $decimal_place=null, $decimal_symbol=null, $thousand_separator=null, $pattern = null){

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
                $settings = self::getGeneralSettings();
                $defaultCurrID = $settings->get('default_currency', '978'); //Euro

                try{
                    $db = Factory::getContainer()->get('DatabaseDriver');
                    $query = $db->getQuery(true);
                    $query->
                    select("symbol, decimal_place, decimal_symbol, thousand_separator, format_pattern")->
                    from('#__alfa_currencies')->
                    where('id=' . $defaultCurrID);
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

    /**
     * Inputs: void (nothing).
     * Sends a DB query to get the general currency settings.
     * Returns: general currency settings or null if they are not found.
     */
//    public static function getGeneralCurrencySettings(){
//        $settings = self::getGeneralSettings();
//        $defaultCurrID = $settings->get('default_currency');
//
//        $db = Factory::getContainer()->get('DatabaseDriver');
//        $query = $db->getQuery(true);
//
//        $query->
//            select("symbol, decimal_place, decimal_symbol, thousand_separator, format_pattern")->
//            from('#__alfa_currencies')->
//            where('id=' . $defaultCurrID);
//        $db->setQuery($query);
//
//        return $db->loadObject() ?: null;
//    }



    //SELECT p.symbol, p.currency_decimal_place, p.currency_decimal_symbol, p.currency_decimal_separator, p.currency_thousand_separator
    //FROM ms0bn_alfa_items_prices as it
    //JOIN ms0bn_alfa_currencies as p ON it.currency_id=p.id
    //WHERE it.id = x;


    public static function getGeneralSettings(){

        // $cache = Factory::getCache('com_alfa','');
        // $cacheKey = "com_alfa_settings";
        // $cachedSettings = $cache->get($cacheKey);

        // if(!empty($cachedSettings)) {
        //     return $cachedSettings;
        // }

        $settings = ComponentHelper::getParams('com_alfa');

        // $cache->store($settings, $cacheKey);

        return $settings;

    }

    /*
     * Inputs: $categoryId -> the id of the category whose settings we want.
     * Finds the price display settings related to the id via a DB query.
     * Returns: An array with these settings.
     */
    public static function getCategorySettings($categoryId = 0){

        $categoryId = intval($categoryId);

        if($categoryId<=0){
            $app = Factory::getApplication();
            $filters = $app->input->get('filter', []);
            $categoryId = $filters['category_id'] ?? 0;
        }

        // TODO: check to make our own cache that always run to avoid duplicates
        $cache = Factory::getCache('com_alfa','');
        // $cache->setCaching(true); // Force caching for this instance
        
        $cacheKey = "category_settings_" . $categoryId;
        $categorySettings = $cache->get($cacheKey);

        if(!empty($categorySettings)){
            return $categorySettings;
        }

        $generalSettings = self::getGeneralSettings();

        // empty object with general settings in case no category found
        $generalPriceSettings = [
            'base_price_show' => $generalSettings->get('base_price_show',1),
            'base_price_show_label' => $generalSettings->get('base_price_show_label',1),

            'base_price_with_discounts_show' => $generalSettings->get('base_price_with_discounts_show',1) ,
            'base_price_with_discounts_show_label' => $generalSettings->get('base_price_with_discounts_show_label',1),

            'tax_amount_show' => $generalSettings->get('tax_amount_show',1),
            'tax_amount_show_label' => $generalSettings->get('tax_amount_show_label',1),

            'base_price_with_tax_show' => $generalSettings->get('base_price_with_tax_show',1),
            'base_price_with_tax_show_label' => $generalSettings->get('base_price_with_discounts_show_label',1),

            'discount_amount_show' => $generalSettings->get('discount_amount_show',1),
            'discount_amount_show_label' => $generalSettings->get('discount_amount_show_label',1),

            'final_price_show' => $generalSettings->get('final_price_show',1),
            'final_price_show_label' => $generalSettings->get('final_price_show_label',1),
        ];

        // Creating an object based on general settings
        $categoryObject = new \stdClass();
        $categoryObject->id = $categoryId;
        $categoryObject->prices = $generalPriceSettings;

        if($categoryId <= 0){
            return $categoryObject;
        }


        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select('*')
            ->from($db->qn('#__alfa_categories'))
            ->where($db->qn('id').' = ' . $db->q($categoryId));
        
        $db->setQuery($query);

        $categoryDbObject = $db->loadObject();

        if(empty($categoryDbObject)){ return $categoryObject; }

        $categoryDbObject->prices = json_decode($categoryDbObject->prices,true);


        // base price
        if($categoryDbObject->prices['base_price_show'] == -1){
            $categoryDbObject->prices['base_price_show'] = $generalPriceSettings['base_price_show'];
        }
        if($categoryDbObject->prices['base_price_show_label'] == -1){
            $categoryDbObject->prices['base_price_show_label'] = $generalPriceSettings['base_price_show_label'];
        }
 
        // base price with discounts
        if($categoryDbObject->prices['base_price_with_discounts_show'] == -1){
            $categoryDbObject->prices['base_price_with_discounts_show'] = $generalPriceSettings['base_price_with_discounts_show'];
        }
        if($categoryDbObject->prices['tax_amount_show_label'] == -1){
            $categoryDbObject->prices['tax_amount_show_label'] = $generalPriceSettings['tax_amount_show_label'];
        }

        // tax amount
        if($categoryDbObject->prices['tax_amount_show'] == -1){
            $categoryDbObject->prices['tax_amount_show'] = $generalPriceSettings['tax_amount_show'];
        }
        if($categoryDbObject->prices['tax_amount_show_label'] == -1){
            $categoryDbObject->prices['tax_amount_show_label'] = $generalPriceSettings['tax_amount_show_label'];
        }

        // base price with tax
        if($categoryDbObject->prices['base_price_with_tax_show'] == -1){
            $categoryDbObject->prices['base_price_with_tax_show'] = $generalPriceSettings['base_price_with_tax_show'];
        }
        if($categoryDbObject->prices['base_price_with_tax_show_label'] == -1){
            $categoryDbObject->prices['base_price_with_tax_show_label'] = $generalPriceSettings['base_price_with_tax_show_label'];
        }

        // discount amount
        if($categoryDbObject->prices['discount_amount_show'] == -1){
            $categoryDbObject->prices['discount_amount_show'] = $generalPriceSettings['discount_amount_show'];
        }
        if($categoryDbObject->prices['discount_amount_show_label'] == -1){
            $categoryDbObject->prices['discount_amount_show_label'] = $generalPriceSettings['discount_amount_show_label'];
        }

        // final
        if($categoryDbObject->prices['final_price_show'] == -1){
            $categoryDbObject->prices['final_price_show'] = $generalPriceSettings['final_price_show'];
        }
        if($categoryDbObject->prices['final_price_show_label'] == -1){
            $categoryDbObject->prices['final_price_show_label'] = $generalPriceSettings['final_price_show_label'];
        }

        $cache->store($categoryDbObject, $cacheKey);

        return $categoryDbObject;
        
    }


	
}
