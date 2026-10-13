<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSource;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartTakeover;
use Alfa\Component\Alfa\Administrator\Service\Cart\OperatorCheckout;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Cart\AddToCartRequest;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Cart\CartSnapshot;
use Alfa\Component\Alfa\Site\Service\Cart\NamedCartEditor;
use Alfa\Component\Alfa\Site\Service\CartRules\CouponOutcome;
use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

/**
 * The back office's live cart, driven a mutation at a time.
 *
 * The one screen an operator builds an order on. This controller does none of
 * the commercial work: it establishes that a request is genuine and permitted,
 * turns the POST into a typed instruction, and hands it to the ONE live-cart
 * engine: {@see NamedCartEditor}, the same owners the storefront mutates
 * through. It writes no cart or order row, prices nothing, decides no
 * eligibility, and after every change reads the answer back off the
 * authoritative snapshot rather than assembling one of its own. A second
 * business engine written for an admin screen is how two carts start to
 * disagree, and there is not going to be one.
 *
 * THE PERMISSION IS ASKED HERE, ON EVERY ENDPOINT, NOT PAINTED ON THE SCREEN.
 * Each mutation is POST-only, carries a valid session token, and asks
 * alfa.carts.checkout strictly, never satisfied by the right that only admits
 * a person to the back office at all. On a cart a storefront shopper created it
 * asks alfa.carts.edit as well, from the source the cart ROW persisted. A
 * crafted request meets the same four refusals the button's own POST does.
 *
 * THE CUSTOMER IS A PROPERTY OF THE CART, NOT OF THE REQUEST. For a cart that
 * already exists the commercial customer is read off the row and the request's
 * word for it is never consulted, so a crafted id cannot make one customer's
 * cart be priced or placed as another's. The single exception is
 * {@see self::createBuilderCart()}, where no cart exists yet to read it from.
 *
 * @since  2.0.0
 */
class CartController extends BaseController
{
    /**
     * Placement codes whose storefront sentence speaks to the shopper, and the
     * admin key that says the same thing to the operator placing the cart.
     *
     * The storefront keeps its wording; only this screen reads these keys.
     * They are distinct names because checkout() loads the site catalogue
     * after the admin one, and a shared key would come back in the shopper's
     * words. Each takes the same parameters as the code it replaces. A plugin's
     * veto, which only the plugin can word, is framed for the operator with its
     * words inside.
     *
     * @var array<string, string>
     * @since  2.0.0
     */
    private const OPERATOR_WORDING = [
        'COM_ALFA_ORDER_PLACE_LINE_UNAVAILABLE' => 'COM_ALFA_CART_CHECKOUT_LINE_UNAVAILABLE',
        'COM_ALFA_ORDER_PLACE_AFTER_HOOK_FAILED' => 'COM_ALFA_CART_CHECKOUT_AFTER_HOOK_FAILED',
        'COM_ALFA_ORDER_PLACE_EMAIL_REQUIRED' => 'COM_ALFA_CART_CHECKOUT_EMAIL_REQUIRED',
        'COM_ALFA_ORDER_PLACE_CART_EMPTY' => 'COM_ALFA_CART_CHECKOUT_CART_EMPTY',
        'COM_ALFA_CART_CREDIT_NOT_APPLIED' => 'COM_ALFA_CART_CHECKOUT_CREDIT_NOT_APPLIED',
        'COM_ALFA_CART_LOAD_FAILED' => 'COM_ALFA_CART_CHECKOUT_CART_NOT_LOADED',
        'COM_ALFA_SHIPMENT_QUOTE_FAILED' => 'COM_ALFA_CART_CHECKOUT_SHIPMENT_QUOTE_FAILED',
        'COM_ALFA_ORDER_PLACE_PLUGIN_REFUSED' => 'COM_ALFA_CART_CHECKOUT_PLUGIN_REFUSED',
    ];

    /**
     * @var CartSource|null The source of the cart the request names, once a guard
     *                      has read it; null when there is no such cart.
     * @since  2.0.0
     */
    private ?CartSource $cartSource = null;

    /**
     * Place the named cart as its customer, on their behalf.
     *
     *
     * @since  2.0.0
     */
    public function checkout(): void
    {
        $app = $this->app;
        $input = $this->input;

        // 1. A placement changes the world, so it is never a GET.
        if (strtoupper((string) $input->getMethod()) !== 'POST') {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 405);
        }

        // 2. The request must have come from a form this session issued.
        if (!Session::checkToken('post')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        // PLACEMENT'S VOCABULARY IS THE STOREFRONT'S, and this screen renders it.
        //
        // The codes a placement answers with (an unavailable line, a refused
        // coupon, a credit that could not be applied) are the shopper's words,
        // and they live in the site catalogue because that is where the checkout
        // that raises them lives. Duplicating sixteen strings into the admin
        // catalogue would only give them two places to drift apart, so this
        // states the dependency instead of inheriting it: loaded once, here,
        // before anything is rendered, for refusals and warnings alike. The few
        // that speak to the shopper are said to staff by self::OPERATOR_WORDING.
        $app->getLanguage()->load('com_alfa', JPATH_SITE);

        $actor = $app->getIdentity() ?? new User(0);

        // 3. STRICTLY, and not ORed with the right that gets a person into this
        //    component's back office at all - admitting that here would mean
        //    everybody who can read the orders list can also charge a customer,
        //    and the permission would document a restriction it does not impose.
        if (!AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor)) {
            throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_CHECKOUT_DENIED'), 403);
        }

        $cartId = (int) $input->post->getInt('cart_id', 0);

        if ($cartId <= 0) {
            $this->refuse(0, [['code' => 'COM_ALFA_ORDER_PLACE_OPERATOR_CART_REQUIRED', 'params' => []]]);

            return;
        }

        // THE CUSTOMER IS A PROPERTY OF THE CART, NOT OF THE FORM. Reading it
        // off the row rather than off the POST is what stops a crafted request
        // naming somebody else's account: the value is then not the caller's to
        // choose, and the hydrator checks it against the same row again before
        // anything is priced.
        $customerId = $this->customerOf($cartId);

        if ($customerId === null) {
            $this->refuse($cartId, [['code' => 'COM_ALFA_CART_NOT_FOUND', 'params' => []]]);

            return;
        }

        // 4. A SHOPPER'S CART NEEDS CARTS: EDIT AS WELL, and is refused before it
        //    is even opened, so a refused request settles nothing.
        //    OperatorCheckout::place() asks the same again, for a caller that
        //    never came through here.
        $this->guardTakeover(cartId: $cartId, actor: $actor);

