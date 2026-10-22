<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Tools\ClearDataEvent;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataStatus;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Setup\ComponentOptions;
use Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Clear everything: core clears core's data and calls every installed Alfa plugin's Clear Data hook
 * (onClearData(), the same call Tools > Extensions makes: {@see ClearDataExtensions::clearPlugin()}),
 * as one controlled maintenance workflow. Afterwards Alfa Commerce is installed at the current version
 * and holds no previous shop data of its own; Joomla's record of the extension, its permissions and
 * its enabled state stay. Core coordinates and never guesses: it never names, counts or deletes an
 * extension's data.
 *
 * BEFORE ANY CHANGE (read only; a failure is a refusal and changes nothing): the permission
 * (alfa.data.clear), the typed phrase, the state idle, no second site sharing core's table names
 * ({@see CoreTables::foreignSite()}), core ready to be made again (every planned manifest and install
 * SQL readable, the download store's path safe), and the run's lock. An extension never refuses the
 * run: its status is shown and recorded. The installers are never refused for a run.
 *
 * THE RUN, each step recorded in the maintenance state before it starts, under the run's lock, which
 * each step checks this connection still holds:
 *   1. The audit record, FIRST, in Joomla's action log and the maintenance log file: core's tables,
 *      rows and files, each Alfa plugin with its support, the product images kept. No record, no run.
 *   2. Maintenance ({@see MaintenanceState}), then a wait of {@see self::SETTLE_SECONDS} for the
 *      requests that came in before.
 *   3. extensions: listed again; each plugin's Clear Data called once, in the order (group, element),
 *      while core's old data still exists, marked in the durable state before it is booted; its outcome
 *      recorded: cleared, cleanup failed, not supported (no hook), unavailable (uninstalled under the
 *      run). If any failed, the run PAUSES here: the lock is released, the shop stays in maintenance,
 *      and the page offers Try again ({@see self::retry()}) and Continue anyway ({@see self::proceed()}).
 *      A request that died inside a hook leaves its mark: the next request turns it into cleanup failed
 *      and pauses the run ({@see self::recoverStopped()}); that plugin is called again only on Try again.
 *   4. Core's own clear: the identifier floors recorded; the store's objects removed (one that cannot
 *      be removed fails the step); the declared tables dropped; the schema made again; the steps of a
 *      fresh install ({@see InstallSteps}); the floors applied; the result verified.
 *   5. Closing: the headline ("Core reset completed", or "... with extension cleanup warnings"), in
 *      the action log, the log file, a fresh Alfa notification and the last-clear record; then
 *      maintenance is left.
 *
 * A CORE FAILURE stops the run at its step and leaves the shop in maintenance, marked failed with the
 * database's or the system's answer. {@see self::resume()} runs core's clear again from its start
 * (extensions already cleared are not called again). A run whose process died (phase running, lock
 * free) is resumed the same way.
 *
 * Domain service: the Tools screen, the tests and any later command line reach it through these
 * methods and no other path.
 *
 * @since  2.1.0
 */
final class FactoryReset
{
    /**
     * The phrase a person types to confirm.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const PHRASE = 'DELETE ALL ALFA DATA';

    /**
     * The permission it takes, beside entering the component.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const PERMISSION = 'alfa.data.clear';

    /**
     * Seconds the run waits after entering maintenance, so the requests that came in before can
     * finish before anything is removed.
     *
     * @var int
     *
     * @since  2.1.0
     */
    public const SETTLE_SECONDS = 3;

    /**
     * The steps of core's own clear, in their order.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const CORE_STEPS = [
        'ids', 'files', 'tables', 'schema', 'schema_version', 'options', 'delivery_mapping', 'download_store',
        'users', 'usergroups', 'languages', 'id_floors', 'price_task', 'verify',
    ];

    /**
     * Every step, in its order, as the maintenance state names it.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const STEPS = ['settle', 'extensions', ...self::CORE_STEPS, 'closing'];

    /**
     * The statuses of an extension that make the headline carry warnings.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const WARNING_STATUSES = ['failed', 'unsupported', 'unavailable', 'installed_later'];

    /**
     * Seconds a DROP TABLE waits for another connection's lock on the table before it is refused,
     * instead of the server's default of a year.
     *
     * @var int
     *
     * @since  2.1.0
     */
    private const LOCK_WAIT_SECONDS = 30;

    /**
     * How many of the files not removed the page lists by name; the maintenance log has them all.
     *
     * @var int
     *
     * @since  2.1.0
     */
    private const LISTED_FILES = 50;

    /**
     * The longest error kept in the state, in characters.
     *
     * @var int
     *
     * @since  2.1.0
     */
    private const ERROR_LENGTH = 2000;

    /**
     * The site's connection.
     *
     *
     * @since  2.1.0
     */
    private DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db The site's connection; the container's by default.
     *
     * @since  2.1.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * What Clear everything would do now: the confirmation shows exactly this.
     *
     * @return array{tables: array<string, int|null>, rows: int, store: array<string, mixed>, images: array{folder: string, path: string, referenced: int, present: int}, extensions: list<array<string, mixed>>, refusal: string|null} Each extension: a plugin entry with its status (supported, unsupported or failed), message and description.
     *
     * @since  2.1.0
     */
    public function preview(): array
    {
        $refusal = $this->refusal();

        try {
            $tables = (new CoreTables(db: $this->db))->declared();
        } catch (Throwable $e) {
            $tables = [];
            $refusal ??= Text::sprintf('COM_ALFA_CLEAR_REFUSED_COMPONENT_SQL', $e->getMessage());
        }

        $store = AlfaFileArea::downloadStore();

        return [
            'tables' => $tables,
            'rows' => array_sum(array_map('intval', $tables)),
            'store' => $store->toState() + $store->inspect(),
            'images' => $this->images(),
            'extensions' => $this->extensionSupport(),
            'refusal' => $refusal,
        ];
    }

    /**
     * Why Clear everything may not start on this site now, or null when it may. Read only.
     *
     * @since  2.1.0
     */
    public function refusal(): ?string
    {
        $core = new CoreTables(db: $this->db);
        $foreign = $core->foreignSite();

        if ($foreign !== null) {
            return Text::sprintf('COM_ALFA_CLEAR_REFUSED_FOREIGN_TABLES', $foreign);
        }

        $unready = (new SchemaRecreator(db: $this->db))->unready();

        if ($unready !== null) {
            return Text::sprintf('COM_ALFA_CLEAR_REFUSED_COMPONENT_SQL', $unready);
        }

        $store = AlfaFileArea::downloadStore();

        if ($store->refused !== null) {
            return Text::sprintf('COM_ALFA_CLEAR_REFUSED_STORE', (string) $store->path, $store->refused);
        }

        return null;
    }

    /**
     * Run Clear everything.
     *
     * @param User $user Who runs it.
     * @param string $phrase What the person typed.
     *
     * @return array<string, mixed> The outcome: run, phase (done, paused or failed), step, error,
     *                              extensions, files, tables, made, headline.
     *
     * @throws RuntimeException When it may not start; nothing was changed then.
     *
     * @since  2.1.0
     */
    public function run(User $user, string $phrase): array
    {
        if (!self::permitted(user: $user)) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_NOT_PERMITTED'), 403);
        }

        if (trim($phrase) !== self::PHRASE) {
            throw new RuntimeException(Text::sprintf('COM_ALFA_CLEAR_ERROR_PHRASE', self::PHRASE), 400);
        }

        if (MaintenanceState::current() !== null) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_IN_MAINTENANCE'), 409);
        }

        $refusal = $this->refusal();

        if ($refusal !== null) {
            throw new RuntimeException($refusal . ' ' . Text::_('COM_ALFA_CLEAR_NOTHING_CHANGED'), 409);
        }

        if (!MaintenanceState::lock(db: $this->db)) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_RUNNING'), 409);
        }

        try {
            $tables = (new CoreTables(db: $this->db))->declared();
            $rows = array_sum(array_map('intval', $tables));
            $store = AlfaFileArea::downloadStore();
            $seen = $store->inspect();
            $images = $this->images();
            $plans = $this->extensionSupport();
            $warned = \count(array_filter($plans, static fn (array $p): bool => $p['status'] !== 'supported'));
            $run = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));

            // 1. The audit record first: no record, no run.
            MaintenanceAudit::record(
                languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_STARTED',
                data: ['run' => $run, 'tables' => \count($tables), 'rows' => $rows, 'files' => $seen['files'], 'size' => self::bytes(bytes: $seen['bytes']), 'extensions' => \count($plans), 'warnings' => $warned],
                user: $user,
                line: sprintf(
                    'Clear everything started: core %d tables with %d rows, %d files (%s) in the store %s; %d Alfa plugins listed, %d with a warning; %d product image files kept in %s.',
                    \count($tables),
                    $rows,
                    $seen['files'],
                    self::bytes(bytes: $seen['bytes']),
                    $store->path ?? '(none)',
                    \count($plans),
                    $warned,
                    $images['present'],
                    $images['folder'],
                ),
            );

            foreach ($tables as $table => $count) {
                MaintenanceAudit::detail(run: $run, line: sprintf('core table %s: %d rows', $table, (int) $count));
            }

            foreach ($plans as $plan) {
                MaintenanceAudit::detail(run: $run, line: sprintf('extension %s (%s): %s%s', $plan['name'], $plan['key'], $plan['status'], $plan['message'] === '' ? '' : ': ' . $plan['message']));
            }

            // 2. Maintenance. Every plugin waits for its call, which decides its support again under its mark.
            $extensions = [];

            foreach ($plans as $plan) {
                $extensions[$plan['key']] = self::stateEntry(entry: $plan, status: 'pending');
            }

            $entered = MaintenanceState::enter(state: [
                'run' => $run,
                'phase' => 'running',
                'step' => 'settle',
                'user' => ['id' => (int) $user->id, 'name' => (string) $user->name, 'username' => (string) $user->username],
                'started' => gmdate('Y-m-d H:i:s'),
                'updated' => gmdate('Y-m-d H:i:s'),
                'error' => null,
                'extensions' => $extensions,
                'areas' => [$store->toState()],
                'images' => $images,
                'tables' => \count($tables),
                'rows' => $rows,
            ]);

            if (!$entered) {
                throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_IN_MAINTENANCE'), 409);
            }

            sleep(self::SETTLE_SECONDS);

            return $this->carryOut(user: $user, run: $run, mode: 'run');
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }
    }

    /**
     * Try again, while the run is paused: the plugins are listed again, and every one that is not
     * cleared is called again, the one whose request died included. When none fails any more, core's
     * clear follows in the same request; otherwise the run pauses again.
     *
     * @param User $user Who runs it.
     *
     * @return array<string, mixed> The outcome, as {@see self::run()} gives it.
     *
     * @throws RuntimeException When the run is not paused, or another request holds it.
     *
     * @since  2.1.0
     */
    public function retry(User $user): array
    {
        return $this->continueRun(user: $user, mode: 'retry', phases: ['paused'], languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_RETRIED');
    }

    /**
     * Continue anyway, while the run is paused: no extension is asked again, the failures stay
     * recorded, and core's clear follows.
     *
     * @param User $user Who runs it.
     *
     * @return array<string, mixed> The outcome, as {@see self::run()} gives it.
     *
     * @throws RuntimeException When the run is not paused, or another request holds it.
     *
     * @since  2.1.0
     */
    public function proceed(User $user): array
    {
        return $this->continueRun(user: $user, mode: 'proceed', phases: ['paused'], languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_CONTINUED');
    }

    /**
     * Run again, after a core step failed or the process died: core's clear from its start (a run that
     * died between two plugins of the extensions step calls the ones not cleared first; one that died
     * inside a plugin's hook is paused instead, {@see self::recoverStopped()}). Extensions already
     * cleared are not called again.
     *
     * @param User $user Who runs it.
     *
     * @return array<string, mixed> The outcome, as {@see self::run()} gives it.
     *
     * @throws RuntimeException When there is nothing to run again, or another request holds it.
     *
     * @since  2.1.0
     */
    public function resume(User $user): array
    {
        return $this->continueRun(user: $user, mode: 'resume', phases: ['failed', 'running'], languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_RESUMED');
    }

    /**
     * Take a stopped or paused run up again.
     *
     * @param User $user Who runs it.
     * @param string $mode retry, proceed or resume.
     * @param string[] $phases The phases it may continue from.
     * @param string $languageKey The action log record that opens it.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When it may not continue now.
     *
     * @since  2.1.0
     */
    private function continueRun(User $user, string $mode, array $phases, string $languageKey): array
    {
        if (!self::permitted(user: $user)) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_NOT_PERMITTED'), 403);
        }

        $state = MaintenanceState::current();

        if ($state === null) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_NOTHING_TO_RESUME'), 409);
        }

        if (!\in_array($state['phase'] ?? '', $phases, true)) {
            throw new RuntimeException(Text::_($mode === 'resume' ? 'COM_ALFA_CLEAR_ERROR_PAUSED' : 'COM_ALFA_CLEAR_ERROR_NOT_PAUSED'), 409);
        }

        // A run that died inside a plugin's hook is paused, not run again over that plugin.
        if ($mode === 'resume' && $this->recoverStopped(user: $user)) {
            $paused = MaintenanceState::current() ?? [];

            return ['run' => (string) ($paused['run'] ?? ''), 'mode' => $mode, 'phase' => 'paused', 'step' => 'extensions', 'error' => null, 'extensions' => (array) ($paused['extensions'] ?? [])];
        }

        if (!MaintenanceState::lock(db: $this->db)) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_RUNNING'), 409);
        }

        try {
            $run = (string) ($state['run'] ?? '');
            $step = (string) ($state['step'] ?? '');
            $failures = \count(array_filter((array) ($state['extensions'] ?? []), static fn ($e): bool => ($e['status'] ?? '') === 'failed'));

            MaintenanceAudit::record(
                languageKey: $languageKey,
                data: ['run' => $run, 'step' => $step, 'failures' => $failures],
                user: $user,
                line: match ($mode) {
                    'retry' => sprintf('Clear everything: Try again, for the extensions not cleared (%d failed).', $failures),
                    'proceed' => sprintf('Clear everything: Continue anyway, with %d extension cleanup failures.', $failures),
                    default => sprintf('Clear everything run again; it had stopped at the step %s.', $step),
                },
            );

            MaintenanceState::update(changes: ['phase' => 'running', 'error' => null]);

            // Try again tries every plugin once more, one whose code could not be loaded included.
            if ($mode === 'retry') {
                foreach (ClearDataStatus::all() as $key => $entry) {
                    if (\is_array($entry) && isset($entry['loading'])) {
                        ClearDataStatus::loading(key: (string) $key, loading: false);
                    }
                }
            }

            if ($mode === 'resume') {
                $mode = \in_array($step, ['settle', 'extensions'], true) ? 'retry' : 'core';
            }

            return $this->carryOut(user: $user, run: $run, mode: $mode);
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }
    }

    /**
     * A run whose request died inside a plugin's hook: the plugin's mark (`clearing`, written before it
     * was booted) is still there and the run's lock is free. That plugin becomes cleanup failed, with
     * the reason, and the run is paused: the page offers Try again (that plugin included, on that
     * explicit click only) and Continue anyway. Nothing else changes. Asked by the Clear everything
     * page and by Run again, under the lock.
     *
     * @param User $user Who asks, for the audit record.
     *
     * @return bool Whether it paused the run.
     *
     * @since  2.1.0
     */
    public function recoverStopped(User $user): bool
    {
        $stopped = static function (?array $state): bool {
            return $state !== null && ($state['phase'] ?? '') === 'running' && \in_array($state['step'] ?? '', ['settle', 'extensions'], true);
        };

        if (!$stopped(MaintenanceState::current()) || !MaintenanceState::lock(db: $this->db)) {
            return false;
        }

        try {
            $state = MaintenanceState::current();

            if (!$stopped($state)) {
                return false;
            }

            $marks = ClearDataStatus::all();
            $extensions = (array) ($state['extensions'] ?? []);
            $failed = [];

            foreach ($extensions as $key => $extension) {
                if (($extension['status'] ?? '') !== 'pending' || ($marks[$key]['status'] ?? '') !== 'clearing') {
                    continue;
                }

                $reason = ClearDataExtensions::stoppedReason(mark: (array) $marks[$key]);
                $extensions[$key] = ['status' => 'failed', 'message' => $reason] + (array) $extension;
                ClearDataStatus::mark(key: (string) $key, status: 'failed', message: $reason, source: ClearDataEvent::CLEAR_EVERYTHING);
                $failed[] = (string) $key;
            }

            if ($failed === []) {
                return false;
            }

            MaintenanceState::update(changes: ['extensions' => $extensions, 'phase' => 'paused', 'step' => 'extensions']);

            try {
                MaintenanceAudit::record(
                    languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_PAUSED',
                    data: ['run' => (string) ($state['run'] ?? ''), 'failures' => \count($failed)],
                    user: $user,
                    line: sprintf('Clear everything paused: the request stopped while it was clearing %s.', implode(', ', $failed)),
                );
            } catch (Throwable) {
                // The state says it; the page shows it.
            }

            return true;
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }
    }

    /**
     * The run inside maintenance: the extensions (unless core's clear is run again on its own), then
     * core's clear and the closing.
     *
     * @param User $user Who runs it.
     * @param string $run The run's id.
     * @param string $mode run, retry, proceed or core.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private function carryOut(User $user, string $run, string $mode): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $step = 'extensions';
        $outcome = ['run' => $run, 'mode' => $mode];

        try {
            if ($mode !== 'core') {
                $this->at(step: $step);
                $extensions = $this->clearExtensions(run: $run, call: $mode !== 'proceed', later: $mode === 'proceed');
                $outcome['extensions'] = $extensions;
                $failed = array_filter($extensions, static fn (array $e): bool => $e['status'] === 'failed');

                if ($failed !== [] && $mode !== 'proceed') {
                    MaintenanceState::update(changes: ['phase' => 'paused', 'step' => 'extensions']);
                    MaintenanceAudit::record(
                        languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_PAUSED',
                        data: ['run' => $run, 'failures' => \count($failed)],
                        user: $user,
                        line: sprintf('Clear everything paused before core\'s clear: %d extensions could not confirm their data was removed (%s).', \count($failed), implode(', ', array_keys($failed))),
                    );

                    return $outcome + ['phase' => 'paused', 'step' => 'extensions', 'error' => null];
                }
            }

            return $outcome + $this->clearCore(user: $user, run: $run, step: $step);
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, self::ERROR_LENGTH);
            $outcome += ['phase' => 'failed', 'step' => $step, 'error' => $error];

            try {
                MaintenanceState::update(changes: ['phase' => 'failed', 'step' => $step, 'error' => $error]);
            } catch (Throwable $stateFailure) {
                $outcome['error'] .= ' The maintenance state could not be updated: ' . $stateFailure->getMessage();
            }

            try {
                MaintenanceAudit::record(
                    languageKey: 'COM_ALFA_ACTIONLOG_FULL_CLEAR_FAILED',
                    data: ['run' => $run, 'step' => $step, 'error' => $error],
                    user: $user,
                    line: sprintf('Clear everything stopped at the step %s: %s Alfa Commerce stays in maintenance.', $step, $error),
                );
            } catch (Throwable $auditFailure) {
                $outcome['error'] .= ' ' . $auditFailure->getMessage();
            }

            return $outcome;
        }
    }

    /**
     * The extensions step: list the plugins again, reconcile them with the state, and (when asked) call
     * the Clear Data of every plugin that is not cleared, through the one call path
     * ({@see ClearDataExtensions::clearPlugin()}). Each outcome is written to the state as soon as it is
     * known; a plugin no longer installed is unavailable.
     *
     * @param string $run The run's id.
     * @param bool $call Whether to call the plugins (false: Continue anyway).
     * @param bool $later Whether a plugin found now was installed after the extensions were cleared.
     *
     * @return array<string, array<string, mixed>> The plugins by key, in their order.
     *
     * @since  2.1.0
     */
    private function clearExtensions(string $run, bool $call, bool $later): array
    {
        $known = (array) ((MaintenanceState::current() ?? [])['extensions'] ?? []);
        $lister = new ClearDataExtensions(db: $this->db);
        $listed = [];

        foreach ($lister->plugins() as $plugin) {
            $listed[$plugin['key']] = ['name' => $lister->displayName(entry: $plugin)] + $plugin;
        }

        $extensions = [];

        foreach ($listed as $key => $plugin) {
            $before = \is_array($known[$key] ?? null) ? $known[$key] : null;

            if ($before !== null && \in_array($before['status'] ?? '', ['cleared', 'installed_later'], true)) {
                $extensions[$key] = $before;

                continue;
            }

            if ($before === null && $later) {
                $extensions[$key] = self::stateEntry(entry: $plugin, status: 'installed_later');
                MaintenanceAudit::detail(run: $run, line: sprintf('extension %s (%s): installed after the extensions were cleared; not cleared', $plugin['name'], $key));

                continue;
            }

            if ($before !== null && !$call) {
                // Continue anyway: what was recorded stays; one never reached (its run stopped before it) is said.
                $extensions[$key] = ($before['status'] ?? '') === 'pending'
                    ? ['status' => 'failed', 'message' => Text::_('COM_ALFA_EXTENSIONS_NOT_CALLED')] + $before
                    : $before;

                continue;
            }

            $extensions[$key] = self::stateEntry(entry: $plugin, status: 'pending');
        }

        foreach ($known as $key => $before) {
            if (!isset($extensions[$key]) && \is_array($before)) {
                $extensions[$key] = \in_array($before['status'] ?? '', ['cleared', 'unsupported', 'unavailable'], true)
                    ? $before
                    : ['status' => 'unavailable', 'message' => Text::_('COM_ALFA_EXTENSIONS_GONE')] + $before;
            }
        }

        MaintenanceState::update(changes: ['extensions' => $extensions]);

        if (!$call) {
            return $extensions;
        }

        foreach ($extensions as $key => $extension) {
            if ($extension['status'] !== 'pending' || !isset($listed[$key])) {
                continue;
            }

            $this->assertLocked();
            $done = $lister->clearPlugin(plugin: $listed[$key], source: ClearDataEvent::CLEAR_EVERYTHING, run: $run);
            $extensions[$key] = array_merge($extension, $done);
            MaintenanceState::update(changes: ['extensions' => $extensions]);
            MaintenanceAudit::detail(run: $run, line: sprintf('extension %s (%s): %s%s', $extension['name'], $key, $done['status'], $done['message'] === '' ? '' : ': ' . $done['message']));
        }

        return $extensions;
    }

    /**
     * Core's own clear, then the closing. Run again from its first step after a failure: the data is
     * gone already, so whatever exists is dropped and made again.
     *
     * @param User $user Who runs it.
     * @param string $run The run's id.
     * @param string $step The step the run is at, for the caller's failure record.
     *
     * @return array<string, mixed>
     *
     * @throws Throwable When a step fails.
     *
     * @since  2.1.0
     */
    private function clearCore(User $user, string $run, string &$step): array
    {
        $state = MaintenanceState::current() ?? [];
        $core = new CoreTables(db: $this->db);

        // The identifier floors, before anything goes: ids are never given out twice.
        $this->at(step: $step = 'ids');
        $drop = $core->dropSet();
        $floors = $core->identifierFloors(tables: $drop, recorded: array_map('intval', (array) ($state['ids'] ?? [])));
        $dropped = array_values(array_unique([...array_map('strval', (array) ($state['dropped'] ?? [])), ...$drop]));
        MaintenanceState::update(changes: ['ids' => $floors, 'dropped' => $dropped]);

        // The store's objects, at the place resolved before the configuration goes.
        $this->at(step: $step = 'files');
        $recordedArea = ((array) ($state['areas'] ?? []))[0] ?? [];
        $area = AlfaFileArea::fromState(state: (array) $recordedArea);
        $files = $this->clearStore(run: $run, area: $area, earlier: (array) ($state['files'] ?? []));

        // The declared tables, found again now.
        $this->at(step: $step = 'tables');
        $removed = $this->dropTables(tables: $core->dropSet());
        $dropped = array_values(array_unique([...$dropped, ...$removed]));
        MaintenanceState::update(changes: ['dropped' => $dropped]);
        Storage::forgetRecordedRoot();

        // The schema, as a fresh install makes it.
        $this->at(step: $step = 'schema');
        $ran = (new SchemaRecreator(db: $this->db))->run();
        MaintenanceAudit::detail(run: $run, line: 'install SQL run again: ' . implode(', ', $ran));

        // What a fresh install does next.
        $steps = new InstallSteps(db: $this->db);

        $this->at(step: $step = 'schema_version');
        $steps->recordSchemaVersion(version: InstallSteps::installedSchemaVersion());

        $this->at(step: $step = 'options');
        $steps->applyDefaultOptions(replace: true);

        $this->at(step: $step = 'delivery_mapping');
        $steps->seedDeliveryPlaceMapping();

        $this->at(step: $step = 'download_store');
        $root = $steps->provisionDownloadStore();
        $storeWarning = null;

        try {
            $storeWarning = $steps->checkDownloadStore(root: $root)['message'] ?? null;
        } catch (Throwable $e) {
            $storeWarning = 'The protection of the download store could not be checked: ' . $e->getMessage();
        }

        $this->at(step: $step = 'users');
        $steps->syncUsers();

        $this->at(step: $step = 'usergroups');
        $steps->syncUsergroups();

        $this->at(step: $step = 'languages');
        $languages = $steps->syncLanguageSchema();

        if (!empty($languages['errors'])) {
            throw new RuntimeException('Some language tables could not be made: ' . implode('; ', array_map(
                static fn (string $table, $error): string => $table . ' (' . (\is_string($error) ? $error : json_encode($error)) . ')',
                array_keys($languages['errors']),
                array_values($languages['errors']),
            )));
        }

        $this->at(step: $step = 'id_floors');
        $core->applyFloors(floors: $floors);

        // The scheduled task that prepares list prices, as an installation makes it (Joomla's own record: a site
        // that never had it gets it here). A failure is said, not a failed reset.
        $this->at(step: $step = 'price_task');
        $taskWarning = null;

        try {
            PriceIndexSyncService::ensurePreparationTask();
        } catch (Throwable $e) {
            $taskWarning = 'The scheduled task that prepares the list prices of customer groups was not created: ' . $e->getMessage();
            MaintenanceAudit::detail(run: $run, line: $taskWarning);
        }

        $this->at(step: $step = 'verify');
        $this->verify(core: $core, floors: $floors, dropped: $dropped, area: $area);

        // The closing record, then out of maintenance.
        $this->at(step: $step = 'closing');
        $state = MaintenanceState::current() ?? [];
        $made = \count($core->declared(count: false));
        $warnings = array_filter((array) ($state['extensions'] ?? []), static fn ($e): bool => \in_array($e['status'] ?? '', self::WARNING_STATUSES, true));
        $clean = $warnings === [];
        $headline = Text::_($clean ? 'COM_ALFA_CLEAR_HEADLINE' : 'COM_ALFA_CLEAR_HEADLINE_WARNINGS');
        $images = (array) ($state['images'] ?? []);

        MaintenanceAudit::record(
            languageKey: $clean ? 'COM_ALFA_ACTIONLOG_FULL_CLEAR_DONE' : 'COM_ALFA_ACTIONLOG_FULL_CLEAR_DONE_WARNINGS',
            data: ['run' => $run, 'tables' => \count($removed), 'made' => $made, 'files' => $files['deleted'], 'left' => $files['left'], 'warnings' => \count($warnings)],
            user: $user,
            line: sprintf(
                '%s: %d core tables removed, %d made again, %d files removed from the store, %d left there (not core\'s); %d extensions with a warning; kept %d product image files in %s.',
                $headline,
                \count($removed),
                $made,
                $files['deleted'],
                $files['left'],
                \count($warnings),
                (int) ($images['present'] ?? 0),
                (string) ($images['folder'] ?? ''),
            ),
        );

        foreach ($warnings as $key => $extension) {
            MaintenanceAudit::detail(run: $run, line: sprintf('warning %s: %s', $key, self::warning(extension: $extension)));
        }

        $this->notify(user: $user, run: $run, headline: $headline, warnings: $warnings, images: $images);
        SetupHelper::invalidate();

        $finished = gmdate('Y-m-d H:i:s');
        $last = [
            'run' => $run,
            'user' => $state['user'] ?? ['id' => (int) $user->id, 'name' => (string) $user->name, 'username' => (string) $user->username],
            'finished' => $finished,
            'clean' => $clean,
            'headline' => $headline,
            'extensions' => $state['extensions'] ?? [],
            'files' => $files,
            'images' => $images,
            'tables' => \count($removed),
            'made' => $made,
        ];
        MaintenanceState::leave(lastClear: $last);

        return $last + ['phase' => 'done', 'step' => null, 'error' => null, 'store_warning' => $storeWarning, 'task_warning' => $taskWarning];
    }

    /**
     * Remove the store's objects at the recorded place. A file that cannot be removed fails the step
     * (Run again finishes it once it can go); a file that is not core's, or a link, stays and is
     * reported.
     *
     * @param string $run The run's id.
     * @param AlfaFileArea $area The store, as recorded and checked again now.
     * @param array<string, mixed> $earlier What an earlier pass of this run reported.
     *
     * @return array{path: string|null, deleted: int, bytes: int, failed: int, left: int, not_removed: list<string>}
     *
     * @throws RuntimeException When the place is refused now, or a file could not be removed.
     *
     * @since  2.1.0
     */
    private function clearStore(string $run, AlfaFileArea $area, array $earlier): array
    {
        if ($area->refused !== null) {
            throw new RuntimeException(sprintf('No file can be removed from the store of purchased files at %s: %s.', (string) $area->path, $area->refused));
        }

        $erased = $area->erase();
        $files = [
            'path' => $area->path,
            'deleted' => $erased['deleted'] + (int) ($earlier['deleted'] ?? 0),
            'bytes' => $erased['bytes'] + (int) ($earlier['bytes'] ?? 0),
            'failed' => \count($erased['failed']),
            'left' => \count($erased['left']),
            // The first of them for the page; every one is in the maintenance log.
            'not_removed' => \array_slice(array_map(
                static fn (array $file): string => $file['path'] . ': ' . $file['reason'],
                [...$erased['failed'], ...$erased['left']],
            ), 0, self::LISTED_FILES),
        ];

        foreach ($erased['failed'] as $failed) {
            MaintenanceAudit::detail(run: $run, line: sprintf('store: could not remove %s: %s', $failed['path'], $failed['reason']));
        }

        foreach ($erased['left'] as $left) {
            MaintenanceAudit::detail(run: $run, line: sprintf('store: left %s: %s', $left['path'], $left['reason']));
        }

        MaintenanceState::update(changes: ['files' => $files]);

        if ($erased['failed'] !== []) {
            throw new RuntimeException(sprintf(
                '%d files of the store of purchased files could not be removed (the first: %s: %s); every one is listed in the maintenance log.',
                \count($erased['failed']),
                $erased['failed'][0]['path'],
                $erased['failed'][0]['reason'],
            ));
        }

        return $files;
    }

    /**
     * Check the cleared shop before maintenance is left. All must hold:
     *   V1 every table the install SQL makes exists;
     *   V2 no removed table exists that a fresh install does not make;
     *   V3 no object is left in the store, and no folder of it is unreadable (it may hold objects);
     *   V4 the recorded schema version is the installed schema's;
     *   V5 the identifier floors hold.
     *
     * @param CoreTables $core The declared set.
     * @param array<string, int> $floors The identifier floors.
     * @param list<string> $dropped Every table this run removed.
     * @param AlfaFileArea $area The store.
     *
     * @throws RuntimeException Naming every check that does not hold.
     *
     * @since  2.1.0
     */
    private function verify(CoreTables $core, array $floors, array $dropped, AlfaFileArea $area): void
    {
        $problems = [];
        $existing = $core->existing();

        $missing = array_values(array_filter($core->sqlTables(), static fn (string $t): bool => !isset($existing[strtolower($t)])));

        if ($missing !== []) {
            $problems[] = 'V1 tables a fresh install makes are missing: ' . implode(', ', $missing);
        }

        $fresh = array_flip($core->freshSet());
        $back = array_values(array_filter($dropped, static fn (string $t): bool => isset($existing[strtolower($t)]) && !isset($fresh[strtolower($t)])));

        if ($back !== []) {
            $problems[] = 'V2 removed tables a fresh install does not make exist again: ' . implode(', ', $back);
        }

        $seen = $area->inspect();
        $unreadable = \count(array_filter($seen['left'], static fn (array $entry): bool => $entry['reason'] === AlfaFileArea::UNREADABLE));

        if ($seen['files'] > 0 || $unreadable > 0) {
            $problems[] = sprintf('V3 %d objects are left in the store of purchased files, and %d of its folders cannot be read', $seen['files'], $unreadable);
        }

        $db = $this->db;
        $recorded = (string) $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version')),
        )->loadResult();
        $installed = InstallSteps::installedSchemaVersion();

        if ($recorded !== $installed) {
            $problems[] = sprintf('V4 the recorded schema version is %s, the installed schema %s', $recorded === '' ? '(none)' : $recorded, $installed);
        }

        $below = $core->floorsBelow(floors: $floors);

        if ($below !== []) {
            $problems[] = 'V5 identifiers below their floor: ' . implode(', ', array_map(
                static fn (string $t, array $b): string => sprintf('%s next %d, floor %d', $t, $b['next'], $b['floor']),
                array_keys($below),
                array_values($below),
            ));
        }

        if ($problems !== []) {
            throw new RuntimeException('The cleared shop does not check out: ' . implode('; ', $problems) . '.');
        }
    }

    /**
     * The product images the shop refers to, which Clear everything keeps (shared Joomla media, never
     * proven to be core's alone): the configured folder, the files the media rows refer to (the
     * component's own shipped media aside), and how many of them exist. Read only.
     *
     * @return array{folder: string, path: string, referenced: int, present: int}
     *
     * @since  2.1.0
     */
    public function images(): array
    {
        $folder = trim((string) (ComponentOptions::stored(db: $this->db)['media_save_location'] ?? ComponentOptions::DEFAULTS['media_save_location']));
        $root = rtrim(str_replace('\\', '/', JPATH_ROOT), '/');
        $images = ['folder' => $folder, 'path' => $root . '/' . trim($folder, '/'), 'referenced' => 0, 'present' => 0];
        $db = $this->db;

        try {
            $paths = $db->setQuery(
                'SELECT ' . $db->quoteName('path') . ' AS p FROM ' . $db->quoteName('#__alfa_media') . ' WHERE ' . $db->quoteName('path') . ' <> ' . $db->quote('')
                . ' UNION SELECT ' . $db->quoteName('thumbnail') . ' FROM ' . $db->quoteName('#__alfa_media')
                . ' WHERE ' . $db->quoteName('thumbnail') . ' IS NOT NULL AND ' . $db->quoteName('thumbnail') . ' <> ' . $db->quote(''),
            )->loadColumn() ?: [];
        } catch (Throwable) {
            return $images;
        }

        foreach (array_unique(array_map(static fn ($p): string => ltrim(trim((string) $p), '/'), $paths)) as $path) {
            if ($path === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path) || str_starts_with($path, 'media/com_alfa/')) {
                continue;
            }

            $images['referenced']++;

            if (is_file($root . '/' . $path)) {
                $images['present']++;
            }
        }

        return $images;
    }

    /**
     * Every Alfa plugin with what Clear everything can do with it, read only: supported (it has the
     * hook), unsupported (it has none), failed (its code could not be loaded) or busy (a clear is at
     * work), with its message and the description it may define. Boots the plugins under THE lock, each
     * marked while it loads ({@see ClearDataExtensions::inspect()}), which changes no data.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.0
     */
    private function extensionSupport(): array
    {
        $lister = new ClearDataExtensions(db: $this->db);

        return array_map(
            static fn (array $plugin): array => ['name' => $plugin['label']] + $plugin['support'] + $plugin,
            $lister->inspect(entries: $lister->plugins()),
        );
    }

    /**
     * A plugin as the state keeps it.
     *
     * @param array<string, mixed> $entry The plugin's listing entry, with its name.
     * @param string $status Its status now.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private static function stateEntry(array $entry, string $status): array
    {
        return [
            'name' => (string) ($entry['name'] ?? $entry['key']),
            'extension_id' => (int) $entry['extension_id'],
            'type' => (string) $entry['type'],
            'folder' => (string) $entry['folder'],
            'element' => (string) $entry['element'],
            'client_id' => (int) $entry['client_id'],
            'status' => $status,
            'message' => '',
        ];
    }

    /**
     * What the page, the log and the notification say of an extension with a warning.
     *
     * @param array<string, mixed> $extension The extension as the state keeps it.
     *
     * @since  2.1.0
     */
    public static function warning(array $extension): string
    {
        $name = (string) ($extension['name'] ?? '');

        return match ($extension['status'] ?? '') {
            'failed' => Text::sprintf('COM_ALFA_CLEAR_EXTENSION_FAILED', $name),
            'unsupported' => Text::sprintf('COM_ALFA_CLEAR_EXTENSION_UNSUPPORTED', $name),
            'unavailable' => Text::sprintf('COM_ALFA_CLEAR_EXTENSION_UNAVAILABLE', $name),
            'installed_later' => Text::sprintf('COM_ALFA_CLEAR_EXTENSION_INSTALLED_LATER', $name),
            default => $name,
        };
    }

    /**
     * Say which step the run is at, before it starts.
     *
     * @param string $step One of {@see self::STEPS}.
     *
     * @since  2.1.0
     */
    private function at(string $step): void
    {
        $this->assertLocked();
        MaintenanceState::update(changes: ['step' => $step]);
    }

    /**
     * Stop when this connection no longer holds the run's lock: a lost connection loses it, and Joomla's
     * driver reconnects silently, so the run would go on unlocked beside a second one.
     *
     * @throws RuntimeException When the lock is not held.
     *
     * @since  2.1.0
     */
    private function assertLocked(): void
    {
        if (!MaintenanceState::holdsLock(db: $this->db)) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_LOST_LOCK'));
        }
    }

    /**
     * Drop core's tables, with the foreign key checks of this connection off and a short wait for
     * another connection's lock.
     *
     * @param list<string> $tables The tables.
     *
     * @return list<string> The tables dropped.
     *
     * @throws RuntimeException When a table cannot be dropped; the ones already dropped stay so.
     *
     * @since  2.1.0
     */
    private function dropTables(array $tables): array
    {
        $db = $this->db;
        $dropped = [];

        $db->setQuery('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS)->execute();
        $db->setQuery('SET FOREIGN_KEY_CHECKS = 0')->execute();

        try {
            foreach ($tables as $table) {
                try {
                    $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
                } catch (Throwable $e) {
                    throw new RuntimeException($table . ': ' . $e->getMessage(), 0, $e);
                }

                $dropped[] = $table;
            }
        } finally {
            $db->setQuery('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        return $dropped;
    }

    /**
     * The fresh Alfa entry of the clear: a notification in Alfa's notification centre, made in the
     * tables just created, with the headline, every extension warning and the kept product images.
     *
     * @param User $user Who ran it.
     * @param string $run The run's id.
     * @param string $headline The headline.
     * @param array<string, array<string, mixed>> $warnings The extensions with a warning.
     * @param array<string, mixed> $images The product images kept.
     *
     * @since  2.1.0
     */
    private function notify(User $user, string $run, string $headline, array $warnings, array $images): void
    {
        $message = Text::sprintf('COM_ALFA_CLEAR_NOTIFY_MESSAGE', (string) $user->name, (string) $user->username, gmdate('Y-m-d H:i'), $run)
            . ' ' . $headline . '.';

        foreach ($warnings as $extension) {
            $message .= ' ' . self::warning(extension: $extension);
        }

        $message .= ' ' . Text::sprintf('COM_ALFA_CLEAR_IMAGES_KEPT', (int) ($images['present'] ?? 0), (string) ($images['folder'] ?? ''));

        NotificationHelper::push(dedupKey: 'alfa.maintenance.cleared', title: Text::_('COM_ALFA_CLEAR_NOTIFY_TITLE'), options: [
            'severity' => $warnings === [] ? 'info' : 'warning',
            'message' => $message,
        ]);
    }

    /**
     * Whether a user may run Clear everything: enter the component, and hold the permission to
     * clear Alfa data (granted to no group at install, so Super Users only until a Super User
     * grants it).
     *
     * @param User $user The user.
     *
     * @since  2.1.0
     */
    public static function permitted(User $user): bool
    {
        return $user->authorise('core.manage', 'com_alfa') && $user->authorise(self::PERMISSION, 'com_alfa');
    }

    /**
     * Whether a run holds the lock now (for the maintenance page: running, or stopped without a word).
     *
     * @since  2.1.0
     */
    public function isRunning(): bool
    {
        return MaintenanceState::lockTaken(db: $this->db);
    }

    /**
     * A size in words.
     *
     * @param int $bytes The size in bytes.
     *
     * @since  2.1.0
     */
    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024, 1) . ' KB',
            default => $bytes . ' bytes',
        };
    }
}
