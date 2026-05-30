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
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_SESSION_ENDED'), 'message');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

            $orderData = $ordersModel->getItem($orderId);

            if ($orderData == null) {
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'), 'warning');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            // Always present so the layout templates can safely check it.
            $this->event = new stdClass();

            if ($this->_layout == 'default_order_process') {
                $selectedPayment = $orderData->selected_payment ?? null;
                $paymentType     = $selectedPayment->type ?? '';

                $onProcessPaymentEventName = 'onOrderProcessView';
                $paymentEvent = new PaymentsOrderProcessViewEvent($onProcessPaymentEventName, [
                    'subject' => $orderData,
                    'method'  => $selectedPayment,
                ]);

                // Engage the payment plugin only when the order has one. bootPlugin()
                // returns a DummyPlugin otherwise — neither a SubscriberInterface nor
                // owner of the event method.
                if ($paymentType !== '') {
                    $plugin = $this->app->bootPlugin($paymentType, 'alfa-payments');

                    if ($plugin instanceof \Joomla\Event\SubscriberInterface) {
                        $this->app->getDispatcher()->addSubscriber($plugin);
                    }

                    if (method_exists($plugin, $onProcessPaymentEventName)) {
                        $plugin->{$onProcessPaymentEventName}($paymentEvent);
                    }

                    if (empty($paymentEvent->getLayoutPluginName())) {
                        $paymentEvent->setLayoutPluginName($paymentType);
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
                }

                // No payment, or the plugin produced no layout → show the completed page.
                if (empty($paymentEvent->getLayout())) {
                    $this->app->redirect(
                        Route::_('index.php?option=com_alfa&view=cart&layout=default_order_completed', false),
                        $paymentEvent->getRedirectCode() ?? 303,
                    );

                    return;
                }

                $this->event->onOrderProcessView = $paymentEvent;
            }

            if ($this->_layout == 'default_order_completed') {
                $selectedPayment = $orderData->selected_payment ?? null;
                $paymentType     = $selectedPayment->type ?? '';

                $onOrderCompleteViewEventName = 'onOrderCompleteView';
                $paymentEvent = new PaymentsOrderCompleteViewEvent($onOrderCompleteViewEventName, [
                    'subject' => $orderData,
                    'method'  => $selectedPayment,
                ]);

                // Engage the payment plugin only when the order has one. bootPlugin()
                // returns a DummyPlugin otherwise — neither a SubscriberInterface nor
                // owner of the event method.
                if ($paymentType !== '') {
                    $plugin = $this->app->bootPlugin($paymentType, 'alfa-payments');

                    if ($plugin instanceof \Joomla\Event\SubscriberInterface) {
                        $this->app->getDispatcher()->addSubscriber($plugin);
                    }

                    if (method_exists($plugin, $onOrderCompleteViewEventName)) {
                        $plugin->{$onOrderCompleteViewEventName}($paymentEvent);
                    }

                    if (empty($paymentEvent->getLayoutPluginName())) {
                        $paymentEvent->setLayoutPluginName($paymentType);
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
                }

                // Always set — the template renders the plugin layout unconditionally;
                // PluginLayoutHelper outputs nothing when the layout is empty (e.g. an
                // order with no payment method).
                $this->event->onOrderCompleteView = $paymentEvent;

                // Terminal state: the order is loaded ($orderData) and every consumer
                // of the session order id has already run (gateway return via
                // PaymentController; Revolut resolves from its payload; retry links
                // carry the id in the URL). Clear it so the completion can't be
                // replayed and a stale id can't re-enter the process/payment page.
                $this->app->setUserState('com_alfa.order_id', null);
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
