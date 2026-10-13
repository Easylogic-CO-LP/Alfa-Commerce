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
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Cart\CartSnapshot;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use stdClass;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $state;

    protected $cart;

    /**
     * @var CartSnapshot|null One observation of the cart, for the templates to
     *                        read. Taken after the methods are reconciled and
     *                        the cart-view events have run, so what it holds is
     *                        what the page is about to show.
     * @since  2.0.0
     */
    protected ?CartSnapshot $snapshot = null;

    protected $items;

    protected $app;

    protected $payment_methods;

    protected $form;

    protected $params;

    protected $event;

    protected $savedUserInfo = [];

    protected $pickedUserInfoId = 0;

    protected $newUserInfoPicked = false;

    protected $invoiceDiffers = false;

    protected $pickedBillingInfoId = 0;

    protected $newBillingPicked = false;

    /** @var string Resolved sub-template for the "cart_items" slot */
    protected $cartItemsTpl = 'cart_items';

    /** @var string Resolved sub-template for the "cart_form" slot */
    protected $cartFormTpl = 'cart_form';

    /** @var string Resolved sub-template for the "select_user_info" slot */
    protected $selectUserInfoTpl = 'select_user_info';

    /** @var string Resolved sub-template for the "select_payment" slot */
    protected $selectPaymentTpl = 'select_payment';

    /** @var string Resolved sub-template for the "select_shipment" slot */
    protected $selectShipmentTpl = 'select_shipment';

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        $this->app = $app = Factory::getApplication();
        $user = $app->getIdentity();
        $input = $app->input;

        $model = $this->getModel();

        $this->state = $model->getState();
        $this->params = $app->getParams('com_alfa');
        $this->form = $model->getForm();

        // Cart participates with SLOTS only: its main layout stays untouched
        // because the flow states (default_cart_empty/order_process/...) branch on it.
        $this->cartItemsTpl = SlotResolver::resolve(value: (string) $this->params->get('cart_items_layout', ''), view: 'cart', slot: 'cart_items');
        $this->cartFormTpl = SlotResolver::resolve(value: (string) $this->params->get('cart_form_layout', ''), view: 'cart', slot: 'cart_form');
        $this->selectUserInfoTpl = SlotResolver::resolve(value: (string) $this->params->get('cart_select_user_info_layout', ''), view: 'cart', slot: 'select_user_info');
        $this->selectPaymentTpl = SlotResolver::resolve(value: (string) $this->params->get('cart_select_payment_layout', ''), view: 'cart', slot: 'select_payment');
        $this->selectShipmentTpl = SlotResolver::resolve(value: (string) $this->params->get('cart_select_shipment_layout', ''), view: 'cart', slot: 'select_shipment');

        // User-info picker data; binding happens in the model.
        $this->savedUserInfo = $model->getSavedUserInfo();
        $this->pickedUserInfoId = $model->getPickedUserInfoId();
        $this->newUserInfoPicked = $model->isNewUserInfoPicked();
        $this->invoiceDiffers = $model->isInvoiceDifferent();
        $this->pickedBillingInfoId = $model->getPickedBillingInfoId();
        $this->newBillingPicked = $model->isNewBillingPicked();

        // Check for errors.
        if (count($errors = $this->get('Errors'))) {
            throw new Exception(implode("\n", $errors));
        }

        // ONE LOOK AT THE CART, AND ONLY WHEN THE PAGE IS THE CART. Whether
        // there is anything in it decides the layout, and costs nothing beyond
        // resolving it. The order pages never resolve one: they render an
        // order, and a cart that has just been converted has nothing to say.
        $reading = $this->_layout == 'default' ? CartService::ambient()->openCheckout() : null;

        if ($reading !== null && $reading->isEmpty()) {
            $this->_layout = 'default_cart_empty';
        }

        if ($this->_layout == 'default') {
            // Account-required checkout: a visitor with something to order is
            // sent to sign in (and back here). Placement enforces the same
            // rule, so this is the courteous half, not the gate.
            if ($user->guest && (int) $this->params->get('checkout_guest', 1) === 0) {
                $app->enqueueMessage(Text::_('COM_ALFA_CART_ACCOUNT_REQUIRED'), 'notice');
                $app->redirect(Route::_(
                    'index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()),
                    false,
                ));

                return;
            }

            // THE SETTLED READING, from the same look that decided the layout:
            // where the order is going bound (the place and the postcode the
            // form carries), the chosen methods reconciled against what is
            // offered, each method asked what to show, one reading of all of
            // it. Inside this branch, not after it: taking it asks for the
            // contributions, and that boots the commercial plugins. The order
            // pages do not do that and must not start.
            $this->snapshot = $reading->settle($model->getDeliveryPlaceId());

            // THE PANELS' CODE, DECLARED WHILE THE PAGE CAN STILL TAKE IT. The
            // AJAX re-renders bring markup only, so every method plugin is
            // asked here, once, whether or not its method is offered yet.
            $reading->declareAssets($this->getDocument());
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
                $paymentType = $selectedPayment->type ?? '';

                $onProcessPaymentEventName = 'onOrderProcessView';
                $paymentEvent = new PaymentsOrderProcessViewEvent($onProcessPaymentEventName, [
                    'subject' => $orderData,
                    'method' => $selectedPayment,
                ]);

                // Engage the payment plugin only when the order has one. bootPlugin()
                // returns a DummyPlugin otherwise: neither a SubscriberInterface nor
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

                // No payment, or the plugin produced no layout -> show the completed page.
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
                $paymentType = $selectedPayment->type ?? '';

                $onOrderCompleteViewEventName = 'onOrderCompleteView';
                $paymentEvent = new PaymentsOrderCompleteViewEvent($onOrderCompleteViewEventName, [
                    'subject' => $orderData,
                    'method' => $selectedPayment,
                ]);

                // Engage the payment plugin only when the order has one. bootPlugin()
                // returns a DummyPlugin otherwise: neither a SubscriberInterface nor
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

                // Always set: the template renders the plugin layout unconditionally;
                // Layout::render outputs nothing when the layout is empty (e.g. an
                // order with no payment method).
                $this->event->onOrderCompleteView = $paymentEvent;

                // Purchased downloads: only a NOTICE here ("links were emailed").
                // Composing real URLs would rotate the tokens (this is a separate
                // request from the grant, so the raw tokens are gone) and kill
                // the links the customer just received by email.
                $this->hasDownloads = false;

                foreach (Entitlements::getForOrder(orderId: (int) $orderData->id) as $entitlement) {
                    if (Entitlements::isServable(status: (int) $entitlement->status)) {
                        $this->hasDownloads = true;
                        break;
                    }
                }

                // Where the confirmation went: shown so a guest sees a typo at once.
                $this->customerEmail = OrderIdentity::notificationEmail($orderData);

                // Terminal state: the order is loaded ($orderData) and every consumer
                // of the session order id has already run (gateway return via
                // PaymentController; another gateway resolves from its payload; retry links
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
     * @since  1.0.0
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
