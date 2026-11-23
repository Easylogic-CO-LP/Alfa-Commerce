<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

/**
 * The state of a task or job, as find() answers it.
 *
 * @api READ ONLY: the states of WorkStatus::$state.
 *
 * @since  2.3.0
 */
enum WorkState: string
{
    /** Waiting to run, or waiting between two steps or two tries. @since 2.3.0 */
    case Waiting = 'pending';

    /** Running now. @since 2.3.0 */
    case Running = 'running';

    /** Done; its result is kept. @since 2.3.0 */
    case Done = 'done';

    /** A retry cannot help: staff decide. @since 2.3.0 */
    case Failed = 'failed';

    /** A task whose effect nobody knows: staff decide. @since 2.3.0 */
    case Held = 'held';

    /** Staff closed it. @since 2.3.0 */
    case Resolved = 'resolved';

    /** Cancelled before it finished. @since 2.3.0 */
    case Cancelled = 'cancelled';
}
