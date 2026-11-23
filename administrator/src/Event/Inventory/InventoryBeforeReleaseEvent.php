<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * onInventoryBeforeRelease: reserved stock is about to be released.
 * Read-only payload; a subscriber vetoes by throwing: nothing was released
 * yet, and the veto rolls back what the caller's transaction did.
 *
 * WHEN IT RUNS. Before the release writes anything, and inside the transaction its
 * caller holds open: core releases inside one, for the stock an order gives
 * back after a status change or a document in that effect's transaction after
 * the order's row is locked (SELECT ... FOR UPDATE), and when an order is
 * deleted in the deletion's transaction. A listener therefore runs under that
 * lock. It reads
 * and writes the database only; its writes join the transaction and are undone
 * with it. It never calls outside (a web service, a mail, a file) and never
 * waits on anything: every other request that touches the order waits for it
 * meanwhile, and what it sent outside stays sent when the transaction rolls
 * back. Work outside belongs after the commit: the after-event, which is
 * delivered once the transaction commits, or PostCommit::defer().
 *
 * To refuse a movement on purpose, throw InventoryRefusedException with the
 * sentence staff are to read: a movement owed after a status change or a
 * document is then refused, not tried again, and staff decide. Any
 * other exception is a failure, tried again with the task engine's waits.
 *
 * @since  2.0.0
 */
class InventoryBeforeReleaseEvent extends AbstractEvent
{
    /**
     * @return string The reservation source domain.
     *
     * @since  2.0.0
     */
    public function getSourceType(): string
    {
        return (string) ($this->arguments['sourceType'] ?? '');
    }

    /**
     * @return int The source row id.
     *
     * @since  2.0.0
     */
    public function getSourceId(): int
    {
        return (int) ($this->arguments['sourceId'] ?? 0);
    }

    /**
     * @return string The target release state (released|cancelled|expired).
     *
     * @since  2.0.0
     */
    public function getToState(): string
    {
        return (string) ($this->arguments['toState'] ?? '');
    }
}
