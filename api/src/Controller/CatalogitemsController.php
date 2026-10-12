<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The storefront catalogue controller: READ ONLY.
 *
 * Served by the SITE models, so it answers the storefront's question ("what
 * may this visitor buy, at what price?") rather than the back office's ("what
 * rows exist?"). Only published, in-window, access-allowed products appear.
 *
 * Never gains write routes: the site models have no getForm()/validate()/
 * save(), and back-office editing already lives at v1/alfa/items.
 *
 * @since  1.9.0
 */
class CatalogitemsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.9.0
     */
    protected $contentType = 'catalogitems';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.9.0
     */
    protected $default_view = 'catalogitems';

    /**
     * Gate this tree on the catalogue's own view permission.
     *
     *
     * @since  1.9.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * Catalogue list view amended to add filtering of data.
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  1.9.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $filter = InputFilter::getInstance();

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', $filter->clean($apiFilterInfo['search'], 'STRING'));
        }

        if (\array_key_exists('category', $apiFilterInfo)) {
            $this->modelState->set('filter.category', [$filter->clean($apiFilterInfo['category'], 'INT')]);
        }

        if (\array_key_exists('manufacturer', $apiFilterInfo)) {
            $this->modelState->set('filter.manufacturer', [$filter->clean($apiFilterInfo['manufacturer'], 'INT')]);
        }

        // The storefront never shows anything but published products.
        $this->modelState->set('filter.state', '1');

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', $filter->clean($apiListInfo['ordering'], 'STRING'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', $filter->clean($apiListInfo['direction'], 'STRING'));
        }

        return parent::displayList();
    }
}
