<?php

/**
 *  STANDARD PAYMENT PLUGIN: Reference Implementation
 *
 * This file is the REFERENCE IMPLEMENTATION for all Alfa payment plugins.
 * Copy this file as a starting point when building a new plugin (a card
 * gateway, a wallet provider, etc.). Every method, constant, and pattern used
 * here is explained in detail.
 *
 *  What this plugin does
 *
 * The Standard plugin handles offline payments: bank transfer, cash on
 * delivery, cheque, manual invoice. There is no external gateway API:
 * the admin manually manages the payment lifecycle through action buttons.
 *
 *  Plugin anatomy: files & structure
 *
 * plugins/alfa-payments/standard/
 * |-- standard.xml                     <- Manifest (group + params + language)
 * |-- src/Extension/Standard.php       <- THIS FILE (main plugin class)
 * |-- params/
 * |   |-- params.xml                   <- Method config; status_on_* automation
 * |   `-- logs.xml                     <- Plugin-specific log table schema
 * |-- tmpl/                            <- Layout templates
 * |   |-- default_item_view.php        <- Product page (payment info)
 * |   |-- default_cart_view.php        <- Cart page (method selector)
 * |   |-- default_order_completed.php  <- Thank-you page
 * |   |-- action_view_details.php      <- Admin: payment details modal
 * |   |-- action_refund_partial.php    <- Admin: partial-refund amount form
 * |   `-- default_order_logs_view.php  <- Admin: plugin logs modal
 * `-- services/provider.php            <- Joomla DI service provider
 *
 * Small JS a layout needs lives OUTSIDE the plugin folder, in
 * media/plg_alfa-payments_standard/ (js/ + joomla.asset.json); the layout
 * loads it through the Web Asset Manager (see action_refund_partial.php).
 *
 * Standard has NO order-process layout: it is offline, so
 * onOrderProcessView() sets nothing and the view falls through to the
 * complete page. A gateway plugin adds its own there.
 *
 * Language files are packaged under language/en-GB/ in the plugin's own
 * ZIP but INSTALL to administrator/language/en-GB/, which is where they
 * live on a running site:
 *   administrator/language/en-GB/plg_alfa-payments_standard.ini
 *   administrator/language/en-GB/plg_alfa-payments_standard.sys.ini
 * Note the HYPHEN: the file name is plg_<group>_<element>, and the
 * group is "alfa-payments". The Text:: KEYS drop it
 * (PLG_ALFA_PAYMENTS_STANDARD_*); the FILE name keeps it.
 *
 *  Inheritance chain
 *
 * Standard extends PaymentsPlugin extends Plugin extends CMSPlugin
 *
 *   CMSPlugin (Joomla)
 *     `- Plugin (Alfa base)
 *          - Logging system: log(), loadLogs(), deleteLog(), getLogsSchema()
 *          - Auto-creates plugin-specific log table from logs.xml
 *          - Abstract hooks: onCartView(), onItemView()
 *
 *        `- PaymentsPlugin (Alfa payments base)
 *              - Fluent builder wrappers: payment(), paymentUpdate()
 *              - Read/delete wrappers: getPayment(), getPaymentsByOrder(),
 *                deletePayment()
 *              - Those wrappers return a PaymentLedger builder; every WRITE
 *                goes through $this->..., never PaymentLedger::.... Two named
 *                one exception: the ledger's static balance API
 *                (PaymentLedger::refundableRemainder() & co.) serves the
 *                same figures its own money guards enforce (see
 *                handleRefundPartialForm()).
 *                The SETTLE is $this->settleAttempt($builder, ...), never
 *                PaymentLedger::updateIfStatus() by hand. It takes the
 *                builder with ->expectStatus() armed, returns the
 *                WRITE_OK / WRITE_RACED / WRITE_FAILED verdict so each
 *                transport maps its own answer, and writes the CRITICAL
 *                "the money moved and the books did not" line for you.
 *              - Sets logIdentifierField = 'id_order_payment'
 *              - Abstract hooks: onOrderProcessView(), onOrderCompleteView()
 *              - onGetActions() is empty; onExecuteAction() defaults to
 *                setError('Unknown action'); override BOTH
 *
 *           `- Standard (THIS: concrete implementation)
 *                 - Implements all abstract hooks
 *                 - Registers admin action buttons (Mark Paid, Cancel, Refund)
 *                 - Handles action execution with fluent builder
 *                 - Writes structured plugin logs
 *                 - Draws its setup checkup (onPaymentCheckup)
 *                 - Implements NO webhook: it is offline (see the WEBHOOK
 *                   TUTORIAL for what a real gateway writes)
 *
 *  THE EVENT SUBJECT: getSubject() and its typed aliases
 *
 * Every Alfa event carries ONE main object under the fixed argument
 * key "subject": whatever the hook is about at that moment. The base
 * event class exposes it untyped:
 *
 *     $event->getSubject()   -> the event's main object
 *
 * Because "the main object" means something different in every hook,
 * each event also declares a DOMAIN-NAMED ALIAS returning the very same
 * thing (the body is literally `return $this->getSubject();`):
 *
 *   ItemViewEvent            getItem()   -> product being viewed
 *   CartViewEvent            getRequest()  -> MethodViewRequest for the method being rendered
 *   OrderBeforePlaceEvent    getContext()  -> PlacementEventContext of the cart becoming an order
 *   OrderAfterPlaceEvent     getOrder()  -> the committed order
 *   OrderProcessViewEvent    getOrder()  -> order awaiting payment
 *   OrderCompleteViewEvent   getOrder()  -> order on the thank-you page
 *   PaymentResponseEvent     getOrder()  -> order the buyer returned for
 *   AdminOrderDeleteEvent    getOrder()  -> order being deleted in the admin
 *
 * BOTH work. ALWAYS PREFER THE ALIAS: it names what you actually got,
 * so the hook reads correctly and an IDE can follow the type.
 *
 * Not every event has a subject. The action events extend Joomla's
 * AbstractImmutableEvent directly and carry named arguments instead:
 * GetPaymentActionsEvent and ExecutePaymentActionEvent expose getPayment()
 * and getOrder() as separate arguments and have NO getSubject() at all
 * (calling it is a fatal "undefined method"). Their getOrder() therefore
 * is not an alias; it is that event's own 'order' argument.
 *
 * Writing your own event: never name an accessor after an argument KEY.
 * Joomla's AbstractEvent routes get<Key>()/set<Key>() through
 * getArgument()/setArgument() as deprecated value hooks. Aliasing the
 * subject as getOrder() is safe precisely because no argument is
 * called "order"; the key is always "subject".
 *
 *  SURFACES & LAYOUTS: where your HTML actually lands
 *
 * A payment plugin NEVER echoes HTML. It names a template and hands
 * over the data; the caller renders it:
 *
 *     $event->setLayout('default_cart_view');        // tmpl/<name>.php
 *     $event->setLayoutData(['method' => ..., 'item' => ...]);
 *
 * The file goes through Joomla's plugin layout chain, so a site
 * template can override any of them without touching the plugin:
 *
 *   templates/<template>/html/plg_alfa-payments_<element>/<layout>.php
 *   `- parent template (child templates inherit)
 *      `- plugins/alfa-payments/<element>/tmpl/<layout>.php   <- yours
 *
 * WHERE EACH LAYOUT APPEARS
 *   onItemView()           product page, payment-methods area
 *   onCartView()           cart / checkout, inside the method selector
 *   onOrderProcessView()   the processing page after checkout
 *   onOrderCompleteView()  the thank-you page
 *   onExecuteAction()      the action page (view=action), in a modal
 *   onPaymentResponse()    NOTHING: redirect-only, no view
 *   onPaymentWebhook()     NOTHING: response-only (raw body + code, no HTML;
 *                          gateways only, Standard has none)
 *
 * SURFACES (product page only). Beyond its default slot the item hook
 * can push into shared page slots, so a finance badge can sit beside
 * the title instead of down in the methods list:
 *
 *     $event->paymentMethods()->setLayout('x')->setData([...]);  // default
 *     $event->title()->setLayout('badge')->setData([...]);       // slot
 *     $event->nearAddToCart()->setLayout('badge')->setData([...]);
 *
 * ADMIN ACTION LAYOUTS are not rendered inline. The button opens
 * index.php?option=com_alfa&view=action&tmpl=component... in an iframe
 * modal; that page dispatches onExecuteAction ONCE and echoes whatever
 * layout you set, so your layout is a real page and may load its own
 * scripts and styles.
 *
 *  PAYMENT LIFECYCLE: State Machine
 *
 *   PENDING --(Mark as Paid)--> COMPLETED --(Refund)--> REFUNDED
 *   PENDING --(Cancel)--> CANCELLED
 *
 * Valid transitions for Standard (offline):
 *   pending   -> completed  (handleMarkPaid)
 *   pending   -> cancelled  (handleCancel)
 *   completed -> refunded   (handleRefund)
 *   completed -> completed  (handleRefundPartial: original stays; audit row only)
 *
 * Gateway plugins may have additional transitions:
 *   pending   -> authorized -> completed -> refunded
 *   pending   -> failed
 *   authorized -> cancelled (void before capture)
 *
 *  STATUS AUTOMATION: how a payment row moves the ORDER
 *
 * The diagram above is the PAYMENT ROW's state machine. The ORDER has its
 * own status, and the two are connected by a map the MERCHANT configures
 * per method. You never call it; you declare it and then write rows.
 *
 * 1. DECLARE the fields (params/params.xml)
 *
 *   <field name="status_on_completed" type="model" model="orderstatuses"
 *          column_value="id" column_text="name" orderBy="ordering"
 *          label="..." description="...">
 *       <option value="">PLG_..._STATUS_NO_CHANGE</option>
 *   </field>
 *
 * Declare one field per phase YOUR gateway can actually reach. What the
 * shipped plugins declare, and why they differ:
 *
 *   standard   completed, cancelled, refunded          (offline: no auth step,
 *                                                       nothing can 'fail')
 *   gateway A  completed, failed, cancelled, refunded  (sale-only gateway)
 *   gateway B  completed, authorized, cancelled,       (adds pre-auth:
 *              failed, refunded                         authorize->capture)
 *
 * The name is always status_on_<transaction_status>, matching the builder
 * setter that produces it: ->completed() looks up status_on_completed.
 * There is deliberately no status_on_pending in any plugin: create() never
 * automates (see the guards below), so the only way to reach it would be an
 * update BACK to pending, which no lifecycle does.
 *
 * `type="model"` fills the dropdown from the order-status table, so the
 * merchant picks a real status id per METHOD; two methods of the same
 * plugin can drive the order differently. The empty <option> is "no
 * change", and it must exist: a merchant who wants a phase to move nothing
 * needs a way to say so.
 *
 * DECLARE NOTHING AND NOTHING AUTOMATES. A gateway that ships no
 * status_on_* fields never moves an order: the lookup finds no target and
 * returns. That is a supported choice, not a broken plugin.
 *
 * 2. WRITE rows. That is your entire part.
 *
 *   $this->payment($order)->amount($due)->pending()->save();      // open
 *   $this->paymentUpdate($id)->completed()->save();               // settle
 *
 * 3. The params are never passed; they are looked up
 *
 * This is the part that surprises people. You do not hand the map to
 * anything. The ONLY link you create is the method id that PaymentLedger
 * ::for() copies from $order->id_payment_method onto the row you write.
 *
 * When an UPDATE changes transaction_status, the ledger:
 *   1. loads the row's pre-update snapshot (it carries id_payment_method)
 *   2. SELECTs that method's `params` from #__alfa_payments
 *   3. reads $params['status_on_' . $newStatus]
 *   4. runs Transition::for($orderId)->to($target)->byPlugin($type)->save()
 *
 * Transition then owns the fan-out: stock, download entitlements, the
 * onOrderStatusChange event, the status email. Never call Transition
 * yourself for this: one funnel means the admin's "Mark as paid", your
 * webhook and a subscription renewal all behave identically.
 *
 * Looking the map up instead of passing it is deliberate:
 *   - your webhook arrives in a DIFFERENT REQUEST with no session, no cart
 *     and no plugin state, only a payment reference
 *   - the merchant may change the map after your row exists; the read
 *     happens at transition time, so the current setting wins
 *
 * 4. Four guards you cannot override
 *
 * CREATE never automates. Only a status CHANGE does, so opening a pending
 * attempt does not move the order.
 *
 * BOOKKEEPING WRITES SKIP IT. Superseding an abandoned attempt lands the
 * row on 'cancelled' through the bookkeeping seam, so status_on_cancelled
 * does NOT fire; otherwise a buyer paying on another tab would be emailed
 * a cancellation, and their stock and download entitlements revoked. The
 * write, the diff and the activity entry still happen; the attempt stays
 * auditable, it simply is not an event.
 *
 * AUTOMATION NEVER LEAVES THE CANCELLED FAMILY. Transition refuses that
 * without force(), which only a conscious admin act passes. Reinstating a
 * cancelled order is a human decision.
 *
 * ONLY MONEY TAKEN IN SPEAKS FOR THE ORDER. A refund, reversal or chargeback
 * row records money going back, so its status change never grants files,
 * retries a document or moves the order. A write that changes what the row
 * is (a payment saved as a refund, or the reverse) corrects the record and
 * does not act for the order either (PaymentLedger::takesMoneyIn()).
 *
 * WHERE THIS LIVES IN CORE (read it if you are debugging)
 *
 *   PaymentLedger::for()                 stamps id_payment_method
 *   PaymentLedger::update()              detects the status change
 *   PaymentLedger::applyStatusAutomation() the SELECT + the map read
 *   Transition::save()                   the order move and its fan-out
 *
 * Shipment plugins work identically through ShipmentLedger, with the
 * phases status_on_shipped / _delivered / _returned / _cancelled.
 *
 *  REFUND STRATEGY: full and partial are ONE VERB
 *
 * FULL REFUND: call the ledger's verb. Do NOT hand-roll it.
 *
 *   $result = $this->refundPayment((int) $payment->id, null, [
 *       'reason' => Text::sprintf('..._LOG_REFUNDED', $payment->id),
 *   ]);
 *
 *   if ($result['outcome'] !== self::OUTCOME_OK) { ...$result['reason']... }
 *
 *         null = the refundable REMAINDER, recomputed inside the ledger's
 *         own lock; after a partial refund that is not the gross.
 *
 *         The verb does both halves: it writes the linked audit row
 *         (payment_type = 'refund', never counted in total_paid_real) under
 *         the order's lock, then flips the original to 'refunded' through a
 *         COMPARE-AND-SET once its settled refunds cover it. A refused row
 *         changes nothing.
 *
 *         THIS USED TO BE DOCUMENTED AS TWO HAND-WRITTEN STEPS, and that is
 *         why the note is here. A hand-rolled step 1 has no expected status,
 *         so two admins clicking Refund both pass it; the loser's audit row
 *         is then refused over the linked cap and its compensation flips the
 *         original back to 'completed' ON TOP OF the winner's settled refund
 *         so the order counts the money as collected and returned at once.
 *         Measured 6 rounds out of 6. The ledger contract makes PaymentLedger the single
 *         writer with compare-and-set transitions; refundPayment() IS that
 *         contract, and every gateway plugin should reach it through
 *         PaymentsPlugin::refundPayment().
 *
 *         GATEWAY PLUGINS (a provider moves the money): never this
 *         verb to start a refund. Reserve first with reserveRefund() (the
 *         core decides it under the order's lock and keeps the amount
 *         reserved), call the provider with no lock held, then report its
 *         answer: settleRefund(), holdRefund() or releaseRefund().
 *
 * PARTIAL REFUND (this method moves no provider money): the same verb
 *         with the amount, refundPayment($id, $partial, [...]). It keeps the
 *         original 'completed' while the refunds do not cover it (so
 *         total_paid_real does not drop the whole payment over a refund of
 *         a few euros and status_on_refunded does not fire across the
 *         order), and decides the start under the order's lock with every
 *         reservation counted. The payment builder
 *         ($this->payment($order)->refund()->...->save()) is a booking door
 *         only: never use it to start a refund.
 *
 * The LIVE implementation is handleRefundPartialForm() / handleRefundPartial(),
 * right after handleRefund(); the PARTIAL REFUND block there keeps the
 * design rationale.
 *
 *  PARTIAL PAYMENTS: deposit now, balance later
 *
 * An order is not limited to one payment row: #__alfa_order_payments
 * has no unique key on id_order and the paid figure is a SUM, so a
 * deposit and its balance are simply two rows.
 *
 *   $this->payment($order)->completed()->amount(50.00)
 *       ->note('Deposit')->save();        // at placement
 *
 *   $this->payment($order)->completed()->amount(70.00)
 *       ->note('Balance')->save();        // when the rest arrives
 *
 * Without ->amount() the builder resolves the FULL order total, so the
 * override is the ONLY thing that makes a payment partial; there is no
 * partial flag on a payment (refund_type applies to refunds alone).
 *
 * SPLITTING INTO N INSTALMENTS: never divide by hand. The order total is
 * a Money object, and Money::allocate(int $n) splits it into n parts that
 * SUM BACK EXACTLY, distributing the leftover cents one at a time:
 *
 *   $parts = $order->total_paid_tax_incl->allocate(3);
 *   // 10.00 -> [3.34, 3.33, 3.33]   - not 3x3.33, which loses a cent
 *
 *   foreach ($parts as $i => $part) {
 *       $id = $this->payment($order)
 *           ->pending()
 *           ->amount($part->getAmount())        // ->amount() takes a float
 *           ->note(sprintf('Instalment %d/%d', $i + 1, count($parts)))
 *           ->save();
 *   }
 *
 * Divide the total by 3 in PHP and the three rows sum to 9.99, so the
 * order can never reach "paid". allocate() is what stops that.
 *
 * For UNEVEN splits (a 30% deposit and the rest), use the ratio form,
 * which has the same no-cents-lost guarantee:
 *
 *   [$deposit, $balance] = $order->total_paid_tax_incl->allocateByRatios([30, 70]);
 *
 * ->amount() is typed float, so unwrap with ->getAmount(). Passing the
 * Money object itself is a TypeError.
 *
 * The order-level picture is DERIVED, never stored on a payment:
 *
 *   total_paid_real     SUM(amount) of completed rows of type 'payment'
 *   payment_status      unpaid (nothing collected) -> partial (the balance at
 *                       the ORDER CURRENCY'S scale is still positive) -> paid
 *   payment_percentage  paid / the normalized order total x 100
 *
 * So nothing has to be told about a deposit: add the row and the order
 * moves to "partial" by itself. Only pending rows are the exception:
 * they count for nothing until flipped to ->completed().
 *
 * Always create a row PENDING and then UPDATE it to completed:
 *
 *   $id = $this->payment($order)->pending()->amount(50)->save();
 *   $this->paymentUpdate($id)->completed()->save();      // <- side effects here
 *
 * The side effects of "completed" (download entitlements and the
 * method's status_on_completed order transition) fire on the UPDATE path
 * only. $this->payment($order)->completed()->save() inserts a completed
 * row that counts in the totals but grants nothing and moves no order.
 *
 * The outstanding figure ("Balance due") is computed for display in the
 * order edit Totals card and nowhere else; no model or service exposes
 * it, so a hook that needs the balance must compute it itself.
 *
 * A worked example lives in the PARTIAL PAYMENT block right after
 * onOrderAfterPlace().
 *
 *  AMOUNT RESOLUTION: How the payment amount is determined
 *
 * When creating a payment with the fluent builder, the amount is
 * auto-resolved if you don't call ->amount($x) explicitly:
 *
 *   Priority 1: $order->total_paid_tax_incl (Money object, always correct)
 *     - During checkout: set by OrderPlacement from the placement cart
 *     - During admin/API: computed by OrderModel::getItem() from DB
 *
 *   Priority 2: OrderTotalHelper::getOrderTotal() (recomputes from DB)
 *     - Defensive fallback if total_paid_tax_incl is missing
 *
 *   Priority 3: ->amount($x) manual override (always wins when called)
 *     - Use for: partial payments, split payments, specific refund amounts
 *
 * In this Standard plugin, onOrderAfterPlace() does NOT call ->amount()
 * because the auto-resolution handles it correctly.
 *
 * Units are MAJOR (euros, not cents) throughout, and ->amount() is
 * typed float; handing it a Money object is a TypeError. Unwrap first:
 * ->amount($order->total_paid_tax_incl->getAmount()).
 *
 *  FLUENT BUILDER API: Quick Reference
 *
 * CREATE (new payment record):
 *   $id = $this->payment($order)->pending()->save();
 *   $id = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
 *   $id = $this->payment($order)->refund()->amount(50)->completed()->save();
 *   $id = $this->payment($order)->completed()->amount(50)->note('Deposit')->save();
 *
 * UPDATE (modify existing):
 *   $this->paymentUpdate($id)->completed()->processedAt($now)->save();
 *   $this->paymentUpdate($id)->cancelled()->save();
 *   $this->paymentUpdate($id)->refunded()->save();
 *
 * READ (no builder needed):
 *   $payment  = $this->getPayment($id);         // with method params
 *   $payments = $this->getPaymentsByOrder($orderId);  // lightweight list
 *
 * DELETE (no builder needed):
 *   $this->deletePayment($id, $orderId);
 *
 *  BUILDER SETTERS: Full List
 *
 * Status:   ->pending(), ->authorized(), ->completed(),
 *           ->failed(), ->cancelled(), ->refunded()
 *
 * Type:     ->refund(), ->authorization()
 *           (default is 'payment'; no setter needed)
 *
 * Amount:   ->amount(float), ->itemsOnly()
 *
 * Gateway:  ->transactionId(string), ->gatewayResponse(string),
 *           ->processedAt(string)
 *
 * Refund:   ->refundedPayment(int), ->fullRefund(), ->partialRefund(),
 *           ->refundType(string), ->refundReason(string),
 *           ->document(int)  - the credit note this refund settles
 *
 * Other:    ->note(string), ->method(int), ->set(key, value)
 *           (->note() labels the movement: 'Deposit 30%', 'Balance')
 *
 * Terminal: ->save() -> int|false (payment ID or false)
 * Debug:    ->toArray() -> array (preview without saving)
 *
 *  LOGGING SYSTEM: Plugin-Specific Logs
 *
 * Each plugin has its own log table, defined by params/logs.xml:
 *   #__alfa_payments_standard_logs   (this plugin)
 *   #__alfa_payments_mygateway_logs  (a gateway plugin)
 *
 * The table is AUTO-CREATED on first use. Mandatory columns (created
 * by Plugin.php, NOT declared in logs.xml):
 *   id                int AUTO_INCREMENT PRIMARY KEY
 *   id_order          int NOT NULL
 *   id_order_payment  int DEFAULT NULL  (from logIdentifierField)
 *
 * Plugin-specific columns (from logs.xml):
 *   action, transaction_status, amount, order_total, currency,
 *   transaction_id, refund_type, note, created_on, created_by
 *
 * Writing logs:
 *   $logId = $this->log([
 *       'id_order'           => $order->id,
 *       'id_order_payment'   => $payment->id,
 *       'action'             => 'mark_paid',
 *       'transaction_status' => 'completed',
 *       ...
 *   ]);
 *
 * Reading logs:
 *   $logs = $this->loadLogs($orderId);                     // all for order
 *   $logs = $this->loadLogs($orderId, $paymentId);         // for specific payment
 *   $logs = $this->loadLogs($orderId, 0, ['action' => 'refund']); // filtered
 *
 * Log schema (for view templates):
 *   $xml = $this->getLogsSchema(); // parsed logs.xml SimpleXMLElement
 *
 * NOTE: Plugin logs are SEPARATE from the unified order activity log
 * (order_activity_log table). The activity log is written for you by the
 * builder's ->save() (and by deletePayment()); you never write to it
 * from your plugin. Plugin logs are for gateway-specific
 * debugging data that doesn't belong in the main activity timeline.
 *
 *  ADMIN ACTIONS: Button Registration + Execution
 *
 * Action buttons appear in the order edit view next to each payment.
 * Two hooks control them:
 *
 * 1. onGetActions($event): REGISTER buttons based on current status.
 *    Called when rendering the payments list. Use the fluent API:
 *
 *      $event->add('action_name', 'Button Label')
 *          ->icon('icon-name')       // Joomla icon class (without 'icon-')
 *          ->css('btn-success')      // Bootstrap button class
 *          ->confirm('Are you sure?') // Optional confirmation dialog
 *          ->modal('layout_name')                // marks it a UI action;
 *                                                // the popup title is the
 *                                                // button label, always
 *          ->priority(200);          // Higher = more prominent
 *
 * 2. onExecuteAction($event): HANDLE button click.
 *    Called when admin clicks a button. Route via match():
 *
 *      match ($event->getAction()) {
 *          'mark_paid' => $this->handleMarkPaid($event),
 *          'refund'    => $this->handleRefund($event),
 *          default     => $event->setError('Unknown action'),
 *      };
 *
 *    Available on $event:
 *      $event->getPayment()  -> payment record (with Money objects)
 *      $event->getOrder()    -> full order object
 *      $event->getAction()   -> action name string
 *      $event->setMessage()  -> success message for admin
 *      $event->setError()    -> error message
 *      $event->setRefresh()  -> reload the page after action
 *      $event->setLayout()   -> render a template (for modal actions)
 *      $event->setLayoutData() -> data for the template
 *
 *  CREATING YOUR OWN PLUGIN: Step by Step
 *
 * 1. Copy the entire plugins/alfa-payments/standard/ directory
 * 2. Rename to plugins/alfa-payments/yourname/
 * 3. Update namespace: Alfa\Plugin\AlfaPayments\YourName\Extension
 * 4. Rename class: final class YourName extends PaymentsPlugin
 * 5. Update services/provider.php with new class reference
 * 6. Update language files and XML manifest
 *
 * 6b. Declare your status_on_* fields in params/params.xml, one per phase
 *     your gateway can reach. Skip this and your plugin will take money
 *     without ever moving the order status. See STATUS AUTOMATION above.
 *
 * 7. Implement hooks:
 *    REQUIRED:
 *      onItemView()          - product page display
 *      onCartView()          - cart page display
 *      onOrderProcessView()  - after checkout (redirect to gateway here)
 *      onOrderCompleteView() - thank-you page
 *      onOrderAfterPlace()   - create initial payment record
 *
 *    RECOMMENDED:
 *      onGetActions()        - register admin buttons
 *      onExecuteAction()     - handle admin button clicks
 *      onPaymentCheckup()    - the setup checklist in the method's settings
 *                              (see SETUP CHECKUP below)
 *
 *    OPTIONAL:
 *      onPaymentResponse()   - customer return from the gateway (REDIRECT-ONLY; see below)
 *      onPaymentWebhook()    - gateway server-to-server notification (RESPONSE-ONLY;
 *                              Standard does not implement it; see the WEBHOOK TUTORIAL below)
 *
 * 8. For gateway plugins, the key differences from Standard:
 *    - onOrderAfterPlace(): create as ->authorized() instead of ->pending()
 *    - onOrderProcessView(): redirect customer to gateway payment page
 *    - onPaymentResponse(): handle the customer's return from the gateway:
 *      REDIRECT-ONLY (see the GATEWAY RETURN section below).
 *    - onPaymentWebhook(): confirm payments server-to-server; the buyer's
 *      browser may never come back. Standard does not implement it; the
 *      WEBHOOK TUTORIAL below is the contract to write it against.
 *    - handleCapture(): ->completed()->transactionId($id)->processedAt($now)
 *    - handleRefund(): add ->transactionId() and ->gatewayResponse()
 *    - logs.xml: add gateway-specific columns (e.g. gateway_event_id)
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Path: plugins/alfa-payments/standard/src/Extension/Standard.php
 *
 * @since  1.0.0
 */

