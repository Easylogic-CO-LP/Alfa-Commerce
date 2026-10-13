<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Alfa\Component\Alfa\Site\Service\Pricing\VariantSelection;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\User\User;
use RuntimeException;

/**
 * Compact price-matrix editor for #__alfa_items_prices rows.
 *
 * One line per price rule (price / override / scopes / status) with quantity
 * tier and publish window in a per-row expander. Posts the exact shape
 * ItemModel::setPrices() consumes.
 *
 * HTML lives in layouts/prices/{editor,row}.php; behavior/styles in the
 * com_alfa.prices web assets. renderPrices() is public and static so the
 * combinations editor reuses the identical UI for per-variant prices: same
 * table (#__alfa_items_prices, combination_id rows), same look, same logic.
 * In that context $showModify additionally offers per-row Adjust mode
 * (a +/-/% delta routed to the impacts table on save).
 *
 * @since  2.0.0
 */
class AlfapricesField extends FormField
{
    /**
     * @var string
     */
    public $type = 'Alfaprices';

    /**
     * @var array|null Cached select options shared by every render on the page.
     *
     * @since  2.0.0
     */
    private static $options = null;

    /**
     * @var array[] Per-(item x row dimensions) engine previews, cached for the request.
     *
     * @since  2.0.0
     */
    private static $previews = [];

    /**
     * Render the field input.
     *
     * @return string
     *
     * @since  2.0.0
     */
    protected function getInput()
    {
        $rows = is_array($this->value) ? $this->value : [];

        return self::renderPrices(
            controlBase: $this->name,
            rows: $rows,
            itemId: (int) $this->form->getValue('id'),
        );
    }

    /**
     * Render a price-matrix editor for the given control base.
     *
     * @param string $controlBase POST base, e.g. 'jform[prices]' or 'jform[combination_prices][12]'
     * @param array $rows Existing price rows (assoc arrays or objects).
     * @param bool $showModify Offer the per-row price/adjust mode (combination context).
     * @param int $itemId Item id; enables the live final-price (tax-inclusive) preview.
     *
     * @return string HTML
     *
     * @since  2.0.0
     */
    public static function renderPrices(string $controlBase, array $rows, bool $showModify = false, int $itemId = 0): string
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_alfa.prices')
            ->useScript('com_alfa.prices');

        // The live coefficient refresh (prices.js -> item.pricePreview)
        // rebuilds the applied-chips line and hints client-side.
        Text::script('COM_ALFA_PRICES_DISCOUNTED');
        Text::script('COM_ALFA_PRICES_FINAL');
        Text::script('COM_ALFA_PRICES_PRICE');

        $layout = new FileLayout('prices.editor', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');

        return $layout->render([
            'controlBase' => $controlBase,
            'rows' => array_map(static fn ($row) => (array) $row, array_values($rows)),
            'showModify' => $showModify,
            'options' => self::loadOptions(),
            'itemId' => $itemId,
            'preview' => static fn (array $row): array => self::rowPreview(itemId: $itemId, row: $row),
        ]);
    }

    /**
     * What the shop charges for one unit of a variant, for the visitor an
     * any-context row previews for (previewContext()): the engine's own price,
     * whatever the variant's rows hold (a custom discount, an adjustment, the
     * item's price).
     *
     * @param int $itemId The item id.
     * @param int $combinationId The variant.
     *
     *
     * @since  2.0.0
     */
    public static function chargedPrice(int $itemId, int $combinationId): Money
    {
        return (new PriceCalculator())->calculate(
            productIds: $itemId,
            quantities: 1,
            context: self::previewContext(row: []),
            selection: new VariantSelection(combinationId: $combinationId),
        )->getPrice();
    }

    /**
     * The item's base NET price as the storefront resolves it for the shopper a
     * price row reaches: the net a combination/option ADJUST applies its delta
     * to, so the editors can preview an adjusted final client-side without a
     * server round-trip per row and invert a typed final against the same figure.
     *
     * It is the engine's own resolution, not a second reading of the table.
     * PriceCalculator::breakdownVariant() with no selection returns the base_net
     * of resolveVariantNet(): the winning row of selectBaseRow() at the row's
     * quantity among the published, in-window item rows its context reaches,
     * with a sale (override_value) standing in for the list value. Context and
     * quantity are the ones rowPreview() prices the same row under, so this base
     * and the tax factor the editors pair it with come from one row set. An
     * empty row is the grid quick edit's any-context row.
     *
     * @param int $itemId The item id.
     * @param array $row The price row values (empty = any context, one unit).
     *
     * @return float The base net price, or 0.0 when none is defined.
     *
     * @since  2.0.0
     */
    public static function baseNet(int $itemId, array $row = []): float
    {
        if ($itemId <= 0) {
            return 0.0;
        }

        return (float) self::baseResolution(itemId: $itemId, row: $row)['base_net'];
    }

