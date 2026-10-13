<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\BackorderPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\QueryInterface;

/**
 * What `stock_action` means, and what it does to the catalogue: the ONE definition.
 *
 * `stock_action` is a FOUR-valued enum, never a flag:
 *
 *     -1  inherit the shop-wide setting ("From configuration")
 *      0  no action: sell/show normally with no salable stock
 *      1  offer a "notify me" button instead of add-to-cart
 *      2  hide the product from listings while it has no salable stock
 *
 * `-1` is a SENTINEL, not a value: nothing may compare it to anything before it has
 * been resolved against the shop-wide setting. That resolution used to happen in PHP
 * only, in three hand-copied `if ($x == -1)` branches that ran AFTER the rows were
 * fetched, so every SQL predicate compared the raw column, and a product set to
 * "From configuration" was never hidden however the shop was configured. The
 * resolution therefore lives here and is expressible in BOTH languages: {@see
 * self::effectiveAction()} for a loaded row, {@see self::effectiveExpression()} for a
 * query.
 *
 * ## The inheritance chain
 *
 *     combination value -> item value -> global setting
 *
 * The first two links are the overlay's job: a combination column is NULL when it does
 * not override, so {@see \Alfa\Component\Alfa\Site\Helper\VariantHelper::overlay()}
 * simply does not copy it. This class owns the last link, plus the one case the
 * overlay cannot express: a combination that stores `-1` and so skips the item and
 * goes straight to the global.
 *
 * ## Why the predicate is NULL-safe, and why it asks the ledger
 *
 * `#__alfa_items.stock_action` is nullable, and the previous predicates were written
 * as `NOT (col = 2 AND ...)`. With a NULL column that is `NOT (NULL AND ...)` = NULL, not
 * TRUE, so an imported or API-written row with a NULL action silently vanished from
 * the catalogue AND from every count. Collapsing the column through
 * `COALESCE(NULLIF(col, -1), <global>)` removes the NULL before it is ever compared.
 *
 * Availability asks the stock ledger, through the one sellability rule of
 * {@see InventoryAvailabilityService}: the salable quantity at enabled locations, per
 * unit, with the unit's own tracking, backorder action, safety stock, integrity hold and
 * smallest sale under its quantity rules. The stock and available_quantity columns are
 * projections; they are read only to recognise a stock-managed unit that shows stock
 * without any stock record, which is never sold.
 *
 * ## THE CACHING RULE
 *
 * Any cache holding a result computed through this policy MUST fold {@see
 * self::cacheKey()} into its key, or a merchant who changes the global setting sees
 * nothing happen until the cache expires. Category product counts are cached for a
 * long time and are computed through this predicate.
 *
 * @since  2.0.0
 */
final class StockVisibilityPolicy
{
    /**
     * Inherit the shop-wide setting.
     *
     * @var int
     * @since  2.0.0
     */
    public const ACTION_INHERIT = -1;

    /**
     * Do nothing special when there is no salable stock.
     *
     * @var int
     * @since  2.0.0
     */
    public const ACTION_NONE = 0;

    /**
     * Offer a "notify me" button instead of add-to-cart.
     *
     * @var int
     * @since  2.0.0
     */
    public const ACTION_NOTIFY = 1;

    /**
     * Remove the product from listings and counts while it has no salable stock.
     *
     * @var int
     * @since  2.0.0
     */
    public const ACTION_HIDE_OUT_OF_STOCK = 2;

    /**
     * Every value a merchant may store on an item or a combination override.
     *
     * @var int[]
     * @since  2.0.0
     */
    public const ALLOWED_VALUES = [
        self::ACTION_INHERIT,
        self::ACTION_NONE,
        self::ACTION_NOTIFY,
        self::ACTION_HIDE_OUT_OF_STOCK,
    ];

    /**
     * Every value the SHOP-WIDE setting may hold. Deliberately without
     * ACTION_INHERIT: the global has nothing to inherit from, so offering it there
     * would be a self-reference.
     *
     * @var int[]
     * @since  2.0.0
     */
    public const GLOBAL_VALUES = [
        self::ACTION_NONE,
        self::ACTION_NOTIFY,
        self::ACTION_HIDE_OUT_OF_STOCK,
    ];

    /**
     * The shop-wide fallback, already normalised to a real action.
     *
     * @since  2.0.0
     */
    private readonly int $global;

    /**
     * The shop-wide backorder default and safety floor, for the salable half of
     * the predicate: an item that inherits them is judged by the SAME rule the
     * storefront flag and the cart clamp apply.
     *
     * @since  2.0.0
     */
    private readonly string $globalBackorder;

    /**
     * @since  2.0.0
     */
    private readonly float $globalMinQty;

