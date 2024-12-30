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
use Joomla\CMS\Component\ComponentHelper;

class PriceCalculator
{
    protected $productId;
    protected $quantity;
    protected $userGroupId;
    protected $currencyId;
    protected $prices;
    protected $app;
    protected $settings;
    protected $db;
    protected $cache;

    public function __construct($productId, $quantity = 1, $userGroupId = null, $currencyId = null)
    {
        $this->productId = $productId;//check id is valid
        $this->quantity = !empty($quantity) ? $quantity : 1 ;
        $this->userGroupId = $userGroupId;
        $this->currencyId = $currencyId;
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->app = Factory::getApplication();
        $this->settings = ComponentHelper::getParams('com_alfa');


        // Create a cache object
        $this->cache = Factory::getCache('com_alfa',''); // 'com_alfa' is the cache group,
        $this->cache->setCaching(true);
        // $this->cache = Factory::getCache('com_alfa', '', ['lifetime' => 3600]); // 1-hour cache lifetime

        $this->prices = $this->loadPrices(); // Load all possible prices for the product
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
        $db = $this->db;

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_items_prices'))
            ->where('item_id = ' . $db->q($this->productId))
            ->where('state = 1')  // Only active prices
            ->where(// Check if the price start date is valid or not set
                'IFNULL(NOW() >= ' . $db->qn('publish_up') .
                ' OR ' . $db->qn('publish_up') . ' = "0000-00-00 00:00:00", 1) = 1 ' .
                //
                ' AND ' .
                // Check if the coupon has not expired
                'IFNULL(' . $db->qn('publish_down') . ' != "0000-00-00 00:00:00" ' .
                'AND NOW() > ' . $db->qn('publish_down') . ', 0) = 0' );

        $db->setQuery($query);

        $prices = $db->loadAssocList('id');  // Prices with 'id' as the key

        // Store the data in cache for future requests
        $this->cache->store($prices, $cacheKey);

        return $prices;
    }