        // THE METHODS ARE THE CART'S, NOT THE FORM'S.
        //
        // The operator chose a carrier and a gateway on the Shipment & payment
        // tab, and that choice was written to the cart and reconciled against
        // what the engine actually offers. Reading the ids back off a POST would
        // make the last page the operator happened to be looking at the
        // authority, and a crafted one, naming a method the cart never
        // selected, would be indistinguishable from an honest stale form. So
        // the request is not asked: the cart is, at the moment of placing.
        //
        // Placement checks them again on its own account, against the offers it
        // recomputes inside the transaction. This is not that check; this is
        // making sure the thing being checked is what the cart says.
        $selection = $this->cartSelection($cartId, $actor, new User($customerId));

        $result = OperatorCheckout::place(
            actor: $actor,
            customer: new User($customerId),
            cartId: $cartId,
            delivery: (array) $input->post->get('alfa_form_fields', [], 'array'),
            billing: (array) $input->post->get('alfa_form_fields_billing', [], 'array'),
            payment: $selection['payment'],
            shipment: $selection['shipment'],
            language: $this->languageFor($customerId),
            // The destination is derived from the delivery details placement is
            // given, by the one method that does it; naming it here would be a
            // second answer to the same question.
            placeId: null,
            useCredit: (int) $input->post->getInt('use_credit', 0) === 1,
            initialStatus: (int) $input->post->getInt('order_status', 0),
        );

        // COMMITTED IS THE QUESTION, NOT PLACED.
        //
        // An order whose transaction committed exists even if the work after it
        // did not finish, and the operator must be sent to it rather than back
        // to a form. Sending them back would be an invitation to place it again,
        // and placing it again is a second real order for a customer who already
        // has one, which is precisely the accident this distinction exists to
        // prevent. The cart is gone either way; there is nothing to resubmit.
        if (!$result['committed']) {
            $this->refuse($cartId, $result['failures']);

            return;
        }

        $orderId = (int) $result['orderId'];

        if ($result['warnings'] !== []) {
            // The order stands. Say what did not finish, and say it as a warning
            // about follow-up work rather than as a failure to create anything.
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_CART_CHECKOUT_PLACED_WITH_WARNING', $orderId),
                'warning',
            );

