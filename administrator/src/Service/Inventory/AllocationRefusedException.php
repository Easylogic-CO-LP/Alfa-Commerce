<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * A deliberate allocation REFUSAL, thrown by an allocation provider
 * that has authority over the unit and rules that no allocation is
 * permitted. Unlike passing (null) or failing (skipped with a warning),
 * a refusal PROPAGATES: the reservation fails loudly and the default
 * location is NEVER a silent substitute for a business rule.
 *
 * @since  2.0.0
 */
class AllocationRefusedException extends RuntimeException
{
}
