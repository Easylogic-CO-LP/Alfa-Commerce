<?php
/**
 * @package    Com_Alfa
 * @subpackage Site.Service.Pricing
 * @since      1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Price Data Loader
 *
 * Loads prices, discounts, and taxes for products with automatic batching.
 * Handles 1 to 100,000+ products efficiently by chunking large requests.
 *
 * WHAT THIS CLASS DOES
 * --------------------
 * Given a list of product ids and a PriceContext (who is asking, from where,
 * in which currency), it queries the database and returns:
 *
 *   - Prices   — the raw unit price rows for those items, filtered to the
 *                visitor's currency, usergroup, user, and place.
 *
 *   - Discounts — all active discounts that apply to those items based on
 *                 category, manufacturer, place, usergroup, and user scope.
 *
 *   - Taxes     — all active taxes that apply to those items based on
 *                 category, manufacturer, place, usergroup, and user scope.
 *
 * HOW SCOPE MATCHING WORKS (discounts and taxes)
 * -----------------------------------------------
 * Each discount/tax has five scope tables (categories, manufacturers, places,
 * usergroups, users). A row with value 0 in any scope table means "applies
 * to ALL" for that dimension. NULL means no restriction row was inserted.
 *
 * For each scope dimension the filter is:
 *   "no restriction row exists  OR  value = 0 (all)  OR  value matches visitor"
 *
 * All five dimensions must match simultaneously for a discount/tax to apply.
 *
 * USERGROUP FILTER — IMPORTANT
 * ----------------------------
 * Discounts and taxes are filtered by the VISITOR's Joomla usergroups from
 * PriceContext, NOT by #__alfa_items_usergroups (which controls item access).
 * Using the item-access table for this filter is a common mistake that causes
 * discounts to never apply (or to apply to the wrong items).
 *
 * @package Alfa\Component\Alfa\Site\Service\Pricing
 * @since   1.0.0
 */
class PriceDataLoader
{
	/**
	 * Optimal chunk size for MySQL JOIN queries.
	 * Balances query complexity vs. DB round-trips.
	 */
	const CHUNK_SIZE = 200;

	/** @var \Joomla\Database\DatabaseInterface */
	protected $db;

	/** @var DateRangeHelper */
	protected $dateHelper;

	public function __construct()
	{
		$this->db         = Factory::getContainer()->get('DatabaseDriver');
		$this->dateHelper = new DateRangeHelper();
	}

	/**
	 * Load pricing data for multiple products.
	 *
	 * Automatically chunks large batches for optimal performance.
	 *
	 * @param   array         $productIds  Product IDs to load
	 * @param   PriceContext  $context     Pricing context (currency, user, location)
	 *
	 * @return  PriceDataCollection
	 */
	public function loadBatch(array $productIds, PriceContext $context): PriceDataCollection
	{
		if (empty($productIds))
		{
			return new PriceDataCollection();
		}

		$prices = $this->executeBatched(
			fn($chunk) => $this->fetchPrices($chunk, $context),
			$productIds
		);

		$discounts = $this->executeBatched(
			fn($chunk) => $this->fetchDiscounts($chunk, $context),
			$productIds
		);

		$taxes = $this->executeBatched(
			fn($chunk) => $this->fetchTaxes($chunk, $context),
			$productIds
		);

		return new PriceDataCollection($prices, $discounts, $taxes);
	}

	/**
	 * Execute fetch function with automatic chunking.
	 *
	 * Splits large batches into optimal chunks and merges results.
	 * For small batches (≤ CHUNK_SIZE), executes directly without chunking.
	 *
	 * @param   callable  $fetchFunction  Function to execute per chunk
	 * @param   array     $productIds     All product IDs
	 *
	 * @return  array  Merged results grouped by product ID
	 */
	protected function executeBatched(callable $fetchFunction, array $productIds): array
	{
		if (count($productIds) <= self::CHUNK_SIZE)
		{
			return $fetchFunction($productIds);
		}

		$chunks     = array_chunk($productIds, self::CHUNK_SIZE);
		$allResults = [];

		foreach ($chunks as $chunk)
		{
			$chunkResults = $fetchFunction($chunk);

			foreach ($chunkResults as $productId => $items)
			{
				if (!isset($allResults[$productId]))
				{
					$allResults[$productId] = [];
				}

				$allResults[$productId] = array_merge($allResults[$productId], $items);
			}
		}

		return $allResults;
	}

