<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

/**
 * InventoryTransferred after-event: post-commit, side-effects only, result summary via getResult(). No command of
 * InventoryService moves stock between locations, so core dispatches it from nowhere; the name is kept for the event
 * list.
 *
 * @since  2.0.0
 */
class InventoryTransferredEvent extends InventoryAfterEvent
{
}
