<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Central registry for plugin actions.
 * Boots the correct plugin and dispatches onGetActions / onExecuteAction.
 *
 * Error messages are ALWAYS shown (admin must know when something is broken).
 * Step-by-step trace only appears when Joomla Debug System is enabled.
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ExecuteFieldActionEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\GetFieldActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\ExecutePaymentActionEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\GetPaymentActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ExecuteShipmentActionEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\GetShipmentActionsEvent;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

class ActionRegistry
{
    /**
     * The layout owner of the core-owned ledger actions (Record failed refund,
     * Record chargeback, Record won dispute). No plugin folder carries this
     * name, so the action layouts resolve to the component's
     * layouts/actions/<file>.php, as the wallet actions do.
     *
     * @var string
     *
     * @since  2.0.0
     */
    public const LEDGER_LAYOUT_OWNER = 'ledger';

    /**
     * Record this refund: the core-owned action that books a refund the payment
     * provider reports as made outside Alfa, with the provider's exact figures
     * ({@see PaymentLedger::recordReportedRefund()}).
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const RECORD_REFUND = 'ledger_record_refund';

    /**
     * Record this refund with an amount to enter, for a report with no exact
     * amount in the order's currency: a form; its _do arm books
     * ({@see PaymentLedger::recordReportedRefundTyped()}).
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const RECORD_REFUND_TYPED = 'ledger_record_refund_typed';

    /**
     * Reconcile a report to a refund already in the order's books: a form; its
     * _do arm resolves ({@see PaymentLedger::reconcileReportedRefund()}).
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const RECONCILE_REPORT = 'ledger_reconcile_report';

    /**
     * Resolve a report as a duplicate or invalid report: a form; its _do arm
     * resolves ({@see PaymentLedger::dismissReportedRefund()}).
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const DISMISS_REPORT = 'ledger_dismiss_report';

    /**
     * The field every report action posts: the report's reference, exactly as the gateway lists it.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const REPORT_FIELD = 'report';

    /**
     * The report actions that open a form, and need the right to resolve reports.
     *
     * @var list<string>
     *
     * @since  2.1.0
     */
    private const REPORT_FORMS = [self::RECORD_REFUND_TYPED, self::RECONCILE_REPORT, self::DISMISS_REPORT];

    /**
     * Where used one-time keys are kept.
     *
     * @var string
     *
     * @since  2.0.0
     */
    private const ONCE_TABLE = '#__alfa_action_once';