    /**
     * The engine's resolution of the item base a price row previews against:
     * the winning base row's net and whether that row carries its own sale,
     * under the row's context and quantity (see baseNet()).
     *
     * @param int $itemId The item id.
     * @param array $row The price row values (empty = any context, one unit).
     *
     * @return array{base_net: float, base_sale: bool, tax_factor: float, lines: array<int, array>}
     *
     * @since  2.0.0
     */
    private static function baseResolution(int $itemId, array $row): array
    {
        return (new PriceCalculator())->breakdownVariant(
            itemId: $itemId,
            quantity: self::previewQuantity(row: $row),
            selection: new VariantSelection(),
            context: self::previewContext(row: $row),
        );
    }

    /**
     * The pricing context a price row previews under: the row's own dimensions,
     * with an any-currency row (0) priced in the shop's default currency.
     *
     * Every figure the editors show for such a row is already in that currency:
     * the engine prices a currency-0 context in it and the preview takes its
     * decimals from it. It is also the currency the storefront gives a visitor
     * who has chosen none (PriceContext::fromSession()). Loading only the
     * currency-0 rows instead drops an item priced in the default currency,
     * and its variants were anchored on a base of zero.
     *
     * The shopper is the one the row reaches. A row for one customer account is
     * priced for that customer, as the storefront prices the account once it
     * signs in: its own id and its own groups with their parents, whatever group
     * the row also names (PriceContext::withCustomer()). Every other row prices
     * under previewGroups().
     *
     * @param array $row The price row values.
     *
     *
     * @since  2.0.0
     */
    private static function previewContext(array $row): PriceContext
    {
        $currencyId = (int) ($row['currency_id'] ?? 0);
        $userId = (int) ($row['user_id'] ?? 0);

        $context = PriceContext::forIndex(
            currencyId: $currencyId > 0 ? $currencyId : Currency::getDefault()->getId(),
            placeId: (int) ($row['country_id'] ?? 0),
            usergroupId: 0,
        );

        if ($userId > 0) {
            return $context->withCustomer(customer: new User(identifier: $userId));
        }

        return $context->withUserGroups(groups: self::previewGroups(usergroupId: (int) ($row['usergroup_id'] ?? 0)));
    }

    /**
     * The user groups a price row with no customer account previews under: an
     * any-group row prices as the anonymous visitor, the guest group and its
     * parents; a row scoped to group G prices as a member of G, who holds G and
     * every parent of G. A group that no longer exists has no parents.
     *
     * @param int $usergroupId The row's user group; 0 for any group.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private static function previewGroups(int $usergroupId): array
    {
        if ($usergroupId <= 0) {
            return PriceContext::customerGroups(customer: new User());
        }

        return array_values(array_unique(array_merge([$usergroupId], PriceContext::groupLineage(groupId: $usergroupId))));
    }

    /**
     * The quantity a price row previews at: the first unit of its tier, else one.
     *
     * @param array $row The price row values.
     *
     *
     * @since  2.0.0
     */
    private static function previewQuantity(array $row): int
    {
        return max(1, (int) (float) ($row['quantity_start'] ?? 0) ?: 1);
    }