	// =========================================================================
	// Price fetching
	// =========================================================================

	/**
	 * Fetch prices for the given products, filtered by the visitor's context.
	 *
	 * Filtering rules for #__alfa_items_prices:
	 *   currency_id  — 0 = any currency, or must match visitor's currency
	 *   usergroup_id — 0 = any group,    or must be in visitor's groups
	 *   user_id      — 0 = any user,     or must match visitor's user id
	 *   country_id   — 0 = any place,    or must match visitor's location
	 *
	 * Prices are ordered most-specific first so PriceComputationEngine picks
	 * the best-matching row (currency > usergroup > user > place > quantity).
	 *
	 * @param   array         $productIds  Product IDs
	 * @param   PriceContext  $context     Pricing context
	 *
	 * @return  array  Prices grouped by product ID
	 */
	protected function fetchPrices(array $productIds, PriceContext $context): array
	{
		$db = $this->db;

		$query = $db->getQuery(true)
			->select('*')
			->from($db->qn('#__alfa_items_prices'))
			->whereIn($db->qn('item_id'), $productIds)
			->where('state = 1')
			->where($this->dateHelper->getActiveCondition('publish_up', 'publish_down'));

		// Apply currency / usergroup / user / location filters from the visitor context.
		// This was previously commented out — it MUST be active so the engine receives
		// only the price rows relevant to this specific visitor / index slot.
		$this->applyPriceContextFilters($query, $context);

		// Most specific prices first (for selectBasePrice in the engine)
		$query->order([
			'currency_id DESC',
			'usergroup_id DESC',
			'user_id DESC',
			'country_id DESC',
			'quantity_start ASC',
		]);

		$db->setQuery($query);
		$results = $db->loadObjectList();

		return $this->groupByProductId($results, 'item_id');
	}

	/**
	 * Apply context-based filters to a #__alfa_items_prices query.
	 *
	 * Every dimension uses the "0 = applies to all" convention so a price
	 * row with currency_id=0 is returned for every currency, and so on.
	 *
	 * NOTE: All properties of PriceContext are private — always use the
	 * public getters (getCurrencyId(), getUserGroups() etc.).
	 * Direct property access ($context->currencyId) is a PHP fatal error.
	 *
	 * @param   mixed         $query    Joomla database query object
	 * @param   PriceContext  $context  Pricing context
	 */
	protected function applyPriceContextFilters($query, PriceContext $context): void
	{
		// ── Currency ──────────────────────────────────────────────────────────
		// 0 in the DB means "applies to any currency".
		$currencyId = $context->getCurrencyId();

		if ($currencyId > 0)
		{
			$query->where('(currency_id = 0 OR currency_id = ' . (int) $currencyId . ')');
		}
		else
		{
			// No specific currency in context — return only universal prices
			$query->where('currency_id = 0');
		}

		// ── Usergroups ────────────────────────────────────────────────────────
		// getUserGroups() always includes 0 (the "any group" sentinel), so
		// using IN() here naturally matches both public and group-specific rows.
		$query->where('usergroup_id IN (' . $context->getUserGroupsSql() . ')');

		// ── User ──────────────────────────────────────────────────────────────
		// 0 in the DB means "applies to any user" (public / not user-specific).
		$userId = $context->getUserId();

		if ($userId !== null && $userId > 0)
		{
			$query->where('(user_id = 0 OR user_id = ' . (int) $userId . ')');
		}
		else
		{
			// Guest or index context — return only universal (non-user-specific) rows
			$query->where('user_id = 0');
		}

		// ── Location / place ──────────────────────────────────────────────────
		// country_id in #__alfa_items_prices stores a #__alfa_places id.
		// 0 means "applies to any place".
		$locationId = $context->getLocationId();

		if ($locationId !== null && $locationId > 0)
		{
			$query->where('(country_id = 0 OR country_id = ' . (int) $locationId . ')');
		}
		else
		{
			// Unknown location — return only universal (non-place-specific) rows
			$query->where('country_id = 0');
		}
	}

	// =========================================================================
	// Discount fetching
	// =========================================================================

