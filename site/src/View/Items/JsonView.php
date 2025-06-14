<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Items;

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonView as BaseJsonView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.1
 */
class JsonView extends BaseJsonView
{
    protected $items;

    protected $pagination;

    protected $state;

    protected $params;

    /**
     * Display the view
     *
     * @param   string  $tpl  Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        // Get the search term from the request
        $searchTerm = $app->input->getString('filter[search]', '');

        $this->state = $this->get('State');
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->params = $app->getParams('com_alfa');
        //		$this->filterForm = $this->get('FilterForm');
        //		$this->activeFilters = $this->get('ActiveFilters');

        // Check for errors.
        if (count($errors = $this->get('Errors'))) {
            throw new \Exception(implode("\n", $errors));
        }

        //        $this->app->enqueueMessage('minima 1');
        $response = new JsonResponse($this->items, 'Items fetched succesufully', false);
        echo $response;

        $app->close();


        //        $this->sendJsonResponse($this->items);

        // Output JSON
        //        $this->response->setBody(json_encode($data));
        //        $this->response->setHeader('Content-Type', 'application/json');
        //		parent::display($tpl);
    }


    /**
     * Send JSON response
     *
     * @param   mixed  $data  The data to send as JSON
     *
     * @return  void
     */
    //    protected function sendJsonResponse($data)
    //    {
    //        $app = Factory::getApplication();
    //        $app->setHeader('Content-Type', 'application/json', true);
    //
    //        echo json_encode($data);
    //        $app->close();
    //    }

}
