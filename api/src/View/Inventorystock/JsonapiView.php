<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Inventorystock;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;
use Joomla\CMS\Uri\Uri;
use Tobscure\JsonApi\Collection;

/**
 * The stock records' answer: a page of records, links.next to the page after.
 *
 * @since  2.3.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields a record carries.
     *
     * @var string[]
     * @since  2.3.0
     */
    protected $fieldsToRenderList = [
        'id',
        'item_id',
        'combination_id',
        'location_id',
        'location_title',
        'quantity',
        'reserved_quantity',
        'available',
    ];

    /**
     * Render one page.
     *
     * @param list<object> $rows The records ({@see \Alfa\Component\Alfa\Administrator\Service\Inventory\StockRecords::page()}).
     * @param int|null $next The key of the next page; null on the last.
     *
     * @since  2.3.0
     */
    public function displayPage(array $rows, ?int $next): string
    {
        $records = [];

        foreach ($rows as $row) {
            $records[] = (object) [
                'id' => (int) $row->id,
                'item_id' => (int) $row->item_id,
                'combination_id' => (int) $row->combination_id,
                'location_id' => (int) $row->location_id,
                'location_title' => (string) ($row->location_title ?? ''),
                'quantity' => (float) $row->quantity,
                'reserved_quantity' => (float) $row->reserved_quantity,
                'available' => (float) $row->available,
            ];
        }

        $current = Uri::getInstance();
        $this->getDocument()->addLink('self', (string) $current);

        if ($next !== null) {
            $query = $current->getQuery(true);
            $page = \is_array($query['page'] ?? null) ? $query['page'] : [];
            unset($page['offset']);
            $page['after'] = (string) $next;
            $query['page'] = $page;
            $nextPage = clone $current;
            $nextPage->setQuery($query);
            $this->getDocument()->addLink('next', $this->queryEncode((string) $nextPage));
        }

        $collection = (new Collection($records, $this->serializer))->fields([$this->type => $this->fieldsToRenderList]);
        $this->getDocument()->setData($collection);

        return $this->getDocument()->render();
    }
}
