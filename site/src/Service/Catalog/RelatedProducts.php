<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\RelatedResolveEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedItems;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLink;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SelectionRules;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Joomla\CMS\Factory;

/**
 * The related products a visitor sees on a product's page: the product's links in the order staff set, read
 * through the site ItemsModel as the listing reads products (published, within their publish window, what the visitor
 * may see), with no search, so no search engine is asked and no result set is announced. Each card is the listing's
 * card, opened on what its link names: the product's default, the link's combination, or the link's values (dimension
 * values constrain the card whether it draws them or not; option values are chosen), so its stock, its price and its
 * buy form are that unit's, priced by the pricing engine as any card is.
 *
 * A link is judged again on every read, never trusted as stored: its combination must be a published combination of
 * the product and its values ones the product offers the visitor, the card must open on what it names, and when the
 * shop hides the product's out-of-stock units the unit it names must be in stock. A link that does not stand, like a
 * product the visitor may not see, is skipped and the next one takes its place. A unit with no price for the visitor
 * shows with no price and no add to cart, as the listing shows a product without one. The links are read once, at
 * most {@see self::READ_MAX}, and handed to onAlfaRelatedResolve ({@see RelatedResolveEvent}), whose listeners may
 * add, remove and reorder them; their products are read in pages of twice the count (at least 8), so a product with
 * many links never loads them all to show a few; a count of 0 reads nothing.
 *
 * @since  2.3.0
 */
final class RelatedProducts
{
    /**
     * How many of a product's links are read at most to fill its related products.
     *
     * @since  2.3.0
     */
    public const READ_MAX = 48;

    /**
     * The most a page shows.
     *
     * @since  2.3.0
     */
    public const SHOWN_MAX = 12;

    /**
     * The count that shows every related product the merchant put on the product's Related tab, in its order, up to
     * {@see self::SHOWN_MAX}: what a shop that never chose a count shows.
     *
     * @since  2.3.0
     */
    public const ALL = -1;

    /**
     * The products to show, decorated by the site ItemsModel, in their order.
     *
     * @param int $itemId The product whose page shows them.
     * @param int $count How many at most: {@see self::ALL} for every one (up to {@see self::SHOWN_MAX}), 0 for none.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public static function visible(int $itemId, int $count): array
    {
        $count = $count === self::ALL ? self::SHOWN_MAX : min(self::SHOWN_MAX, $count);

        if ($itemId <= 0 || $count <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Extensions may add, remove and reorder links before any is judged; what they add is judged as stored links
        // are, and a product is shown once.
        $plugins = AlfaPluginHelper::get();
        $plugins->importGroup('alfa');
        $event = RelatedResolveEvent::of(itemId: $itemId, links: RelatedItems::links(db: $db, itemId: $itemId, limit: self::READ_MAX), count: $count);
        $plugins->dispatchIsolated($event);
        $links = self::onePerProduct(links: \array_slice($event->links(), 0, self::READ_MAX));

        if ($links === []) {
            return [];
        }

        $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
        $page = max(8, $count * 2);
        $shown = [];

        foreach (array_chunk($links, $page) as $chunk) {
            // What each preselecting link opens its card on, judged on the product as the visitor may buy it; a link
            // naming what the product no longer offers is skipped before the read.
            $data = ItemFieldsHelper::getDataForItems(array_map(static fn (RelatedLink $link): int => $link->itemId, $chunk));
            $selections = [];
            $standing = [];

            foreach ($chunk as $link) {
                if (!$link->isPreselected()) {
                    $standing[] = $link;

                    continue;
                }

                $selection = isset($data[$link->itemId]) ? self::selectionOf(data: $data[$link->itemId], link: $link) : null;

                if ($selection !== null) {
                    $selections[$link->itemId] = $selection;
                    $standing[] = $link;
                }
            }

            if ($standing === []) {
                continue;
            }

            /** @var ItemsModel $model */
            $model = $factory->createModel('Items', 'Site', ['ignore_request' => true]);
            // No category of the page's: each card is drawn from its own levels, its default category's and the
            // Options, its link and its reload address under its default category, however the visitor reached the
            // product page (the model reads category_id from the request otherwise), as the items module reads.
            $model->setCategoryContext(categoryId: 0);
            // Under ignore_request populateState() never runs: the state is what is set here.
            $model->setState('filter.id', array_map(static fn (RelatedLink $link): int => $link->itemId, $standing));
            $model->setState('list.limit', 0);
            $model->setState('list.start', 0);
            $model->setState('list.ordering', 'a.id');
            $model->setState('list.direction', 'ASC');
            $model->setState('list.fullordering', 'a.id ASC');
            // The cards' reload URLs carry the context, so a card is drawn again from the levels it was drawn from.
            $model->setState('card.context', 'related');
            $model->setState('card.selections', $selections);
            $byId = [];

