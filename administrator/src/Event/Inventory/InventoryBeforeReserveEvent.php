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
 * onInventoryBeforeReserve: dispatched BEFORE the reserve transaction
 * opens. Subscribers may amend the lines (updateLines) or veto by
 * throwing; a veto costs nothing, no row was touched yet.
 *
 * Quantities are never changed from AFTER events; this is the only
 * place a package may reshape a reservation ask.
 *
 * @since  1.4.0
 */
class InventoryBeforeReserveEvent extends AbstractEvent
{
    /**
     * @return array The reservation request lines.
     *
     * @since  1.4.0
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
     * @since  1.4.0
     */
    public function updateLines(array $lines): void
    {
        $this->arguments['lines'] = $lines;
    }

    /**
     * @return string The reservation source domain.
     *
     * @since  1.4.0
     */
    public function getSourceType(): string
    {
        return (string) ($this->arguments['sourceType'] ?? '');
    }

    /**
     * @return int The source row id.
     *
     * @since  1.4.0
     */
    public function getSourceId(): int
    {
        return (int) ($this->arguments['sourceId'] ?? 0);
    }

    /**
     * @return InventoryContext|null The command's audit context.
     *
     * @since  1.4.0
     */
    public function getContext(): ?InventoryContext
    {
        $context = $this->arguments['context'] ?? null;

        return $context instanceof InventoryContext ? $context : null;
    }
}