    /**
     * @param int|null $global Explicit shop-wide action; null reads com_alfa's
     *                         parameters. Pass it explicitly from CLI and from
     *                         tests, where there is no application to ask.
     *
     * @since  2.0.0
     */
    public function __construct(?int $global = null)
    {
        $resolved = $global ?? (int) ComponentHelper::getParams('com_alfa')->get('stock_action', self::ACTION_NONE);

        // Anything unknown (including a stray -1, which would be a self-reference)
        // degrades to "no action", the only choice that hides nothing from anybody.
        $this->global = \in_array($resolved, self::GLOBAL_VALUES, true) ? $resolved : self::ACTION_NONE;

        $this->globalBackorder = (new BackorderPolicy())->globalAction();
        $this->globalMinQty = (float) ComponentHelper::getParams('com_alfa')->get('min_qty', 0);
    }

    /**
     * The shop-wide action every inheriting product follows.
     *
     * @return int One of GLOBAL_VALUES.
     *
     * @since  2.0.0
     */
    public function globalAction(): int
    {
        return $this->global;
    }

    /**
     * Decode a stored value into the action that actually applies.
     *
     * NULL and '' are treated as the sentinel: the column is nullable, and a row that
     * says nothing must follow the shop rather than opt out of it.
     *
     * @param int|string|null $stored The raw column value; rows arrive as strings.
     *
     * @return int One of GLOBAL_VALUES; never ACTION_INHERIT.
     *
     * @since  2.0.0
     */
    public function effectiveAction(int|string|null $stored): int
    {
        if ($stored === null || $stored === '' || (int) $stored === self::ACTION_INHERIT) {
            return $this->global;
        }

        $value = (int) $stored;

        return \in_array($value, self::GLOBAL_VALUES, true) ? $value : self::ACTION_NONE;
    }

    /**
     * Normalise a merchant-supplied value for STORAGE.
     *
     * Distinct from {@see self::effectiveAction()} on purpose: reading resolves the
     * sentinel, writing must preserve it: an item stored as -1 has to keep following
     * the shop when the merchant later changes it.
     *
     * @param mixed $value The submitted value.
     *
     * @return int|null A member of ALLOWED_VALUES, or null for "no override".
     *
     * @since  2.0.0
     */
    public static function normaliseStoredValue(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return \in_array((int) $value, self::ALLOWED_VALUES, true) ? (int) $value : null;
    }

    /**
     * SQL yielding the EFFECTIVE action for a row: the sentinel and NULL both collapse
     * to the shop-wide value.
     *
     * NULLIF + COALESCE rather than a CASE so the whole thing stays a single
     * expression that a WHERE and a JOIN ... ON clause can share verbatim.
     *
     * @param string $alias Alias `#__alfa_items` is joined under.
     *
     *
     * @since  2.0.0
     */
    public function effectiveExpression(string $alias = 'a'): string
    {
        return 'COALESCE(NULLIF(' . $alias . '.stock_action, ' . self::ACTION_INHERIT . '), ' . $this->global . ')';
    }

    /**
     * The catalogue predicate: TRUE for every row the hide-out-of-stock rule allows.
     *
     * Returned as a fragment rather than applied to a query because one of its callers
     * builds a JOIN ... ON clause by string concatenation, with no query object in
     * scope. Nothing is bound: the only interpolated value is an int normalised in the
     * constructor, and Joomla's bind() takes its value BY REFERENCE, so a literal
     * there would be fatal on PHP 8 anyway.
     *
     * @param string $alias Alias `#__alfa_items` is joined under.
     *
     *
     * @since  2.0.0
     */
    public function condition(string $alias = 'a'): string
    {
        // stock_action is still the gate: a product is hidden ONLY when the merchant
        // chose "hide out of stock", and then only when it is not salable under policy.
        // The COALESCE/NULLIF wrapper keeps the predicate NULL-safe: a row with a NULL
        // action must not evaluate to NULL and silently vanish from the count.
        return 'NOT (' . $this->effectiveExpression(alias: $alias) . ' = ' . self::ACTION_HIDE_OUT_OF_STOCK
            . ' AND NOT ' . $this->sellableExpression(alias: $alias) . ')';
    }

    /**
     * "This product can be bought right now", as SQL over the items table: the one
     * sellability rule of {@see InventoryAvailabilityService::sellableSql()}, applied to
     * the product's units and read from the stock ledger.
     *
     * A product without combinations is its own unit. A product with combinations sells
     * when at least one PUBLISHED combination sells; its own item-level stock is not a
     * unit and never counts, and a product whose combinations are all unpublished is not
     * sellable. Only current combinations make a product one with combinations: an
     * archived combination is kept as the stock identity of order history, never counts,
     * and a product whose combinations are all archived is its own unit again. Each unit
     * is judged with its own resolved policy: tracking combination then product,
     * backorder combination then product then shop (a combination's empty value skips
     * the product), safety stock combination then product then shop, quantity rules
     * combination then product rule by rule, as the cart resolves them, compared as the
     * exact decimals they are stored as. A unit whose quantity rules accept no quantity,
     * or that is held until its stock figures are repaired, is never sellable.
     *
     * The hide-out-of-stock gate, the visitor's in/out filter and its counts all read
     * this expression, so a product the shop would hide and a product a visitor filters
     * out are always the same product. It is two-valued, so `NOT <expr>` is its exact
     * complement.
     *
     * @param string $alias The items table alias in the caller's query.
     *
     * @return string A boolean SQL expression, parenthesised.
     *
     * @since  2.0.0
     */
    public function sellableExpression(string $alias = 'a'): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $globalMinQty = number_format($this->globalMinQty, 8, '.', '');

