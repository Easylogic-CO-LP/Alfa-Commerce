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
 * onInventoryBeforeCommit: dispatched BEFORE the fulfilment
 * transaction opens. Subscribers may replace the CommitmentRequest
 * (updateRequest) or veto by throwing; nothing was deducted yet.
 *
 * To refuse a movement on purpose, throw InventoryRefusedException with the
 * sentence staff are to read: a movement owed after a status change or a
 * document is then refused, not tried again, and staff decide (2.2.1). At an
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
