<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuIndex;
use Alfa\Component\Alfa\Administrator\Service\Inventory\StockRecords;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Alfa\Component\Alfa\Api\View\Inventorystock\JsonApiView;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;

/**
 * GET v1/alfa/inventory/stock: the stock records, one a unit and location, with the quantity open orders reserve and
 * what is left there ({@see StockRecords}). Read only; stock changes through POST v1/alfa/inventory/adjustments.
 *
 * @since  2.3.0
 */
class InventorystockController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the answer.
     *
     * @var string
     * @since  2.3.0
     */
    protected $contentType = 'inventorystock';

    /**
     * The default view.
     *
     * @var string
     * @since  2.3.0
     */
    protected $default_view = 'inventorystock';

    /**
     * Stock is the products' own: reading it asks alfa.items.view.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * A page of stock records in id order: filter[item_id], filter[combination_id] (0 the product's own), filter[sku]
     * (the unit it names), filter[location_id]; page[limit] 1 to 1,000 (100 by default), page[after] the id of the last
     * record of the page before (links.next carries it).
     *
     * @return static
     *
     * @throws Save When a filter or a page key cannot be read (400), or the SKU names no unit (its refusal's status).
     *
     * @since  2.3.0
     */
    public function displayList()
    {
        $page = $this->input->get('page', [], 'array');
        $filter = $this->input->get('filter', [], 'array');
        $limit = StockRecords::PAGE_DEFAULT;
        $after = 0;
        $itemId = 0;
        $combinationId = null;
        $locationId = 0;
        $whole = static fn (mixed $value, int $min): ?int => (\is_string($value) && preg_match('/^[0-9]{1,10}$/', $value) === 1) && (int) $value >= $min ? (int) $value : null;

        if (\array_key_exists('limit', $page)) {
            $limit = $whole(RequestScalar::member($page, 'limit', 'STRING', 'page'), 1) ?? 0;

            if ($limit < 1 || $limit > StockRecords::PAGE_MAX) {
                throw new Save('page[limit] must be a whole number from 1 to ' . StockRecords::PAGE_MAX . '.', 400);
            }
        }

        if (\array_key_exists('after', $page)) {
            $after = $whole(RequestScalar::member($page, 'after', 'STRING', 'page'), 1)
                ?? throw new Save('page[after] must be the id of a stock record, as links.next carries it.', 400);
        }

        foreach (['item_id' => 1, 'combination_id' => 0, 'location_id' => 1] as $key => $min) {
            if (\array_key_exists($key, $filter)) {
                $value = $whole(RequestScalar::member($filter, $key, 'STRING', 'filter'), $min)
                    ?? throw new Save('filter[' . $key . '] must be a whole number' . ($min === 0 ? ', 0 for the product\'s own records' : ' above 0') . '.', 400);

                match ($key) {
                    'item_id' => $itemId = $value,
                    'combination_id' => $combinationId = $value,
                    default => $locationId = $value,
                };
            }
        }

        if (\array_key_exists('sku', $filter)) {
            $unit = SkuIndex::unit(sku: (string) RequestScalar::member($filter, 'sku', 'STRING', 'filter'));

            if ($unit instanceof Refusal) {
                throw new Save('filter[sku]: ' . $unit->sentence, $unit->status === Refusal::INVALID ? 400 : $unit->status);
            }

            if (($itemId > 0 && $itemId !== $unit->itemId) || ($combinationId !== null && $combinationId !== $unit->combinationId)) {
                throw new Save('filter[sku] names another unit than filter[item_id] and filter[combination_id]; send one or the other.', 400);
            }

            $itemId = $unit->itemId;
            $combinationId = $unit->combinationId;
        }

        $result = StockRecords::page(itemId: $itemId, combinationId: $combinationId, locationId: $locationId, limit: $limit, after: $after);
        $view = $this->getView($this->default_view, $this->app->getDocument()->getType(), '', ['base_path' => $this->basePath, 'layout' => 'default', 'contentType' => $this->contentType]);

        if (!$view instanceof JsonApiView) {
            throw new Save('The answer could not be written.', 500);
        }

        $view->setDocument($this->app->getDocument());
        $view->displayPage(rows: $result['rows'], next: $result['next']);

        return $this;
    }
}
