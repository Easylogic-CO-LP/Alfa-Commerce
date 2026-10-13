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
