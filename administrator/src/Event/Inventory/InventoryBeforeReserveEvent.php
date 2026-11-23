<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onInventoryBeforeReserve: a reservation is about to be made. Subscribers
 * may amend the lines (updateLines) or veto by throwing; the reservation has
 * touched no row yet, and a veto rolls back what the caller's transaction did
 * (at placement, the whole placement).
 *
 * WHEN IT RUNS. Before the reservation writes anything, and inside the transaction its
 * caller holds open: core reserves inside one, at an order's placement in the
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
 * Quantities are never changed from AFTER events; this is the only
 * place a package may reshape a reservation ask.
 *
 * To refuse a movement on purpose, throw InventoryRefusedException with the
 * sentence staff are to read: a movement owed after a status change or a
 * document is then refused, not tried again, and staff decide. At an
 * order's placement the refusal stops the placement, and whoever places the
 * order (the shopper, or staff in the back office) reads the sentence. Any
 * other exception is a failure, tried again with the task engine's waits.
 *
 * @since  2.0.0
 */
class InventoryBeforeReserveEvent extends AbstractEvent
{
    /**
     * @return array The reservation request lines.
     *
     * @since  2.0.0
     */
    public function getLines(): array
    {
        return (array) ($this->arguments['lines'] ?? []);
    }

    /**
     * Replace the request lines (same shape as InventoryService::reserve()).
     *
     * @param array $lines The amended lines.
     *
     *
     * @since  2.0.0
     */
    public function updateLines(array $lines): void
    {
        $this->arguments['lines'] = $lines;
    }

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
     * @return InventoryContext|null The command's audit context.
     *
     * @since  2.0.0
     */
    public function getContext(): ?InventoryContext
    {
        $context = $this->arguments['context'] ?? null;

        return $context instanceof InventoryContext ? $context : null;
    }
}
