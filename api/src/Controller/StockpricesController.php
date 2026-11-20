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
use Alfa\Component\Alfa\Administrator\Service\Catalog\CommerceChange;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockPriceList;
use Alfa\Component\Alfa\Api\View\Stockprices\JsonApiView;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;

/**
 * The light list of stock and prices: GET v1/alfa/stockprices, READ ONLY.
 *
 * One row for each product without combinations and for each combination, published or unpublished, with its codes,
 * its stock figures and its selling price ({@see StockPriceList}), so an integrator keeps stock and prices in step with
 * one read a page instead of reading every product. Pages follow the units' key: page[after]=item_id:combination_id,
 * the key of the last unit of the page before (the answer's links.next); page[limit] up to 1,000 (100 when not named).
 * filter[changed_since] keeps the units of the products whose stock, prices or own fields changed at or after a UTC time.
 * Gated on Items: View, the back office's own permission, so unpublished units are listed to whoever reads it.
 *
 * @since  2.2.2
 */
class StockpricesController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the rows.
     *
     * @var string
     * @since  2.2.2
     */
    protected $contentType = 'stockprices';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  2.2.2
     */
    protected $default_view = 'stockprices';

    /**
     * Gate the list on the products' view permission.
     *
     * @since  2.2.2
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * One page of the list.
     *
     * @return static
     *
     * @throws Save When page[limit], page[after] or filter[changed_since] is not what the list takes (400).
     *
     * @since  2.2.2
     */
    public function displayList()
    {
        $page = $this->input->get('page', [], 'array');
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $limit = StockPriceList::PAGE_DEFAULT;
        $after = [0, 0];
        $changedSince = null;

        if (\array_key_exists('limit', $page)) {
            $limit = (int) RequestScalar::member($page, 'limit', 'INT', 'page');

            if ($limit < 1 || $limit > StockPriceList::PAGE_MAX) {
                throw new Save('page[limit] must be a whole number from 1 to ' . StockPriceList::PAGE_MAX . '.', 400);
            }
        }

        if (\array_key_exists('after', $page)) {
            $after = StockPriceList::key(key: (string) RequestScalar::member($page, 'after', 'STRING', 'page'));

            if ($after === null) {
                throw new Save('page[after] must be the key of a row, item_id:combination_id (links.next of the page before).', 400);
            }
        }

        if (\array_key_exists('changed_since', $apiFilterInfo)) {
            $changedSince = CommerceChange::bound(bound: (string) RequestScalar::member($apiFilterInfo, 'changed_since', 'STRING', 'filter'));

            if ($changedSince === null) {
                throw new Save('filter[changed_since] must be a date and time, 2026-11-20T10:00:00Z (UTC when it states no zone).', 400);
            }
        }

        $result = StockPriceList::page(limit: $limit, afterItem: $after[0], afterCombination: $after[1], changedSince: $changedSince);

        /** @var JsonApiView $view */
        $view = $this->getView($this->default_view, $this->app->getDocument()->getType(), '', ['base_path' => $this->basePath, 'layout' => 'default', 'contentType' => $this->contentType]);
        $view->setDocument($this->app->getDocument());
        $view->displayPage(rows: $result['rows'], next: $result['next'], currency: $result['currency'], priced: $result['priced']);

        return $this;
    }
}
