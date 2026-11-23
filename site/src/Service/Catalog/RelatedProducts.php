<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedItems;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Joomla\CMS\Factory;

/**
 * The related products a visitor sees on a product's page: the product's links in the order staff set, read
 * through the site ItemsModel as the listing reads products (published, within their publish window, what the visitor
 * may see, priced from the price index for the visitor's currency, place and groups), with no search, so no search
 * engine is asked and no result set is announced. A product the visitor may not see is skipped and the next one takes
 * its place. The links are read in pages of twice the count (at least 8) and never more than {@see self::READ_MAX}, so
 * a product with many links never loads them all to show a few; a count of 0 reads nothing.
 *
 * @since  2.2.2
 */
final class RelatedProducts
{
    /**
     * How many of a product's links are read at most to fill its related products.
     *
     * @since  2.2.2
     */
    public const READ_MAX = 48;

    /**
     * The most a page shows.
     *
     * @since  2.2.2
     */
    public const SHOWN_MAX = 12;

    /**
     * The products to show, decorated by the site ItemsModel, in their order.
     *
     * @param int $itemId The product whose page shows them.
     * @param int $count How many at most (0 for none, at most {@see self::SHOWN_MAX}).
     *
     * @return list<object>
     *
     * @since  2.2.2
     */
    public static function visible(int $itemId, int $count): array
    {
        $count = min(self::SHOWN_MAX, $count);

        if ($itemId <= 0 || $count <= 0) {
            return [];
        }

        $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
        $db = Factory::getContainer()->get('DatabaseDriver');
        $page = max(8, $count * 2);
        $shown = [];

        for ($offset = 0; $offset < self::READ_MAX && \count($shown) < $count; $offset += $page) {
            $size = min($page, self::READ_MAX - $offset);
            $ids = array_map(static fn ($link): int => $link->itemId, RelatedItems::links(db: $db, itemId: $itemId, limit: $size, offset: $offset));

            if ($ids === []) {
                break;
            }

            /** @var ItemsModel $model */
            $model = $factory->createModel('Items', 'Site', ['ignore_request' => true]);
            // No category of the page's: each card is drawn from its own levels, its default category's and the
            // Options, its link and its reload address under its default category, however the visitor reached the
            // product page (the model reads category_id from the request otherwise), as the items module reads.
            $model->setCategoryContext(categoryId: 0);
            // Under ignore_request populateState() never runs: the state is what is set here.
            $model->setState('filter.id', $ids);
            $model->setState('list.limit', 0);
            $model->setState('list.start', 0);
            $model->setState('list.ordering', 'a.id');
            $model->setState('list.direction', 'ASC');
            $model->setState('list.fullordering', 'a.id ASC');
            // The cards' reload URLs carry the context, so a card is drawn again from the levels it was drawn from.
            $model->setState('card.context', 'related');
            $byId = [];

            foreach ((array) $model->getItems() as $row) {
                $byId[(int) $row->id] = $row;
            }

            foreach ($ids as $id) {
                if (isset($byId[$id]) && \count($shown) < $count) {
                    $shown[] = $byId[$id];
                }
            }

            if (\count($ids) < $size) {
                break;
            }
        }

        return $shown;
    }
}