namespace Alfa\Plugin\AlfaPayments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentCheckupEvent;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\QueryInterface;
use Joomla\Input\Input;

defined('_JEXEC') or die;

final class Standard extends PaymentsPlugin
{
    /**
     * Staff may put an order through with this method.
     *
     * It is the manual one: placement writes a single pending payment row and
     * nothing else. No redirect, no browser session, no request state, no
     * gateway; there is nothing here that assumes a shopper is watching, and
     * nothing that would exist anywhere else if one is not.
     *
     *
     * @since  2.0.0
     */
    public function supportsOperatorPlacement(): bool
    {
        return true;
    }
    //  FRONTEND HOOKS: Customer-Facing Pages
    //
    //  These render HTML on the storefront. Each hook receives an event
    //  with the relevant context (product, cart, order) and the payment
    //  method record. You MUST call setLayout(), even for an empty layout.
    //
    //  For gateway plugins: onOrderProcessView() is where you redirect
    //  the customer to the external payment page (a hosted checkout,
    //  a wallet redirect, etc.).

    /**
     * Product page: show payment-related info (e.g. "Pay in installments").
     *
     * Available on $event:
     *   $event->getItem()     -> Product item object   (alias of getSubject())
     *   $event->getMethod()   -> Payment method record (id, name, params...)
     *   $event->setLayout()   -> Template file in tmpl/ (without .php)
     *   $event->setLayoutData() -> Associative array passed to template
     *
     * For Standard: shows basic payment info text.
     * For a card gateway: could show "Pay in 4 installments" badge.
     * For a wallet gateway: could show "Wallet accepted" logo.
     *
     * @param object $event ItemViewEvent
     * @since  1.0.0
     */
    public function onItemView($event): void
    {
        $event->setLayout('default_item_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item' => $event->getItem(),   // getSubject(): this event's subject is the product
        ]);
    }

    /**
     * Cart page: show payment method selector or payment form.
     *
     * Available on $event:
     *   $event->getRequest()  -> MethodViewRequest (methodId, methodType, methodParams, isSelected, cartTotal)
     *                           (alias of getSubject())
     *   $event->getMethod()   -> Payment method record
     *
     * For Standard: shows method name and description.
     * For a card gateway: could render the gateway's hosted card form.
     * For a wallet gateway: could render the wallet's checkout buttons.
     *
     * @param object $event CartViewEvent
     * @since  1.0.0
     */
    public function onCartView($event): void
    {
        $event->setLayout('default_cart_view');
        // NOTHING IS PASSED BECAUSE NOTHING IS RENDERED. This layout emits no
        // bytes; handing it the whole view request would put an entire cart (
        // its priced lines, its customer and its raw address rows) into layout
        // data that is then carried on the cart snapshot. A presentation payload
        // carries what the template prints, and this one prints nothing.
        $event->setLayoutData([]);
    }

    /**
     * Order processing page: after checkout, before completion.
     *
     * This is the CRITICAL hook for gateway plugins. The order and payment
     * record already exist in the database at this point.
     *
     * For Standard (offline/cash/bank transfer): we skip the processing
     * page entirely and let the HtmlView fallback redirect straight to
     * default_order_completed. No external gateway interaction is needed.
     *
     * For external gateway plugins (card gateways, wallets, etc.) you would:
     *   - Redirect to the gateway:
     *       $event->setRedirectUrl($gatewayCheckoutUrl);
     *   - OR render a processing/waiting page:
     *       $event->setLayout('default_order_process');
     *       $event->setLayoutData([...]);
     *     The component's default_order_process template renders your
     *     layout and nothing else; retry and cancel buttons are your
     *     layout's job.
     *   - On the buyer's RETURN, onPaymentResponse() redirects back here with a
     *     ?<plugin>_result= flag; read it and render default_order_process_cancelled
     *     | error. (See the GATEWAY RETURN section after onOrderCompleteView.)
     *
     * Available on $event:
     *   $event->getOrder()    -> Order object (with ->id, ->payments, etc.)
     *                           (alias of getSubject())
     *   $event->getMethod()   -> Payment method record
     *
     * @param object $event OrderProcessViewEvent
     * @since  1.0.0
     */
    public function onOrderProcessView($event): void
    {
        // Standard payment is offline: no processing step needed.
        // By not setting a layout or redirect, the HtmlView will
        // automatically redirect to default_order_completed.
    }

    /**
     * Order completion page: thank-you / confirmation.
     *
     * Shown after successful payment (or after order placement for offline).
     * For gateway plugins: the customer returns here after paying on the
     * external gateway site.
     *
     * Available on $event:
     *   $event->getOrder()    -> Order object   (alias of getSubject())
     *   $event->getMethod()   -> Payment method record
     *
     * @param object $event OrderCompleteViewEvent
     * @since  1.0.0
     */
    public function onOrderCompleteView($event): void
    {
        $event->setLayout('default_order_completed');
        $event->setLayoutData([
            'order' => $event->getOrder(),   // getSubject(): this event's subject is the order
            'method' => $event->getMethod(),
        ]);
    }

    //  GATEWAY RETURN: onPaymentResponse()  (customer return from the gateway)
    //
    //  Fired by the site PaymentController (task=payment.response) when the buyer
    //  returns from a hosted gateway. It runs in a CONTROLLER WITH NO VIEW, so it
    //  is REDIRECT-ONLY: setLayout()/setLayoutData() are IGNORED here. Decide
    //  where to send the buyer and call setRedirectUrl():
    //
    //      success    -> the complete page : $this->getCompletePageUrl()
    //      cancel/err -> the process page  : $this->getProcessPageUrl()
    //                                       . '&<plugin>_result=cancelled|error'
    //
    //  onOrderProcessView() then reads that ?<plugin>_result= flag and renders
    //  default_order_process_cancelled|error (that hook HAS a view). To show a
    //  result page you ALWAYS redirect to a view page; never render a layout
    //  here.
    //
    //  Standard is offline (no hosted gateway, no return), so it does NOT
    //  implement this hook. A gateway plugin (card gateways, wallets, ...) does;
    //  uncomment and fill in:
    //
    //      public function onPaymentResponse($event): void
    //      {
    //          $order  = $event->getOrder();
    //          $status = Factory::getApplication()->getInput()->getString('<gateway>_status', '');
    //
    //          if ($status === 'paid') {   // gateway confirmed the payment on return
    //              $this->payment($order)->completed()->transactionId($txnId)->save();
    //              $event->setRedirectUrl($this->getCompletePageUrl());      // success
    //              return;
    //          }
    //
    //          // cancelled / failed: bounce to the process page; onOrderProcessView
    //          // reads &<plugin>_result= and renders the cancelled/error layout.
    //          $event->setRedirectUrl($this->getProcessPageUrl() . '&<plugin>_result=cancelled');
    //      }

    //  THE CART PAGE: code in onCartViewAssets(), markup in onCartView()
    //
    //  TWO HOOKS, BECAUSE THE PAGE AND ITS AJAX ANSWERS ARE NOT THE SAME THING.
    //
    //  onCartViewAssets($event): once per plugin, while the page is BUILT.
    //     The storefront cart and the order builder call it during their HTML
    //     render, for every plugin that owns a PUBLISHED method (offered for
    //     this cart or not) and before Joomla compiles the head. Declare here:
    //
    //         $wa = $event->getWebAssetManager();
    //         $wa->getRegistry()->addRegistryFile('media/plg_alfa-payments_x/joomla.asset.json');
    //         $wa->useScript('plg_alfa-payments_x.cart')->useStyle('plg_alfa-payments_x.cart');
    //
    //         Text::script('PLG_ALFA_PAYMENTS_X_CARD_DECLINED');
    //         $event->getDocument()->addScriptOptions('plg_alfa-payments_x', ['publicKey' => $key]);
    //
    //     Give the script a dependency on com_alfa.cart in joomla.asset.json,
    //     so it is ordered after the cart's own, and name no other dependency
    //     from a registry file this hook does not add itself: a dependency
    //     nobody registered is raised while the head is compiled, outside any
    //     isolation, and takes the page down. Keep it to your bootstrap: a
    //     gateway's hosted-fields SDK is that script's job to fetch on first
    //     use, and Alfa ships no loader for it. Standard declares nothing; it
    //     has no script.
    //
    //  onCartView($event): once per OFFERED method, on EVERY settle.
    //     That includes the JSON answers to cart.updateShipment,
    //     cart.updatePayment and cart.refreshMethods, which replace the whole
    //     [data-cart-shipments] / [data-cart-payments] block by outerHTML. So
    //     the layout is markup and data-* attributes only. A useScript() there
    //     reaches the head on the first HTML render and is lost on every JSON
    //     swap after it: the panel works once, then stops (under JDEBUG the
    //     cart log names the asset); a <script> tag there never runs after a
    //     swap. Values that
    //     change during checkout (an instalment amount, the payable total)
    //     go on the panel as data-* so the swap refreshes them; suffix ids
    //     with the method id, because two methods of one plugin may be offered
    //     at once, and disable nothing by hand: a panel that is not the chosen
    //     method's is simply not rendered.
    //
    //  YOUR SCRIPT, on the page from the first render:
    //     * bind once, delegated on document: the panel it targets is
    //       replaced on every change;
    //     * initialise from the panel's data-* on alfaCartShipmentChanged,
    //       alfaCartPaymentChanged and alfaCartMethodsRefreshed, dispatched on
    //       document with detail.data after every successful update, and be
    //       idempotent: already mounted -> update, else mount;
    //     * clean up whatever you attached when the panel goes.

    //  HEARING ANYTHING ELSE: getSubscribedEvents() and com_ajax
    //
    //  TWO KINDS OF HOOK, AND ONLY ONE NEEDS DECLARING.
    //
    //  1. The gateway hooks (onCartViewAssets(), onOrderAfterPlace(),
    //     onPaymentResponse(), onPaymentWebhook(), onGetActions(),
    //     onExecuteAction(), onPaymentCheckup()) are called DIRECTLY by the
    //     component, which boots this plugin by element and checks
    //     method_exists(). Writing the method IS the subscription; declaring
    //     it changes nothing. The base class subscribes
    //     onItemView/onCartView/onCartViewAssets for you and nothing else is
    //     needed. The flip side: a method written only as an example is a
    //     live endpoint, which is why Standard has no onPaymentWebhook().
    //
    //  2. Anything that travels through Joomla's DISPATCHER (a system event
    //     like onBeforeCompileHead or onAfterRender, or com_ajax) reaches this
    //     plugin ONLY if it is declared, and only once the plugin has been
    //     subscribed: the cart page subscribes it when one of its methods is
    //     offered, and com_ajax imports the whole group itself. Anything you
    //     need on every page is a system plugin's job, not a gateway's.
    //     CMSPlugin::registerListeners() reads:
    //
    //         if ($this instanceof SubscriberInterface) {
    //             $this->getDispatcher()->addSubscriber($this);
    //             return;                  // nothing else is registered, ever
    //         }
    //
    //     These plugins ARE SubscriberInterface implementations, so an
    //     undeclared on*() method is simply never called. Merge, never replace:
    //
    //         public static function getSubscribedEvents(): array
    //         {
    //             return array_merge(parent::getSubscribedEvents(), [
    //                 'onBeforeCompileHead' => 'onBeforeCompileHead',
    //                 'onAjaxMygateway' => 'onAjaxMygateway',
    //             ]);
    //         }
    //
    //  AN AJAX ENDPOINT OF YOUR OWN, without adding a controller: com_ajax
    //  calls onAjax<PluginName> on plugins of the group you name. It is also
    //  how your own cart script fetches something for itself mid-checkout (a
    //  client token for hosted fields, an instalment quote for the current
    //  total) without a controller of its own.
    //
    //      index.php?option=com_ajax&group=alfa-payments&plugin=<yourplugin>&format=json
    //
    //  Append what you answer to the event's result; a listener's return
    //  value is discarded:
    //
    //      $result = (array) $event->getArgument('result', []);
    //      $result[] = ['token' => $token];
    //      $event->setArgument('result', $result);
    //
    //  Three things it will not do for you:
    //
    //    * IT CHECKS NO CSRF TOKEN. If your handler writes anything (session
    //      state, a row), check one yourself: Session::checkToken('request').
    //    * IT CANNOT TELL "nobody handled it" FROM "handled, returned nothing".
    //      An undeclared method answers {"success":true,...,"data":[]}: a 200
    //      that did nothing at all. That empty data is the tell.
    //    * IT IS NOT SEF-AWARE FROM THE BROWSER. A relative
    //      fetch('index.php?option=com_ajax...') posted from /en/some-page
    //      resolves to /en/index.php and the router answers 404. Build the URL
    //      from Joomla.getOptions('system.paths').rootFull, and check
    //      response.ok; a fetch().catch() sees network errors only, never a
    //      404, so a dead endpoint fails invisibly.

    //  WEBHOOK TUTORIAL: onPaymentWebhook()  (Standard does not implement it)
    //
    //  STANDARD DOES NOT IMPLEMENT THIS HOOK. It is an offline method: no
    //  payment provider exists to call it, so a live endpoint that settles
    //  money here would serve nobody and could only be attacked. The site
    //  PaymentController boots a plugin by element and checks
    //  method_exists($plugin, 'onPaymentWebhook'); the method's PRESENCE is
    //  the capability, so even a stub that returns at once would advertise
    //  it. Without it, a notification for method=standard gets the
    //  controller's normal answer for a plugin with no webhook: 404 Not found.
    //
    //  What follows is the contract a REAL gateway writes, as commented code.
    //  Copy it into your gateway plugin and fill in the provider's parts.
    //
    //  THE ADDRESS. The provider's server calls (no buyer, no session, no
    //  view)
    //
    //    index.php?option=com_alfa&task=payment.webhook&method=<element>
    //
    //  and nothing more: the address names no order and carries no secret.
    //  Give the provider the exact form your setup checkup prints (the
    //  {webhook} token in the checkup help, PaymentCheckupEvent::webhookUrl()):
    //  on a multilingual SEF site the plain index.php form is redirected and
    //  the notification is lost.
    //
    //  THE EVENT. PaymentWebhookEvent arrives with subject = NULL, always:
    //  $event->getOrder() is null until YOU have found the order. It carries
    //  the raw HTTP answer instead of a redirect: setResponseCode(),
    //  setResponseBody(), setContentType(), or setJsonResponse() for all
    //  three. Bodies are machine strings for the provider's delivery log,
    //  never Text::_(). Register nothing in getSubscribedEvents(): the method
    //  existing IS the subscription.
    //
    //  THE ONE CHAIN: provider reference -> payment row -> order.
    //  An order id names an ORDER, not the payment the provider is talking
    //  about. A deposit and its balance, instalments and a retried attempt
    //  are several payment rows on one order. So the exact ROW is found from
    //  the provider's own reference (the one your plugin stored in
    //  transaction_id when it opened the attempt) and the order is taken
    //  FROM that row. Never the other way round: an order id in the request,
    //  a shared secret beside it, then "the pending payment of that order"
    //  settles whichever row happens to be pending.
    //
    //  AUTHENTICATE WITH THE PROVIDER'S OWN MECHANISM: its signature over
    //  the raw body (a signature header, a digest, a verification call to
    //  the provider). Never with the shopper's return link:
    //  orderFromReturnLink() and its id_order + token answer "may this
    //  BROWSER look at this order", a different boundary from "did the
    //  PROVIDER send this", and every buyer holds that token for their own
    //  order.
    //
    //  THE ANSWER. 2xx acknowledges and stops redelivery; 5xx asks the
    //  provider to retry. Acknowledge what retrying cannot fix (an unknown
    //  reference, a notification type you do not handle); never make it
    //  loop. Processing the same notification twice must never complete,
    //  refund or charge anything twice.
    //
    //      public function onPaymentWebhook($event): void
    //      {
    //          // 1. RECEIVE the notification exactly as it was sent.
    //          $raw = (string) file_get_contents('php://input');
    //          $signature = (string) Factory::getApplication()->getInput()
    //              ->server->get('HTTP_<PROVIDER>_SIGNATURE', '', 'raw');
    //
    //          // 2. AUTHENTICATE it with the provider's signature scheme and
    //          //    the signing key the provider issued. When that key is
    //          //    saved per METHOD, read the payload's reference only to
    //          //    find the candidate row (step 3), take the key from THAT
    //          //    row's method, and believe nothing until this passes.
    //          $notice = ProviderSignature::verify($raw, $signature, $signingKey);
    //
    //          if ($notice === null) {
    //              $event->setResponseCode(401);
    //              $event->setResponseBody('Invalid signature');
    //              return;
    //          }
    //
    //          // 3. RESOLVE THE PAYMENT by the provider's reference: THIS
    //          //    gateway's row carrying it, never another gateway's, and
    //          //    nothing at all rather than a guess.
    //          $payment = $this->gatewayPaymentByReference([$notice->reference, $notice->transactionId]);
    //
    //          if ($payment === null) {
    //              $event->setResponseCode(200);   // retrying will not make it known
    //              $event->setResponseBody('Unknown payment');
    //              return;
    //          }
    //
    //          // 4. LOAD THE ORDER from that payment row.
    //          $order = $this->getOrder((int) $payment->id_order);
    //
    //          // 5. VERIFY state, currency and amount against the ROW. The
    //          //    notification may confirm what the ledger expects; it never
    //          //    supplies the amount.
    //          if ($payment->transaction_status !== self::STATUS_PENDING) {
    //              $event->setResponseCode(200);   // a redelivery: already settled
    //              $event->setResponseBody('Already processed');
    //              return;
    //          }
    //
    //          $paid = Money::ofMinor($notice->amountMinor, $payment->amount->getCurrency());
    //
    //          if ($notice->currency !== $order->currency()->getCode() || !$paid->equals($payment->amount)) {
    //              // The money moved differently from what was asked: settle
    //              // nothing, leave it to an operator, stop the redelivery.
    //              Log::add('Provider paid ' . $paid->format() . ' against payment #' . $payment->id, Log::CRITICAL, $this->logCategory());
    //              $event->setResponseCode(200);
    //              $event->setResponseBody('Amount mismatch');
    //              return;
    //          }
    //
    //          // 6. SETTLE idempotently through the ledger: a compare-and-set
    //          //    from pending, so of two deliveries exactly one flips the row
    //          //    and fires the method's status_on_completed.
    //          $write = $this->paymentUpdate((int) $payment->id)
    //              ->expectStatus(self::STATUS_PENDING)
    //              ->completed()
    //              ->transactionId($notice->transactionId)
    //              ->processedAt(Factory::getDate('now', 'UTC')->toSql());
    //
    //          $verdict = $this->settleAttempt($write, (int) $order->id(), $notice->transactionId);
    //
    //          // 7. ANSWER the provider.
    //          if ($verdict === self::WRITE_FAILED) {
    //              $event->setResponseCode(500);   // not in the books: let it retry
    //              $event->setResponseBody('Ledger rejected');
    //              return;
    //          }
    //
    //          $event->setResponseCode(200);       // WRITE_OK, or WRITE_RACED: the other delivery won
    //          $event->setResponseBody('OK');
    //      }

    //  Order placement hook
    //
    //  Called by OrderPlacement::triggerAfterPlaceEvents() once the order
    //  and its items are committed. The SHIPMENT row does not exist yet:
    //  the payment plugin runs first and the shipment plugin right after,
    //  so shipping figures on the order object come from the cart, not
    //  from a shipment row you could query.
    //
    //  This is where you create the INITIAL payment record.

    /**
     * Create the initial payment record when an order is placed.
     *
     * WHEN: Called once per order, right after checkout completes.
     * WHO:  Triggered by the frontend OrderPlacement service.
     * WHAT: Creates a single payment record in #__alfa_order_payments.
     *
     * The fluent builder auto-resolves the payment amount from
     * $order->total_paid_tax_incl; no manual calculation needed.
     *
     * Status choices by plugin type:
     *   Standard (offline):  ->pending()    - admin confirms later
     *   Card (pre-auth):     ->authorized() - funds reserved, capture later
     *   Wallet (redirect):   ->pending()    - webhook confirms later
     *
     * For gateway plugins that need a transaction ID from the API call:
     *   $paymentId = $this->payment($order)
     *       ->pending()
     *       ->transactionId($gatewaySessionId)
     *       ->save();
     *
     * @param object $event OrderAfterPlaceEvent
     *                      $event->getOrder() -> full order object
     * @since  1.0.0
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        // Guard: order must exist and have a valid ID
        if (!$order || empty($order->id())) {
            return;
        }

        // Nothing to collect (customer credit covered the order at
        // placement): no pending row; a 0.00 collectable is audit noise the
        // admin could even "mark paid".
        if ($this->amountToCollect($order) <= 0.000001) {
            Log::add('Standard payment: order #' . $order->id() . ' fully collected at placement; no pending row.', Log::INFO, $this->logCategory());

            return;
        }

        // Create payment record:
        //   - Amount: auto-resolved via the platform contract: the amount
        //     still to collect (grand total minus completed rows)
        //   - Method: auto-filled from $order->id_payment_method
        //   - Currency: auto-filled from the order's currency
        //   - Status: pending (admin will confirm manually)
        //   - Employee: auto-filled from current session (0 for frontend)
        //   - Added: auto-filled with current UTC timestamp
        $paymentId = $this->payment($order)
            ->pending()
            ->save();

        // Log failure; don't throw, the order is already committed.
        // The admin can manually add a payment record later.
        if (!$paymentId) {
            Log::add(
                'Standard payment: Failed to create initial payment for order #' . $order->id(),
                Log::ERROR,
                $this->logCategory(),
            );
        }
    }

    //  PARTIAL PAYMENT: deposit now, balance later
    //
    //  An order may carry as many payment rows as it needs; "paid" is
    //  their SUM. A deposit at checkout and its balance later are simply
    //  two rows, and the order walks unpaid -> partial -> paid by itself.
    //  There is no partial flag to set anywhere.
    //
    //  Standard collects the whole amount in one row, so it does not do
    //  this. A deposit plugin would write, in onOrderAfterPlace():
    //
    //      // allocateByRatios keeps the two rows summing to the total;
    //      // round($total * 0.30, 2) can leave a cent stranded.
    //      [$deposit, $balance] = $order->total_paid_tax_incl
    //          ->allocateByRatios([30, 70]);
    //
    //      $this->payment($order)
    //          ->pending()                     // ->completed() if collected
    //          ->amount($deposit->getAmount()) // WITHOUT this the FULL total is used
    //          ->note('Deposit 30%')
    //          ->save();
    //
    //  N EQUAL INSTALMENTS (one call, no cents lost, whatever N is):
    //
    //      $parts = $order->total_paid_tax_incl->allocate(3);
    //      // 10.00 -> [3.34, 3.33, 3.33], summing back to exactly 10.00
    //
    //      foreach ($parts as $i => $part) {
    //          $this->payment($order)
    //              ->pending()
    //              ->amount($part->getAmount())
    //              ->note(sprintf('Instalment %d/%d', $i + 1, count($parts)))
    //              ->save();
    //      }
    //
    //  Splitting with /3 in PHP gives three rows summing to 9.99, and the
    //  order can then never reach "paid". Let Money do the arithmetic.
    //
    //  and later (from a webhook, an admin action or a cron task) the
    //  balance, computed from what is already completed:
    //
    //      $paid = 0.0;
    //
    //      foreach ($this->getPaymentsByOrder((int) $order->id) as $row) {
    //          if ($row->transaction_status === self::STATUS_COMPLETED
    //              && $row->payment_type === self::TYPE_PAYMENT) {
    //              $paid += (float) $row->amount;
    //          }
    //      }
    //
    //      $balance = $order->total_paid_tax_incl->getAmount() - $paid;
    //
    //      if ($balance > 0) {
    //          $id = $this->payment($order)->pending()->amount($balance)
    //              ->note('Balance')->save();
    //
    //          $this->paymentUpdate($id)->completed()->save();
    //      }
    //
    //  WHY pending-then-update rather than one ->completed() row: the
    //  side effects of a payment reaching 'completed' (download
    //  entitlements and the method's status_on_completed order transition)
    //  fire on the UPDATE path only. $this->payment(...)->completed()
    //  INSERTS a completed row: it saves fine and counts in the totals, but
    //  grants nothing and moves no order status. Only
    //  $this->paymentUpdate($id)->completed() does.
    //
    //  getPaymentsByOrder() returns RAW rows (plain floats), unlike the
    //  order object's ->payments, whose amounts are Money objects.
    //
    //  Only completed rows of type 'payment' count, so a pending deposit
    //  leaves the order 'unpaid' until it is flipped to ->completed().

    //  ADMIN ACTION HOOKS: Button Registration + Execution
    //
    //  These two methods control the admin action buttons that appear
    //  next to each payment in the order edit view.
    //
    //  Flow: onGetActions() registers buttons -> admin clicks one ->
    //        onExecuteAction() handles the click.

    /**
     * Register action buttons based on the current payment status.
     *
     * Called when the payments list is rendered in the order edit view.
     * Examines the payment's transaction_status and registers only the
     * buttons that make sense for the current state.
     *
     * Button API (fluent):
     *
     *   $event->add('action_name', 'Button Label')
     *       ->icon('checkmark')          // Joomla icon (without 'icon-' prefix)
     *       ->css('btn-success')         // Bootstrap button class
     *       ->confirm('Are you sure?')   // Confirmation dialog before execution
     *       ->priority(200);             // Higher = more prominent position
     *
     * EVERY action opens the same way: the button loads the action page in
     * a modal iframe, and what appears there is whatever your
     * onExecuteAction() sets with setLayout(). ->modal() only records the
     * intent for readers:
     *
     *   $event->add('view_details', 'Details')
     *       ->icon('eye')
     *       ->modal('action_view_details');   // documentation, not wiring
     *
     * Its title and size arguments are NOT read by anything: the popup is
     * always modal-xl and its title is the BUTTON LABEL. Same for
     * $event->setModalTitle() in the handler: a dead write today.
     *
     * Available on $event:
     *   $event->getPayment()  -> Payment record (with Money objects, params)
     *   $event->getOrder()    -> Full order object
     *
     * NOTE: this event has NO getSubject(); payment and order are two
     * separate named arguments, not one subject.
     *
     * @param object $event GetPaymentActionsEvent
     * @since  1.0.0
     */
    public function onGetActions($event): void
    {
        $payment = $event->getPayment();
        $status = $payment->transaction_status ?? 'pending';

        // Always available

        // View Details: opens a modal with payment information
        $event->add('view_details', Text::_('PLG_ALFA_PAYMENTS_STANDARD_VIEW_DETAILS'))
            ->icon('eye')->css('btn-outline-secondary')
            ->modal('action_view_details', Text::_('PLG_ALFA_PAYMENTS_STANDARD_PAYMENT_DETAILS') . ' #' . $payment->id)
            ->readOnly()
            ->priority(10);

        // View Logs: opens a modal with this payment's plugin log history
        $event->add('view_logs', Text::_('PLG_ALFA_PAYMENTS_STANDARD_VIEW_LOGS'))
            ->icon('list')->css('btn-outline-info')
            ->modal('default_order_logs_view', Text::_('PLG_ALFA_PAYMENTS_STANDARD_PAYMENT_LOGS') . ' #' . $payment->id, 'lg')
            ->readOnly()
            ->priority(5);

        // Pending state

        if ($status === self::STATUS_PENDING) {
            // Mark as Paid: transitions to completed
            $event->add('mark_paid', Text::_('PLG_ALFA_PAYMENTS_STANDARD_MARK_PAID'))
                ->mutating()
                ->icon('checkmark')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_STANDARD_CONFIRM_MARK_PAID'))
                ->priority(200);

            // Cancel: transitions to cancelled
            $event->add('cancel', Text::_('PLG_ALFA_PAYMENTS_STANDARD_CANCEL'))
                ->mutating()
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_STANDARD_CONFIRM_CANCEL'))
                ->priority(50);
        }

        // Completed state

        // Refunds are offered only while the ORDER can still give money back:
        // store credit already returned for its credit notes counts as paid
        // out (the platform preflight), so a refunded-to-wallet order must
        // not also offer a cash refund here. Only a payment holds money to
        // return: a refund, reversal or chargeback row is never refunded, and
        // refundPayment() refuses one too. And only while the payment itself
        // still holds some: a chargeback flips nothing, so a payment the bank
        // took back in full stays 'completed' with nothing left to refund.
        if ($status === self::STATUS_COMPLETED
            && ($payment->payment_type ?? '') === self::TYPE_PAYMENT
            && $this->refundableNow($event->getOrder())->isPositive()
            && PaymentLedger::refundableRemainder(paymentId: (int) $payment->id)->isPositive()) {
            // Refund: transitions to refunded + creates refund record. Both refund
            // actions start a refund: the core withholds them while a provider has
            // reported a refund on this order that the books do not hold.
            $event->add('refund', Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND'))
                ->mutating()
                ->startsRefund()
                ->icon('undo-2')->css('btn-warning')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_STANDARD_CONFIRM_REFUND'))
                ->priority(150);

            // Partial Refund: opens an amount form; writes ONLY the audit
            // row, the original stays 'completed' (see the PARTIAL REFUND
            // block after handleRefund() for why that matters).
            $event->add('refund_partial', Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND_PARTIAL'))
                ->mutating()
                ->startsRefund()
                ->icon('undo-2')->css('btn-outline-warning')
                ->modal('action_refund_partial', Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND_PARTIAL'), 'sm')
                ->priority(140);
        }

        // TERMINAL STATES (refunded, cancelled, failed)
        // No STATE-CHANGING actions: the lifecycle is complete. View
        // Details and View Logs are still there: they were registered
        // unconditionally above, before any status branch.
        // Gateway plugins might add "Retry" for failed payments.
    }

    /**
     * Handle an admin action button click.
     *
     * Routes the action name to the appropriate handler method.
     * Uses PHP 8.0 match() for clean dispatch; add your custom
     * actions here when extending.
     *
     * Available on $event:
     *   $event->getAction()    -> action name string (e.g. 'mark_paid')
     *   $event->getPayment()   -> payment record (with Money objects)
     *   $event->getOrder()     -> full order object
     *   $event->setMessage()   -> success message shown to admin
     *   $event->setError()     -> error message shown to admin
     *   $event->setRefresh()   -> true to reload the page after action
     *   $event->setLayout()    -> render template (for modal actions)
     *   $event->setLayoutData() -> data for the template
     *   $event->setModalTitle() -> currently READ BY NOTHING; the popup
     *                            title is the button's label
     *   $event->addButton()    -> footer button on the action page; with
     *                            ['action' => 'x'] it runs another action
     *                            of THIS payment in the same popup
     *   $event->getData()      -> ALWAYS [] for payments and shipments; the
     *                            action page builds the event with an empty
     *                            data array. Read values a layout sent with
     *                            Factory::getApplication()->getInput().
     *
     * Like GetPaymentActionsEvent, this event has NO subject: getPayment()
     * and getOrder() are separate named arguments.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'mark_paid' => $this->handleMarkPaid($event),
            'cancel' => $this->handleCancel($event),
            'refund' => $this->handleRefund($event),
            // Two arms: the amount form, and the mutating step the form's
            // submit navigates to (AlfaAction.run in refund-partial.js).
            'refund_partial' => $this->handleRefundPartialForm($event),
            'refund_partial_do' => $this->handleRefundPartial($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs' => $this->handleViewLogs($event),
            default => $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_UNKNOWN_ACTION', $event->getAction())),
        };
    }

    //  ACTION HANDLERS: Business Logic
    //
    //  Each handler follows the same pattern:
    //    1. Get payment + order from event
    //    2. Use fluent builder to modify the payment record
    //    3. Write a structured log entry with ALL schema columns
    //    4. Set success/error message on the event
    //    5. Request page refresh if data changed
    //
    //  Log schema columns (10 plugin-specific + 3 auto):
    //    Auto:    id, id_order, id_order_payment
    //    Plugin:  action, transaction_status, amount, order_total,
    //             currency, transaction_id, refund_type, note,
    //             created_on, created_by

    /**
     * Mark payment as paid (completed).
     *
     * Transition: pending -> completed
     *
     * What happens:
     *   1. Updates transaction_status to 'completed' in #__alfa_order_payments
     *   2. Sets processed_at to current UTC timestamp (when confirmed)
     *   3. The payment now counts toward total_paid_real
     *   4. When the order's balance settles at its currency's scale ->
     *      payment_status = 'paid'
     *   5. Writes plugin log + automatic activity log (via helper)
     *
     * For gateway plugins, this is typically triggered by a webhook
     * callback (e.g. a gateway's charge-succeeded or capture-completed event)
     * rather than an admin button click.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    private function handleMarkPaid($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $amount = $this->getPaymentAmount($payment);
        $now = Factory::getDate('now', 'UTC')->toSql();

        // Update the payment record via fluent builder.
        // ->completed() sets transaction_status = 'completed'
        // ->processedAt() records when the payment was confirmed
        // ->save() writes every field the builder was given (which is only
        // what you set), diffs old vs new to build the activity-log entry,
        // writes #__alfa_order_activity_log, and (because this is the
        // UPDATE path) grants download entitlements and runs the method's
        // status_on_<status> order transition.
        $result = $this->paymentUpdate((int) $payment->id)
            ->completed()
            ->processedAt($now)
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_MARK_PAID', $payment->id));
            return;
        }

        // Write to the PLUGIN-SPECIFIC log table.
        // This is separate from the order activity log (which the builder
        // writes automatically). Plugin logs store gateway-specific data
        // and are shown in the "View Logs" modal.
        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_payment' => (int) $payment->id,
            'action' => 'mark_paid',
            'transaction_status' => self::STATUS_COMPLETED,
            'amount' => $amount,
            'order_total' => $this->getOrderTotal($order),
            'currency' => $order->currency()?->getCode() ?? '',
            'transaction_id' => $payment->transaction_id ?? null,
            'refund_type' => null,
            'note' => Text::_('PLG_ALFA_PAYMENTS_STANDARD_LOG_MARKED_PAID'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        // Success: show message and refresh the payments list
        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_MSG_MARKED_PAID', $payment->id));
        $event->setRefresh(true);
    }

    /**
     * Cancel a pending payment.
     *
     * Transition: pending -> cancelled
     *
     * What happens:
     *   1. Updates transaction_status to 'cancelled'
     *   2. The payment no longer counts toward total_paid_real
     *      (it never did; pending payments don't count either)
     *   3. The Cancel and Mark as Paid buttons disappear
     *   4. Writes plugin log + automatic activity log
     *
     * For gateway plugins, this might also need to void a pre-auth
     * hold via the gateway API. Example:
     *
     *   $gateway->paymentIntents->cancel($paymentIntentId);
     *   $this->paymentUpdate($id)->cancelled()
     *       ->transactionId($voidTxnId)
     *       ->gatewayResponse(json_encode($response))
     *       ->save();
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    private function handleCancel($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $amount = $this->getPaymentAmount($payment);
        $now = Factory::getDate('now', 'UTC')->toSql();

        $result = $this->paymentUpdate((int) $payment->id)
            ->cancelled()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_CANCEL', $payment->id));
            return;
        }

        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_payment' => (int) $payment->id,
            'action' => 'cancel',
            'transaction_status' => self::STATUS_CANCELLED,
            'amount' => $amount,
            'order_total' => $this->getOrderTotal($order),
            'currency' => $order->currency()?->getCode() ?? '',
            'transaction_id' => $payment->transaction_id ?? null,
            'refund_type' => null,
            'note' => Text::_('PLG_ALFA_PAYMENTS_STANDARD_LOG_CANCELLED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_MSG_CANCELLED', $payment->id));
        $event->setRefresh(true);
    }

    /**
     * Refund a completed payment.
     *
     * Transition: completed -> refunded, through PaymentLedger's verb.
     *
     * ONE CALL: $this->refundPayment($id, null, ['reason' => ...]).
     *
     * The verb writes the linked audit row (payment_type = 'refund', FK
     * id_refunded_payment, refund_type = 'full') under the order's lock, then
     * flips the original to 'refunded' through a COMPARE-AND-SET once its
     * settled refunds cover it; a refused row changes nothing. It answers
     * with an OUTCOME_* verdict, so this
     * handler never has to re-derive what the ledger did to the rows. After a
     * chargeback the rest is booked 'partial' with no flip (refunds alone make
     * a refund full), and the message and log row say so.
     *
     * WHY NOT THE TWO STEPS THIS USED TO DOCUMENT
     *   A hand-written step 1 is paymentUpdate($id)->refunded()->save() with
     *   no expected status, so it takes the ordinary update() path. Two
     *   admins clicking Refund BOTH pass it. The ledger then refuses the
     *   loser's audit row over the linked over-refund cap, and the loser's
     *   own compensation flips the original back to 'completed' on top of the
     *   winner's committed, settled full refund: the money is counted as
     *   collected and returned at the same time, and status_on_completed
     *   re-fires across the order. This is the defect class PaymentLedger's
     *   INTENT VERBS header names ("a refund that, run twice, flipped a
     *   refunded payment back to completed and re-granted its entitlements")
     *   and refundPayment() exists to close it.
     *
     * @see PaymentsPlugin::refundPayment() for the outcome vocabulary.
     *
     * AMOUNT: the refundable REMAINDER, never the gross. After a partial
     * refund the original deliberately stays 'completed', so this action
     * stays registered; a gross-sized refund would then always exceed the
     * ledger's per-payment cap. Exhausted remainder aborts before the verb
     * is called.
     *
     * FOR GATEWAY PLUGINS (card gateways, wallets): reserve first, then ask
     *   the provider with no lock held, then report its answer:
     *
     *     $r = $this->reserveRefund((int) $payment->id, $amount, ['mark' => $postedMark]);
     *     if ($r['outcome'] !== self::OUTCOME_RESERVED) { ...refuse, send nothing... }
     *     $refund = $gateway->refunds->create([... $r['amount'], idempotency key $r['key'] ...]);
     *     $this->settleRefund($r['reservation'], $providerFigure, $refund->id, ['final' => true]);
     *
     *   holdRefund() when the provider accepted it as pending, releaseRefund()
     *   when it refused it, nothing when the answer is unknown. The form's
     *   maximum is refundFormMaximum().
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    private function handleRefund($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $now = Factory::getDate('now', 'UTC')->toSql();

        // The REMAINDER, not the gross (see AMOUNT above): the same figure
        // the ledger's linked over-refund cap enforces, so the audit row cannot
        // be rejected over refunds that already happened. Checked before the
        // verb so nothing is asked when nothing is refundable. Money, so "nothing
        // left" is a question about the currency's own smallest unit.
        $amount = PaymentLedger::refundableRemainder((int) $payment->id);

        if (!$amount->isPositive()) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_EXHAUSTED', $payment->id));

            return;
        }

        // THE ORDER'S POOL, NOT ONLY THIS ROW'S REMAINDER. The gateway plugins
        // both preflight against refundableNow(); this handler did not, and it
        // is the one that hand-rolls its two steps instead of going through
        // refundPayment(), so it also misses that function's own pool cap.
        // The two figures differ whenever money has already gone back by
        // another rail: a credit note settled to the customer's wallet lowers
        // what the ORDER may still return while leaving this payment's own
        // remainder untouched. Refunding the larger of the two pays the same
        // money out twice: once to the wallet, once to the card.
        $pool = $this->refundableNow($order);

        if ($amount->greaterThan($pool)) {
            $event->setError(Text::sprintf(
                'PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_POOL',
                $amount->format(),
                $pool->format(),
            ));

            return;
        }

        // THE REFUND: one verb, not two hand-rolled writes
        //
        // The ledger contract makes PaymentLedger the single writer of money movements and
        // its transitions compare-and-set. refundPayment() is that contract:
        // it writes the linked audit row under the order's lock, then flips
        // the original through the CAS once its settled refunds cover it; a
        // refused row changes nothing.
        //
        // This handler used to do those two steps by hand, and step 1 was a
        // plain paymentUpdate()->refunded()->save() with no expected status,
        // so it took the ordinary update() path and two admins clicking
        // Refund BOTH passed it. The ledger then correctly refused the
        // loser's audit row over the linked cap, and the loser's own
        // compensation flipped the original back to 'completed' on top of the
        // winner's committed, settled full refund: the order counted the
        // money as collected and returned at the same time, and
        // status_on_completed re-fired across it. Measured 6 rounds out of 6
        // before this change.
        //
        // The amount is deliberately NOT passed. null means "the refundable
        // remainder", which the ledger recomputes INSIDE its own lock; the
        // preflight above is an operator courtesy, not the decision, and a
        // figure read out here could already be stale by the time it lands.
        //
        // No 'gateway_moved': this plugin moves nothing at a gateway, so the
        // refund is a start, decided under the order's lock with every
        // reservation counted, and a refused audit row is a plain refusal.
        //
        // FULL OR PARTIAL IS THE LEDGER'S RULE, not a second copy of it here:
        // refunds alone make a refund full, so after a chargeback the rest is
        // booked partial and the payment stays completed. refundTypeFor() is
        // the rule refundPayment() applies to the same remainder; the reason,
        // the log row and the message say what it books.
        $refundType = PaymentLedger::refundTypeFor(paymentId: (int) $payment->id, amount: null);
        $isFull = $refundType === self::REFUND_FULL;
        $reason = Text::sprintf($isFull ? 'PLG_ALFA_PAYMENTS_STANDARD_LOG_REFUNDED' : 'PLG_ALFA_PAYMENTS_STANDARD_LOG_REFUNDED_PARTIAL', $payment->id);

        $result = $this->refundPayment((int) $payment->id, null, [
            'reason' => $reason,
        ]);

        if ($result['outcome'] !== self::OUTCOME_OK) {
            // The ledger has already logged its precise reason (including
            // OUTCOME_RACED, "another caller moved the row mid-flight"); the
            // operator message stays the one this plugin has always shown.
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND', $payment->id));

            return;
        }

        // What the ledger ACTUALLY returned, not the figure read before the
        // call: the same reconciliation trail the gateway plugins keep.
        $refunded = (float) ($result['amount'] ?? $amount->getAmount());

        // Write plugin-specific log
        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_payment' => (int) $payment->id,
            'action' => 'refund',
            'transaction_status' => self::STATUS_REFUNDED,
            'amount' => $refunded,
            'order_total' => $this->getOrderTotal($order),
            'currency' => $order->currency()?->getCode() ?? '',
            'transaction_id' => $payment->transaction_id ?? null,
            'refund_type' => $refundType,
            'note' => $reason,
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage($isFull
            ? Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_MSG_REFUNDED', $payment->id)
            : Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_MSG_REFUNDED_PARTIAL', Money::of($refunded, $amount->getCurrency())->format(), $payment->id));
        $event->setRefresh(true);
    }

    //  PARTIAL REFUND: giving back part of a completed payment
    //
    //  LIVE: registered as 'refund_partial' in onGetActions(); the form step
    //  is handleRefundPartialForm() and the mutating arm 'refund_partial_do'
    //  is handleRefundPartial(), both right below. The amount input lives in
    //  tmpl/action_refund_partial.php; the plugin asset
    //  media/plg_alfa-payments_standard/js/refund-partial.js reads it and
    //  calls AlfaAction.run('refund_partial_do', {amount: value}).
    //
    //  WHY THERE IS NO STEP 1 (the trap handleRefund() cannot teach):
    //  total_paid_real counts only rows still transaction_status 'completed'
    //  AND payment_type 'payment', so flipping the original to ->refunded()
    //  would drop its ENTIRE amount out of the paid total over a refund of
    //  a few euros, and fire the method's status_on_refunded automation
    //  across the whole order (stock release, entitlement revocation).
    //
    //  That automation reaches further than it looks: DownloadController
    //  re-checks the order's CURRENT status on every download hit, so the
    //  moment status_on_refunded moves the order out of the completed family
    //  every mailed download link dies, for the whole order, over a partial
    //  refund. Leave the original alone; write only the audit row.
    //
    //  REPORTING LIMIT: refund rows are payment_type 'refund', so they
    //  are not subtracted from total_paid_real either. The order keeps
    //  reporting the gross as paid. If the books must show the net, lower
    //  the original row as well; the refund row remains the audit trail.

    /**
     * Partial refund, STEP 1 of 2: the amount form.
     *
     * Registered as 'refund_partial'. Renders tmpl/action_refund_partial.php
     * in the action popup with the refundable remainder as both the input's
     * max and its prefill. The layout's submit navigates to the mutating arm
     * 'refund_partial_do' via AlfaAction.run(); this step changes NOTHING.
     *
     * The remainder comes from the ledger's static balance API: the SAME
     * figure its linked over-refund guard enforces, so the form can never
     * offer more than create() would accept.
     *
     * EXHAUSTED remainder -> the explanatory notice, never the form: with max
     * below the input's own min/step (one minor unit of the order's currency)
     * every value fails checkValidity(), so the admin would only ever see the
     * browser's tooltip and the server-side message would be unreachable.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  2.0.0
     */
    private function handleRefundPartialForm($event): void
    {
        $payment = $event->getPayment();
        $max = PaymentLedger::refundableRemainder((int) $payment->id);

        // Below the form's step (one minor unit), nothing is submittable, so
        // answer the notice instead of a dead form. The action stays REGISTERED when
        // exhausted: hiding it would cost a ledger query per payment row on
        // every order-edit render.
        if (!$max->isPositive()) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_EXHAUSTED', $payment->id));

            return;
        }

        $event->setLayout('action_refund_partial');
        $event->setLayoutData([
            'payment' => $payment,
            'max' => $max,
        ]);

        // Footer close button; the submit itself lives in the layout.
        $event->addButton(Text::_('JCANCEL'));
    }

    /**
     * Partial refund, STEP 2 of 2: write the audit row.
     *
     * Transition: NONE; the original payment stays 'completed' (see the
     * PARTIAL REFUND block above). Books only the linked 'refund' row for
     * the amount the admin typed, through the ledger's refund verb
     * (refundPayment()), which decides it as every new refund start.
     *
     * The amount is read from the request, NOT from $event->getData(): the
     * action page builds payment and shipment events with an EMPTY data
     * array, so getData() always returns [] for these two groups.
     *
     * The verb writes the audit row with the refunded row's payment method,
     * not the order's, which differs on a split-payment order.
     *
     * Because the original never leaves 'completed', the refund buttons stay
     * registered and a second partial refund is reachable: the ledger's
     * linked over-refund cap is the backstop; checking first makes the
     * message ours rather than a silent false from ->save().
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  2.0.0
     */
    private function handleRefundPartial($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $now = Factory::getDate('now', 'UTC')->toSql();

        // Ledger-sourced remainder: the exact figure the create-path cap
        // enforces, so this pre-check can never disagree with its backstop.
        // Money comparison: paying out the exact remainder must not be
        // rejected on a rounding artefact, and the tolerance is the currency's
        // own smallest unit rather than a guessed epsilon.
        $remainder = PaymentLedger::refundableRemainder((int) $payment->id);

        // ONE refund-amount contract: present, a valid decimal, greater than
        // zero, expressible at the order's scale; the same four checks the
        // core credit dialog and the REST endpoint apply. getFloat() used to do
        // all four at once, badly: '' and 'abc' both became 0.0.
        $requested = $this->partialRefundAmount(
            Factory::getApplication()->getInput(),
            $remainder,
        );

        if (\is_string($requested)) {
            $event->setError($requested);

            return;
        }

        if ($requested->greaterThan($remainder)) {
            $event->setError(Text::sprintf(
                'PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_AMOUNT',
                $remainder->format(),
            ));

            return;
        }

        // GIVING BACK EVERYTHING IS NOT A PARTIAL REFUND. Whatever the
        // operator clicked, a refund that consumes the entire remainder has
        // exactly the meaning of the full one: nothing is left on this
        // payment. Booked as 'partial' it left the original row 'completed',
        // so the method's refunded transition never fired and every
        // downstream reader (entitlements, documents, the order's payment
        // status) went on describing a paid order whose money had gone back.
        //
        // Deciding it HERE rather than in the dialog is the point. The form
        // used to prefill the whole remainder, which made the default gesture
        // do this; removing that prefill (see the template) stops the
        // accident but not an operator typing the full figure on purpose. The
        // amount decides what the refund IS.
        if (!$remainder->greaterThan($requested)) {
            $this->handleRefund($event);

            return;
        }

        // A NEW REFUND START, decided as every other: through the ledger's
        // refund verb, as handleRefund() books (since 2.1.0). refundPayment()
        // holds it to what the order and this payment have left once the
        // pending provider refunds are reserved, waits while the refund block
        // holds, and decides it again under the order's lock. The audit row it
        // writes is linked, partial (refunds alone decide full or partial: the
        // amount is below the remainder here) and carries the refunded row's
        // method; the original stays 'completed'. The amount is the Money every
        // decision above was taken on, so a figure the order's currency cannot
        // carry never reaches the books. Writing the row through the builder
        // judged it by the books alone: what a pending provider refund reserves
        // could be paid out twice.
        $writeAmount = $requested->getAmount();
        $result = $this->refundPayment((int) $payment->id, $requested, [
            'reason' => Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_LOG_REFUNDED_PARTIAL', $payment->id),
        ]);

        if ($result['outcome'] !== self::OUTCOME_OK) {
            // A start refused for what is reserved or blocked says why, in the
            // ledger's own sentence, as the action page says a block; any other
            // outcome keeps this action's message (the ledger logged its reason).
            $event->setError(\in_array($result['outcome'], [self::OUTCOME_BLOCKED, self::OUTCOME_NOT_REFUNDABLE], true) && (string) ($result['reason'] ?? '') !== ''
                ? Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ACTION_REFUSED_REFUND_BLOCKED', $result['reason'])
                : Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_PARTIAL', $payment->id));

            return;
        }

        $this->log([
            'id_order' => (int) $order->id(),
            'id_order_payment' => (int) $payment->id,
            'action' => 'refund_partial',
            'transaction_status' => self::STATUS_REFUNDED,
            'amount' => $writeAmount,
            'order_total' => $this->getOrderTotal($order),
            'currency' => $order->currency()?->getCode() ?? '',
            'transaction_id' => $payment->transaction_id ?? null,
            'refund_type' => self::REFUND_PARTIAL,
            'note' => Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_LOG_REFUNDED_PARTIAL', $payment->id),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf(
            'PLG_ALFA_PAYMENTS_STANDARD_MSG_REFUNDED_PARTIAL',
            $requested->format(),
            $payment->id,
        ));
        $event->setRefresh(true);
    }

    /**
     * The typed partial-refund amount, or the sentence that refuses it.
     *
     * The same four checks the core credit dialog and the REST endpoint run, in
     * the same order, so one keystroke gets one answer whichever door the
     * operator used. A monetary ZERO must never stand for "no amount was
     * supplied", and getFloat() on raw request input erased exactly that: '',
     * 'abc' and '0' all became 0.0.
     *
     * Validated in order: present, a valid decimal, greater than zero,
     * expressible at the currency's own scale, and only then built. The
     * remainder cap stays with the caller, because it is a question about
     * refundability rather than about what the operator typed.
     *
     * @param Input $input The request carrying 'amount'.
     * @param Money $remainder The refundable remainder; its currency decides
     *                         the scale, and its figure names the cap.
     *
     * @return Money|string The amount, or a translated refusal for the operator.
     *
     * @since  2.0.0
     */
    private function partialRefundAmount(Input $input, Money $remainder): Money|string
    {
        // NOT trimmed: padding is not a decimal, and repairing the input
        // silently is the same helpfulness that made '' mean "all of it".
        $raw = (string) $input->getString('amount', '');

        // 1. PRESENT. No default of '0': a default IS the overloaded zero.
        if ($raw === '') {
            return Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_AMOUNT', $remainder->format());
        }

        // 2. A VALID DECIMAL. Not is_numeric(), which admits '1e3', ' 1' and
        //    '.5'. The comma is the operator's separator, the dot the machine's.
        if (!preg_match('/^[+-]?\d{1,15}(?:[.,](\d+))?$/', $raw, $parts)) {
            return Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_AMOUNT_INVALID', $raw);
        }

        // 3. GREATER THAN ZERO.
        $value = (float) str_replace(',', '.', $raw);

        if ($value <= 0) {
            return Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_AMOUNT', $remainder->format());
        }

        // 4. EXPRESSIBLE IN THIS CURRENCY. Money::of() quantises silently, so
        //    5.004 on a 2-decimal order would be booked as 5.00, a figure the
        //    operator never typed, and the one every later remainder is
        //    computed from.
        $currency = $remainder->getCurrency();

        if (\strlen($parts[1] ?? '') > $currency->getDecimalPlaces()) {
            return Text::sprintf(
                'PLG_ALFA_PAYMENTS_STANDARD_ERROR_REFUND_AMOUNT_SCALE',
                $raw,
                $currency->getCode(),
                $currency->getDecimalPlaces(),
            );
        }

        return Money::of($value, $currency);
    }

    //  Modal action handlers
    //
    //  These open templates in a Bootstrap modal rather than
    //  performing an action. Registered with ->modal() in onGetActions().

    /**
     * Show payment details in a modal.
     *
     * Opens tmpl/action_view_details.php with the payment and order data.
     * The template can display: amount, status, gateway response,
     * transaction timeline, etc.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    private function handleViewDetails($event): void
    {
        $payment = $event->getPayment();

        $event->setLayout('action_view_details');
        $event->setLayoutData([
            'payment' => $payment,
            'order' => $event->getOrder(),
        ]);
        $event->setModalTitle(Text::_('PLG_ALFA_PAYMENTS_STANDARD_PAYMENT_DETAILS') . ' #' . $payment->id);
    }

    /**
     * Show plugin-specific logs in a modal.
     *
     * Opens tmpl/default_order_logs_view.php with log data and the
     * XML schema (for building table headers dynamically).
     *
     * loadLogs() automatically filters by id_order_payment because
     * PaymentsPlugin sets logIdentifierField = 'id_order_payment'.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    private function handleViewLogs($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();

        // Load logs for this specific payment (filtered by id_order_payment)
        $logData = $this->loadLogs((int) $order->id(), (int) $payment->id);

        $event->setLayout('default_order_logs_view');
        $event->setLayoutData([
            'logData' => $logData ?? [],
            'xml' => $this->getLogsSchema(),
        ]);
        $event->setModalTitle(Text::_('PLG_ALFA_PAYMENTS_STANDARD_PAYMENT_LOGS') . ' #' . $payment->id);
    }

    //  SETUP CHECKUP: the checklist in the method's settings, read-only
    //
    //  params.xml places the core field at the top of the settings:
    //
    //      <field name="checkup" type="paymentcheckup" variant="general"/>
    //
    //  variant="general" because Standard has no test and live mode. The
    //  field loads the SAVED method and calls onPaymentCheckup(); each
    //  addStep() is one line of the card, in order. Titles and help come
    //  from this plugin's language file (help may carry simple markup, the
    //  card prints it as is); the detail is plain text and is escaped.
    //
    //  A gateway also calls $event->offerCheck() to get a "Check connection"
    //  button, and asks its provider only when $event->isCheck() is true.
    //  Standard has no provider, so it offers no button.

    /**
     * The order-status fields step 1 needs, with their labels.
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    private const CHECKUP_STATUS_FIELDS = [
        'status_on_completed' => 'PLG_ALFA_PAYMENTS_STANDARD_STATUS_ON_COMPLETED_LABEL',
        'status_on_cancelled' => 'PLG_ALFA_PAYMENTS_STANDARD_STATUS_ON_CANCELLED_LABEL',
        'status_on_refunded' => 'PLG_ALFA_PAYMENTS_STANDARD_STATUS_ON_REFUNDED_LABEL',
    ];

    /**
     * The setup steps of one Standard method.
     *
     * Every step is judged from what is already stored: the saved order-status
     * map, the method's published state, and this method's first payment and
     * first refund in the ledger. Nothing is written and nobody is asked.
     *
     *   1. Order statuses set     all three status_on_* chosen
     *   2. Method published       state 1
     *   3. First payment recorded a payment row of this method that settled:
     *                             completed, or refunded in full since
     *   4. First refund recorded  a refund row of this method
     *
     * @param PaymentCheckupEvent $event The checkup being drawn.
     *
     *
     * @since  2.0.0
     */
    public function onPaymentCheckup(PaymentCheckupEvent $event): void
    {
        $params = $event->getParams();
        $methodId = $event->getMethodId();

        // 1. Without these a paid, cancelled or refunded payment moves no order.
        $missing = [];

        foreach (self::CHECKUP_STATUS_FIELDS as $field => $label) {
            if ((int) $params->get($field, 0) <= 0) {
                $missing[] = Text::_($label);
            }
        }

        $event->addStep(
            Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_STATUSES_TITLE'),
            $missing === [] ? PaymentCheckupEvent::DONE : PaymentCheckupEvent::TODO,
            Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_STATUSES_HELP'),
            $missing === [] ? '' : Text::sprintf('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_STATUSES_MISSING', implode(', ', $missing)),
        );

        // 2. An unpublished method is never offered at checkout.
        $event->addStep(
            Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_PUBLISHED_TITLE'),
            (int) ($event->getSubject()->state ?? 0) === 1 ? PaymentCheckupEvent::DONE : PaymentCheckupEvent::TODO,
            Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_PUBLISHED_HELP'),
        );

        // 3-4. Proven in use: money taken, then money given back.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $proofs = [
            'PAID' => [
                PaymentLedger::settledPaymentPredicate(),
                // Refunded in full since. The full Refund that step 4 asks for
                // flips the payment it returns from completed to refunded
                // (PaymentLedger::refundPayment), and that payment still took
                // the money: the ledger's refundable pool counts both.
                '(' . $db->quoteName('payment_type') . ' = ' . $db->quote(PaymentLedger::TYPE_PAYMENT)
                    . ' AND ' . $db->quoteName('transaction_status') . ' = ' . $db->quote(PaymentLedger::STATUS_REFUNDED) . ')',
            ],
            'REFUND' => [PaymentLedger::refundPredicate()],
        ];

        foreach ($proofs as $step => $predicates) {
            $proof = $this->firstLedgerRow($methodId, $predicates);

            $event->addStep(
                Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_' . $step . '_TITLE'),
                $proof !== null ? PaymentCheckupEvent::DONE : PaymentCheckupEvent::TODO,
                Text::_('PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_' . $step . '_HELP'),
                $proof !== null
                    ? Text::sprintf(
                        'PLG_ALFA_PAYMENTS_STANDARD_CHECKUP_' . $step . '_PROOF',
                        (int) $proof->id,
                        (int) $proof->id_order,
                        HTMLHelper::_('date', (string) $proof->moment, Text::_('DATE_FORMAT_LC5')),
                    )
                    : '',
            );
        }
    }

    /**
     * Steps 3 and 4: this method's first ledger row of one kind.
     *
     * One query, LIMIT 1. Each predicate is one branch that fixes the whole
     * idx_payment_method key (method, type, status), so each branch is a ref
     * lookup that stops at its first row. Step 3's two statuses are two
     * branches joined by UNION ALL, and the lowest id wins. A single
     * IN (completed, refunded) is a range instead, and on the demo MariaDB
     * answered it from another index, reading every payment row of the type.
     *
     * Store-credit rows are left out: a wallet spend carries the ORDER's
     * method id (see PaymentLedger::NON_GATEWAY_TYPES), so without that it
     * would read as money this method took.
     *
     * @param int $methodId The method.
     * @param string[] $predicates Ledger predicates, one per branch, each fixing type and status.
     *
     * @return object|null id, id_order and moment (when the money moved), or null.
     *
     * @since  2.0.0
     */
    private function firstLedgerRow(int $methodId, array $predicates): ?object
    {
        if ($methodId <= 0 || $predicates === []) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $credit = implode(',', array_map(static fn (string $type): string => $db->quote($type), PaymentLedger::NON_GATEWAY_TYPES));
        $branches = array_map(
            static fn (string $predicate): QueryInterface => $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order']))
                ->select(PaymentLedger::moneyMoment() . ' AS ' . $db->quoteName('moment'))
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_payment_method') . ' = ' . $methodId)
                ->where($predicate)
                ->where($db->quoteName('payment_method_type') . ' NOT IN (' . $credit . ')')
                ->order($db->quoteName('id') . ' ASC')
                ->setLimit(1),
            $predicates,
        );
        $query = array_shift($branches);

        if ($branches !== []) {
            $query = $query->toQuerySet();

            foreach ($branches as $branch) {
                $query->unionAll($branch);
            }

            $query->order($db->quoteName('id') . ' ASC');
        }

        return $db->setQuery($query, 0, 1)->loadObject() ?: null;
    }

    //  Utility methods

    /**
     * Extract the payment amount as a float.
     *
     * Safely handles all possible amount formats:
     *   - Money object (from OrderModel::getItem) -> calls ->getAmount()
     *   - Raw float (from direct DB query) -> casts to float
     *   - String (from form input) -> casts to float
     *   - Null/missing -> returns 0.0
     *
     * Used by action handlers for logging and refund amount calculation.
     *
     * @param object $payment Payment record
     * @return float Payment amount as a plain float
     * @since  1.0.0
     */
    private function getPaymentAmount(object $payment): float
    {
        $amount = $payment->amount ?? 0;

        // Money object: the standard format from OrderModel::getItem()
        if (is_object($amount) && method_exists($amount, 'getAmount')) {
            return (float) $amount->getAmount();
        }

        return (float) $amount;
    }

    /**
     * The ORDER's grand total, for the log's order_total column.
     *
     * Not the payment's amount: on an order paid in instalments those are
     * different numbers, and the column exists to record what the order was
     * worth when the action happened.
     *
     * The reader always answers with Money, so the old is_object/method_exists
     * dance around a column that might have arrived as a bare number is gone.
     *
     * @param Order $order The order.
     * @return float Order grand total, tax included
     * @since  1.0.0
     */
    private function getOrderTotal(Order $order): float
    {
        return (float) $order->total()->getAmount();
    }
}