    // Calculate the correct price based on quantity, user group, etc.
    public function calculatePrice()
    {

        $showTax = true;

        $basePrice = $this->quantity * $this->getBasePrice();
        $price = $basePrice;
        $priceWithTax = $price;
        $finalPrice = $price;
        
        $productDiscounts = $this->findDiscounts();
        $productTaxes = $this->findTaxes();

        // Apply discounts before tax
        $basePriceWithDiscount = $this->applyDiscounts($basePrice,$productDiscounts->beforeTax);
        $finalPrice = $this->applyDiscounts($finalPrice,$productDiscounts->beforeTax);


        // Apply the taxes
        $basePriceWithTax = $this->applyPercentages($basePriceWithDiscount, $productTaxes);
        $finalPrice = $this->applyPercentages($finalPrice, $productTaxes);
        $priceWithDiscAndTax = $basePriceWithDiscount;
        foreach($productTaxes as $tax)
            $priceWithDiscAndTax *= (100 + $tax) / 100;

        // Apply discounts after tax
        $finalPrice = $this->applyDiscounts($finalPrice,$productDiscounts->afterTax);

        // if tax is not shown the final price will be the basePriceWith the discount
        // if(!$showTax){
        //     $finalPrice = $basePriceWithDiscount;
        // }
        
        $discountBasePriceToCalculateFrom = $discountFinalPriceToCalculateFrom  =0;
        if(!empty($productDiscounts->beforeTax)){
            $discountBasePriceToCalculateFrom = $basePrice;
            $discountFinalPriceToCalculateFrom = $basePriceWithDiscount;
        }

        if(!empty($productDiscounts->afterTax)){
            $discountBasePriceToCalculateFrom = $basePriceWithTax;
            $discountFinalPriceToCalculateFrom = $finalPrice;
        }

        
        //Setting reduced amount and reduced percentage.
        //amount
        $reducedDiscountAmount = $discountBasePriceToCalculateFrom - $discountFinalPriceToCalculateFrom;
        //percentage
        $reducedDiscountPercentage = 0;
        if($discountBasePriceToCalculateFrom>0){
            $reducedDiscountPercentage = 100 - (100 * $discountFinalPriceToCalculateFrom / $discountBasePriceToCalculateFrom);
        }

        //Validating.
        if($reducedDiscountPercentage < 0)
            $reducedDiscountPercentage = 0;
        if($reducedDiscountAmount < 0)
            $reducedDiscountAmount = 0;


        $total_discounts = 
                        [
                            'amount'=> $reducedDiscountAmount,
                            'percent'=> $reducedDiscountPercentage
                        ];

        //Taxes
        $taxPercentage = 0;
        $taxAmount = 0;
        $taxPercentage = 100 + $productTaxes[0];
        foreach($productTaxes as $i => $tax)
            if ($i != 0)
                $taxPercentage *= (100 + $tax) / 100;

        $taxPercentage -= 100;
        //Tax is calculated as the price with discounts and taxes, minus the price with discounts.
        $taxAmount = $priceWithDiscAndTax - $basePriceWithDiscount;

        $total_taxes =
                    [
                        'amount' => $taxAmount,
                        'percent' => $taxPercentage
                    ];


        return [
            'base_price' => $basePrice, //arxiki timi 100
            'base_price_with_discount' => $basePriceWithDiscount, //only discounts applied
            'base_price_with_tax' => $basePriceWithTax,// only taxes applied
            'final_price' => $finalPrice, // with discounts and tax applied

            'discounts_totals'=> $total_discounts,
            'tax_totals' => $total_taxes,
            'taxes' => $productTaxes, //24%
            'discounts' => $productDiscounts, //20%
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


    // TODO: Add places check except from categories only when functionality is added to alfa commerce simfwna me th topothesia tou xrhsth pou exei dhlwsei
    // Find tax for the current product
    // TODO: FOR ALL CATEGORIES ALSO IF NOTHING IS SELECTED

    protected function findDiscounts(){
        $db = $this->db;
        $productId = $this->productId;

        $query = $db->getQuery(true);

        $query
            ->select('DISTINCT d.id, d.name, d.value, d.is_amount, d.behavior, d.operation , d.apply_before_tax')
            ->from('#__alfa_discounts AS d')
            
            // Join discount tables
            ->join('LEFT', '#__alfa_discount_categories AS dc ON dc.discount_id = d.id')
            ->join('LEFT', '#__alfa_discount_manufacturers AS dm ON dm.discount_id = d.id')
            ->join('LEFT', '#__alfa_discount_usergroups AS du ON du.discount_id = d.id')
            ->join('LEFT', '#__alfa_discount_users AS dg ON dg.discount_id = d.id')
            
            // Join item tables to match categories, manufacturers, places, etc. based on item_id
            ->join('LEFT', '#__alfa_items_categories AS ic ON ic.category_id = dc.category_id')
            ->join('LEFT', '#__alfa_items_manufacturers AS im ON im.manufacturer_id = dm.manufacturer_id')
            ->join('LEFT', '#__alfa_items_usergroups AS iug ON iug.usergroup_id = du.usergroup_id')
            ->join('LEFT', '#__alfa_items_users AS iu ON iu.user_id = dg.user_id')

            // Apply conditions to check if the item matches the category, manufacturer, place, or usergroup
            ->where('(ic.item_id = ' . $db->quote($productId) . ' OR dc.category_id = 0)')
            ->where('(im.item_id = ' . $db->quote($productId) . ' OR dm.manufacturer_id = 0)')
            ->where('(iug.item_id = ' . $db->quote($productId) . ' OR du.usergroup_id = 0)')
            ->where('(iu.item_id = ' . $db->quote($productId) . ' OR dg.user_id = 0)')
            ->where(// Check if the coupon start date is valid or not set
                'IFNULL(NOW() >= ' . $db->quoteName('d.publish_up') .
                ' OR ' . $db->quoteName('d.publish_up') . ' = "0000-00-00 00:00:00", 1) = 1 ' .
                //
                ' AND ' .
                // Check if the coupon has not expired
                'IFNULL(' . $db->quoteName('d.publish_down') . ' != "0000-00-00 00:00:00" ' .
                'AND NOW() > ' . $db->quoteName('d.publish_down') . ', 0) = 0' )

            // Only active discounts
            ->where('d.state = 1')
            ->order('d.ordering ASC');


        $db->setQuery($query);
        $discounts = $db->loadObjectList();

        // If the above query slows down the system and use a lot of sources we can do the each join as sepearte query and
        // handle them accordingly
        // $subQcat =
        // Query for categories
        // $query->clear()
        //     ->select('dca.discount_id')
        //     ->from('#__alfa_items_categories as ca')
        //     ->join('LEFT', '#__alfa_discount_categories as dca ON dca.category_id = ca.category_id') //or dca.category_id = 0
        //     ->where('ca.item_id = ' . $db->quote($this->productId));
        // $categories = $db->setQuery($query)->loadColumn();
        
        // combine results only the 
        // $discountIds = array_unique(array_merge($categories, $manufacturers, $userGroups));

        //         $query->clear()
        //     ->select('value, is_amount, behavior')
        //     ->from('#__alfa_discounts as t')
        //     ->where('t.state = 1')
        //     ->whereIn('t.id',$discountIds);
        // $discounts = $db->setQuery($query)->loadAssocList();

        $discountValueArray = new DiscountGroup();

        foreach($discounts as $discount){
            if(empty($discount->value)){continue;}

            $isAmountIndex = ($discount->is_amount == 1 ? 'amount' : 'percent');//same as our DiscountGroup object
            $isBeforeTaxIndex = ($discount->apply_before_tax == 1 ? 'beforeTax' : 'afterTax');//same as our DiscountGroup object

            if($discount->behavior=='0'){//Only this discount
                $discountValueArray->{$isBeforeTaxIndex}[0][$isAmountIndex] = []; // empty all previous if exist
                $discountValueArray->{$isBeforeTaxIndex}[0][$isAmountIndex] = new DiscountValue($discount->value, $discount->is_amount, $discount->operation, $discount->name);//Create a new and reassign it to this
                break;
                
            }else if($discount->behavior=='1'){//combined   10%,2%    $calculated_discount_value = 12%
                $discountValueArray->{$isBeforeTaxIndex}[0][$isAmountIndex]->addValue($discount->value, $discount->is_amount, $discount->operation, $discount->name);

            }else if($discount->behavior=='2'){//one after another 10%,2%    $calculated_discount_value = price*10% + price*2%
                $discountValueArray->{$isBeforeTaxIndex}[][$isAmountIndex] = new DiscountValue($discount->value, $discount->is_amount, $discount->operation, $discount->name);
            }
        }

        // as we setted the first index 0 of the array if nothing exists in this
        // ( only sequential one after another exists )
        $discountValueArray->cleanEmptys();

        return $discountValueArray;
    }

    protected function applyDiscounts($price, $discountsData)
    {
        $finalPrice = $price;

        foreach ($discountsData as $discountsKey => $discounts) {
            if (is_array($discounts)) {
                foreach ($discounts as $discountKey => $discount) {

                    // Determine the adjustment value
                    $adjustment = $discount->isAmount == 1 
                        ? $discount->value 
                        : $this->getPercentage($finalPrice, $discount->value);

                    // Apply the operation dynamically
                    $finalPrice += ($discount->operation == 1 ? $adjustment : -$adjustment);

                    if($finalPrice <= 0){
                        $finalPrice = 0;
                        break;
                    }

                }
            }
        }

        return $finalPrice;

    }


    // TODO: Add places check except from categories only when functionality is added to alfa commerce simfwna me th topothesia tou xrhsth pou exei dhlwsei
    // Find tax for the current product
    protected function findTaxes()
    {
        
        $db = $this->db;
        $productId = $this->productId;

        $query = $db->getQuery(true);

        $totals = 0;
/*
        
        $subCat =
            "SELECT tc.tax_id
                    FROM #__alfa_tax_categories as tc
                    JOIN #__alfa_items_categories ic
                    ON ic.category_id = tc.category_id
                    WHERE ic.item_id = " . $productId;

        $subMan =
            "SELECT tc.tax_id
                    FROM #__alfa_tax_manufacturers as tc
                    JOIN #__alfa_items_manufacturers ic
                    ON ic.manufacturer_id = tc.manufacturer_id
                    WHERE ic.item_id = " . $productId;

        $subUsgr =
            "SELECT tc.tax_id
                    FROM #__alfa_tax_usergroups as tc
                    JOIN #__alfa_items_usergroups ic
                    ON ic.usergroup_id = tc.usergroup_id
                    WHERE ic.item_id = " . $productId;

        $subUs =
            "SELECT tc.tax_id
                    FROM #__alfa_tax_users as tc
                    JOIN #__alfa_items_users ic
                    ON ic.user_id = tc.user_id
                    WHERE ic.item_id = " . $productId;

        $selCat0 = "SELECT tax_id
                    FROM #__alfa_tax_categories
                    WHERE category_id = 0";

        $selMan0 = "SELECT tax_id
                    FROM #__alfa_tax_manufacturers
                    WHERE manufacturer_id = 0";

        $selUsgr0 = "SELECT tax_id
                    FROM #__alfa_tax_usergroups
                    WHERE usergroup_id = 0";

        $selUs0 = "SELECT tax_id
                    FROM #__alfa_tax_users
                    WHERE user_id = 0";


        for($i = 0; $i < 10000; $i++) {
*/
            $start = hrtime(true);
            $query = $db->getQuery(true);
            $query
                ->select('DISTINCT t.id, t.value, t.behavior')
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
            ->where(// Check if the tax start date is valid or not set
                'IFNULL(NOW() >= ' . $db->quoteName('t.publish_up') .
                ' OR ' . $db->quoteName('t.publish_up') . ' = "0000-00-00 00:00:00", 1) = 1 ' .
                //
                ' AND ' .
                // Check if the coupon has not expired
                'IFNULL(' . $db->quoteName('t.publish_down') . ' != "0000-00-00 00:00:00" ' .
                'AND NOW() > ' . $db->quoteName('t.publish_down') . ', 0) = 0')

/*
                ->where('t.id IN (' . $subCat . ')
                        OR t.id IN (' . $subMan . ')
                        OR t.id IN (' . $subUsgr . ')
                        OR t.id IN (' . $subUs . ')'.
                       'OR t.id IN (' . $selCat0 . ')' .
                        'OR t.id IN (' . $selMan0 . ')' .
                        'OR t.id IN (' . $selUsgr0 . ')' .
                        'OR t.id IN (' . $selUs0 . ')'
                )
*/
                // Only active taxes
                ->where('t.state = 1')
                ->order('t.ordering ASC');

            $db->setQuery($query);

            $end = hrtime(true);

            $totals += $end - $start;
        // }

        // echo "Duration: " . $totals / 1e+9 . "<br>";

        // print_r($db->replacePrefix((string) $query) );

        $taxes = $db->loadObjectList();


        // foreach($taxes as $tax){
        //     print_r($tax);
        // }

        // echo "<br>product id:".$this->productId."<br>";
        // echo "<pre>";
        // print_r($taxes);
        // echo "</pre><br>";

        $tax_value_array = [0];//define the first tax by default to 0 so the for loop work fine if the first rule has behavior one after another

        // TODO: default category on products should be added to calculate the right tax

        foreach($taxes as $tax){
            if(empty($tax->value)){continue;}

            if($tax->behavior=='0'){    //only this tax so we reinitialize the array and break the loop
                $tax_value_array = [$tax->value];
                break;
            }else if($tax->behavior=='1'){//combined   10%,2%    $calculated_tax_value = 12%
                $tax_value_array[0] += $tax->value;
            }else if($tax->behavior=='2'){//one after another 10%,2%    $calculated_tax_value = price*10% + price*2%
                $tax_value_array[] = $tax->value;
            }
        }

        // print_r($tax_value_array);
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

    protected function getPercentage($value,$percent){
        return (abs($value) * ($percent / 100));
    }
}

class DiscountValue {
    public $value;
    public $isAmount;
    public $name;
    public $operation;
    public $combinedValues;

    public function __construct($value = 0, $isAmount = 0, $operation = 0, $name = '') {
        $this->value = $value;
        $this->isAmount = $isAmount;
        $this->name = $name;
        $this->operation = $operation;
    }

    public function addValue($value,$isAmount, $operation, $name) {

        $value = abs($value);// an einai -24 to kanw 24
        $this->value = abs($this->value);

        $this->combinedValues[] = new DiscountValue($value, $isAmount, $operation, $name);

        // if($this->operation != $operation){
        //     $this->value -= $value;
        //     if($this->value < 0)
        //         $this->operation = $operation;
        //     $this->value = abs($this->value);
        // }
        // else{
        //     $this->value += $value;
        // }


        // Construct $this value according to the main operation
        if($this->operation == 0){
            $this->value = $this->value * -1;
        }

        // Construct current value according the the current operation
        if($operation == 0){
            $value = $value * -1;
        }

        // Update main value
        $this->value += $value;

        // Update the operation and the value
        $this->operation = ($this->value < 0 ? 0 : 1);
        $this->value = abs($this->value);

        if($this->name==''){
            $this->name = $name;
        }

    }
}

class DiscountGroup {
    public $beforeTax = [];
    public $afterTax = [];

    public function __construct() {
        $this->beforeTax[0]['amount'] = new DiscountValue();
        $this->beforeTax[0]['percent'] = new DiscountValue();

        $this->afterTax[0]['amount'] = new DiscountValue();
        $this->afterTax[0]['percent'] = new DiscountValue();
    }

    public function cleanEmptys(){

        // Define a function to clean nested structures
        $cleanNested = function (&$array) {
            
            foreach ($array as $key => &$item) {
                if (is_array($item)) {
                    foreach ($item as $subKey => $subItem) {
                        if (
                            $subItem instanceof DiscountValue &&
                            $subItem->value === 0 &&
                            empty($subItem->combinedValues)
                        ) {
                            unset($item[$subKey]); // Remove `amount` or `percent`
                        }
                    }
                    // Remove the main structure if it's empty
                    if (empty($item)) {
                        unset($array[$key]);
                    }
                }

                // we break instant the loop cause we want to check only the first position of the array
                // we do this because in construct we create empty values and we dont always want them which exist in 0 index
                break; 
            }
        };

        // Clean `beforeTax` and `afterTax`
        $cleanNested($this->beforeTax);
        $cleanNested($this->afterTax);

    }
}
