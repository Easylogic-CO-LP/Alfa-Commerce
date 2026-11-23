<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\JobDeclaration;
use Alfa\Component\Alfa\Administrator\Background\TaskDeclaration;
use Alfa\Component\Alfa\Administrator\Event\Background\DeclareWorkEvent;
use Alfa\Component\Alfa\Administrator\Event\Background\RunJobEvent;
use Alfa\Component\Alfa\Administrator\Event\Background\RunTaskEvent;
use Alfa\Component\Alfa\Administrator\Event\Background\WorkSettledEvent;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The work each owner declares, as core last read it (#__alfa_work_declarations): core's own rows from its handler
 * classes, written by the installer and every update; each alfa plugin's from its onAlfaDeclareWork answer, read when the
 * plugin is installed, updated, saved or enabled (Alfa Sync, through {@see PluginFollower}), and once in a request when
 * an enqueue names a work the copy does not hold. The copy is not a second source: it is the plugin's own answer, kept
 * so that the runner's due read, its schedules and Tools > Tasks never boot a plugin.
 *
 * The enabled flag follows the plugin: set from Joomla's extension events, set to 0 at once when a boot for work is
 * refused because the plugin is disabled or gone, and compared with #__extensions once an hour both ways, so a plugin
 * switched behind Joomla's back (a SQL change, a restored backup) is followed within the hour. While it is 0 the
 * plugin's work waits, neither due nor claimed ({@see DueRead::runnableOwner()}).
 *
 * @since  2.3.0
 */
final class WorkDeclarations
{
    /**
     * The table.
     *
     * @since  2.3.0
     */
    public const TABLE = DueRead::DECLARATIONS;

    /**
     * A declared name: lower-case words of letters, digits and _, joined by dots.
     *
     * @since  2.3.0
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * The longest name.
     *
     * @since  2.3.0
     */
    public const NAME_MAX = 64;

    /**
     * The shortest and longest span of every(), in minutes: 5 minutes and 30 days.
     *
     * @since  2.3.0
     */
    public const EVERY_MIN = 5;

    /** @since 2.3.0 */
    public const EVERY_MAX = 43200;

    /**
     * The #__alfa_meta row holding when the enabled flags were last compared with the extensions.
     *
     * @since  2.3.0
     */
    private const META_CHECKED = 'work.declarations.checked';

    /**
     * The rows read in this request, by owner and name.
     *
     * @var array<string, array<string, array<string, mixed>>>
     *
     * @since  2.3.0
     */
    private static array $rows = [];

    /**
     * The owners whose declarations were read from the plugin in this request after an enqueue named a work the copy
     * did not hold: read once a request.
     *
     * @var array<string, true>
     *
     * @since  2.3.0
     */
    private static array $reread = [];

    /**
     * One declaration, or null when its owner declares no work of that name.
     *
     * @param string $owner com_alfa or group.element.
     * @param string $name The work.
     *
     * @return array<string, mixed>|null The row.
     *
     * @since  2.3.0
     */
    public static function find(string $owner, string $name): ?array
    {
        if (!isset(self::$rows[$owner])) {
            $db = self::db();
            self::$rows[$owner] = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('owner') . ' = :owner')
                    ->bind(':owner', $owner),
            )->loadAssocList('name') ?: [];
        }

        return self::$rows[$owner][$name] ?? null;
    }

    /**
     * One declaration of a plugin, read again from the plugin once in this request when the copy does not hold it (a
     * developer who just added a name, a plugin enabled behind Joomla's back): what an enqueue asks.
     *
     * @param PluginId $plugin The plugin.
     * @param string $name The work.
     *
     * @return array<string, mixed>|null The row; null when the plugin does not declare it.
     *
     * @since  2.3.0
     */
    public static function findOrRead(PluginId $plugin, string $name): ?array
    {
        $owner = (string) $plugin;
        $row = self::find(owner: $owner, name: $name);

        if (($row === null || (int) $row['enabled'] !== 1) && !isset(self::$reread[$owner])) {
            self::$reread[$owner] = true;
            self::read(plugin: $plugin);
            $row = self::find(owner: $owner, name: $name);
        }

        return $row;
    }

    /**
     * Whether a plugin is told how its work ended: its declarations say it has onAlfaWorkSettled.
     *
     * @param string $owner group.element.
     *
     * @since  2.3.0
     */
    public static function tellsSettled(string $owner): bool
    {
        self::find(owner: $owner, name: '');

        foreach (self::$rows[$owner] ?? [] as $row) {
            if ((int) $row['tells_settled'] === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the declarations of the alfa plugins among some extensions in step with them: an enabled plugin is asked
     * again, a disabled one keeps its declarations marked disabled. Never breaks the caller: a failure is logged.
     *
     * @param int[] $extensionIds Extension ids installed, updated, saved, enabled or disabled.
     *
     * @since  2.3.0
     */
    public static function followPlugins(array $extensionIds): void
    {
        $extensionIds = array_values(array_filter(array_map('intval', $extensionIds), static fn (int $id): bool => $id > 0));

        if ($extensionIds === []) {
            return;
        }

        try {
            $db = self::db();
            $plugins = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['folder', 'element', 'enabled']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%'))
                    ->whereIn($db->quoteName('extension_id'), $extensionIds),
            )->loadObjectList() ?: [];

            foreach ($plugins as $plugin) {
                $id = PluginId::from(group: (string) $plugin->folder, element: (string) $plugin->element);

                if ((int) $plugin->enabled === 1) {
                    self::read(plugin: $id);
                } else {
                    self::setEnabled(owner: (string) $id, enabled: false);
                }
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background work: the declarations of the saved plugins could not be kept in step: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
        }
    }

    /**
     * Mark the declarations of the alfa plugins among some extensions enabled or disabled, before Joomla stores the
     * switch. Never breaks the caller.
     *
     * @param int[] $extensionIds The extensions.
     * @param bool $enabled Whether they are being enabled.
     *
     * @since  2.3.0
     */
    public static function markExtensions(array $extensionIds, bool $enabled): void
    {
        $extensionIds = array_values(array_filter(array_map('intval', $extensionIds), static fn (int $id): bool => $id > 0));

        if ($extensionIds === []) {
            return;
        }

        try {
            $db = self::db();
            $plugins = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['folder', 'element']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%'))
                    ->whereIn($db->quoteName('extension_id'), $extensionIds),
            )->loadObjectList() ?: [];

            foreach ($plugins as $plugin) {
                self::setEnabled(owner: (string) $plugin->folder . '.' . (string) $plugin->element, enabled: $enabled);
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background work: the declarations of the switched plugins could not be marked: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
        }
    }

    /**
     * Read a plugin's declarations from the plugin (booted for its work: enabled, whatever its Access) and keep them,
     * the names it no longer declares removed. A plugin without onAlfaDeclareWork declares nothing. A declaration that
     * breaks a rule is left out with one ERROR line naming the plugin, the name and the reason, and the plugin's other
     * declarations count. A plugin that cannot be booted, or whose method throws, keeps its previous copy unchanged.
     *
     * @param PluginId $plugin The plugin.
     *
     * @return bool Whether the declarations were read and kept.
     *
     * @since  2.3.0
     */
    public static function read(PluginId $plugin): bool
    {
        $owner = (string) $plugin;

        if (!str_starts_with($plugin->group, 'alfa')) {
            return false;
        }

        $helper = AlfaPluginHelper::get();

        try {
            $loaded = $helper->bootForWork($plugin);
        } catch (PluginUnavailable $e) {
            if (\in_array($e->reason, [PluginUnavailable::DISABLED, PluginUnavailable::MISSING], true)) {
                self::setEnabled(owner: $owner, enabled: false);
            }

            Tasks::log(entry: 'Background work: the declarations of ' . $owner . ' could not be read; its previous copy stands: ' . $e->getMessage(), priority: Log::WARNING);

            return false;
        }

        $declared = [];

        if ($helper->declares($loaded, DeclareWorkEvent::NAME)) {
            $event = new DeclareWorkEvent();

            try {
                $helper->dispatchTo($loaded, $event);
            } catch (Throwable $e) {
                Tasks::log(entry: 'Background work: ' . $owner . ' failed when asked its declarations (' . $e::class . ': ' . $e->getMessage() . '); its previous copy stands.', priority: Log::ERROR);

                return false;
            }

            $declared = $event->declared();
        }

        $rows = self::validate(
            owner: $owner,
            declared: $declared,
            runsTasks: $helper->declares($loaded, RunTaskEvent::NAME),
            runsJobs: $helper->declares($loaded, RunJobEvent::NAME),
            tellsSettled: $helper->declares($loaded, WorkSettledEvent::NAME),
        );

        try {
            self::replace(owner: $owner, rows: $rows);
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background work: the declarations of ' . $owner . ' could not be kept: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);

            return false;
        }

        return true;
    }

    /**
     * Mark a plugin's declarations enabled or disabled: while disabled, its work waits.
     *
     * @param string $owner group.element.
     * @param bool $enabled Whether it is enabled.
     *
     * @since  2.3.0
     */
    public static function setEnabled(string $owner, bool $enabled): void
    {
        if ($owner === DueRead::CORE_OWNER) {
            return;
        }

        try {
            $db = self::db();
            $value = $enabled ? 1 : 0;
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(self::TABLE))
                    ->set($db->quoteName('enabled') . ' = :enabled')
                    ->where($db->quoteName('owner') . ' = :owner')
                    ->bind(':enabled', $value, ParameterType::INTEGER)
                    ->bind(':owner', $owner),
            )->execute();
            unset(self::$rows[$owner]);
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background work: the declarations of ' . $owner . ' could not be marked ' . ($enabled ? 'enabled' : 'disabled') . ': ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * The plugins that are no longer installed (Joomla's uninstall, or Remove extension only): their declarations
     * removed; their waiting tasks and jobs and their running jobs cancelled with the reason; their failed and held
     * rows kept for staff, as evidence. A running task is one go and is not cancelled: it ends, or recovery holds it.
     * Never breaks the caller.
     *
     * @since  2.3.0
     */
    public static function forgetMissing(): void
    {
        try {
            $db = self::db();
            $gone = $db->setQuery(
                'SELECT DISTINCT ' . $db->quoteName('d.owner') . ' FROM ' . $db->quoteName(self::TABLE, 'd')
                . ' WHERE ' . $db->quoteName('d.owner') . ' <> ' . $db->quote(DueRead::CORE_OWNER)
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__extensions', 'e') . ' WHERE ' . $db->quoteName('e.type') . ' = ' . $db->quote('plugin')
                . ' AND CONCAT(' . $db->quoteName('e.folder') . ', ' . $db->quote('.') . ', ' . $db->quoteName('e.element') . ') = ' . $db->quoteName('d.owner') . ')',
            )->loadColumn() ?: [];

            foreach ($gone as $owner) {
                self::cancelOwner(db: $db, owner: (string) $owner, reason: 'The extension was uninstalled.');
                $db->setQuery(
                    $db->getQuery(true)->delete($db->quoteName(self::TABLE))->where($db->quoteName('owner') . ' = :owner')->bind(':owner', $owner),
                )->execute();
                unset(self::$rows[(string) $owner]);
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background work: the work of removed plugins could not be closed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
        }
    }

    /**
     * Remove extension and its data: every task and job of the plugin deleted, and its declarations, after its own
     * Clear Data. Its work is its data.
     *
     * @param PluginId $plugin The plugin.
     *
     * @return int The tasks and jobs deleted.
     *
     * @since  2.3.0
     */
    public static function removeWithData(PluginId $plugin): int
    {
        $owner = (string) $plugin;
        $db = self::db();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName(DueRead::TABLE))->where($db->quoteName('owner') . ' = :owner')->bind(':owner', $owner))->execute();
        $deleted = $db->getAffectedRows();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName(self::TABLE))->where($db->quoteName('owner') . ' = :owner')->bind(':owner', $owner))->execute();
        unset(self::$rows[$owner]);

        return $deleted;
    }

    /**
     * Core's own rows, written from its handler classes: every single and job handler of core with its policy's label,
     * weight and span. Names no longer of core are removed. The installer and every update call it, and Clear
     * everything after it makes the tables again; nothing else writes core's rows.
     *
     * @since  2.3.0
     */
    public static function writeCore(): void
    {
        $rows = [];

        foreach (Tasks::coreHandlerNames() as $name) {
            $policy = Tasks::handler(name: $name)?->policy();

            if ($policy !== null) {
                $rows[] = ['name' => $name, 'kind' => Tasks::TYPE_SINGLE, 'label' => $policy->label, 'covers' => '', 'heavy' => 0, 'calls_out' => 0,
                    'repeatable' => $policy->idempotent ? 1 : 0, 'urgent' => $policy->attention === 'danger' ? 1 : 0, 'every_minutes' => 0, 'tells_settled' => 0];
            }
        }

        foreach (Runner::coreHandlerNames() as $name) {
            $policy = Runner::handler(name: $name)?->policy();

            if ($policy !== null) {
                $rows[] = ['name' => $name, 'kind' => Tasks::TYPE_JOB, 'label' => $policy->label, 'covers' => '', 'heavy' => $policy->weight === JobPolicy::HEAVY ? 1 : 0,
                    'calls_out' => $policy->callsOut ? 1 : 0, 'repeatable' => 0, 'urgent' => 0, 'every_minutes' => $policy->everyMinutes, 'tells_settled' => 0];
            }
        }

        self::replace(owner: DueRead::CORE_OWNER, rows: $rows);
    }

    /**
     * After Clear everything has made the tables again: core's rows, and the declarations of every enabled alfa plugin
     * read again, so the schedules resume.
     *
     * @since  2.3.0
     */
    public static function readAllAgain(): void
    {
        self::writeCore();
        $db = self::db();
        $plugins = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['folder', 'element']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%'))
                ->where($db->quoteName('enabled') . ' = 1'),
        )->loadObjectList() ?: [];

        foreach ($plugins as $plugin) {
            self::read(plugin: PluginId::from(group: (string) $plugin->folder, element: (string) $plugin->element));
        }
    }

    /**
     * Once an hour, from the tick's owe step: every plugin's enabled flag compared with #__extensions and corrected
     * both ways (a plugin no longer installed reads as disabled). One statement; a handful of rows.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.3.0
     */
    public static function checkEnabled(DatabaseDriver $db): void
    {
        $name = self::META_CHECKED;
        $due = !(bool) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = :name')
                ->where($db->quoteName('value') . " > DATE_FORMAT(UTC_TIMESTAMP() - INTERVAL 1 HOUR, '%Y-%m-%d %H:%i:%s')")
                ->bind(':name', $name),
        )->loadResult();

        if (!$due) {
            return;
        }

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ') VALUES ('
            . $db->quote($name) . ", DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')) ON DUPLICATE KEY UPDATE " . $db->quoteName('value')
            . " = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')",
        )->execute();
        $db->setQuery(
            'UPDATE ' . $db->quoteName(self::TABLE, 'd') . ' LEFT JOIN ' . $db->quoteName('#__extensions', 'e') . ' ON ' . $db->quoteName('e.type') . ' = ' . $db->quote('plugin')
            . ' AND CONCAT(' . $db->quoteName('e.folder') . ', ' . $db->quote('.') . ', ' . $db->quoteName('e.element') . ') = ' . $db->quoteName('d.owner')
            . ' SET ' . $db->quoteName('d.enabled') . ' = COALESCE(' . $db->quoteName('e.enabled') . ', 0)'
            . ' WHERE ' . $db->quoteName('d.owner') . ' <> ' . $db->quote(DueRead::CORE_OWNER)
            . ' AND ' . $db->quoteName('d.enabled') . ' <> COALESCE(' . $db->quoteName('e.enabled') . ', 0)',
        )->execute();

        if ($db->getAffectedRows() > 0) {
            self::$rows = [];
            Tasks::log(entry: 'Background work: ' . $db->getAffectedRows() . ' declarations followed a plugin switched outside Joomla\'s extension events.', priority: Log::INFO);
        }
    }

    /**
     * From the tick's owe step: every schedule owed, of every owner (core's prune and tidy among them), asked as its
     * job with no scope and no data ({@see DueRead::scheduleOwedWhere()}), one statement for what is owed.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.3.0
     */
    public static function oweSchedules(DatabaseDriver $db): void
    {
        $owed = $db->setQuery(
            'SELECT ' . $db->quoteName('wd.owner') . ', ' . $db->quoteName('wd.name') . ' FROM ' . $db->quoteName(self::TABLE, 'wd')
            . ' WHERE ' . DueRead::scheduleOwedWhere(db: $db),
        )->loadAssocList() ?: [];

        foreach ($owed as $row) {
            $owner = (string) $row['owner'];
            $name = (string) $row['name'];

            try {
                Runner::enqueue(handler: $name, scopeKey: $name, payload: $owner === DueRead::CORE_OWNER ? [] : ['data' => []], owner: $owner);
            } catch (Throwable $e) {
                Tasks::log(entry: 'Background work: the schedule ' . $name . ' of ' . $owner . ' could not be queued: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
            }
        }
    }

    /**
     * Forget what this request read: the next read asks the table.
     *
     * @since  2.3.0
     */
    public static function forget(): void
    {
        self::$rows = [];
        self::$reread = [];
    }

    /**
     * The rows of a plugin's declarations that keep the rules, each refusal logged.
     *
     * @param string $owner group.element.
     * @param list<array{0: string, 1: TaskDeclaration|JobDeclaration}> $declared What the plugin declared.
     * @param bool $runsTasks Whether it has onAlfaRunTask.
     * @param bool $runsJobs Whether it has onAlfaRunJob.
     * @param bool $tellsSettled Whether it has onAlfaWorkSettled.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    private static function validate(string $owner, array $declared, bool $runsTasks, bool $runsJobs, bool $tellsSettled): array
    {
        $rows = [];
        $refuse = static function (string $name, string $why) use ($owner): void {
            Tasks::log(entry: 'Background work: ' . $owner . ' declares ' . ($name === '' ? '(no name)' : $name) . ', which is left out: ' . $why . ' Its other declarations count.', priority: Log::ERROR);
        };

        foreach ($declared as [$name, $declaration]) {
            $isJob = $declaration instanceof JobDeclaration;
            $said = $declaration->describe();

            if (preg_match(self::NAME_PATTERN, $name) !== 1 || \strlen($name) > self::NAME_MAX) {
                $refuse($name, 'a name is lower-case words of letters, digits and _ joined by dots, at most ' . self::NAME_MAX . ' characters.');

                continue;
            }

            if (isset($rows[$name])) {
                $refuse($name, 'the name is declared twice; a name has one kind.');
                unset($rows[$name]);

                continue;
            }

            if (trim($said['label']) === '') {
                $refuse($name, 'it has no label().');

                continue;
            }

            if ($isJob ? !$runsJobs : !$runsTasks) {
                $refuse($name, 'the plugin has no public ' . ($isJob ? RunJobEvent::NAME : RunTaskEvent::NAME) . '() method to run it.');

                continue;
            }

            $every = 0;

            if ($isJob) {
                $given = array_filter($said['every'], static fn (int $value): bool => $value !== 0);

                if (\count($given) > 1 || min([0, ...array_values($said['every'])]) < 0) {
                    $refuse($name, 'every() takes exactly one of minutes, hours or days, above zero.');

                    continue;
                }

                $every = $said['every']['minutes'] + 60 * $said['every']['hours'] + 1440 * $said['every']['days'];

                if ($every !== 0 && ($every < self::EVERY_MIN || $every > self::EVERY_MAX)) {
                    $refuse($name, 'every() is at least 5 minutes and at most 30 days.');

                    continue;
                }
            }

            $rows[$name] = [
                'name' => $name,
                'kind' => $isJob ? Tasks::TYPE_JOB : Tasks::TYPE_SINGLE,
                'label' => $said['label'],
                'covers' => $isJob ? $said['covers'] : '',
                'heavy' => $isJob && $said['heavy'] ? 1 : 0,
                'calls_out' => $isJob && $said['callsOut'] ? 1 : 0,
                'repeatable' => !$isJob && $said['repeatable'] ? 1 : 0,
                'urgent' => $said['urgent'] ? 1 : 0,
                'every_minutes' => $every,
                'tells_settled' => $tellsSettled ? 1 : 0,
            ];
        }

        return array_values($rows);
    }

    /**
     * Replace an owner's rows in one transaction: the rows given, enabled; any other name removed.
     *
     * @param string $owner The owner.
     * @param list<array<string, mixed>> $rows Its rows.
     *
     * @since  2.3.0
     */
    private static function replace(string $owner, array $rows): void
    {
        $db = self::db();
        $db->transactionStart(true);

        try {
            $db->setQuery($db->getQuery(true)->delete($db->quoteName(self::TABLE))->where($db->quoteName('owner') . ' = :owner')->bind(':owner', $owner))->execute();

            foreach ($rows as $row) {
                $query = $db->getQuery(true)
                    ->insert($db->quoteName(self::TABLE))
                    ->columns($db->quoteName(['owner', 'name', 'kind', 'label', 'covers', 'heavy', 'calls_out', 'repeatable', 'urgent', 'every_minutes', 'tells_settled', 'enabled', 'declared_at']))
                    ->values(':owner, :name, :kind, :label, :covers, :heavy, :callsOut, :repeatable, :urgent, :every, :tells, 1, UTC_TIMESTAMP(6)')
                    ->bind(':owner', $owner)
                    ->bind(':name', $row['name'])
                    ->bind(':kind', $row['kind'])
                    ->bind(':label', $row['label'])
                    ->bind(':covers', $row['covers'])
                    ->bind(':heavy', $row['heavy'], ParameterType::INTEGER)
                    ->bind(':callsOut', $row['calls_out'], ParameterType::INTEGER)
                    ->bind(':repeatable', $row['repeatable'], ParameterType::INTEGER)
                    ->bind(':urgent', $row['urgent'], ParameterType::INTEGER)
                    ->bind(':every', $row['every_minutes'], ParameterType::INTEGER)
                    ->bind(':tells', $row['tells_settled'], ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        unset(self::$rows[$owner]);
    }

    /**
     * Cancel an owner's waiting tasks and jobs and its running jobs, each with a history entry naming the reason; its
     * failed and held rows stay. The owner is gone, so nothing is told.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $owner The owner.
     * @param string $reason Why, for staff.
     *
     * @since  2.3.0
     */
    private static function cancelOwner(DatabaseDriver $db, string $owner, string $reason): void
    {
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'history']))
                ->from($db->quoteName(DueRead::TABLE))
                ->where($db->quoteName('owner') . ' = :owner')
                ->where('(' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' OR (' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING)
                    . ' AND ' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB) . '))')
                ->bind(':owner', $owner),
        )->loadAssocList() ?: [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $history = Tasks::encodeHistory([...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: Tasks::userId(), outcome: Tasks::STATE_CANCELLED, more: ['reason' => $reason])]);
            $state = Tasks::STATE_CANCELLED;
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(DueRead::TABLE))
                    ->set($db->quoteName('state') . ' = :state')
                    ->set($db->quoteName('history') . ' = :history')
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->whereIn($db->quoteName('state'), [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], ParameterType::STRING)
                    ->bind(':state', $state)
                    ->bind(':history', $history)
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
        }

        if ($rows !== []) {
            Tasks::log(entry: 'Background work: ' . \count($rows) . ' waiting or running tasks and jobs of ' . $owner . ' were cancelled: ' . $reason, priority: Log::INFO);
        }
    }

    /**
     * The site's connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
    }
}