	/**
	 * Fetch active discounts for the given products, matched to the visitor's context.
	 *
	 * A discount applies to a product when ALL five scope dimensions match:
	 *
	 *   1. Category scope    — discount targets all categories (category_id=0/NULL)
	 *                          or a category this product belongs to
	 *   2. Manufacturer scope — discount targets all manufacturers (0/NULL)
	 *                          or this product's manufacturer
	 *   3. Place scope        — discount targets all places (place_id=0/NULL)
	 *                          or the visitor's detected location
	 *   4. Usergroup scope    — discount targets all groups (usergroup_id=0/NULL)
	 *                          or one of the VISITOR'S Joomla usergroups
	 *   5. User scope         — discount targets all users (user_id=0/NULL)
	 *                          or the currently logged-in user specifically
	 *
	 * IMPORTANT — usergroup filter uses VISITOR context, NOT item access tables.
	 * #__alfa_items_usergroups controls which groups can VIEW an item;
	 * it has nothing to do with which groups qualify for a discount.
	 *
	 * @param   array         $productIds  Product IDs
	 * @param   PriceContext  $context     Pricing context
	 *
	 * @return  array  Discounts grouped by product ID
	 */
	protected function fetchDiscounts(array $productIds, PriceContext $context): array
	{
		$db            = $this->db;
		$productIdList = implode(',', array_map('intval', $productIds));

		// Visitor's usergroup ids as SQL IN() list, e.g. "0" or "0,8,25"
		// getUserGroupsSql() always includes 0 (public), so public discounts always match.
		$userGroupsSql = $context->getUserGroupsSql();

		// Visitor's location id; 0 means unknown / not set
		$locationId = (int) ($context->getLocationId() ?? 0);

		// Visitor's user id; 0 means guest / anonymous
		$userId = (int) ($context->getUserId() ?? 0);

		$query = $db->getQuery(true)
			->select('DISTINCT d.id, d.name, d.value, d.is_amount, d.behavior, d.operation, d.apply_before_tax, ic.item_id')
			->from('#__alfa_discounts AS d')

			// ── Scope 1: Category ──────────────────────────────────────────────
			// LEFT JOIN so discounts with NO category rows are still returned.
			// The WHERE clause then allows: no row (NULL), any-category (0),
			// or a category this item actually belongs to.
			->join('LEFT',  '#__alfa_discount_categories AS dc ON dc.discount_id = d.id')
			->join('INNER', '#__alfa_items_categories AS ic ON ic.item_id IN (' . $productIdList . ')')
			->where('(dc.discount_id IS NULL OR dc.category_id = 0 OR dc.category_id = ic.category_id)')

			// ── Scope 2: Manufacturer ─────────────────────────────────────────
			// LEFT JOIN the discount's manufacturer scope and the item's manufacturers.
			// Match when: no manufacturer restriction, applies-to-all (0),
			// item has no manufacturer, or exact match.
			->join('LEFT', '#__alfa_discount_manufacturers AS dm ON dm.discount_id = d.id')
			->join('LEFT', '#__alfa_items_manufacturers AS im ON im.item_id = ic.item_id')
			->where('(dm.discount_id IS NULL OR dm.manufacturer_id = 0 OR im.manufacturer_id IS NULL OR dm.manufacturer_id = im.manufacturer_id)')

			// ── Scope 3: Place ────────────────────────────────────────────────
			// Matches when: no place restriction exists, applies-to-all-places (0),
			// or the discount targets the visitor's specific location.
			->join('LEFT', '#__alfa_discount_places AS dp ON dp.discount_id = d.id')
			->where('(dp.discount_id IS NULL OR dp.place_id = 0 OR dp.place_id = ' . $locationId . ')')

			// ── Scope 4: Usergroup ────────────────────────────────────────────
			// Filter by the VISITOR'S Joomla usergroups from PriceContext.
			// getUserGroupsSql() returns e.g. "0,8,25" so this matches both
			// public discounts (usergroup_id=0) and group-specific ones.
			//
			// DO NOT JOIN #__alfa_items_usergroups here — that table controls
			// which groups can ACCESS the item, not who qualifies for discounts.
			->join('LEFT', '#__alfa_discount_usergroups AS du ON du.discount_id = d.id')
			->where('(du.discount_id IS NULL OR du.usergroup_id = 0 OR du.usergroup_id IN (' . $userGroupsSql . '))')

			// ── Scope 5: User ─────────────────────────────────────────────────
			// Filter by the visitor's specific user id from PriceContext.
			// user_id=0 in the scope table means "applies to all users".
			->join('LEFT', '#__alfa_discount_users AS dgu ON dgu.discount_id = d.id')
			->where('(dgu.discount_id IS NULL OR dgu.user_id = 0 OR dgu.user_id = ' . $userId . ')')

			->where($this->dateHelper->getActiveCondition('d.publish_up', 'd.publish_down'))
			->where('d.state = 1')
			->order('d.ordering ASC');

		$db->setQuery($query);
		$results = $db->loadObjectList();

		return $this->groupByProductId($results, 'item_id');
	}

