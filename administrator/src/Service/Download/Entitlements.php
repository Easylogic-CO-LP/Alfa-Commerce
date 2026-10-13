<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Download;

defined('_JEXEC') or die;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * Entitlements
 *
 * Lifecycle of download ENTITLEMENTS (`#__alfa_order_downloads`): granting on
 * paid/completed orders, revoking on cancellation, token resolution and the
 * atomic serve claim. The storage side (bytes on disk) is Storage's
 * job; the two only meet through opaque `storage_key` ids.
 *
 * Both "the order became paid" paths funnel here:
 *   - OrderModel::save()            - admin status edit entering/leaving the
 *                                     is_completed / is_cancelled families.
 *   - PaymentLedger::update()  - a payment row's transaction_status
 *                                     flipping to completed (admin mark-paid
 *                                     button AND gateway callbacks).
 * All calls are idempotent, so overlapping triggers are harmless.
 *
 * SEMANTIC CONTRACT: STATUS_AVAILABLE is the only servable state, now and
 * always. Any future state (partial refund, pending payment, ...) is
 * non-servable unless isServable() says otherwise; SQL that gates on the
 * status column must mirror isServable(), and there is deliberately only one
 * such statement (the atomic claim in claimDownload()).
 *
 * Raw tokens are NEVER stored: the DB holds sha256(token) plus an 8-char
 * prefix for support lookups. Consequences: download URLs can only be
 * composed while the raw token is in hand (grant time, kept in a
 * request-scoped cache for the status email), and rendering links later
 * (e.g. admin re-sends the email) transparently ROTATES the token: fresh
 * link in the new email, previously mailed links die. That rotation is a
 * feature (leaked old links expire), not a bug.
 *
 * @since  2.0.0
 */
class Entitlements
{
    /**
     * Entitlement granted but not yet released (order not paid/completed).
     */
    public const STATUS_PENDING = 0;

    /**
     * The ONLY servable state; see the class-level semantic contract.
     */
    public const STATUS_AVAILABLE = 1;

    /**
     * Terminal: download count exhausted or time window passed.
     */
    public const STATUS_EXPIRED = 2;

    /**
     * Order left the completed family (cancel/refund). Reversible: re-entering
     * the completed family flips the row back to AVAILABLE.
     */
    public const STATUS_REVOKED = 3;

    /**
     * Request-scoped raw tokens captured at grant/rotate time, so the status
     * email rendered later in the same request can compose real URLs without
     * the token ever touching the DB. Shape: [entitlementId => rawToken].
     *
     * @var array<int, string>
     */
    protected static array $rawTokens = [];

    /**
     * Whether an entitlement status may be served to the customer.
     *
     * The single decision point for "does this status download?"; the atomic
     * claim in claimDownload() is its SQL mirror. Extend here (and only here)
     * if a future state ever becomes servable.
     *
     * @param int $status One of the STATUS_* constants.
     *
     * @since  2.0.0
     */
    public static function isServable(int $status): bool
    {
        return $status === self::STATUS_AVAILABLE;
    }

    /**
     * React to an order status transition (called from OrderModel::save()).
     *
     * A status whose grants_downloads flag is set grants/reactivates;
     * entering the is_cancelled family revokes. Anything else is a no-op.
     * Never throws:
     * a downloads failure must not block the order save (same contract as
     * StockPolicy / EmailService).
     *
     * @param int $orderId Order PK.
     * @param int $oldStatusId Previous order status id (unused today; kept so
     *                         the signature already matches the future
     *                         onAlfaOrderStatusChange event payload).
     * @param int $newStatusId New order status id.
     *
     * @since  2.0.0
     */
    public static function handleStatusTransition(int $orderId, int $oldStatusId, int $newStatusId): void
    {
        try {
            // Cancellation always revokes, even if the status also carries the
            // grant flag: a cancelled order must never re-open downloads.
            // Otherwise: completion does not imply entitlement (a pickup, a
            // service or an on-account delivery all complete without handing
            // over files), so the status must say so explicitly.
            if (OrderStatusHelper::isCancelled($newStatusId)) {
                self::revokeForOrder(orderId: $orderId);
            } elseif (self::grantsDownloads($newStatusId)) {
                self::grantForOrder(orderId: $orderId);
            }
        } catch (Throwable $e) {
            // \Throwable, not Exception: the standing rule. An Error (a TypeError
            // from a changed signature, a division by zero) used to pass straight
            // through this net with the caller believing the step had been handled.
            // A gate arm even depended on that hole to manufacture a post-commit
            // failure; it has been re-authored onto an honest seam.
            //
            // register() unguarded first, and it is not decoration: an
            // UNREGISTERED category is dropped by Log::addLogEntry() with no
            // logger and no error (AlfaLog's docblock). AlfaComponent::boot()
            // registers, so an ordinary admin request would have landed this
            // line, but a CLI run, a cron, a queue worker or a test harness
            // never boots that path, and there this catch would have been a
            // silent swallow wearing a Log::add. Widening Exception to
            // Throwable buys nothing if the record then goes nowhere.
            AlfaLog::register();
            Log::add(
                entry:    'Entitlements::handleStatusTransition failed for order ' . $orderId . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.downloads',
            );
        }
    }

