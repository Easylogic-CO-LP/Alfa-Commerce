<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\CommitmentRequest;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onInventoryBeforeCommit: reserved stock is about to be deducted.
 * Subscribers may replace the CommitmentRequest (updateRequest) or veto by
 * throwing; nothing was deducted yet, and a veto rolls back what the caller's
 * transaction did.
 *
 * WHEN IT RUNS. Before the deduction writes anything, and inside the transaction its
 * caller holds open: core deducts inside one, at an order's placement in the
 * placement's transaction and, for the stock owed after a status change or a
 * document, in that effect's transaction after the order's row is locked
 * (SELECT ... FOR UPDATE). A listener therefore runs under that lock. It reads
 * and writes the database only; its writes join the transaction and are undone
 * with it. It never calls outside (a web service, a mail, a file) and never
 * waits on anything: every other request that touches the order waits for it
 * meanwhile, and what it sent outside stays sent when the transaction rolls
 * back. Work outside belongs after the commit: the after-event, which is
 * delivered once the transaction commits, or PostCommit::defer().
 *
 * To refuse a movement on purpose, throw InventoryRefusedException with the
 * sentence staff are to read: a movement owed after a status change or a
 * document is then refused, not tried again, and staff decide. At an
 * order's placement under a status that takes the stock at once the refusal
 * stops the placement, and whoever places the order (the shopper, or staff in
 * the back office) reads the sentence. Any other exception is a failure, tried
 * again with the task engine's waits.
 *
 * @since  2.0.0
 */
class InventoryBeforeCommitEvent extends AbstractEvent
{
    /**
     * @return CommitmentRequest The fulfilment ask.
     *
     * @since  2.0.0
     */
    public function getRequest(): CommitmentRequest
    {
        return $this->arguments['request'];
    }

    /**
     * Replace the fulfilment ask (CommitmentRequest is immutable; build
     * a new one).
     *
     * @param CommitmentRequest $request The amended request.
     *
     *
     * @since  2.0.0
     */
    public function updateRequest(CommitmentRequest $request): void
    {
        $this->arguments['request'] = $request;
    }
}
