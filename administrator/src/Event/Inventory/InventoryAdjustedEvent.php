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
 * InventoryAdjusted after-event: post-commit, side-effects only. Payload in
 * named arguments (see the dispatching command in InventoryService),
 * result summary via getResult().
 *
 * @since  1.4.0
 */
class InventoryAdjustedEvent extends InventoryAfterEvent
{
}
