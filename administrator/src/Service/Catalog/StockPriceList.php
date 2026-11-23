<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use stdClass;

/**
 * The light list of stock and prices: one row for each product without combinations and for each combination of a
 * product, published or unpublished, with its codes, its stock figures and its selling price, a page at a time.
 *
 * A row is a unit an integrator keeps in step: the product itself (combination 0) when it has no combination that is
 * published or unpublished, else each such combination. Its price is the one the shop's product list reads for a guest
 * at no place in the shop's currency: the price index's row of the combination when it has price rows of its own, else
 * the product's own row, which is the price of every combination without them. The index holds rows for published
 * units only: an unpublished combination of a published product reads the product's own row, and an unpublished
 * product, or a unit no price row applies to, has no price. A price of 0 is a price.
 *
 * One statement a page, whatever the page's size: the units are read in key order (item_id, then combination_id) from
 * the products and the combinations by their keys, each side limited to the page, and their prices joined by the index's
 * key. A page continues after the last key of the one before it, so a client walking the list while products change
 * reads every unit once and never a unit twice. changed_since keeps the units of the products whose stock, prices or
 * own fields changed at or after a UTC time ({@see CommerceChange}).
 *
 * @api READ ONLY: a page of units with their stock and prices.
 *
 * @since  2.2.2
 */
final class StockPriceList
{
    /**
     * The most units a page holds.
     *
     * @since  2.2.2
     */
    public const PAGE_MAX = 1000;

    /**
     * The units a page holds when the caller names no size.
     *
     * @since  2.2.2
     */
    public const PAGE_DEFAULT = 100;

    /**
     * The states a product of the list may have: published and unpublished; an archived or trashed product is not one.
     * A combination is one while it is current ({@see CombinationState::currentSql()}: anything but archived).
     *
     * @since  2.2.2
     */
    private const STATES = '0, 1';

    /**
     * One page of the list.
     *
     * @param int $limit At most this many units (1 to PAGE_MAX).
     * @param int $afterItem The page starts after this unit's key: its product.
     * @param int $afterCombination The page starts after this unit's key: its combination (0 for a product).
     * @param string|null $changedSince Only the units of products changed at or after this UTC time (CommerceChange::bound()).
     *
     * @return array{rows: list<stdClass>, next: string|null, currency: string, priced: bool} The units in key order;
     *         the key of the last one when the page is full (the next page starts after it); the currency of the prices;
     *         whether the prices were read (false while the price index is built after an update, or when no pricing
     *         context is prepared).
     *
     * @since  2.2.2
     */
    public static function page(int $limit, int $afterItem = 0, int $afterCombination = 0, ?string $changedSince = null): array
    {
        $limit = max(1, min(self::PAGE_MAX, $limit));
        $afterItem = max(0, $afterItem);
        $afterCombination = max(0, $afterCombination);
        /** @var DatabaseDriver $db */
        $db = Factory::getContainer()->get('DatabaseDriver');
        $items = $db->quoteName('#__alfa_items');
        $combinations = $db->quoteName('#__alfa_item_combinations');
        $changed = $changedSince === null ? '' : ' AND a.' . CommerceChange::COLUMN . ' >= ' . $db->quote($changedSince);

        // The products without a combination that is published or unpublished, and the combinations of the others,
        // each side in key order and limited to the page, so each is read by its key from the page's start.
        $units = '(SELECT a.id AS item_id, 0 AS combination_id, a.sku, a.gtin, a.state, a.state AS item_state, a.manage_stock,'
            . ' a.available_quantity, a.stock, a.' . CommerceChange::COLUMN . ' AS changed_at FROM ' . $items . ' AS a'
            . ' WHERE a.state IN (' . self::STATES . ') AND a.id > ' . $afterItem . $changed
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $combinations . ' AS x WHERE x.item_id = a.id AND ' . CombinationState::currentSql(column: 'x.state') . ')'
            . ' ORDER BY a.id LIMIT ' . $limit . ')'
            . ' UNION ALL'
            . ' (SELECT oc.item_id, oc.id, oc.sku, oc.gtin, oc.state, a.state, COALESCE(oc.manage_stock, a.manage_stock),'
            . ' oc.available_quantity, oc.stock, a.' . CommerceChange::COLUMN . ' FROM ' . $combinations . ' AS oc'
            . ' INNER JOIN ' . $items . ' AS a ON a.id = oc.item_id AND a.state IN (' . self::STATES . ')'
            . ' WHERE ' . CombinationState::currentSql(column: 'oc.state') . ' AND (oc.item_id > ' . $afterItem . ' OR (oc.item_id = ' . $afterItem . ' AND oc.id > ' . $afterCombination . '))'
            . $changed
            . ' ORDER BY oc.item_id, oc.id LIMIT ' . $limit . ')';

        // The price a guest at no place reads in the shop's currency: the combination's own row, else the product's.
        $currency = Currency::getDefault();
        $read = PriceIndexSyncService::isBuilding() ? null : PriceIndexSyncService::readContext(context: PriceContext::fromSession()->withCustomer(customer: new User()));
        $context = (int) ($read['context'] ?? 0);
        $now = $db->quote(gmdate('Y-m-d H:i:s'));
        $index = $db->quoteName(PriceIndexSyncService::INDEX_TABLE);
        $period = static fn (string $alias): string => $alias . '.valid_from <= ' . $now . ' AND (' . $alias . '.valid_until IS NULL OR ' . $alias . '.valid_until > ' . $now . ')';
        $figure = static fn (string $column): string => 'IF(pc.item_id IS NULL, pp.' . $column . ', pc.' . $column . ')';

        $rows = $db->setQuery(
            'SELECT u.item_id, u.combination_id, u.sku, u.gtin, u.state, u.item_state, u.manage_stock, u.available_quantity, u.stock, u.changed_at,'
            . ' ' . $figure('final_price') . ' AS gross, ' . $figure('base_price_with_discounts') . ' AS net FROM (' . $units . ') AS u'
            . ' LEFT JOIN ' . $index . ' AS pc ON pc.context_id = ' . $context . ' AND pc.item_id = u.item_id AND pc.combination_id = u.combination_id'
            . ' AND u.combination_id > 0 AND ' . $period('pc')
            . ' LEFT JOIN ' . $index . ' AS pp ON pp.context_id = ' . $context . ' AND pp.item_id = u.item_id AND pp.combination_id = 0 AND ' . $period('pp')
            . ' ORDER BY u.item_id, u.combination_id',
            0,
            $limit,
        )->loadObjectList() ?: [];

        $last = $rows === [] ? null : $rows[\count($rows) - 1];

        return [
            'rows' => $rows,
            'next' => \count($rows) === $limit && $last !== null ? (int) $last->item_id . ':' . (int) $last->combination_id : null,
            'currency' => $currency->getCode(),
            'priced' => $context > 0,
        ];
    }

    /**
     * A page key as a client sends it, item_id:combination_id, read into its two parts.
     *
     * @param string $key The key as sent.
     *
     * @return array{0: int, 1: int}|null The product and the combination, or null when it is not a key.
     *
     * @since  2.2.2
     */
    public static function key(string $key): ?array
    {
        return preg_match('/^(\d{1,10}):(\d{1,10})$/', trim($key), $match) === 1 ? [(int) $match[1], (int) $match[2]] : null;
    }
}
