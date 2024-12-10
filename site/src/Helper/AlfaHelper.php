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
     * Inputs: $categoryID -> the id of the category whose settings we want.
     * Finds the price display settings related to the id via a DB query.
     * Returns: An array with these settings.
     */
    public static function getCategorySettings($categoryID = 0){

        $categoryID = intval($categoryID);

        // TODO: check to make our own cache that always run to avoid duplicates
        $cache = Factory::getCache('com_alfa','');
        $cache->setCaching(true); // Force caching for this instance
        
        $cacheKey = "category_settings_" . $categoryID;
        $categorySettings = $cache->get($cacheKey);

        if(!empty($categorySettings)){
            return $categorySettings;
        }

        $generalSettings = self::getGeneralSettings();

        // empty object with general settings in case no category found
        $generalPriceSettings = [
            'base_price_show' => $generalSettings->get('base_price_show'),
            'base_price_show_label' => $generalSettings->get('base_price_show_label'),

            'base_price_with_discounts_show' => $generalSettings->get('base_price_with_discounts_show') ,
            'base_price_with_discounts_show_label' => $generalSettings->get('base_price_with_discounts_show_label'),

            'tax_amount_show' => $generalSettings->get('tax_amount_show'),
            'tax_amount_show_label' => $generalSettings->get('tax_amount_show_label'),

            'base_price_with_tax_show' => $generalSettings->get('base_price_with_tax_show'),
            'base_price_with_tax_show_label' => $generalSettings->get('base_price_with_discounts_show_label'),

            'discount_amount_show' => $generalSettings->get('discount_amount_show'),
            'discount_amount_show_label' => $generalSettings->get('discount_amount_show_label'),

            'final_price_show' => $generalSettings->get('final_price_show'),
            'final_price_show_label' => $generalSettings->get('final_price_show_label'),
        ];

        // Creating an object based on general settings
        $categoryObject = new \stdClass();
        $categoryObject->id = 0;
        $categoryObject->prices = $generalPriceSettings;

        if($categoryID <= 0){
            return $categoryObject;
        }


        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select('*')
            ->from($db->qn('#__alfa_categories'))
            ->where($db->qn('id').' = ' . $db->q($categoryID));
        
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
