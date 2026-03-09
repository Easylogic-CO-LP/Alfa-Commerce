<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Items;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\JsonView as BaseJsonView;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;

/**
 * JSON View class for a list of Alfa items.
 *
 * @since  1.0.1
 */
class JsonView extends BaseJsonView
{
    /**
     * The items to display
     *
     * @var array
     * @since  1.0.1
     */
    protected $items;

    /**
     * The pagination object
     *
     * @var \Joomla\CMS\Pagination\Pagination
     * @since  1.0.1
     */
    protected $pagination;

    /**
     * The model state
     *
     * @var \Joomla\CMS\Object\CMSObject
     * @since  1.0.1
     */
    protected $state;

    /**
     * The component parameters
     *
     * @var \Joomla\Registry\Registry
     * @since  1.0.1
     */
    protected $params;

    /**
     * The subcategories list
     *
     * @var array
     * @since  1.0.1
     */
    protected $categories;

    /**
     * The current category
     *
     * @var object|null
     * @since  1.0.1
     */
    protected $category;

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @since   1.0.1
     */
    public function display($tpl = null)
    {
        header('Content-Type: application/json; charset=utf-8');

        $app = Factory::getApplication();
        $this->params = $app->getParams('com_alfa');

        $itemsModel = $this->getModel();

        // Get data from model
        $this->items = $itemsModel->getItems();
        $this->pagination = $itemsModel->getPagination();
        $this->state = $itemsModel->getState();
        $this->categories = $itemsModel->getItemsCategories();
        $this->category = $itemsModel->getItemsCategory();

        // Check for errors from the model
        if (count($errors = $this->get('Errors'))) {
            echo new JsonResponse(null, implode(', ', $errors), true);
            $app->close();
        }

        // Build response data
        $data = [
            'items' => $this->items,
            'pagination' => $this->preparePaginationData(),
            'state' => $this->prepareStateData(),
            'categories' => $this->categories,
        ];

        // Add current category if exists
        if (!empty($this->category)) {
            $data['category'] = $this->prepareCategoryData();
        }

        // Send JSON response
        echo new JsonResponse($data, 'Items fetched successfully', false);

        $app->close();
    }

    /**
     * Prepare pagination data
     *
     * @return array
     *
     * @since   1.0.1
     */
    protected function preparePaginationData()
    {
        $paginationData = [
            'total' => (int) $this->pagination->total,
            'limitstart' => (int) $this->pagination->limitstart,
            'limit' => (int) $this->pagination->limit,
            'pagesTotal' => (int) $this->pagination->pagesTotal,
            'pagesCurrent' => (int) $this->pagination->pagesCurrent,
        ];

        // Add pagination links if multiple pages exist
        if ($this->pagination->pagesTotal > 1) {
            $paginationData['links'] = $this->preparePaginationLinks();
        }

        return $paginationData;
    }

    /**
     * Prepare pagination links
     *
     * @return array
     *
     * @since   1.0.1
     */
    protected function preparePaginationLinks()
    {
        $links = [];
        $paginationDataObj = $this->pagination->getData();

        // Previous page link
        if ($this->pagination->pagesCurrent > 1 && !empty($paginationDataObj->previous->link)) {
            $links['previous'] = Route::_($paginationDataObj->previous->link);
        }

        // Next page link
        if ($this->pagination->pagesCurrent < $this->pagination->pagesTotal && !empty($paginationDataObj->next->link)) {
            $links['next'] = Route::_($paginationDataObj->next->link);
        }

        // First page link
        if (!empty($paginationDataObj->start->link)) {
            $links['first'] = Route::_($paginationDataObj->start->link);
        }

        // Last page link
        if (!empty($paginationDataObj->end->link)) {
            $links['end'] = Route::_($paginationDataObj->end->link);
        }

        // Individual page links
        if (!empty($paginationDataObj->pages)) {
            $links['pages'] = [];

            foreach ($paginationDataObj->pages as $page) {
                $links['pages'][] = [
                    'number' => (int) $page->text,
                    'link' => !empty($page->link) ? Route::_($page->link) : null,
                    'active' => (bool) ($page->active ?? false),
                ];
            }
        }

        return $links;
    }

    /**
     * Prepare state data
     *
     * @return array
     *
     * @since   1.0.1
     */
    protected function prepareStateData()
    {
        return [
            'ordering' => $this->state->get('list.ordering'),
            'direction' => $this->state->get('list.direction'),
            'search' => $this->state->get('filter.search', ''),
            'category_id' => (int) $this->state->get('filter.category_id', 0),
        ];
    }

    /**
     * Prepare category data
     *
     * @return array
     *
     * @since   1.0.1
     */
    protected function prepareCategoryData()
    {
        return [
            'id' => (int) $this->category->id,
            'name' => $this->category->name,
            'alias' => $this->category->alias,
            'desc' => $this->category->desc ?? '',
            'parent_id' => (int) ($this->category->parent_id ?? 0),
        ];
    }
}