	// =========================================================================
	// Tax fetching
	// =========================================================================

	/**
	 * Fetch active taxes for the given products, matched to the visitor's context.
	 *
	 * Scope matching follows the same five-dimension logic as fetchDiscounts().
	 * See that method's doc block for the full explanation.
	 *
	 * @param   array         $productIds  Product IDs
	 * @param   PriceContext  $context     Pricing context
	 *
	 * @return  array  Taxes grouped by product ID
	 */
	protected function fetchTaxes(array $productIds, PriceContext $context): array
	{
		$db            = $this->db;
		$productIdList = implode(',', array_map('intval', $productIds));

		// Visitor's usergroup ids as SQL IN() list — always includes 0
		$userGroupsSql = $context->getUserGroupsSql();

		// Visitor's location id; 0 means unknown / not set
		$locationId = (int) ($context->getLocationId() ?? 0);

		// Visitor's user id; 0 means guest / anonymous
		$userId = (int) ($context->getUserId() ?? 0);

		$query = $db->getQuery(true)
			->select('DISTINCT t.id, t.name, t.value, t.behavior, ic.item_id')
			->from('#__alfa_taxes AS t')

			// ── Scope 1: Category ──────────────────────────────────────────────
			->join('LEFT',  '#__alfa_tax_categories AS tc ON tc.tax_id = t.id')
			->join('INNER', '#__alfa_items_categories AS ic ON ic.item_id IN (' . $productIdList . ')')
			->where('(tc.tax_id IS NULL OR tc.category_id = 0 OR tc.category_id = ic.category_id)')

			// ── Scope 2: Manufacturer ─────────────────────────────────────────
			->join('LEFT', '#__alfa_tax_manufacturers AS tm ON tm.tax_id = t.id')
			->join('LEFT', '#__alfa_items_manufacturers AS im ON im.item_id = ic.item_id')
			->where('(tm.tax_id IS NULL OR tm.manufacturer_id = 0 OR im.manufacturer_id IS NULL OR tm.manufacturer_id = im.manufacturer_id)')

			// ── Scope 3: Place ────────────────────────────────────────────────
			->join('LEFT', '#__alfa_tax_places AS tp ON tp.tax_id = t.id')
			->where('(tp.tax_id IS NULL OR tp.place_id = 0 OR tp.place_id = ' . $locationId . ')')

			// ── Scope 4: Usergroup ────────────────────────────────────────────
			// Filter by VISITOR'S usergroups from PriceContext, not item-access table.
			->join('LEFT', '#__alfa_tax_usergroups AS tu ON tu.tax_id = t.id')
			->where('(tu.tax_id IS NULL OR tu.usergroup_id = 0 OR tu.usergroup_id IN (' . $userGroupsSql . '))')

			// ── Scope 5: User ─────────────────────────────────────────────────
			->join('LEFT', '#__alfa_tax_users AS tgu ON tgu.tax_id = t.id')
			->where('(tgu.tax_id IS NULL OR tgu.user_id = 0 OR tgu.user_id = ' . $userId . ')')

			->where($this->dateHelper->getActiveCondition('t.publish_up', 't.publish_down'))
			->where('t.state = 1')
			->order('t.ordering ASC');

		$db->setQuery($query);
		$results = $db->loadObjectList();

		return $this->groupByProductId($results, 'item_id');
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Group database result rows by product ID.
	 *
	 * Produces an array keyed by product id, where each value is an array
	 * of rows for that product. This is the input format expected by
	 * PriceComputationEngine::compute() and PriceDataCollection.
	 *
	 * @param   array   $results  Database result rows (objects)
	 * @param   string  $idField  Field name containing the product ID
	 *
	 * @return  array  Results grouped by product ID
	 */
	protected function groupByProductId(array $results, string $idField): array
	{
		$grouped = [];

		foreach ($results as $result)
		{
			$id = $result->{$idField};

			if (!isset($grouped[$id]))
			{
				$grouped[$id] = [];
			}

			$grouped[$id][] = $result;
		}

		return $grouped;
	}
}