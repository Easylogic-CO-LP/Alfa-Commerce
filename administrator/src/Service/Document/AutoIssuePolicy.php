<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Throwable;

/**
 * AutoIssuePolicy: the status-flag consumer.
 *
 * Runs as a CORE side-effect inside Transition's terminal
 * region: when an order enters a status flagged with issues_doc_type /
 * issues_delivery_note, it issues the document with FULL REMAINING
 * quantities on the type's default series.
 *
 * Contract (locked): NEVER blocks or fails the transition. Missing data
 * (a required buyer tax ID, no default series, nothing left to issue after
 * manual partials) -> skip, and where the admin must act, push a backend
 * notification. Inert while documents_issuer = external.
 *
 * @since  2.0.0
 */
final class AutoIssuePolicy
{
    /**
     * Dedup-key namespace for auto-issue alerts: PREFIX . orderId . '.' . reason.
     * Order-first so {@see self::clearFor()} can retire an order's alerts with
     * one prefix read, whatever the reason or document type.
     *
     * @var string
     * @since  2.0.0
     */
    private const KEY_PREFIX = 'documents.autoissue.';

    /**
     * React to an order entering a new status.
     *
     * @param int $orderId The order id.
     * @param int $newStatusId The status just entered.
     *
     * @return void Never throws: log-and-continue by contract.
     *
     * @since  2.0.0
     */
    public static function handleTransition(int $orderId, int $newStatusId): void
    {
        try {
            if (!DocumentHelper::isIssuerEnabled()) {
                return;
            }

            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select($db->quoteName(['issues_doc_rules', 'issues_delivery_note', 'documents_require_paid']))
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->where($db->quoteName('id') . ' = :statusId')
                ->bind(':statusId', $newStatusId);
            $db->setQuery(query: $query);
            $flags = $db->loadObject();

            if ($flags === null) {
                return;
            }

            // A fiscal document must not be issued merely because an admin
            // picked a completed-looking status. Skip and log; issuing never
            // blocks the transition.
            if ((int) ($flags->documents_require_paid ?? 0) === 1) {
                $facts = OrderFacts::forOrder($orderId);

                // The SAME payment fact the status gate reads. A tolerance here
                // would be a second opinion about what "paid" means, and the
                // one that used to be here admitted a thousand minor units at
                // scale 8, the exact thing the comment above says must not
                // happen.
                if (!$facts->is_paid) {
                    Log::add(
                        entry:    "AutoIssuePolicy: order {$orderId} -> status {$newStatusId}: skipped, "
                                  . 'the status requires payment and '
                                  . number_format($facts->balance, (int) $facts->currency_scale) . ' is still due.',
                        priority: Log::WARNING,
                        category: 'com_alfa.documents',
                    );

                    return;
                }
            }

            // Sales document: the status' rule rows decide per order.
            // No rows / no matching row = deliberately no document.
            $resolved = self::resolveRules(orderId: $orderId, rulesJson: (string) ($flags->issues_doc_rules ?? ''));

            if ($resolved !== null) {
                self::issueGuarded(orderId: $orderId, docType: $resolved['doc_type'], seriesId: $resolved['id_series']);
            }

            if ((int) ($flags->issues_delivery_note ?? 0) === 1) {
                self::issueGuarded(orderId: $orderId, docType: DocumentHelper::TYPE_DELIVERY_NOTE);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    "AutoIssuePolicy: order {$orderId} -> status {$newStatusId}: "
                          . $e->getMessage() . "\n" . $e->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa.documents',
            );

            // Same visibility contract as the no-series/missing-VAT skips: a
            // failed issuance (e.g. lost a race to a concurrent manual
            // issue) leaves the order undocumented; a human must follow up.
            self::notify(
                orderId: $orderId,
                key:     self::KEY_PREFIX . $orderId . '.failed',
                title:   "Order #{$orderId}: automatic document issuance failed. Issue it manually from the order.",
            );
        }
    }

