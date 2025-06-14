<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

/**
 * Order controller class.
 *
 * @since  1.0.1
 */
class OrderController extends FormController
{
    protected $view_list = 'orders';


    // TODO: Error handling.
    public function saveShipment()
    {
        $this->checkToken();
        $input = Factory::getApplication()->getInput();

        $shipmentID = $input->getInt("id");
        $orderID = $input->getInt("id_order");

        $data = $this->input->post->get('jform', [], 'array');
        $model = $this->getModel();

        if ($model->saveOrderShipment($data)) {
            $this->app->enqueueMessage("The order's shipment was saved successfully.", "success");
        }

        $redirectURL = "index.php?option={$this->option}&view=order&layout=edit_shipment&tmpl=component&id={$data["id"]}&id_order={$orderID}";
        $this->setRedirect(Route::_($redirectURL, false));
    }

    public function savePayment()
    {
        $this->checkToken();
        $input = Factory::getApplication()->getInput();

        $paymentID = $input->getInt("id");
        $orderID = $input->getInt("id_order");

        $data = $this->input->post->get('jform', [], 'array');
        $model = $this->getModel();

        if ($model->saveOrderPayment($data)) {
            $this->app->enqueueMessage("The order's payment was saved successfully.", "success");
        }

        $redirectURL = "index.php?option={$this->option}&view=order&layout=edit_payment&tmpl=component&id={$data["id"]}&id_order={$orderID}";
        $this->setRedirect(Route::_($redirectURL, false));
    }

    public function deleteShipment()
    {
        $this->checkToken();
        $input = Factory::getApplication()->getInput();

        $shipmentID = $input->getInt("id");
        $orderID = $input->getInt("id_order");

        //        $data = $this->input->post->get('jform', [], 'array');
        $model = $this->getModel();

        if ($model->deleteOrderShipment($shipmentID, $orderID)) {
            $this->app->enqueueMessage("The shipment was deleted successfully.", "success");
        }

        $redirectURL = "index.php?option={$this->option}&view=order&layout=edit_shipment&tmpl=component&id=0&id_order={$orderID}";
        $this->setRedirect(Route::_($redirectURL, false));
    }

    public function deletePayment()
    {
        $this->checkToken();
        $input = Factory::getApplication()->getInput();

        $paymentID = $input->getInt("id");
        $orderID = $input->getInt("id_order");

        //        $data = $this->input->post->get('jform', [], 'array');
        $model = $this->getModel();

        if ($model->deleteOrderPayment($paymentID, $orderID)) {
            $this->app->enqueueMessage("The payment was deleted successfully.", "success");
        }

        $redirectURL = "index.php?option={$this->option}&view=order&layout=edit_shipment&tmpl=component&id=0&id_order={$orderID}";
        $this->setRedirect(Route::_($redirectURL, false));
    }


}
