<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The audit trail of Reset to default and Clear everything, written where the Alfa tables cannot
 * take it with them: Joomla's User Actions Log (the action log of com_actionlogs) and the
 * maintenance log file in Joomla's log folder (com_alfa_maintenance.php).
 *
 * A record that opens a destructive run is required to be durable: both writes are read back, and
 * the run does not start when either cannot be found ({@see self::record()} throws). The records
 * written afterwards (finished, failed) are written the same way, and a failure to write one is
 * reported with the run's result.
 *
 * @since  2.1.0
 */
final class MaintenanceAudit
{
    /**
     * The log category, registered by AlfaLog to com_alfa_maintenance.php.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const CATEGORY = 'com_alfa.maintenance';

    /**
     * The action log's extension context of these records.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const CONTEXT = 'com_alfa.maintenance';

    /**
     * Write one record in both logs and read both back.
     *
     * @param string $languageKey The action log message (COM_ALFA_ACTIONLOG_...), its placeholders
     *                            filled from $data.
     * @param array<string, scalar|null> $data What the message names; must hold `run`.
     * @param User $user Who did it.
     * @param string $line The same record for the log file, in words.
     *
     * @throws RuntimeException When either record cannot be found after it was written.
     *
     * @since  2.1.0
     */
    public static function record(string $languageKey, array $data, User $user, string $line): void
    {
        $run = (string) ($data['run'] ?? '');
        $message = $data + [
            'id' => (int) $user->id,
            'title' => (string) $user->name,
            'username' => (string) $user->username,
            'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . (int) $user->id,
        ];

        $failures = [];

        try {
            /** @var \Joomla\Component\Actionlogs\Administrator\Model\ActionlogModel $model */
            $model = Factory::getApplication()->bootComponent('com_actionlogs')->getMVCFactory()
                ->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
            $model->addLog([$message], $languageKey, self::CONTEXT, (int) $user->id);
        } catch (Throwable $e) {
            $failures[] = 'the action log refused it (' . $e->getMessage() . ')';
        }

        if ($failures === []) {
            try {
                if (!self::inActionLog(languageKey: $languageKey, run: $run, userId: (int) $user->id)) {
                    $failures[] = 'it is not in the action log';
                }
            } catch (Throwable $e) {
                $failures[] = 'the action log cannot be read back (' . $e->getMessage() . ')';
            }
        }

        $file = self::logFile();
        $before = is_file($file) ? (int) filesize($file) : 0;

        try {
            AlfaLog::register();
            Log::add(
                entry: sprintf('[%s] %s (user %d %s)', $run, $line, (int) $user->id, (string) $user->username),
                priority: Log::INFO,
                category: self::CATEGORY,
            );
        } catch (Throwable $e) {
            $failures[] = 'the log file refused it (' . $e->getMessage() . ')';
        }

        clearstatcache(true, $file);

        if (!is_file($file) || (int) filesize($file) <= $before || !str_contains((string) file_get_contents($file, false, null, $before), '[' . $run . ']')) {
            $failures[] = 'it is not in the log file ' . $file;
        }

        if ($failures !== []) {
            throw new RuntimeException('The audit record could not be written: ' . implode('; ', $failures) . '.');
        }
    }

    /**
     * Write a line to the maintenance log file only (the details a record refers to: every file
     * left, every extension whose install SQL was not run).
     *
     * @param string $run The run's id.
     * @param string $line What happened.
     *
     * @since  2.1.0
     */
    public static function detail(string $run, string $line): void
    {
        try {
            AlfaLog::register();
            Log::add(entry: sprintf('[%s] %s', $run, $line), priority: Log::INFO, category: self::CATEGORY);
        } catch (Throwable) {
            // The record that names the run says how many details there are.
        }
    }

    /**
     * The maintenance log file.
     *
     * @since  2.1.0
     */
    public static function logFile(): string
    {
        return rtrim((string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/\\')
            . '/' . (AlfaLog::categories()[self::CATEGORY] ?? 'com_alfa_maintenance.php');
    }

    /**
     * Whether the action log holds this run's record.
     *
     * @param string $languageKey The message key.
     * @param string $run The run's id.
     * @param int $userId The user.
     *
     * @since  2.1.0
     */
    private static function inActionLog(string $languageKey, string $run, int $userId): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (int) $db->setQuery(
            $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__action_logs'))
                ->where($db->quoteName('message_language_key') . ' = ' . $db->quote($languageKey))
                ->where($db->quoteName('extension') . ' = ' . $db->quote(self::CONTEXT))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('message') . ' LIKE ' . $db->quote('%"run":"' . $run . '"%')),
        )->loadResult() > 0;
    }
}