    /**
     * Re-run auto-issue for the status the order is already in.
     *
     * A status that issues only when paid skips at transition time while money
     * is due, and the order never transitions again: the target is the status
     * it already holds. Settling the balance is the second chance. Issuance is
     * idempotent (nothing outstanding is a quiet no-op), so a repeat call
     * cannot double-issue.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    public static function retryAfterPayment(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_order_status'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        );
        $statusId = (int) $db->loadResult();

        if ($statusId > 0) {
            self::handleTransition(orderId: $orderId, newStatusId: $statusId);
        }
    }

    /**
     * The sales types the shop's automatic-issue rules choose for an order's buyer.
     *
     * The rules of every published order status, each read for this order
     * exactly as when it enters that status: the first row whose groups its
     * customer holds, or the catch-all row; a row that pins a series names
     * that series' type. A status whose rows choose nothing for this buyer
     * adds nothing, and an unpublished status is never entered, so its rows
     * choose nothing. A correction that adds to an order with no sales
     * document takes the one type they choose; none, or two that disagree,
     * is the caller's to refuse.
     *
     * @param int $orderId The order id.
     *
     * @return string[] The distinct type aliases, in status order.
     *
     * @since  2.0.0
     */
    public static function salesTypesFor(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('issues_doc_rules'))
            ->from($db->quoteName('#__alfa_orders_statuses'))
            ->where([
                $db->quoteName('state') . ' = 1',
                'COALESCE(' . $db->quoteName('issues_doc_rules') . ", '') <> ''",
            ])
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $db->setQuery(query: $query);

        $types = [];

        foreach ($db->loadColumn() ?: [] as $rulesJson) {
            $resolved = self::resolveRules(orderId: $orderId, rulesJson: (string) $rulesJson);

            if ($resolved !== null && !\in_array($resolved['doc_type'], $types, true)) {
                $types[] = $resolved['doc_type'];
            }
        }

