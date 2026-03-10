<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Cart;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\OrderCompleteViewEvent as PaymentsOrderCompleteViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderProcessViewEvent as PaymentsOrderProcessViewEvent;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use stdClass;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    protected $state;

    protected $cart;

    protected $items;

    protected $app;

    protected $payment_methods;

    protected $form;

    protected $params;

    protected $event;

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $this->app = $app = Factory::getApplication();
        $user = $app->getIdentity();
        $input = $app->input;

        $model = $this->getModel();

        $this->state = $model->getState();
        $this->cart = $model->getItem();
        // $this->items   = $this->get('Items');
        $this->params = $app->getParams('com_alfa');
        //        $model = new CartModel();
        $this->form = $model->getForm();

        // Check for errors.
        if (count($errors = $this->get('Errors'))) {
            throw new Exception(implode("\n", $errors));
        }

        if ((!$this->cart->getData() || !is_array($this->cart->getData()->items) || !count($this->cart->getData()->items))
            && $this->_layout == 'default') {
            $this->_layout = 'default_cart_empty';
        }

        if ($this->_layout == 'default') {
            // Load selected payment method onCartView event.
            $this->cart->addEventsToPayments();

            // Load selected shipment method onCartView event.
            $this->cart->addEventsToShipments();
        }

        if ($this->_layout == 'default_order_process' ||
            $this->_layout == 'default_order_completed') {
            $orderId = $app->getUserState('com_alfa.order_id');

            if ($orderId == null) {
                $app->enqueueMessage('Order ID is not set.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

            $orderData = $ordersModel->getItem($orderId);

            if ($orderData == null) {
                $app->enqueueMessage('Order with this order id:' . $orderId . ' not found.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            if ($this->_layout == 'default_order_process') {
                $onProcessPaymentEventName = 'onOrderProcessView';
                $selectedPayment = $orderData->selected_payment;
                $paymentEvent = new PaymentsOrderProcessViewEvent($onProcessPaymentEventName, [
                    'subject' => $orderData,
                    'method' => $selectedPayment,
                ]);

                $this->app->bootPlugin($selectedPayment->type, 'alfa-payments')->{$onProcessPaymentEventName}($paymentEvent);

                if ($paymentEvent->hasRedirect()) {
                    $app->redirect($paymentEvent->getRedirectUrl());
                }

                if (empty($paymentEvent->getLayoutPluginName())) {
                    $paymentEvent->setLayoutPluginName($selectedPayment->type);
                }
                if (empty($paymentEvent->getLayoutPluginType())) {
                    $paymentEvent->setLayoutPluginType('alfa-payments');
                }
                if ($paymentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $paymentEvent->getRedirectUrl(),
                        $paymentEvent->getRedirectCode() ?? 303,
                    );

                    return;
                }

                if (!$paymentEvent->hasRedirect() && empty($paymentEvent->getLayout())) {
                    // Plugin did not set a layout or redirect, fallback redirect to complete page
                    $this->app->redirect(
                        'index.php?option=com_alfa&view=cart&layout=default_order_completed',
                        $paymentEvent->getRedirectCode() ?? 303,
                    );

                    return;
                }

                $this->event = new stdClass();
                $this->event->onOrderProcessView = $paymentEvent;
            }

            if ($this->_layout == 'default_order_completed') {
                $onOrderCompleteViewEventName = 'onOrderCompleteView';

                $paymentEvent = new PaymentsOrderCompleteViewEvent($onOrderCompleteViewEventName, [
                    'subject' => $orderData,
                    'method' => $orderData->selected_payment,
                ]);

                $this->app->bootPlugin($orderData->selected_payment->type, 'alfa-payments')->{$onOrderCompleteViewEventName}($paymentEvent);

                if (empty($paymentEvent->getLayoutPluginName())) {
                    $paymentEvent->setLayoutPluginName($orderData->selected_payment->type);
                }
                if (empty($paymentEvent->getLayoutPluginType())) {
                    $paymentEvent->setLayoutPluginType('alfa-payments');
                }
                if ($paymentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $paymentEvent->getRedirectUrl(),
                        $paymentEvent->getRedirectCode() ?? 303,
                    );

                    return;
                }

                $this->event = new stdClass();
                $this->event->onOrderCompleteView = $paymentEvent;
            }
        }

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Prepares the document
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _prepareDocument()
    {
        $app = Factory::getApplication();
        $menus = $app->getMenu();
        $title = null;

        // Because the application sets a default page title,
        // We need to get it from the menu manufacturer itself
        $menu = $menus->getActive();

        if ($menu) {
            $this->params->def('page_heading', $this->params->get('page_title', $menu->title));
        } else {
            $this->params->def('page_heading', Text::_('COM_ALFA_DEFAULT_PAGE_TITLE'));
        }
    }
}
