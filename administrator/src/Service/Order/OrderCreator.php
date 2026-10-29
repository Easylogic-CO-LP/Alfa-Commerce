<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Utilities\IpHelper;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * OrderCreator
 *
 * Session-free order writing: creates an order + its lines from EXPLICIT
 * data: no cart, no HTTP input, no live catalog pricing. This is the
 * canonical writer for every non-checkout order source:
 *
 *   - subscription renewals   - lines charged from the subscription's frozen
 *                               snapshot (a renewal must NEVER re-price
 *                               through the catalog)
 *   - admin manual orders     - lines the admin composed in the backend
 *   - checkout                - OrderPlacement builds the draft from the
 *                               cart's PriceResults and delegates here
 *
 * Scope is deliberately narrow: order row, order_items rows, optional stock
 * deduction, the order-created activity entry. NO emails, NO plugin events,
 * NO payment/shipment records: callers drive those explicitly afterwards
 * (Transition, payment plugins' onOrderAfterPlace, ...), because
 * each source wants different behaviour there.
 *
 * Transactions: by default create() runs inside its own transaction.
 * Callers that already hold one (checkout) pass manageTransaction: false.
 *
 * @since  2.0.0
 */
final class OrderCreator
{
    /**
     * ISO-4217 numeric fallback when the component has no default currency
     * configured (matches OrderPlacement::DEFAULT_CURRENCY_NUMBER).
     */
    private const DEFAULT_CURRENCY_NUMBER = 978;

    /**
     * Column width of `#__alfa_order_items`.`tax_name`.
     *
     * A single catalogue tax name cannot exceed this (the per-language name
     * column is VARCHAR(255)) but a combined name joined with ' + ' can.
     * Clamping there beats losing the sale to a label.
     *
     * @since  2.0.0
     */
    public const TAX_NAME_MAX = 255;

    /**
     * Create an order with its lines from a plain-data draft.
     *
     * Draft keys (all optional unless noted):
     *   id_user               int    Customer user id; 0 = guest.
     *   id_currency           int    Currency PK; 0/absent = component default.
     *   conversion_rate       float  Default 1.0.
     *   id_payment_method     int    0 = none.
     *   id_shipment_method    int    0 = none (virtual orders).
     *   payment_method_name   string Frozen label ('' = none).
     *   shipment_method_name  string Frozen label ('' = none).
     *   id_address_delivery   int    Existing #__alfa_user_info id (required).
     *   id_address_invoice    int    Existing #__alfa_user_info id (0 = none).
     *   id_order_status       int    0/absent = the is_initial status.
     *   id_language           int    Default 1.
     *   customer_note, note   string
     *   ip_address            string Default: current request IP.
     *   created_by            int    Default: current identity.
     *   source                string REQUIRED. Order origin, first-class on
     *                                the row AND the activity log: 'storefront',
     *                                'admin', 'renewal', 'api'. Presence is
     *                                enforced; the value is not validated.
     *   deduct_stock          bool   Default true; still gated by the status'
     *                                stock_operation (StockPolicy).
     *   allow_empty           bool   Default false. Explicitly permits a
     *                                metadata-only order with NO lines (admin
     *                                manual orders grow lines in the editor).
     *   lines                 array  REQUIRED non-empty unless allow_empty.
     *                                Each line:
     *     id_item (required), id_combination (null ok), quantity (required),
     *     name (frozen {lang:text} JSON string; absent = auto-frozen from the
     *     catalog via ItemFieldsSnapshot::freezeItemName),
     *     sku/gtin/mpn, weight,
     *     unit_price_tax_excl / unit_price_tax_incl (required),
     *     total_price_tax_excl / total_price_tax_incl (absent = unit x qty),
     *     original_product_price (absent = unit_price_tax_excl),
     *     tax_rate, tax_name, discount_percent,
     *     discount_amount_tax_excl / _incl, id_shipment_method.
     *
     * @param array $draft The order draft (see above).
     * @param bool $manageTransaction Wrap in a DB transaction (default true);
     *                                pass false when the caller already holds one.
     *
     * @return object {orderId: int, lineIds: int[], statusId: int} - lineIds in
     *                                                              draft order; statusId is the status the order LANDED in,
     *                                                              which is not always the one requested.
     *
     * @throws RuntimeException On invalid drafts or write failures (after
     *                          rollback when this call owns the transaction).
     *
     * @since  2.0.0
     */
    public static function create(array $draft, bool $manageTransaction = true): object
    {
        // WHO PLACED THIS ORDER IS NOT A DEFAULT. The column documents exactly
        // four origins (storefront | admin | renewal | api) and every real
        // caller passes one, but the two `?? 'service'` fallbacks this replaces
        // wrote an undocumented fifth word for any caller that forgot: 12,845
        // of them on the demo, indistinguishable afterwards from real sales.
        // PRESENCE only, never a vocabulary check: validating the value here
        // would fatal a live checkout the first time a plugin supplies a word
        // this list has not heard of.
        if (!isset($draft['source']) || trim((string) $draft['source']) === '') {
            throw new RuntimeException('OrderCreator: a draft must name its source (storefront | admin | renewal | api).');
        }

        $lines = $draft['lines'] ?? [];

        if (!\is_array($lines)) {
            throw new RuntimeException('OrderCreator: lines must be an array.');
        }

        // An EMPTY order is a deliberate act (admin manual orders start as a
        // metadata-only shell and grow lines in the editor); the caller must
        // say so explicitly; every other source keeps the guard.
        if ($lines === [] && empty($draft['allow_empty'])) {
            throw new RuntimeException('OrderCreator: a draft needs at least one line (or an explicit allow_empty).');
        }

        foreach ($lines as $i => $line) {
            if ((int) ($line['id_item'] ?? 0) <= 0 || (int) ($line['quantity'] ?? 0) <= 0) {
                throw new RuntimeException("OrderCreator: line {$i} needs id_item and a positive quantity.");
            }

            if (!isset($line['unit_price_tax_excl'], $line['unit_price_tax_incl'])) {
                throw new RuntimeException("OrderCreator: line {$i} needs explicit unit prices (the service never re-prices).");
            }
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $statusId = (int) ($draft['id_order_status'] ?? 0);

        // A caller-supplied status is not trusted: a deleted or unpublished id
        // would be written verbatim, and every later status lookup reads that
        // as "no status". Placement must not fail over a misconfigured
        // automation map either, so land the order in the initial status and
        // tell the operator rather than losing it.
        if ($statusId > 0 && !Transition::isValidTarget($statusId)) {
            Log::add(
                'OrderCreator: status ' . $statusId . ' does not exist or is not published; '
                . 'the order lands in the initial status instead.',
                Log::WARNING,
                'com_alfa.orders',
            );

            NotificationHelper::push(
                'orders.creator.badstatus.' . $statusId,
                NotificationText::of('COM_ALFA_NOTIFY_BAD_STATUS_TITLE', $statusId),
                [
                    'severity' => 'warning',
                    'group' => NotificationHelper::GROUP_ORDERS,
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_BAD_STATUS_MSG'),
                    'url' => 'index.php?option=com_alfa&view=orderstatuses',
                    // The fix is in the status configuration; the link opens its list.
                    'view' => ['action' => 'alfa.orderstatuses.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orderstatuses.view', 'asset' => 'com_alfa'],
                ],
            );

            $statusId = 0;
        } elseif ($statusId > 0) {
            // The status is valid, so any standing "missing or unpublished"
            // warning for it is answered: it was republished, or recreated.
            // Nothing else can retract it: the alert names a status id, and a
            // status that is back is exactly the id being validated here.
            // exists() first, so the healthy path costs one indexed read.
            try {
                $badStatusKey = 'orders.creator.badstatus.' . $statusId;

                if (NotificationHelper::exists(dedupKey: $badStatusKey)) {
                    NotificationHelper::clear(dedupKey: $badStatusKey);
                }
            } catch (Throwable $clearError) {
                // advisory only: placement never fails over a notification, unless it ended the placement's
                // transaction
                if (TransactionState::endedBy(db: $db, failure: $clearError)) {
                    throw $clearError;
                }
            }
        }

        if ($statusId <= 0) {
            $statusId = (int) OrderStatusHelper::getInitialId();
        }

        if ($statusId <= 0) {
            throw new RuntimeException('OrderCreator: no target status and no is_initial status exists.');
        }

        // A status that requires payment cannot be satisfied at creation: no
        // order exists yet, so nothing has been paid. Entering it by CREATING
        // into it would be a way around the gate that refuses the same status
        // on a transition, so it obeys the same rule: allowed only where the
        // status permits the exception, and then recorded as one.
        $unpaidExceptionAtCreation = false;

        if (self::statusRequiresPayment($statusId)) {
            if (!self::mayCreateInto($statusId)) {
                throw new RuntimeException(
                    'OrderCreator: status ' . $statusId . ' requires payment, which a new order cannot have.',
                );
            }

            $unpaidExceptionAtCreation = true;
        }

        if ($manageTransaction) {
            $db->transactionStart(true);
        }

        try {
            $orderId = self::insertOrder(db: $db, draft: $draft, statusId: $statusId);
            // The lines' money is the order's, at the scale the order froze.
            $currency = OrderCurrency::of(orderId: $orderId);
            $lineIds = [];

            foreach ($lines as $line) {
                $lineIds[] = self::insertLine(db: $db, orderId: $orderId, draft: $draft, line: $line, currency: $currency);
            }

            // Whether each line's stock identity tracks stock now is recorded
            // with the line, before any claim and whatever the landing status
            // claims: it decides later whether the line's fulfilment deducts
            // stock. A failure fails the placement.
            InventoryBridge::recordPlacement(orderId: $orderId, lineIds: $lineIds);

            // Stock: placement claims salable quantity (reservations,
            // policy-gated); the landing status' stock_operation decides
            // whether fulfilment converts them to physical deductions right
            // away. Non-fatal by contract.
            if ($draft['deduct_stock'] ?? true) {
                // A CLAIM THAT FAILS FAILS THE PLACEMENT.
                //
                // This step used to be wrapped in a catch-all that logged a
                // WARNING and carried on, under a comment calling it
                // "non-fatal by contract". The consequence is not non-fatal:
                // the landing status is one that claims stock, so every reader
                // afterwards (the catalogue ceiling, the reconciliation, the
                // fulfilment screens) believes this order holds units it
                // never took. That is an oversell created at the moment of
                // sale, and it is invisible until someone else's order cannot
                // be filled.
                //
                // The swallow was also load-bearing in a worse way. A deadlock
                // here does not throw an ordinary error: InnoDB rolls back the
                // WHOLE transaction, which is the order row and its lines. The
                // catch hid that, and the code then wrote the creation audit
                // and called commit() for a transaction that no longer
                // existed, leaving checkout to take payment, send email and
                // clear the cart for an order id that had ceased to exist.
                //
                // Letting it propagate reaches the outer catch, which rolls
                // back and throws, and the caller reports a failed placement
                // instead of a phantom order.
                //
                // What the landing status lets this order claim: the same
                // authority the status transition and the line sync use.
                // Reserving unconditionally put a hold on orders placed
                // straight into a cancelled status, and nothing ever took
                // it off again because no transition follows a placement.
                $claim = StockPolicy::claimFor($statusId);

                if ($claim !== StockPolicy::CLAIM_NONE) {
                    InventoryBridge::reserveOrder($orderId);

                    if ($claim === StockPolicy::CLAIM_PHYSICAL) {
                        InventoryBridge::commitOrder($orderId);
                    }
                }
            }

            $draft['__unpaid_exception'] = $unpaidExceptionAtCreation;
            self::logCreation(db: $db, orderId: $orderId, statusId: $statusId, draft: $draft);

            if ($manageTransaction) {
                TransactionState::commitLevel(db: $db);
            }
        } catch (Throwable $e) {
            // \Throwable, not Exception: a PHP \Error must roll back too, or
            // the connection is left inside a dangling transaction.
            if ($manageTransaction) {
                // A deadlock has ALREADY rolled this transaction back, and
                // asking again is an error in its own right. The original
                // failure is the one the caller needs; losing it to the
                // cleanup would replace a diagnosable message with a
                // confusing one. The close also ends what the driver still
                // counts as open, so the request's next transaction is real.
                TransactionState::rollBackLevel(db: $db, failure: $e);
            }

            throw new RuntimeException('OrderCreator: order creation failed: ' . $e->getMessage(), 0, $e);
        }

        // statusId is part of the contract: a caller whose requested status was
        // discarded must be able to see where the order actually landed rather
        // than assume it is already in the status it asked for.
        return (object) [
            'orderId' => $orderId,
            'lineIds' => $lineIds,
            'statusId' => $statusId,
        ];
    }

    /**
     * May a NEW order be created directly into this status?
     *
     * The rule, asked once and answered here: a status that requires payment
     * cannot be satisfied by an order that does not exist yet, so creating into
     * it is refused, unless the status itself permits the unpaid exception, in
     * which case it is allowed and recorded as one.
     *
     * PUBLIC BECAUSE THE OPERATOR IS OFFERED A CHOICE. The builder lists the
     * statuses an order may land in, and it must list exactly the ones this
     * class would accept, asking the same question rather than restating the
     * rule, so a screen can never offer a status that creation then refuses.
     *
     * @param int $statusId Status PK.
     *
     *
     * @since  2.0.0
     */
    public static function mayCreateInto(int $statusId): bool
    {
        if (!self::statusRequiresPayment($statusId)) {
            return true;
        }

        return self::statusAllowsUnpaidException($statusId);
    }

    /**
     * Whether entering this status requires the order to be paid.
     *
     * @param int $statusId Status PK.
     *
     *
     * @since  2.0.0
     */
    private static function statusRequiresPayment(int $statusId): bool
    {
        return self::statusFlag($statusId, 'requires_paid');
    }

    /**
     * Whether this status lets an unpaid order in as a recorded exception.
     *
     * @param int $statusId Status PK.
     *
     *
     * @since  2.0.0
     */
    private static function statusAllowsUnpaidException(int $statusId): bool
    {
        return self::statusFlag($statusId, 'allow_unpaid_exception');
    }

    /**
     * Read one gate flag off a status.
     *
     * @param int $statusId Status PK.
     * @param string $column Flag column.
     *
     *
     * @since  2.0.0
     */
    private static function statusFlag(int $statusId, string $column): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName($column))
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->where($db->quoteName('id') . ' = ' . $statusId),
        );

        return (int) $db->loadResult() === 1;
    }

    /**
     * Insert the `#__alfa_orders` row from the draft.
     *
     * @param object $db Database driver.
     * @param array $draft The order draft.
     * @param int $statusId Resolved landing status.
     *
     * @return int New order PK.
     *
     * @since  2.0.0
     */
    private static function insertOrder(object $db, array $draft, int $statusId): int
    {
        $now = Factory::getDate('now', 'UTC')->toSql();
        $userId = (int) ($draft['id_user'] ?? 0);
        $createdBy = (int) ($draft['created_by'] ?? self::currentUserId());

        $currencyId = (int) ($draft['id_currency'] ?? 0);

        if ($currencyId <= 0) {
            $currencyId = self::defaultCurrencyId(db: $db);
        }

        $order = new stdClass();
        $order->id_user = $userId;

        // CUSTOMER SNAPSHOT: who placed the order, written once, here. The
        // draft may carry it (the checkout knows what the guest typed);
        // otherwise a member is read from the account and a guest from the
        // delivery record the draft points at.
        $snapshot = self::customerSnapshot(db: $db, draft: $draft, userId: $userId);
        $order->customer_name = mb_substr($snapshot['customer_name'], 0, 400);
        $order->customer_email = mb_substr($snapshot['customer_email'], 0, 254);
        $order->customer_is_guest = (int) $snapshot['customer_is_guest'];

        if (!empty($snapshot['account_missing'])) {
            $order->id_user = null;
        }

        $order->id_currency = $currencyId;

        // THE SCALE THIS ORDER'S MONEY MEANS, FROZEN HERE AND NEVER AGAIN.
        //
        // The currency TABLE is configuration for NEW monetary facts; it is not
        // authority over facts already made. Without this column, editing a
        // currency from two decimal places to none turned an order owed 10.40
        // with 10.36 collected from `partial` into `paid` (measured) with no
        // order row touched and no writer run. That drove status transitions
        // and document auto-issue, so a display preference was a fiscal event.
        //
        // Write-once: nothing may change it afterwards, and there is no runtime
        // fallback to the live row for a record that lacks it.
        $order->currency_scale = Currency::loadById($currencyId)->getDecimalPlaces();
        $order->conversion_rate = (float) ($draft['conversion_rate'] ?? 1.0);
        $order->id_address_delivery = (int) ($draft['id_address_delivery'] ?? 0) ?: null;
        $order->id_address_invoice = (int) ($draft['id_address_invoice'] ?? 0) ?: null;
        $order->id_payment_method = (int) ($draft['id_payment_method'] ?? 0);
        $order->id_shipment_method = (int) ($draft['id_shipment_method'] ?? 0);
        $order->id_order_status = $statusId;
        $order->payment_method_name = (string) ($draft['payment_method_name'] ?? '');
        $order->shipment_method_name = (string) ($draft['shipment_method_name'] ?? '');
        $order->payment_method_type = (string) ($draft['payment_method_type'] ?? '');
        $order->shipment_method_type = (string) ($draft['shipment_method_type'] ?? '');
        $order->id_payment_currency = $currencyId;
        $order->id_language = (int) ($draft['id_language'] ?? 1);
        $order->id_coupon = null;
        $order->code_coupon = null;

        // Guest order-tracking key: same contract as checkout placement.
        $order->secure_key = bin2hex(random_bytes(16));

        $order->ip_address = (string) ($draft['ip_address'] ?? IpHelper::getIp());
        $order->customer_note = (string) ($draft['customer_note'] ?? '');
        $order->note = (string) ($draft['note'] ?? '');
        $order->source = (string) $draft['source'];
        $order->state = 1;
        $order->checked_out = 0;
        $order->checked_out_time = $db->getNullDate();
        $order->created = $now;
        $order->modified = $now;
        $order->created_by = $createdBy;
        $order->modified_by = $createdBy;

        $db->insertObject('#__alfa_orders', $order, 'id');

        return (int) $order->id;
    }

    /**
     * Insert one `#__alfa_order_items` line from explicit draft data.
     *
     * Prices are taken AS GIVEN: the whole point of this service is that
     * renewals/admin orders write frozen amounts, never recomputed ones.
     *
     * EVERY FIGURE IS HANDED TO ITS COLUMN AS ITS EXACT DECIMAL TEXT
     * ({@see StoredDecimal::of()}), never as a float, which a database driver
     * prints with fourteen significant digits. A whole-line figure is the
     * order's Money; a unit or a rate is held at its eighth place. A line built
     * by OrderLineMoney states each as that text already; a number is read
     * once, a whole-line one as Money reads it.
     *
     * @param object $db Database driver.
     * @param int $orderId Parent order PK.
     * @param array $draft The order draft (for line-level defaults).
     * @param array $line One draft line.
     * @param Currency $currency The order's currency, at its frozen scale.
     *
     * @return int New order-item PK.
     *
     * @since  2.0.0
     */
    private static function insertLine(object $db, int $orderId, array $draft, array $line, Currency $currency): int
    {
        $quantity = (int) $line['quantity'];
        $money = static fn (string|int|float $value): string => StoredDecimal::of(Money::of($value, $currency));
        $unit = static fn (string|int|float $value): string => StoredDecimal::of(ExactAmount::ofNumber($value)->roundedAt(Currency::SCALE_MAX));
        $unitExcl = $line['unit_price_tax_excl'];
        $unitIncl = $line['unit_price_tax_incl'];
        $combinationId = (int) ($line['id_combination'] ?? 0) ?: null;

        $name = (string) ($line['name'] ?? '');

        if ($name === '') {
            $name = json_encode(
                ItemFieldsSnapshot::freezeItemName((int) $line['id_item'], $combinationId),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        $row = new stdClass();
        $row->id_item = (int) $line['id_item'];
        $row->id_order = $orderId;
        $row->id_combination = $combinationId;
        $row->id_shipment_method = (int) ($line['id_shipment_method'] ?? ($draft['id_shipment_method'] ?? 0));
        $row->name = $name;
        $row->sku = $line['sku'] ?? null;
        $row->gtin = $line['gtin'] ?? null;
        $row->mpn = $line['mpn'] ?? null;
        $row->weight = (float) ($line['weight'] ?? 0);
        $row->quantity = $quantity;
        $row->unit_price_tax_excl = $unit($unitExcl);
        $row->unit_price_tax_incl = $unit($unitIncl);

        // THE FALLBACK SURVIVES HERE, AND ONLY HERE, AND IT IS NOT THE
        // AUTHORITY (Item 3).
        //
        // A caller that carries a PriceResult now states the whole line
        // explicitly, and the authority columns below record that it did. A
        // caller that carries NO PriceResult (a subscription renewal drafted
        // from stored columns, a fixture stating a unit price and a quantity)
        // is stating "this many, at this price", and unit x quantity is what
        // that means. Writing 0 instead would not be more truthful, it would be
        // a different falsehood, and it would empty every such order.
        //
        // What makes this safe is that such a row gets NULL authority below.
        // A rounded unit must never REBUILD an amount that already
        // exists; here no whole-line amount exists, and the row says so.
        $row->total_price_tax_excl = $money($line['total_price_tax_excl'] ?? ExactAmount::ofNumber($unitExcl)->times($quantity)->text());
        $row->total_price_tax_incl = $money($line['total_price_tax_incl'] ?? ExactAmount::ofNumber($unitIncl)->times($quantity)->text());
        $row->original_product_price = $unit($line['original_product_price'] ?? $unitExcl);

        // THE AUTHORITATIVE WHOLE-LINE FACTS. NULL when the caller carries no
        // PriceResult (a renewal drafted from stored columns, say) because
        // NULL means "this row has no Item 3 authority" and 0.00 means "the
        // authority says zero". Writing 0 for the first would be fabricating
        // financial truth, and no consumer could tell it from the second.
        // The custom price travels with its own whole line (custom_total_tax_*,
        // lane CT): a placed custom-priced line keeps what a manual discount
        // on it must later be taken off.
        foreach ([
            'base_total_tax_excl',
            'base_total_tax_incl',
            'reference_total_tax_excl',
            'reference_total_tax_incl',
            'custom_unit_price_tax_incl',
            'custom_total_tax_excl',
            'custom_total_tax_incl',
        ] as $authority) {
            $row->{$authority} = !isset($line[$authority]) ? null : ($authority === 'custom_unit_price_tax_incl' ? $unit($line[$authority]) : $money($line[$authority]));
        }
        // What a piece is paid before any discount, as the engine stated it: a
        // later quantity edit on the line's own terms is judged by it. Kept as
        // text, so its eight decimals are written as stated; NULL when the
        // caller carries no PriceResult.
        $row->payable_unit = isset($line['payable_unit']) ? $unit($line['payable_unit']) : null;
        $row->tax_rate = (float) ($line['tax_rate'] ?? 0);
        $row->tax_name = self::clampTaxName(
            name:    (string) ($line['tax_name'] ?? ''),
            context: 'order ' . $orderId . ', item ' . $row->id_item,
        );
        $row->discount_percent = (float) ($line['discount_percent'] ?? 0);
        $row->discount_amount_tax_excl = $money($line['discount_amount_tax_excl'] ?? 0);
        $row->discount_amount_tax_incl = $money($line['discount_amount_tax_incl'] ?? 0);

        $db->insertObject('#__alfa_order_items', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Fit an applied-tax name to the order line's column, and say so when it did not fit.
     *
     * Combined taxes are named by joining every additive rate with ' + '
     * (PriceComputationEngine::processTaxes()), so a shop with several combined
     * rates can produce a label no column holds. Strict mode rejects the whole
     * INSERT, which rolled the entire checkout back: the shopper lost the sale
     * to a label. The name is a snapshot, so it is cut only when it genuinely
     * cannot fit, and the cut is logged with enough of the original to identify
     * the rule that produced it. Log only, never enqueueMessage(): a clamped
     * label is not something to show a shopper at checkout.
     *
     * @param string $name The applied tax name as the pricing layer produced it.
     * @param string $context Where the line is being written, for the log.
     *
     * @return string The name as it will be stored.
     *
     * @since  2.0.0
     */
    public static function clampTaxName(string $name, string $context): string
    {
        $length = mb_strlen($name);

        if ($length <= self::TAX_NAME_MAX) {
            return $name;
        }

        Log::add(
            entry:    'Tax name was ' . $length . ' characters, longer than order_items.tax_name holds ('
                      . self::TAX_NAME_MAX . '), stored clamped so the sale survives (' . $context
                      . '). Starts with: ' . mb_substr($name, 0, 60) . '...',
            priority: Log::WARNING,
            category: 'com_alfa.orders',
        );

        return mb_substr($name, 0, self::TAX_NAME_MAX);
    }

    /**
     * The customer snapshot for a new order.
     *
     * @param object $db Database driver.
     * @param array $draft The order draft (may carry customer_name / customer_email).
     * @param int $userId The order's account id (0 = guest).
     *
     * @return array{customer_name: string, customer_email: string, customer_is_guest: int}
     *
     * @since  2.0.0
     */
    private static function customerSnapshot(object $db, array $draft, int $userId): array
    {
        if (isset($draft['customer_name']) || isset($draft['customer_email'])) {
            return [
                'customer_name' => trim((string) ($draft['customer_name'] ?? '')),
                'customer_email' => trim((string) ($draft['customer_email'] ?? '')),
                'customer_is_guest' => $userId > 0 ? 0 : 1,
            ];
        }

        if ($userId > 0) {
            $snapshot = OrderIdentity::snapshotFromUser($userId);

            if ($snapshot['customer_name'] !== '' || $snapshot['customer_email'] !== '') {
                return $snapshot;
            }

            // The account is gone (a renewal after the customer deleted it):
            // the delivery record is what is left of the identity, and the
            // order must not point at an id a future account could inherit.
            Log::add('Order draft for user #' . $userId . ' whose account no longer exists; identity taken from the delivery record.', Log::WARNING, 'com_alfa.orders');
        }

        $deliveryId = (int) ($draft['id_address_delivery'] ?? 0);
        $record = null;

        if ($deliveryId > 0) {
            $record = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_user_info'))
                    ->where($db->quoteName('id') . ' = ' . $deliveryId),
            )->loadObject() ?: null;
        }

        $snapshot = OrderIdentity::snapshotFromDetails($record);

        if ($userId > 0) {
            $snapshot['customer_is_guest'] = 0;
            $snapshot['account_missing'] = true;
        }

        return $snapshot;
    }

    /**
     * Write the order-created activity entry (same shape as checkout's).
     *
     * @param object $db Database driver (unused; the shared writer owns
     *                   its own connection).
     * @param int $orderId New order PK.
     * @param int $statusId Landing status.
     * @param array $draft The order draft (source tag, actor).
     *
     * @since  2.0.0
     */
    private static function logCreation(object $db, int $orderId, int $statusId, array $draft): void
    {
        // Through the shared writer: bounded fields, one severity policy and
        // one place that knows what a lost audit record means. Strict, because
        // creation is still inside its transaction and can roll back whole.
        if (!empty($draft['__unpaid_exception'])) {
            OrderModel::logOrderActivityStrict(
                $orderId,
                'order.unpaid_exception',
                'Created directly in a status that requires payment',
                ['status' => $statusId],
            );
        }

        OrderModel::logOrderActivityStrict(
            $orderId,
            'order.created',
            'Order created',
            [
                'source' => (string) $draft['source'],
                'initial_status' => $statusId,
                'actor_name' => (string) ($draft['actor_name'] ?? 'System'),
            ],
        );
    }

    /**
     * Component default currency id (ISO number param -> currency PK).
     *
     * @param object $db Database driver.
     *
     * @throws RuntimeException When the configured currency does not exist.
     *
     * @since  2.0.0
     */
    private static function defaultCurrencyId(object $db): int
    {
        $number = (int) ComponentHelper::getParams('com_alfa')
            ->get('default_currency', self::DEFAULT_CURRENCY_NUMBER);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_currencies'))
            ->where($db->quoteName('number') . ' = :number')
            ->bind(':number', $number);

        $db->setQuery(query: $query);
        $currencyId = (int) $db->loadResult();

        if ($currencyId <= 0) {
            throw new RuntimeException('OrderCreator: default currency (' . $number . ') not found.');
        }

        return $currencyId;
    }

    /**
     * Current identity id, tolerant of console contexts.
     *
     * @since  2.0.0
     */
    private static function currentUserId(): int
    {
        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
