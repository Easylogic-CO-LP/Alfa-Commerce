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
 * onInventoryBeforeRelease: dispatched BEFORE the release transaction
 * opens. Read-only payload; a subscriber vetoes by throwing (nothing
 * was released yet).
 *
 * To refuse a movement on purpose, throw InventoryRefusedException with the
 * sentence staff are to read: a movement owed after a status change or a
 * document is then refused, not tried again, and staff decide (2.2.1). Any
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