        // One quantity rule of a unit: the combination's value when it declares a
        // non-zero one, the product's otherwise; the product's own unit has no
        // combination value to read.
        $rule = static fn (?string $combinationAlias, string $column): string => CartQuantityPolicy::ruleValueSql(
            combinationExpr: $combinationAlias === null ? 'NULL' : $combinationAlias . '.' . $column,
            itemExpr:        $alias . '.' . $column,
        );

        $itemSellable = InventoryAvailabilityService::sellableSql(
            itemExpr:              $alias . '.id',
            combinationExpr:       '0',
            managesExpr:           'COALESCE(' . $alias . '.manage_stock, 0) = 1',
            projectionNonZeroExpr: InventoryAvailabilityService::projectionNonZeroSql(
                stockExpr:     $alias . '.stock',
                availableExpr: $alias . '.available_quantity',
            ),
            holdExpr:              InventoryAvailabilityService::integrityHoldSql(
                db:              $db,
                itemExpr:        $alias . '.id',
                combinationExpr: '0',
            ),
            backorderExpr:         InventoryAvailabilityService::backorderActionSql(
                db:           $db,
                storedExpr:   $alias . '.backorder',
                globalAction: $this->globalBackorder,
            ),
            minQtyExpr:            'COALESCE(' . $alias . '.min_qty, ' . $globalMinQty . ')',
            minRuleExpr:           $rule(combinationAlias: null, column: 'quantity_min'),
            stepRuleExpr:          $rule(combinationAlias: null, column: 'quantity_step'),
            maxRuleExpr:           $rule(combinationAlias: null, column: 'quantity_max'),
        );

        // A combination's NULL backorder inherits the product; its empty string is a
        // stored "follow the shop", which the resolution turns into the shop-wide action.
        $unitSellable = InventoryAvailabilityService::sellableSql(
            itemExpr:              'sv_c.item_id',
            combinationExpr:       'sv_c.id',
            managesExpr:           'COALESCE(sv_c.manage_stock, ' . $alias . '.manage_stock, 0) = 1',
            projectionNonZeroExpr: InventoryAvailabilityService::projectionNonZeroSql(
                stockExpr:     'sv_c.stock',
                availableExpr: 'sv_c.available_quantity',
            ),
            holdExpr:              InventoryAvailabilityService::integrityHoldSql(
                db:              $db,
                itemExpr:        'sv_c.item_id',
                combinationExpr: 'sv_c.id',
            ),
            backorderExpr:         InventoryAvailabilityService::backorderActionSql(
                db:           $db,
                storedExpr:   'COALESCE(sv_c.backorder, ' . $alias . '.backorder)',
                globalAction: $this->globalBackorder,
            ),
            minQtyExpr:            'COALESCE(sv_c.min_qty, ' . $alias . '.min_qty, ' . $globalMinQty . ')',
            minRuleExpr:           $rule(combinationAlias: 'sv_c', column: 'quantity_min'),
            stepRuleExpr:          $rule(combinationAlias: 'sv_c', column: 'quantity_step'),
            maxRuleExpr:           $rule(combinationAlias: 'sv_c', column: 'quantity_max'),
        );

        return '(CASE WHEN EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_item_combinations', 'sv_b')
            . ' WHERE sv_b.item_id = ' . $alias . '.id AND ' . CombinationState::currentSql(column: $db->quoteName('sv_b.state')) . ')'
            . ' THEN EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_item_combinations', 'sv_c')
            . ' WHERE sv_c.item_id = ' . $alias . '.id AND sv_c.state = 1 AND ' . $unitSellable . ')'
            . ' ELSE ' . $itemSellable
            . ' END)';
    }

    /**
     * Restrict a query over `#__alfa_items` to the products the hide-out-of-stock rule
     * allows.
     *
     * @param QueryInterface $query The query being built.
     * @param string $alias Alias `#__alfa_items` is joined under.
     *
     *
     * @since  2.0.0
     */
    public function applyToItems(QueryInterface $query, string $alias = 'a'): void
    {
        $query->where($this->condition(alias: $alias));
    }

    /**
     * A stable key fragment for the shop-wide setting this policy speaks for, so a
     * cached count computed under one setting is not served after the merchant
     * changes it.
     *
     *
     * @since  2.0.0
     */
    public function cacheKey(): string
    {
        // The predicate now also reads the shop-wide backorder and safety floor, so a
        // change to either must invalidate cached counts too. Per-item overrides ride
        // in the query itself and need no key.
        return 'sa' . $this->global . 'bo' . $this->globalBackorder . 'mq' . $this->globalMinQty;
    }
}