            foreach ($result['warnings'] as $warning) {
                $sentence = $this->operatorReason((array) $warning);

                if ($sentence !== '') {
                    $app->enqueueMessage($sentence, 'warning');
                }
            }
        } else {
            $app->enqueueMessage(Text::sprintf('COM_ALFA_CART_CHECKOUT_PLACED', $orderId), 'message');
        }

        // The order screen needs orders edit; without it the operator lands on the
        // carts list, where the message above still names the order.
        $this->setRedirect(
            AlfaHelper::can(section: 'orders', action: 'edit', user: $actor)
                ? 'index.php?option=com_alfa&task=order.edit&id=' . $orderId
                : 'index.php?option=com_alfa&view=carts',
        );
    }

    /**
     * Start a builder cart for a chosen customer.
     *
     * The ONE endpoint where the customer is the request's to name, because no
     * cart exists yet to read it off. The id is resolved to a real account (or
     * left 0 for a walk-in guest) and the cart is minted through the B1A
     * creation API under an operator subject, so its id_customer is set by the
     * subject's owner and never written here.
     *
     *
     * @since  2.0.0
     */
    public function createBuilderCart(): void
    {
        $actor = $this->guardMutation();
        $customerId = (int) $this->input->post->getInt('customer_id', 0);

        if ($customerId > 0 && !$this->customerExists($customerId)) {
            $this->emitError('COM_ALFA_CART_BUILDER_CUSTOMER_NOT_FOUND', 404);

            return;
        }

        // NEW MEANS NEW. createNamedCart mints under FRESH, which neither
        // discovers nor adopts, so this can never hand back the operator's own
        // storefront cart, the customer's existing one, or (for a second
        // walk-in) the first. The controller manufactures no recognition state
        // of its own: the cart's identity is minted by its owner.
        $editor = CartService::forSubject(CartSubject::operator(new User($customerId), $actor))->createNamedCart();

        if ($editor->cartId() <= 0) {
            $this->emitError('COM_ALFA_CART_BUILDER_CART_NOT_CREATED', 500);

            return;
        }

        $this->emitCart($editor, Text::_('COM_ALFA_CART_BUILDER_CART_CREATED'));
    }

    /**
     * Take over a storefront shopper's cart: the confirmation the builder asks
     * for before its first change to a cart the shopper may still be using.
     *
     * THE CONFIRMATION BELONGS TO THE SCREEN; THE AUTHORITY DOES NOT. Every
     * mutation asks Carts: Edit on a shopper's cart whether or not this ever
     * ran. What this adds is the deliberate act, recorded under the operator's
     * name, after which the screen offers its controls.
     *
     *
     * @since  2.0.0
     */
    public function takeOver(): void
    {
        $actor = $this->guardMutation();
        $cartId = (int) $this->input->getInt('cart_id', 0);

        if ($this->cartSource === null) {
            $this->emitError('COM_ALFA_CART_NOT_FOUND', 404);

            return;
        }

        if (!$this->cartSource->requiresTakeover()) {
            $this->emit(['cartId' => $cartId]);

            return;
        }

        // THE RECORD COMES FIRST, because here it still can. The owner requires
        // a trail for a deliberate takeover, and an unrecorded one is refused
        // rather than performed and regretted.
        CartTakeover::recordOrFail(
            source: $this->cartSource,
            cartId: $cartId,
            actor: $actor,
            operation: 'takeOver',
        );

        CartTakeover::confirm($cartId);

        $this->emit(['cartId' => $cartId], Text::_('COM_ALFA_CART_TAKEOVER_DONE'));
    }

    /**
     * Search the catalogue for a product to add, priced for THIS cart.
     *
     * The search itself is the order screen's, reused; what differs is the
     * pricing context, which is the cart's customer and currency rather than an
     * order's; a named cart has no order row to take a context from. So the
     * price a result shows is the price this customer pays, the same one the
     * storefront would show them.
     *
     *
     * @since  2.0.0
     */
    public function searchProducts(): void
    {
        $this->guardRead();
        $cartId = (int) $this->input->getInt('cart_id', 0);

        if ($this->customerOf($cartId) === null) {
            $this->emitError('COM_ALFA_CART_NOT_FOUND', 404);

            return;
        }

        $results = OrderHelper::searchProducts(
            (string) $this->input->getString('q', ''),
            $this->cartPriceContext($cartId),
            20,
        );

        $this->emit(['cartId' => $cartId, 'results' => $this->projectSearch($results)]);
    }

    /**
     * Add a configured product to the cart.
     *
     * The POST is the same one the storefront add reads (the item, the
     * quantity, and whatever configuration the popup gathered), so the command
     * is assembled by the same request reader and lands through the same
     * {@see \Alfa\Component\Alfa\Site\Service\Cart\CartLineWriter::add()}, which
     * is where the required-field and ShowOn validation lives. The controller
     * builds no line and prices nothing.
     *
     *
     * @since  2.0.0
     */
    public function addLine(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $itemId = (int) $this->input->getInt('item_id', 0);
            $quantity = (int) $this->input->getInt('quantity', 1);
            $command = AddToCartRequest::command($this->input, $itemId, $quantity);
            $result = $editor->add($command);

            if ($result->added) {
                $this->recordTakeover($actor, 'addLine', [
                    'item' => $itemId,
                    'quantity' => $quantity,
                    'line' => (int) $result->lineId,
                ]);
            }

            $this->emitCart(
                $editor,
                $result->added ? '' : Text::_('COM_ALFA_CART_BUILDER_ADD_FAILED'),
                !$result->added,
                ['added' => $result->added, 'lineId' => $result->lineId],
            );
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Set how many of one line the cart holds.
     *
     *
     * @since  2.0.0
     */
    public function setQuantity(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $lineId = (int) $this->input->getInt('line_id', 0);
            $quantity = (int) $this->input->getInt('quantity', 0);
            $result = $editor->setQuantity(lineId: $lineId, quantity: $quantity);

            if ($result->succeeded) {
                $this->recordTakeover($actor, 'setQuantity', ['line' => $lineId, 'quantity' => $quantity]);
            }

            $this->emitCart($editor);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Set or clear a line's staff-negotiated unit price.
     *
     * Mirrors setQuantity() except in the one place it must: the audit goes
     * through recordAlways(), not recordTakeover(). A builder cart records
     * nothing through the takeover recorder, and the custom-price capability
     * exists ONLY on builder carts, so the ordinary path would report success
     * and write no row at all.
     *
     *
     * @since  2.0.0
     */
    public function setLinePrice(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $lineId = (int) $this->input->getInt('line_id', 0);

            // THE ONE DECODER, over the raw post. getInt()/getFloat() cannot
            // express this: they answer 0 for an absent field, for a null and
            // for the string "abc" alike, and those are three different
            // commands: Leave, Clear and a refusal. The two keys are named and
            // read raw: getArray() with no argument uses each posted value as
            // the NAME of its filter, so a posted "int" became a price of 0.
            $command = CustomPriceCommand::fromAdminPost($this->input->post->getArray([
                CustomPriceCommand::ACTION_FIELD => 'raw',
                CustomPriceCommand::FIELD => 'raw',
            ]));

            [$result, $previous, $next] = $editor->setCustomPrice(
                lineId: $lineId,
                command: $command,
                actor: $actor,
            );

            // Only a real mutation is audited. Leave changed nothing, so there
            // is no consequential staff action to record.
            if ($result->succeeded && $command->mutates()) {
                $written = CartTakeover::recordAlways(
                    cartId: (int) $this->input->getInt('cart_id', 0),
                    actor: $actor,
                    operation: 'setLinePrice',
                    facts: [
                        'line' => $lineId,
                        // Named 'mutation', not 'action': the recorder's own
                        // message already carries an 'action' key naming the
                        // operation, and PHP's array union keeps the LEFT key,
                        // so a fact called 'action' is silently dropped.
                        'mutation' => strtolower($command->action->name),
                        // BOTH states, for the audit. The previous one was read
                        // before the write, inside the writer, because it
                        // cannot be reconstructed afterwards.
                        'previous' => $previous,
                        'next' => $next,
                    ],
                );

                if (!$written) {
                    $this->app->enqueueMessage(Text::_('COM_ALFA_CART_MUTATION_NOT_RECORDED'), 'warning');
                }
            }

            $this->emitCart($editor);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Take one line out of the cart.
     *
     *
     * @since  2.0.0
     */
    public function removeLine(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $lineId = (int) $this->input->getInt('line_id', 0);

            // THE ENGINE SAYS WHETHER A LINE WENT. A line id belonging to another
            // cart, or to none, removes nothing, and "removed line #N" must not
            // be recorded about a line that is still there.
            if ($editor->remove($lineId)) {
                $this->recordTakeover($actor, 'removeLine', ['line' => $lineId]);
            }

            $this->emitCart($editor);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * The item popup's quote: the line as the cart will price it.
     *
     * A READ. It prices the posted sel/opt/conf selection at the posted quantity
     * in the CART's own pricing context, carrying the edited line's stored
     * custom price, and answers with the quote's HTML (cart.builder_quote), the
     * Items table's own cells. It
     * computes nothing itself, writes nothing and adds nothing.
     *
     *
     * @since  2.0.0
     */
    public function priceLineConfig(): void
    {
        $this->guardRead();

        try {
            $priced = $this->getModel('Cart')->priceLineConfig(
                itemId: (int) $this->input->getInt('item_id', 0),
                cartId: (int) $this->input->getInt('cart_id', 0),
                lineId: (int) $this->input->getInt('line_id', 0),
                // As typed: a box below 1 is refused by the quote, never quoted as 1.
                quantity: (int) $this->input->getInt('quantity', 0),
            );

            if ($priced === null) {
                $this->emitError('COM_ALFA_CART_BUILDER_ITEM_NOT_FOUND');

                return;
            }

            $this->emit(['html' => LayoutHelper::render('cart.builder_quote', ['price' => $priced], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts')]);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * The methods this cart has settled on, read at the moment of placing.
     *
     * Reconciliation runs as part of settling, so what comes back is not merely
     * what was once chosen but what still stands: a carrier withdrawn since the
     * page was rendered is already gone from the row by the time this answers.
     *
     * @param int $cartId The cart being placed.
     * @param User $actor The operator.
     * @param User $customer The cart's own customer.
     *
     * @return array{shipment: int, payment: int}
     *
     * @since  2.0.0
     */
    private function cartSelection(int $cartId, User $actor, User $customer): array
    {
        $offers = CartService::forSubject(CartSubject::operator($customer, $actor))
            ->editCart($cartId)
            ->snapshot()
            ->offers;

        return [
            'shipment' => (int) $offers->selectedShipmentId,
            'payment' => (int) $offers->selectedPaymentId,
        ];
    }

    /**
     * Load the storefront's catalogue before anything can need it.
     *
     * THE ENGINE SPEAKS THE SHOPPER'S LANGUAGE. A settle can reconcile a method
     * away, and the sentence it produces for that ("no longer available with
     * this shipment") is composed inside the engine, at the moment it decides,
     * from the site catalogue where the checkout that raises it lives. Loading
     * afterwards is too late: the string is already resolved. So it is loaded
     * before the guard returns, on the two doors every endpoint comes through,
     * rather than duplicating the wording into the admin catalogue where it
     * would only get two places to drift apart.
     *
     *
     * @since  2.0.0
     */
    private function speakStorefront(): void
    {
        $this->app->getLanguage()->load('com_alfa', JPATH_SITE);
    }

    /**
     * Leave the item popup without touching the cart.
     *
     * The twin of the order screen's cancelOrderItem: no guard, no editor and
     * no write; abandoning a popup is not a mutation. It answers with the
     * return layout carrying reload=0, so the parent closes the popup and
     * leaves the page exactly as it was.
     *
     *
     * @since  2.0.0
     */
    public function cancelLine(): void
    {
        $this->setRedirect(Route::_(
            'index.php?option=com_alfa&view=cart&layout=builder_return&tmpl=component'
            . '&cart_id=' . (int) $this->input->getInt('cart_id', 0)
            . '&line_id=' . (int) $this->input->getInt('line_id', 0)
            . '&reload=0',
            false,
        ));
    }

    /**
     * Enter a coupon code on the builder cart.
     *
     *
     * @since  2.0.0
     */
    public function applyCoupon(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $code = (string) $this->input->getString('code', '');
            $outcome = $editor->applyCoupon($code);

            // Recorded only where the cart KEPT the code: a refused one left
            // nothing behind to describe.
            if (CouponOutcome::isStored($outcome)) {
                $this->recordTakeover($actor, 'applyCoupon', ['code' => $code, 'outcome' => $outcome]);
            }

            $this->emitCart($editor, '', false, ['outcome' => $outcome]);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Take an entered coupon back off.
     *
     *
     * @since  2.0.0
     */
    public function removeCoupon(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $code = (string) $this->input->getString('code', '');
            $cartId = (int) $this->input->getInt('cart_id', 0);

            // THE ROW IS THE ORACLE. The editor and the rules engine both answer
            // void, so what proves a code went is that the cart no longer holds it.
            $repository = new CartRepository();
            $held = \in_array($code, $repository->loadCouponCodes($cartId), true);

            $editor->removeCoupon($code);

            if ($held && !\in_array($code, $repository->loadCouponCodes($cartId), true)) {
                $this->recordTakeover($actor, 'removeCoupon', ['code' => $code]);
            }

            $this->emitCart($editor);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Point the cart at a saved delivery address and, independently, a billing
     * one.
     *
     * Two explicit ids, never one ambiguous parameter: an absent billing id
     * means "same as delivery" and is written as the delivery id rather than
     * left to a stale pointer. Both must be the customer's own saved rows.
     *
     *
     * @since  2.0.0
     */
    public function pointAddress(): void
    {
        $cartId = (int) $this->input->getInt('cart_id', 0);
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        $deliveryId = (int) $this->input->getInt('delivery_id', 0);
        $invoiceId = (int) $this->input->getInt('invoice_id', 0);

        if ($deliveryId <= 0) {
            $this->emitError('COM_ALFA_CART_BUILDER_DELIVERY_REQUIRED', 400);

            return;
        }

        // The addresses must belong to this cart's customer. The pointer is what
        // the snapshot and placement read a destination from, so accepting a
        // stranger's saved row would ship one customer's order to another's door.
        $customerId = (int) $this->customerOf($cartId);

        if (!$this->addressBelongsTo($deliveryId, $customerId) || ($invoiceId > 0 && !$this->addressBelongsTo($invoiceId, $customerId))) {
            $this->emitError('COM_ALFA_CART_BUILDER_ADDRESS_FORBIDDEN', 403);

            return;
        }

        try {
            $effective = $invoiceId > 0 ? $invoiceId : $deliveryId;
            $repository = new CartRepository();
            $before = $repository->loadCart($cartId);

            $editor->pointAtSavedDetails($deliveryId, $effective);

            // Recorded only where a pointer actually moved: re-pointing a cart at
            // the two addresses it already holds changed nothing.
            $after = $repository->loadCart($cartId);

            if ($before !== null && $after !== null
                && ((int) $before->id_user_info_delivery !== (int) $after->id_user_info_delivery
                    || (int) $before->id_user_info_invoice !== (int) $after->id_user_info_invoice)) {
                $this->recordTakeover($actor, 'pointAddress', ['delivery' => $deliveryId, 'invoice' => $effective]);
            }

            $this->emitCart($editor);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Choose the carrier for the builder cart.
     *
     *
     * @since  2.0.0
     */
    public function chooseShipment(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $shipmentId = (int) $this->input->getInt('shipment_id', 0);
            $cartId = (int) $this->input->getInt('cart_id', 0);

            // THE RECORD DESCRIBES A CHANGE, NOT A WRITE. The editor answers
            // whether the UPDATE ran, and it ran just as truly when the operator
            // re-picked the carrier the cart already held; a second "chose
            // shipment method #N" would describe a choice nobody made. The
            // column is the oracle, read either side, exactly as pointAddress()
            // reads its pointers.
            $repository = new CartRepository();
            $before = (int) ($repository->loadCart($cartId)?->id_shipment ?? 0);

            $result = $editor->chooseShipment(shipmentId: $shipmentId, placeId: $this->postedPlace());

            if ($result->succeeded && (int) ($repository->loadCart($cartId)?->id_shipment ?? 0) !== $before) {
                $this->recordTakeover($actor, 'chooseShipment', ['method' => $shipmentId]);
            }

            $this->emitCart($editor, '', false, [], $result->snapshot);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Choose how to pay for the builder cart.
     *
     *
     * @since  2.0.0
     */
    public function choosePayment(): void
    {
        $actor = $this->guardMutation();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        try {
            $paymentId = (int) $this->input->getInt('payment_id', 0);
            $cartId = (int) $this->input->getInt('cart_id', 0);
            $repository = new CartRepository();
            // The gateway answers the same change test as the carrier above.
            $before = (int) ($repository->loadCart($cartId)?->id_payment ?? 0);

            $result = $editor->choosePayment(paymentId: $paymentId, placeId: $this->postedPlace());

            if ($result->succeeded && (int) ($repository->loadCart($cartId)?->id_payment ?? 0) !== $before) {
                $this->recordTakeover($actor, 'choosePayment', ['method' => $paymentId]);
            }

            $this->emitCart($editor, '', false, [], $result->snapshot);
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * The cart as it stands: a read, for a screen that re-renders.
     *
     *
     * @since  2.0.0
     */
    public function snapshot(): void
    {
        $actor = $this->guardRead();
        $editor = $this->editorForOrFail($actor);

        if ($editor === null) {
            return;
        }

        // A LOOK AT A SHOPPER'S CART DOES NOT SETTLE IT. Reconciliation writes
        // id_shipment and id_payment on the row the shopper is using, and the
        // owner's list names both as mutations. The rule is stated once, in
        // CartTakeover; a builder cart settles as before, and so does a
        // shopper's cart this session has taken over.
        $cartId = (int) $this->input->getInt('cart_id', 0);
        $source = CartTakeover::sourceOf($cartId);
        $settles = $source === null
            || CartTakeover::mayOperate(actor: $actor, cartId: $cartId, source: $source);

        try {
            $this->emitCart($editor, settled: $settles ? null : $editor->reading());
        } catch (Throwable $e) {
            $this->emitThrowable($e);
        }
    }

    /**
     * Where this task decided to send the operator.
     *
     * BaseController keeps the redirect to itself and acts on it during
     * dispatch, which is right for a request and useless to anything that wants
     * to know what the task decided WITHOUT being redirected. Naming it is what
     * lets the outcome be asserted instead of inferred.
     *
     * Protected: every public controller method is an addressable task.
     *
     *
     * @since  2.0.0
     */
    protected function getRedirect(): string
    {
        return (string) $this->redirect;
    }

    /**
     * The four refusals every mutation meets, in order, before it may touch a
     * cart: a POST, a valid token, the permission asked strictly, and (on a
     * cart a storefront shopper created) Carts: Edit.
     *
     * @return User The authenticated, authorised operator.
     *
     * @throws RuntimeException 405 for a non-POST, 403 for a bad token, a
     *                          missing permission, or a shopper's cart this
     *                          operator may not take over.
     *
     * @since  2.0.0
     */
    private function guardMutation(): User
    {
        $this->speakStorefront();

        if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 405);
        }

        if (!Session::checkToken('post')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        $actor = $this->authorisedActor();
        $this->guardTakeover(cartId: (int) $this->input->getInt('cart_id', 0), actor: $actor);

        return $actor;
    }

    /**
     * A read still proves who is asking and that they may: the token (in either
     * verb, since a look is not a write) and the same permission.
     *
     * @return User The authenticated, authorised operator.
     *
     * @throws RuntimeException 403 for a bad token or a missing permission.
     *
     * @since  2.0.0
     */
    private function guardRead(): User
    {
        $this->speakStorefront();

        if (!Session::checkToken('request')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        return $this->authorisedActor();
    }

    /**
     * The operator, once their permission is confirmed: strictly, the carts
     * checkout permission alone, exactly as the placement endpoint asks it.
     *
     *
     * @throws RuntimeException 403 when the permission is absent.
     *
     * @since  2.0.0
     */
    private function authorisedActor(): User
    {
        $actor = $this->app->getIdentity() ?? new User(0);

        if (!AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor)) {
            throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_CHECKOUT_DENIED'), 403);
        }

        return $actor;
    }

    /**
     * A cart a storefront shopper created is changed or placed only by an
     * operator who also holds Carts: Edit. Reads the source the ROW persisted (
     * never the request, the key, the customer id or the screen) and remembers
     * it for the audit record this request may go on to write. A cart that does
     * not exist is left to the endpoint's own refusal.
     *
     * @param int $cartId The cart the request names, or 0.
     * @param User $actor The operator.
     *
     *
     * @throws RuntimeException 403 when the cart is a shopper's and Carts: Edit
     *                          (with Carts: View under it) is absent.
     *
     * @since  2.0.0
     */
    private function guardTakeover(int $cartId, User $actor): void
    {
        $this->cartSource = CartTakeover::sourceOf($cartId);

        if ($this->cartSource !== null && !CartTakeover::authorized(actor: $actor, source: $this->cartSource)) {
            throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_EDIT_DENIED'), 403);
        }
    }

    /**
     * Put an operator's act on a shopper's cart in the User Actions Log; a
     * builder cart records nothing.
     *
     * CALLED ONLY WITH A REAL EFFECT. The engine's own answer decides (a line
     * that was added, a quantity that was set, a line that was removed, a coupon
     * that is gone, a pointer that changed), so the log never describes a
     * request that did nothing.
     *
     * The act has already happened by the time this runs, so it cannot be
     * undone; a record that could not be written is therefore said out loud
     * rather than swallowed. {@see CartTakeover::recordOrFail()} is the other
     * direction, used where the record still comes first.
     *
     * @param User $actor The operator.
     * @param string $operation The task, a key of CartTakeover::OPERATIONS.
     * @param array $facts Scalar details of what was done.
     *
     *
     * @since  2.0.0
     */
    private function recordTakeover(User $actor, string $operation, array $facts = []): void
    {
        if ($this->cartSource === null) {
            return;
        }

        $written = CartTakeover::record(
            source: $this->cartSource,
            cartId: (int) $this->input->getInt('cart_id', 0),
            actor: $actor,
            operation: $operation,
            facts: $facts,
        );

        if (!$written) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_CART_MUTATION_NOT_RECORDED'), 'warning');
        }
    }

    /**
     * The one resolution every editing endpoint shares: the cart named in the
     * request, bound to an editor under its OWN customer and this operator.
     *
     * The customer rule lives here and only here: the customer comes from the
     * cart row, the actor from the signed-in operator, and the request's word
     * for the customer is never read. The permission rule is guardMutation()'s.
     * A cart that does not exist (or was consumed by a placement) has no
     * customer and is refused, consistently, before any mutation is attempted.
     *
     * @param User $actor The authenticated operator.
     *
     * @return NamedCartEditor|null The bound editor, or null once the refusal
     *                              has been emitted.
     *
     * @since  2.0.0
     */
    private function editorForOrFail(User $actor): ?NamedCartEditor
    {
        $cartId = (int) $this->input->getInt('cart_id', 0);
        $customerId = $cartId > 0 ? $this->customerOf($cartId) : null;

        if ($customerId === null) {
            $this->emitError('COM_ALFA_CART_NOT_FOUND', 404);

            return null;
        }

        return CartService::forSubject(CartSubject::operator(new User($customerId), $actor))->editCart($cartId);
    }

    /**
     * The delivery place named on the request, or null to let the cart's own
     * address decide.
     *
     *
     * @since  2.0.0
     */
    private function postedPlace(): ?int
    {
        $place = (int) $this->input->getInt('place_id', 0);

        return $place > 0 ? $place : null;
    }

    /**
     * Emit the authoritative reading of a cart after a change: the response
     * state comes from the settled snapshot, never from a value assembled here.
     *
     * @param NamedCartEditor $editor The cart just changed.
     * @param string $message A message for the operator, or ''.
     * @param bool $error Whether the change did not take.
     * @param array $extra Endpoint-specific facts to merge in.
     * @param CartSnapshot|null $settled The reading the change itself settled,
     *                                   when it took one; null settles here.
     *
     *
     * @since  2.0.0
     */
    private function emitCart(
        NamedCartEditor $editor,
        string $message = '',
        bool $error = false,
        array $extra = [],
        ?CartSnapshot $settled = null,
    ): void {
        // ONE SETTLE PER ACT. A mutation that already settled hands its own
        // reading in: taking a second one would not only repeat the work, it
        // would lose what the first one had to say: reconciliation's notices
        // belong to the settle that produced them, and a fresh settle finds
        // nothing left to drop.
        $snapshot = $settled ?? $editor->snapshot(placeId: $this->postedPlace());

        // THE ENGINE'S OWN SENTENCE SURVIVES THE REDIRECT. Reconciliation may
        // have just dropped a method that stopped being eligible, and it says so
        // - but a notice belongs to the settle that produced it, and the page
        // rendered after a redirect settles again and finds nothing left to
        // report. So a form post carries them across as messages; a scripted
        // caller reads them off the projection it was handed.
        if ($this->input->getWord('form', '') !== '') {
            foreach (['shipment', 'payment'] as $kind) {
                foreach ($snapshot->offers->notices($kind) as $notice) {
                    $this->app->enqueueMessage($notice, 'warning');
                }
            }
        }

        // The authoritative STRUCTURED reading is the whole contract. The screen
        // shows real, server-rendered state by reloading after a mutation (the
        // same way the Order screen re-renders), so the response reconstructs
        // nothing on the client; it reports what happened and the settled cart.
        $this->emit(
            ['cartId' => $editor->cartId(), 'cart' => $this->projectSnapshot($snapshot)] + $extra,
            $message,
            $error,
        );
    }

    /**
     * A caught failure, as a truthful error that leaves the cart as it was.
     *
     * @param Throwable $e The failure.
     *
     *
     * @since  2.0.0
     */
    private function emitThrowable(Throwable $e): void
    {
        $this->emit([], $e->getMessage() !== '' ? $e->getMessage() : Text::_('COM_ALFA_CART_CHECKOUT_FAILED'), true);
    }

    /**
     * The one builder response shape: a JSON envelope whose data carries the
     * cart identity and, for anything that changed or read it, the authoritative
     * snapshot projection.
     *
     * Marked protected so a test can observe what an endpoint decided without
     * the process closing under it; production echoes and stops.
     *
     * @param array $data The response payload.
     * @param string $message A message for the operator.
     * @param bool $error Whether this reports a failure.
     *
     *
     * @since  2.0.0
     */
    protected function emit(array $data, string $message = '', bool $error = false): void
    {
        // ONE mutation, rendered for whoever asked. A form on the screen is a
        // normal browser navigation, so it is answered the way the Order screen
        // answers its own: a redirect, with the outcome enqueued as a message.
        // Every other caller (the builder's reads, the tests) takes the JSON
        // envelope. The guards, the command and the editor above this line are
        // identical either way; only the reply differs.
        $form = $this->input->getWord('form', '');

        if ($form !== '') {
            $this->emitToBrowser($form, $data, $message, $error);

            return;
        }

        echo new JsonResponse($data, $message, $error);

        $this->app->close();
    }

    /**
     * A form's reply: where the operator goes back to, and what they read there.
     *
     * Nothing is decided here; the outcome already happened. Two forms post
     * into this seam and each goes back to where it came from: the item popup
     * ('popup') to its return layout, which closes the modal and re-renders the
     * page; a form on the builder itself ('page') straight back to the builder.
     * A refusal keeps the popup open with the configuration still on screen,
     * rather than closing over a message nobody read.
     *
     * @param string $form Which form posted: 'popup' or 'page'.
     * @param array $data The response payload, for the cart it concerns.
     * @param string $message A message for the operator.
     * @param bool $error Whether this reports a failure.
     *
     *
     * @since  2.0.0
     */
    private function emitToBrowser(string $form, array $data, string $message, bool $error): void
    {
        // THE CART THE MUTATION CONCERNS, which is not always the one posted:
        // minting a builder cart has no cart_id to send and answers with the id
        // it just created, so the operator lands in the cart they asked for.
        $cartId = (int) ($data['cartId'] ?? 0) ?: (int) $this->input->getInt('cart_id', 0);
        $lineId = (int) $this->input->getInt('line_id', 0);

        if ($form !== 'popup') {
            // A form on the builder: back to the builder, with the outcome said
            // out loud. There is nothing to close and nothing to hand back.
            if ($message !== '') {
                $this->app->enqueueMessage($message, $error ? 'error' : 'message');
            }

            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart&id=' . $cartId, false));

            return;
        }

        if ($error) {
            $this->app->enqueueMessage(
                $message !== '' ? $message : Text::_('COM_ALFA_CART_BUILDER_ADD_FAILED'),
                'error',
            );

            $this->setRedirect(Route::_(
                'index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component'
                . '&cart_id=' . $cartId
                . '&id_item=' . (int) $this->input->getInt('item_id', 0)
                . '&line_id=' . $lineId,
                false,
            ));

            return;
        }

        if ($message !== '') {
            $this->app->enqueueMessage($message, 'message');
        }

        // The line id reported back is the one the popup was OPENED on, not the
        // one the mutation produced: it names which modal the parent must close
        // (0 = the Add popup). What the cart now holds is not this page's to
        // describe; the parent re-renders and reads it off the server.
        $this->setRedirect(Route::_(
            'index.php?option=com_alfa&view=cart&layout=builder_return&tmpl=component'
            . '&cart_id=' . $cartId
            . '&line_id=' . $lineId,
            false,
        ));
    }

    /**
     * A refusal with no cart to describe.
     *
     * @param string $messageKey A language key for the reason.
     * @param int $status The HTTP status to answer with.
     *
     *
     * @since  2.0.0
     */
    protected function emitError(string $messageKey, int $status = 400): void
    {
        $this->app->setHeader('status', (string) $status, true);

        $this->emit([], Text::_($messageKey), true);
    }

    /**
     * A cart snapshot as a stable data projection for the builder screen.
     *
     * Every money figure and every offered method is copied straight off the
     * settled snapshot (nothing is recomputed here), so the number a tab shows
     * is the number the engine reached.
     *
     * @param CartSnapshot $snapshot The settled reading.
     *
     *
     * @since  2.0.0
     */
    private function projectSnapshot(CartSnapshot $snapshot): array
    {
        $totals = $snapshot->totals;

        return [
            'cartId' => $snapshot->cartId,
            'exists' => $snapshot->exists(),
            'isEmpty' => $snapshot->isEmpty(),
            'isVirtual' => $snapshot->isVirtual,
            'itemCount' => $snapshot->itemCount,
            'quantity' => $snapshot->quantity,
            'customerId' => (int) $snapshot->customer->id,
            'placeId' => $snapshot->placeId,
            'currency' => $snapshot->currency->toArray(),
            'totals' => [
                'goods' => $totals->goods->toArray(),
                'goodsExcl' => $totals->goodsExcl->toArray(),
                'discount' => $totals->discount->toArray(),
                'tax' => $totals->tax->toArray(),
                'shipment' => $totals->shipment->toArray(),
                'grand' => $totals->grand->toArray(),
                'grandExcl' => $totals->grandExcl->toArray(),
            ],
            'coupons' => array_values($snapshot->rules->codes),
            'lines' => array_map([$this, 'projectLine'], $snapshot->lines),
            'offers' => [
                'hasShipments' => $snapshot->offers->hasShipments,
                'hasPayments' => $snapshot->offers->hasPayments,
                'selectedShipmentId' => $snapshot->offers->selectedShipmentId,
                'selectedPaymentId' => $snapshot->offers->selectedPaymentId,
                'shipments' => $this->projectMethods($snapshot->offers->shipments),
                'payments' => $this->projectMethods($snapshot->offers->payments),
            ],
        ];
    }

    /**
     * One cart line, as the fields the builder reads, and its OWN priced
     * total, taken from the line rather than multiplied here.
     *
     * The unit is the one the builder's tables print (the cart's line rule:
     * the agreed rate as agreed, any other unit the line per piece), published
     * as an exact decimal string, never the money of one piece; the total and
     * the saving are Money as exact decimal strings.
     *
     * @param object $line A hydrated cart line.
     *
     *
     * @since  2.0.0
     */
    private function projectLine(object $line): array
    {
        $price = $line->data->price ?? null;

        return [
            'id' => (int) ($line->id ?? 0),
            'itemId' => (int) ($line->id_item ?? 0),
            'name' => (string) ($line->data->name ?? ''),
            'quantity' => (int) ($line->quantity ?? 0),
            'unavailable' => !empty($line->unavailable),
            'unavailableReason' => (string) ($line->unavailable_reason ?? ''),
            'unit' => $price instanceof PriceResult ? PriceUnits::publishedLine(price: $price, withTax: true) : null,
            'lineTotal' => $price?->getTotal() instanceof Money ? $price->getTotal()->toArray() : null,
            // The two custom-price facts, so the JSON the builder re-renders
            // from carries what the server-rendered table shows. Copied off the
            // settled result, never recomputed: the same rule as every other
            // figure in this projection.
            'hasCustomPrice' => (bool) $price?->hasExplicitPrice(),
            'customPriceSaving' => $price?->hasExplicitPrice()
                ? $price->getLineSavingWithTax()->toArray()
                : null,
        ];
    }

    /**
     * Offered methods keyed by id, projected to what a picker renders.
     *
     * @param array $offered Method objects keyed by id.
     *
     *
     * @since  2.0.0
     */
    private function projectMethods(array $offered): array
    {
        $out = [];

        foreach ($offered as $id => $method) {
            $out[] = [
                'id' => (int) ($method->id ?? $id),
                'name' => (string) ($method->name ?? ''),
                'description' => (string) ($method->description ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The catalogue search results, projected to the fields a result row shows,
     * the price among them the one this cart's customer pays.
     *
     * Each price is the exact unit of the item's priced result, published as an
     * exact decimal string ({@see PriceUnits::published()}); the tax rate its
     * decimal text. An item with no priced result states no price.
     *
     * @param array $results Item objects as OrderHelper priced them.
     *
     *
     * @since  2.0.0
     */
    private function projectSearch(array $results): array
    {
        $out = [];

        foreach ($results as $item) {
            $price = ($item->price_result ?? null) instanceof PriceResult ? $item->price_result : null;
            // At quantity one the unit with tax is what the customer pays for one piece.
            $final = $price === null ? null : PriceUnits::published(price: $price, figure: 'final', text: '')['amount'];

            $out[] = [
                'id' => (int) $item->id,
                'name' => (string) ($item->name ?? ''),
                'sku' => (string) ($item->sku ?? ''),
                'gtin' => (string) ($item->gtin ?? ''),
                'mpn' => (string) ($item->mpn ?? ''),
                'stock' => (float) ($item->stock ?? 0),
                'price_tax_incl' => $final,
                'price_tax_excl' => $price === null ? null : PriceUnits::published(price: $price, figure: 'subtotal', text: '')['amount'],
                'customer_price' => $final,
                'has_discount' => (bool) ($item->has_discount ?? false),
                'tax_rate' => $price === null ? null : PublishedDecimal::decimal(value: ExactAmount::ofNumber($price->getTaxes()->getEffectiveRate())),
            ];
        }

        return $out;
    }

    /**
     * A price context that prices for THIS cart's customer and currency, so a
     * search shows what the customer pays, never the operator's session or an
     * order's context.
     *
     * @param int $cartId The cart.
     *
     *
     * @throws RuntimeException When the cart does not exist or names no currency.
     *
     * @since  2.0.0
     */
    private function cartPriceContext(int $cartId): PriceContext
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_customer'), $db->quoteName('id_currency')])
                ->from($db->quoteName('#__alfa_cart'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $cartId, ParameterType::INTEGER),
        )->loadObject();

        // No substitute context (the no-fallback rule): a cart that does not
        // exist, or names no currency, is refused, as the cart line refuses it
        // (CartSubject::priceContext()).
        if (!$row) {
            throw new RuntimeException(Text::_('COM_ALFA_CART_NOT_FOUND'));
        }

        if ((int) $row->id_currency <= 0) {
            throw new RuntimeException('Cart ' . $cartId . ' names no currency, so it cannot be priced.');
        }

        // Priced as the cart's customer, by the rule the cart line follows; a
        // cart with no account to load (a walk-in guest, or an account since
        // deleted) prices as the anonymous visitor.
        return PriceContext::forIndex(currencyId: (int) $row->id_currency, placeId: 0, usergroupId: 0)
            ->withCustomer(customer: new User(identifier: (int) $row->id_customer));
    }

    /**
     * Whether a saved address row belongs to a customer (or is a guest row on a
     * guest cart).
     *
     * @param int $addressId The user_info row.
     * @param int $customerId The cart's customer, 0 for a guest.
     *
     *
     * @since  2.0.0
     */
    private function addressBelongsTo(int $addressId, int $customerId): bool
    {
        if ($addressId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $owner = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_user'))
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $addressId, ParameterType::INTEGER),
        )->loadResult();

        return $owner !== null && (int) $owner === $customerId;
    }

    /**
     * Whether a user id names a real account.
     *
     * @param int $userId The candidate customer.
     *
     *
     * @since  2.0.0
     */
    private function customerExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $found = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $userId, ParameterType::INTEGER),
        )->loadResult();

        return $found !== null;
    }

    /**
     * Say why, in the operator's language, and go back to the cart.
     *
     * The reasons arrive as keys and parameters rather than as sentences, so
     * nothing here reads an exception's words or matches on a message. A key
     * with no translation shows as itself, which is a missing string rather
     * than a silent failure.
     *
     * @param int $cartId The cart to return to.
     * @param array $failures The structured reasons.
     *
     *
     * @since  2.0.0
     */
    private function refuse(int $cartId, array $failures): void
    {
        $said = false;

        foreach ($failures as $failure) {
            $sentence = $this->operatorReason((array) $failure);

            if ($sentence !== '') {
                $this->app->enqueueMessage($sentence, 'warning');
                $said = true;
            }
        }

        // A refusal is never an empty queue: with no reason, or none that has
        // words (a plugin that vetoed with an empty message), the operator is
        // still told the order was not placed.
        if (!$said) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_CART_CHECKOUT_FAILED'), 'warning');
        }

        $this->setRedirect(
            $cartId > 0
                ? 'index.php?option=com_alfa&view=cart&id=' . $cartId
                : 'index.php?option=com_alfa&view=carts',
        );
    }

    /**
     * One placement reason, as the operator reads it.
     *
     * Rendered from its key AND its parameters. An operator placement queues
     * nothing of its own, so the parameters are the only copy of which product,
     * field or plugin refused; the key alone is half a sentence with a bare %s
     * in it. Every parameter is escaped because the message queue prints HTML
     * and a product name or a plugin's words are data. A list parameter (the
     * after-place hooks) is joined as the storefront joins it: Text::sprintf()
     * would read a trailing array as its options.
     *
     * The text decides whether the parameters are used. Several reasons carry
     * metadata their sentence never shows (a method id, an order id), and a
     * form rule's message travels as its own code with none; sprintf() over
     * such a text reads a literal % as a directive and garbles it or throws,
     * on the warning path after the order is committed. So only a text with a
     * Text::sprintf() placeholder (%s, %d, %1$s, or Joomla's [[%1:name]]) takes
     * them.
     *
     * @param array $reason A placement failure or warning: ['code' => key, 'params' => [...]].
     *
     * @return string The sentence, or '' when the reason names no key.
     *
     * @since  2.0.0
     */
    private function operatorReason(array $reason): string
    {
        $code = (string) ($reason['code'] ?? '');

        if ($code === '') {
            return '';
        }

        // A plugin's veto is framed for staff; a veto with no words has nothing to frame, and the
        // refusal then says only that the order was not placed.
        if ($code === 'COM_ALFA_ORDER_PLACE_PLUGIN_REFUSED' && trim((string) (((array) ($reason['params'] ?? []))['message'] ?? '')) === '') {
            return '';
        }

        // A quantity that is not charged is said as the storefront says it: the
        // product, then the one sentence, with the quantity it names.
        if ($code === ChargeableQuantity::TOO_SMALL || $code === ChargeableQuantity::NEVER) {
            $params = (array) ($reason['params'] ?? []);

            return '"' . htmlspecialchars((string) ($params['line'] ?? ''), ENT_QUOTES, 'UTF-8') . '": '
                . ChargeableQuantity::sentence(smallest: $code === ChargeableQuantity::TOO_SMALL ? (int) ($params['quantity'] ?? 0) : null);
        }

        $key = self::OPERATOR_WORDING[$code] ?? $code;
        $text = Text::_($key);

        if (!preg_match('/%(\d+\$)?[sdfu]|\[\[%\d+:[^\]]*\]\]/', $text)) {
            return $text;
        }

        $params = array_map(
            static fn ($value): string => htmlspecialchars(
                \is_array($value) ? implode(', ', $value) : (string) $value,
                ENT_QUOTES,
                'UTF-8',
            ),
            array_values((array) ($reason['params'] ?? [])),
        );

        // A placeholder that arrived with nothing to fill it stays visible as a
        // gap in the sentence; sprintf() would throw on it instead.
        return $params === [] ? $text : Text::sprintf($key, ...$params);
    }

    /**
     * Whose cart this is: the account, 0 for a guest, or null if there is no
     * such cart.
     *
     * @param int $cartId The cart.
     *
     *
     * @since  2.0.0
     */
    private function customerOf(int $cartId): ?int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id_customer'))
            ->from($db->quoteName('#__alfa_cart'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $cartId, ParameterType::INTEGER);

        $found = $db->setQuery($query)->loadResult();

        return $found === null ? null : (int) $found;
    }

    /**
     * The language the customer is written to in.
     *
     * Never the back office's. An operator switching their own interface to
     * another language must not change what language a customer's receipt
     * arrives in, so the account decides, and a guest gets the site's default.
     *
     * @param int $customerId The customer, or 0 for a guest.
     *
     * @return string|null A language tag, or null to let placement decide.
     *
     * @since  2.0.0
     */
    private function languageFor(int $customerId): ?string
    {
        if ($customerId > 0) {
            $chosen = (string) (new User($customerId))->getParam('language', '');

            if ($chosen !== '') {
                return $chosen;
            }
        }

        $site = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', '');

        return $site !== '' ? $site : null;
    }
}