            foreach ((array) $model->getItems() as $row) {
                $byId[(int) $row->id] = $row;
            }

            foreach ($standing as $link) {
                if (isset($byId[$link->itemId]) && self::stands(row: $byId[$link->itemId], link: $link) && \count($shown) < $count) {
                    $shown[] = $byId[$link->itemId];
                }
            }

            if (\count($shown) >= $count) {
                break;
            }
        }

        return $shown;
    }

    /**
     * What a preselecting link opens its product's card on, or null when the product as the visitor may buy it does
     * not offer it: the dimensions of the link's combination (a published combination of the product) and the link's
     * values split by {@see SelectionRules} into dimension values (ones a published combination carries) and option
     * values (ones the product offers).
     *
     * @param object $data The product's field data ({@see ItemFieldsHelper::getDataForItems()}): fields, options and
     *                     its published combinations.
     * @param RelatedLink $link The link.
     *
     * @return array{selection: array<int, int>, optionValueIds: array<int, int>}|null
     *
     * @since  2.3.0
     */
    public static function selectionOf(object $data, RelatedLink $link): ?array
    {
        $rules = SelectionRules::forItem(item: $data);
        $selection = [];
        $options = [];

        if ($link->combinationId > 0) {
            $combination = null;

            foreach ((array) ($data->combinations ?? []) as $candidate) {
                if ((int) $candidate->id === $link->combinationId) {
                    $combination = $candidate;

                    break;
                }
            }

            if ($combination === null) {
                return null;
            }

            foreach ((array) ($combination->value_ids ?? []) as $valueId) {
                foreach ($rules->dimensions() as $fieldId => $values) {
                    if (isset($values[(int) $valueId])) {
                        $selection[$fieldId] = (int) $valueId;
                    }
                }
            }
        }

        foreach ($link->values as $fieldId => $valueId) {
            $verdict = $rules->verdict(fieldId: $fieldId, valueId: $valueId);

            if ($verdict === SelectionRules::OPTION) {
                $options[$fieldId] = $valueId;
            } elseif ($verdict === SelectionRules::DIMENSION && ($selection[$fieldId] ?? $valueId) === $valueId) {
                $selection[$fieldId] = $valueId;
            } else {
                return null;
            }
        }

        ksort($selection);
        ksort($options);

        return ['selection' => $selection, 'optionValueIds' => $options];
    }

    /**
     * Whether a link's card stands as read: it opens on the link's combination, or on a combination agreeing with the
     * link's dimension values; and, when the shop hides the product's out-of-stock units, that unit is in stock. A
     * product link stands as the listing listed it.
     *
     * @param object $row The card, decorated by the site ItemsModel.
     * @param RelatedLink $link Its link.
     *
     * @since  2.3.0
     */
    public static function stands(object $row, RelatedLink $link): bool
    {
        $picked = (array) ($row->preselected['selection'] ?? []);

        if ($link->combinationId <= 0 && $picked === []) {
            return true;
        }

        $active = $row->variant->activeCombination ?? null;

        if ($active === null) {
            return false;
        }

        if ($link->combinationId > 0 && (int) $active->id !== $link->combinationId) {
            return false;
        }

        foreach ($picked as $fieldId => $valueId) {
            if ((int) ($row->variant->selection[$fieldId] ?? 0) !== (int) $valueId) {
                return false;
            }
        }

        $hides = (new StockVisibilityPolicy())->effectiveAction(stored: $row->stock_action ?? null) === StockVisibilityPolicy::ACTION_HIDE_OUT_OF_STOCK;

        return !$hides || !empty($active->in_stock);
    }

    /**
     * The links with one link a product, the first kept: a product is shown once on a product's page.
     *
     * @param list<RelatedLink> $links The links, in their order.
     *
     * @return list<RelatedLink>
     *
     * @since  2.3.0
     */
    private static function onePerProduct(array $links): array
    {
        $kept = [];

        foreach ($links as $link) {
            if ($link instanceof RelatedLink && $link->itemId > 0 && !isset($kept[$link->itemId])) {
                $kept[$link->itemId] = $link;
            }
        }

        return array_values($kept);
    }
}
