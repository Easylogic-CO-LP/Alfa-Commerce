<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The attention notice of the tasks: standing while a single is failed or held, retired when none is.
 *
 * It is danger while a task whose handler says so (an e-mail, a plugin's reaction) is failed or held, else a warning,
 * and it links to the Tools tab where staff resend, run again or mark each one resolved ({@see TaskStaff}). A handler
 * whose failures are only logged is not counted.
 *
 * @since  2.1.4
 */
final class TaskAttention
{
    /**
     * The notice's dedup key.
     *
     * @since  2.1.4
     */
    public const KEY = 'alfa.tasks.attention';

    /**
     * Where the notice links: Tools, the Tasks tab.
     *
     * @since  2.1.4
     */
    public const URL = 'index.php?option=com_alfa&view=tools#tasks';

    /**
     * The failed and held singles staff are to see, counted, and the notice's severity.
     *
     * @return array{failed: int, held: int, severity: string}
     *
     * @since  2.1.4
     */
    public static function counts(): array
    {
        /** @var DatabaseDriver $db */
        $db = Factory::getContainer()->get('DatabaseDriver');
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['handler', 'state']))
                ->select('COUNT(*) AS ' . $db->quoteName('n'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_FAILED, Tasks::STATE_HELD], ParameterType::STRING)
                ->group($db->quoteName(['handler', 'state'])),
        )->loadAssocList() ?: [];
        $counts = ['failed' => 0, 'held' => 0, 'severity' => 'warning'];

        foreach ($rows as $row) {
            // A task whose handler this version no longer has is shown, as a warning.
            $attention = Tasks::handler(name: (string) $row['handler'])?->policy()->attention ?? 'warning';

            if ($attention === '') {
                continue;
            }

            $counts[$row['state'] === Tasks::STATE_HELD ? 'held' : 'failed'] += (int) $row['n'];

            if ($attention === 'danger') {
                $counts['severity'] = 'danger';
            }
        }

        return $counts;
    }

    /**
     * Raise, update or retire the notice to match the tasks. A failure is logged: the tasks themselves are recorded
     * either way.
     *
     * @since  2.1.4
     */
    public static function sync(): void
    {
        try {
            $counts = self::counts();
            $total = $counts['failed'] + $counts['held'];

            if ($total === 0) {
                if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                    NotificationHelper::clear(dedupKey: self::KEY);
                }

                return;
            }

            // Re-opened only when it is not already standing: a standing notice just gets its counts.
            NotificationHelper::push(
                self::KEY,
                NotificationText::of('COM_ALFA_NOTIFY_TASKS_ATTENTION_TITLE'),
                [
                    'group' => NotificationHelper::GROUP_ORDERS,
                    'severity' => $counts['severity'],
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_TASKS_ATTENTION_MSG', $total, $counts['failed'], $counts['held']),
                    'dismissible' => false,
                    'constant' => !NotificationHelper::isOpen(dedupKey: self::KEY),
                    'url' => self::URL,
                    'view' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tasks: the attention notice could not be brought up to date: ' . $e->getMessage(), priority: Log::WARNING);
        }
    }
}
