<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Model\InventoryauditModel;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryLineResolutionException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryOpenLinesException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitRetirementException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * The audited inventory actions of the inventory audit page.
 *
 * - reconcile:   sets a product's stock figures (and its combinations') to what its stock
 *                records say, and so offers its units that were kept from sale until then for
 *                sale again. The stock records are not changed.
 * - writeOff:    sets the stock held on a stock identity kept only for order history (the
 *                product itself of a product with combinations, an archived combination, any
 *                stock identity of an archived product) to zero, as adjustments carrying the
 *                operator's reason.
 * - resolveLine: records the stock identity an order line's stock moves against when the
 *                product or combination it was sold as no longer exists, or records that a line
 *                sold on the product itself keeps moving the product's own stock; or records a
 *                correction that takes the place of the line's resolution while no stock has
 *                moved for it yet, the earlier one kept. The order line itself is never changed.
 * - prioritise:  serves an order first: its open reservations come before every other one on
 *                the stock records they stand on, with the operator's reason, and the order's
 *                stock movements still owed are tried at once. Nothing else moves stock.
 *
 * Each is an explicit operator action: a POST with the form token, by a Super User, recorded
 * under that user through the inventory service. They are the only callers of the service's
 * repair, write-off, line-resolution and serve-first commands; nothing repairs, writes stock
 * off, maps an order line or serves an order out of turn on its own.
 *
 * @since  2.0.0
 */
class InventoryauditController extends BaseController
{
    /**
     * Where every audited action returns to.
     *
     * @var string
     *
     * @since  2.0.0
     */
    private const AUDIT_PAGE = 'index.php?option=com_alfa&view=inventoryaudit';

    /**
     * The source type the repair is recorded under.
     *
     * @var string
     *
     * @since  2.0.0
     */
    private const REPAIR_SOURCE = 'inventory_repair';

    /**
     * Repair one product's stock figures from its stock records.
     *
     * The repair also ends every hold that kept a unit of the product from sale because stock
     * was recorded on it while its figures were broken; the operator is told how many units
     * are offered for sale again.
     *
     *
     * @throws NotAllowed Without a POST, the form token or Super User rights.
     *
     * @since  2.0.0
     */
    public function reconcile(): void
    {
        $operator = $this->operator();
        $itemId = $this->input->post->getInt('id', 0);

        $this->setRedirect(url: Route::_(url: self::AUDIT_PAGE, xhtml: false));

        try {
            $result = (new InventoryService())->reconcile(
                itemId: $itemId,
                sourceType: self::REPAIR_SOURCE,
                sourceId: $itemId,
                context: new InventoryContext(userId: (int) $operator->id),
            );
        } catch (RuntimeException $e) {
            $this->setMessage(text: $this->refusal(e: $e), type: 'error');

            return;
        }

        $corrected = \count($result['corrections']);
        $released = (int) $result['holds_released'];
        $message = $corrected > 0
            ? Text::sprintf('COM_ALFA_INVENTORY_AUDIT_REPAIR_DONE', $itemId, (string) $result['operation_id'], $corrected)
            : Text::sprintf('COM_ALFA_INVENTORY_AUDIT_REPAIR_NOTHING', $itemId, (string) $result['operation_id']);

        if ($released > 0) {
            $message .= ' ' . Text::sprintf('COM_ALFA_INVENTORY_AUDIT_RECONCILE_HOLDS_RELEASED', $released);
        }

        $this->setMessage(text: $message);
    }

