<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Stockprices;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;
use Joomla\CMS\Uri\Uri;
use stdClass;
use Tobscure\JsonApi\Collection;

/**
 * The light list of stock and prices: a page of units, each with its key as its id (item_id:combination_id), and the
 * link to the next page by key. No total and no last page: the list is walked by key, one statement a page.
 *
 * @since  2.3.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields of a row.
     *
     * @var array
     * @since  2.3.0
     */
    protected $fieldsToRenderList = [
        'id',
        'item_id',
        'combination_id',
        'sku',
        'gtin',
        'state',
        'item_state',
        'manage_stock',
        'available_quantity',
        'stock',
        'price',
        'changed_at',
    ];

    /**
     * Render one page.
     *
     * @param list<stdClass> $rows The units, in key order (StockPriceList::page()).
     * @param string|null $next The key the next page starts after; null on the last page.
     * @param string $currency The currency of the prices.
     * @param bool $priced Whether the prices were read.
     *
     * @return string
     *
     * @since  2.3.0
     */
    public function displayPage(array $rows, ?string $next, string $currency, bool $priced): string
    {
        $items = [];

        foreach ($rows as $row) {
            $tracked = (int) ($row->manage_stock ?? 0) === 1;
            $items[] = (object) [
                'id' => (int) $row->item_id . ':' . (int) $row->combination_id,
                'item_id' => (int) $row->item_id,
                'combination_id' => (int) $row->combination_id,
                'sku' => (string) $row->sku,
                'gtin' => (string) $row->gtin,
                'state' => (int) $row->state,
                'item_state' => (int) $row->item_state,
                'manage_stock' => $row->manage_stock === null ? null : (int) $row->manage_stock,
                'available_quantity' => self::figure(value: $row->available_quantity, tracked: $tracked),
                'stock' => self::figure(value: $row->stock, tracked: $tracked),
                // The figures as the index holds them, exact to the eighth place; none when it has no valid price.
                'price' => $row->gross === null ? null : ['gross' => (string) $row->gross, 'net' => (string) $row->net, 'currency' => $currency],
                'changed_at' => $row->changed_at,
            ];
        }

        $current = Uri::getInstance();
        $this->getDocument()->addLink('self', (string) $current)->addMeta('prices', $priced ? 'read' : 'not prepared');

        if ($next !== null) {
            // The request's own query, its page continued after the last key of this one.
            $query = $current->getQuery(true);
            $page = \is_array($query['page'] ?? null) ? $query['page'] : [];
            unset($page['offset']);
            $page['after'] = $next;
            $query['page'] = $page;
            $nextPage = clone $current;
            $nextPage->setQuery($query);
            $this->getDocument()->addLink('next', $this->queryEncode((string) $nextPage));
        }

        $collection = (new Collection($items, $this->serializer))->fields([$this->type => $this->fieldsToRenderList]);
        $this->getDocument()->setData($collection);

        return $this->getDocument()->render();
    }

    /**
     * A stock figure of a unit as the list gives it: a unit that tracks stock and holds no stock record has none on the
     * shelf, so 0; a unit that does not track stock and holds no figure reads null (stock not tracked), as the
     * product's own read gives its stock. A stored figure is given as stored.
     *
     * @param mixed $value The stored figure (null when the unit holds none).
     * @param bool $tracked Whether the unit tracks stock.
     *
     * @since  2.3.0
     */
    private static function figure(mixed $value, bool $tracked): ?float
    {
        if ($value === null) {
            return $tracked ? 0.0 : null;
        }

        return (float) $value;
    }
}
