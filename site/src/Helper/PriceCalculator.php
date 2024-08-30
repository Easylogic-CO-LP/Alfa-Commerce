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
            ->where('product_id = ' . $this->db->quote($this->productId))
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
        $price = $this->applyDiscount($price,10);

        // Calculate the total tax applicable
        
        // calculate the tax independt and the add it
        // $tax = $this->calculateTax($price,24);
        // $priceWithTax = $price + $tax;

        // apply the tax
        $priceWithTax = $this->applyTax($price, 24);

        // Apply tax to the discounted price
        // $priceWithTax = $price + $tax; //if we want to have function to get only the tax and add it to the price

        // foreach ($this->prices as $priceRule) {
        // $price = $this->applyModifyFunction($price, $priceRule);
        // 
        //     if ($this->matchesRule($priceRule)) {
        //     }
        // }

        

        return [
            'base_price' => $basePrice,
            'discounted_price' => $price,
            'price_with_tax' => $priceWithTax
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

    // Apply a discount based on user group
    protected function applyDiscount($price, $discountRate)
    {
        if ($discountRate > 0) {
            return $price - ($price * ($discountRate / 100));
        }
        return $price;
    }

    // Calculate tax based on the price
    protected function calculateTax($price, $taxRate)
    {
        return $price * ($taxRate / 100);
        // return $price + ($price * $taxRate);
    }

    // Apply tax to the price
    protected function applyTax($price, $taxRate)
    {
        // TODOL add logic with taxes database connection
        return $price + ($price * ($taxRate / 100));
        // return $price + ($price * $taxRate);
    }

    // Apply the modify function (e.g., add or subtract a percentage or amount)
    protected function applyModifyFunction($price, $priceRule)
    {
        switch ($priceRule['modify_function']) {
            case 'add':
                if ($priceRule['modify_type'] == 'amount') {
                    return $price + $priceRule['value'];
                } elseif ($priceRule['modify_type'] == 'percentage') {
                    return $price + ($price * ($priceRule['value'] / 100));
                }
                break;
            case 'remove':
                if ($priceRule['modify_type'] == 'amount') {
                    return $price - $priceRule['value'];
                } elseif ($priceRule['modify_type'] == 'percentage') {
                    return $price - ($price * ($priceRule['value'] / 100));
                }
                break;
        }
        return $price;
    }
}