    /**
     * Whether entering this status grants download entitlements.
     *
     * The flag is the whole rule: nothing is inferred from is_completed.
     *
     * @param int $statusId Order status id.
     *
     *
     * @since  2.0.0
     */
    /**
     * Whether an order in this status may hold live entitlements.
     *
     * Cancellation outranks the grant flag: a cancelled order never entitles,
     * however its status is configured.
     *
     * @param int $statusId Order status id.
     *
     *
     * @since  2.0.0
     */
    public static function statusEntitles(int $statusId): bool
    {
        return !OrderStatusHelper::isCancelled($statusId) && self::grantsDownloads($statusId);
    }

    public static function grantsDownloads(int $statusId): bool
    {
        if ($statusId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('grants_downloads'))
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->where($db->quoteName('id') . ' = ' . $statusId),
        );

        return (int) $db->loadResult() === 1;
    }

    /**
     * The order's current status id.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    private static function currentStatusOf(int $orderId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_order_status'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        );

        return (int) $db->loadResult();
    }

    /**
     * Grant entitlements for every (order line x active download file) pair.
     *
     * Idempotent:
     *   - missing pair          -> new AVAILABLE row with frozen snapshots
     *                             (terms, filename, storage_key, sha256) and
     *                             expires_at = now + days_valid.
     *   - existing PENDING row  -> released to AVAILABLE.
     *   - existing REVOKED row  -> reactivated to AVAILABLE (counters and the
     *                             original expires_at survive the round trip).
     *   - existing EXPIRED row  -> left alone: exhaustion is terminal.
     *
     * @param int $orderId Order PK.
     *
     * @return int Number of rows created or reactivated.
     *
     * @since  2.0.0
     */
    public static function grantForOrder(int $orderId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Candidate files per line: item-wide files (combination_id NULL)
        // plus files bound to the line's exact combination.
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('oi.id', 'order_item_id'),
                $db->quoteName('oi.quantity'),
                $db->quoteName('oi.id_item'),
                $db->quoteName('oi.id_combination'),
                $db->quoteName('d.id', 'download_id'),
                $db->quoteName('d.max_downloads'),
                $db->quoteName('d.days_valid'),
                $db->quoteName('d.display_filename'),
                $db->quoteName('d.mime_type'),
                $db->quoteName('d.storage_key'),
                $db->quoteName('d.sha256'),
            ])
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_item_downloads', 'd'),
                $db->quoteName('d.item_id') . ' = ' . $db->quoteName('oi.id_item')
                . ' AND (' . $db->quoteName('d.combination_id') . ' IS NULL OR '
                . $db->quoteName('d.combination_id') . ' = ' . $db->quoteName('oi.id_combination') . ')',
            )
            ->where($db->quoteName('oi.id_order') . ' = :orderId')
            ->where($db->quoteName('d.state') . ' = 1')
            ->bind(':orderId', $orderId);

        $db->setQuery(query: $query);
        $candidates = $db->loadObjectList() ?: [];

        if (!$candidates) {
            return 0;
        }

        $existing = self::getForOrder(orderId: $orderId);

        // THE ONE MINTING EVENT IN AN ORDER'S LIFE.
        //
        // An order that already holds ANY entitlement has been granted before,
        // so this call is a RE-FIRE: a status round-trip, a second payment
        // callback, an operator re-entering a completed family. A re-fire may
        // reactivate what it granted before; it may NEVER mint.
        //
        // WHY THE GATE IS "any row at all" RATHER THAN "this pair": the
        // candidate query reads the LIVE order line, so a pair that did not
        // exist at grant time is one an EDIT created: a variant swapped to
        // bring new files into scope, a line added to a completed order, a
        // catalogue file re-published. Minting for it would size an allowance
        // from a mutable quantity nobody paid against, which is precisely what
        // "an order edit is not itself proof of entitlement" forbids.
        //
        // Shrinking eligibility is a different question and belongs to
        // {@see self::syncForLine()}, which may reduce and revoke but never
        // raise.
        $isInitialGrant = $existing === [];
        $existingByPair = [];

        foreach ($existing as $row) {
            $existingByPair[$row->id_order_item . ':' . $row->id_download] = $row;
        }

        $now = Factory::getDate('now', 'UTC');
        $granted = 0;

        foreach ($candidates as $candidate) {
            $pairKey = $candidate->order_item_id . ':' . $candidate->download_id;
            $existingRow = $existingByPair[$pairKey] ?? null;

            if ($existingRow !== null) {
                // Terminal exhaustion never reopens; available needs nothing.
                // Nothing here touches granted_allowance, downloads_used or
                // expires_at: a re-grant restores AVAILABILITY, never rights.
                // In particular the expiry window is not restarted: an edit
                // date must never become a fresh entitlement period.
                $reactivatable = [self::STATUS_PENDING, self::STATUS_REVOKED];

                if (in_array((int) $existingRow->status, $reactivatable, true)) {
                    $update = (object) [
                        'id' => (int) $existingRow->id,
                        'status' => self::STATUS_AVAILABLE,
                    ];
                    $db->updateObject('#__alfa_order_downloads', $update, 'id');
                    $granted++;
                }

                continue;
            }

            // A pair with no row, on an order that has already been granted.
            // This is an EDIT asking for rights, and the answer is no.
            if (!$isInitialGrant) {
                continue;
            }

            $rawToken = bin2hex(random_bytes(32));
            $daysValid = (int) $candidate->days_valid;

            $entitlement = (object) [
                'id_order' => $orderId,
                'id_order_item' => (int) $candidate->order_item_id,
                'id_download' => (int) $candidate->download_id,
                'token_prefix' => substr($rawToken, 0, 8),
                'token_hash' => hash(algo: 'sha256', data: $rawToken),
                'granted_allowance' => (int) $candidate->max_downloads * max(1, (int) $candidate->quantity),
                'downloads_used' => 0,
                // PROVENANCE. What this allowance was derived from, recorded so
                // it can be explained later and so a shrink can be computed
                // without asking the mutable line what it says today.
                'granted_quantity' => max(1, (int) $candidate->quantity),
                'granted_id_item' => (int) $candidate->id_item,
                'granted_id_combination' => $candidate->id_combination === null
                    ? null
                    : (int) $candidate->id_combination,
                'max_downloads' => (int) $candidate->max_downloads,
                'days_valid' => $daysValid,
                'display_filename' => (string) $candidate->display_filename,
                'mime_type' => (string) $candidate->mime_type,
                'storage_key' => (string) $candidate->storage_key,
                'sha256' => (string) $candidate->sha256,
                'expires_at' => $daysValid > 0
                    ? Factory::getDate('+' . $daysValid . ' days', 'UTC')->toSql()
                    : null,
                'status' => self::STATUS_AVAILABLE,
                'granted_at' => $now->toSql(),
            ];

            $db->insertObject('#__alfa_order_downloads', $entitlement, 'id');
            self::$rawTokens[(int) $entitlement->id] = $rawToken;
            $granted++;
        }

        if ($granted > 0) {
            OrderModel::logOrderActivity(
                $orderId,
                'downloads.granted',
                "Granted {$granted} download entitlement(s)",
                ['count' => $granted],
            );
        }

        return $granted;
    }

    /**
     * THE ONE PER-LINE RECONCILER. Shrinks entitlement when eligibility
     * shrinks, and can never grow it.
     *
     * THE PRE-V1 RULE, STATED ONCE HERE: an existing entitlement may be
     * REDUCED or REVOKED when its eligibility shrinks, but an ordinary edit to
     * a mutable order or catalogue must never create additional digital
     * rights. An order edit is not proof of entitlement. So this method has no
     * branch that inserts a row, raises an allowance or moves an expiry
     * forward, not as policy enforced by a caller, but because the code to do
     * any of those things is not here.
     *
     * WHAT IT RECONCILES, and why each is a shrink:
     *
     *   VARIANT. A file bound to one combination is eligible only while the
     *   line still carries that combination. Swapping the variant revokes the
     *   old identity's files; without this the line accumulates the UNION of
     *   every variant it has ever held. Files bound to no combination
     *   (combination_id NULL) belong to the item and survive a variant change.
     *   The NEW variant's files are NOT granted: that would be minting.
     *
     *   PUBLICATION. An unpublished or deleted catalogue file is not eligible
     *   for future serving. The history stays and the consumption count stays;
     *   only future access stops. Re-publishing does not mint and does not
     *   refresh expiry; it may only let a surviving entitlement be reactivated
     *   by the ordinary grant path, and only if nothing else revoked it.
     *
     *   CAPACITY. Lowering the line quantity lowers what the line may still
     *   take: the cap becomes max_downloads x the new quantity, and the stored
     *   allowance is reduced to it. Raising the quantity changes nothing, by
     *   the rule above. An unlimited allowance (0) is left alone: it was
     *   granted as unlimited and a quantity is not what made it so.
     *
     * NEVER TOUCHES `downloads_used`. Consumption is historical fact. When the
     * new cap falls BELOW what has already been taken, the allowance is set to
     * the usage rather than under it, so remaining is exactly zero and never
     * negative: the customer keeps what they took and can take no more.
     *
     * @param int $orderItemId The order line to reconcile.
     *
     * @return int How many entitlement rows changed.
     *
     * @since  2.0.0
     */
    public static function syncForLine(int $orderItemId): int
    {
        if ($orderItemId <= 0) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $line = $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('id'),
                        $db->quoteName('id_order'),
                        $db->quoteName('id_item'),
                        $db->quoteName('id_combination'),
                        $db->quoteName('quantity'),
                    ])
                    ->from($db->quoteName('#__alfa_order_items'))
                    ->where($db->quoteName('id') . ' = ' . $orderItemId),
            )->loadObject();

            if ($line === null) {
                return 0;
            }

            // The line's entitlements, with the catalogue file's CURRENT
            // eligibility beside each. A LEFT join on purpose: a file that has
            // been deleted outright must reconcile as ineligible, not vanish
            // from the result and be left alone.
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('e.id'),
                        $db->quoteName('e.status'),
                        $db->quoteName('e.granted_allowance'),
                        $db->quoteName('e.downloads_used'),
                        $db->quoteName('e.max_downloads'),
                        $db->quoteName('e.granted_id_combination'),
                        $db->quoteName('d.state', 'file_state'),
                        $db->quoteName('d.combination_id', 'file_combination'),
                    ])
                    ->from($db->quoteName('#__alfa_order_downloads', 'e'))
                    ->join(
                        'LEFT',
                        $db->quoteName('#__alfa_item_downloads', 'd'),
                        $db->quoteName('d.id') . ' = ' . $db->quoteName('e.id_download'),
                    )
                    ->where($db->quoteName('e.id_order_item') . ' = ' . $orderItemId),
            )->loadObjectList() ?: [];

            $currentCombination = $line->id_combination === null ? null : (int) $line->id_combination;
            $quantity = max(0, (int) $line->quantity);
            $changed = 0;

            foreach ($rows as $row) {
                $update = ['id' => (int) $row->id];

                // eligibility
                $fileGone = $row->file_state === null;
                $unpublished = !$fileGone && (int) $row->file_state !== 1;
                $fileCombination = $row->file_combination === null ? null : (int) $row->file_combination;
                $variantLost = $fileCombination !== null && $fileCombination !== $currentCombination;

                $servable = \in_array(
                    (int) $row->status,
                    [self::STATUS_PENDING, self::STATUS_AVAILABLE],
                    true,
                );

                if ($servable && ($fileGone || $unpublished || $variantLost)) {
                    // Future access stops. The row, its counter and its
                    // provenance all stay: this is a revocation, not an
                    // erasure.
                    $update['status'] = self::STATUS_REVOKED;
                }

                // capacity
                $perUnit = (int) $row->max_downloads;
                $allowance = (int) $row->granted_allowance;
                $used = (int) $row->downloads_used;

                // 0 is unlimited, not zero: a quantity never made it so and a
                // quantity must not undo it.
                if ($perUnit > 0 && $allowance > 0) {
                    $cap = $perUnit * $quantity;

                    if ($cap < $allowance) {
                        // Never below what has already been taken, so remaining
                        // is exactly zero rather than negative, and never
                        // upward.
                        $update['granted_allowance'] = max($used, $cap);
                    }
                }

                if (\count($update) > 1) {
                    // By REFERENCE. updateObject() declares `&$object`, so a cast
                    // expression cannot be passed to it: PHP raises an Error, not
                    // a notice. With a Throwable net directly above, that Error
                    // becomes a reconciler that quietly does nothing and reports
                    // "0 changed", indistinguishable from "nothing to change".
                    $patch = (object) $update;
                    $db->updateObject('#__alfa_order_downloads', $patch, 'id');
                    $changed++;
                }
            }

            if ($changed > 0) {
                OrderModel::logOrderActivity(
                    (int) $line->id_order,
                    'downloads.reconciled',
                    "Reconciled {$changed} download entitlement(s) after a line change",
                    ['line' => $orderItemId, 'count' => $changed],
                );
            }

            return $changed;
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add(
                'Entitlement reconciliation failed for line ' . $orderItemId . ': '
                . $e::class . ': ' . $e->getMessage(),
                Log::ERROR,
                // com_alfa.downloads, matching the other two catches of this
                // service. An operator chasing a failed entitlement should not
                // have to know which of three entry points tripped to know
                // which log file to open.
                'com_alfa.downloads',
            );

            return 0;
        }
    }

    /**
     * Revoke every non-terminal entitlement of an order (cancel/refund).
     *
     * EXPIRED rows stay expired; AVAILABLE and PENDING become REVOKED. The
     * site endpoint additionally re-checks the live order status on every
     * hit, so links die even before this runs.
     *
     * @param int $orderId Order PK.
     *
     * @return int Number of rows revoked.
     *
     * @since  2.0.0
     */
    public static function revokeForOrder(int $orderId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_order_downloads'))
            ->set($db->quoteName('status') . ' = ' . self::STATUS_REVOKED)
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->whereIn($db->quoteName('status'), [self::STATUS_PENDING, self::STATUS_AVAILABLE])
            ->bind(':orderId', $orderId);

        $db->setQuery(query: $query);
        $db->execute();
        $revoked = (int) $db->getAffectedRows();

        if ($revoked > 0) {
            OrderModel::logOrderActivity(
                $orderId,
                'downloads.revoked',
                "Revoked {$revoked} download entitlement(s)",
                ['count' => $revoked],
            );
        }

        return $revoked;
    }

    /**
     * React to a payment transaction-status change (called from
     * PaymentLedger::update()). Grants when a payment turns completed.
     * Never throws.
     *
     * @param int $orderId Order PK.
     * @param string $oldStatus Previous transaction_status.
     * @param string $newStatus New transaction_status.
     *
     * @since  2.0.0
     */
    public static function handlePaymentTransition(int $orderId, string $oldStatus, string $newStatus): void
    {
        try {
            if ($newStatus === PaymentLedger::STATUS_COMPLETED && $oldStatus !== $newStatus) {
                // Payment alone does not entitle: the order's current status
                // still decides, cancellation included. Without this the flag
                // could grant but never deny, since any completed payment
                // released the files, on a cancelled order too.
                if (self::statusEntitles(self::currentStatusOf($orderId))) {
                    self::grantForOrder(orderId: $orderId);
                }
            }
        } catch (Throwable $e) {
            // \Throwable, not Exception: the standing rule. An Error (a TypeError
            // from a changed signature, a division by zero) used to pass straight
            // through this net with the caller believing the step had been handled.
            // A gate arm even depended on that hole to manufacture a post-commit
            // failure; it has been re-authored onto an honest seam.
            //
            // register() unguarded first, and it is not decoration: an
            // UNREGISTERED category is dropped by Log::addLogEntry() with no
            // logger and no error (AlfaLog's docblock). AlfaComponent::boot()
            // registers, so an ordinary admin request would have landed this
            // line, but a CLI run, a cron, a queue worker or a test harness
            // never boots that path, and there this catch would have been a
            // silent swallow wearing a Log::add. Widening Exception to
            // Throwable buys nothing if the record then goes nowhere.
            AlfaLog::register();
            Log::add(
                entry:    'Entitlements::handlePaymentTransition failed for order ' . $orderId . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.downloads',
            );
        }
    }

    /**
     * All download file rows of an item (policy side), for the admin panel.
     *
     * @param int $itemId Item PK.
     *
     * @return object[] `#__alfa_item_downloads` rows, in display order.
     *
     * @since  2.0.0
     */
    public static function getFilesForItem(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_item_downloads'))
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->order($db->quoteName('ordering') . ' ASC')
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':itemId', $itemId);

        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Persist the Downloads-tab metadata grid from the item save.
     *
     * Called by ItemModel::save() with the `jform[item_downloads]` payload
     * (rowId => {title, max_downloads, days_valid, combination_id, state}),
     * so file metadata saves with the toolbar Save/Save & Close like every
     * other item surface. File BYTES are not touched here: upload/replace/
     * delete stay on the ItemdownloadsController AJAX tasks.
     *
     * Rows not belonging to $itemId are ignored (ownership guard). Existing
     * entitlement snapshots are untouched by design: new terms apply to
     * future purchases only.
     *
     * @param int $itemId Item PK.
     * @param array $payload Posted grid rows keyed by download row id.
     *
     * @since  2.0.0
     */
    public static function saveItemDownloadsMeta(int $itemId, array $payload): void
    {
        if ($itemId <= 0 || $payload === []) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $userId = (int) Factory::getApplication()->getIdentity()->id;

        $existingById = [];

        foreach (self::getFilesForItem(itemId: $itemId) as $row) {
            $existingById[(int) $row->id] = $row;
        }

        foreach ($payload as $rowId => $meta) {
            $row = $existingById[(int) $rowId] ?? null;

            if ($row === null || !\is_array($meta)) {
                continue;
            }

            $combinationId = (int) ($meta['combination_id'] ?? 0);

            $update = (object) [
                'id' => (int) $row->id,
                'title' => trim((string) ($meta['title'] ?? '')) ?: $row->title,
                'combination_id' => $combinationId > 0 ? $combinationId : null,
                'max_downloads' => max(0, (int) ($meta['max_downloads'] ?? 0)),
                'days_valid' => max(0, (int) ($meta['days_valid'] ?? 0)),
                'state' => (int) ($meta['state'] ?? 1) === 1 ? 1 : 0,
                'modified_by' => $userId,
            ];

            $db->updateObject('#__alfa_item_downloads', $update, 'id', true);
        }
    }

    /**
     * All entitlement rows of an order.
     *
     * @param int $orderId Order PK.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public static function getForOrder(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_order_downloads'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':orderId', $orderId);

        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Resolve an entitlement from a RAW token (site endpoint lookup).
     *
     * @param string $rawToken Raw token from the download URL.
     *
     * @return object|null Entitlement row, or null when unknown/malformed.
     *
     * @since  2.0.0
     */
    public static function findByToken(string $rawToken): ?object
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }

        $tokenHash = hash(algo: 'sha256', data: $rawToken);

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_order_downloads'))
            ->where($db->quoteName('token_hash') . ' = :tokenHash')
            ->bind(':tokenHash', $tokenHash);

        $db->setQuery(query: $query);

        return $db->loadObject() ?: null;
    }

    /**
     * Atomically claim one download before streaming.
     *
     * Single guarded UPDATE (the SQL mirror of isServable() plus the count
     * guard), so two simultaneous clicks can never overshoot the limit and a
     * concurrent revoke can never be raced past. Zero affected rows = claim
     * refused. The increment is final: no decrement on client disconnect
     * (connection_aborted is non-deterministic under FPM; support adjusts
     * counters instead).
     *
     * Flips the row to EXPIRED when this claim consumed the last download.
     *
     * @param int $entitlementId Entitlement PK (already vetted by the caller).
     * @param string $ip Client IP for the audit trail.
     * @param string $userAgent Client User-Agent for the audit trail.
     *
     * @return bool True when the claim succeeded and streaming may start.
     *
     * @since  2.0.0
     */
    public static function claimDownload(int $entitlementId, string $ip, string $userAgent): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $now = Factory::getDate('now', 'UTC')->toSql();
        $ip = substr($ip, 0, 45);
        $userAgent = substr($userAgent, 0, 255);

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_order_downloads'))
            ->set($db->quoteName('downloads_used') . ' = ' . $db->quoteName('downloads_used') . ' + 1')
            ->set($db->quoteName('last_download_at') . ' = :now')
            ->set($db->quoteName('last_ip') . ' = :ip')
            ->set($db->quoteName('last_user_agent') . ' = :userAgent')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('status') . ' = ' . self::STATUS_AVAILABLE)
            ->where('(' . $db->quoteName('granted_allowance') . ' = 0 OR '
                . $db->quoteName('downloads_used') . ' < ' . $db->quoteName('granted_allowance') . ')')
            ->bind(':now', $now)
            ->bind(':ip', $ip)
            ->bind(':userAgent', $userAgent)
            ->bind(':id', $entitlementId);

        $db->setQuery(query: $query);
        $db->execute();

        if ((int) $db->getAffectedRows() !== 1) {
            return false;
        }

        // Terminal flip when the last download was just consumed.
        $expire = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_order_downloads'))
            ->set($db->quoteName('status') . ' = ' . self::STATUS_EXPIRED)
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('granted_allowance') . ' > 0')
            ->where($db->quoteName('downloads_used') . ' >= ' . $db->quoteName('granted_allowance'))
            ->bind(':id', $entitlementId);

        $db->setQuery(query: $expire);
        $db->execute();

        return true;
    }

    /**
     * Mark an entitlement expired (time window passed, checked lazily at
     * serve time; there is no cron).
     *
     * @param int $entitlementId Entitlement PK.
     *
     * @since  2.0.0
     */
    public static function markExpired(int $entitlementId): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_order_downloads'))
            ->set($db->quoteName('status') . ' = ' . self::STATUS_EXPIRED)
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $entitlementId);

        $db->setQuery(query: $query);
        $db->execute();
    }

    /**
     * Servable download links for an order, for email/page rendering.
     *
     * Returns one object per AVAILABLE entitlement: {title (display
     * filename), url, downloads_left (null = unlimited), expires_at}.
     *
     * URLs need the raw token: rows granted in THIS request use the cached
     * raw token; older rows get a fresh token (rotation; see class
     * docblock). Rotation only happens here, i.e. only when someone is about
     * to actually send/show links.
     *
     * @param int $orderId Order PK.
     * @param string|null $secureKey The order's secure_key to append for
     *                               guest access (null for logged-in flows).
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public static function getLinksForOrder(int $orderId, ?string $secureKey = null): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $links = [];

        foreach (self::getForOrder(orderId: $orderId) as $row) {
            if (!self::isServable(status: (int) $row->status)) {
                continue;
            }

            $rawToken = self::$rawTokens[(int) $row->id] ?? null;

            if ($rawToken === null) {
                // Rotate: mint a fresh token for this render, kill the old link.
                $rawToken = bin2hex(random_bytes(32));

                $rotation = (object) [
                    'id' => (int) $row->id,
                    'token_prefix' => substr($rawToken, 0, 8),
                    'token_hash' => hash(algo: 'sha256', data: $rawToken),
                ];
                $db->updateObject('#__alfa_order_downloads', $rotation, 'id');
                self::$rawTokens[(int) $row->id] = $rawToken;
            }

            $url = 'index.php?option=com_alfa&task=download.serve&token=' . $rawToken;

            if ($secureKey !== null && $secureKey !== '') {
                $url .= '&sk=' . $secureKey;
            }

            $links[] = (object) [
                'title' => $row->display_filename !== '' ? $row->display_filename : 'download',
                'url' => Route::link(client: 'site', url: $url, xhtml: false, absolute: true),
                'downloads_left' => (int) $row->granted_allowance > 0
                    ? max(0, (int) $row->granted_allowance - (int) $row->downloads_used)
                    : null,
                'expires_at' => $row->expires_at,
            ];
        }

        return $links;
    }
}
