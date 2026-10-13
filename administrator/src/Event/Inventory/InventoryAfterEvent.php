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
 * Base of the inventory AFTER events: dispatched post-commit,
 * side-effects only (notify, enqueue, sync). The command already
 * happened: nothing here can veto, and no subscriber may write a stock
 * column (the service is the only writer; a package reacting to an
 * after event issues NEW commands if it needs quantity changes).
 *
 * Every event carries the command's operation summary via getResult()
 * (operation_id included) plus its own named arguments.
 *
 * @since  2.0.0
 */
abstract class InventoryAfterEvent extends AbstractEvent
{
    /**
     * @return array The command's result summary (operation_id included).
     *
     * @since  2.0.0
     */
    public function getResult(): array
    {
        return (array) ($this->arguments['result'] ?? []);
    }

    /**
     * Convenience argument reader for the event's named payload.
     *
     * @param string $key The argument name.
     * @param mixed $default Fallback when absent.
     *
     *
     * @since  2.0.0
     */
    public function argument(string $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }
}