    /**
     * The frontend pricing result for ONE price row, computed by the SAME
     * site services the shop uses (PriceDataLoader + PriceComputationEngine)
     * under the row's OWN dimensions (currency/country/group/user, quantity
     * tier; see previewContext()). Discounts and fixed amounts make the
     * pipeline affine, so two probe runs (net 1000 and 2000) recover
     * final = a*net + b, which the editor uses to show the live final price
     * AND to invert it when the final price is typed. taxFactor covers the
     * override pair (discounts suppressed there by definition). baseNet is the
     * item base an ADJUST row applies its delta to, resolved under the same
     * dimensions (see baseNet()).
     *
     * @param int $itemId The item id (0 = new item: neutral preview).
     * @param array $row The price row values.
     *
     * Public so ItemController::pricePreview() can serve LIVE coefficient
     * refreshes when a row's dimension selects change in the editor.
     *
     * For an existing item, a preview that cannot be computed is refused, never
     * answered with substitute figures (a tax factor of 1.0): the editors invert
     * a typed Final with these figures and store the result.
     *
     * @return array{a: float, b: float, taxFactor: float, decimals: int, taxes: array, discounts: array, baseNet: float, baseSale: bool}
     *
     * @throws RuntimeException When the row's currency, rules or base cannot be resolved.
     *
     * @since  2.0.0
     */
    public static function rowPreview(int $itemId, array $row): array
    {
        if ($itemId <= 0) {
            return ['a' => 1.0, 'b' => 0.0, 'taxFactor' => 1.0, 'decimals' => 2, 'taxes' => [], 'discounts' => [], 'baseNet' => 0.0, 'baseSale' => false];
        }

        $quantity = self::previewQuantity(row: $row);
        $key = implode('-', [
            $itemId,
            (int) ($row['currency_id'] ?? 0),
            (int) ($row['country_id'] ?? 0),
            (int) ($row['usergroup_id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            $quantity,
        ]);

        if (isset(self::$previews[$key])) {
            return self::$previews[$key];
        }

        $context = self::previewContext(row: $row);

        $collection = (new PriceDataLoader())->loadBatch(productIds: [$itemId], context: $context);
        $discounts = $collection->getDiscountsFor($itemId);
        $taxes = $collection->getTaxesFor($itemId);
        $engine = new PriceComputationEngine();

        $probe = static fn (float $value) => $engine->compute(
            productId: $itemId,
            quantity: $quantity,
            prices: [(object) [
                'value' => $value,
                'override_value' => null,
                'quantity_start' => $row['quantity_start'] ?? null,
                'quantity_end' => $row['quantity_end'] ?? null,
            ]],
            discounts: $discounts,
            taxes: $taxes,
            context: $context,
        );

        $low = $probe(1000.0);
        $high = $probe(2000.0);

        $a = ($high->getPrice()->getAmount() - $low->getPrice()->getAmount()) / 1000.0;
        $b = $low->getPrice()->getAmount() - ($a * 1000.0);

        // The row's currency dictates the precision of every money
        // figure (EUR 2, JPY 0, BTC 8); nets carry two guard digits more
        // so any final price stays reachable by back-computation.
        $rowCurrency = ((int) ($row['currency_id'] ?? 0)) > 0
            ? Currency::loadById((int) $row['currency_id'])
            : Currency::getDefault();

        $preview = [
            'a' => $a,
            'b' => round($b, 6),
            'taxFactor' => $engine->taxFactor(taxes: $taxes),
            'decimals' => $rowCurrency->getDecimalPlaces(),
            'taxes' => array_map(
                static fn ($tax) => ['name' => $tax->name, 'rate' => $tax->rate],
                $high->getTaxes()->getApplied(),
            ),
            'discounts' => array_map(
                static fn ($discount) => [
                    'name' => $discount->name,
                    'percent' => $discount->percent,
                    'type' => $discount->type,
                    'timing' => $discount->timing,
                ],
                $high->getDiscounts()->getApplied(),
            ),
        ];

        // The base an adjust row applies its delta to, under this same context
        // and quantity, and whether it is a sale (then the engine runs no regular
        // discount on the variant).
        $resolution = self::baseResolution(itemId: $itemId, row: $row);
        $preview['baseNet'] = (float) $resolution['base_net'];
        $preview['baseSale'] = (bool) $resolution['base_sale'];

        return self::$previews[$key] = $preview;
    }

    /**
     * The select option lists, loaded once per request and shared by every
     * editor on the page.
     *
     * @return array{currencies: array, places: array, usergroups: array, users: array}
     *
     * @since  2.0.0
     */
    protected static function loadOptions(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $load = static function (string $query) use ($db) {
            $db->setQuery($query);

            return $db->loadAssocList('id', 'val') ?: [];
        };

        self::$options = [
            'currencies' => $load('SELECT `id`, CONCAT(`name`, " (", `code`, ")") AS val FROM `#__alfa_currencies` WHERE state = 1 ORDER BY ordering'),
            'places' => $load('SELECT `id`, `name` AS val FROM `#__alfa_places` ORDER BY `name`'),
            'usergroups' => $load('SELECT `id`, `title` AS val FROM `#__usergroups` ORDER BY `lft`'),
            'users' => $load('SELECT `id`, `name` AS val FROM `#__users` ORDER BY `name`'),
        ];

        return self::$options;
    }
}