    /**
     * How long a used key is kept, in days.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const ONCE_KEEP_DAYS = 30;

    /**
     * The most expired keys one claim removes.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const ONCE_PRUNE_BATCH = 100;

    /**
     * The ids the plugin is offering for this entity right now.
     *
     * @param array<int, mixed> $offered Actions as resolveActions() returned them.
     *
     * @return array<int, string>
     *
     * @since  2.0.0
     */
    private static function offeredIds(array $offered): array
    {
        $ids = [];

        foreach ($offered as $action) {
            $id = \is_array($action) ? ($action['id'] ?? '') : ($action->id ?? '');

            if ((string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Why this action may not run against this row, or null.
     *
     * THE OFFER IS THE PRECONDITION. Every plugin already decides
     * admissibility when it builds its action list: Standard registers
     * `mark_paid` only while the payment is pending, offers the refund pair
     * only while the order can still give money back, and registers nothing
     * state-changing once the lifecycle is terminal. The core does the same
     * for store credit. What was missing is that nobody asked again at
     * execution time: onExecuteAction() is a match() on the action NAME, so
     * the absence of a button was the entire protection.
     *
     * That gap is reachable without an attacker. The POST rule and the
     * one-time key stop a refresh and a replay; neither stops a STALE TAB. An
     * operator opens a pending payment, a colleague refunds it, the operator
     * clicks "Mark as Paid": correct token, unused key, genuine POST. And a
     * refunded payment was marked completed, which re-granted downloads and
     * re-ran the method's paid transition on money that had gone back.
     *
     * Re-deriving the list here is the whole fix, because it is derived from
     * the row as it is ON DISK now, not from the page that produced the
     * request. It also means a plugin author never writes a precondition
     * twice: the registration IS the rule, and a new action is governed the
     * day it is declared.
     *
     * A modal action submits to a second id (`refund_partial` opens the form,
     * `refund_partial_do` performs the write) and that second id is
     * deliberately never registered, because it is not a button. It is
     * admissible exactly when the step that opens it is on offer. Standard,
     * a card gateway plugin and the core credit path already share this spelling.
     *
     * @param array<int, mixed> $offered Actions the plugin is offering now.
     * @param string $actionId The requested action id.
     *
     * @return string|null Refusal reason, or null when the action may run.
     *
     * @since  2.0.0
     */
    public static function refuseUnofferedAction(array $offered, string $actionId): ?string
    {
        if ($actionId === '') {
            return 'no action was named';
        }

        $ids = self::offeredIds($offered);

        if (\in_array($actionId, $ids, true)) {
            return null;
        }

        if (str_ends_with($actionId, '_do')
            && \in_array(substr($actionId, 0, -3), $ids, true)) {
            return null;
        }

        // Naming what IS available makes a mis-declared action obvious in the
        // debug trace instead of looking like a permission problem. The
        // operator is told in plain words (refusedPage), not in action ids.
        return sprintf(
            'action "%s" is not available for this record in its current state%s',
            $actionId,
            $ids === [] ? '' : '; currently available: ' . implode(', ', $ids),
        );
    }

    /**
     * Whether this action changes state: true unless the plugin offers it
     * declared read-only.
     *
     * The declaration lives with the action, next to its label and its
     * confirmation text, so a plugin author marks a read-only action once and
     * the dispatcher enforces the rule everywhere. An action that is not on the
     * offered list changes state: the safe answer for the two-step `_do` arm,
     * which is never registered and moves the money, and for an item field's
     * save arm.
     *
     * @param array<int, mixed> $offered Actions the plugin is offering now.
     * @param string $actionId The requested action id.
     *
     *
     * @since  2.0.0
     */
    public static function actionMutates(array $offered, string $actionId): bool
    {
        if ($actionId === '') {
            return true;
        }

        foreach ($offered as $action) {
            $id = \is_array($action) ? ($action['id'] ?? '') : ($action->id ?? '');

            if ((string) $id !== $actionId) {
                continue;
            }

            return (bool) (\is_array($action) ? ($action['mutates'] ?? true) : ($action->mutates ?? true));
        }

        return true;
    }

    /**
     * Why a state-changing action may not run for this request, or null.
     *
     * Money actions used to execute while a page rendered, so a refresh or a
     * back button re-fired them. Two things stop that: the request must be a
     * POST (a URL cannot be replayed by navigation), and it must carry a
     * one-time key that is consumed here: a second submission of the same
     * key is refused whatever the ledger would have done with it.
     *
     * Public, and its only side effects are consuming the key and removing a
     * bounded batch of expired claims (see consumeOnceKey()), so the behaviour
     * can be asserted directly instead of inferred from a page.
     *
     * @param bool $mutating Whether the resolved action changes state.
     * @param Input $input The request.
     *
     * @return string|null Refusal reason, or null when the request may run.
     *
     * @since  2.0.0
     */
    public static function refuseUnsafeRequest(bool $mutating, Input $input): ?string
    {
        if (!$mutating) {
            return null;
        }

        if (strtoupper((string) $input->getMethod()) !== 'POST') {
            return 'a state-changing action must be submitted as a POST, not opened as a URL';
        }

        $once = trim((string) $input->getString('once', ''));

        if ($once === '') {
            return 'a state-changing action must carry a one-time key';
        }

        $identity = Factory::getApplication()->getIdentity();
        $userId = $identity === null ? 0 : (int) $identity->id;

        if ($userId <= 0) {
            return 'a state-changing action needs a signed-in user';
        }

        if (!self::consumeOnceKey(db: Factory::getContainer()->get('DatabaseDriver'), userId: $userId, key: $once)) {
            return 'this action was already submitted; it has not been run again';
        }

        return null;
    }

    /**
     * Claim a one-time action key for a user; false when it was already used.
     *
     * The claim is a row under a primary key on the user and the key's hash,
     * written in its own statement before the action runs. Of two requests
     * presenting the same key at the same moment the database admits one and
     * the other gets a duplicate key, whatever the session handler.
     *
     * Expired claims are removed first, a bounded batch per call and by one
     * request at a time, so no scheduled task is needed and a failed clean-up
     * spends no key.
     *
     * @param DatabaseInterface $db The connection that records the claim.
     * @param int $userId The signed-in user.
     * @param string $key The key the request presented.
     *
     * @return bool True when this call claimed the key.
     *
     * @throws ExecutionFailureException When the database refuses for any other reason.
     * @throws RuntimeException When the database gives no answer about the clean-up lock.
     *
     * @since  2.0.0
     */
    public static function consumeOnceKey(DatabaseInterface $db, int $userId, string $key): bool
    {
        self::pruneOnceKeys(db: $db);

        $hash = hash(algo: 'sha256', data: $key);
        $claimed = Factory::getDate()->toSql();
        $claim = $db->createQuery()
            ->insert($db->quoteName(self::ONCE_TABLE))
            ->columns($db->quoteName(['id_user', 'key_hash', 'claimed']))
            ->values(':user, :hash, :claimed')
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':hash', $hash)
            ->bind(':claimed', $claimed);

        try {
            $db->setQuery(query: $claim)->execute();
        } catch (ExecutionFailureException $e) {
            // Only a duplicate key (MySQL 1062) is a key already used. Any other
            // refusal of the row is an error, whatever the driver: PDO reports
            // every integrity violation under the one SQLSTATE 23000.
            if (MysqlError::number($e) === 1062) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Remove a bounded batch of expired claims, unless another request is doing it.
     *
     * Two deletes of the expired rows running at once can reach them through
     * different indexes, lock them in opposite orders and deadlock, and the
     * losing request's action would fail. So one request at a time does this
     * clean-up: a request that finds the lock taken leaves the work to the one
     * holding it, and a later claim catches up.
     *
     * @param DatabaseInterface $db The connection that records the claims.
     *
     *
     * @throws ExecutionFailureException When the delete fails.
     * @throws RuntimeException When the database gives no answer about the lock.
     *
     * @since  2.0.0
     */
    private static function pruneOnceKeys(DatabaseInterface $db): void
    {
        $lock = self::onceKeyPruneLock(db: $db);
        $taken = $db->setQuery(query: $db->createQuery()->select('GET_LOCK(:lock, 0)')->bind(':lock', $lock))->loadResult();

        if ($taken === null) {
            throw new RuntimeException('The clean-up lock of used one-time keys could not be taken: the database returned no answer.');
        }

        if ((int) $taken !== 1) {
            return;
        }

        try {
            $cutoff = Factory::getDate(time: '-' . self::ONCE_KEEP_DAYS . ' days')->toSql();
            $prune = $db->createQuery()
                ->delete($db->quoteName(self::ONCE_TABLE))
                ->where($db->quoteName('claimed') . ' < :cutoff')
                ->bind(':cutoff', $cutoff)
                ->setLimit(self::ONCE_PRUNE_BATCH);
            $db->setQuery(query: $prune)->execute();
        } finally {
            $db->setQuery(query: $db->createQuery()->select('RELEASE_LOCK(:lock)')->bind(':lock', $lock))->loadResult();
        }
    }

    /**
     * The name of the lock under which expired claims are removed, for this site.
     *
     * Named locks are server-wide, so the name carries this site's database
     * and table prefix, hashed to stay within the 64 characters MySQL and
     * MariaDB allow. The lock belongs to the connection, so a request that
     * dies frees it.
     *
     * @param DatabaseInterface $db The connection that records the claims.
     *
     * @return string 32 characters.
     *
     * @since  2.0.0
     */
    private static function onceKeyPruneLock(DatabaseInterface $db): string
    {
        $site = (string) Factory::getApplication()->get(key: 'db') . '.' . $db->getPrefix();

        return 'alfa.once.prune.' . substr(string: hash(algo: 'sha256', data: $site), offset: 0, length: 16);
    }

    /**
     * Resolve + dispatch an action for the generic action PAGE (view=
     * view=action). Boots the plugin, loads the group's context and dispatches
     * its execute event in PAGE mode, so the plugin's setLayout()/useScript()
     * land on a real page. One place for every group; the view just echoes the
     * returned event's layout.
     *
     * @param Input $input The request input (group + ids + action).
     *
     * @return array{group: string, type: string, event: object}
     *
     * @throws RuntimeException
     *
     * @since  2.0.0
     */
    public static function resolveActionPage(Input $input): array
    {
        $app = Factory::getApplication();
        $group = $input->getCmd('group', 'alfa-item-fields');

        switch ($group) {
            case 'alfa-item-fields':
                $db = Factory::getContainer()->get('DatabaseDriver');
                $db->setQuery(
                    $db->getQuery(true)->select('*')->from($db->qn('#__alfa_item_fields'))
                        ->where($db->qn('id') . ' = ' . $input->getInt('field_id', 0)),
                );
                $field = $db->loadObject();

                if (!$field) {
                    throw new RuntimeException('Item field not found.', 404);
                }

                $scope = in_array($input->getCmd('scope', 'item'), ['item', 'combination', 'option', 'order'], true)
                    ? $input->getCmd('scope', 'item') : 'item';
                $actionId = $input->getCmd('action', 'configure');
                $scopeId = $input->getInt('scope_id', 0);
                $itemId = $input->getInt('item_id', 0);

                $event = new ExecuteFieldActionEvent('onExecuteFieldAction', [
                    'action' => $actionId,
                    'scope' => $scope,
                    'scopeId' => $scopeId,
                    'itemId' => $itemId,
                    'field' => $field,
                    'data' => ['page' => 1],
                ]);

                // The rules of the payment and shipment arms. The page runs only
                // what the field's plugin offers for this row (or its _do arm):
                // a save arm (configure_save, terms_save) is never offered, so it
                // is not started from a button here. An action the plugin does not
                // offer read-only changes state, so it runs only from a POST with
                // a one-time key. Admissibility first, so a refused click burns no
                // key.
                $offered = self::getItemFieldActions(field: $field, scope: $scope, scopeId: $scopeId, itemId: $itemId);
                $refusal = self::refuseUnofferedAction($offered, $actionId)
                    ?? self::refuseUnsafeRequest(mutating: self::actionMutates(offered: $offered, actionId: $actionId), input: $input);

                if ($refusal !== null) {
                    return self::refusedPage(group: $group, type: (string) $field->type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_REPEATED', reason: $refusal);
                }

                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');
                $plugin->onExecuteFieldAction($event);

                return ['group' => $group, 'type' => (string) $field->type, 'event' => $event];

            case 'alfa-payments':
                $payment = self::orderModel()->getPaymentData($input->getInt('payment_id', 0));

                if (!$payment) {
                    throw new RuntimeException('Payment not found.', 404);
                }

                // FAIL CLOSED. A payment that records no type has no plugin to
                // act for it: guessing one (the live method's type, 'standard')
                // once ran the wrong plugin's actions against a real card payment.
                // The core's own report actions are the exception: they call the
                // ledger's verbs and never boot a plugin, and the ledger acts only
                // on a report it lists for this payment, so staff always have a
                // way out of the refund block such a payment's kept provider
                // refund holds (since 2.1.1).
                $type = self::methodType($payment, 'payment_method_type');
                $actionId = $input->getCmd('action', '');

                if ($type === '' && !self::isReportAction(actionId: $actionId)) {
                    throw new RuntimeException(
                        'Payment #' . (int) $payment->id . ' records no payment method type: no plugin can act on it.',
                        409,
                    );
                }

                // Store credit moved this money, not a gateway. The COMPONENT
                // executes it: booting a plugin named 'credit' hands back a
                // DummyPlugin, and booting the ORDER'S gateway instead would
                // aim a refund at a card that was never charged.
                $orderForAction = self::orderModel()->getItem((int) $payment->id_order);

                // Resolved once and used for both questions. The list is the
                // authority on WHETHER this action may run at all and on
                // whether it changes state, and asking twice would boot the
                // plugin and rebuild the list twice per request.
                $offer = self::paymentOffer(payment: $payment, order: $orderForAction);
                $offered = $offer['actions'];

                // Built before the guards: a refusal answers on it as a notice,
                // and an admitted gateway action is dispatched with it.
                $event = new ExecutePaymentActionEvent('onExecuteAction', [
                    'action' => $actionId,
                    'payment' => $payment,
                    'order' => $orderForAction,
                    'data' => [],
                ]);

                // THE REFUND BLOCK. An action that starts a refund, and the arm
                // its form submits to, is refused with its reason while a
                // provider has reported a refund on this order that the books do
                // not hold: before the one-time key is used and before any
                // plugin runs.
                $formOf = str_ends_with($actionId, '_do') ? substr($actionId, 0, -3) : '';

                if ($offer['refusal'] !== null && array_intersect([$actionId, $formOf], $offer['withheld']) !== []) {
                    return self::refusedPage(group: $group, type: $type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_REFUND_BLOCKED', reason: 'refund block', shown: $offer['refusal']);
                }

                // Admissibility first, and deliberately before the one-time
                // key is consumed: a click refused because the world moved
                // should not also burn the key, or a legitimate retry after
                // reloading would be refused for a second, wrong reason.
                // The report actions answer for themselves: the ledger asks the
                // gateway again and says whether the report is already settled,
                // still reported, or without an exact amount. Their forms only
                // ask; every arm that writes is submitted with a one-time key.
                $reportAction = self::isReportAction(actionId: $actionId);
                $refusal = $reportAction ? null : self::refuseUnofferedAction(offered: $offered, actionId: $actionId);

                if ($refusal !== null) {
                    return self::refusedPage(group: $group, type: $type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_STALE', reason: $refusal);
                }

                $mutating = $reportAction ? !\in_array($actionId, self::REPORT_FORMS, true) : self::actionMutates(offered: $offered, actionId: $actionId);
                $refusal = self::refuseUnsafeRequest(mutating: $mutating, input: $input);

                if ($refusal !== null) {
                    return self::refusedPage(group: $group, type: $type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_REPEATED', reason: $refusal);
                }

                // The ledger's own actions record a provider fact. They are
                // never dispatched to the row's plugin, which may be disabled
                // or report nothing.
                if (str_starts_with($actionId, 'ledger_')) {
                    return [
                        'group' => $group,
                        'type' => self::LEDGER_LAYOUT_OWNER,
                        'event' => self::executeLedgerAction(payment: $payment, order: $orderForAction, action: $actionId, input: $input),
                    ];
                }

                if (in_array($type, PaymentLedger::NON_GATEWAY_TYPES, true)) {
                    return [
                        'group' => $group,
                        'type' => $type,
                        'event' => self::executeCreditAction(
                            $payment,
                            self::orderModel()->getItem((int) $payment->id_order),
                            $actionId,
                            $input,
                        ),
                    ];
                }

                $app->bootPlugin((string) $type, 'alfa-payments')->onExecuteAction($event);

                return ['group' => $group, 'type' => (string) $type, 'event' => $event];

            case 'alfa-shipments':
                $shipment = self::orderModel()->getShipmentData($input->getInt('shipment_id', 0));

                if (!$shipment) {
                    throw new RuntimeException('Shipment not found.', 404);
                }

                // Fail closed, same reasoning as the payment arm above.
                $type = self::methodType($shipment, 'shipment_method_type');

                if ($type === '') {
                    throw new RuntimeException(
                        'Shipment #' . (int) $shipment->id . ' records no shipment method type: no plugin can act on it.',
                        409,
                    );
                }

                // THE SAME TWO GUARDS THE PAYMENT ARM APPLIES, for the same
                // reasons: a button row rendered before a colleague cancelled
                // the parcel must not run an action the row would no longer
                // offer, and a state-changing arm must arrive as a POST with
                // a one-time key rather than as a URL that a refresh replays.
                // The order is resolved once and used for both the list and
                // the event, and admissibility is checked before the key is
                // consumed so a stale click is refused for one reason only.
                $orderForAction = self::orderModel()->getItem((int) $shipment->id_order);
                $actionId = $input->getCmd('action', '');
                $offered = self::getShipmentActions($shipment, $orderForAction);
                $event = new ExecuteShipmentActionEvent('onExecuteAction', [
                    'action' => $actionId,
                    'shipment' => $shipment,
                    'order' => $orderForAction,
                    'data' => [],
                ]);

                $refusal = self::refuseUnofferedAction($offered, $actionId);

                if ($refusal !== null) {
                    return self::refusedPage(group: $group, type: $type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_STALE', reason: $refusal);
                }

                $refusal = self::refuseUnsafeRequest(self::actionMutates($offered, $actionId), $input);

                if ($refusal !== null) {
                    return self::refusedPage(group: $group, type: $type, event: $event, messageKey: 'COM_ALFA_ACTION_REFUSED_REPEATED', reason: $refusal);
                }

                $app->bootPlugin((string) $type, 'alfa-shipments')->onExecuteAction($event);

                return ['group' => $group, 'type' => (string) $type, 'event' => $event];

            default:
                throw new RuntimeException('Unsupported action group: ' . $group, 400);
        }
    }

    /**
     * The page a refused action answers with: the result notice, never an error page.
     *
     * The action page runs in its screen's modal frame, where an exception is
     * Joomla's error page: no Close, no Reload, and the stale row still on the
     * screen behind it. A refusal is the notice every action result already uses,
     * so the operator reads why, and OK closes the frame and reloads the screen,
     * which then offers only what can run now.
     *
     * The operator is told in plain words. The precise reason names internal
     * action ids, so it is shown only with Debug System on.
     *
     * @param string $group The action group.
     * @param string $type The plugin element.
     * @param ExecutePaymentActionEvent|ExecuteShipmentActionEvent|ExecuteFieldActionEvent $event The event the page renders.
     * @param string $messageKey What the operator is told.
     * @param string $reason Why it was refused, for debugging.
     * @param string $shown The reason in the operator's words, put into the
     *                      message; '' when the message says it all.
     *
     * @return array{group: string, type: string, event: object}
     *
     * @since  2.0.0
     */
    private static function refusedPage(
        string $group,
        string $type,
        ExecutePaymentActionEvent|ExecuteShipmentActionEvent|ExecuteFieldActionEvent $event,
        string $messageKey,
        string $reason,
        string $shown = '',
    ): array {
        self::trace('Action refused: ' . $reason);

        $event->setError($shown === '' ? Text::_($messageKey) : Text::sprintf($messageKey, $shown));
        $event->setRefresh(true);

        return ['group' => $group, 'type' => $type, 'event' => $event];
    }

    /**
     * The permissions an action page needs, every one of them, decided by what the action
     * works on, asked before {@see self::resolveActionPage()} dispatches anything.
     *
     * Payment and shipment actions act on an order's rows, so they need orders edit. An
     * item-field action may write the field's configuration on the catalogue item at any
     * scope (an order line's save lands on the item), so it needs items edit, and on an
     * order line orders edit as well: the item-field endpoint asks the same. The scope is
     * normalised exactly as resolveActionPage() normalises it.
     *
     * @param Input $input The request input (group + scope).
     *
     * @return array<int, array{section: string, action: string}>|null Null for a group no action page serves.
     *
     * @since  2.0.0
     */
    public static function actionPagePermissions(Input $input): ?array
    {
        $action = $input->getCmd('action', '');
        $formOf = str_ends_with($action, '_do') ? substr($action, 0, -3) : $action;

        return match ($input->getCmd('group', 'alfa-item-fields')) {
            // An amount typed for a provider's refund, and a report resolved
            // without a booking, need their own right as well (granted to no group).
            'alfa-payments' => \in_array($formOf, self::REPORT_FORMS, true)
                ? [['section' => 'orders', 'action' => 'edit'], ['section' => 'orders', 'action' => 'refund.resolve']]
                : [['section' => 'orders', 'action' => 'edit']],
            'alfa-shipments' => [['section' => 'orders', 'action' => 'edit']],
            'alfa-item-fields' => $input->getCmd('scope', 'item') === 'order'
                ? [['section' => 'items', 'action' => 'edit'], ['section' => 'orders', 'action' => 'edit']]
                : [['section' => 'items', 'action' => 'edit']],
            default => null,
        };
    }

    /**
     * The COMPONENT'S OWN actions for money that moved without a gateway.
     *
     * Store credit is spent through the wallet, so no gateway plugin took it
     * and none can give it back. The ledger can: refundPayment() writes the
     * refund row and settleWalletForPayment() returns the money to the
     * customer's balance. These two buttons are the admin's way to reach that,
     * and they call the ledger DIRECTLY: no bootPlugin(), no gateway method.
     *
     * Offered under the same conditions the gateway plugins use, so a credit
     * row cannot be refunded on terms a card payment could not: the row must
     * be a 'completed' PAYMENT, and it must still have a refundable remainder.
     * A refund to the wallet carries the credit classification too (it
     * inherits it from the payment it refunds), and it is money going back,
     * not money to give back; refundPayment() would refuse it anyway, so a
     * button offered there could only fail. So is a payment that names no
     * wallet spend: only the spend link can move wallet money, and the ledger
     * refuses a wallet refund without one.
     *
     * @param object $payment Payment row (already known to be a non-gateway type).
     *
     * @return PluginAction[]
     *
     * @since  2.0.0
     */
    private static function creditActions(object $payment): array
    {
        $paymentId = (int) ($payment->id ?? 0);

        if ($paymentId <= 0
            || (string) ($payment->payment_type ?? '') !== PaymentLedger::TYPE_PAYMENT
            || (int) ($payment->id_credit_transaction ?? 0) <= 0
            || (string) ($payment->transaction_status ?? '') !== PaymentLedger::STATUS_COMPLETED) {
            return [];
        }

        // The remainder, not the gross: partials may already have run. Same
        // figure the ledger's own cap enforces, so a button that appears is a
        // button that works.
        if (!PaymentLedger::refundableRemainder($paymentId)->isPositive()) {
            return [];
        }

        return [
            new PluginAction([
                'id' => 'credit_refund',
                'label' => Text::_('COM_ALFA_CREDIT_REFUND'),
                'icon' => 'undo-2',
                'class' => 'btn-warning',
                'requires_confirmation' => true,
                'confirmation_message' => Text::_('COM_ALFA_CREDIT_REFUND_CONFIRM'),
                'priority' => 150,
                // Returns money: submitted, never opened as a URL.
                'mutates' => true,
                'starts_refund' => true,
            ]),
            new PluginAction([
                'id' => 'credit_refund_partial',
                'label' => Text::_('COM_ALFA_CREDIT_REFUND_PARTIAL'),
                'icon' => 'undo-2',
                'class' => 'btn-outline-warning',
                'response_layout' => 'action_credit_refund_partial',
                'modal_title' => Text::_('COM_ALFA_CREDIT_REFUND_PARTIAL'),
                'modal_size' => 'sm',
                'priority' => 140,
                // The form itself only asks for an amount; the arm that MOVES
                // the money is credit_refund_partial_do, which is not offered
                // in this list and therefore falls to the fail-closed default.
                'mutates' => false,
                'starts_refund' => true,
            ]),
        ];
    }

    /**
     * Execute a core-owned credit action. Never boots a plugin.
     *
     * The AMOUNT rules are split deliberately: the boundary refuses input that
     * is not an amount at all (missing, not a decimal, not positive, not
     * expressible in the order's currency), and the ledger refuses an amount
     * that is not REFUNDABLE (over the remainder, over the pool, a foreign
     * currency). null (and only null) means "the remainder".
     *
     * @param object $payment The credit payment row.
     * @param object $order Its order.
     * @param string $action 'credit_refund' or 'credit_refund_partial'.
     * @param Input $input Request input (carries the partial amount).
     *
     * @return ExecutePaymentActionEvent The event the action page renders.
     *
     * @since  2.0.0
     */
    private static function executeCreditAction(
        object $payment,
        object $order,
        string $action,
        Input $input,
    ): ExecutePaymentActionEvent {
        $event = new ExecutePaymentActionEvent('onExecuteAction', [
            'action' => $action,
            'payment' => $payment,
            'order' => $order,
            'data' => [],
        ]);

        $paymentId = (int) ($payment->id ?? 0);
        $remainder = PaymentLedger::refundableRemainder($paymentId);

        // NON-MUTATING ARM: render the amount form and stop. The house splits
        // these deliberately: a GET that refunds money is a GET a browser
        // prefetch or a bookmarked URL can fire.
        if ($action === 'credit_refund_partial') {
            $event->setLayout('action_credit_refund_partial');
            $event->setLayoutData([
                'payment' => $payment,
                'order' => $order,
                'remainder' => $remainder,
            ]);

            return $event;
        }

        if (!in_array($action, ['credit_refund', 'credit_refund_partial_do'], true)) {
            $event->setError(Text::sprintf('COM_ALFA_CREDIT_REFUND_UNKNOWN_ACTION', $action));

            return $event;
        }

        // null = the remainder, and ONLY null. The partial arm has to produce a
        // real amount or a sentence: the ledger no longer turns a zero into
        // "everything left", and an operator must not read a ledger refusal
        // where they made a typing mistake.
        $amount = null;

        if ($action === 'credit_refund_partial_do') {
            $amount = self::partialRefundAmount($input, $remainder);

            if (\is_string($amount)) {
                $event->setError(Text::sprintf('COM_ALFA_CREDIT_REFUND_FAILED', $amount));

                return $event;
            }
        }

        $result = PaymentLedger::refundPayment($paymentId, $amount, [
            'reason' => Text::sprintf('COM_ALFA_CREDIT_REFUND_REASON', $paymentId),
        ]);

        if ($result['outcome'] !== PaymentLedger::OUTCOME_OK) {
            $event->setError(Text::sprintf('COM_ALFA_CREDIT_REFUND_FAILED', $result['reason']));

            return $event;
        }

        $event->setSuccess(true);
        $event->setMessage(Text::sprintf(
            'COM_ALFA_CREDIT_REFUND_DONE',
            $remainder->getCurrency()->format((float) $result['amount']),
        ));
        $event->setRefresh(true);

        return $event;
    }

    /**
     * The typed partial-refund amount, or the sentence that refuses it.
     *
     * A monetary ZERO must never stand for "no amount was supplied". The ledger
     * returns the remainder for null alone, so a blank field, a typo or a figure
     * the order's currency cannot express is refused HERE, before a Money
     * exists. Casting the raw request with (float) is what erased the
     * difference: '', 'abc' and '0' all became 0.0, and 0.0 used to mean "give
     * back everything".
     *
     * Validated in order: present; read exactly by the one reader of a typed
     * amount ({@see ExactMoney}): a valid decimal, no finer than the
     * currency's own scale, no larger than an amount of it holds; greater
     * than zero. The figure is never rounded and never read through a float.
     *
     * @param Input $input The request carrying 'amount'.
     * @param Money $remainder The refundable remainder; its currency decides
     *                         the scale, and its figure names the cap.
     *
     * @return Money|string The amount, or a translated refusal for the operator.
     *
     * @since  2.0.0
     */
    private static function partialRefundAmount(Input $input, Money $remainder): Money|string
    {
        // NOT trimmed. Padding is not a decimal, and silently repairing the
        // input is the same class of helpfulness that made '' mean "all of it".
        $raw = (string) $input->getString('amount', '');

        // 1. PRESENT. No default of '0': a default is exactly the overloaded
        //    zero this contract removes.
        if ($raw === '') {
            return Text::sprintf('COM_ALFA_CREDIT_REFUND_AMOUNT_MISSING', $remainder->format());
        }

        // 2. READ EXACTLY. A valid decimal (not is_numeric(), which admits
        //    '1e3', ' 1' and '.5'): one optional sign, digits, one separator:
        //    the comma is the operator's, the dot is the machine's. Never
        //    rounded: 0.004 on a 2-decimal order would arrive as zero and
        //    5.004 as 5.00, a figure the operator never typed, returned from a
        //    real wallet.
        $currency = $remainder->getCurrency();

        try {
            $amount = ExactMoney::of(value: $raw, currency: $currency, commaDecimal: true);
        } catch (ExactMoneyRefusal $e) {
            return match ($e->reason()) {
                ExactMoney::NOT_A_NUMBER => Text::sprintf('COM_ALFA_CREDIT_REFUND_AMOUNT_INVALID', $raw),
                ExactMoney::FINER => Text::sprintf('COM_ALFA_CREDIT_REFUND_AMOUNT_SCALE', $raw, $currency->getCode(), $currency->getDecimalPlaces()),
                default => $e->words(field: Text::_('COM_ALFA_CREDIT_REFUND_AMOUNT')),
            };
        }

        // 3. GREATER THAN ZERO. '0', '0.00' and '-5' are refused, and the
        //    message names the button that DOES return everything.
        if (!$amount->isPositive()) {
            return Text::sprintf('COM_ALFA_CREDIT_REFUND_AMOUNT_NOT_POSITIVE', $remainder->format());
        }

        return $amount;
    }

    /**
     * Which plugin element owns this row: its snapshot column, and nothing else.
     *
     * The snapshot column is the authority: it was written when the row was
     * created and survives the method being deleted, which is the whole point
     * of having it. A row that records none (written before the snapshot
     * existed) is UNKNOWN: its type is never inferred from the live method,
     * which may have been edited or replaced since (no fallbacks, since 2.1.1).
     *
     * Returns '' when the row records none. '' means UNKNOWN: no caller assumes
     * a plugin for it, never "standard" (a wrong plugin booted against a real
     * payment is worse than none). The action page refuses it; an order delete
     * has no plugin to ask for a veto and decides on the core's own refusals.
     *
     * Public because the four order templates need the same answer, and four
     * hand-rolled copies is how `?? 'standard'` got into all of them.
     *
     * @param object $row Payment or shipment record
     * @param string $column 'payment_method_type' or 'shipment_method_type'
     *
     * @return string Plugin element, or '' when the row records none
     *
     * @since  2.0.0
     */
    public static function methodType(object $row, string $column): string
    {
        return (string) ($row->{$column} ?? '');
    }

    /**
     * The admin Order model: loads payment/shipment/order context for the
     * action page. Created with ignore_request so it reads only what we pass.
     *
     *
     * @since  2.0.0
     */
    private static function orderModel(): object
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Order', 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Get available actions for a payment.
     *
     * @param object $payment Payment record (must have params->type)
     * @param object $order Order record
     *
     * @return array Array of PluginAction objects
     *
     * @since  1.0.0
     */
    public static function getPaymentActions(object $payment, object $order): array
    {
        return self::paymentOffer(payment: $payment, order: $order)['actions'];
    }

    /**
     * What a payment row offers now: the plugin's actions, Record this refund
     * for each refund its gateway reports and the books do not hold, and the
     * ledger's, less every action that starts a refund while the refund block holds.
     *
     * An action declared with PluginAction::startsRefund() is withheld while a
     * provider has reported a refund on the payment's order that the books do
     * not hold ({@see PaymentLedger::refundStartRefusal()}, asked only when such
     * an action is on offer). The action page refuses a withheld action, and the
     * arm its form submits to, with that reason. Answers the actions offered,
     * the ids withheld, and why.
     *
     * @param object $payment Payment record.
     * @param object $order Order record.
     *
     * @return array{actions: array, withheld: list<string>, refusal: string|null}
     *
     * @since  2.1.0
     */
    private static function paymentOffer(object $payment, object $order): array
    {
        $ledger = self::ledgerActions($payment);

        // A reversal or a chargeback is the ledger's own record: no plugin is
        // asked about it.
        if (\in_array((string) ($payment->payment_type ?? ''), PaymentLedger::FACT_TYPES, true)) {
            return ['actions' => $ledger, 'withheld' => [], 'refusal' => null];
        }

        // Merged outside resolveActions(), so a plugin that offers nothing
        // (disabled, missing, throwing) still leaves the ledger's actions.
        $actions = array_merge(
            self::resolveActions(
                'alfa-payments',
                $payment,
                $order,
                GetPaymentActionsEvent::class,
                'Payment',
            ),
            self::reportActions(payment: $payment),
            $ledger,
        );

        $startsRefund = static fn ($action): bool => (bool) (\is_array($action) ? ($action['starts_refund'] ?? false) : ($action->starts_refund ?? false));
        $starters = self::offeredIds(offered: array_values(array_filter($actions, $startsRefund)));
        $refusal = $starters === [] ? null : PaymentLedger::refundStartRefusal(orderId: (int) ($payment->id_order ?? 0));

        if ($refusal === null) {
            return ['actions' => $actions, 'withheld' => [], 'refusal' => null];
        }

        return [
            'actions' => array_values(array_filter($actions, static fn ($action): bool => !$startsRefund($action))),
            'withheld' => $starters,
            'refusal' => $refusal,
        ];
    }

    /**
     * The COMPONENT'S OWN actions for recording a provider fact on a gateway row.
     *
     * Record failed refund (a settled refund the provider reports as failed or
     * returned), Record won dispute (a chargeback the provider gave back) and
     * Record chargeback (a completed payment the bank took money back from).
     * They are the core-owned manual recording path for the
     * gateways that report no such event, and never boot a plugin.
     *
     * Only on a gateway row: a row whose class is empty or non-gateway (the
     * wallet) is offered none.
     *
     * @param object $payment Payment row.
     *
     * @return PluginAction[]
     *
     * @since  2.0.0
     */
    private static function ledgerActions(object $payment): array
    {
        $rowId = (int) ($payment->id ?? 0);
        $class = self::methodType($payment, 'payment_method_type');
        $type = (string) ($payment->payment_type ?? '');
        $status = (string) ($payment->transaction_status ?? '');

        if ($rowId <= 0
            || $class === ''
            || \in_array($class, PaymentLedger::NON_GATEWAY_TYPES, true)
            || (int) ($payment->id_credit_transaction ?? 0) > 0) {
            return [];
        }

        $action = null;

        if ($type === PaymentLedger::TYPE_REFUND
            && \in_array($status, [PaymentLedger::STATUS_REFUNDED, PaymentLedger::STATUS_COMPLETED], true)
            && PaymentLedger::unreversedRemainder($rowId)->isPositive()) {
            $action = ['ledger_reversal', 'COM_ALFA_LEDGER_RECORD_REFUND_FAILED', 'undo-2'];
        } elseif ($type === PaymentLedger::TYPE_CHARGEBACK
            && $status === PaymentLedger::STATUS_COMPLETED
            && PaymentLedger::unreversedRemainder($rowId)->isPositive()) {
            $action = ['ledger_reversal', 'COM_ALFA_LEDGER_RECORD_DISPUTE_WON', 'undo-2'];
        } elseif ($type === PaymentLedger::TYPE_PAYMENT && $status === PaymentLedger::STATUS_COMPLETED) {
            $action = ['ledger_chargeback', 'COM_ALFA_LEDGER_RECORD_CHARGEBACK', 'warning'];
        }

        if ($action === null) {
            return [];
        }

        [$id, $labelKey, $icon] = $action;

        return [
            new PluginAction([
                'id' => $id,
                'label' => Text::_($labelKey),
                'icon' => $icon,
                'class' => 'btn-outline-danger',
                'response_layout' => 'action_ledger_fact',
                'modal_title' => Text::_($labelKey),
                'modal_size' => 'sm',
                'priority' => 40,
                // The form only asks; the arm that records (<id>_do) is never
                // offered and falls to the fail-closed default.
                'mutates' => false,
            ]),
        ];
    }

    /**
     * The report actions of a payment row, for each refund its gateway reports
     * and the books do not hold: the way out of the refund block.
     *
     * A gateway's own reports are offered on its own payment rows. The core's
     * own reports (money a provider moved that the core keeps for staff,
     * REPORT_RESERVATION_PREFIX ids) are offered on every payment row, whatever
     * it records as its type (none, the customer's credit), so staff always
     * have a way out of the refund block they hold (since 2.1.1). Per report,
     * those that hold the refund block first, then those that reserve their
     * amount, each in the order listed: Record this refund for a settled report
     * with an exact amount (orders: edit); for an operator who may resolve reports,
     * Record this refund with an amount to enter for a settled report with none,
     * and Reconcile to an existing refund and Duplicate or invalid report for
     * every report but a refund request this site sent, which its gateway alone
     * ends. A report the provider has not settled yet offers no booking. Each
     * posts the report's reference and nothing else; the ledger reads the
     * report again when it acts. A label never carries a provider's value: the
     * button prints labels as they are.
     *
     * @param object $payment Payment row.
     *
     * @return PluginAction[]
     *
     * @since  2.1.0
     */
    private static function reportActions(object $payment): array
    {
        $paymentId = (int) ($payment->id ?? 0);

        if ($paymentId <= 0 || (string) ($payment->payment_type ?? '') !== PaymentLedger::TYPE_PAYMENT) {
            return [];
        }

        $class = self::methodType(row: $payment, column: 'payment_method_type');
        $gatewayRow = $class !== ''
            && !\in_array($class, PaymentLedger::NON_GATEWAY_TYPES, true)
            && (int) ($payment->id_credit_transaction ?? 0) <= 0;
        $answer = PaymentLedger::unbookedProviderRefundsOfPayment(paymentId: $paymentId);
        $refunds = array_values(array_filter(
            array_merge($answer['refunds'], $answer['reserved']),
            static fn (array $refund): bool => $gatewayRow || str_starts_with((string) $refund['reportId'], PaymentLedger::REPORT_RESERVATION_PREFIX),
        ));

        if ($refunds === []) {
            return [];
        }

        $mayResolve = AlfaHelper::can(section: 'orders', action: 'refund.resolve');
        $actions = [];

        foreach ($refunds as $refund) {
            $reference = $refund['id'] ?? Text::sprintf('COM_ALFA_REPORT_NO_ID', $refund['reportId']);
            $posts = [self::REPORT_FIELD => $refund['reportId']];
            $tooltip = Text::sprintf('COM_ALFA_RECORD_REFUND_TIP', $reference, $refund['providerAmount'] . ' ' . $refund['providerCurrency'], $refund['reportedOn']);

            $settled = $refund['status'] === PaymentLedger::REPORT_STATUS_SETTLED;

            if ($settled && $refund['amount'] !== null) {
                $amount = $refund['amount']->format();
                $actions[] = new PluginAction([
                    'id' => self::RECORD_REFUND,
                    'label' => Text::sprintf('COM_ALFA_RECORD_REFUND', $amount),
                    'tooltip' => $tooltip,
                    'icon' => 'checkmark',
                    'class' => 'btn-warning',
                    'requires_confirmation' => true,
                    'confirmation_message' => Text::sprintf('COM_ALFA_RECORD_REFUND_CONFIRM', $reference, $amount, $paymentId, $refund['reportedOn']),
                    'data' => $posts,
                    'priority' => 160,
                    // Books money: submitted, never opened as a URL.
                    'mutates' => true,
                ]);
            } elseif ($settled && $mayResolve) {
                $actions[] = self::reportForm(id: self::RECORD_REFUND_TYPED, labelKey: 'COM_ALFA_RECORD_REFUND_TYPED', class: 'btn-warning', tooltip: $tooltip, posts: $posts);
            }

            // A refund request this site sent is ended by its gateway alone, never resolved here.
            if ($mayResolve && !str_starts_with($refund['reportId'], PaymentLedger::REPORT_REQUEST_PREFIX)) {
                $actions[] = self::reportForm(id: self::RECONCILE_REPORT, labelKey: 'COM_ALFA_RECONCILE_REPORT', class: 'btn-outline-secondary', tooltip: $tooltip, posts: $posts);
                $actions[] = self::reportForm(id: self::DISMISS_REPORT, labelKey: 'COM_ALFA_DISMISS_REPORT', class: 'btn-outline-secondary', tooltip: $tooltip, posts: $posts);
            }
        }

        return $actions;
    }

    /**
     * One report action that opens its form (read only; its _do arm writes).
     *
     * @param string $id The action id.
     * @param string $labelKey Its label.
     * @param string $class The button's class.
     * @param string $tooltip The report's facts.
     * @param array<string, string> $posts The report's reference.
     *
     * @since  2.1.0
     */
    private static function reportForm(string $id, string $labelKey, string $class, string $tooltip, array $posts): PluginAction
    {
        return new PluginAction([
            'id' => $id,
            'label' => Text::_($labelKey),
            'tooltip' => $tooltip,
            'icon' => 'pencil',
            'class' => $class,
            'response_layout' => 'action_refund_report',
            'modal_title' => Text::_($labelKey),
            'modal_size' => 'md',
            'data' => $posts,
            'priority' => 150,
            'mutates' => false,
        ]);
    }

    /**
     * Whether an action id is one of the report actions (or the arm a form submits to).
     *
     * @param string $actionId The requested action.
     *
     * @since  2.1.0
     */
    private static function isReportAction(string $actionId): bool
    {
        $formOf = str_ends_with($actionId, '_do') ? substr($actionId, 0, -3) : '';

        return $actionId === self::RECORD_REFUND
            || \in_array($actionId, self::REPORT_FORMS, true)
            || \in_array($formOf, self::REPORT_FORMS, true);
    }

    /**
     * Execute a core-owned ledger action. Never boots a plugin.
     *
     * The form arms render the Record form; the _do arms read the typed amount,
     * reference and reason and call the ledger's verb, which decides. A refused
     * submission brings the form back holding what the operator typed. The
     * report actions are answered by executeReportAction().
     *
     * @param object $payment The refund, chargeback or payment row.
     * @param object $order Its order.
     * @param string $action ledger_reversal, ledger_chargeback, either with _do, or a report action.
     * @param Input $input Request input (amount, reference, reason; a report's reference).
     *
     * @return ExecutePaymentActionEvent The event the action page renders.
     *
     * @since  2.0.0
     */
    private static function executeLedgerAction(object $payment, object $order, string $action, Input $input): ExecutePaymentActionEvent
    {
        $event = new ExecutePaymentActionEvent('onExecuteAction', [
            'action' => $action,
            'payment' => $payment,
            'order' => $order,
            'data' => [],
        ]);

        $rowId = (int) ($payment->id ?? 0);
        $isRefund = (string) ($payment->payment_type ?? '') === PaymentLedger::TYPE_REFUND;

        if (self::isReportAction(actionId: $action)) {
            return self::executeReportAction(event: $event, payment: $payment, order: $order, action: $action, input: $input);
        }

        // The order's currency at its frozen scale: the one the ledger's verbs
        // compare against.
        $currency = PaymentLedger::paidTotal((int) ($payment->id_order ?? 0))->getCurrency();

        if ($action === 'ledger_reversal' || $action === 'ledger_chargeback') {
            return self::ledgerForm(event: $event, payment: $payment, order: $order, formAction: $action, currency: $currency);
        }

        if (!\in_array($action, ['ledger_reversal_do', 'ledger_chargeback_do'], true)) {
            $event->setError(Text::sprintf('COM_ALFA_LEDGER_UNKNOWN_ACTION', $action));

            return $event;
        }

        // What the operator typed, exactly: a refusal brings the form back with it.
        $formAction = substr($action, 0, -\strlen('_do'));
        $typed = [
            'amount' => self::typedValue(input: $input, name: 'amount'),
            'reference' => self::typedValue(input: $input, name: 'reference'),
            'reason' => self::typedValue(input: $input, name: 'reason'),
        ];

        $amount = self::ledgerAmount(input: $input, currency: $currency);

        if (\is_string($amount)) {
            return self::ledgerForm(event: $event, payment: $payment, order: $order, formAction: $formAction, currency: $currency, typed: $typed, refusal: $amount);
        }

        // The reference as typed, unfiltered: the complete value is the identity,
        // letter case and trailing spaces included.
        // Every screen escapes it when it prints it.
        $reference = $typed['reference'];

        if (trim($reference) === '') {
            return self::ledgerForm(event: $event, payment: $payment, order: $order, formAction: $formAction, currency: $currency, typed: $typed, refusal: Text::_('COM_ALFA_LEDGER_REFERENCE_MISSING'));
        }

        $options = [
            'transaction_id' => $reference,
            'reason' => trim($input->getString('reason', '')),
        ];

        if ($action === 'ledger_chargeback_do') {
            $result = PaymentLedger::recordChargeback(paymentId: $rowId, amount: $amount, options: $options);
            $messageKey = 'COM_ALFA_LEDGER_CHARGEBACK_DONE';
        } else {
            $result = PaymentLedger::recordReversal(rowId: $rowId, amount: $amount, options: $options);
            $messageKey = $isRefund ? 'COM_ALFA_LEDGER_REVERSAL_DONE' : 'COM_ALFA_LEDGER_DISPUTE_WON_DONE';
        }

        if ($result['outcome'] !== PaymentLedger::OUTCOME_RECORDED) {
            return self::ledgerForm(event: $event, payment: $payment, order: $order, formAction: $formAction, currency: $currency, typed: $typed, refusal: (string) $result['reason']);
        }

        $event->setSuccess(true);
        $event->setMessage(Text::sprintf($messageKey, $currency->format((float) $result['amount'])));
        $event->setRefresh(true);

        return $event;
    }

    /**
     * The Record form of a ledger action: fresh, or refused and holding what was typed.
     *
     * A refused submission comes back as the form, not as the result notice, whose
     * Close would throw the typed amount, reference and reason away. The refusal
     * reads above the fields, and the event still answers as refused.
     *
     * @param ExecutePaymentActionEvent $event The event the action page renders.
     * @param object $payment The refund, chargeback or payment row.
     * @param object $order Its order.
     * @param string $formAction ledger_reversal or ledger_chargeback.
     * @param Currency $currency The order's currency at its frozen scale.
     * @param array $typed What the operator typed: amount, reference, reason.
     * @param string $refusal Why the submission was refused, plain text; '' for a fresh form.
     *
     *
     * @since  2.0.0
     */
    private static function ledgerForm(
        ExecutePaymentActionEvent $event,
        object $payment,
        object $order,
        string $formAction,
        Currency $currency,
        array $typed = [],
        string $refusal = '',
    ): ExecutePaymentActionEvent {
        $isRefund = (string) ($payment->payment_type ?? '') === PaymentLedger::TYPE_REFUND;
        $message = $refusal === '' ? '' : Text::sprintf('COM_ALFA_LEDGER_ACTION_FAILED', $refusal);

        if ($message !== '') {
            $event->setError($message);
        }

        $event->setLayout('action_ledger_fact');
        $event->setLayoutData([
            'kind' => $formAction === 'ledger_chargeback' ? 'chargeback' : ($isRefund ? 'refund_reversal' : 'dispute_won'),
            'payment' => $payment,
            'order' => $order,
            'cap' => $formAction === 'ledger_reversal' ? PaymentLedger::unreversedRemainder(rowId: (int) ($payment->id ?? 0)) : null,
            'currency' => $currency,
            'submit' => $formAction . '_do',
            'typed' => $typed,
            'refusal' => $message,
        ]);

        return $event;
    }

    /**
     * Answer a report action: Record this refund, its typed amount, Reconcile to
     * an existing refund, Duplicate or invalid report. Never boots a plugin.
     *
     * A form arm shows the report's facts as the gateway lists it now, unchanged,
     * and asks what its _do arm needs; a report already settled or no longer
     * listed answers with why instead. The _do arms and Record this refund call
     * the ledger's verb, which decides. A refused form submission brings the
     * form back holding what was typed; every other answer reloads the screen.
     *
     * @param ExecutePaymentActionEvent $event The event the action page renders.
     * @param object $payment The payment the report is about.
     * @param object $order Its order.
     * @param string $action The report action, or a form's _do arm.
     * @param Input $input The request: the report's reference; amount, refund and reason as typed.
     *
     * @return ExecutePaymentActionEvent The event, answered.
     *
     * @since  2.1.0
     */
    private static function executeReportAction(ExecutePaymentActionEvent $event, object $payment, object $order, string $action, Input $input): ExecutePaymentActionEvent
    {
        $paymentId = (int) ($payment->id ?? 0);
        $reportId = self::typedValue(input: $input, name: self::REPORT_FIELD);
        $currency = PaymentLedger::paidTotal(orderId: (int) ($payment->id_order ?? 0))->getCurrency();
        $formOf = str_ends_with($action, '_do') ? substr($action, 0, -3) : '';

        if (\in_array($action, self::REPORT_FORMS, true)) {
            return self::reportPage(event: $event, payment: $payment, order: $order, form: $action, reportId: $reportId, currency: $currency);
        }

        $typed = [
            'amount' => self::typedValue(input: $input, name: 'amount'),
            'refund' => self::typedValue(input: $input, name: 'refund'),
            'reason' => self::typedValue(input: $input, name: 'reason'),
        ];
        $back = static fn (string $why): ExecutePaymentActionEvent => self::reportPage(event: $event, payment: $payment, order: $order, form: $formOf, reportId: $reportId, currency: $currency, typed: $typed, refusal: $why);

        if ($action === self::RECORD_REFUND) {
            $result = PaymentLedger::recordReportedRefund(paymentId: $paymentId, reportId: $reportId);
        } elseif ($formOf === self::RECORD_REFUND_TYPED) {
            $amount = self::ledgerAmount(input: $input, currency: $currency);

            if (\is_string($amount)) {
                return $back($amount);
            }

            $result = PaymentLedger::recordReportedRefundTyped(paymentId: $paymentId, reportId: $reportId, amount: $amount, reason: $typed['reason']);
        } elseif ($formOf === self::RECONCILE_REPORT) {
            $result = PaymentLedger::reconcileReportedRefund(paymentId: $paymentId, reportId: $reportId, refundId: (int) $typed['refund'], reason: $typed['reason']);
        } else {
            $result = PaymentLedger::dismissReportedRefund(paymentId: $paymentId, reportId: $reportId, reason: $typed['reason']);
        }

        $reference = (string) ($result['reference'] ?? $reportId);
        $event->setRefresh(true);

        if ($result['outcome'] === PaymentLedger::OUTCOME_OK) {
            $event->setSuccess(true);
            $event->setMessage(Text::sprintf('COM_ALFA_RECORD_REFUND_DONE', $reference, $currency->format((float) $result['amount']), $paymentId));

            return $event;
        }

        if ($result['outcome'] === PaymentLedger::OUTCOME_RECORDED) {
            $event->setSuccess(true);
            $event->setMessage($formOf === self::RECONCILE_REPORT
                ? Text::sprintf('COM_ALFA_REPORT_RECONCILED_DONE', $reference, $paymentId, (int) $result['refund_id'])
                : Text::sprintf('COM_ALFA_REPORT_DISMISSED_DONE', $reference, $paymentId));

            return $event;
        }

        // Already settled in the books: what the operator wanted holds; nothing was written again.
        if ($result['outcome'] === PaymentLedger::OUTCOME_DUPLICATE) {
            $event->setSuccess(true);
            $event->setMessage((string) $result['reason']);

            return $event;
        }

        if ($action === self::RECORD_REFUND) {
            $event->setError(Text::sprintf('COM_ALFA_RECORD_REFUND_FAILED', $reference, (string) $result['reason']));

            return $event;
        }

        return $back((string) $result['reason']);
    }

    /**
     * The form of a report action, fresh or refused: the report's facts as the
     * gateway lists them, unchanged, and the fields the action asks for.
     *
     * A report already settled, or no longer listed, answers with why instead
     * of a form, and the screen reloads.
     *
     * @param ExecutePaymentActionEvent $event The event the action page renders.
     * @param object $payment The payment.
     * @param object $order Its order.
     * @param string $form RECORD_REFUND_TYPED, RECONCILE_REPORT or DISMISS_REPORT.
     * @param string $reportId The report's reference.
     * @param Currency $currency The order's currency at its frozen scale.
     * @param array $typed What was typed (amount, refund, reason) on a refused submission.
     * @param string $refusal Why it was refused, plain text; '' for a fresh form.
     *
     * @since  2.1.0
     */
    private static function reportPage(
        ExecutePaymentActionEvent $event,
        object $payment,
        object $order,
        string $form,
        string $reportId,
        Currency $currency,
        array $typed = [],
        string $refusal = '',
    ): ExecutePaymentActionEvent {
        $report = PaymentLedger::reportedRefund(paymentId: (int) ($payment->id ?? 0), reportId: $reportId);

        // Already settled (another click, another operator): said as done; gone or unreadable: said as refused.
        if ($report['entry'] === null) {
            if ($report['settled']) {
                $event->setSuccess(true);
                $event->setMessage((string) $report['reason']);
            } else {
                $event->setError((string) $report['reason']);
            }

            $event->setRefresh(true);

            return $event;
        }

        $message = $refusal === '' ? '' : Text::sprintf('COM_ALFA_REPORT_RESOLVE_FAILED', $refusal);

        if ($message !== '') {
            $event->setError($message);
        }

        $event->setLayout('action_refund_report');
        $event->setLayoutData([
            'kind' => match ($form) {
                self::RECORD_REFUND_TYPED => 'typed',
                self::RECONCILE_REPORT => 'reconcile',
                default => 'dismiss',
            },
            'payment' => $payment,
            'order' => $order,
            'report' => $report['entry'],
            'reference' => $report['entry']['id'] ?? Text::sprintf('COM_ALFA_REPORT_NO_ID', $report['entry']['reportId']),
            'currency' => $currency,
            'candidates' => $form === self::RECONCILE_REPORT ? PaymentLedger::reconcilableRefunds(orderId: (int) ($payment->id_order ?? 0)) : [],
            'submit' => $form . '_do',
            'typed' => $typed,
            'refusal' => $message,
        ]);

        return $event;
    }

    /**
     * One field of the Record form exactly as posted: no filter, no trim.
     *
     * @param Input $input The request.
     * @param string $name amount, reference or reason.
     *
     * @return string The typed value; '' when absent or not a single value.
     *
     * @since  2.0.0
     */
    private static function typedValue(Input $input, string $name): string
    {
        $value = $input->get($name, '', 'raw');

        return \is_string($value) ? $value : '';
    }

    /**
     * The typed amount of a ledger fact, or the sentence that refuses it.
     *
     * The checks of {@see self::partialRefundAmount()}: present; read exactly
     * by {@see ExactMoney} (a valid decimal, no finer than the currency's own
     * scale, no larger than an amount of it holds); greater than zero. The
     * amount is required: a provider fact always states one.
     *
     * @param Input $input The request carrying 'amount'.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return Money|string The amount, or a translated refusal for the operator,
     *                      plain text: the page that shows it escapes it once.
     *
     * @since  2.0.0
     */
    private static function ledgerAmount(Input $input, Currency $currency): Money|string
    {
        // Not trimmed, as partialRefundAmount(): padding is not a decimal.
        $raw = (string) $input->getString('amount', '');

        if ($raw === '') {
            return Text::_('COM_ALFA_LEDGER_AMOUNT_MISSING');
        }

        try {
            $amount = ExactMoney::of(value: $raw, currency: $currency, commaDecimal: true);
        } catch (ExactMoneyRefusal $e) {
            return match ($e->reason()) {
                ExactMoney::NOT_A_NUMBER => Text::sprintf('COM_ALFA_LEDGER_AMOUNT_INVALID', $raw),
                ExactMoney::FINER => Text::sprintf('COM_ALFA_LEDGER_AMOUNT_SCALE', $raw, $currency->getCode(), $currency->getDecimalPlaces()),
                default => $e->words(field: Text::_('COM_ALFA_LEDGER_AMOUNT')),
            };
        }

        if (!$amount->isPositive()) {
            return Text::_('COM_ALFA_LEDGER_AMOUNT_NOT_POSITIVE');
        }

        return $amount;
    }

    /**
     * Get available actions for a shipment.
     *
     * @param object $shipment Shipment record (must have params->type)
     * @param object $order Order record
     *
     * @return array Array of PluginAction objects
     *
     * @since  1.0.0
     */
    public static function getShipmentActions(object $shipment, object $order): array
    {
        return self::resolveActions(
            'alfa-shipments',
            $shipment,
            $order,
            GetShipmentActionsEvent::class,
            'Shipment',
        );
    }

    /**
     * Get the action buttons for an item field on a backend surface. The
     * plugin type IS the field type; the SCOPE says which row is asking
     * ('item' | 'combination' | 'option' | 'order'), and the event arrives
     * pre-bound to that row's params.
     *
     * @param object $field Field definition row (->type, ->id)
     * @param string $scope 'item' | 'combination' | 'option' | 'order'
     * @param int $scopeId The scope row id (combination/option/order-item id)
     * @param int $itemId The owning item id
     *
     * @return array Array of PluginAction objects
     *
     * @since  2.0.0
     */
    public static function getItemFieldActions(
        object $field,
        string $scope,
        int $scopeId,
        int $itemId,
    ): array {
        $app = Factory::getApplication();
        $fieldId = $field->id ?? '?';

        try {
            $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');

            if (!$plugin || !method_exists($plugin, 'onGetFieldActions')) {
                return [];
            }

            $event = new GetFieldActionsEvent('onGetFieldActions', [
                'scope' => $scope,
                'scopeId' => $scopeId,
                'itemId' => $itemId,
                'field' => $field,
            ]);

            $plugin->onGetFieldActions($event);

            return $event->getActions();
        } catch (Throwable $e) {
            self::warn(
                "Item field #{$fieldId}: EXCEPTION: " . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return [];
        }
    }

    /**
     * Unified action resolution: shared by payments and shipments.
     *
     * Steps:
     *   1. Read params->type from the entity
     *   2. Boot the plugin via Joomla's bootPlugin()
     *   3. Verify onGetActions() method exists
     *   4. Dispatch event and collect actions
     *
     * Errors -> always shown as warnings (admin must know).
     * Trace  -> shown only when JDEBUG is on.
     *
     * @param string $pluginGroup Plugin group ('alfa-payments' or 'alfa-shipments')
     * @param object $entity Payment or shipment record
     * @param object $order Order record
     * @param string $eventClass Fully qualified event class name
     * @param string $label Human label for messages ('Payment' or 'Shipment')
     *
     * @return array Array of PluginAction objects
     *
     * @since  1.0.0
     */
    private static function resolveActions(
        string $pluginGroup,
        object $entity,
        object $order,
        string $eventClass,
        string $label,
    ): array {
        $app = Factory::getApplication();
        $entityId = $entity->id ?? '?';

        try {
            // Step 1: Read plugin type

            // The row's OWN snapshot, never the live method: a row's plugin is
            // the one that took it, which survives its method being deleted or
            // edited. A row that records none offers no plugin action.
            $pluginType = self::methodType(
                $entity,
                $pluginGroup === 'alfa-shipments' ? 'shipment_method_type' : 'payment_method_type',
            );

            if ($pluginType === '') {
                self::warn("{$label} #{$entityId}: no plugin action: the row records no method type.");

                return [];
            }

            // Money that moved without a gateway gets the COMPONENT'S OWN
            // actions. Refunding store credit is a real operation: the ledger
            // returns it to the customer's wallet; it simply has no gateway to
            // ask, so the core answers for it. No plugin is booted.
            if (in_array($pluginType, PaymentLedger::NON_GATEWAY_TYPES, true)) {
                self::trace("{$label} #{$entityId}: {$pluginType} - core-owned actions, no plugin");

                return $pluginGroup === 'alfa-payments' ? self::creditActions($entity) : [];
            }

            self::trace("{$label} #{$entityId}: type = \"{$pluginType}\"");

            // Step 2: Boot plugin
            $plugin = $app->bootPlugin($pluginType, $pluginGroup);

            if (!$plugin) {
                self::warn(
                    "{$label} #{$entityId}: Plugin \"{$pluginType}\" ({$pluginGroup}) could not be booted."
                    . ' Check: 1) Plugin installed? 2) Plugin enabled?'
                    . ' 3) services/provider.php correct? 4) Namespace correct?',
                );
                return [];
            }

            self::trace("{$label} #{$entityId}: Plugin booted -> " . get_class($plugin));

            // Step 3: Verify method
            if (!method_exists($plugin, 'onGetActions')) {
                self::warn(
                    "{$label} #{$entityId}: " . get_class($plugin)
                    . ' has no onGetActions() method.'
                    . ' The deployed plugin file may be outdated; re-upload it.',
                );
                return [];
            }

            // Step 4: Dispatch event
            $eventKey = ($label === 'Payment') ? 'payment' : 'shipment';

            $event = new $eventClass('onGetActions', [
                $eventKey => $entity,
                'order' => $order,
            ]);

            $plugin->onGetActions($event);

            // Step 5: Collect actions
            $actions = $event->getActions();

            if (empty($actions)) {
                self::trace(
                    "{$label} #{$entityId}: onGetActions() returned 0 actions."
                    . ' Plugin may conditionally exclude actions for current status.',
                );
            } else {
                $ids = array_map(fn ($a) => $a->id, $actions);
                self::trace(
                    "{$label} #{$entityId}: " . count($actions)
                    . ' action(s) -> [' . implode(', ', $ids) . ']',
                );
            }

            return $actions;
        } catch (Throwable $e) {
            self::warn(
                "{$label} #{$entityId}: EXCEPTION: " . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return [];
        }
    }

    /**
     * Show a warning: ALWAYS visible.
     *
     * Used for actual errors that prevent actions from working.
     * Admin must see these regardless of debug mode.
     *
     * @param string $message Warning message
     *
     *
     * @since  1.0.0
     */
    private static function warn(string $message): void
    {
        try {
            Factory::getApplication()->enqueueMessage(
                '<strong>[ActionRegistry]</strong> ' . htmlspecialchars($message),
                'warning',
            );
        } catch (Throwable $e) {
            // Application not available
        }
    }

    /**
     * Show a trace message: only when JDEBUG is enabled.
     *
     * Used for success-path step-by-step info.
     * Not needed in production.
     *
     * @param string $message Trace message
     *
     *
     * @since  1.0.0
     */
    private static function trace(string $message): void
    {
        if (defined('JDEBUG') && JDEBUG) {
            try {
                Factory::getApplication()->enqueueMessage(
                    '<strong>[ActionRegistry]</strong> ' . htmlspecialchars($message),
                    'info',
                );
            } catch (Throwable $e) {
                // Silently ignore
            }
        }
    }
}