        return $types;
    }

    /**
     * Resolve the status' issuance rule rows for one order.
     *
     * Rows ([{usergroups: [], series: '<id>'|'<sales type>'}]) are
     * evaluated top-down; the first matching row wins. A row matches when
     * the order's customer holds ANY of its groups, or unconditionally when
     * its group list is empty (the catch-all row). The customer's groups are
     * the ones the order is priced with: the account's own and every group
     * above them, and the guest group's where the order has no customer
     * account, so a row limited to Guest or to Public matches a guest order.
     *
     * The `series` value implies the document: a numeric id pins that
     * series (its doc_type is the document; unpublished -> same type's
     * default), a sales-type slug follows the type's default series.
     *
     * @param int $orderId The order id.
     * @param string $rulesJson The status' issues_doc_rules JSON.
     *
     * @return array|null ['doc_type' => string, 'id_series' => int] of the
     *                    winning row (id_series 0 = type default), or null
     *                    when no row matches; deliberately no document.
     *
     * @since  2.0.0
     */
    private static function resolveRules(int $orderId, string $rulesJson): ?array
    {
        $rules = json_decode($rulesJson, true);

        if (!\is_array($rules) || $rules === []) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('id_user'))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :orderId')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);
        $userId = (int) $db->loadResult();

        // A member of a group under a configured one counts, and an order with
        // no customer account is the guest group's.
        $memberOf = PriceContext::customerGroups(customer: new User(identifier: $userId));

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $groups = array_map('intval', (array) ($rule['usergroups'] ?? []));

            if ($groups !== [] && array_intersect($groups, $memberOf) === []) {
                continue;
            }

            $target = self::resolveSeriesTarget(target: (string) ($rule['series'] ?? ''));

            if ($target !== null) {
                return $target;
            }

            // A broken row (deleted series / bad value) falls through to
            // the next rule rather than silently issuing nothing.
        }

        return null;
    }

    /**
     * Turn a rule's series value into the document to issue.
     *
     * @param string $target Numeric series id, or a sales-type slug for
     *                       "that type's default series".
     *
     * @return array|null ['doc_type' => string, 'id_series' => int]
     *                    (id_series 0 = type default), or null when the
     *                    value no longer resolves.
     *
     * @since  2.0.0
     */
    private static function resolveSeriesTarget(string $target): ?array
    {
        if (\in_array($target, DocumentHelper::salesTypes(), true)) {
            return ['doc_type' => $target, 'id_series' => 0];
        }

        if (!ctype_digit($target) || (int) $target <= 0) {
            return null;
        }

        $seriesId = (int) $target;
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['doc_type', 'state']))
            ->from($db->quoteName('#__alfa_document_series'))
            ->where($db->quoteName('id') . ' = :seriesId')
            ->bind(':seriesId', $seriesId);
        $db->setQuery(query: $query);
        $series = $db->loadObject();

        if ($series === null || !\in_array((string) $series->doc_type, DocumentHelper::salesTypes(), true)) {
            return null;
        }

        // A retired (unpublished) pinned series degrades to the same
        // type's default instead of failing the issuance.
        return [
            'doc_type' => (string) $series->doc_type,
            'id_series' => (int) $series->state === 1 ? $seriesId : 0,
        ];
    }

    /**
     * Issue one document type with full remaining quantities, or skip with
     * an admin notification when a human is needed.
     *
     * @param int $orderId The order id.
     * @param string $docType The document type to issue.
     * @param int $seriesId Explicit series (rule rows); 0 = type default.
     *
     * @since  2.0.0
     */
    /**
     * Issue ONE document, and keep its failure to itself.
     *
     * issues_doc_rules and issues_delivery_note are independent switches
     * governing independent documents. They used to share a single try in
     * handleTransition(), so a throw from the sales issuance (a lost race
     * against a concurrent manual issue is the case the outer catch itself
     * names) jumped past the delivery note, which was never attempted even
     * though nothing about it had failed. The order was then left with one
     * alert that named no document, so nothing told the operator a second,
     * unrelated document was missing too; and with a dedup key of
     * "<orderId>.failed", a second failure collapsed into the first.
     *
     * The key carries the doc type for the same reason the skips already do
     * (".noseries.<docType>", ".charges.<docType>"): the operator has to know
     * WHICH document to go and issue. clearFor() retires them all by order
     * prefix, so the extra segment costs nothing.
     *
     * Still never throws: the locked contract is that auto-issue cannot fail
     * a transition.
     *
     * @param int $orderId Order PK.
     * @param string $docType The document to issue.
     * @param int $seriesId Pinned series, 0 = the type's default.
     *
     *
     * @since  2.0.0
     */
    private static function issueGuarded(int $orderId, string $docType, int $seriesId = 0): void
    {
        try {
            self::issueFor(orderId: $orderId, docType: $docType, seriesId: $seriesId);
        } catch (Throwable $e) {
            Log::add(
                entry:    "AutoIssuePolicy: order {$orderId}: {$docType} could not be issued: "
                          . $e->getMessage() . "\n" . $e->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa.documents',
            );

            self::notify(
                orderId: $orderId,
                key:     self::KEY_PREFIX . $orderId . '.failed.' . $docType,
                title:   "Order #{$orderId}: {$docType} was not issued automatically. Issue it manually from the order.",
            );
        }
    }

    private static function issueFor(int $orderId, string $docType, int $seriesId = 0): void
    {
        // Every line's whole remainder, at its exact remaining frozen amount.
        // Nothing left to issue (manual partials covered it) = quiet no-op.
        $lines = OrderItemLines::buildSalesLines(
            orderId:  $orderId,
            docType:  $docType,
            picks:    null,
            language: DocumentHelper::defaultLanguageTag(),
        );

        if ($lines === []) {
            // Items fully documented, but unclaimed charges (shipping the
            // operator unticked, a fee added late) would otherwise be
            // stranded SILENTLY: no future auto-issue sweeps them and the
            // issued documents total less than the customer paid. A human
            // must decide, so say so.
            $charges = OrderChargeLines::remaining(orderId: $orderId);
            $stranded = 0.0;

            foreach (array_merge($charges->shipping, $charges->fees) as $charge) {
                $stranded += (float) $charge->remaining_incl;
            }

            // One minor unit of unclaimed shipping or fees is money, and it is
            // money in the ORDER'S currency: 0.01 was one unit at two decimals
            // and a million at eight. Resolve the currency the way OrderFacts
            // does and let Money answer: here and in the figure the merchant
            // is shown.
            $currency = OrderItemLines::orderCurrency(orderId: $orderId);
            $strandedMoney = Money::of($stranded, $currency);

            if ($strandedMoney->isPositive()) {
                self::notify(
                    orderId: $orderId,
                    key:     self::KEY_PREFIX . $orderId . '.charges.' . $docType,
                    title:   sprintf(
                        'Order #%d: %s of shipping/fees is not on any document. The items are fully documented, so issue it manually from the order.',
                        $orderId,
                        number_format($strandedMoney->getAmount(), $currency->getDecimalPlaces()),
                    ),
                );
            }

            return;
        }

        // The order's non-item money (shipping, fees, cart-rule discounts)
        // rides the same draft, each charge claimed from its remaining
        // balance. Without these the document totals MORE than the customer
        // paid. Full-remaining issuance is by definition the final item
        // draft, so discounts claim their whole remaining balance.
        $lines = array_merge($lines, OrderChargeLines::buildLines(
            orderId:       $orderId,
            docType:       $docType,
            itemLines:     $lines,
            picks:         null,
            finalForItems: true,
        ));

        if ($seriesId <= 0 && DocumentHelper::defaultSeriesId($docType) === null) {
            self::notify(
                orderId: $orderId,
                key:     self::KEY_PREFIX . $orderId . '.noseries.' . $docType,
                title:   "Order #{$orderId}: cannot auto-issue {$docType}; no default series exists.",
            );

            return;
        }

        $buyer = self::buyerFromOrder(orderId: $orderId);

        if (DocumentHelper::requiresBuyerVat($docType) && $buyer['buyer_vat_number'] === '') {
            // Invoices need a human: the tax ID is entered in the issue modal.
            self::notify(
                orderId: $orderId,
                key:     self::KEY_PREFIX . $orderId . '.vat',
                title:   "Order #{$orderId}: invoice not auto-issued; the buyer tax/VAT number is missing. Issue it manually from the order.",
            );

            return;
        }

        $draft = array_merge($buyer, [
            'doc_type' => $docType,
            'id_order' => $orderId,
            'id_series' => $seriesId,
            'lines' => $lines,
        ]);

        if ($docType === DocumentHelper::TYPE_DELIVERY_NOTE) {
            // Movement purpose defaults to "sale"; richer movement detail
            // belongs to the fulfilment flow.
            $draft['movement_purpose'] = '1';
        }

        DocumentIssuer::issue($draft);
    }

    /**
     * Best-available buyer block for the order: the invoicee when the order
     * invoices to details of its own, else the customer who placed it
     * (OrderIdentity, guests included).
     *
     * A tax ID is never derivable today (no checkout field); when the
     * configuration requires one, invoices go through the manual issue
     * modal instead; receipts always auto-issue.
     *
     * @param int $orderId The order id.
     *
     * @return array buyer_* draft keys.
     *
     * @since  2.0.0
     */
    private static function buyerFromOrder(int $orderId): array
    {
        $order = OrderIdentity::loadOrder($orderId);
        $buyer = $order !== null
            ? OrderIdentity::buyer($order)
            : ['buyer_name' => '', 'buyer_email' => ''];

        return $buyer + ['buyer_vat_number' => ''];
    }

    /**
     * Retire every auto-issue alert standing against an order: the document
     * it was waiting for now exists, so none of the reasons (failed, no
     * series, missing VAT, undocumented charges) can still be true.
     *
     * The namespace owner exposes this rather than letting callers build keys:
     * the order-first grammar is an implementation detail of this class, and
     * one prefix sweep retires every reason without enumerating doc types.
     *
     * Archived, not deleted: the history of what needed a human is kept.
     * Advisory: never breaks issuance.
     *
     * @param int $orderId The order whose document was just issued.
     *
     * @since  2.0.0
     */
    public static function clearFor(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            foreach (NotificationHelper::keys(prefix: self::KEY_PREFIX . $orderId . '.') as $key) {
                NotificationHelper::clear(dedupKey: (string) $key);
            }
        } catch (Throwable $clearError) {
            // advisory only
        }
    }

    /**
     * Push a deduplicated backend notification pointing at the order.
     *
     * @param int $orderId The order id.
     * @param string $key Dedup key.
     * @param string $title Notification title.
     *
     * @since  2.0.0
     */
    private static function notify(int $orderId, string $key, string $title): void
    {
        NotificationHelper::push($key, $title, [
            'severity' => 'warning',
            'group' => 'Documents',
            'message' => 'Automatic document issuance was skipped and needs your attention.',
            'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
            'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
            'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
        ]);
    }
}
