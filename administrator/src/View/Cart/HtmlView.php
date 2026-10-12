<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Cart;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartActivity;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSource;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartTakeover;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\User\User;
use RuntimeException;

/**
 * One cart, worked in the order builder.
 *
 * A cart a storefront shopper created stays theirs. The screen draws its
 * mutating controls only after the operator has deliberately taken it over, and
 * every endpoint asks Carts: Edit for it regardless: the confirmation is a UX
 * safeguard, not the authority ({@see CartTakeover}). The toolbar carries the
 * Back link and, on a shopper's cart the operator may take over, that one
 * confirmation.
 *
 * @since  1.10.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('cart').
     * @since  1.10.0
     */
    protected string $alfaEntity = 'cart';

    /**
     * @var object|null The cart, with its lines, its codes and its owner.
     * @since  1.10.0
     */
    protected $item;

    /**
     * @var int Hours of silence after which a cart with lines reads as abandoned.
     * @since  1.10.0
     */
    protected int $abandonedAfterHours = CartActivity::ABANDONED_AFTER_HOURS;

    /**
     * @var bool May this operator place carts on a customer's behalf?
     * @since  1.10.0
     */
    protected bool $canCheckout = false;

    /**
     * @var bool May this operator add and edit the customer's addresses? The
     *           address editor saves through the User screen's endpoints, which ask users edit.
     * @since  1.10.0
     */
    protected bool $canEditAddresses = false;

    /**
     * @var bool Was this cart created by a storefront shopper?
     * @since  1.10.0
     */
    protected bool $isShopperCart = false;

    /**
     * @var bool May the operator take it over, and have they not yet?
     * @since  1.10.0
     */
    protected bool $canTakeOver = false;

    /**
     * @var bool Has this session confirmed taking it over?
     * @since  1.10.0
     */
    protected bool $takenOver = false;

    /**
     * @var bool Are the builder's mutating controls drawn for this cart?
     * @since  1.10.0
     */
    protected bool $canOperate = false;

    /**
     * Whether this operator may set a custom price on this cart's lines.
     *
     * Drawing only. Held false while the order line cannot record that a price
     * was overridden; the pipeline beneath it is built, proven and audited.
     *
     *
     * @since  1.11.0
     */
    protected bool $canPriceCustom = false;

    /**
     * @var object[] The statuses a new order may land in, for the operator to
     *               choose from: the merchant's own, narrowed by OrderCreator.
     * @since  1.10.0
     */
    protected array $placementStatuses = [];

    /**
     * @var \Joomla\CMS\Form\Form|false The builder form: its only job is to
     *                                  carry plugin-contributed fieldsets as tabs, through onAlfaAdminForm.
     * @since  1.10.0
     */
    protected $form = false;

    /**
     * @var int The live cart this builder edits: the id the tabs and the
     *          B1B.1c endpoints operate on.
     * @since  1.10.0
     */
    protected int $builderCartId = 0;

    /**
     * @var \Alfa\Component\Alfa\Site\Service\Cart\CartSnapshot|null The priced,
     *                                                               settled reading the Items tab renders its lines and totals from.
     * @since  1.10.0
     */
    protected $builderSnapshot = null;

    /**
     * @var object|null The product being configured in the modal layout, priced
     *                  for the cart's customer.
     * @since  1.10.0
     */
    protected $configItem = null;

    /**
     * @var array<int, object[]> Each line's chosen configuration, already
     *                           resolved to names by the cart read model (R.1H), keyed by line id:
     *                           the ONE semantic source both the Summary and Items tabs print from.
     * @since  1.10.0
     */
    protected array $builderSelections = [];

    /**
     * @var object[] The line whose configuration the selection sub-template is
     *               printing right now; the caller sets it and loads that template.
     * @since  1.10.0
     */
    protected array $lineSelections = [];

    /**
     * @var object|null The cart line the item popup is re-configuring, or null
     *                  when the popup is adding.
     * @since  1.10.0
     */
    protected $configLine = null;

    /**
     * @var object|null Who the cart belongs to, their saved addresses, and the
     *                  two addresses it currently points at.
     * @since  1.10.0
     */
    protected $builderCustomer = null;

    /**
     * @var \Joomla\CMS\Form\Form|null The placement form's delivery and invoice
     *                                 buckets, seeded from the addresses the cart points at.
     * @since  1.10.0
     */
    protected $checkoutForm = null;

    /**
     * @var int The catalog item chosen in the popup's search but not yet added
     *          (the configure-first state the Order screen's popup also has).
     * @since  1.10.0
     */
    protected int $newItemId = 0;

    /**
     * @param string|null $tpl The layout.
     *
     * @return void
     *
     * @since  1.10.0
     */
    public function display($tpl = null)
    {
        // The item popup and its return page are standalone layouts on this
        // view, loaded into Joomla's own modal, the same convention, and the
        // same three-step shape, as the Order screen's item popup: the popup
        // configures, the controller mutates, the return page tells the parent
        // to close and re-render.
        if (\in_array($this->getLayout(), ['builder_line', 'builder_return'], true)) {
            $this->displayBuilderLine();

            return;
        }

        // The methods tab prints the engine's own notices about a method it had
        // to drop, and those sentences are the storefront's, stated as a
        // dependency, never copied into the admin catalogue.
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_SITE);

        $this->item = $this->get('Item');

        if ($this->item === null) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_CART_NOT_FOUND'), 'warning');
            Factory::getApplication()->redirect('index.php?option=com_alfa&view=carts');

            return;
        }

        // THE FORM IS BUILT ONLY FOR SOMEBODY WHO MAY USE IT. Hiding it is not
        // what stops anybody (the task checks the same permission again, and a
        // crafted POST never passes through here at all), but a control that
        // leads to a refusal is worse than no control.
        $actor = Factory::getApplication()->getIdentity() ?? new User(0);
        $this->canCheckout = AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor);
        $this->canEditAddresses = AlfaHelper::can(section: 'users', action: 'edit', user: $actor);
        // The statuses this order could land in. Nothing else is needed: the
        // placement card states what the cart already holds and asks only for
        // placement intent, so there is no second, unsettled reading of the
        // cart to take.
        $this->placementStatuses = $this->canCheckout ? $this->getModel()->getPlacementStatuses() : [];

        $this->builderCartId = (int) $this->item->id;

        // A SHOPPER'S CART IS WORKED ONLY ONCE IT IS TAKEN OVER. The source the
        // row persisted decides it; these flags only decide which controls are
        // drawn, and the rule behind them is stated once, in CartTakeover.
        $takeover = CartTakeover::screen(
            actor: $actor,
            cartId: $this->builderCartId,
            source: CartSource::fromStored($this->item->source ?? null),
        );
        $this->isShopperCart = $takeover->isShopperCart;
        $this->canTakeOver = $takeover->canTakeOver;
        $this->takenOver = $takeover->takenOver;
        $this->canOperate = $takeover->canOperate;
        // DRAWING ONLY, like the four above it. Server-side authority is
        // authoritative and is asked again at the write boundary: hiding the
        // field in the UI is never relied on.
        $this->canPriceCustom = $takeover->canPriceCustom;

        // The Customer tab reads identity from the cart ROW and the address book
        // from its one owner, never from the request, and never from a store of
        // its own.
        $this->builderCustomer = $this->getModel()->getBuilderCustomer($this->builderCartId, $actor);

        // The placement form carries the merchant's REAL checkout fields, seeded
        // from the addresses the cart points at, so the order freezes the
        // address the operator chose, not whatever a hand-listed input held.
        $this->checkoutForm = $this->canCheckout
            ? $this->getModel()->getCheckoutForm($this->builderCartId, $actor)
            : null;

        // THE FORM CARRIES NO RECORD, only plugin tabs. A plugin adds a
        // <fieldset> through onAlfaAdminForm for entity 'cart', and the template
        // renders each as its own tab: the same seam the Order and Item screens
        // use. The builder itself mutates the live cart through the request
        // seam, so the form is never submitted or saved.
        $builder = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('CartBuilder', 'Administrator', ['ignore_request' => true]);
        $this->form = $builder ? $builder->getForm() : false;

        // The Items tab renders its lines and totals from the priced, settled
        // snapshot (the same authoritative reading the request seam returns),
        // built only for an operator who may work the cart.
        if ($this->canCheckout) {
            // The page is handed in so the method panels' code is declared from
            // the same opened cart the reading comes from: the contract the
            // storefront cart honours, and one hydration rather than two.
            //
            // A SHOPPER'S CART IS NOT SETTLED BY BEING LOOKED AT. Settling
            // reconciles, and reconciliation writes id_shipment and id_payment on
            // the row the shopper is still using, which the owner's list calls a
            // mutation. Until the cart has been taken over the reading is taken
            // without settling; a builder cart settles exactly as before.
            $this->builderSnapshot = $this->getModel()->getBuilderSnapshot(
                cartId: $this->builderCartId,
                actor: $actor,
                page: $this->getDocument(),
                settle: $this->canOperate,
            );
        }

        // The chosen configuration of each line, taken from the read model that
        // already resolved every field and value to a name, so the Items tab
        // prints exactly what the Summary tab prints, from one source.
        foreach ($this->item->lines as $line) {
            $this->builderSelections[(int) $line->id] = $line->selections ?? [];
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The product-configuration modal body: the item's own field surfaces (
     * variants, options, item fields, ShowOn), priced for the cart's customer,
     * rendered standalone for the iframe modal. It mutates nothing; opening it
     * is a look, and the add it leads to goes through the request seam.
     *
     *
     * @since  1.10.0
     */
    protected function displayBuilderLine(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $actor = $app->getIdentity() ?? new User(0);

        // A read, but a privileged one: the same permission the mutations ask,
        // asked here so the popup is never rendered for someone who could not
        // then add its result.
        if (!AlfaHelper::can(section: 'carts', action: 'checkout', user: $actor)) {
            throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_CHECKOUT_DENIED'), 403);
        }

        $this->builderCartId = (int) $input->getInt('cart_id', 0);

        // The same rule the mutations ask: a cart a storefront shopper created
        // needs Carts: Edit too, so the popup is never rendered for an operator
        // who could not then add its result.
        $source = CartTakeover::sourceOf($this->builderCartId);

        if ($source !== null && !CartTakeover::authorized(actor: $actor, source: $source)) {
            throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_EDIT_DENIED'), 403);
        }

        $lineId = (int) $input->getInt('line_id', 0);

        // The return page reports an outcome; it configures nothing.
        if ($this->getLayout() === 'builder_return') {
            $this->configLine = (object) ['id' => $lineId];

            parent::display();

            return;
        }

        // The quote renders the Items table's own cells, whose custom-price label
        // is the storefront's: loaded here as the builder page loads it (:207).
        $app->getLanguage()->load(extension: 'com_alfa', basePath: JPATH_SITE);

        $this->configLine = null;
        $this->configItem = null;
        $this->newItemId = 0;

        if ($lineId > 0) {
            // RE-CONFIGURE: the popup opens on what the shopper actually chose.
            $config = $this->getModel()->getBuilderLineConfig($lineId, $this->builderCartId);
            $this->configLine = $config->line ?? (object) ['id' => $lineId, 'quantity' => 0];
            $this->configItem = $config->item ?? null;

            // A STRANDED line: its item is gone or unpublished, so there is
            // nothing to configure and nothing that may be re-added. The popup
            // still opens, because the one action left is removing it, and the
            // toolbar below offers exactly that: Delete and Cancel, no Save.
        } elseif (($itemId = (int) $input->getInt('id_item', 0)) > 0) {
            // CONFIGURE-FIRST: an item was picked in the popup's own search and
            // the popup reloaded on it. Nothing is in the cart yet.
            $this->configItem = $this->getModel()->getBuilderItemConfig($itemId, $this->builderCartId);
            $this->newItemId = $this->configItem !== null ? $itemId : 0;
        }

        $this->addBuilderLineToolbar();

        // The layout is already 'builder_line' (from the request): render it as
        // the view's main template; passing it again would look for
        // builder_line_builder_line.php.
        parent::display();
    }

    /**
     * The item popup's toolbar: Save, Delete, Cancel.
     *
     * The same three buttons, in the same order, driving the same kind of tasks
     * as the Order screen's item popup. Delete appears only where there is a
     * line to delete, and every button is a plain form submit: the popup posts,
     * the controller decides, the return page closes it.
     *
     *
     * @since  1.10.0
     */
    protected function addBuilderLineToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();
        $isNew = $this->configLine === null;

        ToolbarHelper::title(
            Text::_($isNew ? 'COM_ALFA_CART_BUILDER_LINE_NEW' : 'COM_ALFA_CART_BUILDER_LINE_EDIT'),
            'cart',
        );

        // Nothing is chosen yet: the popup is a search, and there is nothing to
        // save. Offering Save there would submit an item id of zero.
        if ($this->configItem !== null) {
            $toolbar->save('cart.addLine');
        }

        if (!$isNew) {
            $toolbar->delete('cart.removeLine')
                ->text('JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE');
        }

        $toolbar->cancel('cart.cancelLine');
    }

    /**
     * One button, and it goes back.
     *
     *
     * @since  1.10.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::sprintf('COM_ALFA_CART_TITLE', (int) $this->item->id), 'cart');

        // A LINK, not a cancel task. Cancel exists to release a checked-out
        // record and return; nothing here is ever checked out, so a task (and
        // the controller to receive it) would be machinery for a hyperlink.
        $this->getDocument()->getToolbar()
            ->linkButton('back')
            ->text(Text::_('JTOOLBAR_BACK'))
            ->url(Route::_('index.php?option=com_alfa&view=carts'))
            ->icon('icon-arrow-left');

        // THE ONE WAY INTO A SHOPPER'S CART: a confirmation in Joomla's own
        // dialog, whose button posts cart.takeOver. Drawn only for an operator
        // the task would accept, and only until this session has confirmed.
        if ($this->canTakeOver) {
            $this->getDocument()->getToolbar()
                ->popupButton('takeover', 'COM_ALFA_CART_TAKEOVER_BUTTON')
                ->popupType('inline')
                ->icon('icon-edit')
                ->buttonClass('btn btn-warning')
                ->textHeader(Text::_('COM_ALFA_CART_TAKEOVER_HEADER'))
                ->url('#joomla-dialog-takeover')
                ->modalWidth('600px')
                ->modalHeight('fit-content')
                ->listCheck(false);
        }
    }
}
