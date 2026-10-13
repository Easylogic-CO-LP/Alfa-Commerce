<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a single Order.
 * We directly here extend the BaseHtmlView instead of FormView to directly handle all the layout and data
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    protected $state;

    protected $order;

    protected $form;

    protected $cartForm;

    /**
     * Whether the order line's popup shows the sold configuration read-only: the catalogue can no longer draw
     * what the line sold.
     *
     * @since  2.0.0
     */
    public bool $soldReadOnly = false;

    /**
     * What a refused order-line Save posted, for the popup's kept=1 redisplay
     * (OrderController::saveOrderItem); empty on any other open.
     *
     * @since  2.0.0
     */
    public array $keptPost = [];

    /**
     * Why the order shows no money and offers no action: the sentence the
     * screen of an order that names no currency states. Empty on every other
     * order.
     *
     * @since  2.0.0
     */
    public string $currencyRefusal = '';

    /**
     * The name of the order's status, for the screen of an order that names no
     * currency.
     *
     * @since  2.0.0
     */
    public string $statusName = '';

    /**
     * Every layout of this view is a facet of the order EDIT screen (the popup layouts
     * included), so the contract sees one screen identity throughout.
     *
     *
     * @since  2.0.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_EDIT;
    }

    /**
     * Render the order view, branching on the current layout: the
     * edit_shipment / edit_payment / edit_order_item layouts (and their
     * *_return variants) load the relevant sub-record plus its form and a
     * dedicated toolbar; otherwise the main order is loaded, checked out for
     * editing and its activity log fetched for the History tab.
     *
     * @param string|null $tpl The name of the template file to parse
     *
     * @return void
     *
     * @throws Exception When a requested shipment/payment/order item or order is missing, or an id is required
     *
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        // The trait owns the $alfaEntity property; redeclaring it here is a composition
        // fatal, so the entity is assigned, not declared (the AlfaHtmlView/AlfaFormView
        // pilots inherit it from a base class and may redeclare freely).
        $this->alfaEntity = 'order';

        $model = $this->getModel();

        $this->state = $model->getState();

        $app = Factory::getApplication();
        $input = $app->getInput();

        if ($this->_layout == 'edit_shipment' || $this->_layout == 'edit_shipment_return') {
            $shipmentId = $input->getInt('id', 0);
            $orderId = $input->getInt('id_order', 0);

            $this->shipment = null;
            $this->order = null;

            if ($shipmentId > 0) {
                $this->shipment = $model->getShipmentData($shipmentId);
                if (!$this->shipment) {
                    throw new Exception('Shipment not found', 404);
                }
                $this->order = $model->getItem((int) $this->shipment->id_order);
            } else {
                if ($orderId <= 0) {
                    throw new Exception('Order ID is required when creating a shipment', 400);
                }
                $this->order = $model->getItem($orderId);
            }

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }

            $this->form = $model->getShipmentForm($this->shipment ?? []);
            // What a refused Save kept, bound over the stored row on the
            // kept=1 redisplay only (OrderController::saveShipment).
            $this->form->bind($this->keptModalDraft('com_alfa.edit.order.shipment.' . $orderId . '.' . $shipmentId));
            self::addShipmentToolbar();
        } elseif ($this->_layout == 'edit_payment' || $this->_layout == 'edit_payment_return') {
            $paymentId = $input->getInt('id', 0);
            $orderId = $input->getInt('id_order', 0);

            $this->payment = null;
            $this->order = null;

            if ($paymentId > 0) {
                $this->payment = $model->getPaymentData($paymentId);
                if (!$this->payment) {
                    throw new Exception('Payment not found', 404);
                }
                $this->order = $model->getItem((int) $this->payment->id_order);
            } else {
                if ($orderId <= 0) {
                    throw new Exception('Order ID is required when creating a payment', 400);
                }
                $this->order = $model->getItem($orderId);
            }

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }

            $this->form = $model->getPaymentForm($this->payment ?? []);
            // What a refused Save kept, bound over the stored row on the
            // kept=1 redisplay only (OrderController::savePayment).
            $this->form->bind($this->keptModalDraft('com_alfa.edit.order.payment.' . $orderId . '.' . $paymentId));
            self::addPaymentToolbar();
        } elseif ($this->_layout == 'edit_replacement') {
            // Void & Replace, previewed. Its own request, in an iframe, like
            // every other order popup, and refused outright to anyone
            // without the Void & Replace capability, before anything is built.
            $orderId = $input->getInt('id_order', 0);
            $sourceId = $input->getInt('id_source', 0);
            $user = $this->getCurrentUser();

            if (!AlfaHelper::can(
                section: 'documents',
                action:  \Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies::CAPABILITY_REPLACE,
                user:    $user,
            )) {
                throw new Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $this->order = $model->getItem($orderId);

            if (!$this->order || $sourceId <= 0) {
                throw new Exception('Order and source document are required to replace a document', 400);
            }

            // The source must be one of THIS order's documents: the modal shows
            // this order and replaces that document, and the two must agree.
            $sourceRow = \Alfa\Component\Alfa\Administrator\Helper\DocumentHelper::getById($sourceId);

            if ($sourceRow !== null && (int) $sourceRow->id_order !== $orderId) {
                throw new Exception('The document to replace does not belong to this order', 400);
            }

            $this->sourceDocumentId = $sourceId;

            // What the operator last previewed or confirmed for this source:
            // the buyer edits and the reason (OrderController keeps them). The
            // proposal is built WITH them, so the preview, its validation and
            // its fingerprint are the issuer's answer for exactly those values.
            // Read back ONLY on the redisplay the controller asked for
            // (kept=1). Any other open (the documents panel's button, after
            // the modal was closed with its X, Esc or the backdrop, none of
            // which reach cancelReplacement) starts from the frozen source
            // and forgets an abandoned edit.
            $stateKey = 'com_alfa.edit.order.replacement.' . $sourceId;

            if ($input->getInt('kept', 0) !== 1) {
                $app->setUserState($stateKey, null);
            }

            $kept = (array) $app->getUserState($stateKey, []);
            $this->replacementReason = (string) ($kept['reason'] ?? '');
            $this->replacement = \Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace::propose(
                $sourceId,
                $user,
                \is_array($kept['buyer'] ?? null) ? $kept['buyer'] : null,
            );

            // A credit note's replacement is made out to the buyer of the
            // document it corrects and is never edited:
            // the modal shows that buyer read-only.
            $this->replacementCredit = $this->replacement->source !== null
                && \Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace::isCredit($this->replacement->source);

            // The buyer is editable wherever editing it can change the answer:
            // on an offer, and on a refusal of the buyer itself (by the
            // issuer, or an edited email that is not an address), which must
            // stay correctable. Never on a credit note's replacement, nor when
            // the source's frozen buyer cannot be read (REASON_BUYER_UNAVAILABLE):
            // a blank block offered for editing would drop the carried address.
            $this->buyerEditable = $this->replacement->source !== null
                && !$this->replacementCredit
                && ($this->replacement->available
                    || \in_array($this->replacement->reason, [
                        \Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace::REASON_ISSUER_REFUSED,
                        \Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace::REASON_INVALID_BUYER_EMAIL,
                    ], true));

            // The canonical field definitions of the issue form, bound ONLY to
            // the replacement's own buyer block (the source's, or the edit),
            // never to the current order, which getDocumentForm() would read.
            $this->buyerForm = Factory::getContainer()->get(\Joomla\CMS\Form\FormFactoryInterface::class)
                ->createForm('com_alfa.order.replacement_buyer', ['control' => '']);
            $this->buyerForm->loadFile(JPATH_ADMINISTRATOR . '/components/com_alfa/forms/document.xml');
            $this->buyerForm->bind(array_intersect_key(
                $this->replacement->buyer,
                array_flip(\Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace::EDITABLE_BUYER),
            ));

            self::addReplacementToolbar($this->replacement->available, $this->buyerEditable);

            parent::display($tpl);

            return;
        } elseif ($this->_layout == 'edit_correction') {
            // The proposed incremental correction, previewed. Its own request,
            // in an iframe, like every other order popup.
            $orderId = $input->getInt('id_order', 0);

            if ($orderId <= 0) {
                throw new Exception('Order ID is required to propose a correction', 400);
            }

            $user = $this->getCurrentUser();

            // The same pair manual issuance answers to: a correction is an
            // ordinary issuance of an ordinary document.
            if (!AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
                || !AlfaHelper::can(section: 'documents', action: 'create', user: $user)) {
                throw new Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $this->order = $model->getItem($orderId);

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }

            // Proposed HERE, not in the template: the panel and its layouts
            // stay presentation only, and the proposal is one derivation.
            $this->sourceDocumentId = $input->getInt('id_source', 0);
            $this->proposal = \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::propose(
                $orderId,
                $this->sourceDocumentId,
                $user,
            );

            // With a source named, the modal also shows EVERY strategy for it,
            // available or not, with its reason: the reconciliation surface's
            // pattern: nothing the operator is looking for is silently absent.
            $this->strategies = $this->sourceDocumentId > 0
                ? \Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies::forDocument($this->sourceDocumentId, $user)
                : [];

            // A buyer the type's requirements are not met by, a credit
            // unavailable on this order, and a type that cannot be chosen or
            // numbered are decided for THIS proposal, not for the document,
            // so the strategy row says it too, never "available" beside a
            // correction that cannot be made.
            $proposalRefused = !$this->proposal->available && \in_array($this->proposal->reason, [
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_BUYER_INSUFFICIENT,
                \Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies::REASON_CREDIT_UNAVAILABLE,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_NO_CREDIT_TYPE,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_CREDIT_TYPE_AMBIGUOUS,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_CREDIT_TYPE_DISABLED,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_NO_SERIES,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_NO_SALES_TYPE,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_SALES_TYPE_AMBIGUOUS,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_SALES_RULES_DISAGREE,
                \Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection::REASON_SALES_TYPE_DISABLED,
            ], true);

            if ($proposalRefused && isset($this->strategies[\Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies::INCREMENTAL])) {
                $this->strategies[\Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies::INCREMENTAL] = (object) [
                    'available' => false,
                    'reason' => $this->proposal->reason,
                    'detail' => $this->proposal->detail,
                ];
            }

            self::addCorrectionToolbar($this->proposal->available);

            parent::display($tpl);

            return;
        } elseif ($this->_layout == 'edit_document_return') {
            // The page every document modal closes through: Issue, the
            // correction and Void & Replace, done or closed. It writes nothing
            // and shows nothing of the order but its id, so it asks no document
            // right of its own: the dispatcher already asked Orders: Edit for
            // this layout, and an operator who may only replace must be able
            // to close that modal (F-20). The issue form below keeps its 403.
            $orderId = $input->getInt('id_order', 0);

            if ($orderId <= 0) {
                throw new Exception('Order ID is required to return to the order', 400);
            }

            $this->order = $model->getItem($orderId);

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }
        } elseif ($this->_layout == 'edit_document') {
            // Issuing is a one-way act: there is no document id to edit, only
            // an order to issue against. The modal is always "new".
            $orderId = $input->getInt('id_order', 0);

            if ($orderId <= 0) {
                throw new Exception('Order ID is required when issuing a document', 400);
            }

            $this->order = $model->getItem($orderId);

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }

            // The panel withholds this modal from anyone who may not issue.
            // Now that the form is its own request, that request has to say
            // the same thing: without both rights there is no form to build,
            // and the POST would be refused by assertCanIssueDocument anyway.
            $user = $this->getCurrentUser();

            if (!AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
                || !AlfaHelper::can(section: 'documents', action: 'create', user: $user)) {
                throw new Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $model->setState('order.id', $orderId);

            // The draft a refusal kept: read back
            // ONLY on the redisplay the controller asked for (kept=1). Any
            // other open (the documents panel's button, or after the modal
            // was closed by its X, Esc or the backdrop, none of which reach
            // cancelDocument) forgets it and starts from the order. Read here
            // and not in the model's form builder, which other callers use
            // bare. The controller validated it; the fields escape it.
            $draftKey = 'com_alfa.edit.order.document.' . $orderId;

            if ($input->getInt('kept', 0) !== 1) {
                $app->setUserState($draftKey, null);
            }

            $draft = (array) $app->getUserState($draftKey, []);
            $this->form = $model->getDocumentForm(['id_order' => $orderId] + $draft);

            self::addDocumentToolbar();
        } elseif ($this->_layout == 'edit_order_item' || $this->_layout == 'edit_order_item_return') {
            $itemId = $input->getInt('id', 0);
            $orderId = $input->getInt('id_order', 0);

            $this->orderItem = null;
            $this->order = null;
            $this->configItem = null;

            // What a refused Save kept, redisplayed over the stored line on the
            // kept=1 redisplay only (OrderController::saveOrderItem).
            $this->keptPost = $this->keptModalDraft('com_alfa.edit.order.item.' . $orderId . '.' . $itemId);
            $keptConfiguration = \is_array($this->keptPost['configuration'] ?? null) ? $this->keptPost['configuration'] : null;

            if ($itemId > 0) {
                $this->orderItem = $model->getOrderItemData($itemId);
                if (!$this->orderItem) {
                    throw new Exception('Order item not found', 404);
                }
                $this->order = $model->getItem((int) $this->orderItem->id_order);

                // The line's item-field surfaces, seeded from the frozen snapshot, or
                // from what a refused Save posted: the configuration half of the
                // single edit popup.
                $config = $model->getOrderItemConfig(orderItemId: $itemId, keptConfiguration: $keptConfiguration);
                $this->configItem = $config->item ?? null;
                $this->soldReadOnly = $config !== null && $config->soldReadOnly;

                // The frozen per-field price impacts, for the popup's breakdown panel.
                \Alfa\Component\Alfa\Administrator\Helper\OrderHelper::attachOrderItemFields([$this->orderItem]);
            } else {
                if ($orderId <= 0) {
                    throw new Exception('Order ID is required when adding an item', 400);
                }
                $this->order = $model->getItem($orderId);
            }

            if (!$this->order) {
                throw new Exception('Order not found', 404);
            }

            // Configure-first add: a product picked in the search reloads this
            // SAME layout with its id_item: full edit view (configuration +
            // pricing) before the line exists; one save creates it all. A
            // refused new line comes back with the product it was saved with.
            $this->newItemId = 0;
            $newItemId = (int) ($this->keptPost['jform']['id_item'] ?? 0) ?: $input->getInt('id_item', 0);

            if (!$this->orderItem && $newItemId > 0) {
                $this->configItem = $model->getNewItemConfig(itemId: $newItemId, keptConfiguration: $keptConfiguration);

                if ($this->configItem) {
                    $this->newItemId = $newItemId;
                }
            }

            $this->form = $model->getOrderItemForm(
                $this->orderItem ?? ($this->newItemId
                    ? ['id_item' => $this->newItemId, 'name' => $this->configItem->name, 'quantity' => 1]
                    : []),
            );
            $this->form->bind((array) ($this->keptPost['jform'] ?? []));
            self::addOrderItemToolbar();
        } else {
            // The post Joomla kept for this order (OrderModel::keptPostFor) is
            // read back ONLY on the redisplay the controller asked for (kept=1):
            // after a reload (every modal return) or a refused save. Any other
            // open (the notification bell, a dashboard card, a bookmark) forgets
            // it, or unsaved values gone stale would refill the form and Save
            // would write them (F-19).
            if ($input->getInt('kept', 0) !== 1) {
                $app->setUserState('com_alfa.edit.order.data', null);
            }

            try {
                $this->order = $model->getItem();
            } catch (OrderCurrencyException $e) {
                // The order names no currency. The screen still opens, so an
                // administrator can see which order it is and why nothing can
                // be done with it.
                $this->displayWithoutCurrency(model: $model, refusal: $e);

                return;
            }

            $this->form = $model->getForm();

            // Read once: the form now holds what the post refilled. The kept=1
            // address requested again (an action popup's return reloads the
            // page as it stands, and so do F5 and Back) must show the order as
            // stored, not a status or values gone stale since (review O-REV-1).
            $app->setUserState('com_alfa.edit.order.data', null);

            // Column definitions for the Documents panel's table, the same way
            // the shipments and payments panels read theirs from their subforms.
            $this->documentListForm = $model->getDocumentListForm();

            // Check out the order (lock for editing), same as FormView does
            if ($this->order->id) {
                $model->checkout($this->order->id);

                // AN OPERATOR IS LOOKING AT IT, so the "new order" bell has done
                // its job. This is the one place that means that: the model's
                // getItem() is called by placement, by the mailer and by the
                // storefront too, and clearing there destroyed the notification
                // in the same request that raised it.
                \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::clear(
                    dedupKey: 'order:' . (int) $this->order->id,
                );
            }

            // Load activity log for History tab (unified; includes status changes)
            if ($this->order && $this->order->id) {
                $this->activityLog = $model->getOrderActivityLog((int) $this->order->id);
            } else {
                $this->activityLog = [];
            }

            // Plugin contributions now arrive through the admin contract: the template's
            // renderSlot('order-details' / 'order-main') dispatches onAlfaAdminRender with
            // this record on the context. The conventional property is what the contract
            // reads, so alias it; the template keeps its own name.
            $this->item = $this->order;

            $this->addToolbar();
        }

        parent::display($tpl);
    }

    /**
     * The order screen of an order that names no currency.
     *
     * No form and no panel is built: every one of them prices, takes money,
     * ships or issues, and each of those is refused for this order. What is
     * shown is which order it is, who it is for and why nothing can be done
     * with it, in the operator's words, and no amount at all.
     *
     * @param object $model The order model.
     * @param OrderCurrencyException $refusal The reader's refusal; its message is the sentence shown.
     *
     *
     * @throws Exception When the order no longer exists.
     *
     * @since  2.0.0
     */
    private function displayWithoutCurrency(object $model, OrderCurrencyException $refusal): void
    {
        $this->order = $model->getItemWithoutMoney($refusal->orderId());

        if ($this->order === null) {
            throw new Exception('Order not found', 404);
        }

        $this->item = $this->order;
        $this->currencyRefusal = $refusal->getMessage();
        $this->statusName = (string) (AlfaHelper::getOrderStatuses()[(int) ($this->order->id_order_status ?? 0)]->name ?? '');

        Factory::getApplication()->getInput()->set('hidemainmenu', true);
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), 'generic');
        ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CLOSE');

        $this->setLayout('edit_nocurrency');

        parent::display();
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->order->id == 0);
        $checkedOut = !(empty($this->order->checked_out) || $this->order->checked_out == $userId);

        // Saving a new order is create, saving an existing one is edit.
        $canEdit = AlfaHelper::can(section: 'orders', action: 'edit', user: $user);
        $canSave = $isNew ? AlfaHelper::can(section: 'orders', action: 'create', user: $user) : $canEdit;

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), 'generic');

        // Apply reopens the saved order, which needs edit even when the order is new.
        if (!$checkedOut && $canSave && $canEdit) {
            ToolbarHelper::apply('order.apply', 'JTOOLBAR_APPLY');
        }

        // If not checked out, can save the item.
        if (!$checkedOut && $canSave) {
            ToolbarHelper::save('order.save', 'JTOOLBAR_SAVE');
        }

        ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CANCEL');
    }

    /**
     * Add toolbar for payment edit view
     * @since  1.0.0
     */
    protected function addPaymentToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $paymentId = Factory::getApplication()->getInput()->getInt('id', 0);
        $isNew = ($paymentId == 0);
        $toolbar = $this->getDocument()->getToolbar();

        $title = $isNew
            ? Text::_('COM_ALFA_TITLE_PAYMENT_NEW')
            : Text::_('COM_ALFA_TITLE_PAYMENT_EDIT');

        ToolbarHelper::title($title, 'generic');

        $user = $this->getCurrentUser();

        // Save button: adding or changing a payment is an order edit
        if (AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->save('order.savePayment');
        }

        // Delete button (only for existing payments): removing a payment is an order edit
        if (!$isNew && AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->delete('order.deletePayment')
                ->text('JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE');
        }

        // Cancel button
        $toolbar->cancel('order.cancelPayment');
    }

    /**
     * Add toolbar for shipment edit view
     * @since  1.0.0
     */
    protected function addShipmentToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $shipmentId = Factory::getApplication()->getInput()->getInt('id', 0);
        $isNew = ($shipmentId == 0);
        $toolbar = $this->getDocument()->getToolbar();

        $title = $isNew
            ? Text::_('COM_ALFA_TITLE_SHIPMENT_NEW')
            : Text::_('COM_ALFA_TITLE_SHIPMENT_EDIT');

        ToolbarHelper::title($title, 'generic');

        $user = $this->getCurrentUser();

        // Save button: adding or changing a shipment is an order edit
        if (AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->save('order.saveShipment');
        }

        // Delete button (only for existing shipments): removing a shipment is an order edit
        if (!$isNew && AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->delete('order.deleteShipment')
                ->text('JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE');
        }

        // Cancel button
        $toolbar->cancel('order.cancelShipment');
    }

    /**
     * Toolbar for the Void & Replace modal.
     *
     * The destructive action is offered ONLY when the service would accept it:
     * a button certain to be refused is not an offer. Preview is offered
     * wherever the buyer can be edited: it re-proposes with the edits and
     * writes nothing. No button validates in the browser, and the form carries
     * no client validator (edit_replacement.php): the server is the one
     * validator, so preview and issue agree, Cancel and Preview always submit,
     * and a frozen email a browser dislikes never blocks a confirm that changes
     * nothing. The required reason and an edited email are checked on the
     * server, which keeps what was typed.
     *
     * @param bool $available Whether the replacement can be carried out.
     * @param bool $editable Whether the buyer can be edited and previewed.
     *
     *
     * @since   2.0.0
     */
    protected function addReplacementToolbar(bool $available, bool $editable): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_REPLACEMENT_MODAL_TITLE'), 'generic');

        // Every button NAMES its form. Joomla.submitbutton() finds one by
        // `form.form-validate`, else `#adminForm`, and the replacement form is
        // neither (it carries no client validator, the server being the one
        // validator), so an unnamed button found nothing and threw in
        // core.js: Preview, Void & Replace and Close all submitted nothing.
        if ($editable) {
            $toolbar->standardButton('preview', 'COM_ALFA_REPLACEMENT_ACTION_PREVIEW', 'order.previewReplacement')
                ->icon('icon-eye')
                ->form('replacement-form');
        }

        if ($available) {
            $toolbar->save('order.replaceDocument')
                ->text('COM_ALFA_REPLACEMENT_ACTION')
                ->icon('icon-refresh')
                ->form('replacement-form')
                ->formValidation(false);
        }

        $toolbar->cancel('order.cancelReplacement')
            ->form('replacement-form');
    }

    /**
     * Toolbar for the correction modal.
     *
     * The Issue button exists ONLY when the proposal is actually issuable:
     * section 5 says the operator chooses among strategies that are currently valid,
     * and a button certain to be refused is not an offer: it is a trap that
     * costs the operator a round trip to discover.
     *
     * @param bool $available Whether the proposal can be issued.
     *
     *
     * @since   2.0.0
     */
    protected function addCorrectionToolbar(bool $available): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_CORRECTION_MODAL_TITLE'), 'generic');

        if ($available) {
            $toolbar->save('order.issueCorrection')
                ->text('COM_ALFA_CORRECTION_ACTION_ISSUE');
        }

        $toolbar->cancel('order.cancelCorrection');
    }

    /**
     * Add toolbar for the sales-document issue view.
     *
     * No Delete: a document is never deleted, only voided, and voiding is the
     * panel's own button behind the documents permission.
     *
     * @since  2.0.0
     */
    protected function addDocumentToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTS_MODAL_TITLE'), 'generic');

        $user = $this->getCurrentUser();

        // Issuing by hand is a fiscal act, not merely an order edit: it needs
        // Orders edit AND Sales Documents create, the pair the controller's
        // assertCanIssueDocument() enforces on the POST.
        if (AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
            && AlfaHelper::can(section: 'documents', action: 'create', user: $user)) {
            $toolbar->save('order.issueDocument')
                ->text('COM_ALFA_DOCUMENTS_ACTION_ISSUE');
        }

        $toolbar->cancel('order.cancelDocument');
    }

    /**
     * Add toolbar for order item edit view
     * @since  1.0.0
     */
    protected function addOrderItemToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $itemId = Factory::getApplication()->getInput()->getInt('id', 0);
        $isNew = ($itemId == 0);
        $toolbar = $this->getDocument()->getToolbar();

        $title = $isNew
            ? Text::_('COM_ALFA_TITLE_ORDER_ITEM_NEW')
            : Text::_('COM_ALFA_TITLE_ORDER_ITEM_EDIT');

        ToolbarHelper::title($title, 'generic');

        $user = $this->getCurrentUser();

        // Save button: adding or changing an order line is an order edit
        if (AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->save('order.saveOrderItem');
        }

        // Delete button (only for existing items): removing an order line is an order edit
        if (!$isNew && AlfaHelper::can(section: 'orders', action: 'edit', user: $user)) {
            $toolbar->delete('order.deleteOrderItem')
                ->text('JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE');
        }

        // Cancel button
        $toolbar->cancel('order.cancelOrderItem');
    }

    /**
     * The draft a refused payment, shipment or order-line Save kept for one record.
     *
     * Read back ONLY on the redisplay the controller asked for (kept=1). Any
     * other open (the panel's button, or after the modal was closed by its X,
     * Esc or the backdrop, none of which reach a task) forgets it, so an
     * abandoned edit never comes back. Read here and bound over the record,
     * not in the model's form builder, which the save task uses bare.
     *
     * @param string $modalKey The record's user-state key.
     *
     * @return array The kept fields; [] when there is nothing to redisplay.
     *
     * @since   2.0.0
     */
    private function keptModalDraft(string $modalKey): array
    {
        $app = Factory::getApplication();

        if ($app->getInput()->getInt('kept', 0) !== 1) {
            $app->setUserState($modalKey, null);

            return [];
        }

        $draft = $app->getUserState($modalKey, []);

        return \is_array($draft) ? $draft : [];
    }
}