    /**
     * Write off the stock held on one stock identity kept only for order history: the product
     * itself of a product with combinations, an archived combination, or any stock identity of
     * an archived product, named by the posted product and combination (0 for the product
     * itself).
     *
     * A reason is required; without one nothing is written. It is recorded exactly as typed,
     * markup-like characters included, and escaped wherever it is shown. A refusal of the
     * inventory service (a stock identity still offered for sale, whose stock changes in the
     * stock editor; stock still reserved, an open reservation or a pending stock effect on it;
     * open order lines that still rely on it to deduct their stock; an identity that does not
     * exist) is shown to the operator, naming the stock identity, and nothing is written
     * either. Stock on a record that active reservations still claim is refused in the
     * inventory service's own words for the operator (the stock identity, the location, what is
     * reserved there and the least stock those reservations need), escaped once since a message
     * is shown as HTML. The service judges that once the identity is known to exist and to be
     * kept only for order history, and before the identity's other claims and the order lines
     * relying on it.
     *
     *
     * @throws NotAllowed Without a POST, the form token or Super User rights.
     *
     * @since  2.0.0
     */
    public function writeOff(): void
    {
        $operator = $this->operator();
        $itemId = $this->input->post->getInt('id', 0);
        $combinationId = $this->input->post->getInt('combination_id', 0);
        $reason = trim((string) $this->input->post->get(name: 'reason', default: '', filter: 'raw'));

        $this->setRedirect(url: Route::_(url: self::AUDIT_PAGE, xhtml: false));

        if ($reason === '') {
            $this->setMessage(text: Text::_('COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REASON_REQUIRED'), type: 'error');

            return;
        }

        try {
            $result = (new InventoryService())->writeOffStock(
                itemId: $itemId,
                combinationId: $combinationId,
                reason: $reason,
                context: new InventoryContext(userId: (int) $operator->id, note: $reason),
            );
        } catch (InventoryUnitRetirementException $e) {
            $this->setMessage(text: Text::sprintf(
                'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REFUSED_CLAIMS',
                $this->unitLabel(itemId: $itemId, combinationId: $combinationId),
                InventoryAvailabilityService::formatQuantity(value: $e->reserved),
                $e->activeReservations,
                $e->pendingEffects,
            ), type: 'error');

            return;
        } catch (InventoryOpenLinesException $e) {
            $this->setMessage(text: Text::sprintf(
                'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REFUSED_OPEN_LINES',
                $this->unitLabel(itemId: $e->itemId, combinationId: $e->combinationId),
                \count($e->lines),
                InventoryauditModel::openLinesText(lines: $e->lines),
            ), type: 'error');

            return;
        } catch (InventoryReservedFloorException $e) {
            // The service's words name the product, its variant and the location as typed.
            $this->setMessage(
                text: htmlspecialchars(string: $e->operatorMessage(), flags: ENT_QUOTES, encoding: 'UTF-8'),
                type: 'error',
            );

            return;
        } catch (RuntimeException $e) {
            $this->setMessage(
                text: $this->writeOffRefusal(e: $e, itemId: $itemId, combinationId: $combinationId),
                type: 'error',
            );

            return;
        }

        $this->setMessage(text: Text::sprintf(
            'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_DONE',
            $this->unitLabel(itemId: $itemId, combinationId: $combinationId),
            (string) $result['operation_id'],
            (int) $result['rows'],
        ));
    }

    /**
     * The operator's message for a write-off the inventory service refused without naming a
     * claim or an open order line: a stock identity still offered for sale is told to use the
     * stock editor, naming the identity; any other refusal is worded as the service states it
     * ({@see self::refusal()}).
     *
     * The inventory service has already refused when this runs; whether the identity is still
     * offered for sale is read only to choose the words.
     *
     * @param RuntimeException $e The refusal.
     * @param int $itemId The product posted.
     * @param int $combinationId The combination posted, 0 for the product itself.
     *
     *
     * @since  2.0.0
     */
    private function writeOffRefusal(RuntimeException $e, int $itemId, int $combinationId): string
    {
        if (!$e instanceof InventoryUnitException) {
            try {
                $model = $this->getModel(name: 'Inventoryaudit', prefix: 'Administrator', config: ['ignore_request' => true]);

                if ($model instanceof InventoryauditModel && $model->isCurrentUnit(itemId: $itemId, combinationId: $combinationId)) {
                    return Text::sprintf(
                        'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REFUSED_CURRENT',
                        $this->unitLabel(itemId: $itemId, combinationId: $combinationId),
                    );
                }
            } catch (Throwable $readFailure) {
                Log::add(
                    entry: sprintf(
                        'Inventory audit: a refused write-off of item #%d combination #%d could not be worded: %s',
                        $itemId,
                        $combinationId,
                        $readFailure->getMessage(),
                    ),
                    priority: Log::WARNING,
                    category: 'com_alfa.inventory',
                );
            }
        }

        return $this->refusal(e: $e);
    }

