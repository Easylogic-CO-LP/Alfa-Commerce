<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Items;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedItems;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLink;
use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Items view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use ListsTranslations;
    use PublishesStoredFigures;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'alias',
        'short_desc',
        'full_desc',
        'meta_title',
        'meta_desc',
        'sku',
        'gtin',
        'mpn',
        'id_category_default',
        'state',
        'stock',
        'available_quantity',
        'quantity_min',
        'quantity_max',
        'quantity_step',
        'stock_action',
        'stock_low',
        'manage_stock',
        'backorder',
        'min_qty',
        'is_virtual',
        'width',
        'height',
        'depth',
        'weight',
        'meta_data',
        'robots',
        'prices',
        'prices_state',
        'categories',
        'manufacturers',
        'shipments',
        'related',
        'medias',
        'translations',
        'fields',
        'options',
        'combinations',
        'informational',
        'publish_up',
        'publish_down',
        'created_by',
        'modified',
        'commerce_changed_at',
        'modified_by',
        'ordering',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'name',
        'alias',
        'sku',
        'gtin',
        'mpn',
        'state',
        'stock',
        'available_quantity',
        'manage_stock',
        'stock_action',
        'is_virtual',
        'combination_count',
        'id_category_default',
        'category_ids',
        'manufacturer_ids',
        'publish_up',
        'publish_down',
        'modified',
        // When the product's stock, prices or own fields last changed (UTC, to the microsecond): filter[changed_since].
        'commerce_changed_at',
        'ordering',
    ];

    /**
     * An item's price rows and option deltas as exact decimal text.
     *
     * A price row states an amount in its own currency (the shop's default when
     * it names none), published at that currency's scale; an adjust row of a
     * percentage operation states a percentage. An option's amount is a money
     * delta ('+', '-') in the shop's default currency, at its scale, or a
     * percentage ('%', '-%') in its own digits.
     *
     * @param object $item The item.
     *
     * @return object
     *
     * @since  2.0.0
     */
    protected function prepareItem($item)
    {
        if (isset($item->prices) && \is_array($item->prices)) {
            $item->prices = array_map(static function ($row) {
                $row = (array) $row;
                $percent = ($row['mode'] ?? 'price') === 'adjust' && \in_array($row['operation'] ?? null, ['%', '-%'], true);
                $scale = (int) ($row['currency_id'] ?? 0) > 0
                    ? Currency::loadById((int) $row['currency_id'])->getDecimalPlaces()
                    : self::defaultScale();

                foreach (['value', 'override_value'] as $field) {
                    if (\array_key_exists($field, $row)) {
                        $row[$field] = $percent ? self::storedRate(value: $row[$field]) : self::storedAmount(value: $row[$field], scale: $scale);
                    }
                }

                return $row;
            }, $item->prices);
        }

        if (isset($item->options) && \is_array($item->options)) {
            $item->options = array_map(static function ($option) {
                if (\is_array($option) && \array_key_exists('amount', $option)) {
                    $option['amount'] = \in_array($option['operation'] ?? null, ['+', '-'], true)
                        ? self::storedAmount(value: $option['amount'], scale: self::defaultScale())
                        : self::storedRate(value: $option['amount']);
                }

                return $option;
            }, $item->options);
        }

        // The shipping methods the product ships with, by id; [] for every method. Read only: the item screen sets
        // them, and each combination carries its own (null: as the product's).
        if (isset($item->shipments) && \is_array($item->shipments)) {
            $item->shipments = array_values(array_map('intval', $item->shipments));
        }

        // The related products, in their order, each {item_id, combination_id (null for none), values (an object of
        // field id to value id)}: the shape a write sends.
        if (isset($item->related) && \is_array($item->related)) {
            $item->related = array_values(array_map(static fn (RelatedLink $link): array => [
                'item_id' => $link->itemId,
                'combination_id' => $link->combinationId > 0 ? $link->combinationId : null,
                'values' => (object) array_combine(array_map('strval', array_keys($link->values)), array_values($link->values)),
            ], RelatedItems::posted(posted: $item->related)));
        }

        // A product that tracks stock and holds no stock record has none on the shelf: its stock and available_quantity
        // read 0. The inventory makes no record for a figure of 0, so a product created with stock 0 holds no figure,
        // which a client would take for "not tracked". A product that does not track stock and holds no figure reads
        // null for both; a stored figure is given as stored.
        if (\is_object($item) && (int) ($item->manage_stock ?? 0) === 1) {
            foreach (['stock', 'available_quantity'] as $figure) {
                if (property_exists($item, $figure) && $item->$figure === null) {
                    $item->$figure = 0.0;
                }
            }
        }

        return $item;
    }

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.3.0
     */
    protected function translationSource(): array
    {
        return ['#__alfa_items', 'id_item', []];
    }
}
