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

use \Joomla\CMS\Factory;
use \Joomla\Database\DatabaseDriver;
use \Joomla\CMS\MVC\Model\BaseDatabaseModel;

class PriceCalculator
{
    protected $productId;
    protected $quantity;
    protected $userGroupId;
    protected $currencyId;
    protected $prices;
    // protected $taxes;
    // protected $discounts;
    protected $db;
    protected $cache;

    public function __construct($productId, $quantity = 1, $userGroupId = null, $currencyId = null)
    {
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->userGroupId = $userGroupId;
        $this->currencyId = $currencyId;
        $this->db = Factory::getContainer()->get('DatabaseDriver');

        // Create a cache object
        $this->cache = Factory::getCache('com_alfa',''); // 'com_alfa' is the cache group,
        // $this->cache = Factory::getCache('com_alfa', '', ['lifetime' => 3600]); // 1-hour cache lifetime

        $this->prices = $this->loadPrices(); // Load all possible prices for the product
        // $this->taxes = $this->loadTaxes(); // Load all applicable taxes
        // $this->discounts = $this->loadDiscounts(); // Load all applicable discounts
    }

    // Load all prices from the database
    protected function loadPrices()
    {
        // Define cache key
        $cacheKey = 'product_prices_' . $this->productId;

        $cachedPrices = $this->cache->get($cacheKey);

        if (!empty($cachedPrices)){
            return $cachedPrices;

        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__alfa_items_prices'))
            ->where('item_id = ' . $this->db->quote($this->productId))
            ->where('state = 1');  // Only active prices

        $this->db->setQuery($query);

        $prices = $this->db->loadAssocList('id');  // Prices with 'id' as the key

        // Store the data in cache for future requests
        $this->cache->store($prices, $cacheKey);

        return $prices;
    }

    // Calculate the correct price based on quantity, user group, etc.
    public function calculatePrice()
    {
        $basePrice = $this->quantity * $this->getBasePrice();
        $price = $basePrice;
        $priceWithTax = $price;
        
        // Apply discounts

        // $productDiscounts = $this->findDiscounts();

        // apply the tax
        $productTaxes = $this->findTaxes();
        $priceWithTax = $this->applyPercentages($price, $productTaxes);

        return [
            'base_price' => $basePrice,
            'price' => $price,
            'price_with_tax' => $priceWithTax,
            'taxes' => $productTaxes,
        ];
    }

    protected function getBasePrice()
    {
        $pricesMatched = [];
        $mostRelevantPrice = 0;
        $smallestValue = 0;

        foreach ($this->prices as $price) {
            $quantityStart = $price['quantity_start'];
            $quantityEnd = $price['quantity_end'];

            // Check if the current quantity is within the defined range
            if (($quantityStart === null || $quantityStart <= $this->quantity) &&
                ($quantityEnd === null || $quantityEnd >= $this->quantity)) {
                $pricesMatched[] = $price;
            }

        }

        if (empty($pricesMatched)) {
            return 0; // Or handle the case where no price is found
        }


        // Sort by the most relevant match, first by smallest value, then by closest quantity start
        usort($pricesMatched, function ($a, $b) {
            // Convert price values and quantities to float to ensure proper comparison
            $aValue = floatval($a['value']);
            $bValue = floatval($b['value']);
            
            $aQuantityStart = floatval($a['quantity_start'] ?? 0);
            $bQuantityStart = floatval($b['quantity_start'] ?? 0);
            
            $aQuantityEnd = floatval($a['quantity_end'] ?? INF); // Use INF if quantity_end is null (no upper limit)
            $bQuantityEnd = floatval($b['quantity_end'] ?? INF);
            
            // Calculate the range for each price
            $aRange = $aQuantityEnd - $aQuantityStart;
            $bRange = $bQuantityEnd - $bQuantityStart;

            // Compare by range first (smallest range is more relevant)
            if ($aRange != $bRange) {
                return $aRange <=> $bRange;
            }

            // If ranges are the same, compare by value (smallest price is more relevant)
            return $aValue <=> $bValue;
        });

        // Return the most relevant price or default to 0 if no price found
        return $pricesMatched[0]['value'];
    }

    // Check if a price rule matches the current state (user group, currency, etc.)
    protected function matchesRule($priceRule)
    {
        // Example checks (expand these as needed):
        // if ($priceRule['usergroup_id'] && $priceRule['usergroup_id'] != $this->userGroupId) {
        //     return false;
        // }
        // if ($priceRule['currency_id'] && $priceRule['currency_id'] != $this->currencyId) {
        //     return false;
        // }
        // You can add more checks for date ranges, countries, etc.
        return true;
    }


    // TODO: Add places check except from categories only when functionallity is added to alfa commerce simfwna me th topothesia tou xrhsth pou exei dhlwsei
    // Find tax for the current product
    // TODO: FOR ALL CATEGORIES ALSO IF NOTHING IS SELECTED
    protected function findDiscounts()
    {
        return [];
        // $db = $this->db;
        // $productId = $this->productId;
        // //Briskoume se poies kathgories anhkei to proion apo to product id kai ton pinaka #_alfa_items_categories

        // $query = $db->getQuery(true);
        // $query
        //     ->select('dc.discount_id')
        //     ->from('#__alfa_discounts_categories AS dc')
        //     ->join('LEFT', '#__alfa_items_categories AS ic ON dc.category_id = ic.category_id OR dc.category_id = 0')
        //     ->where('ic.item_id = ' . $db->quote($productId));
        
        // $db->setQuery($query);

        // $discountIdsCategories= $db->loadColumn();


//        echo 'DISCOUNT IDS FROM CATEGORIES OF PRODUCT<pre>';
//        print_r($discountIdsCategories);
//        echo '</pre>';

        //Briskoume se poious kataskeuastes anhkei to proion apo to product id kai ton pinaka #_alfa_items_manufacturers

        // $query= $db->getQuery(true);
        // $query
        //     ->select('dm.discount_id')
        //     ->from('#__alfa_discounts_manufacturers AS dm')
        //     ->join('LEFT', '#__alfa_items_manufacturers AS im ON dm.manufacturer_id = im.manufacturer_id OR dm.manufacturer_id = 0')
        //     ->where('im.item_id = ' . $db->quote($productId));
        // $db->setQuery($query);

        // $discountIdsManufacturers= $db->loadColumn();
//        echo 'DISCOUNT IDS FROM MANUFACTURERS OF PRODUCT<pre>';
//        print_r($discountIdsManufacturers);
//        echo '</pre>';


// categoriesIdDiscount 1 ,2 ,3
// manufacturersIdDiscount 2,3
// idsToGet = 2,3

        //kratame mono ta koina
         // $idsToGet=array_intersect($discountIdsManufacturers, $discountIdsCategories);
//        echo'<pre>';
//        print_r($idsToGet);
//        echo '</pre>';


// erwthma sth vash ta idsToGet na pareis ta values
        // whereIn

        // try {
        
        // $query = $db->getQuery(true);
        // $query
        //     ->select('value,behavior,is_amount')
        //     ->from('#__alfa_discounts')
        //     ->whereIn('id', $idsToGet);

        // $db->setQuery($query);
        // $discounts = $db->loadObjectList();

        // } catch
        // (\Exception $e) {
        //     echo $e->getCode() . ' ' . $e->getMessage();
        // }

        // $discounts_array = [0];//define the first discount by default to 0 so the for loop work fine if the first rule has behavior one after another

        // foreach($discounts as $da){
        //     if(empty($da->value)){continue;}

        //     // $discountObject = new \stdClass();
        //     // $discountObject->value = 0;
        //     // $discountObject->behavior = 0;
        //     // $discountObject->is_amount = 0;

        //     if($da->behavior=='0'){//only this tax
        //         $discounts_array[0] = $da->value;
        //         break;
        //     }else if($da->behavior=='1'){//combined   10%,2%    $calculated_tax_value = 12%
        //         $discounts_array[0] += $da->value;
        //     }else if($da->behavior=='2'){//one after another 10%,2%    $calculated_tax_value = price*10% + price*2%
        //         $discounts_array[] = $da->value;
        //     }
        // }


        return $discount_array;

    }


    // TODO: Add places check except from categories only when functionallity is added to alfa commerce simfwna me th topothesia tou xrhsth pou exei dhlwsei
    // Find tax for the current product
    protected function findTaxes()
    {
        
        
        $db = $this->db;
        $productId = $this->productId;

        $query = $db->getQuery(true);

        $query
            ->select('t.id, t.value, t.behavior')
            ->from('#__alfa_taxes AS t')
            
            // Join tax tables
            ->join('LEFT', '#__alfa_tax_categories AS tc ON tc.tax_id = t.id')
            ->join('LEFT', '#__alfa_tax_manufacturers AS tm ON tm.tax_id = t.id')
            ->join('LEFT', '#__alfa_tax_usergroups AS tu ON tu.tax_id = t.id')
            ->join('LEFT', '#__alfa_tax_users AS tg ON tg.tax_id = t.id')
            
            // Join item tables to match categories, manufacturers, places, etc. based on item_id
            ->join('LEFT', '#__alfa_items_categories AS ic ON ic.category_id = tc.category_id')
            ->join('LEFT', '#__alfa_items_manufacturers AS im ON im.manufacturer_id = tm.manufacturer_id')
            ->join('LEFT', '#__alfa_items_usergroups AS iug ON iug.usergroup_id = tu.usergroup_id')
            ->join('LEFT', '#__alfa_items_users AS iu ON iu.user_id = tg.user_id')

            // Apply conditions to check if the item matches the category, manufacturer, place, or usergroup
            ->where('(ic.item_id = ' . $db->quote($productId) . ' OR tc.category_id = 0)')
            ->where('(im.item_id = ' . $db->quote($productId) . ' OR tm.manufacturer_id = 0)')
            ->where('(iug.item_id = ' . $db->quote($productId) . ' OR tu.usergroup_id = 0)')
            ->where('(iu.item_id = ' . $db->quote($productId) . ' OR tg.user_id = 0)')

            // Only active taxes
            ->where('t.state = 1');

        $db->setQuery($query);
        $taxes = $db->loadObjectList();

        $tax_value_array = [0];//define the first tax by default to 0 so the for loop work fine if the first rule has behavior one after another

        foreach($taxes as $tax){
            if(empty($tax->value)){continue;}

            if($tax->behavior=='0'){//only this tax
                $tax_value_array[0] = $tax->value;
                break;
            }else if($tax->behavior=='1'){//combined   10%,2%    $calculated_tax_value = 12%
                $tax_value_array[0] += $tax->value;
            }else if($tax->behavior=='2'){//one after another 10%,2%    $calculated_tax_value = price*10% + price*2%
                $tax_value_array[] = $tax->value;
            }
        }

//        echo'<pre>';
//        print_r($tax_value_array);
//        echo '</pre>';
//        exit;

        // tax_value_array have on first position all combined or only this tax value and on next places the one after another values
        return $tax_value_array;
    }




    // Apply the modify function (e.g., add or subtract a percentage or amount)
    protected function applyModifyFunction($price, $priceRule)
    {
        switch ($priceRule['modify_function']) {
            case 'add':
                if ($priceRule['modify_type'] == 'amount') {
                    return $price + $priceRule['value'];
                } elseif ($priceRule['modify_type'] == 'percentage') {
                    return $price + $this->getPercentage($price,$priceRule['value']);
                }
                break;
            case 'remove':
                if ($priceRule['modify_type'] == 'amount') {
                    return $price - $priceRule['value'];
                } elseif ($priceRule['modify_type'] == 'percentage') {
                    return $price - $this->getPercentage($price,$priceRule['value']);
                }
                break;
        }
        return $price;
    }

    // Apply tax to the price
    protected function applyPercentages($price, $percentagesData)
    {
        if(empty($percentagesData) || !is_array($percentagesData)){ return $price; }

        $price_calculated = $price;
       
        // tax_value_array have on first position all combined or only this tax value and on next places the one after another values
        foreach($percentagesData as $percentage){
            $price_calculated += $this->getPercentage($price_calculated,$percentage);    
        }
        
        return $price_calculated;
        // return $price + ($price * $taxRate);
    }

    protected function applyDiscounts(){

        // $db = $this->db;
        // $productId = $this->productId;



//        $query = $db->getQuery(true);
//        $query
//            ->select('value')
//            ->from('#__alfa_discounts')
//            ->where('id = ' . $db->quote($id));
//
//        $db->setQuery($query);
//        $val = $db->loadColumn();

        // $query = $db->getQuery(true);
        // $query
        //     ->select('modify_type')
        //     ->from('#__alfa_discounts')
        //     ->where('id = ' . $db->quote($productId));



        // $db->setQuery($query);
        // $type = $db->loadColumn();

        // echo'<pre>';
        // print_r($productId);
        // echo '</pre>';
        // exit;



    }

    protected function getPercentage($value,$percent){
        return ($value * ($percent / 100));
    }
}
