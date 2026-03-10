<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentResponseEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

// use \Alfa\Component\Alfa\Site\Helper\CartHelper;

/**
 * Item class.
 *
 * @since  1.6.0
 */
class PaymentController extends BaseController
{
    public function response()
    {
        $app = $this->app;
        //echo "aaa";

        //            exit;

        // echo '<pre>';
        // print_r($app->input->server->get('REQUEST_URI', '', 'STRING'));
        // echo '</pre>';
        // exit;
        //            $app->setUserState('com_alfa.order_id', 1);
        $orderId = $app->getUserState('com_alfa.order_id');

        //            $orderId = 1;

        if ($orderId == null) {
            $app->enqueueMessage('Order ID is not set.', 'error');
            $app->redirect(Route::_('/index.php'));//redirect to home page
        }

        $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

        $orderData = $ordersModel->getItem($orderId);

        if ($orderData == null) {
            $app->enqueueMessage('Order with this order id:' . $orderId . ' not found.', 'error');
            $app->redirect(Route::_('/index.php')); //redirect to home page
        }

        $onResponsePaymentEventName = 'onPaymentResponse';
        $paymentEvent = new PaymentResponseEvent($onResponsePaymentEventName, [
            'subject' => $orderData,
            'method' => $orderData->selected_payment->type,
        ]);

        $app->bootPlugin($orderData->selected_payment->type, 'alfa-payments')->{$onResponsePaymentEventName}($paymentEvent);

        if ($paymentEvent->hasRedirect()) {
            $app->redirect($paymentEvent->getRedirectUrl());
        }
    }
}
