<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\FormErrors;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Cart\CartSnapshot;
use Alfa\Component\Alfa\Site\Service\CartRules\CouponOutcome;
use Alfa\Component\Alfa\Site\Service\Checkout\OrderPlacement;
use Exception;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;
use Throwable;

/**
 * Item class.
 *
 * @since  1.0.0
 */
class CartController extends FormController implements UserFactoryAwareInterface
{
    use UserFactoryAwareTrait;

    /**
     * The cart is changed through this controller's own tasks, never through FormController's.
     *
     * The inherited add, save and reload tasks would be answered with Joomla's core.create;
     * refusing here keeps the storefront independent of admin rights.
     *
     * @param array $data The data for the new record.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowAdd($data = [])
    {
        return false;
    }

    /**
     * Closes the inherited edit, save and reload tasks, which Joomla would grant on core.edit.
     *
     * @param array $data The record data.
     * @param string $key The record key name.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        return false;
    }

    /**
     * Joomla's generic batch, reachable as a task only because it is public.
     *
     * @param mixed $model Unused.
     *
     * @return bool
     *
     * @throws NotAllowed Always.
     *
     * @since  2.0.0
     */
    public function batch($model = null)
    {
        throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }

    /**
     * Method to get a model object, loading it if required.
     *
     * @param string $name The model name. Optional.
     * @param string $prefix The class prefix. Optional.
     * @param array $config Configuration array for model. Optional.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel The model.
     *
     * @since  1.0.0
     */
    public function getModel($name = 'cart', $prefix = '', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => false]);
    }

    public function recalculate()
    {
        $errorOccurred = false;

        $input = $this->app->input;

        $failMessage = Text::_('COM_ALFA_CART_RECALCULATE_FAILED');
        $successMessage = Text::_('COM_ALFA_CART_RECALCULATE_SUCCESS');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);
        $userGroupId = 1;
        $currencyId = 1;

        // TODO: cache the item here or in the model, accounting for the varying quantity.
        // Load the full item data.
        $model = $this->getModel('Item');

        // Set state in the model
        $model->setState('quantity', $quantity);
        // Data-only price/stock render: skip the field display surfaces.
        $model->setState('plugin.layouts', false);
        $item = $model->getItem($itemId);

        // Calculate price

        // Layout::render (not LayoutHelper): it supplies the component's layout base
        // path. A raw LayoutHelper::render('price') searches JPATH_ROOT/layouts, finds
        // nothing and returns '', so the AJAX payload silently carried empty markup.
        $priceLayout = Layout::render(
            'item.price',
            [
                'item' => $item,
                'category' => [],
            ],
        );

        $stockAvailabilityLayout = Layout::render(
            'item.stock_info',
            [
                'item' => $item,
                'quantity' => $quantity,
            ],
        );

        $responseData = [
            'price_layout' => $priceLayout,
            'stock_info_layout' => $stockAvailabilityLayout,
        ];

        $response = new JsonResponse($responseData, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);

        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: add an item (with the posted configuration and quantity) to the cart
     * and answer with the outcome card. The card is the `cart.added` layout rendered
     * server-side (the added line with the cart totals, or the buyer messages when
     * the add was rejected), so the layout owns every word the shopper reads and the
     * script only mounts it. Requires a valid POST CSRF token; closes the application
     * after emitting JSON. Both JSON replies, the rejected-token one included, declare
     * their type (application/json), so the script can tell the cart's answer from any
     * other page a request may be answered with.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function addToCart()
    {
        $failMessage = Text::_('COM_ALFA_CART_ADD_FAILED');
        $successMessage = Text::_('COM_ALFA_CART_ADD_SUCCESS');

        // A navigation is answered with a page, a rejected token included; never raw JSON.
        if (!$this->checkToken('post', false)) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN_NOTICE'), 'warning');

            if ($this->wantsHtml()) {
                $this->app->redirect($this->safeReturnUrl());

                return;
            }

            $this->app->setHeader(name: 'Content-Type', value: 'application/json; charset=utf-8', replace: true);
            $this->app->sendHeaders();

            echo new JsonResponse(null, $failMessage, true);
            $this->app->close();
        }

        $quantity = $this->input->getInt('quantity', 1);
        $itemId = $this->input->getInt('item_id', 0);
        $editLineId = $this->input->getInt('edit_line', 0);

        $result = null;

        try {
            $result = CartService::ambient()->add($itemId, $quantity);
        } catch (Throwable $e) {
            // The card below renders this queue verbatim, so what lands in it is
            // text the shopper reads. The failure's own words go to the log,
            // after register(), because an unregistered category is dropped in
            // silence and the detail would be lost rather than redirected.
            AlfaLog::register();
            Log::add('Cart add failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            $this->app->enqueueMessage($failMessage, 'error');
        }

        $lineId = $result?->lineId ?? 0;
        $line = $result?->line;

        if ($line !== null) {
            // Cart lines carry no media (the drawer never shows one); the card does,
            // in the listing card's shape. Prices show as the line total alone:
            // the card confirms, the cart page itemises.
            $line->data->medias = MediaHelper::getMediaData(origin: 'item', itemIDs: (int) $line->id_item);
        }

        $responseData = [
            'line_id' => $line !== null ? $lineId : 0,
            'total_items' => $result?->itemCount ?? 0,
            'total_quantity' => $result?->quantity ?? 0,
        ];

        $options = $this->addedCardOptions();

        // The card key is present only when there is a card to show: a rejected
        // add always has one, a successful add only while the confirmation is on.
        if ($line === null || $options['enabled']) {
            $responseData['added'] = Layout::render('cart.added', [
                'line' => $line,
                'edited' => $line !== null && $editLineId > 0 && $lineId === $editLineId,
                'quantity' => $result?->quantity ?? 0,
                'grandTotal' => $result?->grandTotal,
                'priceSettings' => PriceSettings::minimal(),
                'messages' => $line === null ? $this->app->getMessageQueue() : [],
            ] + $options);
        }

        // A browser navigation gets Post/Redirect/Get; every other caller keeps today's JSON.
        if ($this->wantsHtml()) {
            $this->app->enqueueMessage(
                $line === null ? $failMessage : $successMessage,
                $line === null ? 'error' : 'message',
            );
            $this->app->redirect($this->safeReturnUrl());

            return;
        }

        $response = new JsonResponse($responseData, $line === null ? $failMessage : $successMessage, $line === null);

        $this->app->setHeader(name: 'Content-Type', value: 'application/json; charset=utf-8', replace: true);
        $this->app->sendHeaders();

        echo $response;
        $this->app->close();
    }

    /**
     * Whether this request is a browser navigation rather than a programmatic call.
     *
     * A native form post accepts text/html; fetch() sends a wildcard Accept unless it says
     * otherwise, and addtocart.js says application/json. Anything that does not explicitly accept
     * HTML keeps the JSON contract, so an API client is never redirected.
     *
     * @since  2.0.0
     */
    private function wantsHtml(): bool
    {
        return str_contains($this->input->server->getString('HTTP_ACCEPT', ''), 'text/html');
    }

    /**
     * A full-page URL to send a native submit back to.
     *
     * The referring page, accepted only when it is internal, the same rule core applies in
     * BaseController::checkToken(). Any tmpl is stripped: a form rendered during a filter
     * module's partial refresh would otherwise send the shopper to a chrome-less page.
     *
     * @since  2.0.0
     */
    private function safeReturnUrl(): string
    {
        $referrer = (string) $this->input->server->getString('HTTP_REFERER', '');

        if ($referrer === '' || !Uri::isInternal($referrer)) {
            return Route::_('index.php?option=com_alfa&view=cart', false);
        }

        $uri = new Uri($referrer);
        $uri->delVar('tmpl');

        return $uri->toString();
    }

    /**
     * The confirmation card's presentation options (Options -> Add-to-cart confirmation):
     * where it sits, how far from the edges, how it enters and when it closes. Values are
     * whitelisted here because they land in HTML attributes and a style attribute.
     *
     * @return array enabled (the success card is shown), placement, offsetY, offsetX (CSS lengths), effect, timeout (seconds, fractions allowed).
     *
     * @since  2.0.0
     */
    private function addedCardOptions(): array
    {
        $params = $this->app->getParams('com_alfa');

        $placement = (string) $params->get('cart_added_pos', 'top-right');
        $effect = (string) $params->get('cart_added_effect', 'slide');

        $length = static function ($value, $unit): string {
            $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

            return $number . (\in_array($unit, ['px', '%'], true) ? $unit : 'px');
        };

        return [
            'enabled' => (bool) $params->get('cart_added', 1),
            'placement' => \in_array($placement, ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'], true) ? $placement : 'top-right',
            'offsetY' => $length($params->get('cart_added_offset_vertical', 16), $params->get('cart_added_offset_vertical_unit', 'px')),
            'offsetX' => $length($params->get('cart_added_offset_horizontal', 16), $params->get('cart_added_offset_horizontal_unit', 'px')),
            'effect' => \in_array($effect, ['slide', 'fade', 'zoom', 'none'], true) ? $effect : 'slide',
            'timeout' => max(0.0, (float) $params->get('cart_added_timeout', 6)),
        ];
    }

    /**
     * Apply a coupon code to the cart: plain POST + redirect (the totals
     * re-render with the applied rule row). The code is only an activation
     * method: unknown/ineligible codes are rejected with a message.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function applyCoupon()
    {
        $this->checkToken();

        $code = (string) $this->app->getInput()->post->get('coupon_code', '', 'string');

        // The engine distinguishes "no such code" from "you already have a
        // better discount" from "you need to be logged in"; say which.
        $outcome = CartService::ambient()->applyCoupon($code);

        $this->app->enqueueMessage(
            Text::_(CouponOutcome::languageKey($outcome)),
            CouponOutcome::messageType($outcome),
        );

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));
    }

    /**
     * Remove an entered coupon code from the cart.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function removeCoupon()
    {
        $this->checkToken();

        $code = (string) $this->app->getInput()->post->get('coupon_code', '', 'string');

        if ($code !== '') {
            CartService::ambient()->removeCoupon($code);
            $this->app->enqueueMessage(Text::_('COM_ALFA_CART_COUPON_REMOVED'), 'message');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));
    }

    /**
     * AJAX task: empty the cart and return the rendered empty-cart layout as JSON.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function clearCart()
    {
        $errorOccurred = false;

        $failMessage = Text::_('COM_ALFA_CART_CLEAR_FAILED');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);
        $userId = Factory::getApplication()->getIdentity()->id;
        $response_data = [];

        // "Clear cart" empties the lines and keeps the cart; the shopper is
        // still shopping. Retiring a cart is a different act, and it belongs to
        // placement alone.
        $errorOccurred = !CartService::ambient()->clear();

        $result = $this->cartPartial(file: SlotResolver::findFile(view: 'cart', file: 'default_cart_empty'));

        $data = [
            'data' => $result,
        ];

        // json output
        header('Content-Type: application/json');
        $response = new JsonResponse($result, $errorOccurred ? $failMessage : Text::_('COM_ALFA_CART_CLEAR_SUCCESS'), $errorOccurred);
        echo $response;

        $this->app->close();
    }

    /**
     * AJAX task: set a line's quantity and, on success, answer with the whole
     * method response (items, shipments, payments, the chosen ids and the
     * reconcile notices), read off the one settled reading the change left.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function updateQuantity()
    {
        $input = $this->app->input;

        $failMessage = Text::_('COM_ALFA_CART_ITEM_UPDATE_FAILED');
        $successMessage = Text::_('COM_ALFA_CART_ITEM_UPDATE_SUCCESS');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $lineId = $input->getInt('id_line', 0);
        $quantity = $input->getInt('quantity', 1);

        $response_data = [];
        $errorOccurred = false;

        // Target a single configured line (not the item) and clamp/remove it.
        // The reading comes back settled against the delivery place the form
        // holds, and the whole method response is built from it: a quantity is
        // a fact a carrier's parcel limits and a payment method's ceiling are
        // conditioned on, and a panel left standing after the change could
        // still offer a method the cart no longer qualifies for.
        $outcome = CartService::ambient()->setLineQuantityAtCheckout(
            lineId: $lineId,
            quantity: $quantity,
            placeId: $this->deliveryPlace(),
        );
        $errorOccurred = !$outcome->succeeded;

        // A REFUSED CHANGE IS ANSWERED WITH THE CART AS IT STANDS, so the page
        // shows the line at the quantity the cart kept; and every answer
        // carries what the cart said about the change, for the cart's message
        // slot.
        $response_data = $this->methodsResponse(
            $outcome->succeeded ? $outcome->snapshot : CartService::ambient()->checkout($this->deliveryPlace()),
        );
        $response_data['messages'] = $this->messagesFragment();

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * The reload task of the checkout form (FormController::reload
     * pattern): the WHOLE form posts here, so current field values are
     * preserved in user state; a picked saved-info row (own rows only)
     * overlays the customer fields. loadFormData binds on render.
     *
     *
     * @since  2.0.0
     */
    public function pickUserinfo(): void
    {
        $this->checkToken();

        $data = $this->input->post->get('cartform', [], 'array');

        // Both pickers post together (whole-form reload pattern); each role
        // resolves independently into its own bucket and state slot.
        $data = $this->applyUserInfoPick(
            data: $data,
            picked: $this->input->getString('userinfo_pick', ''),
            bucket: FieldsHelper::FIELDS_KEY,
            stateKey: 'com_alfa.cart.userinfo_pick',
        );
        $data = $this->applyUserInfoPick(
            data: $data,
            picked: $this->input->getString('userinfo_pick_billing', ''),
            bucket: FieldsHelper::BILLING_FIELDS_KEY,
            stateKey: 'com_alfa.cart.userinfo_pick_billing',
        );

        $this->app->setUserState('com_alfa.cart.form_data', $data);

        // POINT THE CART AT THE CHOSEN RECORDS. `id_user_info_delivery` was
        // written as 0 at creation and only ever assigned in memory at
        // placement, so the column claimed every cart had no details. A picked
        // address is a decision about THIS cart and outlives the session: it is
        // what makes the delivery-place fallback reachable and what lets the
        // backend see whose cart it is looking at.
        $deliveryId = (int) $this->app->getUserState('com_alfa.cart.userinfo_pick', 0);
        $invoiceId = (int) $this->app->getUserState('com_alfa.cart.userinfo_pick_billing', 0);

        if ($deliveryId > 0) {
            CartService::ambient()->pointAtSavedDetails(
                deliveryId: $deliveryId,
                invoiceId: $invoiceId > 0 ? $invoiceId : null,
            );
        }

        // Land back on the picker that was used; a plain reload returns to
        // the top (painful on mobile); the fragment scrolls natively.
        $anchor = $this->input->getString('picker_apply') === 'billing' ? '#userinfo_pick_billing' : '#userinfo_pick';

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false) . $anchor);
    }

    /**
     * Resolve one picker role: "new" empties its bucket (and disables the
     * implicit default), an owned row id overlays the bucket, anything else
     * leaves the bucket as posted. Posted ids are never written; the row
     * is re-read under the current user.
     *
     * @param array $data The posted cartform data.
     * @param string $picked The picker value ('' | 'new' | row id).
     * @param string $bucket The field bucket this role fills.
     * @param string $stateKey The user-state slot recording the choice.
     *
     * @return array The (possibly overlaid) form data.
     *
     * @since  2.0.0
     */
    private function applyUserInfoPick(array $data, string $picked, string $bucket, string $stateKey): array
    {
        if ($picked === '') {
            return $data; // picker not on the page for this role
        }

        if ($picked === 'new') {
            unset($data[$bucket]);
            $this->app->setUserState($stateKey, 'new');

            return $data;
        }

        $userId = (int) $this->app->getIdentity()?->id;
        $row = (int) $picked > 0 && $userId > 0 ? UserInfoHelper::getRow(userId: $userId, id: (int) $picked) : null;

        if ($row !== null) {
            $values = get_object_vars($row);
            unset($values['id'], $values['id_user']);
            $data[$bucket] = $values;
        }

        $this->app->setUserState($stateKey, $row !== null ? (int) $picked : null);

        return $data;
    }

    /**
     * AJAX keeper of the checkout form state (fired on field changes by
     * form-state.js): typed values survive refreshes and closed tabs.
     *
     *
     * @since  2.0.0
     */
    public function saveFormState(): void
    {
        self::verifyTokenAndRespondJson('post', Text::_('JINVALID_TOKEN'));

        $data = $this->input->post->get('cartform', [], 'array');

        // Session-bloat guard: no legitimate checkout form comes close.
        if (\strlen(serialize($data)) > 65536) {
            echo new JsonResponse(null, Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), true);
            $this->app->close();
        }

        $this->app->setUserState('com_alfa.cart.form_data', $data);

        echo new JsonResponse(['saved' => true]);
        $this->app->close();
    }

    /**
     * Validate and submit the checkout form, then place the order through OrderPlacement.
     * Checks the CSRF token and session state, validates the cart form, and on success stores the
     * new order id in the session and redirects to the order-process page; otherwise redirects back
     * to the cart with enqueued error messages.
     *
     * @return bool True when the order is placed, false on validation/session/placement failure.
     *
     * @since  1.0.0
     */
    public function placeOrder()
    {
        $isValid = $this->checkToken();//'post',false  this functions uses the Session:checkToken inside

        $app = $this->app;
        $model = $this->getModel('cart');
        $data = $this->input->post->get('cartform', [], 'array');
        $validateSessionCookie = true;
        if ($validateSessionCookie) {
            if ($app->getSession()->getState() !== 'active') {
                $this->app->enqueueMessage(Text::_('JLIB_ENVIRONMENT_SESSION_INVALID'), 'warning');

                // Redirect back to the cart form.
                $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

                return false;
            }
        }

        $form = $model->getForm();

        if (!$form) {
            throw new Exception($model->getError(), 500);
        }

        // Keep the FILTERED result: validate() applies each field's InputFilter.
        // Using the raw POST past this point would persist unfiltered values into
        // the order / user-info rows while validation passed on the clean copy.
        $validData = $model->validate($form, $data);

        if ($validData === false) {
            $errors = $model->getErrors();

            // Two unrelated things arrive in the same array: what the form
            // decided, which the buyer must keep seeing, and what broke
            // underneath, which is the driver's words. FormErrors tells them
            // apart by exact class, never by reading the text.
            $split = FormErrors::partition($errors);

            foreach ($split['messages'] as $message) {
                $app->enqueueMessage($message, 'error');
            }

            if ($split['failures'] !== []) {
                AlfaLog::register();
                Log::add('Checkout validation failed: ' . FormErrors::detail($split['failures']), Log::ERROR, 'com_alfa.orders');

                // Said once, however many rules broke.
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_ERROR'), 'error');
            }

            // Keep what the customer typed across the redirect.
            $app->setUserState('com_alfa.cart.form_data', $data);
            // The credit opt-in is a top-level field (outside cartform[]);
            // keep it across the redirect like every other typed value.
            $app->setUserState('com_alfa.cart.use_credit', $this->input->getInt('use_credit', 0));
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return false;
        }

        try {
            $orderHelper = new OrderPlacement();

            // placeOrder() returns true on success, false on failure. The billing
            // bucket only survives validation when the customer asked for
            // different invoice details; absent means "same as delivery".
            $orderPlacedSuccessfully = $orderHelper->placeOrder(
                $validData[FieldsHelper::FIELDS_KEY] ?? [],
                $validData[FieldsHelper::BILLING_FIELDS_KEY] ?? [],
            );

            if ($orderPlacedSuccessfully) {
                $order = $orderHelper->getOrder();

                // THE COMMITTED ID IS THE AUTHORITY, NOT THE RELOAD. An order
                // that committed and then could not be read back still exists,
                // and the confirmation page has to be able to point at it, so
                // the id comes from the placement's own outcome, which is
                // recorded at the commit, and the reloaded row is only what
                // decides whether the shopper is told something went missing.
                $orderId = $orderHelper->getOutcome()->orderId();

                if ($orderId > 0) {
                    if (!$order || !isset($order->id)) {
                        $this->app->enqueueMessage(
                            Text::_('COM_ALFA_ORDER_PLACED_BUT_NOT_LOADED'),
                            'warning',
                        );
                    }

                    // Store order id in the session for the success page.
                    $this->app->setUserState('com_alfa.order_id', $orderId);

                    // Clear cart-related session data.
                    $this->app->setUserState('com_alfa.cart.userinfo_pick', null);
                    $this->app->setUserState('com_alfa.cart.userinfo_pick_billing', null);
                    $this->app->setUserState('com_alfa.cart.form_data', null);
                    $this->app->setUserState('com_alfa.cart.use_credit', null);

                    // Redirect to success/processing page
                    $this->setRedirect(
                        Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process', false),
                    );

                    return true;
                } else {
                    // Nothing to point at: no committed id and no row.
                    $this->app->enqueueMessage(
                        Text::_('COM_ALFA_ORDER_PLACED_BUT_NOT_LOADED'),
                        'warning',
                    );

                    // Still redirect to success (order was created)
                    $this->setRedirect(
                        Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process', false),
                    );

                    return true;
                }
            } else {
                // Placement failed; OrderPlacement has already enqueued the specific errors.
                // Add a fallback message.
                $this->app->enqueueMessage(
                    Text::_('COM_ALFA_ORDER_PLACEMENT_FAILED'),
                    'error',
                );

                // Keep what the customer typed across the redirect.
                $this->app->setUserState('com_alfa.cart.form_data', $data);
                $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

                return false;
            }
        } catch (Throwable $e) {
            // \Throwable, not Exception: a plugin's TypeError is not an
            // Exception, and this is the last net before the buyer would see a
            // white page on a checkout whose order may already be committed.
            // Text::_, not Text::sprintf with the exception. The key carries no
            // placeholder, so the driver's text was already being discarded, but
            // a translator who adds one would silently open the leak.
            $this->app->enqueueMessage(Text::_('COM_ALFA_ORDER_ERROR'), 'error');

            // Log the error
            \Joomla\CMS\Log\Log::add(
                'Order placement exception: ' . $e->getMessage(),
                \Joomla\CMS\Log\Log::ERROR,
                'com_alfa.orders',
            );

            // Redirect back to cart
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return false;
        }
        return true;
    }

    // Updates the cart's shipment id, re-renders the items layout, and returns it as an answer.
    public function updateShipment()
    {
        $input = $this->app->input;

        $failMessage = Text::_('COM_ALFA_CART_SHIPMENT_UPDATE_FAILED');
        $successMessage = Text::_('COM_ALFA_CART_SHIPMENT_UPDATE_SUCCESS');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $shipmentID = $input->getInt('id_shipment', 0);
        $response_data = [];
        $errorOccurred = false;

        $outcome = CartService::ambient()->chooseShipment($shipmentID, $this->deliveryPlace());
        $errorOccurred = !$outcome->succeeded;

        if (!$errorOccurred) {
            $response_data = $this->methodsResponse($outcome->snapshot);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: set the cart's payment method and, on success, return the re-rendered items, payments
     * and shipments layouts (plus the empty flag) as JSON.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function updatePayment()
    {
        $input = $this->app->input;

        $failMessage = Text::_('COM_ALFA_CART_PAYMENT_UPDATE_FAILED');
        $successMessage = Text::_('COM_ALFA_CART_PAYMENT_UPDATE_SUCCESS');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $paymentID = $input->getInt('id_payment', 0);
        $response_data = [];
        $errorOccurred = false;

        $outcome = CartService::ambient()->choosePayment($paymentID, $this->deliveryPlace());
        $errorOccurred = !$outcome->succeeded;

        if (!$errorOccurred) {
            $response_data = $this->methodsResponse($outcome->snapshot);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: the checkout form changed something the offered methods
     * depend on (the delivery place). Keeps the posted form in user state (
     * the same state the page reload reads), reconciles the chosen methods
     * and returns the re-rendered items, payments and shipments layouts.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     *
     * @since  1.0.0
     */
    public function refreshMethods(): void
    {
        $failMessage = Text::_('COM_ALFA_CART_METHODS_REFRESH_FAILED');

        self::verifyTokenAndRespondJson('post', $failMessage);

        $data = $this->input->post->get('cartform', [], 'array');

        // Session-bloat guard: no legitimate checkout form comes close.
        if (\strlen(serialize($data)) > 65536) {
            echo new JsonResponse(null, $failMessage, true);
            $this->app->close();
        }

        $this->app->setUserState('com_alfa.cart.form_data', $data);

        // A LOOK, NOT A CHANGE. Reloading the method list must leave a
        // first-time visitor with no cart row and no recognition cookie, which
        // is what the operation's own identity mode guarantees.
        $snapshot = CartService::ambient()->checkout($this->deliveryPlace());

        header('Content-Type: application/json');
        echo new JsonResponse($this->methodsResponse($snapshot), Text::_('COM_ALFA_CART_METHODS_REFRESH_SUCCESS'));
        $this->app->close();
    }

    /**
     * Where the checkout form says the order is going.
     *
     * The offered methods are filtered by it, so every checkout operation is
     * given it rather than left to discover it. Which of those operations may
     * create a cart is no longer decided here: it is stated on the operation.
     *
     * @return int|null The bound delivery place, or null when the form has none.
     *
     * @since  2.0.0
     */
    private function deliveryPlace(): ?int
    {
        return $this->getModel('cart')->getDeliveryPlaceId();
    }

    /**
     * The JSON payload of a method change: the re-rendered items, payments
     * and shipments layouts, the chosen method ids and the reconcile notices.
     *
     * ONE READING FOR THE WHOLE RESPONSE. The three layouts, the chosen ids and
     * the notices all describe the same cart, because they are read off one
     * snapshot taken when the change settled rather than re-asked of a cart
     * that each render could move.
     *
     * @param CartSnapshot $snapshot The settled reading taken after the change.
     *
     *
     * @since  1.0.0
     */
    private function methodsResponse(CartSnapshot $snapshot): array
    {
        $declaredBefore = $this->declaredAssets();

        $response = [
            'isEmpty' => $snapshot->isEmpty(),
            'items' => $this->getItemsLayout($snapshot),
            'payments' => $this->getPaymentsLayout($snapshot),
            'shipments' => $this->getShipmentsLayout($snapshot),
            'selected' => [
                'shipment' => $snapshot->offers->selectedShipmentId,
                'payment' => $snapshot->offers->selectedPaymentId,
            ],
            'notices' => [
                'shipment' => $snapshot->offers->notices('shipment'),
                'payment' => $snapshot->offers->notices('payment'),
            ],
        ];

        $this->warnLateAssets($declaredBefore);

        return $response;
    }

    /**
     * What the cart said about the change just made, rendered for the cart's
     * message slot (layout cart.messages): the messages queued in this request,
     * taken off the queue so they are said once.
     *
     *
     * @since  2.0.0
     */
    private function messagesFragment(): string
    {
        return Layout::render('cart.messages', ['messages' => $this->app->getMessageQueue(true)]);
    }

    /**
     * The assets active on this request's document, under JDEBUG only.
     *
     * GUARDED, BECAUSE IT IS A DIAGNOSTIC. Reading the active set resolves
     * dependencies, and a stray asset with one nobody registered throws
     * there; a tell must never be what breaks the answer it is checking.
     *
     * @return string[] Asset names; empty when not debugging or unreadable.
     *
     * @since  2.0.0
     */
    private function declaredAssets(): array
    {
        if (!\defined('JDEBUG') || !JDEBUG) {
            return [];
        }

        try {
            $wa = $this->app->getDocument()->getWebAssetManager();

            return array_merge(
                array_keys($wa->getAssets('script')),
                array_map(static fn (string $name): string => $name . '#style', array_keys($wa->getAssets('style'))),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A DEVELOPER'S TELL, NOT A REPAIR. A method layout that declares an asset
     * while rendering an AJAX answer declares it into a document that has no
     * head. One the JSON request knows (a core asset, or one whose registry
     * file the layout adds) is silently dropped; one it does not know throws
     * out of useScript(), because com_alfa's own registry is added on HTML
     * renders only. Either way nothing reaches the browser. Under JDEBUG the
     * dropped case is written down, with the fix, so a plugin author meets it
     * on the first refresh rather than in a shop.
     *
     * @param string[] $before The assets active before the fragments rendered.
     *
     *
     * @since  2.0.0
     */
    private function warnLateAssets(array $before): void
    {
        if (!\defined('JDEBUG') || !JDEBUG) {
            return;
        }

        $late = array_values(array_diff($this->declaredAssets(), $before));

        if ($late === []) {
            return;
        }

        Log::add(
            'Cart method assets must be declared in onCartViewAssets(); declarations made while rendering an AJAX fragment are ignored: '
            . implode(', ', $late),
            Log::WARNING,
            'com_alfa.cart',
        );
    }

    /**
     * Render the cart items layout, falling back to the empty-cart layout when the cart has no items.
     *
     * @param CartSnapshot $snapshot One settled reading of the cart.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getItemsLayout(CartSnapshot $snapshot)
    {
        $layoutData = [];
        $isEmpty = $snapshot->isEmpty();

        if ($isEmpty) {
            $result = $this->cartPartial(file: SlotResolver::findFile(view: 'cart', file: 'default_cart_empty'));
        } else {
            // Honours the cart_items slot variant + template overrides, matching the full-page render.
            $result = $this->cartPartial(
                file: SlotResolver::resolveFile(value: (string) $this->app->getParams('com_alfa')->get('cart_items_layout', ''), view: 'cart', slot: 'cart_items'),
                displayData: $snapshot,
            );
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    /**
     * Render the payment-method selection layout.
     * Returns an empty template when the cart has no items.
     *
     * @param CartSnapshot $snapshot One settled reading of the cart, carrying
     *                               what each method asked to show beside it.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getPaymentsLayout(CartSnapshot $snapshot)
    {
        $layoutData = [];
        $isEmpty = $snapshot->isEmpty();

        $result = '';

        if (!$isEmpty) {
            $result = $this->cartPartial(
                file: SlotResolver::resolveFile(value: (string) $this->app->getParams('com_alfa')->get('cart_select_payment_layout', ''), view: 'cart', slot: 'select_payment'),
                displayData: $snapshot,
            );
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    /**
     * Render the shipment-method selection layout.
     * Returns an empty template when the cart has no items.
     *
     * @param CartSnapshot $snapshot One settled reading of the cart, carrying
     *                               what each carrier asked to show beside it.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getShipmentsLayout(CartSnapshot $snapshot)
    {
        $layoutData = [];
        $isEmpty = $snapshot->isEmpty();

        $result = '';

        if (!$isEmpty) {
            $result = $this->cartPartial(
                file: SlotResolver::resolveFile(value: (string) $this->app->getParams('com_alfa')->get('cart_select_shipment_layout', ''), view: 'cart', slot: 'select_shipment'),
                displayData: $snapshot,
            );
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    /**
     * Render one cart tmpl partial from a resolved [basename, basePath] pair.
     *
     * @param array{0: string, 1: string} $file SlotResolver::resolveFile()/findFile() result
     * @param mixed $displayData Data exposed to the layout as $displayData
     *
     * @return string Rendered HTML
     * @since  2.0.0
     */
    private function cartPartial(array $file, mixed $displayData = []): string
    {
        return (new FileLayout($file[0], $file[1]))->render($displayData);
    }

    protected function verifyTokenAndRespondJson($method = 'post', $invalidMessage = '')
    {
        $isValid = $this->checkToken($method, false);

        if (!$isValid) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN_NOTICE'), 'warning');
            $response = new JsonResponse(null, $invalidMessage, true);
            echo $response;
            $this->app->close();
        }
    }
}
