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
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

/**
 * Class AlfaFrontendHelper
 *
 * @since  1.0.1
 */
class AlfaHelper
{
    public static function getGeneralSettings()
    {

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
    public static function getCategorySettings($categoryId = 0)
    {

        $categoryId = intval($categoryId);

        if ($categoryId <= 0) {
            $app = Factory::getApplication();
            $filters = $app->input->get('filter', []);
            $categoryId = $filters['category_id'] ?? 0;
        }

        // TODO: check to make our own cache that always run to avoid duplicates
        $cache = Factory::getCache('com_alfa', '');
        // $cache->setCaching(true); // Force caching for this instance

        $cacheKey = "category_settings_" . $categoryId;
        $categorySettings = $cache->get($cacheKey);

        if (!empty($categorySettings)) {
            return $categorySettings;
        }

        $generalSettings = self::getGeneralSettings();

        // empty object with general settings in case no category found
        $generalPriceSettings = [
            'base_price_show' => $generalSettings->get('base_price_show', 1),
            'base_price_show_label' => $generalSettings->get('base_price_show_label', 1),

            'base_price_with_discounts_show' => $generalSettings->get('base_price_with_discounts_show', 1) ,
            'base_price_with_discounts_show_label' => $generalSettings->get('base_price_with_discounts_show_label', 1),

            'tax_amount_show' => $generalSettings->get('tax_amount_show', 1),
            'tax_amount_show_label' => $generalSettings->get('tax_amount_show_label', 1),

            'base_price_with_tax_show' => $generalSettings->get('base_price_with_tax_show', 1),
            'base_price_with_tax_show_label' => $generalSettings->get('base_price_with_discounts_show_label', 1),

            'discount_amount_show' => $generalSettings->get('discount_amount_show', 1),
            'discount_amount_show_label' => $generalSettings->get('discount_amount_show_label', 1),

            'final_price_show' => $generalSettings->get('final_price_show', 1),
            'final_price_show_label' => $generalSettings->get('final_price_show_label', 1),
        ];

        // Creating an object based on general settings
        $categoryObject = new \stdClass();
        $categoryObject->id = $categoryId;
        $categoryObject->prices = $generalPriceSettings;

        if ($categoryId <= 0) {
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

        if (empty($categoryDbObject)) {
            return $categoryObject;
        }

        $categoryDbObject->prices = json_decode($categoryDbObject->prices, true);


        // base price
        if ($categoryDbObject->prices['base_price_show'] == -1) {
            $categoryDbObject->prices['base_price_show'] = $generalPriceSettings['base_price_show'];
        }
        if ($categoryDbObject->prices['base_price_show_label'] == -1) {
            $categoryDbObject->prices['base_price_show_label'] = $generalPriceSettings['base_price_show_label'];
        }

        // base price with discounts
        if ($categoryDbObject->prices['base_price_with_discounts_show'] == -1) {
            $categoryDbObject->prices['base_price_with_discounts_show'] = $generalPriceSettings['base_price_with_discounts_show'];
        }
        if ($categoryDbObject->prices['tax_amount_show_label'] == -1) {
            $categoryDbObject->prices['tax_amount_show_label'] = $generalPriceSettings['tax_amount_show_label'];
        }

        // tax amount
        if ($categoryDbObject->prices['tax_amount_show'] == -1) {
            $categoryDbObject->prices['tax_amount_show'] = $generalPriceSettings['tax_amount_show'];
        }
        if ($categoryDbObject->prices['tax_amount_show_label'] == -1) {
            $categoryDbObject->prices['tax_amount_show_label'] = $generalPriceSettings['tax_amount_show_label'];
        }

        // base price with tax
        if ($categoryDbObject->prices['base_price_with_tax_show'] == -1) {
            $categoryDbObject->prices['base_price_with_tax_show'] = $generalPriceSettings['base_price_with_tax_show'];
        }
        if ($categoryDbObject->prices['base_price_with_tax_show_label'] == -1) {
            $categoryDbObject->prices['base_price_with_tax_show_label'] = $generalPriceSettings['base_price_with_tax_show_label'];
        }

        // discount amount
        if ($categoryDbObject->prices['discount_amount_show'] == -1) {
            $categoryDbObject->prices['discount_amount_show'] = $generalPriceSettings['discount_amount_show'];
        }
        if ($categoryDbObject->prices['discount_amount_show_label'] == -1) {
            $categoryDbObject->prices['discount_amount_show_label'] = $generalPriceSettings['discount_amount_show_label'];
        }

        // final
        if ($categoryDbObject->prices['final_price_show'] == -1) {
            $categoryDbObject->prices['final_price_show'] = $generalPriceSettings['final_price_show'];
        }
        if ($categoryDbObject->prices['final_price_show_label'] == -1) {
            $categoryDbObject->prices['final_price_show_label'] = $generalPriceSettings['final_price_show_label'];
        }

        $cache->store($categoryDbObject, $cacheKey);

        return $categoryDbObject;

    }

    /**
     *  Filters out elements that are not common in all given arrays.
     *  @param $arrays array of arrays
     *  @return array containing elements common in all given arrays.
     */
    // public static function getCommonElements($arrays){

    //     // We only keep the elements common among all arrays.
    //     $commonElements = $arrays[0];
    //     foreach($arrays as $array){
    //         if(empty($commonElements))
    //             break;

    //         foreach($commonElements as $i => $element){
    //             if(!in_array($element, $array))
    //                 unset($commonElements[$i]);
    //         }
    //     }

    //     return $commonElements;

    // }

    /**
     *  Filters out the payment methods that are not common in all items.
     *  @param $items array of item objects. Each item has data about its categories/manufacturers etc.
     *  @return array the common payment methods.
     */
    public static function getFilteredMethods($categories, $manufacturers, $usergroups, $userId, $baseTable = "payment")
    {

        $categories[] = 0; //to support all categories for payment method
        $manufacturers[] = 0; //to support all manufacturers for payment method
        $usergroups[] = 0; //to support all usergroups for payment method
        // $users[] = 0; //to support all users for payment method

        // GET ALL PAYMENT METHODS
        $db = Factory::getContainer()->get("DatabaseDriver");
        $query = $db->getQuery(true);

        $query
            ->select('m.*')  // Select all payment method fields
            ->select('GROUP_CONCAT(DISTINCT mc.category_id) AS categories')  // Get all unique categories for the payment
            ->select('GROUP_CONCAT(DISTINCT mm.manufacturer_id) AS manufacturers')  // Get all unique manufacturers for the payment
            ->select('GROUP_CONCAT(DISTINCT mu.user_id) AS users')  // Get all unique manufacturers for the payment
            ->select('GROUP_CONCAT(DISTINCT mug.usergroup_id) AS groups')  // Get all unique manufacturers for the payment
            ->from('#__alfa_' . $baseTable . 's AS m')  // Main table

            // Join related tables
            ->join('LEFT', "#__alfa_". $baseTable . '_categories AS mc ON mc.' . $baseTable . '_id = m.id')
            ->join('LEFT', "#__alfa_". $baseTable . '_manufacturers AS mm ON mm.' . $baseTable . '_id = m.id')
            ->join('LEFT', "#__alfa_". $baseTable . '_users AS mu ON mu.' . $baseTable . '_id = m.id')
            ->join('LEFT', "#__alfa_". $baseTable . '_usergroups AS mug ON mug.' . $baseTable . '_id = m.id')

            ->where('m.state = 1')

            // Group by payment method ID to combine categories and manufacturers
            ->group('m.id');




        $db->setQuery($query);
        $filteredMethods = $db->loadObjectList('id');

        // FILTER PAYMENT METHODS
        // Compare ids given with payment ids.
        foreach ($filteredMethods as $index => $method) {
            $isValid = true;

            $methodCategories = explode(",", $method->categories);
            $methodManufacturers = explode(",", $method->manufacturers);
            $methodUsers = explode(",", $method->users);
            $methodUsersgroups = explode(",", $method->groups);

            //if payment method don't have common categories with those given turn is valid to false to unset it
            if (empty(array_intersect($categories, $methodCategories))) {
                $isValid = false;
            }

            //if payment method dont have common manufacturers with those given turn is valid to false to unset it
            if (empty(array_intersect($manufacturers, $methodManufacturers))) {
                $isValid = false;
            }

            //if payment method don't have common manufacturers with those given turn is valid to false to unset it
            if (empty(array_intersect($usergroups, $methodUsersgroups))) {
                $isValid = false;
            }

            // check if 0 is set on method to allow all users or at least the user is inside the users array of the method
            if (!in_array(0, $methodUsers) && !in_array($userId, $methodUsers)) {
                $isValid = false;
            }

            if (!$isValid) {
                unset($filteredMethods[$index]);
            }

        }


        return $filteredMethods;

    }

    //	public function pluginLayout($fileName){
    //		$path = dirname(PluginHelper::getLayoutPath($this->_type, $this->_name, $fileName));
    //		return new FileLayout($fileName,$path);
    //	}


}
