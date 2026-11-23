<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * The plugin that owns a task or job is disabled or no longer installed: its work goes back to waiting with no attempt
 * counted, and waits until the plugin is enabled again (the engine marks its declarations disabled as it puts the row
 * back).
 *
 * @since  2.3.0
 */
final class OwnerUnavailable extends RuntimeException
{
}
