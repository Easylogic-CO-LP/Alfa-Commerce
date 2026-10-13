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
