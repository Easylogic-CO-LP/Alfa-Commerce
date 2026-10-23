<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Order Model: Admin CRUD for orders and related entities
 *
 * Handles orders, payments, shipments, and order items through
 * a unified architecture with shared helpers.
 *
 * Architecture:
 *   Reusable helpers   -> getRelatedModel(), toMoney(),
 *                         saveDataOnTable(), deleteTableEntry()
 *   Data loading       -> getItem(), getPaymentData(), getShipmentData(), getOrderItemData()
 *   Save / Delete      -> save(), saveOrderPayment(), saveOrderShipment(), saveOrderItem()
 *   Activity log       -> logOrderActivity(), getOrderActivityLog()
 *
 * Totals:
 *   grand_total = items + shipping - discounts
 *   Computed by getItem() via OrderTotalHelper::computeFromArrays().
 *   Single source of truth: same method used by OrdersModel list view.
 *   No total columns stored on #__alfa_orders; always computed.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderDeleteEvent as PaymentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderDeleteEvent as ShipmentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\ItemConfigurationHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Plugin\ItemFieldsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderItemLines;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\InventoryBridge;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Alfa\Component\Alfa\Administrator\Service\Order\ManualDiscountScope;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderLineMoney;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\ReconciliationFacts;
use Alfa\Component\Alfa\Administrator\Service\Order\ShipmentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\StockPolicy;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\FieldState\StateBagBuilder;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceAction;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\VariantSelection;
use Alfa\Component\Alfa\Site\Service\ShowOn\InitialState;
use Alfa\Component\Alfa\Site\Service\ShowOn\SubmitAuthority;
use DomainException;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

class OrderModel extends AlfaAdminModel
{
    protected $text_prefix = 'COM_ALFA';
    public $typeAlias = 'com_alfa.order';
    protected $formName = 'order';
    protected $item = null;

    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'order';

    /**
     * Whether the form being preprocessed is the MAIN order form. This model loads three
     * popup forms (shipment, payment, order item) under the SAME name 'com_alfa.order',
     * so the contract's name guard cannot tell them apart: only the main form may take
     * the onAlfaAdminForm / onAlfaAdminPrepareData events.
     *
     *
     * @since  2.0.0
     */
    private bool $alfaMainForm = false;

    /**
     * Route only the MAIN order form through the admin contract's form seam; the popup
     * forms take Joomla's plain preprocessing (the grandparent's), exactly as before.
     *
     * @param \Joomla\CMS\Form\Form $form The form.
     * @param mixed $data The bind data.
     * @param string $group The plugin group for Joomla's own dispatch.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function preprocessForm(\Joomla\CMS\Form\Form $form, $data, $group = 'content')
    {
        if ($this->alfaMainForm) {
            parent::preprocessForm($form, $data, $group);

            return;
        }

        AdminModel::preprocessForm($form, $data, $group);
    }

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - CREATE goes through OrderCreator (the domain pipeline) and returns before
     *   parent::save(), so the onAlfaAdminValidate/BeforeSave/AfterSave bracket fires on
     *   EDIT saves only. Admin order creation is a domain flow with its own events.
     * - A delete batch fully vetoed by the payment/shipment gateways returns before
     *   parent::delete(), so the contract's delete bracket fires only when something
     *   actually reaches the table: nothing was deleted, nothing to observe.
     */

    // Form methods

    public function getShipmentForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_shipments', [
            'control' => 'jform',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_shipments.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        // The row and the order are the caller's to load: the view already
        // has both, and the Save task builds this form only to filter and
        // validate its post, so loading them here cost every Save a whole order.