    /**
     * Record the stock identity one order line's stock moves against.
     *
     * Two actions, both recorded by the inventory service under the operator with a
     * mandatory reason: the historical parent identity (the line's stock moves against the
     * product itself) and binding to a stock unit (a combination of the same product, archived
     * ones included). Without a reason nothing is written; the service refuses everything
     * else it cannot record (a line whose own stock identity still exists, a combination of
     * another product, ...) and the operator is told why in words. The order line itself is
     * never changed.
     *
     * A correction names the line's effective resolution it takes the place of (the posted
     * supersedes; none for a first resolution). The service records it as a new resolution and
     * keeps the earlier one, only while no stock has moved for the line on the stock identity
     * the effective one recorded, and only when the named resolution is still the effective one
     * (a first resolution is likewise refused once one is recorded): a resolution recorded or
     * corrected after the page was loaded is never overwritten unseen.
     *
     * The stock movements of the line's order that were refused for want of that identity are
     * queued again by the same command. Each is tried here at once, before anything else is
     * read, and the operator is told how many applied, how many are still refused and how many
     * are not applied yet (a failure a retry may apply, such as a stock shortfall); those stay
     * queued for Apply outstanding stock movements. Anything not applied is a warning.
     *
     *
     * @throws NotAllowed Without a POST, the form token or Super User rights.
     *
     * @since  2.0.0
     */
    public function resolveLine(): void
    {
        $operator = $this->operator();
        $lineId = (int) $this->input->post->get(name: 'line_id', default: 0, filter: 'int');
        $action = (string) $this->input->post->get(name: 'action', default: '', filter: 'cmd');
        $reason = trim((string) $this->input->post->get(name: 'reason', default: '', filter: 'raw'));

        // The effective resolution a correction takes the place of; none for a first resolution.
        $supersedes = (int) $this->input->post->get(name: 'supersedes', default: 0, filter: 'int');
        $supersedes = $supersedes > 0 ? $supersedes : null;

        // A combination is chosen only when binding to a stock unit.
        $combinationId = $action === InventoryService::RESOLVE_BIND_UNIT
            ? (int) $this->input->post->get(name: 'combination_id', default: 0, filter: 'int')
            : 0;

        $this->setRedirect(url: Route::_(url: self::AUDIT_PAGE, xhtml: false));

        if ($reason === '') {
            $this->setMessage(text: Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON_REQUIRED'), type: 'error');

            return;
        }

        try {
            $result = (new InventoryService())->resolveLine(
                orderItemId: $lineId,
                action: $action,
                resolvedCombinationId: $combinationId,
                reason: $reason,
                supersedes: $supersedes,
                context: new InventoryContext(userId: (int) $operator->id),
            );
        } catch (InventoryLineResolutionException $e) {
            $this->setMessage(text: Text::sprintf(
                'COM_ALFA_INVENTORY_LINE_RESOLVE_REFUSED',
                $lineId,
                Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REFUSED_' . strtoupper($e->reason)),
            ), type: 'error');

            return;
        } catch (RuntimeException $e) {
            $this->setMessage(text: $this->refusal(e: $e), type: 'error');

            return;
        }

        // The order's refused stock movements, queued again by the resolution, are tried first:
        // the resolution is committed, so nothing read afterwards may keep them from running.
        $queued = \count($result['rearmed']);
        $applied = 0;
        $refused = 0;

        foreach ($result['rearmed'] as $obligation) {
            try {
                $drained = InventoryEffectOutbox::drain(
                    limit: 1,
                    onlySourceId: (int) $obligation['source_id'],
                    onlySourceType: (string) $obligation['source_type'],
                );
            } catch (Throwable $e) {
                // The drain settles what its effects throw; this is the queue itself failing.
                // The obligation stays queued and is counted as not applied.
                Log::add(
                    entry: sprintf(
                        'Inventory audit: the stock movement of %s #%d, queued again by an order-line resolution, could not be tried: %s',
                        (string) $obligation['source_type'],
                        (int) $obligation['source_id'],
                        $e->getMessage(),
                    ),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );

                continue;
            }

            $applied += (int) $drained['applied'];
            $refused += (int) $drained['refused'];
        }

        // A correction names the stock identity the line now moves against; a first resolution
        // names what it recorded.
        $message = $supersedes !== null
            ? Text::sprintf(
                'COM_ALFA_INVENTORY_LINE_RESOLVE_CORRECTED',
                $lineId,
                $this->resolvedLabel(action: $action, resolutionId: (int) $result['resolution_id'], asUnit: true),
            )
            : Text::sprintf(
                'COM_ALFA_INVENTORY_LINE_RESOLVE_DONE',
                $lineId,
                $this->resolvedLabel(action: $action, resolutionId: (int) $result['resolution_id'], asUnit: false),
            );

        if ($queued === 0) {
            $this->setMessage(text: $message);

            return;
        }

        // Neither applied nor refused here: failed in a way a retry may apply, or taken first by
        // another worker, which settles it. Apply outstanding stock movements retries what is left.
        $waiting = $queued - $applied - $refused;

        $this->setMessage(
            text: $message . ' ' . Text::sprintf(
                'COM_ALFA_INVENTORY_LINE_RESOLVE_REARMED',
                $queued,
                (int) $result['order_id'],
                $applied,
                $refused,
                $waiting,
            ),
            type: $applied < $queued ? 'warning' : 'message',
        );
    }

    /**
     * Serve one order first on the stock records its open reservations stand on: the explicit,
     * audited override of first reserved, first served.
     *
     * A reason is required; without one nothing is written. The inventory service records the
     * override under the operator with the reason, exactly as typed, and refuses an order
     * without an open reservation; an order that already comes first everywhere changes nothing
     * and is told so. The reservations it moves ahead of wait for stock from then on wherever
     * the stock on hand no longer covers them. Nothing moves stock in the override itself; the
     * order's stock movements that are still owed (a status or a delivery note whose stock was
     * kept back for the orders ahead) are then tried at once, each as an ordinary fulfilment,
     * and the operator is told how many applied and how many are not applied yet.
     *
     * When the database ends the override because another stock change held the same rows at
     * that moment ({@see self::databaseBusy()}), the override has written nothing: the operator
     * is told so and asked to try again, and nothing else is tried.
     *
     *
     * @throws NotAllowed Without a POST, the form token or Super User rights.
     *
     * @since  2.0.0
     */
    public function prioritise(): void
    {
        $operator = $this->operator();
        $orderId = (int) $this->input->post->get(name: 'order_id', default: 0, filter: 'int');
        $reason = trim(string: (string) $this->input->post->get(name: 'reason', default: '', filter: 'raw'));

        $this->setRedirect(url: Route::_(url: self::AUDIT_PAGE, xhtml: false));

        if ($reason === '') {
            $this->setMessage(text: Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_PRIORITISE_REASON_REQUIRED'), type: 'error');

            return;
        }

        try {
            $result = (new InventoryService())->prioritiseReservations(
                sourceType: 'order',
                sourceId: $orderId,
                lineId: null,
                reason: $reason,
                context: new InventoryContext(userId: (int) $operator->id, note: $reason),
            );
        } catch (RuntimeException $e) {
            // The stock was held by another stock change of the same rows, such as the order's
            // own status change: the database ended the override whole, and a retry may succeed.
            if ($this->databaseBusy(e: $e)) {
                $this->setMessage(text: Text::sprintf('COM_ALFA_INVENTORY_AUDIT_PRIORITISE_BUSY', $orderId), type: 'error');

                return;
            }

            $this->setMessage(
                text: Text::sprintf('COM_ALFA_INVENTORY_AUDIT_PRIORITISE_REFUSED', $orderId, $this->refusal(e: $e)),
                type: 'error',
            );

            return;
        }

        if ($result['operation_id'] === null) {
            $this->setMessage(text: Text::sprintf('COM_ALFA_INVENTORY_AUDIT_PRIORITISE_NOTHING', $orderId));

            return;
        }

        $message = Text::sprintf(
            'COM_ALFA_INVENTORY_AUDIT_PRIORITISE_DONE',
            $orderId,
            (string) $result['operation_id'],
            (int) $result['records'],
            implode(separator: ', ', array: array_map(
                callback: static fn (array $source): string => $source['source_type'] === 'order'
                    ? Text::sprintf('COM_ALFA_INVENTORY_CLAIM_AHEAD_ORDER', (int) $source['source_id'])
                    : Text::sprintf('COM_ALFA_INVENTORY_CLAIM_AHEAD_SOURCE', htmlspecialchars(string: (string) $source['source_type'], flags: ENT_QUOTES, encoding: 'UTF-8'), (int) $source['source_id']),
                array: $result['ahead_of'],
            )),
        );

        // The order's stock movements still owed, tried now that its reservations come first.
        $owed = [];
        $applied = 0;

        try {
            $model = $this->getModel(name: 'Inventoryaudit', prefix: 'Administrator', config: ['ignore_request' => true]);

            if (!$model instanceof InventoryauditModel) {
                throw new UnexpectedValueException(message: 'the Inventoryaudit model is not available');
            }

            $owed = $model->owedMovements(orderId: $orderId);
        } catch (Throwable $e) {
            Log::add(
                entry: sprintf('Inventory audit: order #%d was served first, but its owed stock movements could not be read: %s', $orderId, $e->getMessage()),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }

        foreach ($owed as $obligation) {
            try {
                $drained = InventoryEffectOutbox::drain(
                    limit: 1,
                    onlySourceId: (int) $obligation['source_id'],
                    onlySourceType: (string) $obligation['source_type'],
                );
            } catch (Throwable $e) {
                // The drain settles what its effects throw; this is the queue itself failing.
                // The obligation stays owed and is counted as not applied.
                Log::add(
                    entry: sprintf(
                        'Inventory audit: the owed stock movement of %s #%d of order #%d, served first, could not be tried: %s',
                        (string) $obligation['source_type'],
                        (int) $obligation['source_id'],
                        $orderId,
                        $e->getMessage(),
                    ),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );

                continue;
            }

            $applied += (int) $drained['applied'];
        }

        if ($owed === []) {
            $this->setMessage(text: $message);

            return;
        }

        $this->setMessage(
            text: $message . ' ' . Text::sprintf('COM_ALFA_INVENTORY_AUDIT_PRIORITISE_OWED', \count(value: $owed), $applied, \count(value: $owed) - $applied),
            type: $applied < \count(value: $owed) ? 'warning' : 'message',
        );
    }

    /**
     * What a recorded resolution chose, in the operator's words: the historical parent identity,
     * or the stock unit the line was bound to as the resolution records it. Asked for the unit,
     * the historical parent identity is named as the product itself as well.
     *
     * The unit is read after the resolution committed. Should that read fail, the resolution
     * is still reported, by its action without the unit, and the failure is logged.
     *
     * @param string $action The recorded action.
     * @param int $resolutionId The recorded resolution.
     * @param bool $asUnit Name the stock unit whatever the action (a correction), or name
     *                     the historical parent identity by its action (a first resolution).
     *
     *
     * @since  2.0.0
     */
    private function resolvedLabel(string $action, int $resolutionId, bool $asUnit): string
    {
        $parent = $action === InventoryService::RESOLVE_HISTORICAL_PARENT;

        if ($parent && !$asUnit) {
            return Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT');
        }

        try {
            $model = $this->getModel(name: 'Inventoryaudit', prefix: 'Administrator', config: ['ignore_request' => true]);

            if (!$model instanceof InventoryauditModel) {
                throw new UnexpectedValueException('the Inventoryaudit model is not available');
            }

            $unit = $model->getResolvedUnit(resolutionId: $resolutionId);
        } catch (Throwable $e) {
            Log::add(
                entry: sprintf(
                    'Inventory audit: order-line resolution #%d is recorded, but its stock unit could not be read for the message: %s',
                    $resolutionId,
                    $e->getMessage(),
                ),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );

            return Text::_($parent ? 'COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT' : 'COM_ALFA_INVENTORY_LINE_RESOLVE_BIND');
        }

        return $unit['combination_id'] > 0
            ? Text::sprintf('COM_ALFA_INVENTORY_LINE_UNIT_COMBINATION', $unit['combination_id'], $unit['item_id'])
            : Text::sprintf('COM_ALFA_INVENTORY_LINE_UNIT_ITEM', $unit['item_id']);
    }

    /**
     * A stock identity as the operator knows it ({@see InventoryUnitException::labelFor()}),
     * ready for a message: the product and variant names in it are text administrators typed,
     * and a message is shown as HTML, so they are escaped and shown as typed.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     *
     *
     * @since  2.0.0
     */
    private function unitLabel(int $itemId, int $combinationId): string
    {
        return htmlspecialchars(
            string: InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId),
            flags: ENT_QUOTES,
            encoding: 'UTF-8',
        );
    }

    /**
     * Whether the database ended a command because another one held the rows it needed at that
     * moment: a deadlock (1213), which rolls the whole transaction back, or a lock wait that
     * timed out (1205). The command has then written nothing, and the same request may succeed
     * when it is tried again. Only a failure of a database statement counts, read by its MySQL
     * error number on either driver ({@see MysqlError::number()}: the PDO driver's code is the
     * SQLSTATE); a refusal of the inventory service never does.
     *
     * @param Throwable $e The failure.
     *
     *
     * @since  2.0.0
     */
    private function databaseBusy(Throwable $e): bool
    {
        return \in_array(needle: MysqlError::number(error: $e), haystack: [1205, 1213], strict: true);
    }

    /**
     * The operator's message for a refusal of the inventory service: a product that no longer
     * exists in the operator's words, any other refusal naming a stock identity in the words
     * the inventory service gives the operator, anything else as the service states it.
     *
     * The service's words name products and variants by the names administrators typed, and a
     * message is shown as HTML, so they are escaped and shown as typed.
     *
     * @param RuntimeException $e The refusal.
     *
     *
     * @since  2.0.0
     */
    private function refusal(RuntimeException $e): string
    {
        if ($e instanceof InventoryUnitException && $e->reason === InventoryUnitException::ITEM_GONE) {
            return Text::_('COM_ALFA_INVENTORY_AUDIT_REASON_ITEM_GONE');
        }

        return htmlspecialchars(
            string: $e instanceof InventoryUnitException ? $e->operatorMessage() : $e->getMessage(),
            flags: ENT_QUOTES,
            encoding: 'UTF-8',
        );
    }

    /**
     * The operator of an audited action: refused unless the request is a POST carrying the
     * form token and the user is a Super User.
     *
     *
     * @throws NotAllowed 405 for any other method, 403 for a missing token or permission.
     *
     * @since  2.0.0
     */
    private function operator(): User
    {
        if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 405);
        }

        if (!$this->checkToken(method: 'post', redirect: false)) {
            throw new NotAllowed(message: Text::_('JINVALID_TOKEN_NOTICE'), code: 403);
        }

        $user = $this->app->getIdentity();

        if (!$user instanceof User || !$user->authorise(action: 'core.admin', assetname: 'com_alfa')) {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 403);
        }

        return $user;
    }
}
