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
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Cart\CartSnapshot;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use InvalidArgumentException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use stdClass;
use Throwable;

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

    /**
     * The order-completed page's order, the one read-only value its template reads (2.2.0, design 2.17); null when the
     * order is not available to this visitor.
     *
     * @since  2.2.0
     */
    protected ?OrderConfirmation $confirmation = null;

    /**
     * Whether the order's gateway of record cannot be loaded now: the order pages show the neutral line and change
     * nothing (2.2.0, design 2.15.5).
     *
     * @since  2.2.0
     */
    protected bool $providerUnavailable = false;

    /**
     * The process page when the gateway of record cannot be loaded (2.2.0, UX-72): the number the customer quotes, and
     * the process address, which asks the gateway again (Try again). Empty on every other page.
     *
     * @since  2.2.0
     */
    protected string $orderNumber = '';

    /** @since 2.2.0 */
    protected string $retryUrl = '';

    /**
     * The order-not-available page's Log in address for a visitor not logged in, back to this address (2.2.0, UX-57);
     * empty for a logged-in customer.
     *
     * @since  2.2.0
     */
    protected string $loginUrl = '';

    /**
     * The order-completed page's print view (2.2.0, UX-81), Joomla's own print pattern: the same keyed address with
     * tmpl=component and print=1 shows the order alone, and its root is marked for the page's script to print it.
     *
     * @since  2.2.0
     */
    protected bool $print = false;

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

        // THE ORDER-COMPLETED PAGE (2.2.0, design 2.17): an order read by the site read service, never the cart, its
        // form or the checkout's state.
        if ($this->_layout == 'default_order_completed') {
            $this->displayOrderCompleted($tpl);

            return;
        }

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

            // THE EMPTY PAGE STILL LISTENS. Its layout is also the JSON partial the
            // cart tasks answer with, where an asset call would throw, so the view
            // declares the script that reveals the layout's notice when another
            // surface changes the cart, and the stylesheet that styles the notice
            // as on a page emptied in place, with the tokens it reads.
            $this->getDocument()->getWebAssetManager()->useStyle('com_alfa.tokens')->useStyle('com_alfa.cart');
            $this->getDocument()->getWebAssetManager()->useScript('com_alfa.cart');
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

        if ($this->_layout == 'default_order_process') {
            $orderId = $app->getUserState('com_alfa.order_id');

            if ($orderId == null) {
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_SESSION_ENDED'), 'message');
                // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
                $app->redirect(Uri::root());
            }

            $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

            $orderData = $ordersModel->getItem($orderId);

            if ($orderData == null) {
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'), 'warning');
                // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
                $app->redirect(Uri::root());
            }

            // Always present so the layout templates can safely check it.
            $this->event = new stdClass();

            // THE GATEWAY OF RECORD (2.2.0, design 2.15.5): the order's own snapshot of the plugin
            // that took its payment, never the live method row, which staff may have edited.
            $selectedPayment = $orderData->selected_payment ?? null;
            $paymentType = (string) ($orderData->payment_method_type ?? '');

            $paymentEvent = new PaymentsOrderProcessViewEvent('onOrderProcessView', [
                'subject' => $orderData,
                'method' => $selectedPayment,
            ]);

            if ($paymentType !== '' && $this->askGatewayOfRecord($paymentType, $paymentEvent) !== 'asked') {
                // The gateway of record cannot be loaded, or its handler failed: the page says so in
                // neutral words and changes nothing; it never moves on to the completed page for it,
                // which would read as a payment taken (2.2.0, s96).
                $this->providerUnavailable = true;
                $this->event->onOrderProcessView = $paymentEvent;
                // Never a dead end (2.2.0, UX-72): the number the customer quotes, and this same address, which asks
                // the gateway again.
                $this->orderNumber = EmailService::orderNumber(order: $orderData);
                $this->retryUrl = Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process');
                $this->_prepareDocument();

                parent::display($tpl);

                return;
            }

            if ($paymentEvent->hasRedirect()) {
                $this->app->redirect(
                    $paymentEvent->getRedirectUrl(),
                    $paymentEvent->getRedirectCode() ?? 303,
                );

                return;
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

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Ask the order's gateway of record for its page (2.2.0, mode B): its ambient subscriptions engaged, the view event
     * delivered to its declared handler, the layout owner defaulted to it. A gateway with no handler for the page is
     * asked nothing. Any Throwable leaving the handler is its failure: logged, and the caller renders no plugin block
     * (the process page shows the neutral line and stays).
     *
     * @param string $paymentType The order's snapshot of the gateway's plugin element.
     * @param PaymentsOrderProcessViewEvent|PaymentsOrderCompleteViewEvent $event The view event.
     *
     * @return string 'asked'; 'unavailable' when the gateway of record is disabled, missing or cannot be loaded;
     *                'failed' when its handler threw.
     *
     * @since  2.2.0
     */
    private function askGatewayOfRecord(string $paymentType, PaymentsOrderProcessViewEvent|PaymentsOrderCompleteViewEvent $event): string
    {
        $plugins = AlfaPluginHelper::get();

        try {
            $id = PluginId::from('alfa-payments', $paymentType);
            $gateway = $plugins->bootSingle($id);
        } catch (PluginUnavailable|InvalidArgumentException $e) {
            Log::add('Order page: the gateway of record cannot be loaded (' . $e->getMessage() . '); the page shows the neutral line.', Log::WARNING, 'com_alfa.payments');

            return 'unavailable';
        }

        $plugins->importSingle($id);

        if ($plugins->declares($gateway, $event->getName())) {
            try {
                $plugins->dispatchTo($gateway, $event);
            } catch (Throwable $e) {
                Log::add('Order page: ' . $id . ' failed in ' . $event->getName() . ' (' . $e::class . ': ' . $e->getMessage() . '); the page renders without it.', Log::ERROR, 'com_alfa.payments');

                return 'failed';
            }
        }

        if (empty($event->getLayoutPluginName())) {
            $event->setLayoutPluginName($paymentType);
        }

        if (empty($event->getLayoutPluginType())) {
            $event->setLayoutPluginType('alfa-payments');
        }

        return 'asked';
    }

    /**
     * The order-completed page (2.2.0, design 2.17): two addresses, one page.
     *
     * The ARRIVAL address (layout=default_order_completed, no id and no key) is where every gateway and the process
     * page land the buyer: it reads the order the checkout completed from the session, clears it (the page's one
     * write) and answers 303 to the KEYED address, which carries the order's id and key and renders the order on every
     * request: it writes nothing, so a reload, the back button or a second tab shows the same page. The order is read
     * through the model by the site read service; access is the key, and for a customer account's order that customer
     * logged in as well.
     *
     * @param string|null $tpl Template name.
     *
     * @throws Exception
     * @since  2.2.0
     */
    private function displayOrderCompleted(?string $tpl): void
    {
        $app = $this->app;
        $input = $app->getInput();

        // THE RESPONSE'S PROTECTIONS, on the redirect and on every keyed answer: the key is in the address, so the
        // answer is never stored by a browser or a proxy, never indexed, and the address never leaves the site in a
        // Referer. An uncachable response is answered by Joomla with its own Cache-Control (no-store, no-cache,
        // must-revalidate), sent last whatever a view sets. Joomla's page cache stores a guest's GET by its address and
        // reads no header, so this request answers its exclusion event itself (asked after the render, before anything
        // is stored).
        $app->allowCache(false);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Referrer-Policy', 'same-origin', true);
        AlfaPluginHelper::get()->listen('onPageCacheIsExcluded', static function ($event): void {
            $event->addResult(true);
        });

        if (!$input->exists('id') && !$input->exists('key')) {
            $this->arriveAtCompletedOrder($tpl);

            return;
        }

        // The address's two values, read raw and judged here (A-C16-7): each one plain value, the id a positive whole
        // number as the arrival writes it. Anything else (an array such as id[]=, a malformed number) is no order of
        // this visitor's and gets the page's own answer, never a thrown error page.
        $id = $input->get('id', null, 'raw');
        $key = $input->get('key', null, 'raw');

        if (!self::isPlain($id) || !preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) || !self::isPlain($key)) {
            $this->displayOrderNotAvailable($tpl);

            return;
        }

        try {
            $this->confirmation = $this->getModel()->getCompletedOrder(id: (int) $id, key: (string) $key);
        } catch (NotAllowed) {
            // A customer account's order: its customer logs in and comes back to this address. The page's own words,
            // never an exception's.
            $app->enqueueMessage(Text::_('COM_ALFA_ORDER_COMPLETED_LOGIN_REQUIRED'), 'notice');
            $app->redirect(Route::_(
                'index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()),
                false,
            ));

            return;
        }

        if ($this->confirmation === null) {
            $this->displayOrderNotAvailable($tpl);

            return;
        }

        // THE GATEWAY'S BLOCK (mode B, design 2.15.5): the completion view goes to the order's gateway of record alone,
        // the plugin the order recorded, with the order's own row as its subject. A gateway that cannot be loaded or
        // whose handler throws leaves its block out, and the page says so in neutral words: never a word of payment
        // the order does not hold.
        $this->event = new stdClass();
        $paymentEvent = new PaymentsOrderCompleteViewEvent('onOrderCompleteView', [
            'subject' => $this->confirmation->gatewaySubject(),
            'method' => $this->confirmation->gatewayMethod(),
        ]);

        if ($this->confirmation->gateway !== '') {
            if ($this->askGatewayOfRecord($this->confirmation->gateway, $paymentEvent) !== 'asked') {
                $this->providerUnavailable = true;
            } elseif ($paymentEvent->hasRedirect()) {
                // The gateway's own choice, as before.
                $app->redirect($paymentEvent->getRedirectUrl(), $paymentEvent->getRedirectCode() ?? 303);

                return;
            }
        }

        $this->event->onOrderCompleteView = $paymentEvent;

        // PRINT, JOOMLA'S WAY (2.2.0, UX-81): the same keyed address with tmpl=component and print=1 is the print view,
        // which shows the order alone; the page's script opens it from the address it was asked by, so the key is
        // never written into the page.
        $this->print = RequestScalar::bool($input, 'print', false);

        $this->getDocument()->setMetaData('robots', 'noindex, nofollow');
        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Whether a value read raw from the request is one plain value: a string or a whole number, never an array, an
     * object or a boolean.
     *
     * @param mixed $value The value as the input holds it.
     *
     * @since  2.2.0
     */
    private static function isPlain(mixed $value): bool
    {
        return \is_string($value) || \is_int($value);
    }

    /**
     * The arrival address: the session's order, cleared once, then 303 to its keyed address. The query a gateway added
     * to the arrival address (a result flag its completion view reads) goes along, so no gateway changes.
     *
     * @param string|null $tpl Template name.
     *
     * @throws Exception
     * @since  2.2.0
     */
    private function arriveAtCompletedOrder(?string $tpl): void
    {
        $app = $this->app;
        $orderId = (int) $app->getUserState('com_alfa.order_id');

        if ($orderId <= 0) {
            $app->enqueueMessage(Text::_('COM_ALFA_ORDER_SESSION_ENDED'), 'message');
            // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
            $app->redirect(Uri::root());

            return;
        }

        // Every reader of the session's order id has run by now (the gateway's return through PaymentController,
        // another gateway from its own payload, retry links carrying the id in their address). Cleared here, once,
        // so a stale id never re-enters the process page.
        $app->setUserState('com_alfa.order_id', null);

        $key = $this->getModel()->getCompletedOrderKey(id: $orderId);

        if ($key === '') {
            $this->displayOrderNotAvailable($tpl);

            return;
        }

        $query = array_diff_key(Uri::getInstance()->getQuery(true), array_flip(['option', 'view', 'layout', 'id', 'key', 'task']));
        $address = 'index.php?' . http_build_query(['option' => 'com_alfa', 'view' => 'cart', 'layout' => 'default_order_completed', 'id' => $orderId, 'key' => $key] + $query);

        $app->redirect(Route::_($address, false), 303);
    }

    /**
     * The answer for an order this visitor may not see: status 404 and the page's one line, nothing about the order.
     * Rendered by the page itself, not thrown: a thrown error page is stored by Joomla's page cache under the address,
     * and this request has excluded itself from it.
     *
     * @param string|null $tpl Template name.
     *
     * @throws Exception
     * @since  2.2.0
     */
    private function displayOrderNotAvailable(?string $tpl): void
    {
        $this->confirmation = null;

        // A customer's order shows only to that customer logged in: a visitor not logged in is offered the login, back
        // to this address (2.2.0, UX-57), as the keyed page sends one there itself.
        if ($this->app->getIdentity()->guest) {
            $this->loginUrl = Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()));
        }

        $this->app->setHeader('status', 404, true);
        $this->getDocument()->setMetaData('robots', 'noindex, nofollow');
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
