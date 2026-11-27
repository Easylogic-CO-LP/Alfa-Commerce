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
use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The storefront catalogue controller: READ ONLY.
 *
 * Served by the SITE models, so it answers the storefront's question ("what
 * may this visitor buy, at what price?") rather than the back office's ("what
 * rows exist?"). Only published, in-window, access-allowed products appear,
 * and a product set to hide from listings while it has no salable stock
 * (stock_action, StockVisibilityPolicy) is left out of the list until it has.
 *
 * Never gains write routes: the site models have no getForm()/validate()/
 * save(), and back-office editing already lives at v1/alfa/items.
 *
 * @since  2.0.0
 */
class CatalogitemsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  2.0.0
     */
    protected $contentType = 'catalogitems';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  2.0.0
     */
    protected $default_view = 'catalogitems';

    /**
     * Gate this tree on the catalogue's own view permission.
     *
     *
     * @since  2.0.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * Catalogue list view amended to add filtering of data. A page holds at most
     * {@see HasApiGuards::LIST_PAGE_MOST} products, each priced ({@see HasApiGuards::boundListPage()}).
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $this->boundListPage();

        $apiFilterInfo = $this->input->get('filter', [], 'array');

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', RequestScalar::member($apiFilterInfo, 'search', 'STRING', 'filter'));
        }

        if (\array_key_exists('category', $apiFilterInfo)) {
            $this->modelState->set('filter.category', [RequestScalar::member($apiFilterInfo, 'category', 'INT', 'filter')]);
        }

        if (\array_key_exists('manufacturer', $apiFilterInfo)) {
            $this->modelState->set('filter.manufacturer', [RequestScalar::member($apiFilterInfo, 'manufacturer', 'INT', 'filter')]);
        }

        // New arrivals: filter[new]=1 lists only the products the shop counts as new (NewProducts).
        if (\array_key_exists('new', $apiFilterInfo)) {
            $this->modelState->set('filter.new', (int) (bool) RequestScalar::member($apiFilterInfo, 'new', 'INT', 'filter'));
        }

        // The products that tick any of the labels: filter[label][]=3&filter[label][]=5, or one id.
        if (\array_key_exists('label', $apiFilterInfo)) {
            $this->modelState->set('filter.label', array_values(array_filter(array_map('intval', (array) $apiFilterInfo['label']))));
        }

        // The storefront never shows anything but published products.
        $this->modelState->set('filter.state', '1');

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', RequestScalar::member($apiListInfo, 'ordering', 'STRING', 'list'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', RequestScalar::member($apiListInfo, 'direction', 'STRING', 'list'));
        }

        return parent::displayList();
    }
}
