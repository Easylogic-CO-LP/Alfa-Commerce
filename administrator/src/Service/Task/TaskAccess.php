<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\User\User;

/**
 * Who may read a task through the API, and who may process a job.
 *
 * Only a signed-in user reads a task. Its creator reads their own; anyone else needs the permission of the work: Tools
 * (alfa.tools) for a job or a single about anything but an order, and the orders view permission for an order's
 * single. A caller who may not read a task is told it does not exist, so its existence is never revealed. Processing
 * a job needs Tools, whoever created it.
 *
 * @since  2.2.0
 */
final class TaskAccess
{
    /**
     * Whether a user may read a task.
     *
     * @param array<string, mixed> $row The task's row: type, subject_type and created_by at least.
     * @param User $user Who asks.
     *
     * @since  2.2.0
     */
    public static function canRead(array $row, User $user): bool
    {
        $userId = (int) $user->id;

        if ($user->guest || $userId <= 0) {
            return false;
        }

        if ((int) $row['created_by'] === $userId) {
            return true;
        }

        if ($row['type'] === Tasks::TYPE_SINGLE && $row['subject_type'] === 'order') {
            return AlfaHelper::can(section: 'orders', action: 'view', user: $user);
        }

        return self::canUseTools(user: $user);
    }

    /**
     * Whether a user may list every task and process a job: the Tools permission.
     *
     * @param User $user Who asks.
     *
     * @since  2.2.0
     */
    public static function canUseTools(User $user): bool
    {
        return !$user->guest && (int) $user->id > 0 && (bool) $user->authorise('alfa.tools', 'com_alfa');
    }
}