        // Convert object -> array with Money->float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }
        }

        // Inject shipment status options
        // The XML declares <field name="status" type="list"> with NO static
        // options. We inject them here from ShipmentLedger constants
        // so there's a single source of truth for all valid statuses.
        $shipStatusField = $form->getField('status');
        if ($shipStatusField) {
            foreach (ShipmentLedger::allStatuses() as $status) {
                $shipStatusField->addOption(
                    Text::_('COM_ALFA_STATUS_' . strtoupper($status)),
                    ['value' => $status],
                );
            }
        }

        $form->bind($data);

        // Pre-select items assigned to this shipment (edit only)
        // The 'items' field is a multi-select: needs an array of item IDs
        if ($id > 0) {
            $assignedItemIds = $this->getShipmentItemIds($id);
            $form->setValue('items', null, $assignedItemIds);
        }

        return $form;
    }

    /**
     * Build the order-payment edit form (order_payments), injecting the
     * payment_type, transaction_status and refund_type list options from
     * PaymentLedger, then binding the supplied data (with the order/payment ids).
     *
     * @param array|object $data Data for the form (objects are normalised via objectToFormData).
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getPaymentForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_payments', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_payments.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        // The row is the caller's to load (see getShipmentForm()).

        // Convert object -> array with Money->float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        // The row the caller hands in (the modal shows it); the save path builds the form with none, only to filter
        // and validate the post, and reads nothing for it.
        $shown = \is_array($data) && $data !== [];

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }

            // A new payment arrives with no row of its own, so the form's loaded
            // defaults are the ORDER's: right for the currency, rate and method the
            // payment is taken in, wrong for the note: the order's internal note is
            // not this movement's label (order_payments.xml, "Internal label for
            // this movement").
            if (!\array_key_exists('note', $data)) {
                $data['note'] = '';
            }
        }

        // Inject payment_type options
        // XML declares <field name="payment_type" type="list"> with NO static
        // options. Injected from PaymentLedger::recordableTypes(): a reversal or
        // a chargeback is recorded from the payment's actions, never by hand.
        $paymentTypeField = $form->getField('payment_type');
        if ($paymentTypeField) {
            foreach (PaymentLedger::recordableTypes() as $type) {
                $paymentTypeField->addOption(
                    Text::_('COM_ALFA_PAYMENT_TYPE_' . strtoupper($type)),
                    ['value' => $type],
                );
            }
        }

        // Inject transaction_status options
        // Same pattern: injected from PaymentLedger::allStatuses(). A stored row is offered the
        // statuses it may be written with (PaymentLedger::statusesFrom()): a settled refund is
        // never offered pending or authorized; the ledger refuses them in any case.
        $txnStatusField = $form->getField('transaction_status');
        if ($txnStatusField) {
            $statuses = \is_array($data) && (int) ($data['id'] ?? 0) > 0
                ? PaymentLedger::statusesFrom(paymentType: (string) ($data['payment_type'] ?? ''), status: (string) ($data['transaction_status'] ?? ''))
                : PaymentLedger::allStatuses();

            foreach ($statuses as $status) {
                $txnStatusField->addOption(
                    Text::_('COM_ALFA_STATUS_' . strtoupper($status)),
                    ['value' => $status],
                );
            }
        }

        // A refund pending at a gateway's provider keeps its amount and its reference while the provider may still
        // complete it (PaymentLedger::refundIdentityFixed()): both are shown read-only; the ledger refuses the change
        // in any case (the staff guard, PaymentLedger::staffWriteRefusal()). Its status may still be set failed or
        // cancelled.
        if ($shown
            && (int) ($data['id'] ?? 0) > 0
            && (string) ($data['payment_type'] ?? '') === PaymentLedger::TYPE_REFUND
            && \in_array((string) ($data['transaction_status'] ?? ''), PaymentLedger::REFUND_RESERVING_STATUSES, true)
            && PaymentLedger::refundIdentityFixed(refundId: (int) $data['id'])) {
            foreach (['amount', 'transaction_id'] as $fixed) {
                $form->setFieldAttribute($fixed, 'readonly', 'true');
            }
        }

        // Inject refund_type options
        // Empty first option ("-") for non-refund payments, then full/partial.
        $refundTypeField = $form->getField('refund_type');
        if ($refundTypeField) {
            $refundTypeField->addOption('-', ['value' => '']);
            foreach (PaymentLedger::allRefundTypes() as $type) {
                $refundTypeField->addOption(
                    Text::_('COM_ALFA_REFUND_TYPE_' . strtoupper($type)),
                    ['value' => $type],
                );
            }
        }

        $form->bind($data);

        return $form;
    }

    /**
     * Build the sales-document ISSUE form (document.xml), the body of the
     * order screen's Issue modal.
     *
     * Two things are injected here rather than declared in the XML, because
     * neither is knowable at authoring time:
     *
     *  1. OPTION SETS: the series under each type and the already-issued
     *     documents a credit may correct, both of which are rows, not
     *     constants. (The type picker needs none: DocumentTypeField reads the
     *     registry itself.)
     *
     *  2. showon CONDITIONS: which fields a type shows is a PROPERTY of that
     *     type (its family, whether it is fiscal, whether it needs a tax id,
     *     its movement mode), never its alias. The XML therefore carries no
     *     alias literals; the alias lists below are read from the registry and
     *     written onto the fields, so a pack that adds a credit type gets the
     *     credit behaviour with no template or form edit.
     *
     * The control is deliberately EMPTY, not 'jform'. A shipment posts through
     * the model into its own table, so it wears the jform prefix every Joomla
     * edit form wears. This form posts to order.issueDocument, a task that
     * binds nothing: it hands a payload to DocumentIssuer, which owns every
     * guard. Flat names are that task's contract and four suites drive it
     * through the real controller with flat input.
     *
     * A refused issue's kept draft reaches this as ordinary $data (the view
     * reads it, never this builder) and binds like any other: lines and
     * charges whole, for their fields to render (since 2.0.0).
     *
     * @param array|object $data Data to bind (objects are normalised via objectToFormData).
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  2.0.0
     */
    public function getDocumentForm($data = [])
    {
        $form = $this->loadForm('com_alfa.order.document', 'document', [
            'control' => '',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $orderId = (int) ($this->getState('order.id', 0)
            ?: Factory::getApplication()->getInput()->getInt('id_order', 0));

        // Type picker, and the alias lists every showon below is built from
        $types = DocumentHelper::types();

        $creditAliases = [];
        $fiscalAliases = [];
        $vatAliases = [];
        $combinedAliases = [];
        $movementAliases = [];

        // doc_type needs no injection: DocumentTypeField already lists the
        // registry's visible types. This loop only reads their properties.
        foreach ($types as $type) {
            if (DocumentHelper::familyOf($type) === 'credit') {
                $creditAliases[] = $type;
            }

            if (DocumentHelper::isFiscal($type)) {
                $fiscalAliases[] = $type;
            }

            if (DocumentHelper::requiresBuyerVat($type)) {
                $vatAliases[] = $type;
            }

            // "Also serves as delivery note": a SALES document that its type
            // allows to carry goods. Never offered on a credit or a pure note.
            if (DocumentHelper::familyOf($type) === 'sales' && DocumentHelper::supportsMovement($type)) {
                $combinedAliases[] = $type;
            }

            // A logistics document's whole content IS the movement, so the
            // block belongs to the type itself and not to a tick. Read as the
            // family, never as movement_mode: no shipped type sets that to
            // 'required', and keying on it would hide the block on the one
            // document that cannot be issued without it. The rule is one
            // method, which the issue task keeps the block by as well.
            if (self::issueFormShowsMovement(docType: $type, isCombined: false)) {
                $movementAliases[] = $type;
            }
        }

        // Series. Every type's series are options of the one field and the
        //    modal's script narrows the list to the picked type.
        //
        //    The map is published on the FIELD, not as a data attribute per
        //    option: Joomla's list field copies only the attributes it knows
        //    onto a rendered <option> and silently drops a data-* one, so an
        //    option cannot carry its own type. A field can: FormField keeps
        //    data-* attributes and the layout renders them.
        $seriesField = $form->getField('id_series');
        $seriesByType = [];

        foreach ($types as $type) {
            foreach (DocumentHelper::seriesForType($type) as $series) {
                $label = $series->code . ($series->title !== '' ? ' - ' . $series->title : '');

                $seriesByType[$type][] = ['id' => (int) $series->id, 'label' => $label];

                if ($seriesField) {
                    $seriesField->addOption($label, ['value' => (int) $series->id]);
                }
            }
        }

        $form->setFieldAttribute('id_series', 'data-series-by-type', json_encode($seriesByType, JSON_THROW_ON_ERROR));

        // Credit destination. The wallet option exists only for an order
        //    that HAS a customer to credit; a guest order can only be
        //    refunded by hand.
        $destinationField = $form->getField('credit_destination');

        if ($destinationField) {
            $destinationField->addOption(Text::_('COM_ALFA_DOCUMENTS_CREDIT_DEST_MANUAL'), ['value' => '']);

            $db = $this->getDatabase();
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id_user'))
                    ->from($db->quoteName('#__alfa_orders'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $orderId, ParameterType::INTEGER),
            );

            if ((int) $db->loadResult() > 0) {
                $destinationField->addOption(
                    Text::_('COM_ALFA_DOCUMENTS_CREDIT_DEST_ACCOUNT'),
                    ['value' => 'credit_account'],
                );
            }
        }

        // The visibility rules, as showon. Nothing below names an alias
        //    literal: every list came from the registry loop above.
        $showon = static fn (array $aliases): string => 'doc_type:' . implode(',', $aliases);

        $form->setFieldAttribute('id_corrects', 'showon', $showon($creditAliases));
        $form->setFieldAttribute('credit_destination', 'showon', $showon($creditAliases));
        $form->setFieldAttribute('is_combined', 'showon', $showon($combinedAliases));

        // A credit is made out to the buyer its corrected document froze
        // so it SHOWS that buyer read-only, per
        // correctable document, and asks for none, so the editable buyer
        // fields belong to the fiscal types that are not credits.
        $editableBuyerAliases = array_values(array_diff($fiscalAliases, $creditAliases));

        // The heading carries the same condition as the fields under it, so a
        // group that does not apply leaves nothing behind, not an empty box.
        // It leads both the editable block and the credit's read-only one.
        $form->setFieldAttribute('buyer_heading', 'showon', $showon(array_values(array_unique(array_merge($fiscalAliases, $creditAliases)))));
        $form->setFieldAttribute('credit_buyer', 'showon', $showon($creditAliases));

        foreach (['buyer_name', 'buyer_tax_office', 'buyer_activity', 'buyer_email'] as $field) {
            $form->setFieldAttribute($field, 'showon', $showon($editableBuyerAliases));
        }

        // The tax id is part of the buyer block, shown with the rest of it.
        // requires_buyer_tax_id does not decide whether it is ASKED FOR; it
        // decides whether it is MANDATORY, and the issuer enforces that. The
        // modal's script marks the field required on the types that need it,
        // from the list published below, never a credit's, which asks for no
        // buyer: a required field showon hides would still block the submit.
        $form->setFieldAttribute('buyer_vat_number', 'showon', $showon($editableBuyerAliases));
        $form->setFieldAttribute('buyer_vat_number', 'data-vat-types', implode(',', array_diff($vatAliases, $creditAliases)));

        // Required by the type, OR asked for by ticking combined.
        $movementShowon = $movementAliases === []
            ? 'is_combined:1'
            : $showon($movementAliases) . '[OR]is_combined:1';

        foreach (['movement_heading', 'movement_purpose', 'carrier_name', 'vehicle_number'] as $field) {
            $form->setFieldAttribute($field, 'showon', $movementShowon);
        }

        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        if (is_array($data)) {
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }

            // A kept draft that chose no movement purpose keeps that choice: a
            // form reads an empty value as "use the default", which here is Sale.
            if (\array_key_exists('movement_purpose', $data) && $data['movement_purpose'] === '') {
                $form->setFieldAttribute('movement_purpose', 'default', '');
            }

            // Who the document is made out to: the separate invoice record when
            // the order has one, else the customer: the same rule auto-issue
            // follows, so a hand-issued document names the same buyer.
            $order = $this->getItem($orderId);

            if ($order) {
                $buyer = OrderIdentity::buyer($order);

                $data['buyer_name'] ??= (string) ($buyer['buyer_name'] ?? '');
                $data['buyer_email'] ??= (string) ($buyer['buyer_email'] ?? '');
            }
        }

        $form->bind($data);

        return $form;
    }

    /**
     * Does the issue form show the movement block for this draft?
     *
     * The ONE rule for that block: getDocumentForm() writes it as the block's
     * showon, and OrderController::issueDocument() keeps what the block posted
     * only where it holds: a hidden control still posts, and the hidden
     * purpose list's default (Sale) printed a Movement block on invoices and
     * credit notes (F-09). It holds for a logistics type, whose content IS the
     * movement; for a type the registry says requires one, which the issuer
     * refuses without it; and for a document ticked to serve also as a
     * delivery note.
     *
     * @param string $docType The document type alias.
     * @param bool $isCombined Whether "also serves as delivery note" is ticked.
     *
     *
     * @since   2.0.0
     */
    public static function issueFormShowsMovement(string $docType, bool $isCombined): bool
    {
        return $isCombined
            || DocumentHelper::familyOf($docType) === DocumentHelper::FAMILY_LOGISTICS
            || DocumentHelper::requiresMovement($docType);
    }

    /**
     * Build the sales-document LIST form (order_documents.xml).
     *
     * Columns only. The order screen's Documents panel generates its table
     * head and its cells from this form's fields, the same way the shipments
     * and payments panels generate theirs from their subforms. It is never
     * posted and never bound to a document: an issued document is immutable.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  2.0.0
     */
    public function getDocumentListForm()
    {
        return $this->loadForm('com_alfa.order.documentlist', 'order_documents', [
            'control' => '',
            'load_data' => false,
        ]);
    }

    /**
     * Build the order edit form. On a new order the user_email/user_name fields
     * are made editable; the custom cart fields (user_details) are prepared and
     * prefilled from user state (preserving resubmitted input) or the order's
     * stored user_info row.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $app = Factory::getApplication();

        $this->alfaMainForm = true;

        try {
            $form = $this->loadForm('com_alfa.order', 'order', [
                'control' => 'jform',
                'load_data' => $loadData,
            ]);
        } finally {
            $this->alfaMainForm = false;
        }

        if (empty($form)) {
            return false;
        }

        $idFromInput = $app->getInput()->getInt('id', 0);
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);
        $this->item = $this->getItem($id);

        if ($id == 0) {
            // Manual creation: identity comes from the Customer picker
            // (registered) or the dynamic checkout fields (guest); the
            // snapshot-display fields would only duplicate/confuse here.
            $form->removeField('customer_kind');
            $form->removeField('user_email');
            $form->removeField('user_name');

            // Preselect the nominated is_initial status; without a default the
            // list renders its first option (alphabetically "Cancelled").
            $initialStatusId = OrderStatusHelper::getInitialId();

            if ($initialStatusId !== null) {
                $form->setFieldAttribute('id_order_status', 'default', (string) $initialStatusId);
            }
        }

        // created_by is an audit fact set by the writer (0 = guest checkout,
        // the admin identity on manual creation). Display-only and excluded
        // from the save: its UserId validation would otherwise reject a
        // guest's 0 (and an empty value on the creation form).
        $form->setFieldAttribute('created_by', 'readonly', 'true');
        $form->setFieldAttribute('created_by', 'class', 'readonly');
        $form->setFieldAttribute('created_by', 'filter', 'unset');
        $form->setFieldAttribute('created_by', 'validate', '');

        if ($id > 0) {
            // The customer is placement state; frozen lines never follow an
            // owner change silently (reassignment ships with the explicit
            // "Recalculate lines" action).
            $form->setFieldAttribute('id_user', 'readonly', 'true');
            $form->setFieldAttribute('id_user', 'filter', 'unset');

            // THE SAME TREATMENT THE TWO OTHER FROZEN FIELDS ALREADY GET, and
            // the one id_currency was missing. `readonly` alone is
            // presentation: Joomla's Form class never consults it, and a
            // readonly input is still submitted, so the field is dropped from
            // the save outright. This is CONVENIENCE, not enforcement: the
            // authority is OrderTable::store(), which refuses a change or a
            // clear whatever route it arrives by, including REST.
            $form->setFieldAttribute('id_currency', 'filter', 'unset');
        }

        // Two buckets, same field names, identical to the storefront checkout:
        // the delivery section posts under FIELDS_KEY, the invoice copy under
        // BILLING_FIELDS_KEY. The bucket carries the ROLE, the field name carries
        // the #__alfa_user_info column, so both rows stay type-neutral.
        // Built for the ORDER's customer (a member's email is the account's and
        // is not rendered; a guest's is), not for the administrator editing it.
        // On CREATION there is no customer yet: the restricted fields follow the
        // operator (userId null -> the current identity, so nothing the merchant
        // scoped to a usergroup goes missing), while the identity fields are
        // rendered as for a guest (customerId 0); validate() then decides,
        // once the picker's value is known, whether the email was needed.
        $customerId = $id > 0 ? (int) ($this->item->id_user ?? 0) : 0;
        $visibilityId = $id > 0 ? $customerId : null;

        FieldsHelper::prepareForm('cart.shipping', $form, $data, 'user_details', userId: $visibilityId, customerId: $customerId);
        FieldsHelper::prepareForm(
            'cart.billing',
            $form,
            $data,
            'user_details_billing',
            group: FieldsHelper::BILLING_FIELDS_KEY,
            userId: $visibilityId,
            customerId: $customerId,
        );

        // The identity floors are a CAPTURE contract for new orders, not a
        // correction contract: an order placed before a field existed must
        // stay savable (status, notes, lines) without the operator inventing
        // a value the customer never gave.
        if ($id > 0) {
            foreach (RoleRegistry::roles() as $role) {
                $column = RoleRegistry::column($role);

                if ($column === null) {
                    continue;
                }

                $form->setFieldAttribute($column, 'required', 'false', FieldsHelper::FIELDS_KEY);
                $form->setFieldAttribute($column, 'required', 'false', FieldsHelper::BILLING_FIELDS_KEY);
            }
        } else {
            // On creation the customer is picked on this same screen, so the
            // form cannot know yet whether the account will own the email.
            // The browser therefore does not demand it; validate() does,
            // and only when the order turns out to be a guest's.
            $emailColumn = RoleRegistry::column(RoleRegistry::EMAIL);

            if ($emailColumn !== null) {
                $form->setFieldAttribute($emailColumn, 'required', 'false', FieldsHelper::FIELDS_KEY);
                $form->setFieldAttribute($emailColumn, 'required', 'false', FieldsHelper::BILLING_FIELDS_KEY);
            }
        }

        // Prefill dynamic fields. Joomla's bind runs inside loadForm() before the
        // dynamic fields exist, so they never get populated automatically. Resolve
        // values from user state (resubmit after validation failure must keep the
        // user's typed-in input) falling back to the order's user_info row from DB.
        $userState = $this->keptPostFor($id);

        // The box is decided from the same read that refills its fields: the
        // edit screen forgets the kept post once this form is built.
        $this->formInvoiceDiffers = $userState === []
            ? $this->hasSeparateInvoice()
            : !empty($userState['invoice_differs']) || isset($userState[FieldsHelper::BILLING_FIELDS_KEY]);

        $this->prefillBucket(
            form: $form,
            bucket: FieldsHelper::FIELDS_KEY,
            state: $userState,
            row: $this->item->user_info ?? null,
        );

        // The invoice bucket only pre-fills from a row that is genuinely a
        // SEPARATE record; when the order invoices to its delivery address the
        // section stays empty and its toggle stays off.
        $this->prefillBucket(
            form: $form,
            bucket: FieldsHelper::BILLING_FIELDS_KEY,
            state: $userState,
            row: $this->hasSeparateInvoice() ? ($this->item->invoice_info ?? null) : null,
        );

        return $form;
    }

    /**
     * The order form's last post that Joomla kept, only when it is this order's.
     *
     * Joomla keeps the post in com_alfa.edit.order.data after a failed save and
     * after every reload, and the order page reloads that way after each modal
     * action (issue, void, correction, payment). Only the edit, save and cancel
     * tasks clear it, and orders are also opened by direct link (the
     * notification bell, the dashboard), which runs none of them. The post
     * then filled ANOTHER order's form with the first order's id, address
     * record and customer, and a Save wrote them back.
     * A post refills only the order it was posted for, and on the
     * edit screen only the redisplay a reload or a refused save redirects to
     * (kept=1) finds one, once: any other open, and that address requested
     * again, forgets it (View\Order\HtmlView).
     *
     * @param int $orderId The order on screen; 0 for a new one.
     *
     * @return array The kept post, or [] when there is none for this order.
     *
     * @since  2.0.0
     */
    private function keptPostFor(int $orderId): array
    {
        $post = Factory::getApplication()->getUserState('com_alfa.edit.order.data', []);
        $post = \is_array($post) ? $post : (array) $post;

        return $post !== [] && (int) ($post['id'] ?? 0) === $orderId ? $post : [];
    }

    /**
     * Whether this order invoices to a record of its own rather than reusing the
     * delivery address: the backend twin of the checkout's "different details"
     * choice, read from the stored ids instead of a posted checkbox.
     *
     *
     * @since  2.0.0
     */
    public function hasSeparateInvoice(): bool
    {
        // Callers include the edit template, which runs after getForm() has
        // populated $this->item, but never assume that ordering.
        $item = \is_object($this->item ?? null) ? $this->item : null;

        if ($item === null) {
            return false;
        }

        $delivery = (int) ($item->id_address_delivery ?? 0);
        $invoice = (int) ($item->id_address_invoice ?? 0);

        return $invoice > 0 && $invoice !== $delivery;
    }

    /**
     * The name, under jform, of the status the order page was built with:
     * the STORED status, whatever the select shows. The reload a modal's
     * return runs posts it back, so the redisplay can tell a status the
     * operator changed from one the modal itself moved (loadFormData).
     *
     * @var string
     * @since 2.0.0
     */
    public const LOADED_STATUS_KEY = 'loaded_status';

    /**
     * "Different invoice details" as the form getForm() last built shows it.
     *
     * @var bool|null Null until a form is built.
     * @since 2.0.0
     */
    private ?bool $formInvoiceDiffers = null;

    /**
     * Whether the order form shows "Different invoice details" ticked.
     *
     * On the redisplay after a reload or a refused save the invoice fields are
     * refilled from the kept post, so the box must follow that post too: read
     * from the stored ids it came back unticked over typed details, and the
     * next Save (an unticked box posts nothing) dropped them (F-08). A raw
     * post (a reload, a validation refusal) carries the box itself; the
     * validated data an order refusal keeps has no box, being no form field,
     * but keeps the invoice section only when the box was ticked (validate()
     * removes it otherwise). With nothing kept, the stored choice.
     *
     * Decided by getForm() from the same read of the kept post that refills
     * the fields: the edit screen forgets the post once the form is built,
     * before the template asks.
     *
     *
     * @throws LogicException When no order form has been built yet.
     *
     * @since  2.0.0
     */
    public function invoiceDiffersOnForm(): bool
    {
        if ($this->formInvoiceDiffers === null) {
            throw new LogicException('The order form must be built before its invoice box is read.');
        }

        return $this->formInvoiceDiffers;
    }

    /**
     * Seed one bucket's dynamic fields: the resubmitted values win (a failed
     * validation must never lose what the admin typed), otherwise the stored
     * user-info row fills in.
     *
     * @param Form $form The order form.
     * @param string $bucket The field bucket (FIELDS_KEY / BILLING_FIELDS_KEY).
     * @param array $state The whole com_alfa.edit.order.data user state.
     * @param object|null $row The #__alfa_user_info row backing this bucket.
     *
     *
     * @since  2.0.0
     */
    private function prefillBucket(Form $form, string $bucket, array $state, ?object $row): void
    {
        // A repopulated POST wins: it is what the operator last typed.
        if (isset($state[$bucket]) && \is_array($state[$bucket])) {
            foreach ($state[$bucket] as $key => $value) {
                $form->setValue($key, $bucket, $value);
            }

            return;
        }

        // Otherwise the stored row, seeded through the one bucket seeder the
        // cart builder also uses.
        FieldsHelper::prefillBucket($form, $bucket, $row);
    }

    /**
     * Validate the order submission after taking off the form everything the
     * admin was never shown: the same authority the storefront applies, so a
     * rule that hides a field hides it on both sides of the app:
     *
     *   - the whole INVOICE bucket unless "different details" is ticked;
     *   - each field a ShowOn rule hides, per section.
     *
     * Removed fields also drop out of `Form::filter()`, so a value posted for a
     * hidden field can never reach a `#__alfa_user_info` row.
     *
     * @param Form $form The order form.
     * @param array $data The posted data.
     * @param string|null $group The form group.
     *
     * @return array|bool The filtered data, or false on validation failure.
     *
     * @since  2.0.0
     */
    public function validate($form, $data, $group = null)
    {
        $data = (array) $data;

        $isNew = (int) ($data['id'] ?? 0) === 0;

        // WHOSE FORM THIS IS. The buckets were built for the order's customer
        // (getForm), so visibility must be judged for that same customer here
        // - a field the customer has and the administrator does not would
        // otherwise escape the ShowOn prune entirely.
        $customerId = $isNew
            ? (int) ($data['id_user'] ?? 0)
            : (int) ($this->item->id_user ?? 0);

        // On creation the form was built with the OPERATOR's visibility (the
        // customer was not known yet), so the prune must judge by the same
        // rule; otherwise a field the operator can see but the picked
        // customer cannot would be pruned off a form that still demands it.
        $visibilityId = $isNew ? null : $customerId;

        // The email is demanded only where it is kept: a manual order for a
        // MEMBER takes the account's address (the form is built before the
        // customer is picked, so the field is on it and is relaxed on both
        // sides; see getForm); a GUEST order still has to give one.
        $emailColumn = RoleRegistry::column(RoleRegistry::EMAIL);

        if ($isNew && $emailColumn !== null) {
            if ($customerId > 0) {
                unset($data[FieldsHelper::FIELDS_KEY][$emailColumn], $data[FieldsHelper::BILLING_FIELDS_KEY][$emailColumn]);
            } else {
                $form->setFieldAttribute($emailColumn, 'required', 'true', FieldsHelper::FIELDS_KEY);
            }
        }

        $data[FieldsHelper::FIELDS_KEY] = SubmitAuthority::prune(
            form: $form,
            fields: FieldsHelper::getFields('cart.shipping', null, $visibilityId),
            values: (array) ($data[FieldsHelper::FIELDS_KEY] ?? []),
            group: FieldsHelper::FIELDS_KEY,
            extra: ['invoice_differs' => empty($data['invoice_differs']) ? null : '1'],
        );

        if (empty($data['invoice_differs'])) {
            $form->removeGroup(FieldsHelper::BILLING_FIELDS_KEY);
            unset($data[FieldsHelper::BILLING_FIELDS_KEY]);
        } else {
            $data[FieldsHelper::BILLING_FIELDS_KEY] = SubmitAuthority::prune(
                form: $form,
                fields: FieldsHelper::getFields('cart.billing', null, $visibilityId),
                values: (array) ($data[FieldsHelper::BILLING_FIELDS_KEY] ?? []),
                group: FieldsHelper::BILLING_FIELDS_KEY,
            );
        }

        return parent::validate($form, $data, $group);
    }

    /**
     * Re-derive a GUEST order's customer snapshot from its delivery record
     * after a save, and log the correction with before/after values.
     *
     * @param int $orderId The order being saved.
     * @param object|null $prevOrder The order before the save (getItem()).
     * @param int $deliveryId The delivery record the order now points at.
     *
     *
     * @since  2.0.0
     */
    protected function refreshGuestSnapshot(int $orderId, ?object $prevOrder, int $deliveryId): void
    {
        if ($prevOrder === null || (int) ($prevOrder->customer_is_guest ?? 0) !== 1 || $deliveryId <= 0) {
            return;
        }

        $db = $this->getDatabase();
        $record = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . $deliveryId),
        )->loadObject() ?: null;

        $snapshot = OrderIdentity::snapshotFromDetails($record);
        $before = [
            'name' => (string) ($prevOrder->customer_name ?? ''),
            'email' => (string) ($prevOrder->customer_email ?? ''),
        ];
        $after = ['name' => $snapshot['customer_name'], 'email' => $snapshot['customer_email']];

        // Nothing typed (a legacy record with no identity columns yet) keeps
        // whatever the snapshot already holds.
        if ($after === $before || ($after['name'] === '' && $after['email'] === '')) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_orders'))
                ->set($db->quoteName('customer_name') . ' = ' . $db->quote(mb_substr($after['name'], 0, 400)))
                ->set($db->quoteName('customer_email') . ' = ' . $db->quote(mb_substr($after['email'], 0, 254)))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        )->execute();

        // Inside the edit's transaction: the correction rolls back with its record.
        self::logOrderActivityStrict($orderId, 'order.customer_changed', 'Customer details corrected', [
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Point the order at its invoice record, mirroring the checkout: the values
     * submitted under the invoice bucket become a record of their own, and an
     * absent bucket ("different details" unticked) means the delivery address
     * doubles as the invoice address.
     *
     * The write goes straight to the order row because `id_address_invoice` is
     * not a form field; it is resolved state, never something the admin types.
     *
     * @param int $orderId The order being saved.
     * @param array $data The validated submission (mutated: the resolved id).
     * @param int $customerId The order's customer (0 = guest -> always a fresh row).
     * @param int $previousInvoiceId The invoice id before this save.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function saveInvoiceInfo(int $orderId, array &$data, int $customerId, int $previousInvoiceId): bool
    {
        $deliveryId = (int) ($data['id_address_delivery'] ?? 0);
        $billing = array_filter(
            (array) ($data[FieldsHelper::BILLING_FIELDS_KEY] ?? []),
            static fn ($value) => \is_array($value) ? $value !== [] : trim((string) $value) !== '',
        );

        if ($billing === []) {
            $invoiceId = $deliveryId;
        } else {
            // Reuse the order's own invoice record as the edit target so a
            // correction updates it instead of piling up rows; insertData still
            // snapshots it to a new row when an order has frozen it.
            $invoiceId = UserInfoHelper::insertData(
                userId: $customerId,
                data: $billing,
                existingId: ($previousInvoiceId > 0 && $previousInvoiceId !== $deliveryId) ? $previousInvoiceId : null,
            );

            // Billing details were submitted and no row came back to hold them.
            // Answered rather than thrown, so the caller can roll the whole
            // save back instead of committing an order that points at nothing.
            if ($invoiceId <= 0) {
                return false;
            }
        }

        // Carry the resolved id into $data either way: the activity-log diff
        // reads it from there, so a silent skip would hide a real change.
        $data['id_address_invoice'] = $invoiceId;

        if ($invoiceId === $previousInvoiceId) {
            return true;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_orders'))
            ->set($db->quoteName('id_address_invoice') . ' = ' . ($invoiceId ?: 'NULL'))
            ->where($db->quoteName('id') . ' = ' . $orderId);
        $db->setQuery($query);
        $db->execute();

        return true;
    }

    /**
     * Create a `#__alfa_user_info` row from the dynamic checkout field values
     * of a manual order: the delivery-address record the order points at.
     *
     * WHOSE ROW IT IS has to be said, because `id_user` is NOT NULL DEFAULT 0
     * and 0 is not "unset"; it is the value UserInfoHelper reads as GUEST.
     * It stamps `$row->id_user = $userId` when it writes, documents "Guests
     * (id_user = 0) always INSERT", and EXCLUDES guest rows from a customer's
     * address book. So a registered customer whose address was typed in by an
     * admin used to end up with an address that customer could never see.
     *
     * @param array $fields Dynamic field values (field name => value).
     * @param int $userId The customer the row belongs to; 0 for a guest.
     *
     * @return int The new user_info id, 0 when nothing was submitted.
     *
     * @since  2.0.0
     */
    protected function createUserInfo(array $fields, int $userId = 0): int
    {
        $fields = array_filter($fields, static fn ($value) => $value !== '' && $value !== null);

        if ($fields === []) {
            return 0;
        }

        // The single write funnel's rules: only real columns, arrays encoded,
        // and a member's account-owned values (email) never on a record.
        $row = (object) UserInfoHelper::normalizeFieldValues($fields, $userId);
        unset($row->id);

        // Set AFTER the posted values are folded in, so a stray posted
        // `id_user` cannot decide who an address belongs to.
        $row->id_user = $userId;

        $this->getDatabase()->insertObject('#__alfa_user_info', $row, 'id');

        return (int) $row->id;
    }

    /**
     * The order form's data: the post Joomla kept for this order over the
     * stored order, or the stored order alone.
     *
     * The post is laid over the order, not shown alone. After an order refusal
     * core keeps the VALIDATED data, in which every field the save never takes
     * (filter="unset": the currency, the customer, the audit fields) is null.
     * Shown alone they rendered empty, the next Save posted '' for them (an
     * empty value is returned before any filter runs, so "unset" never removes
     * it) and the order refused every later Save as a cleared currency
     * field. What the operator typed still wins, and the status only
     * when the operator changed it.
     *
     * @return array|object The form data.
     *
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        $data = $this->keptPostFor((int) $this->getState($this->formName . '.id'));

        if ($this->item === null) {
            $this->item = $this->getItem();
            if (empty($this->item)) {
                $this->item = [];
            }
        }

        if (empty($data)) {
            return $this->item;
        }

        // A modal's save can move the order's status (a payment or shipment
        // method's status map), and its return reloads with the page as it
        // was: the kept status was then the stale one, and Save wrote it back
        // over the automation (review O-REV-2). A kept status equal to the one
        // the page was loaded with was not changed there, so the stored one
        // is shown. When the operator did change it, theirs is kept.
        $loaded = $data[self::LOADED_STATUS_KEY] ?? null;
        unset($data[self::LOADED_STATUS_KEY]);

        if ($loaded !== null && (string) ($data['id_order_status'] ?? '') === (string) $loaded) {
            unset($data['id_order_status']);
        }

        foreach (\is_object($this->item) ? get_object_vars($this->item) : $this->item as $key => $value) {
            if (!\array_key_exists($key, $data) || $data[$key] === null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    // Get order data (with money objects)
    //
    // Loading sequence:
    //   1. Order base row (parent::getItem) + Currency
    //   2. Items (with Money objects)
    //   3. User info
    //   4. Payments -> total_paid_real (Money objects)
    //   5. Shipments -> per-shipment costs (Money objects)
    //   6. Order totals via OrderTotalHelper::computeFromArrays()
    //      Uses already-loaded items + shipments + discounts from DB.
    //      Single source of truth: same formula as OrdersModel.
    //   7. History

    /**
     * The order with everything the order screen shows, every amount as Money
     * in the order's own currency at the scale the order froze.
     *
     * @param int|null $pk Order PK; null reads the model's state.
     *
     * @return object|null
     *
     * @throws OrderCurrencyException When the order exists and names no
     *                                currency: there is nothing to make Money
     *                                in. {@see self::getItemWithoutMoney()} is
     *                                what a screen shows in that case.
     *
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        $pk = (int) ($pk ?: $this->getState($this->getName() . '.id'));

        // THE CLEAR IS NOT HERE ANY MORE, and the comment that used to sit here
        // is why: it said "opening an order clears its notification", but
        // getItem() is not opening an order. It is every load of one: the
        // storefront's process page, EmailService composing the confirmation,
        // and placement's own reload of the order it has just created. That last
        // one runs in the same request as the push, so the new-order bell was
        // destroyed by the very placement that raised it and no operator ever
        // saw one. Clearing now happens where an operator genuinely opens the
        // record: administrator/src/View/Order/HtmlView.php's edit branch.

        if ($order = parent::getItem($pk)) {
            $db = $this->getDatabase();

            // A NEW order (manual creation form) has no id yet: typed loaders
            // below get a hard 0 and return their empty shapes, and the form's
            // money is read in the currency a new order takes. An order that
            // exists is read in the currency it names, or not at all.
            $orderId = (int) ($order->id ?? 0);
            $currency = $orderId > 0
                ? OrderCurrency::ofRow(orderId: $orderId, currencyId: $order->id_currency ?? null, scale: $order->currency_scale ?? null)
                : OrderCurrency::forNewOrder();

            // 1. Get order items (with money objects)
            $order->items = OrderHelper::getOrderItems($orderId, $currency);

            // Store currency object
            $order->currency = $currency;

            // Get user info
            $this->attachCustomer(order: $order);

            // Get payments (with money objects)
            $paymentMethods = $this->getOrderPayments($orderId);
            $paymentModel = $this->getRelatedModel('Payment');

            $order->payments = [];

            foreach ($paymentMethods as $paymentMethod) {
                $payment = $paymentMethod;
                $payment->params = $paymentModel->getItem($paymentMethod->id_payment_method);

                // Convert to Money objects
                $paymentAmount = $this->toMoney($payment->amount, $currency);
                $payment->amount = $paymentAmount;
                $payment->conversion_rate = (float) ($payment->conversion_rate ?? 1.0);

                // Load plugin actions for this payment (Refund, Capture, etc.)
                $payment->actions = ActionRegistry::getPaymentActions($payment, $order);

                $order->payments[] = $payment;
            }

            // The edit view used to accumulate its own paid/refunded totals,
            // and the totals card computed its own clamped balance on top:
            // two more answers to a question that has one. Both read the same
            // facts as the list and the API now.
            $facts = OrderFacts::forOrder($orderId);

            $order->payment_facts = $facts;
            $order->total_paid_real = Money::of($facts->paid_real, $currency);
            $order->total_refunded_real = Money::of($facts->refunded_real, $currency);
            $order->still_held = Money::of($facts->still_held, $currency);
            // Taken back by the customer's bank: an orthogonal fact, never a refund.
            $order->charged_back = Money::of($facts->charged_back, $currency);
            // Signed: negative = overpaid.
            $order->balance = Money::of($facts->balance, $currency);
            // The reconciliation projection, for the order screen's own panel.
            // The SAME class the list asks, so the two cannot disagree about
            // one order.
            $order->reconciliation_facts = ReconciliationFacts::forOrder($orderId);
            // The same subtraction read from the other end, so the totals card
            // no longer computes an abs() of its own.
            $order->excess = Money::of($facts->excess, $currency);

            $order->selected_payment = $paymentModel->getItem($order->id_payment_method);

            // Get shipments (with money objects)
            $shipmentMethods = $this->getOrderShipments($orderId);
            $shipmentModel = $this->getRelatedModel('Shipment');

            $order->shipments = [];
            foreach ($shipmentMethods as $shipmentMethod) {
                $shipment = $shipmentMethod;
                $shipment->params = $shipmentModel->getItem($shipmentMethod->id_shipment_method);

                // Convert to Money objects
                $shipment->shipping_cost_tax_incl = $this->toMoney($shipment->shipping_cost_tax_incl, $currency);
                $shipment->shipping_cost_tax_excl = $this->toMoney($shipment->shipping_cost_tax_excl, $currency);
                $shipment->weight = (float) ($shipment->weight ?? 0);

                // Items assigned to this shipment (for list display)
                $shipment->items = $this->getShipmentItemNames((int) $shipment->id);

                $shipment->actions = ActionRegistry::getShipmentActions($shipment, $order);

                $order->shipments[] = $shipment;
            }

            $order->selected_shipment = $shipmentModel->getItem($order->id_shipment_method);

            // 5. CALCULATE ORDER TOTALS: Single Source of Truth
            //
            // Delegates to OrderTotalHelper::computeFromArrays() which
            // contains THE formula: grand_total = items + shipping - discounts
            //
            // Uses already-loaded entities from steps 1 and 4.
            // Only discounts need a DB query (not loaded elsewhere).
            //
            // The helper handles:
            //   - Money object extraction (via extractFloat)
            //   - Discount tax-inclusive approximation (excl x avg tax multiplier)
            //   - Both incl and excl totals in one call

            // Load discount cart rules (only entity not loaded in earlier steps)
            $discountQuery = $db->getQuery(true)
                ->select(['value_tax_excl', 'value_tax_incl', 'discount_class', 'free_shipping'])
                ->from('#__alfa_order_cart_rule')
                ->where('id_order = ' . (int) $order->id)
                ->where('deleted = 0');
            $db->setQuery($discountQuery);
            $discountRows = $db->loadObjectList() ?: [];

            // Load frozen cart contributions (labeled charge rows, for the
            // totals card and the formula alike)
            $contributionQuery = $db->getQuery(true)
                ->select('*')
                ->from('#__alfa_order_contributions')
                ->where('id_order = ' . (int) $order->id)
                ->order('sort_order ASC');
            $db->setQuery($contributionQuery);
            $order->contributions = $db->loadObjectList() ?: [];

            foreach ($order->contributions as $contribution) {
                $contribution->amount_tax_incl_money = Money::of((float) $contribution->amount_tax_incl, $currency);
            }

            // Compute all totals through the single source of truth: the
            // same loader-backed path every other reader uses. Passing the
            // Money-hydrated in-memory arrays here re-rounded every line to
            // the display scale before summing, so the edit view could differ
            // from the helper/list by sub-cent legacy amounts; and the local
            // shipment array included cancelled/returned parcels the formula
            // must ignore. getBreakdown() loads raw rows with the ONE
            // population definition, so all readers agree by construction.
            $totals = OrderTotalHelper::getBreakdown((int) $order->id);

            // Wrap results as Money objects for the view templates
            $order->total_products_tax_incl = Money::of($totals->items_tax_incl, $currency);
            $order->total_products_tax_excl = Money::of($totals->items_tax_excl, $currency);
            $order->total_shipping_tax_incl = Money::of($totals->shipping_tax_incl, $currency);
            $order->total_shipping_tax_excl = Money::of($totals->shipping_tax_excl, $currency);
            $order->total_contributions_tax_incl = Money::of($totals->contributions_tax_incl, $currency);
            $order->total_contributions_tax_excl = Money::of($totals->contributions_tax_excl, $currency);
            $order->total_discounts_tax_incl = Money::of($totals->discount_tax_incl, $currency);
            $order->total_discounts_tax_excl = Money::of($totals->discount_tax_excl, $currency);
            $order->total_paid_tax_incl = Money::of($totals->grand_total_tax_incl, $currency);
            $order->total_paid_tax_excl = Money::of($totals->grand_total_tax_excl, $currency);

            // 6. Get order history
            $order->history = $this->getOrderHistory($orderId);

            // Get currency data
            $order->currency_data = $currency;

            $this->item = $order;
            return $order;
        }

        return null;
    }

    /**
     * An order that names no currency, as a screen may still show it: who and
     * what it is, and its stored rows, with no money built from them.
     *
     * {@see self::getItem()} refuses such an order, because every amount it
     * hands over is Money and there is no currency to make Money in. An
     * administrator must still be able to open the order and read its id, and
     * a client reading it is told what is stored rather than refused, so the
     * order screen and the REST order ask here instead. Nothing returned is
     * formatted, and no fact that needs a currency (the balance, the paid
     * state) is derived.
     *
     * @param int $pk Order PK.
     *
     * @return object|null The order row with its customer (user_name,
     *                     user_email, customer_kind, user_info, invoice_info),
     *                     the stored items, payments, shipments and
     *                     contributions rows, its history, and
     *                     currency = null; null when there is no such order.
     *
     * @since  2.0.0
     */
    public function getItemWithoutMoney(int $pk): ?object
    {
        $order = $pk > 0 ? parent::getItem($pk) : null;

        if (!\is_object($order) || (int) ($order->id ?? 0) !== $pk) {
            return null;
        }

        $db = $this->getDatabase();

        $this->attachCustomer(order: $order);

        $order->currency = null;
        $order->currency_data = null;

        $order->items = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = ' . $pk)
                ->order($db->quoteName('added') . ' ASC'),
        )->loadObjectList() ?: [];

        foreach ($order->items as $line) {
            // Frozen {lang: text} name, in the reader's language.
            $line->name = MultilingualHelper::resolveLangMap($line->name);
        }

        $order->payments = $this->getOrderPayments($pk) ?: [];
        $order->shipments = $this->getOrderShipments($pk) ?: [];

        $order->contributions = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_order_contributions'))
                ->where($db->quoteName('id_order') . ' = ' . $pk)
                ->order($db->quoteName('sort_order') . ' ASC'),
        )->loadObjectList() ?: [];

        $order->history = $this->getOrderHistory($pk);

        return $order;
    }

    /**
     * The customer of an order, and the delivery and invoice records it
     * points at.
     *
     * The customer is the order's own snapshot (OrderIdentity): a guest, an
     * account, or an account since deleted. Shown for every order; the
     * delivery and invoice details are the dynamic fields.
     *
     * @param object $order The order row; user_name, user_email,
     *                      customer_kind, user_info and invoice_info are set
     *                      on it.
     *
     *
     * @since  2.0.0
     */
    private function attachCustomer(object $order): void
    {
        $db = $this->getDatabase();

        $customer = OrderIdentity::forOrder($order);
        $order->user_name = $customer['name'];
        $order->user_email = $customer['email'];
        $order->customer_kind = Text::_('COM_ALFA_CUSTOMER_KIND_' . strtoupper($customer['kind']));

        if ($order->id_address_delivery) {
            $query = $db->getQuery(true);
            $query->select('*')
                ->from('#__alfa_user_info')
                ->where('id = ' . $db->quote($order->id_address_delivery));
            $db->setQuery($query);
            $order->user_info = $db->loadObject();
        }

        // The invoice record, when the order bills to one of its own. Same
        // id as delivery = "invoice to the delivery address", so the row is
        // already loaded above and is deliberately not fetched twice.
        if ($order->id_address_invoice && (int) $order->id_address_invoice !== (int) $order->id_address_delivery) {
            $query = $db->getQuery(true);
            $query->select('*')
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . (int) $order->id_address_invoice);
            $db->setQuery($query);
            $order->invoice_info = $db->loadObject();
        }
    }

    // Payments

    public function getPaymentData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_payments', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);
        $payment = $db->loadObject();

        if ($payment) {
            $paymentModel = $this->getRelatedModel('Payment');
            $payment->params = $paymentModel->getItem($payment->id_payment_method);

            // A payment is money in its order's currency, and in no other. Its
            // popup shows the figure as stored, at the currency's decimal places
            // (19.40), so a row finer than its order's money is refused on Save
            // rather than rounded by the display.
            $currency = OrderCurrency::of(orderId: (int) $payment->id_order);
            $payment->stored_figures = $this->storedFigures(row: $payment, columns: ['amount'], currency: $currency);
            $payment->amount = $this->toMoney($payment->amount, $currency);
        }

        return $payment;
    }

    /**
     * Load all payment rows for an order from #__alfa_order_payments.
     *
     * @param int $orderId The order id.
     *
     * @return array An array of payment row objects.
     *
     * @since  1.0.0
     */
    public function getOrderPayments($orderId)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_order_payments')
            ->where('id_order = ' . $db->quote($orderId));

        $db->setQuery($query);
        return $db->loadObjectList();
    }

    /**
     * Save or update an order payment record.
     *
     * Delegates to PaymentLedger for all database operations,
     * activity logging, and server-side defaults.
     *
     * The helper handles:
     *   - the amount as the exact decimal text of the order's Money
     *   - Method name snapshotting
     *   - Auto-fill: added, id_employee, id_currency
     *   - Change detection and activity logging
     *
     * @param array &$data Form data (by reference, PK set on insert)
     *
     *
     * @since  1.0.0
     */
    public function saveOrderPayment(&$data): bool
    {
        // Strip nested 'payment' key if present (from form binding)
        $saveData = $data;
        if (isset($saveData['payment'])) {
            unset($saveData['payment']);
        }

        $isNew = empty($saveData['id']) || (int) $saveData['id'] === 0;

        if ($isNew) {
            // Create
            // A linked refund is recorded only by the payment's Refund action,
            // which writes the link.
            if ((int) ($saveData['id_refunded_payment'] ?? 0) > 0) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_PAYMENT_REFUND_LINK_ON_NEW_REFUSED'), 'error');

                return false;
            }

            // A reversal or a chargeback is recorded from the payment's actions.
            if (\in_array((string) ($saveData['payment_type'] ?? ''), PaymentLedger::FACT_TYPES, true)) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_PAYMENT_FACT_TYPE_REFUSED'), 'error');

                return false;
            }

            if ($this->refuseTypedRefund(data: $saveData, stored: null)) {
                return false;
            }

            // A refund typed by staff is a refund start: create() decides it again under the order's lock.
            $newId = PaymentLedger::create(data: $saveData, refundStart: true);

            if ($newId === false) {
                return false;
            }

            $data['id'] = $newId;

            return true;
        }

        // Update
        $id = (int) $saveData['id'];

        $db = $this->getDatabase();
        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order', 'payment_type', 'transaction_status', 'amount', 'id_refunded_payment', 'transaction_id', 'id_payment_method', 'payment_method_type', 'id_credit_transaction']))
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id') . ' = ' . $id),
        )->loadObject();

        if ($stored !== null && $this->refuseTypedRefund(data: $saveData, stored: $stored)) {
            return false;
        }

        // The payment form is a staff entry point: its write is held to the staff guard (since 2.1.0).
        $success = PaymentLedger::update($id, $saveData, staff: true);

        if ($success) {
            $data['id'] = $id;
        }

        return $success;
    }

    /**
     * Refuse a refund typed into the payment form while the refund block holds,
     * or beyond what the order may still refund once its pending provider
     * refunds are reserved: the ledger decides
     * ({@see PaymentLedger::staffRefundRefusal()},
     * {@see PaymentLedger::staffRefundReservedRefusal()}), this tells the
     * operator, pointing to Record this refund and the resolutions for the block.
     * A refund already in the books may still be edited or settled; raising a
     * pending or authorized one is decided as a start for its increase.
     *
     * @param array $data The form's save data.
     * @param object|null $stored The row as stored (id, id_order, payment_type,
     *                            transaction_status, amount, id_refunded_payment,
     *                            transaction_id, id_payment_method,
     *                            payment_method_type, id_credit_transaction);
     *                            null for a new row.
     *
     * @return bool True when the save is refused; the reason is enqueued for the operator.
     *
     * @since  2.1.0
     */
    private function refuseTypedRefund(array $data, ?object $stored): bool
    {
        $orderId = (int) ($data['id_order'] ?? $stored->id_order ?? 0);

        // The staff guard, asked first so the operator is told before anything is written: a reservation whose money
        // the provider moved goes through the way out, never through this form; a gateway payment's pending refund
        // keeps its amount and reference and is settled by its provider only; a gateway payment keeps its method and
        // its provider transaction id (since 2.1.0).
        $moved = $stored !== null ? PaymentLedger::staffWriteRefusal(stored: $stored, data: $data) : null;

        if ($moved !== null) {
            Factory::getApplication()->enqueueMessage(htmlspecialchars($moved, ENT_QUOTES, 'UTF-8'), 'error');

            return true;
        }

        $refusal = PaymentLedger::staffRefundRefusal(stored: $stored, data: $data, orderId: $orderId);
        $key = 'COM_ALFA_PAYMENT_REFUND_TYPED_BLOCKED';

        if ($refusal === null) {
            $refusal = PaymentLedger::staffRefundReservedRefusal(stored: $stored, data: $data, orderId: $orderId);
            $key = 'COM_ALFA_PAYMENT_REFUND_TYPED_RESERVED';
        }

        if ($refusal === null) {
            return false;
        }

        // Escaped: the reason quotes the provider's refund ids.
        Factory::getApplication()->enqueueMessage(htmlspecialchars(Text::sprintf($key, $refusal), ENT_QUOTES, 'UTF-8'), 'error');

        return true;
    }

    /**
     * Delete an order payment record.
     *
     * Delegates to PaymentLedger::delete() which handles
     * pre-delete snapshot and activity logging.
     *
     * @param int $id Payment row PK
     * @param int $id_order Order PK
     *
     *
     * @since  1.0.0
     */
    public function deleteOrderPayment($id, $id_order): bool
    {
        return PaymentLedger::delete((int) $id, (int) $id_order);
    }

    /**
     * Load a single payment row by its primary key from #__alfa_order_payments.
     *
     * @param int $id The payment row id.
     *
     * @return object|null The payment row object, or null if not found.
     *
     * @since  1.0.0
     */
    protected function getOrderPayment($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from($db->qn('#__alfa_order_payments'))
            ->where($db->qn('id') . '=' . $id);

        $db->setQuery($query);
        return $db->loadObject();
    }

    // Shipments

    public function getShipmentData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_shipments', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);
        $shipment = $db->loadObject();

        if ($shipment) {
            $shipmentModel = $this->getRelatedModel('Shipment');
            $shipment->params = $shipmentModel->getItem($shipment->id_shipment_method);

            // A shipping cost is money in its order's currency, and in no other;
            // the popup shows the costs as stored (see getPaymentData()).
            $currency = OrderCurrency::of(orderId: (int) $shipment->id_order);
            $shipment->stored_figures = $this->storedFigures(row: $shipment, columns: ['shipping_cost_tax_incl', 'shipping_cost_tax_excl'], currency: $currency);
            $shipment->shipping_cost_tax_incl = $this->toMoney($shipment->shipping_cost_tax_incl, $currency);
            $shipment->shipping_cost_tax_excl = $this->toMoney($shipment->shipping_cost_tax_excl, $currency);
        }

        return $shipment;
    }

    /**
     * Load all shipment rows for an order from #__alfa_order_shipments.
     *
     * @param int $orderId The order id.
     *
     * @return array An array of shipment row objects.
     *
     * @since  1.0.0
     */
    public function getOrderShipments($orderId)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_order_shipments')
            ->where('id_order = ' . $db->quote($orderId));

        $db->setQuery($query);
        return $db->loadObjectList();
    }

    /**
     * Save or update an order shipment record.
     *
     * Delegates to ShipmentLedger for all database operations,
     * item assignment, and activity logging. The parcel popup and the REST
     * shipment verbs save here, so the charge is one an operator entered:
     * the ledger judges it against the entry mode it is entered under.
     *
     * The helper handles:
     *   - the costs as the exact decimal text of the order's Money
     *   - Method name snapshotting
     *   - Auto-fill: added, id_employee, id_currency
     *   - Item assignment via 'items' key
     *   - Change detection and activity logging
     *
     * @param array &$data Form data (by reference, PK set on insert)
     *
     *
     * @since  1.0.0
     */
    public function saveOrderShipment(&$data): bool
    {
        // Strip nested 'shipment' key if present (from form binding)
        $saveData = $data;
        if (isset($saveData['shipment'])) {
            unset($saveData['shipment']);
        }

        $isNew = empty($saveData['id']) || (int) $saveData['id'] === 0;

        if ($isNew) {
            // Create
            $newId = ShipmentLedger::create($saveData, entered: true);

            if ($newId === false) {
                return false;
            }

            $data['id'] = $newId;

            return true;
        }

        // Update
        $id = (int) $saveData['id'];

        $success = ShipmentLedger::update($id, $saveData, entered: true);

        if ($success) {
            $data['id'] = $id;
        }

        return $success;
    }

    /**
     * Delete an order shipment record.
     *
     * Delegates to ShipmentLedger::delete() which handles
     * item unassignment, pre-delete snapshot, and activity logging.
     *
     * @param int $id Shipment row PK
     * @param int $id_order Order PK
     *
     *
     * @since  1.0.0
     */
    public function deleteOrderShipment($id, $id_order): bool
    {
        return ShipmentLedger::delete((int) $id, (int) $id_order);
    }

    /**
     * Assign order items to a shipment.
     *
     * Delegates to ShipmentLedger::assignItems().
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $selectedItemIds Array of order_items.id values
     *
     *
     * @since  1.0.0
     */
    protected function assignItemsToShipment(int $shipmentId, int $orderId, array $selectedItemIds): void
    {
        ShipmentLedger::assignItems($shipmentId, $orderId, $selectedItemIds);
    }

    /**
     * Get item IDs assigned to a shipment (for form pre-selection).
     *
     * Delegates to ShipmentLedger::getItemIds().
     *
     * @param int $shipmentId Shipment PK
     *
     * @return array Array of order_items.id values
     *
     * @since  1.0.0
     */
    protected function getShipmentItemIds(int $shipmentId): array
    {
        return ShipmentLedger::getItemIds($shipmentId);
    }

    /**
     * Get human-readable item names assigned to a shipment.
     *
     * Delegates to ShipmentLedger::getItemNames().
     *
     * @param int $shipmentId Shipment PK
     *
     * @return string Comma-separated item names, or empty string
     *
     * @since  1.0.0
     */
    protected function getShipmentItemNames(int $shipmentId): string
    {
        return ShipmentLedger::getItemNames($shipmentId);
    }

    /**
     * Load a raw shipment row by PK (for diffs and logging)
     *
     * @param int $id Shipment row PK
     * @return object|null
     * @since  1.0.0
     */
    protected function getOrderShipment($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_order_shipments'))
            ->where($db->qn('id') . ' = ' . intval($id));
        $db->setQuery($query);
        return $db->loadObject();
    }

    // ORDER ITEM CONFIGURATION (re-configure a line's item-fields, admin popup)

    /**
     * Load an item hydrated with its item-fields data (fields / options /
     * combinations), the same shape the storefront configures against, so the
     * admin re-configure renders and resolves through identical inputs.
     *
     * @param int $itemId The #__alfa_items row id.
     *
     * @return object|null The hydrated item, or null when not found.
     * @since  2.0.0
     */
    protected function loadConfiguredItem(int $itemId): ?object
    {
        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $itemId),
        );
        $item = $db->loadObject();

        if (!$item) {
            return null;
        }

        $data = ItemFieldsHelper::getDataForItem($itemId);
        $item->fields = $data->fields;
        $item->options = $data->options;
        $item->combinations = $data->combinations;
        $item->informational = $data->informational;

        return $item;
    }

    /**
     * Prepare an order line for the admin re-configure popup: the item with the
     * SAME interactive field surfaces the customer configured on (each field's
     * plugin renders its own input), seeded from the line's frozen snapshot:
     * variant/option selectors pre-selected via the resolved variant, payload
     * plugins via the render event's current value.
     *
     * WHAT WAS SOLD IS A READ-ONLY FACT.
     * A line whose combination the catalogue no longer offers (unpublished
     * or deleted), or whose snapshot no longer resolves to it, cannot be drawn
     * as sold: seeding selectors would draw something else. Such a line is
     * shown read-only (soldReadOnly), with no surfaces, so the popup posts no
     * configuration and a quantity edit rescales the line's own money.
     *
     * Otherwise the seed is the line's frozen snapshot, reconstructed into the
     * same shape the product page's request parser produces, or, for a variant
     * line that froze no snapshot, its stored combination (never the item's
     * default). The seed is built FROM the configured item (variant fields
     * decide the split), so it is handed in as a callback rather than
     * precomputed. It is the SAME selection the save compares a posted one
     * against, so a popup opened and saved unchanged is no reconfigure.
     *
     * A refused save's redisplay seeds the configuration that save posted
     * instead, so the operator's choices come back as they were made; what
     * was sold, and whether it can still be drawn, is decided as above.
     *
     * @param int $orderItemId The #__alfa_order_items row id.
     * @param array|null $keptConfiguration The configuration a refused save posted
     *                                      ({@see self::postedSelection()}), or null for the frozen one.
     *
     * @return object|null {orderItem, item, soldReadOnly}, or null when the line/item is gone.
     * @since  2.0.0
     */
    public function getOrderItemConfig(int $orderItemId, ?array $keptConfiguration = null): ?object
    {
        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id') . ' = ' . $orderItemId),
        );
        $orderItem = $db->loadObject();

        if (!$orderItem) {
            return null;
        }

        $configured = $this->loadConfiguredItem(itemId: (int) $orderItem->id_item);

        if (!$configured) {
            return null;
        }

        [$offered, $sold] = $this->soldConfigurationOf(row: $orderItem, item: $configured);
        $config = (object) ['orderItem' => $orderItem];
        $config->soldReadOnly = !$offered || !$sold['resolves'];
        $config->item = $config->soldReadOnly ? self::drawnAsSold(item: $configured) : $this->getSeededItemConfig(
            itemId:  (int) $orderItem->id_item,
            seedFor: fn (object $configured): array => $keptConfiguration !== null
                ? self::postedSelection(configuration: $keptConfiguration)
                : $this->frozenSelectionOf(
                    orderItemId:   $orderItemId,
                    item:          $configured,
                    combinationId: (int) ($orderItem->id_combination ?? 0),
                ),
        );

        return $config->item ? $config : null;
    }

    /**
     * A configured item drawn as sold: from the snapshot and the line's frozen name, never seeded, with no
     * surface, so the popup posts nothing and nothing is re-chosen.
     *
     * @param object $item The configured item.
     *
     * @return object The same item, without surfaces.
     *
     * @since  2.0.0
     */
    private static function drawnAsSold(object $item): object
    {
        $item->surfaces = [];

        return $item;
    }

    /**
     * Whether a line's sold combination is still offered, and whether its frozen configuration still resolves to
     * it.
     *
     * @param object $row The stored order line.
     * @param object $item The configured item.
     *
     * @return array{0: bool, 1: array{resolves: bool, variant: ?VariantSelection}} [offered, the resolution].
     *
     * @since  2.0.0
     */
    private function soldConfigurationOf(object $row, object $item): array
    {
        $stored = (int) ($row->id_combination ?? 0);
        $soldFrozen = $this->frozenSelectionOf(orderItemId: (int) $row->id, item: $item, combinationId: $stored);
        $offered = $stored <= 0 || \in_array($stored, array_map(static fn (object $combination): int => (int) $combination->id, (array) ($item->combinations ?? [])), true);

        return [$offered, $this->resolveSoldConfiguration(row: $row, item: $item, frozen: $soldFrozen, context: OrderHelper::buildOrderPriceContext(orderId: (int) $row->id_order))];
    }

    /**
     * Prepare a catalog item for CONFIGURE-FIRST adding: the same interactive
     * field surfaces as an existing line's re-edit, seeded with the item's
     * defaults (no frozen snapshot yet: the line does not exist until save),
     * or, on a refused save's redisplay, with the configuration that save posted.
     *
     * @param int $itemId The #__alfa_items row id.
     * @param array|null $keptConfiguration The configuration a refused save posted
     *                                      ({@see self::postedSelection()}), or null for the defaults.
     *
     * @return object|null The hydrated item with surfaces, or null.
     * @since  2.0.0
     */
    public function getNewItemConfig(int $itemId, ?array $keptConfiguration = null): ?object
    {
        // Nothing stored yet: the empty seed preselects the DEFAULT combination;
        // a refused save's configuration reopens as it was posted.
        return $this->getSeededItemConfig(
            itemId: $itemId,
            seedFor: $keptConfiguration !== null
                ? static fn (object $item): array => self::postedSelection(configuration: $keptConfiguration)
                : null,
        );
    }

    /**
     * THE ONE configuration-seeding path: a catalog item hydrated with its
     * interactive field surfaces, pre-selected from a stored selection.
     *
     * Every popup that configures a line goes through here: the order line's
     * re-edit (seeded from its frozen snapshot), the order's configure-first add
     * and the cart builder's line popup (seeded from the live cart line), so
     * one item renders one way, whatever screen asked for it. The seed is built
     * from the CONFIGURED item, because splitting variant fields from plain
     * option values needs the item's combinations; that is why the caller hands
     * in a builder rather than a finished array.
     *
     * No price context is applied here: this prepares configuration, and each
     * caller prices the result in ITS own context (the order's currency, or the
     * cart customer's price context).
     *
     * @param int $itemId The #__alfa_items row id.
     * @param callable|null $seedFor fn(object $item): array - the stored
     *                               selection in lineSelection() shape, or null
     *                               for a fresh configure.
     *
     * @return object|null The hydrated item with surfaces, or null.
     * @since  2.0.0
     */
    public function getSeededItemConfig(int $itemId, ?callable $seedFor = null): ?object
    {
        $item = $this->loadConfiguredItem(itemId: $itemId);

        if (!$item) {
            return null;
        }

        $seed = $seedFor !== null ? $seedFor($item) : [];
        $selection = $seed['selection'] ?? [];
        $optionValueIds = array_values($seed['optionValueIds'] ?? []);
        $payloads = $seed['payloads'] ?? [];

        // Resolve the variant so enumerable selectors render pre-selected (the
        // same state the storefront derives from the request). An empty seed
        // resolves to the item's default combination.
        VariantHelper::resolve($item, $selection, $optionValueIds, $payloads);
        $item->selectedOptionValueIds = $optionValueIds;

        // The display name is translatable: resolve it for the popup header.
        $named = MultilingualHelper::getRecordsByIds(
            $this->getDatabase(),
            [$itemId],
            '#__alfa_items',
            'id',
            [],
            '#__alfa_items',
            'id_item',
            ['name'],
        );
        $item->name = $named[$itemId]['name'] ?? ('#' . $itemId);

        $this->dispatchItemConfigSurfaces(item: $item, seed: ['payloads' => $payloads]);

        return $item;
    }

    /**
     * Fire each field plugin's item-view render and harvest the buy-form
     * surfaces onto the item, handing every plugin its stored value (null on
     * a fresh configure) so payload widgets re-open on their composition.
     *
     * @param object $item The hydrated item (fields + variant resolved).
     * @param array $seed lineSelection()-shaped seed (payloads consumed here).
     *
     * @since  2.0.0
     */
    protected function dispatchItemConfigSurfaces(object $item, array $seed): void
    {
        $app = Factory::getApplication();
        $item->surfaces = [];

        // Conditional visibility: the SAME showon rules + initial-state painting
        // as the storefront, so a field hidden for the customer's selection is
        // hidden for the admin too (the JS engine drives the live toggles).
        $showonBag = StateBagBuilder::fromItem($item);

        foreach ($item->fields as $field) {
            try {
                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');
            } catch (Throwable $e) {
                continue;
            }

            if (!$plugin instanceof ItemFieldsPlugin) {
                continue;
            }

            $event = new ItemViewEvent('onItemView', [
                'subject' => $item,
                'method' => $field,
                'currentValue' => $seed['payloads'][(int) $field->id] ?? null,
                'selections' => [
                    'sel' => (array) ($seed['selection'] ?? []),
                    'opt' => array_map('intval', (array) ($seed['optionValueIds'] ?? [])),
                ],
            ]);

            $plugin->onItemView($event);

            Surfaces::appendTo(
                $item->surfaces,
                $event,
                'alfa-item-fields',
                (string) $field->type,
                ItemSurface::BuyForm->value,
                Surfaces::showonAttr($field) . InitialState::hiddenAttr($field, $showonBag),
                field: $field,
            );
        }
    }

    /**
     * What the posted configuration costs the cart's customer, at the posted
     * quantity, with the edited line's stored custom price: the quote the cart
     * will charge.
     *
     * The admin cart builder's popup asks in the CART CUSTOMER's context. It is
     * the cart line's own pricing call (CartHydrator), so the quote carries every
     * catalogue discount and follows the quantity box. The order-line popup does
     * not ask here: its figures are the save's own dry-run
     * ({@see self::planOrderItemLine()}). The selection is read the storefront's
     * own way, through VariantHelper::requestSelection().
     *
     * @param int $itemId The #__alfa_items row id.
     * @param PriceContext $context The pricing context to answer in.
     * @param int $quantity The quantity to price.
     * @param ExplicitPriceInstruction|null $price The edited line's stored custom price; null for none.
     *
     * @return PriceResult|null The priced line, or null when there is no such item.
     *
     * @throws RuntimeException When the quantity is below 1.
     *
     * @since  2.0.0
     */
    public function priceConfigIn(int $itemId, PriceContext $context, int $quantity, ?ExplicitPriceInstruction $price): ?PriceResult
    {
        // A quote is for a quantity the cart can hold: 0, a negative number or an
        // empty box is refused, never quoted as 1.
        if ($quantity < 1) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_QUANTITY_INVALID'));
        }

        $item = $this->loadConfiguredItem(itemId: $itemId);

        if (!$item) {
            return null;
        }

        $request = VariantHelper::requestSelection();

        VariantHelper::resolveAndPrice(
            item: $item,
            selection: $request['selection'],
            context: $context,
            calculator: new PriceCalculator(),
            quantity: $quantity,
            optionValueIds: array_values($request['optionValueIds']),
            payloads: $request['payloads'],
            price: $price,
        );

        return $item->price;
    }

    // ORDER ITEMS (single-item CRUD, for admin popup)

    /**
     * Get a single order item by PK
     * Mirrors getPaymentData() / getShipmentData() pattern
     *
     * @param int|null $pk Order item row PK
     * @return object|null
     * @since  1.0.0
     */
    public function getOrderItemData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_items', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . intval($pk));

        $db->setQuery($query);
        $item = $db->loadObject();

        if ($item) {
            // Frozen {lang: text} name -> the admin language (legacy plain passes through).
            $item->name = MultilingualHelper::resolveLangMap($item->name);

            // A line's money is its order's: the order's own currency.
            $currency = OrderCurrency::of(orderId: (int) $item->id_order);

            // The stored unit decimals, for the popup's unit rows: a custom rate
            // finer than the currency shows its own precision.
            $item->unit_price_tax_incl_stored = (string) $item->unit_price_tax_incl;
            $item->unit_price_tax_excl_stored = (string) $item->unit_price_tax_excl;

            // Convert prices to Money
            $item->unit_price_tax_incl = $this->toMoney($item->unit_price_tax_incl, $currency);
            $item->unit_price_tax_excl = $this->toMoney($item->unit_price_tax_excl, $currency);
            $item->total_price_tax_incl = $this->toMoney($item->total_price_tax_incl, $currency);
            $item->total_price_tax_excl = $this->toMoney($item->total_price_tax_excl, $currency);
            $item->original_product_price = $this->toMoney($item->original_product_price, $currency);
            $item->currency = $currency;

            // No per-unit `price` alias: it was the stored unit as Money at the
            // currency's scale (a finer unit read 0.00), and nothing reads it.
            $item->total = $item->total_price_tax_incl;
        }

        return $item;
    }

    /**
     * Get order item form (for popup edit)
     * Mirrors getPaymentForm() / getShipmentForm() pattern exactly
     *
     * Loads order_items.xml, binds existing item data for editing.
     *
     * @param array|object $data Existing item data to bind (or empty for new)
     * @param bool $loadData Whether to load data
     * @return \Joomla\CMS\Form\Form|false
     * @since  1.0.0
     */
    public function getOrderItemForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_items', [
            'control' => 'jform',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_items.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        if ($id > 0 && empty($data)) {
            $data = $this->getOrderItemData($id);
        }

        // Convert object -> array with Money->float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        // The price inputs' step follows the ORDER currency's precision (0.01
        // for a 2dp coin, 0.00000001 for an 8dp one), never a hardcoded scale.
        $currency = $orderId > 0 ? OrderCurrency::of(orderId: $orderId) : OrderCurrency::forNewOrder();
        $decimals = $currency->getDecimalPlaces();
        $step = $decimals > 0 ? '0.' . str_repeat('0', $decimals - 1) . '1' : '1';

        foreach (['unit_price_tax_incl', 'unit_price_tax_excl', 'total_price_tax_incl', 'discount_amount_tax_incl'] as $priceField) {
            $form->setFieldAttribute($priceField, 'step', $step);
        }

        // No discount prefill. The popup's manual discount is an INSTRUCTION
        // input that opens empty; the line's stored whole-line saving is
        // shown as a fact beside it and is never bound back as a percent to
        // re-apply, nor inferred as "manual" from its money.

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }
        }

        $form->bind($data);

        // Always hide id_item dropdown; product selection is handled by:
        //   New items:      AJAX search populates the hidden field
        //   Existing items: Pre-filled, shown in header, cannot be changed
        $form->setFieldAttribute('id_item', 'type', 'hidden');

        return $form;
    }

    /**
     * Resolve the identity snapshot (sku/gtin/mpn/weight) of an order line:
     * the combination's own value wins, empty (NULL for weight) inherits the
     * item: the same rule VariantHelper::overlay() applies on the storefront
     * when the line is first frozen.
     *
     * Single source for both writers (the line save and the configuration
     * re-freeze); they used to carry the rule separately, which is how the
     * line save drifted to item-level values and reverted variant identity.
     *
     * @param int $combinationId Combination id; 0 when the line is not a variant.
     * @param object|null $item Catalog item row used as the fallback source.
     *
     * @return object Object carrying sku, gtin, mpn and weight.
     *
     * @since  2.0.0
     */
    private function resolveLineIdentity(int $combinationId, ?object $item): object
    {
        $combo = null;

        if ($combinationId > 0) {
            $db = $this->getDatabase();

            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['sku', 'gtin', 'mpn', 'weight']))
                    ->from($db->quoteName('#__alfa_item_combinations'))
                    ->where($db->quoteName('id') . ' = ' . (int) $combinationId),
            );

            $combo = $db->loadObject();
        }

        // Each code falls back to the item's when the variant leaves it blank:
        // the same inherit rule VariantHelper::SCALARS applies on the storefront,
        // so the line, the product page and any feed agree. Frozen here so a later
        // catalogue edit never rewrites a placed order's identity.
        return (object) [
            'sku' => ($combo->sku ?? '') !== '' ? $combo->sku : (string) ($item->sku ?? ''),
            'gtin' => ($combo->gtin ?? '') !== '' ? $combo->gtin : (string) ($item->gtin ?? ''),
            'mpn' => ($combo->mpn ?? '') !== '' ? $combo->mpn : (string) ($item->mpn ?? ''),
            'weight' => ($combo->weight ?? null) !== null ? (float) $combo->weight : (float) ($item->weight ?? 0),
        ];
    }

    /**
     * A line quantity as the one rule reads it: a whole number from 1 up to
     * what the quantity column holds.
     *
     * The same test at both doors and in the writer. An int, or a
     * string of digits, is a quantity. Zero, a negative, a fraction ("2.9" or
     * 2.9), a boolean, an empty string and text are not, and are refused
     * rather than coerced: (int) "abc" is 0, max(1, 0) is 1, and a line the
     * operator never asked to shrink was rescaled to one unit.
     *
     * @param mixed $value The posted or sent quantity.
     *
     * @return int|null The quantity, or null when the value is not one.
     *
     * @since  2.0.0
     */
    public static function positiveQuantity(mixed $value): ?int
    {
        if (\is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) {
            $value = (int) $value;
        }

        // 4294967295 is the ceiling of the unsigned INT column the line stores.
        return \is_int($value) && $value >= 1 && $value <= 4294967295 ? $value : null;
    }

    /**
     * A manual discount percentage as the one rule reads it: a number from 0
     * to 100 in whole hundredths, the precision of the column that stores it.
     *
     * The same test at both doors and in the writer. The manual discount is
     * the line's stored setting, so what is accepted is
     * exactly what is stored: 10.005 is refused, never rounded to a 10.01
     * nobody typed. A string must be a plain decimal ("10", "12.5"); "1e1",
     * " 10", text, a boolean and anything outside 0-100 are not percentages.
     *
     * @param mixed $value The posted or sent percentage.
     *
     * @return float|null The percentage, or null when the value is not one.
     *
     * @since  2.0.0
     */
    public static function manualDiscountPercent(mixed $value): ?float
    {
        if (\is_string($value)) {
            if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value) !== 1) {
                return null;
            }

            $value = (float) $value;
        } elseif (\is_int($value)) {
            $value = (float) $value;
        }

        if (!\is_float($value) || !is_finite($value) || $value < 0.0 || $value > 100.0) {
            return null;
        }

        return abs(round($value * 100) - $value * 100) < 1e-6 ? round($value, 2) : null;
    }

    /**
     * The configuration refusals whose storefront sentence speaks of a cart, and the key that says the
     * same to staff editing an order line. Each takes the storefront key's parameters.
     *
     * @var array<string, string>
     * @since  2.0.0
     */
    private const OPERATOR_CONFIGURATION_WORDING = [
        'COM_ALFA_CART_FIELD_REQUIRED' => 'COM_ALFA_ORDER_ITEM_ERROR_FIELD_REQUIRED',
        'COM_ALFA_CART_CHOOSE_OPTIONS' => 'COM_ALFA_ORDER_ITEM_ERROR_CHOOSE_OPTIONS',
    ];

    /**
     * What an order-line edit WILL write: for the save and for its dry-run.
     *
     * THE ONE ANSWER, and that is the point of it. The popup's preview
     * calls this and the save calls this, with the same inputs, so a figure the
     * operator was shown is a figure the save commits: the preview is no
     * longer arithmetic of its own. Nothing here writes, and nothing here
     * materialises a payload upload; it reads and it decides.
     *
     * THE PRICE COMMAND DECIDES WHERE THE MONEY COMES FROM:
     *
     *   Leave  an existing line keeps its commercial terms: its authoritative
     *          whole-line money is rescaled to the new quantity
     *          ({@see OrderLineMoney::rescaledForQuantity()}) and NOTHING is
     *          asked of the catalogue, the customer's prices, the promotions or
     *          the taxes. Name, codes, combination, tax, custom price and the
     *          frozen configuration stay the line's own. A line with no
     *          whole-line authority, no quantity, or a changed configuration
     *          is REFUSED, never guessed.
     *   Clear  the explicit reprice: today's canonical price for the line's own
     *          (or newly chosen) configuration, at the POSTED quantity.
     *   Set    the operator's unit price through ExplicitPriceInstruction.
     *
     * A new line has no terms to keep, so Leave and Clear both price it through
     * today's pipeline; Set stays available.
     *
     * A POSTED SELECTION IS NOT A RECONFIGURE. The popup always posts the
     * selection it was seeded with; only one that differs from the line's
     * frozen selection is a configuration change, and a change needs Set or
     * Clear: a price agreed for one configuration is not silently kept for
     * another.
     *
     * THE MANUAL DISCOUNT IS THE LINE'S SETTING: omitted
     * keeps the stored one, a value sets it, 0 clears it, and the same value
     * sent twice changes nothing. It is stored as the operator stated it
     * (manual_discount_percent), beside the per-line tick that says whether
     * it applies to a custom price (manual_discount_scope), and it is
     * always taken off the line's PRE-MANUAL amounts, never off a line it
     * already cut. Under Leave those are the line's own terms
     * ({@see OrderLineMoney::withPreManualTotals()}); under Set or Clear they
     * are the explicit reprice's result.
     *
     * @param array $data id (0 = new), id_order, id_item, quantity; a new
     *                    line may carry id_combination (REST addLine).
     *                    Every other key is ignored: a stored or posted
     *                    money column is never read as an instruction.
     * @param CustomPriceCommand|null $price Leave (null), Set or Clear.
     * @param float|null $manualDiscountPercent The manual discount the request states, 0-100;
     *                                          null = not stated, the stored one stands.
     * @param array|null $configuration The posted configuration in requestSelection()
     *                                  shape plus fullPayloads/files, or null when
     *                                  nothing was posted.
     * @param ManualDiscountScope|null $manualDiscountScope The per-line tick the request states; null = not
     *                                                      stated, the stored one stands.
     *
     * @return array{columns: array<string, mixed>, lineId: int, orderId: int, itemId: int, quantity: int,
     *               oldRow: ?object, currency: Currency, command: string, reconfigured: bool,
     *               combinationChanged: bool, snapshot: ?array, name: string}
     *
     * @throws RuntimeException When the edit is refused; the message is for the operator.
     *
     * @since  2.0.0
     */
    public function planOrderItemLine(
        array $data,
        ?CustomPriceCommand $price = null,
        ?float $manualDiscountPercent = null,
        ?array $configuration = null,
        ?ManualDiscountScope $manualDiscountScope = null,
    ): array {
        $price ??= CustomPriceCommand::leave();

        $lineId = (int) ($data['id'] ?? 0);
        $orderId = (int) ($data['id_order'] ?? 0);
        $itemId = (int) ($data['id_item'] ?? 0);

        if ($orderId <= 0 || $itemId <= 0) {
            throw new RuntimeException(Text::_('COM_ALFA_ERROR_INVALID_ORDER_OR_PRODUCT'));
        }

        // A QUANTITY IS REFUSED, NEVER COERCED: max(1, (int) "abc")
        // was 1, and the line was silently rescaled to a single unit.
        $quantity = self::positiveQuantity($data['quantity'] ?? null);

        if ($quantity === null) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_QUANTITY_INVALID'));
        }

        if ($manualDiscountPercent !== null && self::manualDiscountPercent($manualDiscountPercent) === null) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_DISCOUNT_FORMAT'));
        }

        $oldRow = null;

        if ($lineId > 0) {
            $oldRow = $this->loadExistingItemRow($lineId);

            // A posted id that names no line, or another order's line, is
            // refused: it used to be inserted as a new line, or edited across
            // orders.
            if ($oldRow === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_FOUND'));
            }

            if ((int) $oldRow->id_order !== $orderId) {
                throw new RuntimeException(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_IN_ORDER'));
            }

            // The line carries a frozen snapshot of what was sold. To sell
            // something else, delete it and add the new product: stock, refunds
            // and documents stay attached to the right line.
            if ((int) $oldRow->id_item !== $itemId) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_PRODUCT_CHANGE'));
            }
        }

        // A Set amount is a unit rate: it may be finer than the currency's
        // scale, and the decoder has already refused anything beyond Alfa's
        // unit-rate precision.
        $currency = OrderItemLines::orderCurrency($orderId);

        // The line's manual-discount intent after this request, resolved once:
        // whether it ends with a custom price decides what the tick may say.
        $intent = $this->manualIntentOf(
            row: $oldRow,
            percent: $manualDiscountPercent,
            scope: $manualDiscountScope,
            hasCustomPrice: $price->action === CustomPriceAction::Set
                || ($price->action === CustomPriceAction::Leave && $oldRow !== null && $oldRow->custom_unit_price_tax_incl !== null),
        );

        $plan = [
            'columns' => [],
            'lineId' => $lineId,
            'orderId' => $orderId,
            'itemId' => $itemId,
            'quantity' => $quantity,
            'oldRow' => $oldRow,
            'currency' => $currency,
            'command' => strtolower($price->action->name),
            'reconfigured' => false,
            'combinationChanged' => false,
            'snapshot' => null,
            'name' => $oldRow !== null ? MultilingualHelper::resolveLangMap($oldRow->name ?? '') : '',
        ];

        // The configuration the popup posted, in the shape the freeze path
        // reads ({@see self::postedSelection()}).
        $posted = null;
        $fullPayloads = [];

        if ($configuration !== null) {
            $posted = self::postedSelection(configuration: $configuration);
            $fullPayloads = (array) ($configuration['fullPayloads'] ?? []);
        }

        // The catalogue ITEM (fields and combinations, never a price) is read
        // only when a configuration must be compared or a price resolved.
        $item = null;

        if ($posted !== null || $oldRow === null || $price->mutates()) {
            $item = $this->loadConfiguredItem(itemId: $itemId);

            if (!$item) {
                throw new RuntimeException(Text::sprintf('COM_ALFA_ERROR_PRODUCT_NOT_FOUND', $itemId));
            }
        }

        $frozen = null;

        if ($oldRow !== null && $posted !== null) {
            $frozen = $this->frozenSelectionOf(
                orderItemId: $lineId,
                item: $item,
                combinationId: (int) ($oldRow->id_combination ?? 0),
            );

            // A COMPOSITION THE OPERATOR DID NOT TOUCH IS NOT A CHANGE.
            // A payload widget re-serialises its stored composition when it
            // initialises (new keys, re-keyed legacy maps, its live camera),
            // so the bytes it posts differ from the frozen ones although
            // nobody configured anything. The popup posts, per payload field,
            // the value the widget showed before the operator's first
            // interaction; a posted composition equal to that baseline is the
            // FROZEN one, and is neither compared nor written.
            foreach ((array) ($configuration['payloadBaselines'] ?? []) as $fieldId => $baseline) {
                $fieldId = (int) $fieldId;

                if (!isset($posted['payloads'][$fieldId])
                    || self::canonicalPayload($posted['payloads'][$fieldId]) !== self::canonicalPayload($baseline)) {
                    continue;
                }

                if (isset($frozen['payloads'][$fieldId])) {
                    $posted['payloads'][$fieldId] = $frozen['payloads'][$fieldId];
                } else {
                    unset($posted['payloads'][$fieldId]);
                }

                unset($fullPayloads[$fieldId]);
            }

            $plan['reconfigured'] = $this->configurationDiffers(
                item: $item,
                frozen: $frozen,
                posted: $posted,
                files: (array) ($configuration['files'] ?? []),
                filesChosen: (array) ($configuration['filesChosen'] ?? []),
            );
        }

        // LEAVE UNCHANGED: the line's own terms, at the new quantity
        if ($oldRow !== null && $price->action === CustomPriceAction::Leave) {
            // A line the popup cannot draw as sold is shown read-only and posts
            // no configuration, so a
            // posted configuration that differs is a real change.
            if ($plan['reconfigured']) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_RECONFIGURE_NEEDS_PRICE'));
            }

            if ((int) $oldRow->quantity <= 0) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_ZERO_QUANTITY'));
            }

            // NOTHING SAID, NOTHING CHANGED: opening and saving a line writes
            // nothing at all ("Opening and saving an order line must
            // never change its pricing simply because the form was submitted").
            // The manual discount the popup opened on, posted back, is nothing
            // said: it is the stored setting.
            if ($quantity === (int) $oldRow->quantity && !$intent['changed']) {
                return $plan;
            }

            try {
                $money = OrderLineMoney::rescaledForQuantity(row: $oldRow, newQuantity: $quantity, currency: $currency);
            } catch (DomainException $e) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_NO_PRICE_AUTHORITY'), 0, $e);
            }

            // THE MONEY MOVES ONLY WHEN THE PART OF THE MANUAL DISCOUNT THAT
            // REDUCES THE LINE MOVES. Otherwise the rescale already carries the
            // stored cut. When it moves, the new one is taken off the line's
            // own pre-manual amounts, so 20 replaces 10 and 0 returns the line
            // to them: never a cut on a cut, never today's catalogue.
            if ($intent['effective'] !== $intent['effectiveBefore']) {
                $money = $this->withManualDiscount(
                    preManual: $money->withPreManualTotals(currency: $currency, quantity: $quantity, taxRate: (float) $oldRow->tax_rate),
                    hundredths: $intent['effective'],
                    currency: $currency,
                    quantity: $quantity,
                );
            }

            // No custom-price mutation under Leave: the column is not written.
            $columns = $money->toColumns();
            unset($columns['custom_unit_price_tax_incl']);

            $plan['columns'] = ['quantity' => $quantity] + $columns + $intent['columns'];

            return $plan;
        }

        // SET, CLEAR, OR A NEW LINE: today's pipeline
        $context = OrderHelper::buildOrderPriceContext($orderId);
        $variant = null;
        $combinationId = null;

        if ($posted !== null && ($oldRow === null || $plan['reconfigured'])) {
            // The configuration the operator chose now.
            $variant = VariantHelper::resolve(
                $item,
                $posted['selection'],
                array_values($posted['optionValueIds']),
                $posted['payloads'],
                $context,
            );
            $combinationId = ($variant !== null && $variant->combinationId > 0) ? $variant->combinationId : null;

            if (!empty($item->combinations) && $combinationId === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_CHOOSE_OPTIONS'));
            }

            $error = ItemConfigurationHelper::validate(
                item: $item,
                combinationId: $combinationId,
                request: $posted,
                quantity: (float) $quantity,
            );

            if ($error !== null) {
                throw new RuntimeException(ItemConfigurationHelper::refusalText(refusal: $error, wording: self::OPERATOR_CONFIGURATION_WORDING));
            }

            $breakdown = null;

            try {
                if ($variant !== null) {
                    $breakdown = (new PriceCalculator())->breakdownVariant($itemId, $quantity, $variant, $context);
                }
            } catch (Throwable $e) {
                // The configuration still freezes; only its analytical amounts are missing.
                Log::add('Order line configuration pricing failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            }

            $plan['snapshot'] = [
                'item' => $item,
                'request' => $posted,
                // Without the untouched compositions: those are the frozen ones.
                'fullPayloads' => $fullPayloads,
                'files' => (array) ($configuration['files'] ?? []),
                'breakdown' => $breakdown,
            ];
        } elseif ($oldRow !== null) {
            // The line's own configuration, exactly as it was frozen.
            $sold = $this->resolveSoldConfiguration(row: $oldRow, item: $item, frozen: $frozen ?? $this->frozenSelectionOf(orderItemId: $lineId, item: $item, combinationId: (int) ($oldRow->id_combination ?? 0)), context: $context);

            // WHAT WAS SOLD IS A READ-ONLY FACT:
            // a Set on a sale the catalogue can no longer resolve prices it on its own stored terms.
            if (!$sold['resolves'] && $price->action === CustomPriceAction::Set) {
                return $this->planOnStoredTerms(plan: $plan, row: $oldRow, price: $price, intent: $intent, quantity: $quantity, currency: $currency);
            }

            if (!$sold['resolves']) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_CONFIGURATION_UNRESOLVED'));
            }

            $variant = $sold['variant'];
            $combinationId = ($variant !== null && $variant->combinationId > 0) ? $variant->combinationId : null;
        } else {
            // A new line with no posted configuration (REST addLine): the
            // combination it names, which must be one of the product's own.
            $combinationId = (int) ($data['id_combination'] ?? 0) ?: null;

            if ($combinationId !== null) {
                $ownIds = array_map(static fn (object $combo): int => (int) $combo->id, (array) ($item->combinations ?? []));

                if (!\in_array($combinationId, $ownIds, true)) {
                    throw new RuntimeException(Text::sprintf('COM_ALFA_ORDER_ITEM_ERROR_COMBINATION_NOT_OF_ITEM', $combinationId));
                }

                $variant = new VariantSelection(combinationId: $combinationId);
            } elseif (!empty($item->combinations)) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_CHOOSE_OPTIONS'));
            }

            // A NEW LINE IS CHECKED AS ADD-TO-CART CHECKS IT:
            // a required option left out is refused, never priced as the bare product.
            $missing = ItemConfigurationHelper::validate(
                item: $item,
                combinationId: $combinationId,
                request: $combinationId !== null
                    ? $this->selectionOfCombination(item: $item, combinationId: $combinationId)
                    : ['selection' => [], 'optionValueIds' => [], 'payloads' => []],
                quantity: (float) $quantity,
            );

            if ($missing !== null) {
                throw new RuntimeException(ItemConfigurationHelper::refusalText(refusal: $missing, wording: self::OPERATOR_CONFIGURATION_WORDING));
            }
        }

        $result = (new PriceCalculator())->calculate(
            $itemId,
            $quantity,
            $context,
            $variant,
            $price->action === CustomPriceAction::Set ? ExplicitPriceInstruction::perUnitTaxIncl((float) $price->amount) : null,
        );

        if (!$result instanceof PriceResult) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_PRICE_UNAVAILABLE'));
        }

        // The explicit reprice IS the line's pre-manual amount; the manual
        // discount that reduces it comes off that, once.
        $money = $this->withManualDiscount(
            preManual: OrderLineMoney::fromPriceResult($result),
            hundredths: $intent['effective'],
            currency: $currency,
            quantity: $quantity,
        );

        $applied = $result->getTaxes()->getApplied();
        $columns = ['quantity' => $quantity] + $money->toColumns() + $intent['columns'] + [
            'tax_rate' => (float) $result->getTaxes()->getEffectiveRate(),
            'tax_name' => OrderCreator::clampTaxName(
                name:    (string) ($applied !== [] ? $applied[0]->name : ''),
                context: 'order ' . $orderId . ', line ' . ($lineId ?: 'new'),
            ),
        ];

        // IDENTITY FOLLOWS THE SELLABLE UNIT. A new line freezes it; an
        // existing one changes it only when its combination changed. A reprice
        // alone renames nothing.
        $combinationChanged = $oldRow === null
            || (int) ($combinationId ?? 0) !== (int) ($oldRow->id_combination ?? 0);

        if ($combinationChanged) {
            $identity = $this->resolveLineIdentity((int) ($combinationId ?? 0), $item);
            $name = ItemFieldsSnapshot::freezeItemName($itemId, $combinationId);

            $columns += [
                'id_combination' => $combinationId,
                'name' => json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sku' => $identity->sku,
                'gtin' => $identity->gtin,
                'mpn' => $identity->mpn,
                'weight' => $identity->weight,
            ];

            $plan['name'] = MultilingualHelper::resolveLangMap($columns['name']);
        }

        if ($oldRow === null) {
            $columns = ['id_item' => $itemId, 'id_order' => $orderId] + $columns;
        }

        $plan['columns'] = $columns;
        $plan['combinationChanged'] = $oldRow !== null && $combinationChanged;

        return $plan;
    }

    /**
     * The figures an order-line plan leaves on the line, formatted for the popup.
     *
     * The popup does no arithmetic and no formatting of its own: it shows what
     * this returns, which is the stored line overlaid with the columns the save
     * will write.
     *
     * @param array $plan A {@see self::planOrderItemLine()} result.
     *
     * @return array<string, string|bool>
     *
     * @since  2.0.0
     */
    public function describeOrderItemPlan(array $plan): array
    {
        $after = array_merge((array) ($plan['oldRow'] ?? []), $plan['columns']);
        $currency = $plan['currency'];
        $money = static fn (string $column): string => \array_key_exists($column, $after) && $after[$column] !== null
            ? $currency->format((float) $after[$column])
            : '';
        // The custom rate itself shows its own decimals, never fewer than the
        // currency's: it is the agreed rate as data. Totals keep $money.
        $rate = static fn (string $column): string => \array_key_exists($column, $after) && $after[$column] !== null ? $currency->formatRate(rate: (float) $after[$column]) : '';
        // The line's units by the one unit rule, from the quantity and totals the
        // save leaves on the line, at the order's frozen scale; the agreed rate
        // is a gross rate and is passed beside the gross unit only.
        $unit = static fn (string $column, string $totalColumn, ?string $rateColumn = null): string => \array_key_exists($column, $after) && $after[$column] !== null
            ? $currency->formatUnit(
                unit: $after[$column],
                lineTotal: $after[$totalColumn] ?? 0,
                quantity: $after['quantity'] ?? 0,
                rate: $rateColumn !== null ? ($after[$rateColumn] ?? null) : null,
            )
            : '';
        // A percentage as its column will hold it (two places for the saving and the
        // manual discount, three for the tax rate), printed by the one percentage rule.
        $percent = static fn (string $column, int $places): string => AlfaHelper::percentDisplay(
            value: ExactAmount::ofNumber($after[$column] ?? 0)->roundedAt($places),
        );

        return [
            'changed' => $plan['columns'] !== [],
            'command' => $plan['command'],
            'name' => $plan['name'],
            'quantity' => (string) (int) ($after['quantity'] ?? 0),
            'unit_price_tax_incl' => $unit('unit_price_tax_incl', 'total_price_tax_incl', 'custom_unit_price_tax_incl'),
            'unit_price_tax_excl' => $unit('unit_price_tax_excl', 'total_price_tax_excl'),
            'total_price_tax_incl' => $money('total_price_tax_incl'),
            'total_price_tax_excl' => $money('total_price_tax_excl'),
            'discount_amount_tax_incl' => $money('discount_amount_tax_incl'),
            'discount_percent' => $percent('discount_percent', 2),
            'tax_rate' => $percent('tax_rate', 3),
            'custom_unit_price_tax_incl' => $rate('custom_unit_price_tax_incl'),
            'manual_discount_percent' => $percent('manual_discount_percent', 2),
            // Said only while there is a custom price for the tick to govern.
            'manual_discount_scope' => ($after['custom_unit_price_tax_incl'] ?? null) === null ? '' : Text::_(
                ($after['manual_discount_scope'] ?? '') === ManualDiscountScope::ApplyToCustomPrice->value
                    ? 'COM_ALFA_ORDER_ITEM_MANUAL_SCOPE_APPLIED'
                    : 'COM_ALFA_ORDER_ITEM_MANUAL_SCOPE_NOT_APPLIED',
            ),
        ];
    }

    /**
     * Take the manual discount that reduces a line off its PRE-MANUAL amounts.
     *
     * A custom-priced line with the tick off is handed 0 here and keeps its
     * negotiated price.
     *
     * @param OrderLineMoney $preManual The line at its pre-manual amounts.
     * @param int $hundredths The manual percentage that reduces it, in hundredths (1000 = 10%).
     * @param Currency $currency The order's currency at its frozen scale.
     * @param int $quantity The planned quantity, which the unit columns are derived at.
     *
     *
     * @since  2.0.0
     */
    private function withManualDiscount(OrderLineMoney $preManual, int $hundredths, Currency $currency, int $quantity): OrderLineMoney
    {
        if ($hundredths === 0) {
            return $preManual;
        }

        return $preManual->withManualDiscountPercent(percent: $hundredths / 100, currency: $currency, quantity: $quantity);
    }

    /**
     * The line's manual-discount intent after a request: what is stored, and
     * how much of it reduces the money.
     *
     * Both halves are the operator's stated instructions, stored on the line
     * and never inferred from its money ("Do not infer this later from
     * saved amounts"): a percentage and scope that are not stated keep the
     * stored ones, and an existing line starts from 0.00 and the default
     * scope, which is what every line the ALTER found carries.
     *
     * THE TICK GOVERNS A CUSTOM PRICE, AND ONLY WHILE THERE IS ONE. A line
     * that ends without one (Clear, or a line never overridden) returns to the
     * default, so a custom price set later starts with the box unticked
     * ("setting a custom price does not automatically enable the
     * scope"); a request that ticks it for such a line is refused.
     *
     * @param object|null $row The stored line, or null for a new one.
     * @param float|null $percent The manual discount the request states; null = not stated.
     * @param ManualDiscountScope|null $scope The tick the request states; null = not stated.
     * @param bool $hasCustomPrice Whether the line carries a custom price after the request.
     *
     * @return array{columns: array{manual_discount_percent: float, manual_discount_scope: string},
     *               changed: bool, effective: int, effectiveBefore: int} The effective parts in hundredths.
     *
     * @throws RuntimeException When the stored scope is unknown, or the tick is asked for without a custom price.
     *
     * @since  2.0.0
     */
    private function manualIntentOf(?object $row, ?float $percent, ?ManualDiscountScope $scope, bool $hasCustomPrice): array
    {
        $storedHundredths = $row !== null ? (int) round((float) $row->manual_discount_percent * 100) : 0;
        $storedScope = $row !== null
            ? ManualDiscountScope::tryFrom((string) $row->manual_discount_scope)
            : ManualDiscountScope::ExcludeCustomPrice;

        if ($storedScope === null) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_SCOPE_UNKNOWN'));
        }

        if (!$hasCustomPrice && $scope === ManualDiscountScope::ApplyToCustomPrice) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_SCOPE_WITHOUT_CUSTOM_PRICE'));
        }

        $hundredths = $percent !== null ? (int) round($percent * 100) : $storedHundredths;
        $newScope = $hasCustomPrice ? ($scope ?? $storedScope) : ManualDiscountScope::ExcludeCustomPrice;
        $hadCustomPrice = $row !== null && $row->custom_unit_price_tax_incl !== null;

        return [
            'columns' => [
                'manual_discount_percent' => $hundredths / 100,
                'manual_discount_scope' => $newScope->value,
            ],
            'changed' => $hundredths !== $storedHundredths || $newScope !== $storedScope,
            'effective' => (int) round($newScope->effectivePercent(manualPercent: $hundredths / 100, hasCustomPrice: $hasCustomPrice) * 100),
            'effectiveBefore' => (int) round($storedScope->effectivePercent(manualPercent: $storedHundredths / 100, hasCustomPrice: $hadCustomPrice) * 100),
        ];
    }

    /**
     * Does the posted configuration differ from the one the line froze?
     *
     * Both sides are normalised through the freeze path's own row builder, so
     * a fixed dimension the popup did not draw reads the same on both, and a
     * plugin payload is compared as the JSON value it is rather than as bytes,
     * key order aside, and STRICTLY: a loose comparison would call "1" and
     * "01" the same value. An uploaded file is always a change, and so is the
     * preview's "a file is chosen" marker for it: the dry run carries no
     * bytes, and it must reach the verdict the save reaches.
     *
     * @param object $item The configured item.
     * @param array $frozen The line's selection, {@see self::frozenSelectionOf()}.
     * @param array $posted The posted selection, in the same shape.
     * @param array $files Uploaded configuration files.
     * @param array $filesChosen The preview's per-slot markers of a chosen file, field id => slot => flag.
     *
     *
     * @since  2.0.0
     */
    private function configurationDiffers(object $item, array $frozen, array $posted, array $files, array $filesChosen = []): bool
    {
        foreach ($files as $slots) {
            foreach ((array) $slots as $file) {
                if (\is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    return true;
                }
            }
        }

        foreach ($filesChosen as $slots) {
            foreach ((array) $slots as $chosen) {
                if ($chosen !== null && $chosen !== '' && $chosen !== '0') {
                    return true;
                }
            }
        }

        $normalise = static function (array $request) use ($item): array {
            $rows = ItemConfigurationHelper::buildRows(item: $item, request: $request);
            ksort($rows);

            foreach ($rows as &$row) {
                $row['value_id'] = $row['value_id'] === null ? null : (int) $row['value_id'];
                $row['payload'] = $row['payload'] === null ? null : self::canonicalPayload($row['payload']);
            }

            return $rows;
        };

        return $normalise($frozen) !== $normalise($posted);
    }

    /**
     * A payload as the value it states: decoded JSON with its object keys in
     * one order, or the text itself when it is not JSON.
     *
     * Two payloads state the same composition when their canonical values are
     * identical; the save reads a composition equal to its baseline as
     * untouched, and a refused save's kept draft compares them the same way.
     *
     * @param mixed $payload A stored or posted payload.
     *
     *
     * @since  2.0.0
     */
    public static function canonicalPayload(mixed $payload): mixed
    {
        if (!\is_string($payload)) {
            $payload = (string) json_encode($payload);
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $payload;
        }

        $order = static function (mixed $value) use (&$order): mixed {
            if (!\is_array($value)) {
                return $value;
            }

            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($order, $value);
        };

        return $order($decoded);
    }

    /**
     * A posted configuration as a selection, in {@see ItemFieldsSnapshot::lineSelection()} shape.
     *
     * The one reading of what the popup posted: the save compares it with the
     * frozen selection, and a refused save's redisplay seeds the popup with it.
     * A payload widget posts its full composition beside conf[]; that
     * composition IS the payload.
     *
     * @param array $configuration The posted configuration in requestSelection() shape plus fullPayloads.
     *
     * @return array{selection: array<int, int>, optionValueIds: array, payloads: array}
     *
     * @since  2.0.0
     */
    private static function postedSelection(array $configuration): array
    {
        $posted = [
            'selection' => array_map('intval', (array) ($configuration['selection'] ?? [])),
            'optionValueIds' => (array) ($configuration['optionValueIds'] ?? []),
            'payloads' => (array) ($configuration['payloads'] ?? []),
        ];

        foreach ((array) ($configuration['fullPayloads'] ?? []) as $fieldId => $payload) {
            if ($payload !== null && $payload !== '') {
                $posted['payloads'][(int) $fieldId] = $payload;
            }
        }

        return $posted;
    }

    /**
     * The line's frozen selection, in {@see ItemFieldsSnapshot::lineSelection()} shape.
     *
     * A variant line whose snapshot names none of its item's variant
     * dimensions (a REST-added line, or one placed before configuration
     * snapshots existed) still sold ONE combination: the row's stored
     * id_combination. Its dimensions are read from that combination, so the
     * popup seeds it and the configuration check compares against it (the
     * snapshot's equivalent), never against the item's
     * default combination, which the popup used to seed and the check then
     * read as a reconfigure.
     *
     * @param int $orderItemId The line.
     * @param object $item The configured item (fields and combinations).
     * @param int $combinationId The line's stored combination, or 0.
     *
     * @return array{selection: array, optionValueIds: array, payloads: array}
     *
     * @since  2.0.0
     */
    private function frozenSelectionOf(int $orderItemId, object $item, int $combinationId): array
    {
        $frozen = ItemFieldsSnapshot::lineSelection(orderItemId: $orderItemId, item: $item);

        if ($frozen['selection'] !== [] || $combinationId <= 0) {
            return $frozen;
        }

        $frozen['selection'] = $this->selectionOfCombination(item: $item, combinationId: $combinationId)['selection'];

        return $frozen;
    }

    /**
     * A combination's variant dimensions, in {@see ItemFieldsSnapshot::lineSelection()} shape.
     *
     * The mapping a line that names only its combination needs: the popup's
     * seed for a variant line with no snapshot, and the validator's request for
     * a REST line that names an id_combination.
     *
     * @param object $item The configured item (fields and combinations).
     * @param int $combinationId The combination.
     *
     * @return array{selection: array<int, int>, optionValueIds: array, payloads: array}
     *
     * @since  2.0.0
     */
    private function selectionOfCombination(object $item, int $combinationId): array
    {
        $selection = [];
        $variantFields = ItemFieldsSnapshot::variantFieldIds(item: $item);
        $fieldOfValue = [];

        foreach ($item->fields ?? [] as $field) {
            foreach ($field->values ?? [] as $value) {
                $fieldOfValue[(int) $value->id] = (int) $field->id;
            }
        }

        foreach ($item->combinations ?? [] as $combination) {
            if ((int) $combination->id !== $combinationId) {
                continue;
            }

            foreach ($combination->value_ids ?? [] as $valueId) {
                $fieldId = $fieldOfValue[(int) $valueId] ?? 0;

                if (isset($variantFields[$fieldId])) {
                    $selection[$fieldId] = (int) $valueId;
                }
            }
        }

        return ['selection' => $selection, 'optionValueIds' => [], 'payloads' => []];
    }

    /**
     * Does the line's OWN frozen configuration still resolve, and to what?
     *
     * Resolved from the frozen snapshot and required to land on the line's
     * stored combination: a snapshot that resolves elsewhere (the catalogue has
     * moved) does not resolve, and neither does a stored combination that no
     * longer exists in any state. A variant line with no snapshot carries the
     * selection of its stored combination ({@see self::frozenSelectionOf()}).
     * A sale that does not resolve is never priced as something the line did
     * not sell: Clear refuses it, and Set prices it on its own stored terms.
     *
     * @param object $row The stored line.
     * @param object $item The configured item.
     * @param array $frozen The line's selection, {@see self::frozenSelectionOf()}.
     * @param PriceContext $context The order's pricing context.
     *
     * @return array{resolves: bool, variant: ?VariantSelection}
     *
     * @since  2.0.0
     */
    private function resolveSoldConfiguration(object $row, object $item, array $frozen, PriceContext $context): array
    {
        $stored = (int) ($row->id_combination ?? 0);

        if (!empty($item->combinations) && $stored <= 0) {
            return ['resolves' => false, 'variant' => null];
        }

        if ($stored > 0 && !$this->combinationExists(itemId: (int) $row->id_item, combinationId: $stored)) {
            return ['resolves' => false, 'variant' => null];
        }

        if ($frozen['selection'] === [] && $frozen['optionValueIds'] === [] && $frozen['payloads'] === []) {
            return ['resolves' => true, 'variant' => $stored > 0 ? new VariantSelection(combinationId: $stored) : null];
        }

        $variant = VariantHelper::resolve(
            item: $item,
            selection: $frozen['selection'],
            optionValueIds: array_values($frozen['optionValueIds']),
            payloads: $frozen['payloads'],
            context: $context,
        );
        $resolved = ($variant !== null && $variant->combinationId > 0) ? $variant->combinationId : 0;

        return $resolved === $stored
            ? ['resolves' => true, 'variant' => $variant]
            : ['resolves' => false, 'variant' => null];
    }

    /**
     * Does this combination of this item still exist, in any state?
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination.
     *
     *
     * @since  2.0.0
     */
    private function combinationExists(int $itemId, int $combinationId): bool
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_item_combinations'))
            ->where($db->quoteName('id') . ' = :c')
            ->where($db->quoteName('item_id') . ' = :i')
            ->bind(':c', $combinationId, ParameterType::INTEGER)
            ->bind(':i', $itemId, ParameterType::INTEGER);

        return (bool) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * A Set on a line the catalogue can no longer resolve, priced on the line's own stored terms.
     *
     * The rate times the quantity, rounded once, split at the line's stored
     * tax rate ({@see OrderLineMoney::customPriceOnStoredTerms()}); no price,
     * discount, tax or rule table is read. Name, codes, combination, tax and
     * the frozen configuration are not written. A line with no whole-line
     * authority writes none of its authority columns, so its reference stays
     * unknown rather than guessed.
     *
     * @param array $plan The plan so far.
     * @param object $row The stored line.
     * @param CustomPriceCommand $price The Set.
     * @param array $intent {@see self::manualIntentOf()}.
     * @param int $quantity The posted quantity.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return array The plan, with its columns.
     *
     * @throws RuntimeException When the line has no quantity or only part of its authority.
     *
     * @since  2.0.0
     */
    private function planOnStoredTerms(array $plan, object $row, CustomPriceCommand $price, array $intent, int $quantity, Currency $currency): array
    {
        try {
            $money = OrderLineMoney::customPriceOnStoredTerms(row: $row, rate: ExplicitPriceInstruction::canonicalRate(value: (float) $price->amount), quantity: $quantity, currency: $currency);
        } catch (DomainException $e) {
            throw new RuntimeException(Text::_((int) $row->quantity <= 0 ? 'COM_ALFA_ORDER_ITEM_ERROR_ZERO_QUANTITY' : 'COM_ALFA_ORDER_ITEM_ERROR_NO_PRICE_AUTHORITY'), 0, $e);
        }

        $money = $this->withManualDiscount(preManual: $money, hundredths: $intent['effective'], currency: $currency, quantity: $quantity);
        $columns = ['quantity' => $quantity] + $money->toColumns() + $intent['columns'];

        if (!OrderLineMoney::hasAuthority(row: $row)) {
            $columns = array_diff_key($columns, array_flip(OrderLineMoney::AUTHORITY_COLUMNS));
        }

        $plan['columns'] = $columns;

        return $plan;
    }

    /**
     * Refuse an order-line write: on the model for the REST caller, in the
     * queue for the popup.
     *
     * @param string $message The operator-facing reason.
     *
     *
     * @since  2.0.0
     */
    private function refuseOrderItem(string $message): void
    {
        // Raw on the model (a REST body is JSON, escaped by its reader);
        // escaped in the queue, which the admin renders as HTML and which can
        // carry a plugin's or the database's words.
        $this->setError($message);
        Factory::getApplication()->enqueueMessage(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 'error');
    }

    /**
     * Save a single order line, from the admin popup or the REST line verbs.
     *
     * One plan ({@see self::planOrderItemLine()}), the same one the popup's
     * preview showed, written in ONE transaction: the line, its frozen
     * configuration, the stock reconciliation and the history event commit
     * together or not at all. A payload upload is materialised before the
     * transaction opens, because a rollback cannot un-write a file.
     *
     * @param array $data id (0 = new), id_order, id_item, quantity
     *                    (id_combination for a REST addLine). The line
     *                    id is written back into it.
     * @param CustomPriceCommand|null $price Leave (null), Set or Clear.
     * @param float|null $manualDiscountPercent The manual discount the request states; null = not stated.
     * @param array|null $configuration The posted configuration, or null.
     * @param ManualDiscountScope|null $manualDiscountScope The per-line tick the request states; null = not stated.
     *
     *
     * @throws Throwable What ended a caller's transaction, when the line was saved inside one.
     *
     * @since  1.0.0
     */
    public function saveOrderItem(
        array &$data,
        ?CustomPriceCommand $price = null,
        ?float $manualDiscountPercent = null,
        ?array $configuration = null,
        ?ManualDiscountScope $manualDiscountScope = null,
    ): bool {
        $db = $this->getDatabase();
        $app = Factory::getApplication();

        try {
            $plan = $this->planOrderItemLine(
                data: $data,
                price: $price,
                manualDiscountPercent: $manualDiscountPercent,
                configuration: $configuration,
                manualDiscountScope: $manualDiscountScope,
            );
        } catch (Throwable $e) {
            $this->refuseOrderItem($e->getMessage());

            return false;
        }

        $isNew = $plan['oldRow'] === null;
        $lineId = $plan['lineId'];
        $orderId = $plan['orderId'];

        if ($plan['columns'] === []) {
            $data['id'] = $lineId;

            return true;
        }

        // A new line, a larger quantity or a moved line is a new stock claim: only a current unit takes one.
        $claims = $isNew || $plan['combinationChanged'] || $plan['quantity'] > (int) $plan['oldRow']->quantity;
        $claimedCombinationId = \array_key_exists(key: 'id_combination', array: $plan['columns'])
            ? (int) ($plan['columns']['id_combination'] ?? 0)
            : (int) ($plan['oldRow']->id_combination ?? 0);
        $keepsPlacement = !$isNew && !$plan['combinationChanged'];

        if ($claims) {
            $refusal = InventoryBridge::claimRefusal(
                lineId: $isNew ? 0 : $lineId,
                itemId: $plan['itemId'],
                combinationId: $claimedCombinationId,
                keepsPlacement: $keepsPlacement,
                beforeCommit: false,
            );

            if ($refusal !== null) {
                $this->refuseOrderItem(message: $refusal);

                return false;
            }
        }

        $rows = null;

        if ($plan['snapshot'] !== null) {
            $prepared = ItemConfigurationHelper::preparePayloads(
                item: $plan['snapshot']['item'],
                request: $plan['snapshot']['request'],
                fullPayloads: $plan['snapshot']['fullPayloads'],
                files: $plan['snapshot']['files'],
            );

            if (\is_string($prepared)) {
                $this->refuseOrderItem($prepared);

                return false;
            }

            $rows = ItemConfigurationHelper::buildRows(item: $plan['snapshot']['item'], request: $prepared);
        }

        // Savepoint-aware: inside a caller's transaction this nests.
        $totalsBefore = OrderTotalHelper::getBreakdown($orderId);
        $nested = TransactionState::isOpen($db);
        $db->transactionStart(true);

        try {
            if ($isNew) {
                $line = (object) ($plan['columns'] + [
                    'id_order_invoice' => null,
                    'id_shipment_method' => 0,
                    'quantity_refunded' => 0,
                    'quantity_returned' => 0,
                    'refunded_amount_tax_excl' => 0,
                    'refunded_amount_tax_incl' => 0,
                    'total_shipping_price_tax_incl' => 0,
                    'total_shipping_price_tax_excl' => 0,
                    'added' => Factory::getDate('now', 'UTC')->toSql(),
                ]);
                $db->insertObject('#__alfa_order_items', $line, 'id');
                $lineId = (int) $db->insertid();
            } else {
                // updateNulls: a Clear writes its NULL custom price. Only the
                // planned columns are named, so nothing else on the row moves.
                $line = (object) (['id' => $lineId] + $plan['columns']);
                $db->updateObject('#__alfa_order_items', $line, 'id', true);
            }

            // A new or moved line is placed: its identity's stock tracking is recorded with it.
            if ($isNew || $plan['combinationChanged']) {
                InventoryBridge::recordPlacement(orderId: $orderId, lineIds: [$lineId]);
            }

            if ($rows !== null) {
                ItemFieldsSnapshot::freezeSelection(
                    orderItemId: $lineId,
                    orderId: $orderId,
                    rows: $rows,
                    item: $plan['snapshot']['item'],
                    breakdown: $plan['snapshot']['breakdown'],
                    currency: $plan['currency'],
                    replace: !$isNew,
                );
            }

            // STOCK, AS BEFORE: the kernel converges the order's claims to its
            // lines whenever a line's quantity or its unit moved.
            $oldQuantity = $isNew ? 0 : (int) $plan['oldRow']->quantity;

            if ($plan['quantity'] !== $oldQuantity || $plan['combinationChanged']) {
                InventoryBridge::syncOrderLines($orderId);

                // The quantity or the unit moved, so the line's digital capacity
                // may have shrunk; syncForLine() never raises an allowance.
                Entitlements::syncForLine($lineId);

                $this->warnNegativeStock(
                    itemId: $plan['itemId'],
                    combinationId: (int) ($plan['columns']['id_combination'] ?? ($plan['oldRow']->id_combination ?? 0)),
                    lineName: $plan['name'],
                );
            }

            $this->logOrderItemWrite(plan: $plan, lineId: $lineId, totalsBefore: $totalsBefore);

            // Judged again as the last statement before commit, on the identity as last committed, so a change made to it by this save's own transaction or by another one meanwhile is judged too.
            $refusal = $claims
                ? InventoryBridge::claimRefusal(
                    lineId: $lineId,
                    itemId: $plan['itemId'],
                    combinationId: $claimedCombinationId,
                    keepsPlacement: $keepsPlacement,
                    beforeCommit: true,
                )
                : null;

            if ($refusal !== null) {
                TransactionState::rollBackLevel($db);
                $this->refuseOrderItem(message: $refusal);

                return false;
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended a caller's transaction is the caller's to hear.
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            $this->refuseOrderItem(Text::sprintf(
                $isNew ? 'COM_ALFA_ORDER_ITEM_INSERT_ERROR' : 'COM_ALFA_ORDER_ITEM_UPDATE_ERROR',
                $e->getMessage(),
            ));

            return false;
        }

        $data['id'] = $lineId;

        return true;
    }

    /**
     * Warn when a saved line left its unit's physical stock below zero.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination the line sells, or 0.
     * @param string $lineName The line's own name, for the message.
     *
     *
     * @since  2.0.0
     */
    private function warnNegativeStock(int $itemId, int $combinationId, string $lineName): void
    {
        $db = $this->getDatabase();
        $manages = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manage_stock'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $itemId),
        )->loadResult();

        if ($manages !== 1) {
            return;
        }

        // The combination when it tracks own stock, else the item.
        $service = new InventoryAvailabilityService();
        $availability = $combinationId > 0
            ? $service->availability(itemId: $itemId, combinationId: $combinationId)
            : null;

        if ($availability === null || ($availability['physical'] === 0.0 && $availability['reserved'] === 0.0)) {
            $availability = $service->availability(itemId: $itemId);
        }

        if ($availability['physical'] < 0) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_ORDER_ITEM_STOCK_NEGATIVE',
                    htmlspecialchars($lineName, ENT_QUOTES, 'UTF-8'),
                    number_format($availability['physical'], 0),
                ),
                'warning',
            );
        }
    }

    /**
     * The history event of an order-line write, under the line's OWN name.
     *
     * Inside the write's transaction, so the mutation and its record land or
     * vanish together. An edit that changed nothing records nothing.
     *
     * @param array $plan The written plan.
     * @param int $lineId The line id (new lines: the inserted one).
     * @param object $totalsBefore The order totals before the write.
     *
     *
     * @since  2.0.0
     */
    private function logOrderItemWrite(array $plan, int $lineId, object $totalsBefore): void
    {
        $orderId = $plan['orderId'];
        $totalsAfter = OrderTotalHelper::getBreakdown($orderId);
        $columns = $plan['columns'];
        $context = [
            'price_command' => $plan['command'],
            'reconfigured' => $plan['reconfigured'],
            'totals_before' => [
                'grand_total_tax_incl' => $totalsBefore->grand_total_tax_incl,
                'grand_total_tax_excl' => $totalsBefore->grand_total_tax_excl,
            ],
            'totals_after' => [
                'grand_total_tax_incl' => $totalsAfter->grand_total_tax_incl,
                'grand_total_tax_excl' => $totalsAfter->grand_total_tax_excl,
            ],
        ];

        if ($plan['oldRow'] === null) {
            // The unit as the log keeps it: the Auto form of the one unit rule at
            // the order's frozen scale, plain, so a stored text never depends on
            // a display setting.
            $unit = Currency::unitFigure(
                unit: $columns['unit_price_tax_incl'] ?? 0,
                lineTotal: $columns['total_price_tax_incl'] ?? 0,
                quantity: $plan['quantity'],
                scale: $plan['currency']->getDecimalPlaces(),
                rate: $columns['custom_unit_price_tax_incl'] ?? null,
                auto: true,
            )['figure'];

            self::logOrderActivityStrict(
                $orderId,
                'item.added',
                "Added \"{$plan['name']}\" x{$plan['quantity']} at {$unit}",
                [
                    'id_item' => $plan['itemId'],
                    'id_combination' => $columns['id_combination'] ?? null,
                    'name' => $plan['name'],
                    'quantity' => $plan['quantity'],
                    'unit_price_tax_incl' => $columns['unit_price_tax_incl'] ?? null,
                    'unit_price_tax_excl' => $columns['unit_price_tax_excl'] ?? null,
                    'custom_unit_price_tax_incl' => $columns['custom_unit_price_tax_incl'] ?? null,
                    'manual_discount_percent' => $columns['manual_discount_percent'] ?? null,
                    'manual_discount_scope' => $columns['manual_discount_scope'] ?? null,
                    'tax_rate' => $columns['tax_rate'] ?? null,
                ] + $context,
                $lineId,
            );

            return;
        }

        $old = $plan['oldRow'];
        // The manual discount and its tick are tracked like the money: a change
        // of intent that moves no money (the tick off) is still an edit.
        $tracked = ['quantity', 'unit_price_tax_incl', 'unit_price_tax_excl', 'total_price_tax_incl',
            'custom_unit_price_tax_incl', 'manual_discount_percent', 'manual_discount_scope', 'tax_rate', 'id_combination', 'name'];
        $before = [];
        $after = [];

        foreach ($tracked as $column) {
            $before[$column] = $column === 'name' ? MultilingualHelper::resolveLangMap($old->name ?? '') : ($old->{$column} ?? null);
            $after[$column] = !\array_key_exists($column, $columns)
                ? $before[$column]
                : ($column === 'name' ? MultilingualHelper::resolveLangMap((string) $columns['name']) : $columns[$column]);
        }

        $changes = AlfaHelper::buildDiff((object) $before, $after, $tracked);

        if ($changes === []) {
            return;
        }

        $parts = [];

        if (isset($changes['quantity'])) {
            $parts[] = "qty {$changes['quantity']['from']}->{$changes['quantity']['to']}";
        }

        if (isset($changes['total_price_tax_incl'])) {
            $parts[] = 'total ' . number_format((float) $changes['total_price_tax_incl']['from'], 2)
                . '->' . number_format((float) $changes['total_price_tax_incl']['to'], 2);
        }

        if ($plan['command'] !== 'leave') {
            $parts[] = $plan['command'] === 'set' ? 'custom price set' : 'repriced';
        }

        if (isset($changes['manual_discount_percent'])) {
            // Stored text: the percentages with a '.' whatever the writer's language.
            $parts[] = 'manual discount ' . AlfaHelper::percentDisplay(value: $changes['manual_discount_percent']['from'] ?? 0, decimalSeparator: '.')
                . '->' . AlfaHelper::percentDisplay(value: $changes['manual_discount_percent']['to'] ?? 0, decimalSeparator: '.');
        }

        if (isset($changes['manual_discount_scope'])) {
            $parts[] = $changes['manual_discount_scope']['to'] === ManualDiscountScope::ApplyToCustomPrice->value
                ? 'manual discount applies to the custom price'
                : 'manual discount does not apply to the custom price';
        }

        if ($plan['reconfigured']) {
            $parts[] = 'reconfigured';
        }

        $lineName = MultilingualHelper::resolveLangMap($old->name ?? '');

        self::logOrderActivityStrict(
            $orderId,
            'item.edited',
            "Edited \"{$lineName}\": " . implode(', ', $parts),
            $changes + $context,
            $lineId,
        );
    }

    /**
     * Delete a single order item and restore stock
     *
     * @param int $id Order item row PK
     * @param int $orderId Order ID (for security)
     *
     * @throws Throwable What ended a caller's transaction, when the line was deleted inside one.
     *
     * @since  1.0.0
     */
    public function deleteOrderItem(int $id, int $orderId): bool
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        // Load item to get quantity and product ID for stock restore
        $item = $this->loadExistingItemRow($id);

        if ($item) {
            // Frozen {lang: text} name -> readable for the message/activity log.
            $item->name = MultilingualHelper::resolveLangMap($item->name);
        }
        if (!$item) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_FOUND'), 'error');
            return false;
        }

        // Verify order ownership
        if ((int) $item->id_order !== $orderId) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_IN_ORDER'), 'error');
            return false;
        }

        // Removing a line changes what the order is worth: an order that
        // names no currency is not edited. Refused before anything is read
        // or written, in the words every other refusal of this method uses.
        try {
            $orderCurrency = OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            $this->refuseOrderItem(message: $e->getMessage());

            return false;
        }

        // A LINE ON A SALES DOCUMENT STAYS. The document's line keeps pointing at
        // this row, and once it is gone every later credit naming it is refused for
        // good ("order line #X does not belong to order #Y"). Drafts count, exactly
        // as they do when the whole order is deleted (ordersWithDocuments()). The
        // message is set on the model too: the API's removeLine reads it from there.
        $holding = $this->documentsWithLine($id);

        if ($holding !== []) {
            // Plain text for the REST error; the queued copy renders as HTML,
            // so the frozen document numbers are escaped there.
            $this->setError(Text::sprintf('COM_ALFA_ORDER_ITEM_ERROR_DELETE_HAS_DOCUMENTS', implode(', ', $holding)));
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_ORDER_ITEM_ERROR_DELETE_HAS_DOCUMENTS', htmlspecialchars(implode(', ', $holding), ENT_QUOTES, 'UTF-8')),
                'error',
            );

            return false;
        }

        // One transaction: the line, its children (field freezes, tax
        // breakdown, download entitlements), the inventory reconciliation AND
        // the history event commit together or not at all: no orphaned
        // children, no mutation without its record.
        $totalsBefore = OrderTotalHelper::getBreakdown($orderId);
        $nested = TransactionState::isOpen($db);

        $db->transactionStart(true);

        try {
            // Per-line children die with the line (a removed line must not
            // keep serving its files).
            foreach ([
                '#__alfa_order_item_fields' => 'id_order_item',
                '#__alfa_order_downloads' => 'id_order_item',
                // The parcel association outlives the line unless we say so:
                // it used to be an FK ON the line, which died with it.
                '#__alfa_order_shipment_items' => 'id_order_item',
            ] as $childTable => $childColumn) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName($childTable))
                        ->where($db->quoteName($childColumn) . ' = ' . $id),
                )->execute();
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_order_items'))
                    ->where($db->quoteName('id') . ' = ' . (int) $id)
                    ->where($db->quoteName('id_order') . ' = ' . (int) $orderId),
            )->execute();

            // Reconcile inventory: the vanished line's deductions return and
            // its holds release (kernel per-line ledger, idempotent).
            if ((int) $item->quantity > 0) {
                InventoryBridge::syncOrderLines($orderId);
            }

            // Activity log: inside the transaction (mutation + history land
            // or vanish together), with before/after total snapshots.
            $totalsAfter = OrderTotalHelper::getBreakdown($orderId);
            // The unit as the log keeps it: the Auto form of the one unit rule at
            // the order's frozen scale, plain.
            $priceFormatted = Currency::unitFigure(
                unit: $item->unit_price_tax_incl ?? 0,
                lineTotal: $item->total_price_tax_incl ?? 0,
                quantity: $item->quantity ?? 0,
                scale: $orderCurrency->getDecimalPlaces(),
                rate: $item->custom_unit_price_tax_incl ?? null,
                auto: true,
            )['figure'];
            self::logOrderActivityStrict(
                $orderId,
                'item.deleted',
                "Deleted \"{$item->name}\" (qty: {$item->quantity}, price: {$priceFormatted}, stock restored)",
                [
                    'id_item' => (int) $item->id_item,
                    'name' => $item->name ?? '',
                    'quantity' => (int) $item->quantity,
                    'unit_price_tax_incl' => (float) ($item->unit_price_tax_incl ?? 0),
                    'unit_price_tax_excl' => (float) ($item->unit_price_tax_excl ?? 0),
                    'tax_rate' => (float) ($item->tax_rate ?? 0),
                    'stock_restored' => true,
                    'totals_before' => [
                        'grand_total_tax_incl' => $totalsBefore->grand_total_tax_incl,
                        'grand_total_tax_excl' => $totalsBefore->grand_total_tax_excl,
                    ],
                    'totals_after' => [
                        'grand_total_tax_incl' => $totalsAfter->grand_total_tax_incl,
                        'grand_total_tax_excl' => $totalsAfter->grand_total_tax_excl,
                    ],
                ],
                $id,
            );

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended a caller's transaction is the caller's to hear.
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            // Escaped, as the task's own catch escapes: the driver's words
            // can quote a stored value.
            Factory::getApplication()->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');

            return false;
        }

        if ((int) $item->quantity > 0) {
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_ORDER_ITEM_RESTOCKED', (int) $item->quantity, htmlspecialchars((string) $item->name, ENT_QUOTES, 'UTF-8')),
                'message',
            );
        }

        return true;
    }

    /**
     * Load existing order item row from DB (all fields preserved)
     *
     * @param int $itemPK Row PK
     * @since  1.0.0
     */
    protected function loadExistingItemRow(int $itemPK): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_items')
            ->where('id = ' . intval($itemPK));
        $db->setQuery($query);

        try {
            $row = $db->loadObject();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ORDER HISTORY (reads from unified order_activity_log)

    /**
     * Get order history (full activity log)
     *
     * Status is captured on EVERY row via id_order_status + status_name columns.
     * No need for a separate status query; filter in the UI if needed.
     *
     * @param int $orderId Order ID
     * @return array Activity log entries
     * @since  1.0.0
     */
    public function getOrderHistory($orderId)
    {
        return $this->getOrderActivityLog((int) $orderId);
    }

    // Save & delete

    /**
     * Prepare and sanitize the table data before save
     *
     * Called automatically by AdminModel::save() after bind(), before check()+store().
     * This is the Joomla-standard hook for data sanitization.
     *
     * Handles:
     * - MySQL strict mode: empty strings -> null for nullable int/datetime columns
     * - Default values for required fields
     * - Timestamp management
     *
     * @param \Joomla\CMS\Table\Table $table The table instance
     * @since  1.0.0
     */
    protected function prepareTable($table)
    {
        parent::prepareTable($table);

        // MySQL strict mode: '' -> null for nullable integer columns
        //
        // Joomla forms send '' for empty fields. MySQL strict mode rejects
        // '' for int columns. Convert to null for all nullable FK/meta fields.
        $nullableIntColumns = [
            'checked_out',
            // id_currency is NOT here any more (currency item): an empty posted
            // value used to become NULL, and OrderFacts then resolved no
            // currency and fell through to the shop default, re-deriving the
            // order's money in a DIFFERENT CURRENCY, not merely at a different
            // scale. OrderTable::store() now refuses the clear outright, and
            // leaving it in this list would only turn that refusal into a
            // confusing one.
            'id_user',
            'id_address_delivery', 'id_address_invoice',
            'id_payment_method', 'id_shipment_method', 'id_order_status',
            'id_payment_currency', 'id_language',
            'id_coupon', 'code_coupon',
            'modified_by', 'created_by',
        ];

        foreach ($nullableIntColumns as $col) {
            if (property_exists($table, $col) && $table->{$col} === '') {
                $table->{$col} = null;
            }
        }

        // Nullable datetime columns
        $nullableDateColumns = ['checked_out_time', 'invoice_date', 'delivery_date'];

        foreach ($nullableDateColumns as $col) {
            if (property_exists($table, $col) && empty($table->{$col})) {
                $table->{$col} = null;
            }
        }

        // Timestamps
        $now = Factory::getDate()->toSql();
        $user = Factory::getApplication()->getIdentity();

        if (!(int) $table->id) {
            // New record
            if (empty($table->created)) {
                $table->created = $now;
            }
            if (empty($table->created_by)) {
                $table->created_by = $user->id;
            }

            // Default the order status to whichever row admin nominated as
            // is_initial. OrderStatusHelper falls back to the first published
            // row by ordering when no role has been assigned yet, so this
            // never blocks order creation even on a fresh install.
            if (empty($table->id_order_status)) {
                $table->id_order_status = OrderStatusHelper::getInitialId();
            }
        }

        $table->modified = $now;
        $table->modified_by = $user->id;

        // The status is not ordinary order data
        // Table::store() writes the WHOLE row, not the keys that were bound,
        // so leaving id_order_status on the object means every ordinary save
        // re-writes it from a value read before the form was even rendered:
        // an unlocked read-modify-write that silently reverts a Transition a
        // webhook committed in the meantime. Unsetting the property removes
        // the column from the UPDATE altogether (DatabaseDriver::updateObject
        // enumerates get_object_vars), leaving Transition as the one writer,
        // under the one lock.
        //
        // Creation is untouched: a new order still persists the initial status
        // chosen above, because it has no id yet.
        if ((int) $table->id > 0) {
            unset($table->id_order_status);
        }
    }

    /**
     * The origin word this model writes onto an order it creates.
     *
     * save()'s new-record branch is reached by BOTH clients: the admin form
     * controller and, through the API OrderModel that extends this class, a
     * POST to /v1/alfa/orders. It wrote the literal 'admin' either way, so a
     * live, ACL-gated API route was labelling its orders as hand-made in the
     * backend, on the permanent commercial record, with nothing to tell them
     * apart afterwards.
     *
     * A seam rather than an isClient('api') probe: the API model already
     * extends this one, so the client that is running IS the class that is
     * running and nothing has to ask the application. `source` is not in
     * forms/order.xml either, so a client cannot mislabel itself: core's
     * ApiController passes only form-validated data to save().
     *
     * @return string One of the documented origins: storefront | admin | renewal | api.
     *
     * @since  2.0.0
     */
    protected function newOrderSource(): string
    {
        return 'admin';
    }

    /**
     * Save an order with stock operation handling.
     *
     * On status change, delegates to StockPolicy::handleStatusTransition()
     * which detects 0<->1 transitions in stock_operation and bulk-adjusts stock.
     *
     * @param array $data The form data.
     * @return bool True on success, false on failure.
     *
     * @throws OrderCurrencyException When the order exists and names no currency: nothing is written.
     * @throws Throwable What ended a caller's transaction, when the order was saved inside one.
     *
     * @since  1.0.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $table = $this->getTable();

        $key = $table->getKeyName();
        $pk = (int) ($data[$key] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;

        // The order as it stands, read in its own currency. An order that
        // names no currency is refused by this read, before anything is
        // written: it is not edited through this screen or through the API.
        $prevOrder = !$isNew ? $this->getItem($pk) : null;

        // The target status is validated before the row is written:
        // parent::save() persists id_order_status itself, so leaving it to
        // Transition would strand the order in an unknown or unpublished
        // status with none of the side effects applied.
        if (array_key_exists('id_order_status', $data)) {
            $candidate = (int) $data['id_order_status'];
            $current = (int) ($prevOrder->id_order_status ?? 0);

            // A new order has no current status to compare against, and an
            // omitted one is legitimate (creation falls back to the initial
            // status), but a status that was actually chosen is checked, so
            // the operator is told rather than silently landing elsewhere.
            // Authorisation first, and before the target is examined at all:
            // a caller who may not move this order must not be able to use the
            // refusal reason as an oracle for which statuses exist.
            //
            // Only an actual move is gated. The edit form posts the current
            // status on every save, so treating "the field is present" as "the
            // status is changing" would turn orders edit.state into a permission
            // to edit an order at all.
            if (!$isNew && $candidate !== $current && !self::mayChangeStatus()) {
                $app->enqueueMessage(Text::_('COM_ALFA_ERROR_ORDER_STATUS_FORBIDDEN'), 'error');

                return false;
            }

            if ($isNew ? $candidate > 0 : $candidate !== $current) {
                // A submitted 0 is not "leave it alone": it would persist a
                // dangling reference that every later status lookup reads as
                // "no status".
                $reason = match (true) {
                    $candidate <= 0 => 'no target status given',
                    // Creation asks only the structural question: there is no
                    // order yet for a payment gate to weigh.
                    $isNew => Transition::isValidTarget($candidate)
                        ? null
                        : 'status ' . $candidate . ' does not exist or is not published',
                    default => Transition::refusalReason((int) $pk, $candidate),
                };

                if ($reason !== null) {
                    // Escaped once: the queue renders HTML, and a reason can name
                    // a product or a variant.
                    $app->enqueueMessage(
                        Text::sprintf('COM_ALFA_ERROR_ORDER_STATUS_REFUSED', htmlspecialchars(string: $reason, flags: ENT_QUOTES, encoding: 'UTF-8')),
                        'error',
                    );

                    return false;
                }
            }
        }

        // Manual creation: the ONE write path. A new backend order is a
        // metadata-only shell (explicit allow_empty) created through
        // OrderCreator: same defaults, guards and audit trail as the
        // checkout and the renewals. Lines/payments/shipments are added
        // afterwards on the normal edit screen; from the first line on,
        // prices are FROZEN (recalculation only via the explicit action).
        if ($isNew) {
            try {
                $identity = $app->getIdentity();
                $customerId = (int) ($data['id_user'] ?? 0);

                // The addresses and the order are one act
                //
                // These rows used to be written before OrderCreator opened its
                // transaction, so a creation that failed left them behind with
                // no order to belong to, and nothing collects them:
                // UserInfoHelper::deleteRow() refuses any row an order
                // references, and an orphan references nothing, so no surface
                // can reach it. Measured: one forced failure, one stranded row.
                //
                // OrderCreator already takes `manageTransaction: false` for
                // exactly this: a caller that owns the wider transaction. No
                // new mechanism, just the seam it already offers.
                $db = $this->getDatabase();
                $db->transactionStart(true);

                try {
                    // The customer's details (dynamic checkout fields) become
                    // the order's delivery address record, owned by the
                    // customer the order is for, the same as the checkout's.
                    $addressId = $this->createUserInfo($data[FieldsHelper::FIELDS_KEY] ?? [], $customerId);

                    // The invoice bucket only survives validation when "different
                    // details" was ticked; absent means the delivery record doubles
                    // as the invoice record: the same default as the checkout.
                    $invoiceId = $this->createUserInfo($data[FieldsHelper::BILLING_FIELDS_KEY] ?? [], $customerId)
                        ?: $addressId;

                    // The customer snapshot: the picked account, or the guest
                    // identity typed into the dynamic fields.
                    $customer = $customerId > 0
                        ? OrderIdentity::snapshotFromUser($customerId)
                        : OrderIdentity::snapshotFromDetails((array) ($data[FieldsHelper::FIELDS_KEY] ?? []));

                    $created = OrderCreator::create(draft: [
                        'id_user' => $customerId,
                        'customer_name' => $customer['customer_name'],
                        'customer_email' => $customer['customer_email'],
                        'customer_is_guest' => $customer['customer_is_guest'],
                        'id_order_status' => (int) ($data['id_order_status'] ?? 0),
                        'id_address_delivery' => $addressId,
                        'id_address_invoice' => $invoiceId,
                        'source' => $this->newOrderSource(),
                        'created_by' => (int) ($identity?->id ?? 0),
                        'actor_name' => (string) ($identity?->name ?? 'System'),
                        'allow_empty' => true,
                        'lines' => [],
                    ], manageTransaction: false);

                    TransactionState::commitLevel($db);
                } catch (Throwable $inner) {
                    // \Throwable, not Exception: an Error raised in the
                    // creation must not leave this transaction open, or every
                    // later write in the request commits into nothing.
                    TransactionState::rollBackLevel(db: $db, failure: $inner);

                    throw $inner;
                }

                // OrderCreator writes the order.created activity entry itself.
                $this->setState($this->getName() . '.id', $created->orderId);

                return true;
            } catch (Exception $e) {
                $this->setError($e->getMessage());

                return false;
            }
        }

        // An existing order is saved in phases
        //
        // The order used to be written one statement at a time with no
        // boundary at all, and the status was written FIRST, before the
        // buyer's own details. A status that auto-issues a fiscal document
        // therefore froze the identity the operator had just corrected out of
        // the document, permanently: the buyer block is copied into
        // #__alfa_documents and an issued document is never edited. And a
        // failure anywhere after that left the consequences (stock, a gapless
        // document number, the customer's email) standing against an order
        // whose details had not been saved.
        //
        //   B. every reversible write, together, or none of them
        //   C. commit, so the transition reads a whole order
        //   D. Transition, which alone moves the status
        //   E. report what is actually persisted, not what was asked for
        //   F. best-effort tidying
        //
        // Phase B holds a transaction across parent::save(), which dispatches
        // the admin save events. That is deliberate and it is the reason those
        // listeners must not perform network or filesystem work: they run
        // inside this transaction. The shipped listener stamps a key and
        // enqueues a message. A listener that needs external I/O belongs on
        // the AFTER-commit surfaces, not here.
        $db = $this->getDatabase();
        $orderId = $pk;

        $oldStatusId = (int) ($prevOrder->id_order_status ?? 0);
        $targetStatusId = (int) ($data['id_order_status'] ?? $oldStatusId);

        $userInfoData = $data[FieldsHelper::FIELDS_KEY] ?? [];

        // Whether this submission is the EDIT FORM, which always posts its address
        // section. The inline routes (the orders list's status dropdown, note and
        // bulk actions through OrderController::updateOrder) prefill only the table
        // row and post no section at all. Only a submission that carries the section
        // can say anything about the addresses; one that does not leaves them as they are.
        $hasAddressSection = \array_key_exists(FieldsHelper::FIELDS_KEY, $data);

        // Rows this save re-points AWAY from. Collected rather than deleted on
        // the spot: the invoice pointer may still be on the delivery row at
        // this point and only settles a few lines below, so a sweep here would
        // read a reference that is about to disappear.
        $superseded = [];

        // Phase B
        $nested = TransactionState::isOpen($db);
        $db->transactionStart(true);

        try {
            if (!parent::save($data)) {
                // parent::save() answers false and sets its own error rather
                // than throwing. Without this the rest of phase B would run on
                // an order that was never written.
                //
                // Its error may be a driver message (AdminModel::save() catches
                // the exception and stores getMessage() verbatim), so it is
                // logged where it is useful and replaced with a sentence the
                // operator can act on.
                Log::add(
                    'Order #' . $orderId . ': the order row was refused: ' . (string) $this->getError(),
                    Log::ERROR,
                    'com_alfa.orders',
                );

                $this->setError(Text::_('COM_ALFA_ERROR_ORDER_SAVE_FAILED'));

                throw new RuntimeException(self::PHASE_B_REFUSED);
            }

            $orderId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id');

            // The customer's details become the order's delivery record.
            if (!empty($userInfoData) && !empty($data['id_address_delivery'])) {
                $currentId = (int) $data['id_address_delivery'];
                $newId = UserInfoHelper::insertData(
                    // id_user is filter="unset" on edit, so $data never carries it there;
                    // read the customer from the authoritative record, not the form; else
                    // every save runs the guest (userId 0) path and duplicates the address.
                    userId: (int) ($prevOrder->id_user ?? 0),
                    data: $userInfoData,
                    existingId: $currentId ?: null,
                );

                if ($newId <= 0) {
                    $this->setError(Text::_('COM_ALFA_ERROR_ORDER_ADDRESS_NOT_SAVED'));

                    throw new RuntimeException(self::PHASE_B_REFUSED);
                }

                // insertData snapshots frozen rows: a row referenced by order
                // history is never overwritten: edits land on a NEW row and
                // the order re-points to it.
                if ($newId !== $currentId) {
                    $data['id_address_delivery'] = $newId;
                    $superseded[] = $currentId;

                    $query = $db->getQuery(true)
                        ->update($db->quoteName('#__alfa_orders'))
                        ->set($db->quoteName('id_address_delivery') . ' = ' . $newId)
                        ->where($db->quoteName('id') . ' = ' . $orderId);
                    $db->setQuery($query);
                    $db->execute();
                }
            }

            // The invoice bucket survives validation only when "different
            // details" is ticked, so on a submission that carries the address
            // section its absence is the explicit "invoice to the delivery
            // address" choice: the same contract the checkout uses, and what
            // keeps a de-ticked order from keeping a stale invoice record.
            //
            // A submission WITHOUT the section made no such choice. Reading its
            // missing bucket as one re-pointed the invoice at the delivery record
            // on every inline note or status change and, for a guest, discarded
            // the separate invoice record for good. The API controller documents
            // the same collapse and restores the pointer around its own saves.
            $previousInvoiceId = (int) ($prevOrder->id_address_invoice ?? 0);

            if ($hasAddressSection) {
                if (
                    !$this->saveInvoiceInfo(
                        orderId: $orderId,
                        data: $data,
                        customerId: (int) ($prevOrder->id_user ?? 0),
                        previousInvoiceId: $previousInvoiceId,
                    )
                ) {
                    $this->setError(Text::_('COM_ALFA_ERROR_ORDER_ADDRESS_NOT_SAVED'));

                    throw new RuntimeException(self::PHASE_B_REFUSED);
                }

                if ((int) ($data['id_address_invoice'] ?? 0) !== $previousInvoiceId) {
                    $superseded[] = $previousInvoiceId;
                }
            }

            // A guest's identity IS the delivery details: a corrected name or
            // email re-derives the order's customer snapshot. An account's
            // snapshot is frozen: the account is the identity.
            $this->refreshGuestSnapshot(
                orderId: $orderId,
                prevOrder: $prevOrder,
                deliveryId: (int) ($data['id_address_delivery'] ?? 0),
            );

            // Inside the transaction on purpose: a save that rolls back must
            // not leave a timeline entry saying it happened.
            $this->logOrderChanges($orderId, $prevOrder, $data, $userInfoData);

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended a caller's transaction is the caller's to hear.
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            if ($e->getMessage() !== self::PHASE_B_REFUSED) {
                // A real fault. The operator gets a sentence, not a driver
                // message; the detail goes where it can be read safely.
                Log::add(
                    'Order #' . (int) $orderId . ': the edit was rolled back: '
                    . $e->getMessage() . "\n" . $e->getTraceAsString(),
                    Log::CRITICAL,
                    'com_alfa.orders',
                );

                $this->setError(Text::_('COM_ALFA_ERROR_ORDER_SAVE_FAILED'));
            }

            return false;
        }

        // Phase D + E
        // Past the commit. The order is whole and correct as it stands, so
        // nothing below may turn this into a failed save.
        if ($targetStatusId !== $oldStatusId) {
            $this->applyStatusChange($orderId, $oldStatusId, $targetStatusId);
        }

        // Phase F
        // Last, and outside the transaction: the replacement rows are already
        // durable, so this is tidying and may never fail a coherent save.
        $this->discardSupersededAddresses($superseded);

        return true;
    }

    /**
     * What a status change did to the order's stock, in the operator's language.
     *
     * One sentence per action, because each says something different: a deduction
     * counts the order lines it fulfilled; a restore returns what the order took out
     * and releases its reservations; a reservation holds the order's lines again. The
     * action's code is never shown. An action the stock policy does not have is not
     * reported: the order is saved either way, and this runs after the commit.
     *
     * @param string $action The stock result's action: 'deducted', 'restored' or 'reserved'.
     * @param int $count The stock result's count: the order lines a deduction fulfilled.
     *
     * @return string|null The message, or null for an action the stock policy does not have.
     *
     * @since  2.1.1
     */
    private static function stockAdjustedMessage(string $action, int $count): ?string
    {
        return match ($action) {
            'deducted' => Text::plural('COM_ALFA_ORDER_STOCK_ADJUSTED_DEDUCTED', $count),
            'restored' => Text::_('COM_ALFA_ORDER_STOCK_ADJUSTED_RESTORED'),
            'reserved' => Text::_('COM_ALFA_ORDER_STOCK_ADJUSTED_RESERVED'),
            default => null,
        };
    }

    /**
     * Move the order, then say what is actually true afterwards.
     *
     * Phase B is committed before this runs, so the order's own details are
     * saved and correct whatever happens here; this can add a warning, never
     * un-save the edit.
     *
     * The old code called Transition, threw the answer away, and then told the
     * operator that a stock movement "is recorded and will be applied
     * automatically". On every refusal that sentence was false: Transition
     * nulls its stock result on entry and no refusal path sets it, so the
     * message fired precisely when nothing had been recorded.
     *
     * What replaces it re-reads the row. A re-read is the only thing that can
     * be honestly reported: it says where the order IS, not who put it there:
     * under concurrency another writer may have reached the same status first,
     * and claiming the credit would be a guess.
     *
     * @param int $orderId The order being moved.
     * @param int $from The status it was in when this save began.
     * @param int $target The status the operator asked for.
     *
     *
     * @since  2.0.0
     */
    protected function applyStatusChange(int $orderId, int $from, int $target): void
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        // What the timeline already holds, so a refusal Transition recorded
        // itself is not written twice.
        $logMark = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(MAX(' . $db->quoteName('id') . '), 0)')
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId),
        )->loadResult();

        // No from(): the row still holds the old status, so Transition reads it
        // under its own lock, writes the new one, and owns the consequences:
        // the same contract every other producer in the tree uses.
        $transition = Transition::for($orderId)->to($target)->force();
        $threw = false;

        try {
            $moved = $transition->save();
        } catch (Throwable $e) {
            $moved = false;
            $threw = true;

            Log::add(
                'Order #' . $orderId . ': the status transition ' . $from . ' -> ' . $target
                . ' failed after the order itself had been saved: ' . $e->getMessage()
                . "\n" . $e->getTraceAsString(),
                Log::CRITICAL,
                'com_alfa.orders',
            );
        }

        if ($moved) {
            $stockResult = $transition->getStockResult();

            if ($stockResult !== null && ($stockResult['action'] ?? 'none') !== 'none') {
                $adjusted = self::stockAdjustedMessage(action: (string) $stockResult['action'], count: (int) $stockResult['count']);

                if ($adjusted !== null) {
                    $app->enqueueMessage($adjusted, 'message');
                }
            } elseif ($stockResult === null) {
                // Only reachable now that the transition really ran. A refused
                // effect is not retried: an order line has no stock identity to
                // move against until the operator resolves it, so the message
                // says that instead of promising an automatic retry. Any other
                // miss is an obligation of this order and is retried until the
                // movement exists; telling the operator to reconcile by hand
                // would invite a double adjustment. A movement that waits for
                // stock other claims reserve names that stock. Each reason is
                // the inventory service's or the drain's own text, escaped here
                // once because the message queue renders HTML.
                $refusal = $transition->getStockRefusal();
                $reserved = $transition->getStockReservedFloor();
                $failure = $transition->getStockFailure();

                $app->enqueueMessage(
                    msg:  match (true) {
                        $refusal !== null => Text::sprintf(
                            'COM_ALFA_ORDER_STOCK_REFUSED',
                            htmlspecialchars(string: $refusal, flags: ENT_QUOTES, encoding: 'UTF-8'),
                        ),
                        $reserved !== null => Text::sprintf(
                            'COM_ALFA_ORDER_STOCK_RESERVED_ELSEWHERE',
                            htmlspecialchars(string: $reserved, flags: ENT_QUOTES, encoding: 'UTF-8'),
                        ),
                        $failure !== null => Text::sprintf(
                            'COM_ALFA_ORDER_STOCK_NOT_MOVED',
                            htmlspecialchars(string: $failure, flags: ENT_QUOTES, encoding: 'UTF-8'),
                        ),
                        default => Text::_('COM_ALFA_ORDER_STOCK_DEFERRED'),
                    },
                    type: 'warning',
                );
            }

            return;
        }

        // The transition did not run. Report the row, not the intention.
        $persisted = self::persistedStatus($orderId);
        $atTarget = $persisted === $target;

        if ($atTarget) {
            $app->enqueueMessage(
                Text::_($threw
                    ? 'COM_ALFA_ORDER_STATUS_REACHED_FOLLOWUP_FAILED'
                    : 'COM_ALFA_ORDER_STATUS_ALREADY_CURRENT'),
                $threw ? 'error' : 'message',
            );
        } else {
            $app->enqueueMessage(
                Text::sprintf(
                    $threw
                        ? 'COM_ALFA_ORDER_STATUS_PROCESSING_FAILED'
                        : 'COM_ALFA_ORDER_STATUS_NOT_CHANGED',
                    $this->statusName($persisted),
                ),
                'error',
            );
        }

        // Nothing was refused and nothing failed: the order was already where it
        // was asked to be, so this request has nothing to add to its history.
        if ($atTarget && !$threw) {
            return;
        }

        // The order DID reach the target and the fault came afterwards. Calling
        // that a refusal would put a false record on the timeline: the move
        // happened; what did not finish is the work that follows it.
        if ($atTarget) {
            self::logOrderActivity(
                $orderId,
                'order.status_followup_failed',
                'The order reached status ' . $target . ', but the processing that follows it failed.',
                [
                    'from' => $from,
                    'to' => $target,
                    'persisted' => $persisted,
                    'reason' => 'status processing failed after the status was committed',
                ],
            );

            return;
        }

        // Transition records its own refusal for one of its guards. Only the
        // outcomes it did not put on the timeline are added here.
        $alreadyRecorded = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('id') . ' > ' . $logMark)
                ->where($db->quoteName('event') . ' = ' . $db->quote('order.transition_refused')),
        )->loadResult() > 0;

        if ($alreadyRecorded) {
            return;
        }

        self::logOrderActivity(
            $orderId,
            'order.transition_refused',
            $threw
                ? 'The order details were saved; status processing for ' . $from . ' -> ' . $target . ' failed.'
                : 'The order details were saved; the status was not moved ' . $from . ' -> ' . $target . '.',
            [
                'from' => $from,
                'to' => $target,
                'persisted' => $persisted,
                'reason' => $threw ? 'status processing failed' : 'the transition was refused',
            ],
        );
    }

    /**
     * A status' name for an operator message, or its id when it has none.
     *
     * @param int $statusId The status to name.
     *
     * @return string Something safe to put in a message.
     *
     * @since  2.0.0
     */
    protected function statusName(int $statusId): string
    {
        $status = OrderStatusHelper::getById($statusId);

        return (string) ($status->name ?? '') !== '' ? (string) $status->name : '#' . $statusId;
    }

    /**
     * Drop the address rows this save re-pointed away from, when nothing is
     * left holding them.
     *
     * WHY THIS EXISTS. `UserInfoHelper::insertData()` short-circuits on
     * `$userId <= 0` and always INSERTs: a guest order forks a new address row
     * on every full-form save, even one that changes nothing, and the order
     * re-points to it. The row it left behind is then unreachable BY
     * CONSTRUCTION, not by oversight: `getRow()` returns null on its first line
     * for a guest, `getRowsByUser(0)` returns [], and `deleteRow()` gates on
     * `getRow()`, so no surface in the product can ever select it again. It
     * holds a name, a phone number and a postal address, and nothing can reach
     * it to delete it.
     *
     * The product already says what should happen. `deleteRelatedData()` purges
     * the order's `id_user = 0` rows when the order goes, and the API's
     * `restoreInvoiceAddress()` deletes the row its own PATCH minted for the
     * same reason. A guest address row lives exactly as long as something
     * points at it; this closes the one path that broke that.
     *
     * WHAT IT DELIBERATELY DOES NOT DO:
     *
     *   - A REGISTERED customer's superseded row is kept. It is a real entry in
     *     their address book, reachable and reusable; `id_user = 0` is the
     *     whole test, and 0 is a value here, not "unset".
     *   - A row something ELSE still references is kept. Rows are shared: a
     *     renewal draft copies `id_address_delivery` from its origin order
     *     verbatim, and a cart carries the same id into the order it places. So
     *     the reference test names BOTH referring tables, and it lives INSIDE
     *     the DELETE: testing first and deleting second would leave a window
     *     in which a concurrent save adopts the row between the two.
     *   - Rows orphaned before this fix are left alone. This collects what one
     *     save superseded; it is not a garbage collector, and sweeping the
     *     historical backlog is a separate, separately-decided job.
     *
     * @param int[] $ids Address rows this save re-pointed away from.
     *
     *
     * @since  2.0.0
     */
    private function discardSupersededAddresses(array $ids): void
    {
        $db = $this->getDatabase();

        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            try {
                $db->setQuery(
                    'DELETE FROM ' . $db->quoteName('#__alfa_user_info')
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
                    . ' AND ' . $db->quoteName('id_user') . ' = 0'
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_orders', 'o')
                    . '   WHERE ' . $db->quoteName('o.id_address_delivery') . ' = ' . $id
                    . '      OR ' . $db->quoteName('o.id_address_invoice') . ' = ' . $id . ')'
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_cart', 'c')
                    . '   WHERE ' . $db->quoteName('c.id_user_info_delivery') . ' = ' . $id
                    . '      OR ' . $db->quoteName('c.id_user_info_invoice') . ' = ' . $id . ')',
                );
                $db->execute();
            } catch (Throwable $e) {
                // The order is already saved and correct; a leftover row is
                // untidy, never wrong. Say so instead of failing the save.
                Log::add(
                    'Order #' . $this->getState('order.id') . ': the superseded address row #' . $id
                    . ' could not be removed - ' . $e->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }
    }

    /**
     * Diff ALL changes from one save and log as a single order.edited event
     *
     * Compares:
     * - Order fields (status, payment method, notes, dates, etc.)
     * - Customer/address info (name, phone, address, etc.)
     *
     * One save = one row in the activity log. The status snapshot columns
     * (id_order_status, status_name) on the log row already capture what
     * status the order is in; no separate status event needed.
     *
     * @param int $orderId Order PK
     * @param object|null $prevOrder Previous order from getItem() (includes user_info)
     * @param array $data New form data
     * @param array $userInfoData New customer/address data (com_alfa fields)
     * @since  1.0.0
     */
    protected function logOrderChanges(int $orderId, ?object $prevOrder, array $data, array $userInfoData = []): void
    {
        if (!$prevOrder) {
            return;
        }

        $changes = [];

        // Order fields
        // id_order_status is deliberately ABSENT: Transition owns the status
        // event ('order.status_changed', one funnel, one row). Diffing it here
        // as well double-logged every admin status change, exactly what
        // this method's docblock promises not to do.
        // id_currency is deliberately ABSENT (currency item): it is write-once
        // and OrderTable::store() refuses a change, so a diff entry for it
        // would document an expectation that can no longer come true.
        $orderFields = [
            'id_address_delivery', 'id_address_invoice',
            'id_payment_method', 'id_shipment_method',
            'customer_note', 'note', 'secure_key',
            'invoice_number', 'invoice_date', 'delivery_date',
        ];

        foreach ($orderFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            // FK columns: NULL, '' and 0 all mean "no reference"; compare as
            // ints, or a status-only save of an order with a NULL invoice
            // address logs a phantom "Edited: id_address_invoice ''->'0'" row.
            if (str_starts_with($field, 'id_')) {
                $oldId = (int) ($prevOrder->{$field} ?? 0);
                $newId = (int) ($data[$field] ?? 0);

                if ($oldId !== $newId) {
                    $changes[$field] = ['from' => $oldId, 'to' => $newId];
                }

                continue;
            }

            $oldVal = (string) ($prevOrder->{$field} ?? '');
            $newVal = (string) ($data[$field] ?? '');

            if ($oldVal !== $newVal) {
                $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        // Customer/address fields
        $oldUserInfo = $prevOrder->user_info ?? null;

        if ($oldUserInfo && !empty($userInfoData)) {
            foreach ($userInfoData as $field => $newVal) {
                if ($field === 'id') {
                    continue;
                }

                $oldVal = (string) ($oldUserInfo->{$field} ?? '');
                $newVal = (string) $newVal;

                if ($oldVal !== $newVal) {
                    $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
                }
            }
        }

        // Nothing changed -> no log entry
        if (empty($changes)) {
            return;
        }

        // Build human-readable summary from changed field names
        $summary = 'Edited: ' . implode(', ', array_keys($changes));

        // Inside the edit's transaction: the edit rolls back with its record.
        self::logOrderActivityStrict($orderId, 'order.edited', $summary, $changes);
    }

    /**
     * Change the published state of the selected orders. An order restored from
     * the trash comes back in the status it was trashed in: no status change is
     * made, so nothing else would judge whether that status may still hold it. An
     * order whose status holds a reservation is therefore asked what a move into
     * that status is asked ({@see InventoryBridge::untrashRefusal()}): one with a
     * line that would wait for its stock without a reservation stays in the trash
     * and is named in a message, and the others are restored.
     *
     * Only orders the user may change the state of are asked; the others, and
     * every order of a move into the trash, are left to the parent.
     *
     * @param array &$pks The order ids (orders left in the trash are removed in place).
     * @param int $value The published state to set: -2 moves into the trash, any
     *                   other value takes a trashed order out of it.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function publish(&$pks, $value = 1)
    {
        $pks = (array) $pks;

        if ((int) $value !== -2) {
            $table = $this->getTable();

            foreach ($pks as $index => $pk) {
                $table->reset();

                if (!$table->load(keys: (int) $pk) || !$this->canEditState(record: $table)) {
                    continue;
                }

                $refusal = InventoryBridge::untrashRefusal(orderId: (int) $pk);

                if ($refusal === null) {
                    continue;
                }

                unset($pks[$index]);

                // The refusal is raw and the message queue renders HTML.
                Factory::getApplication()->enqueueMessage(
                    msg:  Text::sprintf(
                        'COM_ALFA_ORDERS_UNTRASH_REFUSED',
                        (int) $pk,
                        htmlspecialchars(string: $refusal, flags: ENT_QUOTES, encoding: 'UTF-8'),
                    ),
                    type: 'warning',
                );
            }
        }

        return parent::publish(pks: $pks, value: $value);
    }

    /**
     * Delete the selected orders. An order that holds a sales document is never
     * deleted, and neither is one with a refund that is not settled (a
     * reservation: PaymentLedger::orderDeleteRefusal()): both are answered
     * before any plugin is asked, and decided again under the order lock. Each order's payment and shipment plugins get the
     * onAdminOrderDelete event; if any plugin vetoes (returns false) that order
     * is skipped. Under the lock the delete is refused when a payment or
     * shipment changed after its plugins were asked. Surviving orders have
     * their related rows removed (deleteRelatedData) before parent::delete runs.
     * A refusal or a failure leaves every selected order as it was and sets the
     * error the operator reads.
     *
     * @param array &$pks The order ids to delete (vetoed ids are removed in place).
     *
     * @return bool True on success.
     *
     * @throws Throwable What ended a caller's transaction, when the orders were deleted inside one.
     *
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();
        $orders = [];

        // The API hands this a SCALAR id (ApiController::delete), while the
        // admin hands it an array. Unnormalised, the scalar made every loop
        // below a no-op and deleteRelatedData()'s array type-hint threw; the
        // throw was swallowed into "return false" and reported as 204.
        $pks = array_values(array_filter(array_map('intval', (array) $pks)));

        // Permission first: the documents lookup, the plugin vetoes, the stock
        // restore and deleteRelatedData() all run before parent::delete() asks.
        if (!AlfaHelper::can(section: 'orders', action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        if ($pks === []) {
            return true;
        }

        // A documents refusal holds back the whole selection, and its sentence
        // says so when several orders were selected.
        $selected = \count($pks);

        // Issued documents are legally immutable and their numbering is
        // gapless, so an order that holds one is never deleted. This early
        // answer spares the payment and shipment plugins a question about an
        // order that will be refused; the check under the order lock decides.
        $blocked = $this->ordersWithDocuments($pks);

        if ($blocked !== []) {
            $this->refuseDeleteForDocuments(blocked: $blocked, selected: $selected);

            return false;
        }

        // A refund of the order that is not settled reserves its amount: the order is not deleted (core's
        // rule for every gateway; the check under the order lock decides).
        if ($this->refuseDeleteForReservations(pks: $pks)) {
            return false;
        }

        // An order that names no currency is not deleted. Deleting returns
        // stock and wallet money and asks each payment and shipment plugin
        // about the order's money, and none of that can be said without the
        // order's own currency. The whole selection is refused, before any
        // plugin is asked and before anything is written. An id that names
        // no order is not this refusal's to answer.
        foreach ($pks as $pk) {
            try {
                OrderCurrency::of(orderId: $pk);
            } catch (OrderCurrencyException $e) {
                if ($e->orderExists()) {
                    $this->setError($e->getMessage());

                    return false;
                }
            }
        }

        foreach ($pks as $pk) {
            // Keyed BY PK: $pks may be sparse (AdminController builds its cid
            // with array_filter, which preserves keys), so a positional index
            // would unset a DIFFERENT order than the one a plugin vetoed.
            $orders[$pk] = $this->getItem($pk);
        }

        $onAdminOrderDeleteEventName = 'onAdminOrderDelete';
        $vetoed = [];

        foreach ($orders as $pk => $order) {
            $deleteEntry = true;

            foreach ($order->payments as $j => $payment) {
                if (!$deleteEntry) {
                    break;
                }

                // A reversal or a chargeback is the ledger's own record of a
                // provider fact: no plugin is ever passed one.
                if (\in_array((string) ($payment->payment_type ?? ''), PaymentLedger::FACT_TYPES, true)) {
                    continue;
                }

                // The row's persisted type: the plugin that took the payment is
                // asked for its veto, its method deleted or not. A row that
                // records no type (written before the snapshot existed) has no
                // plugin to ask, as a store-credit row has none: the core's own
                // refusals (issued documents, refunds not settled) still stand.
                // The plugin may be uninstalled; none of it may fatal a delete.
                $paymentType = ActionRegistry::methodType($payment, 'payment_method_type');

                if (\in_array($paymentType, PaymentLedger::NON_GATEWAY_TYPES, true)) {
                    $paymentType = '';
                }

                $paymentPlugin = $paymentType !== '' ? $app->bootPlugin($paymentType, 'alfa-payments') : null;

                if ($paymentPlugin !== null && method_exists($paymentPlugin, $onAdminOrderDeleteEventName)) {
                    $paymentDeleteOrderDataEvent = new PaymentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                        'subject' => $order,
                        'method' => $payment,
                    ]);

                    $paymentPlugin->{$onAdminOrderDeleteEventName}($paymentDeleteOrderDataEvent);
                    $deleteEntry = $paymentDeleteOrderDataEvent->getResult();
                }
            }

            if ($deleteEntry) {
                foreach ($order->shipments as $shipment) {
                    if (!$deleteEntry) {
                        break;
                    }

                    // Persisted type, same reasoning as the payment veto above.
                    $shipmentType = ActionRegistry::methodType($shipment, 'shipment_method_type');
                    $shipmentPlugin = $shipmentType !== '' ? $app->bootPlugin($shipmentType, 'alfa-shipments') : null;

                    if ($shipmentPlugin !== null && method_exists($shipmentPlugin, $onAdminOrderDeleteEventName)) {
                        $shipmentDeleteOrderDataEvent = new ShipmentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                            'subject' => $order,
                            'method' => $shipment,
                        ]);

                        $shipmentPlugin->{$onAdminOrderDeleteEventName}($shipmentDeleteOrderDataEvent);
                        $deleteEntry = $shipmentDeleteOrderDataEvent->getResult();
                    }
                }
            }

            if (!$deleteEntry) {
                $vetoed[] = $pk;
            }
        }

        if ($vetoed !== []) {
            $pks = array_values(array_diff($pks, $vetoed));
        }

        if ($pks === []) {
            return true;
        }

        // What the plugins were asked about, compared under the lock below.
        $asked = [];

        foreach ($pks as $pk) {
            $asked[$pk] = self::pluginRowFacts(
                payments: $orders[$pk]->payments ?? [],
                shipments: $orders[$pk]->shipments ?? [],
            );
        }

        // Related rows and the order rows must go together: a failure partway
        // through must not leave orphaned items/payments/shipments, or an order
        // with no lines. Wrap both in one transaction. The plugins were asked
        // above, outside it: their logging can create a table, and that
        // commits whatever transaction is open.
        $nested = TransactionState::isOpen($db);
        $db->transactionStart(true);

        try {
            // The orders are locked before anything else is read. Issuing a
            // document locks its order first as well, so the check below sees
            // every document issued before this point, and an issue that comes
            // later waits for this delete and then finds no order.
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $pks)) . ')'
                . ' ORDER BY ' . $db->quoteName('id') . ' FOR UPDATE',
            )->loadColumn();

            $blocked = $this->ordersWithDocuments(pks: $pks);
            $reserved = $blocked === [] && $this->refuseDeleteForReservations(pks: $pks);

            if ($blocked !== [] || $reserved) {
                TransactionState::rollBackLevel($db);

                if ($blocked !== []) {
                    $this->refuseDeleteForDocuments(blocked: $blocked, selected: $selected);
                }

                return false;
            }

            // A payment or shipment written or changed since its plugin was
            // asked was never put to that plugin, so its answer no longer holds.
            if ($this->lockedPluginRowFacts(pks: $pks) !== $asked) {
                TransactionState::rollBackLevel($db);
                $this->setError(error: Text::_('COM_ALFA_ERROR_ORDER_DELETE_CHANGED'));

                return false;
            }

            // INVENTORY RECONCILIATION: deducted stock returns and active
            // reservations release THROUGH the kernel (ledger-written,
            // idempotent by source identity) before the order rows vanish:
            // a deleted order must never keep quantities deducted or
            // reserved_quantity inflated.
            foreach ($pks as $pk) {
                InventoryBridge::restoreOrder((int) $pk);
            }

            $walletReturns = $this->deleteRelatedData($pks);

            if (!parent::delete($pks)) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended a caller's transaction is the caller's to hear.
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            // The caller reads the plain sentence; the failure's own words go
            // to the log.
            Log::add(
                entry: 'Orders ' . implode(separator: ', ', array: $pks) . ' were not deleted; nothing was changed: ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orders',
            );
            $this->setError(error: Text::_('COM_ALFA_ERROR_ORDER_DELETE_FAILED'));

            return false;
        }

        // The wallet returns' committed lines, after the COMMIT; every rollback
        // path above returns before reaching them. Post-commit only when this
        // call owns the transaction: nested in a caller's, transactionStart(true)
        // was a SAVEPOINT and this commit only released it, as with
        // CustomerCreditLedger::write()'s own-transaction line.
        foreach ($walletReturns as $entry) {
            CustomerCreditLedger::logCommitted($entry);
        }

        return true;
    }

    /**
     * Refuse deleting orders while a refund of one of them is not settled (pending or authorized: it
     * reserves its amount, {@see PaymentLedger::orderDeleteRefusal()}): the whole selection is refused and
     * the operator reads why, per order.
     *
     * @param array $pks The order ids about to be deleted.
     *
     * @return bool True when refused (the error is set); false when none stands in the way.
     *
     * @since  2.1.0
     */
    private function refuseDeleteForReservations(array $pks): bool
    {
        $refusals = array_values(array_filter(array_map(
            static fn ($pk): ?string => PaymentLedger::orderDeleteRefusal(orderId: (int) $pk),
            $pks,
        )));

        if ($refusals === []) {
            return false;
        }

        $this->setError(error: implode(' ', $refusals));

        return true;
    }

    /**
     * The document numbers standing in the way of deleting these orders.
     *
     * Drafts count. A draft already holds its id_series and is treated as
     * consuming the series everywhere else (SeriesModel::hasIssuedDocuments),
     * so filtering on status here would let a delete burn a reserved number.
     *
     * @param array $pks The order ids about to be deleted.
     *
     * @return string[] Human-readable document identifiers, empty when clear.
     *
     * @since  2.0.0
     */
    public function ordersWithDocuments(array $pks): array
    {
        if ($pks === []) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_order', 'number_formatted']))
            ->from($db->quoteName('#__alfa_documents'))
            ->whereIn($db->quoteName('id_order'), $pks)
            ->order($db->quoteName('id') . ' ASC');

        $rows = $db->setQuery(query: $query)->loadObjectList() ?: [];

        return array_map(
            static fn (object $row): string => ((string) $row->number_formatted !== ''
                ? (string) $row->number_formatted
                : '#' . (int) $row->id) . ' (order ' . (int) $row->id_order . ')',
            $rows,
        );
    }

    /**
     * Refuse a delete for the documents that stand in its way.
     *
     * The operator's copy renders as HTML, so the frozen document numbers are
     * escaped there.
     *
     * @param string[] $blocked The documents, as ordersWithDocuments() names them.
     * @param int $selected How many orders were selected: a selection is
     *                      refused whole, and the sentence says so.
     *
     *
     * @since  2.0.0
     */
    private function refuseDeleteForDocuments(array $blocked, int $selected): void
    {
        $this->setError(
            error: Text::sprintf(
                $selected > 1 ? 'COM_ALFA_ORDER_ERROR_DELETE_HAS_DOCUMENTS_SELECTION' : 'COM_ALFA_ORDER_ERROR_DELETE_HAS_DOCUMENTS',
                htmlspecialchars(string: implode(separator: ', ', array: $blocked), flags: ENT_QUOTES, encoding: 'UTF-8'),
            ),
        );
    }

    /**
     * The payment and shipment rows a delete's plugins judge, one line per row.
     *
     * A plugin decides on a row's method, type and status, so a change in any
     * of them, or a row that came or went, is a change.
     *
     * @param iterable $payments The order's payment rows.
     * @param iterable $shipments The order's shipment rows.
     *
     * @return string[] Sorted, so two readings of unchanged rows are equal.
     *
     * @since  2.0.0
     */
    private static function pluginRowFacts(iterable $payments, iterable $shipments): array
    {
        $facts = [];

        foreach ($payments as $payment) {
            $facts[] = 'payment ' . (int) $payment->id . ' ' . (string) $payment->payment_method_type
                . ' ' . (string) $payment->payment_type . ' ' . (string) $payment->transaction_status;
        }

        foreach ($shipments as $shipment) {
            $facts[] = 'shipment ' . (int) $shipment->id . ' ' . (string) $shipment->shipment_method_type
                . ' ' . (string) $shipment->status;
        }

        sort($facts);

        return $facts;
    }

    /**
     * The payment and shipment rows of the orders being deleted, read under
     * the orders' lock and locked themselves.
     *
     * The rows are read with a lock of their own, so the reading is the
     * committed one whether or not the row's writer took the order's lock, and
     * a later write to them waits for the delete.
     *
     * @param int[] $pks The order ids, locked by the caller.
     *
     * @return array<int, string[]> Per order id, as pluginRowFacts() states them.
     *
     * @since  2.0.0
     */
    private function lockedPluginRowFacts(array $pks): array
    {
        $db = $this->getDatabase();
        $list = implode(separator: ',', array: array_map('intval', $pks));
        $found = [];

        foreach ($pks as $pk) {
            $found[$pk] = ['payments' => [], 'shipments' => []];
        }

        $payments = $db->setQuery(
            query: 'SELECT ' . implode(separator: ', ', array: $db->quoteName(['id', 'id_order', 'payment_method_type', 'payment_type', 'transaction_status']))
            . ' FROM ' . $db->quoteName('#__alfa_order_payments')
            . ' WHERE ' . $db->quoteName('id_order') . ' IN (' . $list . ')'
            . ' ORDER BY ' . $db->quoteName('id') . ' FOR UPDATE',
        )->loadObjectList() ?: [];

        foreach ($payments as $payment) {
            $found[(int) $payment->id_order]['payments'][] = $payment;
        }

        $shipments = $db->setQuery(
            query: 'SELECT ' . implode(separator: ', ', array: $db->quoteName(['id', 'id_order', 'shipment_method_type', 'status']))
            . ' FROM ' . $db->quoteName('#__alfa_order_shipments')
            . ' WHERE ' . $db->quoteName('id_order') . ' IN (' . $list . ')'
            . ' ORDER BY ' . $db->quoteName('id') . ' FOR UPDATE',
        )->loadObjectList() ?: [];

        foreach ($shipments as $shipment) {
            $found[(int) $shipment->id_order]['shipments'][] = $shipment;
        }

        return array_map(
            static fn (array $rows): array => self::pluginRowFacts(payments: $rows['payments'], shipments: $rows['shipments']),
            $found,
        );
    }

    /**
     * The documents that carry one order line, by number.
     *
     * Drafts count, for the reason ordersWithDocuments() gives: a draft already
     * holds its series.
     *
     * @param int $orderItemId The order line id.
     *
     * @return string[] Human-readable document identifiers, empty when clear.
     *
     * @since  2.0.0
     */
    private function documentsWithLine(int $orderItemId): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('d.id') . ', ' . $db->quoteName('d.number_formatted'))
            ->from($db->quoteName('#__alfa_document_items', 'di'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd'), $db->quoteName('d.id') . ' = ' . $db->quoteName('di.id_document'))
            ->where($db->quoteName('di.id_order_item') . ' = ' . (int) $orderItemId)
            ->order($db->quoteName('d.id') . ' ASC');

        $rows = $db->setQuery(query: $query)->loadObjectList() ?: [];

        return array_map(
            static fn (object $row): string => (string) $row->number_formatted !== ''
                ? (string) $row->number_formatted
                : '#' . (int) $row->id,
            $rows,
        );
    }

    /**
     * Remove all rows belonging to the given orders: items, payments, shipments,
     * activity log, and the guest (id_user = 0) delivery/invoice user_info records.
     * Sales documents are never among them: the caller holds the orders' lock
     * and has refused every order that holds one.
     *
     * @param array $pks The order ids whose related data should be deleted.
     *
     * @return object[] The wallet counter-entries written inside the caller's
     *                  transaction, for it to log as committed after its COMMIT.
     *
     * @since  1.0.0
     */
    protected function deleteRelatedData(array $pks): array
    {
        $db = $this->getDatabase();

        // Per-line children first: their keys are the order item ids, so
        // they must be collected before the lines vanish.
        $lineIdsQuery = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_order_items'))
            ->whereIn('id_order', $pks);
        $lineIds = array_map('intval', $db->setQuery($lineIdsQuery)->loadColumn() ?: []);

        if ($lineIds !== []) {
            foreach (['#__alfa_order_item_fields' => 'id_order_item'] as $table => $column) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName($table))
                        ->whereIn($column, $lineIds),
                )->execute();
            }
        }

        // Order-keyed children: rule/contribution freezes, download
        // entitlements (a deleted order must never keep serving files:
        // serving matches entitlement rows), payment gateway logs.
        foreach ([
            '#__alfa_order_cart_rule',
            '#__alfa_order_contributions',
            '#__alfa_order_downloads',
            '#__alfa_order_shipment_items',
            '#__alfa_payments_standard_logs',
        ] as $table) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->whereIn('id_order', $pks),
            )->execute();
        }

        // Delete order items
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_items'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Wallet money is returned before its record is destroyed
        // Deleting an order deletes its payment rows, and a wallet spend is a
        // payment row: the customer's balance stays debited while the only
        // thing that justified the debit disappears. That is money taken from
        // a customer for an order that no longer exists, not a bookkeeping
        // detail, a balance they can see.
        //
        // WHAT IS RETURNED IS THE NET THE ORDER STILL HOLDS, from the wallet
        // ledger, never a payment row's amount. A spend partly refunded to
        // the wallet already had that part back: returning the whole spend
        // again created money (fund 30, spend 20, refund 5, delete -> 35). And
        // a spend whose row is not settled (pending) still holds the wallet's
        // money, which the settled rows alone never returned. Per customer:
        // the ledger entry, not the order's current customer, says whose
        // money it is, so an order reassigned after the spend repays the
        // person who paid.
        //
        // The caller holds the orders' lock: a refund settling its wallet leg
        // locks them too (PaymentLedger), so it is either in the locking read
        // below or waits for this delete, never returned twice.
        //
        // Gateway money cannot be answered this way (the bank has it, and only
        // a real refund moves it back), so this returns the money the shop
        // itself is holding and says so loudly about the rest.
        $walletReturns = [];

        foreach (CustomerCreditLedger::heldByOrders($pks) as $spentOrderId => $byUser) {
            foreach ($byUser as $userId => $held) {
                try {
                    $walletReturns[] = CustomerCreditLedger::credit(
                        userId:     $userId,
                        amount:     $held->getAmount(),
                        sourceType: CustomerCreditLedger::SOURCE_ORDER,
                        sourceId:   $spentOrderId,
                        note:       'Order #' . $spentOrderId . ' was deleted: wallet money it still held returned.',
                        // JOINS the delete's transaction; it must never open its
                        // own. MySQL's START TRANSACTION implicitly commits the one
                        // already open, so a managed call here committed the
                        // half-finished purge and left the later rollback with
                        // nothing to undo: measured as a customer refunded for an
                        // order that was still there afterwards.
                        manageTransaction: false,
                    );
                } catch (Throwable $e) {
                    // The counter-entry is the whole point; if it cannot be
                    // written the deletion must not quietly proceed as if the
                    // customer had been repaid.
                    Log::add(
                        'CRITICAL: order #' . $spentOrderId . ' deletion could not return the wallet money it held ('
                        . $held->getAmount() . ') to user #' . $userId . ': ' . $e->getMessage(),
                        Log::CRITICAL,
                        'com_alfa.orders',
                    );

                    throw $e;
                }
            }
        }

        // Gateway money that is about to lose its record. Nothing here can
        // give it back, so the only honest act is to leave a trail.
        $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(SUM(' . $db->quoteName('amount') . '), 0)')
                ->from($db->quoteName('#__alfa_order_payments'))
                ->whereIn('id_order', $pks)
                ->where(PaymentLedger::settledPaymentPredicate())
                ->whereNotIn(
                    $db->quoteName('payment_method_type'),
                    PaymentLedger::NON_GATEWAY_TYPES,
                    ParameterType::STRING,
                ),
        );
        $gatewayCollected = (float) $db->loadResult();

        if ($gatewayCollected > 0.0) {
            Log::add(
                'Orders ' . implode(', ', array_map('intval', $pks)) . ' deleted while holding '
                . $gatewayCollected . ' collected at a gateway: those payment records are gone and the'
                . ' money was NOT refunded. Refund at the gateway if that was not intended.',
                Log::CRITICAL,
                'com_alfa.orders',
            );
        }

        // The kept outcomes of the provider refund reports of these orders' payments.
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_refund_report_outcomes'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete payments
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_payments'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete shipments
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_shipments'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete activity log
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_activity_log'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete guest user info
        $ids = implode(',', array_map([$db, 'quote'], $pks));

        $subQuery1 = $db->getQuery(true)
            ->select($db->qn('id_address_delivery'))
            ->from($db->qn('#__alfa_orders'))
            ->where($db->qn('id') . ' IN (' . $ids . ')');

        $subQuery2 = $db->getQuery(true)
            ->select($db->qn('id_address_invoice'))
            ->from($db->qn('#__alfa_orders'))
            ->where($db->qn('id') . ' IN (' . $ids . ')');

        $query = $db->getQuery(true)
            ->delete($db->qn('#__alfa_user_info'))
            ->where('(' . $db->qn('id') . ' IN (' . $subQuery1 . ') OR ' . $db->qn('id') . ' IN (' . $subQuery2 . '))')
            ->where($db->qn('id_user') . ' = 0');

        $db->setQuery($query);
        $db->execute();

        return $walletReturns;
    }

    // PREFILL DATA: For AJAX partial updates
    //
    // When updating an order via AJAX (e.g. status change from list view),
    // only the changed field(s) are sent. But save() needs a COMPLETE
    // record because $table->bind($data) overwrites everything.
    //
    // prefillData() loads the full current row from the Table object,
    // then overlays only the fields you sent. Result: save() gets
    // a complete record and all its logic fires correctly.
    //
    // We use Table (not getItem) because:
    //   - Table is lightweight: 1 query, raw columns only
    //   - getItem loads items, payments, shipments, Money objects, history
    //   - For a status change we just need the orders row, not the universe

    /**
     * Prefill order data for partial updates.
     *
     * Loads the FULL current row from the Table object (1 lightweight query),
     * then overlays the provided $data on top. Fields in $data win;
     * all other fields keep their current DB values.
     *
     * This ensures save() always receives a complete record, so all its
     * logic fires correctly: prepareTable, stock transitions, activity log.
     *
     * @param int $recordKey Order PK
     * @param array $data Partial data to overlay (changed fields only)
     *
     * @return array Complete data array safe for save()
     *
     * @since  1.0.0
     */
    /**
     * Columns the RAW/inline order-update paths may write.
     *
     * The inline list dropdown and any JSON body reach save() without the
     * form pipeline, so nothing else strips columns; before this allowlist a
     * client with order edit rights could write ANY #__alfa_orders column (secure_key,
     * created_by, id_user, ...) through order.updateOrder. The fat admin form
     * keeps its own richer field set; THIS list is only for raw bodies.
     *
     * @since 2.0.0
     */
    public const INLINE_EDITABLE_COLUMNS = ['id_order_status', 'note', 'customer_note'];

    /**
     * The permission that moving an order between statuses requires.
     *
     * Not alfa.orders.edit. Editing an order's notes and its addresses is ordinary
     * order data; moving it between statuses runs stock movements, issues a
     * fiscal document against a gapless series, emails the customer and
     * dispatches onOrderStatusChange to every listening plugin. The orders list
     * has always said so: it renders a read-only badge and not a select when
     * this action is absent (tmpl/orders/default.php:60, 385), but only the
     * screen enforced it, so a request that did not come from that screen was
     * never asked.
     *
     * @since  2.0.0
     */
    public const STATUS_CHANGE_ACTION = 'alfa.orders.edit.state';

    /**
     * Marks a phase-B abort that has already explained itself.
     *
     * Some of phase B answers false instead of throwing. Those cases still
     * have to unwind to the rollback, so they raise this, and the handler
     * knows not to overwrite the error the caller already set, nor to log a
     * fault where there was none.
     *
     * @since  2.0.0
     */
    private const PHASE_B_REFUSED = 'com_alfa.order.phase_b_refused';

    /**
     * Whether the current actor may move an order between statuses.
     *
     * Fails closed: no identity is not permission, so a context without a user
     * (a task, a CLI run) cannot move an order through this model. The paths
     * that legitimately move orders without an operator (the payment and
     * shipment ledgers, the renewals engine) build a Transition directly and
     * never pass through here.
     *
     * @return bool True when the actor holds STATUS_CHANGE_ACTION on com_alfa.
     *
     * @since  2.0.0
     */
    public static function mayChangeStatus(): bool
    {
        $user = Factory::getApplication()->getIdentity();

        // STATUS_CHANGE_ACTION names alfa.orders.edit.state; asked through the one Alfa check.
        return $user !== null && AlfaHelper::can(section: 'orders', action: 'edit.state', user: $user);
    }

    /**
     * The status an order is actually in, read straight from the row.
     *
     * Deliberately not getItem(): the question is one column, and the caller
     * asking it has not been authorised yet.
     *
     * @param int $orderId Order PK.
     *
     * @return int The persisted status id, or 0 when the order does not exist.
     *
     * @since  2.0.0
     */
    public static function persistedStatus(int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('id_order_status'))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $orderId, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Reduce a raw (non-form) order payload to the inline-editable columns.
     *
     * Dropped keys are logged so a tampered request is visible, never silent.
     *
     * @param array $data Raw payload.
     *
     * @return array Only the allowlisted keys.
     *
     * @since 2.0.0
     */
    public static function filterInlineOrderData(array $data): array
    {
        $allowed = array_intersect_key($data, array_flip(self::INLINE_EDITABLE_COLUMNS));
        $dropped = array_diff_key($data, $allowed);

        if ($dropped !== []) {
            Log::add(
                'Inline order update dropped non-allowlisted columns: ' . implode(', ', array_keys($dropped)),
                Log::WARNING,
                'com_alfa.orders',
            );
        }

        return $allowed;
    }

    public function prefillData(int $recordKey, array $data = []): array
    {
        if ($recordKey <= 0) {
            return $data;
        }

        $table = $this->getTable();

        if (!$table->load($recordKey)) {
            return $data;
        }

        // Get all table columns and their current values
        $fields = $table->getFields();

        foreach ($fields as $field) {
            $fieldName = $field->Field;

            // Don't overwrite fields that the caller explicitly set
            if (array_key_exists($fieldName, $data)) {
                continue;
            }

            // Fill from current DB values
            $data[$fieldName] = $table->{$fieldName};
        }

        return $data;
    }

    // Reusable helpers
    //
    // Shared utilities used across payments, shipments, items, and orders.
    // Every method here exists to eliminate duplication.

    /**
     * Get a related admin model (Payment, Shipment, etc.)
     *
     * Wraps the verbose Joomla bootComponent -> getMVCFactory -> createModel chain
     * into a single reusable call.
     *
     * @param string $name Model name: 'Payment', 'Shipment', etc.
     * @since  1.0.0
     */
    protected function getRelatedModel(string $name): AdminModel
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Wrap a raw database value as a Money object
     *
     * Null-safe: treats null/empty as zero.
     *
     * @param mixed $value Raw DB value (string, float, null)
     * @param Currency $currency Currency for the Money object
     * @since  1.0.0
     */
    protected function toMoney(mixed $value, Currency $currency): Money
    {
        return Money::of($value ?? 0, $currency);
    }

    /**
     * The money columns of a row as stored, as exact decimal texts at the order currency's decimal places.
     *
     * Only a stored figure is written so: a refused Save's draft, bound over the row, is shown as it was typed (the
     * popup's field prints the text it is bound with).
     *
     * @param object $row A row as loaded, before its columns become Money.
     * @param string[] $columns The money columns.
     * @param Currency $currency The order's currency, at the scale the order keeps.
     *
     * @return array<string, string> Column => its figure (e.g. '19.40', or '5.004' when finer than the money); a column
     *                               that states none is left out.
     *
     * @since  2.0.0
     */
    private function storedFigures(object $row, array $columns, Currency $currency): array
    {
        $figures = [];

        foreach ($columns as $column) {
            if (isset($row->{$column}) && \is_string($row->{$column})) {
                $figures[$column] = self::figureAtScale(figure: ExactAmount::of($row->{$column})->text(), scale: $currency->getDecimalPlaces());
            }
        }

        return $figures;
    }

    /**
     * A figure with at least the given decimal places, never rounded: zeros are added, no digit is dropped. 19.4 is
     * 19.40 at two places; 5.004 stays 5.004 (finer than the money: shown as it is, for the rule to refuse on Save).
     *
     * @param string $figure A plain decimal in its normal form (ExactAmount::text()).
     * @param int $scale The decimal places.
     *
     * @return string The figure at the scale.
     *
     * @since  2.1.1
     */
    private static function figureAtScale(string $figure, int $scale): string
    {
        $point = strpos($figure, '.');
        $places = $point === false ? 0 : \strlen($figure) - $point - 1;

        if ($places >= $scale) {
            return $figure;
        }

        return ($point === false ? $figure . '.' : $figure) . str_repeat('0', $scale - $places);
    }

    /**
     * Convert an object (with possible Money values) to a flat array for form binding
     *
     * Forms expect scalar values: Money objects cause empty fields. A Money
     * is handed over as its exact decimal text, never a float (a float prints
     * fourteen digits, and a form saves back what it shows); a column the row
     * kept as stored (stored_figures) is handed over as stored.
     *
     * Used by: getPaymentForm(), getShipmentForm(), getOrderItemForm()
     *
     * @param object $data Object from getPaymentData(), getShipmentData(), etc.
     * @return array Flat array safe for Form::bind()
     * @since  1.0.0
     */
    protected function objectToFormData(object $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($key === 'stored_figures') {
                continue;
            }

            if ($value instanceof Money) {
                $result[$key] = ExactAmount::ofMoney($value)->text();
            } elseif (is_object($value)) {
                // Skip nested objects (params, currency, etc.): forms don't bind them
                continue;
            } else {
                $result[$key] = $value;
            }
        }

        foreach ((array) ($data->stored_figures ?? []) as $key => $figure) {
            $result[$key] = $figure;
        }

        return $result;
    }

    /**
     * Save an associative array to a database table
     *
     * Queries actual table columns and strips unknown keys; prevents
     * "Unknown column" errors when form data has extra fields.
     * Uses INSERT for new rows, UPDATE for existing.
     *
     * @param array &$data Form data (by ref, PK is set on insert)
     * @param string $tableName Full table name with #__ prefix
     * @param string $pkColumn Primary key column name
     * @since  1.0.0
     */
    protected function saveDataOnTable(&$data, $tableName, $pkColumn = 'id'): bool
    {
        $db = $this->getDatabase();

        // Filter to actual DB columns; prevents "Unknown column" errors
        $tableColumns = $db->getTableColumns($tableName);
        $filteredData = array_intersect_key($data, $tableColumns);

        if (empty($filteredData)) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_NO_VALID_COLUMNS'), 'error');
            return false;
        }

        $isNew = empty($filteredData[$pkColumn]) || (int) $filteredData[$pkColumn] === 0;

        try {
            if ($isNew) {
                unset($filteredData[$pkColumn]);
                $obj = (object) $filteredData;
                $db->insertObject($tableName, $obj, $pkColumn);
                $data[$pkColumn] = $obj->{$pkColumn} ?? $db->insertid();
            } else {
                $obj = (object) $filteredData;
                $db->updateObject($tableName, $obj, $pkColumn);
                $data[$pkColumn] = $filteredData[$pkColumn];
            }
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    /**
     * Delete a single row from a table by PK
     *
     * @param string $tableName Full table name with #__ prefix
     * @param int $id Row PK value
     * @param string $pkColumn Primary key column name
     * @since  1.0.0
     */
    protected function deleteTableEntry($tableName, $id, $pkColumn = 'id'): bool
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->qn($tableName))
            ->where($db->qn($pkColumn) . ' = ' . intval($id));

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    // ORDER ACTIVITY LOG (Unified Event System)
    //
    // Single table: #__alfa_order_activity_log
    // ONE timeline for everything that happens to an order.
    //
    // Event format: "entity.action"
    //
    // Who writes what (one funnel per event, no double-logging):
    //   order.created         OrderCreator (the placement funnel; admin "new
    //                         order" goes through it too)
    //   order.edited          this model's save diff, NEVER re-records
    //                         id_order_status (Transition owns that)
    //   order.status_changed  Service\Order\Transition, exactly once
    //   item.added/edited/deleted  this model's item CRUD, logged inside the
    //                         item transaction; context carries
    //                         totals_before/totals_after snapshots
    //   payment.added/edited/deleted  PaymentLedger; a refund row lands as
    //                         payment.refunded, never as payment.added
    //   payment.refund_reversed / payment.charged_back / payment.chargeback_reversed
    //                         PaymentLedger's verbs
    //   shipment.added/edited/deleted  shipment CRUD
    //
    // Plugins write (gateway/carrier events):
    //   payment.captured, payment.failed
    //   shipment.tracking_updated, shipment.delivered
    //
    // Plugin usage (public static, callable from anywhere):
    //   OrderModel::logOrderActivity($orderId, 'payment.captured', 'Gateway captured €50', [
    //       'transaction_id' => 'ch_3MqBE2...', 'gateway' => 'mygateway', 'amount' => 50.00
    //   ], $paymentId);
    //
    // Status snapshot on EVERY row: always know the order state
    // when an event happened.

    /**
     * Log an event to the unified order activity log
     *
     * @param int $orderId Order ID
     * @param string $event Dot-notation event: 'order.edited', 'item.added', etc.
     * @param string $summary Human-readable summary
     * @param array|null $context Structured data (changes, metadata, gateway info)
     * @param int|null $entityId Related entity PK (order_items.id, order_payments.id, etc.)
     * @since  1.0.0
     */
    /**
     * Record an activity entry, or fail loudly.
     *
     * The strict sibling of {@see logOrderActivity}: it lets a write failure
     * propagate so a caller whose mutation is still open can roll back. Use
     * it inside a transaction: an item edit that commits without its history
     * entry leaves a changed order nobody can account for. Anywhere the
     * mutation has already happened (a gateway callback, a status change
     * whose side effects ran) use the best-effort method instead: undoing is
     * not on the table there, and throwing would only lose more.
     *
     * @param int $orderId Order PK.
     * @param string $event Dot-notation event.
     * @param string $summary Human-readable summary.
     * @param array|null $context Structured data.
     * @param int|null $entityId Related entity PK.
     * @param int|null $actorId The acting user, stated by the caller: 0 = System;
     *                          null = the current identity (since 2.0.0).
     * @param bool $refuseOversize Refuse a context above ACTIVITY_CONTEXT_MAX_BYTES
     *                             instead of storing it truncated (since 2.0.0).
     *
     *
     * @throws Throwable When the entry cannot be written.
     *
     * @since  2.0.0
     */
    public static function logOrderActivityStrict(
        int $orderId,
        string $event,
        string $summary,
        ?array $context = null,
        ?int $entityId = null,
        ?int $actorId = null,
        bool $refuseOversize = false,
    ): void {
        $failure = null;

        self::writeActivity($orderId, $event, $summary, $context, $entityId, $failure, actorId: $actorId, refuseOversize: $refuseOversize);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * The most context an activity row stores: the column is TEXT (64 KB).
     *
     * @since  2.0.0
     */
    private const ACTIVITY_CONTEXT_MAX_BYTES = 60000;

    /**
     * Write one activity row; a failure is handed back in $failure, never thrown.
     *
     * @param int $orderId Order PK.
     * @param string $event Dot-notation event.
     * @param string $summary Human-readable summary.
     * @param array|null $context Structured data.
     * @param int|null $entityId Related entity PK.
     * @param Throwable|null $failure Set to what stopped the write.
     * @param int|null $actorId The acting user; 0 = System; null = the current identity.
     * @param bool $refuseOversize Refuse an oversize context instead of truncating it.
     *
     * @return int|null The new row's id; null when nothing was written (since 2.1.1).
     *
     * @since  2.0.0
     */
    private static function writeActivity(
        int $orderId,
        string $event,
        string $summary,
        ?array $context,
        ?int $entityId,
        ?Throwable &$failure,
        ?int $actorId = null,
        bool $refuseOversize = false,
    ): ?int {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $user = null;
            $actorName = 'System';

            if ($actorId === null) {
                // The identity is decoration on an audit row: a context without an
                // application (CLI, a task run) must not cost the record, still
                // less roll back the mutation it belongs to.
                try {
                    $user = Factory::getApplication()->getIdentity();
                } catch (Throwable $e) {
                    $user = null;
                }
            } elseif ($actorId !== 0) {
                // An actor the caller states is the record's author
                // and it must be a user.
                $actor = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($actorId);

                if ((int) $actor->id !== $actorId) {
                    throw new RuntimeException("activity actor #{$actorId} is not a user.");
                }

                $actorName = (string) $actor->name;
            }

            // Get current order status for snapshot. The status name is resolved
            // in the active language from the per-language tables (aliased `name`).
            $statusQuery = $db->getQuery(true)
                ->select('o.id_order_status')
                ->from('#__alfa_orders AS o')
                ->join('LEFT', '#__alfa_orders_statuses AS s ON s.id = o.id_order_status')
                ->where('o.id = ' . intval($orderId));

            MultilingualHelper::addMultilingualJoinToQuery(
                query:             $statusQuery,
                mainAlias:         's',
                mainPrimaryColumn: 'id',
                langTableBase:     '#__alfa_orders_statuses',
                langPrimaryColumn: 'id_orderstatus',
                fields:            ['name'],
            );

            $db->setQuery($statusQuery);
            $status = $db->loadObject();

            $log = new stdClass();
            $log->id_order = $orderId;
            $log->id_employee = $actorId ?? ($user->id ?? 0);
            // Every snapshot column is bounded; a long customer name, status
            // name or event must never be the reason an order cannot be
            // saved. summary is cut below.
            // No identity (a guest placing, a CLI run): the event's own actor,
            // so a guest's placement reads as the customer, not "System".
            $log->employee_name = mb_substr(
                $actorId !== null ? $actorName : (string) ($user->name ?? ($context['actor_name'] ?? 'System')),
                0,
                100,
            );
            $log->event = mb_substr($event, 0, 64);
            $log->id_order_status = $status->id_order_status ?? null;
            $log->status_name = mb_substr((string) ($status->name ?? ''), 0, 100);
            $log->entity_id = $entityId;
            $log->summary = mb_substr($summary, 0, 500);
            // context is TEXT (64KB). An unusually large payload must not be
            // the reason a mutation rolls back, so it is bounded here and the
            // truncation is recorded rather than hidden.
            // INVALID_UTF8_SUBSTITUTE: a gateway payload with a stray byte
            // would otherwise make json_encode return false and store an empty
            // context: the structured half of the record lost without a word.
            $encoded = $context
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;

            if ($encoded === false) {
                $encoded = json_encode(['unencodable' => true, 'keys' => array_slice(array_keys($context), 0, 50)]);
            }

            if ($encoded !== null && $encoded !== false && strlen($encoded) > self::ACTIVITY_CONTEXT_MAX_BYTES) {
                // A record that must survive whole is refused, not truncated:
                // nothing is stored, and a strict caller rolls back with it.
                if ($refuseOversize) {
                    throw new RuntimeException(
                        "the activity record {$event} is " . \strlen($encoded) . ' bytes; at most '
                        . self::ACTIVITY_CONTEXT_MAX_BYTES . ' are stored, so it is refused rather than truncated.',
                    );
                }

                $encoded = json_encode([
                    'truncated' => true,
                    'original_bytes' => strlen($encoded),
                    'keys' => array_slice(array_keys($context), 0, 50),
                ], JSON_UNESCAPED_UNICODE);
            }

            $log->context = $encoded;
            $log->created = Factory::getDate('now', 'UTC')->toSql();

            $db->insertObject('#__alfa_order_activity_log', $log, 'id');

            return (int) $log->id;
        } catch (Throwable $e) {
            $failure = $e;

            return null;
        }
    }

    /**
     * Record an activity entry; never break the operation that caused it.
     *
     * The mutation this describes has already happened: a gateway callback,
     * a status change whose side effects ran, stock that already moved, so
     * the write cannot be undone and must not throw. A missing audit record
     * is still never a warning: it is logged CRITICAL and raised as a backend
     * notification, because a timeline with a hole in it is a thing an
     * operator has to know about. The one failure that does reach the caller
     * is one that ended the caller's transaction (a deadlock): its work is gone
     * with it and must not go on writing outside any transaction (since 2.1.1).
     *
     * Callers whose mutation is still open (inside a transaction) must use
     * {@see logOrderActivityStrict} instead, so the mutation rolls back with
     * its record.
     *
     * @param int $orderId Order PK.
     * @param string $event Dot-notation event.
     * @param string $summary Human-readable summary.
     * @param array|null $context Structured data.
     * @param int|null $entityId Related entity PK.
     *
     * @return int|null The record's id; null when it could not be written (since 2.1.1).
     *
     * @since  1.0.0
     */
    public static function logOrderActivity(
        int $orderId,
        string $event,
        string $summary,
        ?array $context = null,
        ?int $entityId = null,
    ): ?int {
        $failure = null;

        $recordId = self::writeActivity($orderId, $event, $summary, $context, $entityId, $failure);

        if ($failure === null) {
            self::retractActivityAlert($orderId);

            return $recordId;
        }

        if (TransactionState::endedBy(db: Factory::getContainer()->get('DatabaseDriver'), failure: $failure)) {
            throw $failure;
        }

        Log::add(
            'Activity log failed for order ' . $orderId . ' (' . $event . '): ' . $failure->getMessage(),
            Log::CRITICAL,
            'com_alfa.orders',
        );

        try {
            NotificationHelper::push(
                'orders.activitylog.' . $orderId,
                'Order #' . $orderId . ': an activity record could not be written',
                [
                    'severity' => 'danger',
                    'group' => 'Orders',
                    'message' => 'The order changed but its history entry was not saved, so the timeline is incomplete.',
                    'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                    // Seen by who may view orders; linked only for who may open the edit screen.
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            // The notifier is itself best-effort; the CRITICAL above stands.
        }

        return null;
    }

    /**
     * Retract the alert {@see logOrderActivity} raises when a record is lost.
     *
     * A written record is the proof the log is writable again, so the alert
     * must not outlive the condition that raised it: the same retraction
     * contract every other producer follows. The `exists()` probe keeps the
     * ordinary path (nothing was ever raised) at one point read on the
     * dedup_key index instead of a write on every activity row, and the
     * per-request memo keeps a mutation that writes several records from
     * repeating even that.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since  1.0.0
     */
    private static function retractActivityAlert(int $orderId): void
    {
        static $reconciled = [];

        if (isset($reconciled[$orderId])) {
            return;
        }

        $reconciled[$orderId] = true;
        $dedupKey = 'orders.activitylog.' . $orderId;

        try {
            if (NotificationHelper::exists(dedupKey: $dedupKey)) {
                NotificationHelper::clear(dedupKey: $dedupKey);
            }
        } catch (Throwable $e) {
            // Retraction is best-effort: the record is written, which is the
            // half an operator cannot recover later.
        }
    }

    /**
     * Get activity log entries for an order, newest first.
     *
     * `created` holds whole seconds, so entries of one second are ordered by
     * their id, the order they were written in (since 2.1.0); before, the
     * database returned them in any order.
     *
     * @param int $orderId Order ID
     * @param string $eventFilter Filter by event prefix: 'item', 'payment', 'status', '' = all
     * @param int $limit Max entries
     * @since  1.0.0
     */
    public function getOrderActivityLog(int $orderId, string $eventFilter = '', int $limit = 100): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_activity_log')
            ->where('id_order = ' . intval($orderId))
            ->order(['created DESC', 'id DESC']);

        if (!empty($eventFilter)) {
            $query->where('event LIKE ' . $db->quote($eventFilter . '.%'));
        }

        $query->setLimit($limit);
        $db->setQuery($query);

        try {
            return $db->loadObjectList() ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Populate the model state, additionally seeding the record id from the
     * `id_order` request variable when present.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function populateState()
    {
        parent::populateState();

        $input = Factory::getApplication()->getInput();
        $id_order = $input->get('id_order', null, 'STRING');

        if ($id_order !== null) {
            $this->setState($this->getName() . '.id', $id_order);
        }
    }
}
