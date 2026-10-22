<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ClearData;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Tools\ClearDataEvent;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceAudit;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\SchemaRecreator;
use Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate;
use Closure;
use Joomla\CMS\Extension\DummyPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\CurrentUserInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The installed Alfa extensions, the one way core calls an extension's Clear Data, and the three actions of Tools >
 * Extensions. Core finds extensions here, never their data: what an extension clears, where and how is the
 * extension's own, in its hook ({@see ClearDataEvent}).
 *
 * WHICH EXTENSIONS. One query of Joomla's extensions table ({@see self::installed()}, the list Options > About shows
 * too): Alfa Commerce, packages `pkg_alfa...`, plugins of a group `alfa...` or of an `Alfa\` namespace (the task
 * plugins), modules `mod_alfa...`. Tools > Extensions and Clear everything leave out Alfa Commerce and the extensions
 * its manifest bundles.
 *
 * WHO SUPPORTS CLEAR DATA. A plugin that has onClearData(ClearDataEvent $event): void, asked with method_exists() on the
 * booted plugin, enabled or not. A plugin without it: "Automatic Clear Data not supported", none of its data is
 * touched. A module is not part of Clear Data: it can only be removed. A package is one row; its Clear Data is its
 * plugins' own, each called the same way. Even this question is asked under THE lock, the plugin marked `loading`
 * before it is booted ({@see self::support()}): a plugin whose code cannot be loaded (a parse error is caught; a fatal
 * ends the request) is shown "could not be loaded", never breaks the page twice, and is booted again only when someone
 * asks ({@see self::retryLoad()}) or presses Try again in Clear everything.
 *
 * THE CALL ({@see self::clearPlugin()}), the same for Clear Data, Remove extension and its data and Clear everything,
 * always under THE lock ({@see MaintenanceState::lock()}): the row read again (gone: unavailable); the extension marked
 * `clearing` in the durable state; the plugin booted, marked `loading` while its code loads; given the name, group and params a disabled plugin's service
 * provider leaves out; its language loaded; its hook called inside a catch of every Throwable; a database transaction
 * it left open rolled back; then the event read. Expected failures come through setError(), word for word; a
 * Throwable is logged with its details and said as an actionable sentence. A request that dies inside the hook leaves
 * the mark, and the next request turns it into cleanup failed ({@see self::recoverStale()},
 * {@see FactoryReset::recoverStopped()}): it is not called again on its own. A plugin whose code threw while it loaded
 * is not booted again in the same request: PHP loads a file once, and Joomla would answer with an empty plugin.
 *
 * REMOVAL is Joomla's own uninstall, through com_installer's Manage model: its permission (core.delete), its locked
 * check, its messages. Remove extension only never calls the hook; Remove extension and its data uninstalls only after
 * every plugin of the row cleared its data.
 *
 * @since  2.1.0
 */
final class ClearDataExtensions
{
    /**
     * The phrase a person types to confirm Clear Data, or Remove extension and its data.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const PHRASE = 'DELETE EXTENSION DATA';

    /**
     * How the screen orders its rows by type: packages first, then plugins, modules, components.
     *
     * @var array<string, int>
     *
     * @since  2.1.0
     */
    private const ROW_ORDER = ['package' => 0, 'plugin' => 1, 'module' => 2, 'component' => 3];

    /**
     * How Options > About orders the list: the component first, then packages, plugins, modules.
     *
     * @var array<string, int>
     *
     * @since  2.1.0
     */
    private const ABOUT_ORDER = ['component' => 0, 'package' => 1, 'plugin' => 2, 'module' => 3];

    /**
     * The plugins whose code threw while it loaded in this request, by key: booted again, each throws the same.
     *
     * @var array<string, Throwable>
     *
     * @since  2.1.0
     */
    private static array $unloadable = [];

    /**
     * The Alfa packages Joomla began to uninstall in this request, by id: Joomla uninstalls a package's extensions
     * right after it, and the package's warning covers its plugins. Request memory only: nothing is stored.
     *
     * @var array<int, true>
     *
     * @since  2.1.0
     */
    private static array $packagesUninstalling = [];

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
     * Every installed Alfa extension, Alfa Commerce and what it bundles included, in the order of Options > About.
     *
     * @param DatabaseInterface|null $db The connection; the container's by default.
     *
     * @return list<array{key: string, extension_id: int, package_id: int, type: string, element: string, folder: string, client_id: int, enabled: bool, locked: bool, name: string, version: string}>
     *
     * @since  2.1.0
     */
    public static function installed(?DatabaseInterface $db = null): array
    {
        $db ??= Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName(['extension_id', 'package_id', 'type', 'element', 'folder', 'client_id', 'enabled', 'locked', 'name', 'manifest_cache']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('state') . ' = 0')
                ->extendWhere('AND', [
                    '(' . $db->quoteName('type') . ' = ' . $db->quote('component') . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('com_alfa') . ')',
                    '(' . $db->quoteName('type') . ' = ' . $db->quote('plugin') . ' AND ' . $db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%') . ')',
                    '(' . $db->quoteName('type') . ' = ' . $db->quote('plugin') . ' AND ' . $db->quoteName('manifest_cache') . ' LIKE ' . $db->quote('%"namespace":"Alfa%') . ')',
                    '(' . $db->quoteName('type') . ' = ' . $db->quote('module') . ' AND ' . $db->quoteName('element') . ' LIKE ' . $db->quote($db->escape('mod_alfa', true) . '%') . ')',
                    '(' . $db->quoteName('type') . ' = ' . $db->quote('package') . ' AND ' . $db->quoteName('element') . ' LIKE ' . $db->quote($db->escape('pkg_alfa', true) . '%') . ')',
                ], 'OR'),
        )->loadObjectList() ?: [];

        $installed = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;
            $folder = $type === 'plugin' ? (string) $row->folder : '';
            $cache = json_decode((string) $row->manifest_cache, true);
            $cache = \is_array($cache) ? $cache : [];

            // A plugin outside the alfa groups is Alfa's only by its namespace.
            if ($type === 'plugin' && !str_starts_with($folder, 'alfa') && !str_starts_with(ltrim((string) ($cache['namespace'] ?? ''), '\\'), 'Alfa\\')) {
                continue;
            }

            $installed[] = [
                'key' => AlfaInstallerGate::key(type: $type, element: (string) $row->element, folder: $folder),
                'extension_id' => (int) $row->extension_id,
                'package_id' => (int) $row->package_id,
                'type' => $type,
                'element' => (string) $row->element,
                'folder' => $folder,
                'client_id' => (int) $row->client_id,
                'enabled' => (int) $row->enabled === 1,
                'locked' => (int) $row->locked === 1,
                'name' => (string) $row->name,
                'version' => trim((string) ($cache['version'] ?? '')),
            ];
        }

        usort($installed, static fn (array $a, array $b): int => [self::ABOUT_ORDER[$a['type']] ?? 9, self::aboutId(entry: $a)]
            <=> [self::ABOUT_ORDER[$b['type']] ?? 9, self::aboutId(entry: $b)]);

        return $installed;
    }

    /**
     * The rows of Tools > Extensions: every installed Alfa extension but Alfa Commerce and what it bundles. A package
     * is one row, with its extensions in `children`; the others have none.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.0
     */
    public function rows(): array
    {
        $listed = $this->separate();
        $rows = [];

        foreach ($listed as $id => $entry) {
            if ($entry['type'] === 'package') {
                $rows[$id] = $entry + ['children' => []];
            }
        }

        foreach ($listed as $id => $entry) {
            if ($entry['type'] === 'package') {
                continue;
            }

            if ($entry['package_id'] > 0 && isset($rows[$entry['package_id']])) {
                $rows[$entry['package_id']]['children'][] = $entry + ['children' => []];

                continue;
            }

            $rows[$id] = $entry + ['children' => []];
        }

        foreach ($rows as $id => $row) {
            usort($rows[$id]['children'], [self::class, 'byRowOrder']);
        }

        $rows = array_values($rows);
        usort($rows, [self::class, 'byRowOrder']);

        return $rows;
    }

    /**
     * The units of Clear everything: every listed Alfa plugin, packaged or not, in the order (group, element). Modules
     * and packages are not called: a package's plugins are.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.0
     */
    public function plugins(): array
    {
        $plugins = array_values(array_filter($this->separate(), static fn (array $entry): bool => $entry['type'] === 'plugin'));
        usort($plugins, [self::class, 'byRowOrder']);

        return $plugins;
    }

    /**
     * What a row allows, before any click: `supported` (Clear Data, Remove extension only, Remove extension and its
     * data), `unsupported` (Automatic Clear Data not supported: Remove extension only), `failed` (its code could not be
     * loaded: Remove extension only; `stale` when an earlier request died while loading it, and it is not booted
     * again), `remove_only` (a module, or a package without plugins). The caller holds THE lock: the plugin is marked
     * `loading` before it is booted and the mark taken away after, so a request that dies while loading it leaves the
     * mark. Booting changes nothing.
     *
     * @param array<string, mixed> $entry A row of {@see self::rows()} or a plugin of {@see self::plugins()}.
     *
     * @return array{status: string, message: string, stale?: bool}
     *
     * @throws LogicException When the caller does not hold THE lock.
     *
     * @since  2.1.0
     */
    public function support(array $entry): array
    {
        if ($entry['type'] === 'package') {
            $plugins = array_filter((array) ($entry['children'] ?? []), static fn (array $child): bool => $child['type'] === 'plugin');

            if ($plugins === []) {
                return ['status' => 'remove_only', 'message' => ''];
            }

            $answers = array_map(fn (array $plugin): array => ['plugin' => $plugin, 'support' => $this->support(entry: $plugin)], $plugins);

            foreach (['failed', 'unsupported'] as $worst) {
                foreach ($answers as $answer) {
                    if ($answer['support']['status'] === $worst) {
                        return ['status' => $worst, 'message' => $this->displayName(entry: $answer['plugin']) . ': ' . $answer['support']['message']];
                    }
                }
            }

            return ['status' => 'supported', 'message' => ''];
        }

        if ($entry['type'] !== 'plugin') {
            return ['status' => 'remove_only', 'message' => ''];
        }

        if (!MaintenanceState::holdsLock(db: $this->db)) {
            throw new LogicException('An Alfa plugin is booted for Clear Data only under the lock of Clear everything.');
        }

        $key = (string) $entry['key'];

        // A mark found under the lock was left by a request that died while it loaded this plugin.
        if (isset((ClearDataStatus::get($key) ?? [])['loading'])) {
            return ['status' => 'failed', 'message' => Text::_('COM_ALFA_EXTENSIONS_LOAD_STOPPED'), 'stale' => true];
        }

        ClearDataStatus::loading(key: $key, loading: true);

        try {
            $plugin = $this->boot(entry: $entry);
            $support = match (true) {
                $plugin instanceof DummyPlugin => ['status' => 'failed', 'message' => Text::_('COM_ALFA_EXTENSIONS_GONE')],
                method_exists($plugin, 'onClearData') => ['status' => 'supported', 'message' => ''],
                default => ['status' => 'unsupported', 'message' => Text::_('COM_ALFA_EXTENSIONS_UNSUPPORTED')],
            };
        } catch (Throwable $e) {
            $this->logFailure(run: 'clear-data', entry: $entry, error: $e);
            $support = ['status' => 'failed', 'message' => Text::_('COM_ALFA_EXTENSIONS_LOAD_FAILED')];
        }

        ClearDataStatus::loading(key: $key, loading: false);

        return $support;
    }

    /**
     * Every entry with its name, its description and what it allows, for a page (Tools > Extensions, the Clear
     * everything confirmation), a package's extensions in `children` the same way. Takes THE lock, waiting a few
     * seconds for a clear at work; when it cannot, nothing is booted and each plugin answers `busy`.
     *
     * @param list<array<string, mixed>> $entries Rows of {@see self::rows()} or plugins of {@see self::plugins()}.
     *
     * @return list<array<string, mixed>> Each entry + label, description, support.
     *
     * @since  2.1.0
     */
    public function inspect(array $entries): array
    {
        $locked = MaintenanceState::lock(db: $this->db, wait: 10);

        try {
            $describe = function (array $entry) use ($locked): array {
                $support = !$locked && $entry['type'] !== 'module'
                    ? ['status' => 'busy', 'message' => Text::_('COM_ALFA_EXTENSIONS_BUSY_CHECK')]
                    : $this->support(entry: $entry);

                return ['label' => $this->displayName(entry: $entry), 'description' => $this->description(entry: $entry), 'support' => $support] + $entry;
            };

            return array_map(static function (array $entry) use ($describe): array {
                $entry = $describe($entry);

                if (isset($entry['children'])) {
                    $entry['children'] = array_map($describe, (array) $entry['children']);
                }

                return $entry;
            }, $entries);
        } finally {
            if ($locked) {
                MaintenanceState::unlock(db: $this->db);
            }
        }
    }

    /**
     * Try loading again: take away the `loading` mark of an extension (a package's plugins too), so the next page boots
     * it again. Changes nothing of its data.
     *
     * @param User $user Who asks.
     * @param int $extensionId The extension's id.
     *
     * @return string The extension's name.
     *
     * @throws RuntimeException When the user may not use Tools (403), it is not a row of the screen (400), or a clear
     *                          is at work (409).
     *
     * @since  2.1.0
     */
    public function retryLoad(User $user, int $extensionId): string
    {
        if (!$user->authorise('alfa.tools', 'com_alfa')) {
            throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $row = array_column($this->rows(), null, 'extension_id')[$extensionId] ?? null;

        if ($row === null) {
            throw new RuntimeException(Text::sprintf('COM_ALFA_EXTENSIONS_REFUSED_NOT_LISTED', $extensionId), 400);
        }

        if (!MaintenanceState::lock(db: $this->db, wait: 10)) {
            throw new RuntimeException(Text::_('COM_ALFA_EXTENSIONS_ERROR_BUSY'), 409);
        }

        try {
            foreach ([$row, ...(array) $row['children']] as $entry) {
                if (isset((ClearDataStatus::get((string) $entry['key']) ?? [])['loading'])) {
                    ClearDataStatus::loading(key: (string) $entry['key'], loading: false);
                }
            }
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }

        return $this->displayName(entry: $row);
    }

    /**
     * THE call of one plugin's Clear Data, for every action that clears data. The caller holds THE lock. The outcome
     * is recorded ({@see ClearDataStatus}) and returned: `cleared` (with what the plugin said), `failed` (the error it
     * set, word for word; or why core could not confirm it), `unsupported` (no hook), `unavailable` (no longer
     * installed, or its files are not on the site). Core never deletes anything of the extension itself.
     *
     * @param array<string, mixed> $plugin A plugin of {@see self::plugins()}.
     * @param string $source {@see ClearDataEvent::CLEAR_DATA}, REMOVE_WITH_DATA or CLEAR_EVERYTHING.
     * @param string $run The action's or the run's id, for the maintenance log.
     *
     * @return array{status: string, message: string}
     *
     * @throws LogicException When the caller does not hold THE lock.
     *
     * @since  2.1.0
     */
    public function clearPlugin(array $plugin, string $source, string $run = 'clear-data'): array
    {
        if (!MaintenanceState::holdsLock(db: $this->db)) {
            throw new LogicException('Clear Data is called only under the lock of Clear everything.');
        }

        $key = (string) $plugin['key'];
        $row = $this->freshRow(extensionId: (int) $plugin['extension_id']);

        if ($row === null || $row['type'] !== 'plugin' || $row['folder'] !== $plugin['folder'] || $row['element'] !== $plugin['element']) {
            return $this->outcome(key: $key, status: 'unavailable', message: Text::_('COM_ALFA_EXTENSIONS_GONE'), source: $source);
        }

        // A request died while it loaded this plugin's code: it is not booted again on its own.
        if (isset((ClearDataStatus::get($key) ?? [])['loading'])) {
            return $this->outcome(key: $key, status: 'failed', message: Text::_('COM_ALFA_EXTENSIONS_LOAD_STOPPED'), source: $source);
        }

        // The marks, before anything of the extension runs: a request that dies from here on leaves them; one that
        // dies while its code loads leaves `loading` too, so no page boots it again on its own.
        ClearDataStatus::mark(key: $key, status: 'clearing', source: $source);
        ClearDataStatus::loading(key: $key, loading: true);

        try {
            $instance = $this->boot(entry: $row);
        } catch (Throwable $e) {
            ClearDataStatus::loading(key: $key, loading: false);
            $this->logFailure(run: $run, entry: $row, error: $e);

            return $this->outcome(key: $key, status: 'failed', message: Text::_('COM_ALFA_EXTENSIONS_LOAD_FAILED'), source: $source);
        }

        ClearDataStatus::loading(key: $key, loading: false);

        if ($instance instanceof DummyPlugin) {
            return $this->outcome(key: $key, status: 'unavailable', message: Text::_('COM_ALFA_EXTENSIONS_GONE'), source: $source);
        }

        if (!method_exists($instance, 'onClearData')) {
            return $this->outcome(key: $key, status: 'unsupported', message: Text::_('COM_ALFA_EXTENSIONS_UNSUPPORTED'), source: $source);
        }

        $this->adopt(plugin: $instance, row: $row);
        $this->loadLanguage(entry: $row);
        $event = new ClearDataEvent('onClearData', ['source' => $source]);
        $thrown = null;

        try {
            $instance->onClearData($event);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        // Work an extension left uncommitted is never committed by core's next statement.
        $open = false;

        if ($this->db instanceof DatabaseDriver && TransactionState::isOpen(db: $this->db)) {
            $open = true;

            try {
                $this->db->transactionRollback();
            } catch (Throwable) {
                // The connection ends with the request; nothing of it is committed.
            }
        }

        if ($thrown !== null) {
            $this->logFailure(run: $run, entry: $row, error: $thrown);

            return $this->outcome(key: $key, status: 'failed', message: Text::_('COM_ALFA_EXTENSIONS_UNEXPECTED'), source: $source);
        }

        if ($open) {
            return $this->outcome(key: $key, status: 'failed', message: Text::_('COM_ALFA_EXTENSIONS_OPEN_TRANSACTION'), source: $source);
        }

        if ($event->getError() !== '') {
            return $this->outcome(key: $key, status: 'failed', message: $event->getError(), source: $source);
        }

        return $this->outcome(key: $key, status: 'cleared', message: $event->getMessage(), source: $source);
    }

    /**
     * Turn the marks a request left when it died inside an extension's hook into cleanup failed, with the reason.
     * Only outside Clear everything (a run's marks are the run's, {@see FactoryReset::recoverStopped()}) and only
     * when THE lock is free: then no request is clearing anything.
     *
     * @return list<string> The keys turned into cleanup failed.
     *
     * @since  2.1.0
     */
    public function recoverStale(): array
    {
        $marked = static fn (): array => array_keys(array_filter(ClearDataStatus::all(), static fn ($entry): bool => \is_array($entry) && ($entry['status'] ?? '') === 'clearing'));

        if ($marked() === [] || MaintenanceState::current() !== null || !MaintenanceState::lock(db: $this->db)) {
            return [];
        }

        try {
            $converted = [];
            $all = ClearDataStatus::all();

            foreach ($marked() as $key) {
                $mark = (array) ($all[$key] ?? []);
                $loading = isset($mark['loading']);
                ClearDataStatus::mark(key: (string) $key, status: 'failed', message: self::stoppedReason(mark: $mark), source: (string) ($mark['source'] ?? ''));
                MaintenanceAudit::detail(run: 'clear-data', line: sprintf('%s: the request stopped while it was %s; recorded as cleanup failed.', $key, $loading ? 'loading its code' : 'clearing its data'));
                $converted[] = (string) $key;
            }

            return $converted;
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }
    }

    /**
     * Why a `clearing` mark a dead request left is cleanup failed: it stopped while the plugin's code loaded (the mark
     * `loading` is there too), or inside its hook.
     *
     * @param array<string, mixed> $mark The extension's record ({@see ClearDataStatus::get()}).
     *
     * @since  2.1.0
     */
    public static function stoppedReason(array $mark): string
    {
        return Text::_(isset($mark['loading']) ? 'COM_ALFA_EXTENSIONS_LOAD_STOPPED' : 'COM_ALFA_EXTENSIONS_STOPPED');
    }

    /**
     * Tools > Extensions > Clear Data: each selected row clears its own data; the extensions stay installed.
     *
     * @param User $user Who asks.
     * @param array<int|string> $ids The selected extension ids.
     * @param string $phrase What the person typed.
     *
     * @return list<array{key: string, name: string, status: string, message: string}> One per selected row, in order:
     *                                                                                 cleared, failed or refused.
     *
     * @throws RuntimeException When it may not start (403, 400, 409); nothing was done then.
     *
     * @since  2.1.0
     */
    public function clearData(User $user, array $ids, string $phrase): array
    {
        return $this->clearRows(user: $user, ids: $ids, phrase: $phrase, remove: false);
    }

    /**
     * Tools > Extensions > Remove extension and its data: each selected row clears its data, then Joomla uninstalls
     * it; a row whose Clear Data does not succeed is not uninstalled.
     *
     * @param User $user Who asks.
     * @param array<int|string> $ids The selected extension ids.
     * @param string $phrase What the person typed.
     *
     * @return list<array{key: string, name: string, status: string, message: string}> One per selected row, in order:
     *                                                                                 removed, kept (cleared, but
     *                                                                                 Joomla did not uninstall it),
     *                                                                                 failed or refused.
     *
     * @throws RuntimeException When it may not start (403, 400, 409); nothing was done then.
     *
     * @since  2.1.0
     */
    public function removeWithData(User $user, array $ids, string $phrase): array
    {
        return $this->clearRows(user: $user, ids: $ids, phrase: $phrase, remove: true);
    }

    /**
     * Tools > Extensions > Remove extension only: Joomla uninstalls each selected row; its data is kept and its hook
     * never called. Never refused for the state of Clear everything.
     *
     * @param User $user Who asks.
     * @param array<int|string> $ids The selected extension ids.
     *
     * @return list<array{key: string, name: string, status: string, message: string}> One per selected row, in order:
     *                                                                                 removed, kept or refused.
     *
     * @throws RuntimeException When it may not start (403, 400); nothing was done then.
     *
     * @since  2.1.0
     */
    public function remove(User $user, array $ids): array
    {
        if (!self::mayRemove(user: $user)) {
            throw new RuntimeException(Text::_('COM_ALFA_EXTENSIONS_ERROR_REMOVE_NOT_PERMITTED'), 403);
        }

        $rows = array_column($this->rows(), null, 'extension_id');
        $results = [];

        foreach ($this->selected(ids: $ids) as $id) {
            $row = $rows[$id] ?? null;

            if ($row === null) {
                $results[] = $this->notListed(extensionId: $id);

                continue;
            }

            $name = $this->displayName(entry: $row);

            if ($row['locked']) {
                $results[] = ['key' => $row['key'], 'name' => $name, 'status' => 'refused', 'message' => Text::sprintf('COM_ALFA_EXTENSIONS_LOCKED', $name)];

                continue;
            }

            $removed = $this->uninstall(user: $user, row: $row);
            $results[] = ['key' => $row['key'], 'name' => $name, 'status' => $removed ? 'removed' : 'kept', 'message' => Text::sprintf($removed ? 'COM_ALFA_EXTENSIONS_REMOVED' : 'COM_ALFA_EXTENSIONS_NOT_REMOVED', $name)];
        }

        return $results;
    }

    /**
     * Whether a user may clear an extension's data: the Tools permission, and the permission to clear Alfa data with
     * entering the component ({@see FactoryReset::permitted()}).
     *
     * @param User $user The user.
     *
     * @since  2.1.0
     */
    public static function permitted(User $user): bool
    {
        return $user->authorise('alfa.tools', 'com_alfa') && FactoryReset::permitted(user: $user);
    }

    /**
     * Whether a user may remove an extension here: the Tools permission, and what Joomla's own uninstall asks of him
     * (Access Administration of the Extension Manager, to reach it, and its Delete, which its uninstall checks again).
     *
     * @param User $user The user.
     *
     * @since  2.1.0
     */
    public static function mayRemove(User $user): bool
    {
        return $user->authorise('alfa.tools', 'com_alfa')
            && $user->authorise('core.manage', 'com_installer')
            && $user->authorise('core.delete', 'com_installer');
    }

    /**
     * The extension Joomla is about to uninstall, when its uninstall is warned that its data stays: an Alfa plugin, or
     * an Alfa package with plugins, that Alfa Commerce does not bundle; a module, Alfa Commerce and another vendor's
     * extension never. A plugin uninstalled with its package is warned through the package (Joomla asks about the
     * package first, then uninstalls its extensions in the same request); a plugin of a package uninstalled on its
     * own (Joomla allows it unless the package's manifest blocks it) is warned itself.
     *
     * @param int $extensionId The extension's id.
     *
     * @return array<string, mixed>|null Its listing entry with its name as the site shows it.
     *
     * @since  2.1.0
     */
    public function uninstalling(int $extensionId): ?array
    {
        $listed = $this->separate();
        $entry = $listed[$extensionId] ?? null;

        if ($entry === null || !\in_array($entry['type'], ['plugin', 'package'], true)) {
            return null;
        }

        if ($entry['type'] === 'plugin' && $entry['package_id'] > 0 && isset(self::$packagesUninstalling[$entry['package_id']])) {
            return null;
        }

        if ($entry['type'] === 'package') {
            if (array_filter($listed, static fn (array $e): bool => $e['type'] === 'plugin' && $e['package_id'] === $extensionId) === []) {
                return null;
            }

            self::$packagesUninstalling[$extensionId] = true;
        }

        return ['name' => $this->displayName(entry: $entry)] + $entry;
    }

    /**
     * What a plugin says it clears and keeps, in one line, when it defines one: its own language string
     * {@see self::descriptionKey()}, from its administrator language. '' when it has none (nothing is shown).
     *
     * @param array<string, mixed> $entry A plugin entry.
     *
     * @since  2.1.0
     */
    public function description(array $entry): string
    {
        if (($entry['type'] ?? '') !== 'plugin') {
            return '';
        }

        $this->loadLanguage(entry: $entry);
        $key = self::descriptionKey(entry: $entry);
        $text = Text::_($key);

        return $text === $key ? '' : $text;
    }

    /**
     * The language string a plugin may define to describe its Clear Data: the prefix of its own strings (Alfa's
     * convention: PLG_, its group and its element, each "-" as "_") followed by _CLEAR_DATA_DESC. For example
     * PLG_ALFA_RETURNS_CLEAR_DATA_DESC.
     *
     * @param array<string, mixed> $entry A plugin entry: folder and element.
     *
     * @since  2.1.0
     */
    public static function descriptionKey(array $entry): string
    {
        return strtoupper(str_replace('-', '_', 'PLG_' . $entry['folder'] . '_' . $entry['element'])) . '_CLEAR_DATA_DESC';
    }

    /**
     * The extension's name as the site shows it, in the administrator's language.
     *
     * @param array<string, mixed> $entry A listing entry.
     *
     * @since  2.1.0
     */
    public function displayName(array $entry): string
    {
        $this->loadLanguage(entry: $entry);
        $name = trim((string) ($entry['name'] ?? ''));

        return $name === '' ? (string) $entry['key'] : Text::_($name);
    }

    /**
     * Clear Data, or Remove extension and its data, of the selected rows, under THE lock.
     *
     * @param User $user Who asks.
     * @param array<int|string> $ids The selected extension ids.
     * @param string $phrase What the person typed.
     * @param bool $remove Whether each row cleared is then uninstalled.
     *
     * @return list<array{key: string, name: string, status: string, message: string}>
     *
     * @throws RuntimeException When it may not start; nothing was done then.
     *
     * @since  2.1.0
     */
    private function clearRows(User $user, array $ids, string $phrase, bool $remove): array
    {
        if (!self::permitted(user: $user) || ($remove && !self::mayRemove(user: $user))) {
            throw new RuntimeException(Text::_($remove ? 'COM_ALFA_EXTENSIONS_ERROR_REMOVE_DATA_NOT_PERMITTED' : 'COM_ALFA_EXTENSIONS_ERROR_NOT_PERMITTED'), 403);
        }

        $selected = $this->selected(ids: $ids);

        if (trim($phrase) !== self::PHRASE) {
            throw new RuntimeException(Text::sprintf('COM_ALFA_CLEAR_ERROR_PHRASE', self::PHRASE), 400);
        }

        if (MaintenanceState::current() !== null) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_IN_MAINTENANCE'), 409);
        }

        if (!MaintenanceState::lock(db: $this->db)) {
            throw new RuntimeException(Text::_('COM_ALFA_EXTENSIONS_ERROR_BUSY'), 409);
        }

        try {
            $rows = array_column($this->rows(), null, 'extension_id');
            $results = [];

            foreach ($selected as $id) {
                $results[] = isset($rows[$id]) ? $this->clearRow(user: $user, row: $rows[$id], remove: $remove) : $this->notListed(extensionId: $id);
            }

            return $results;
        } finally {
            MaintenanceState::unlock(db: $this->db);
        }
    }

    /**
     * One row's Clear Data, recorded in the audit trail before it runs and after; then, for Remove extension and its
     * data, Joomla's uninstall when every plugin of the row cleared its data.
     *
     * @param User $user Who asks.
     * @param array<string, mixed> $row A row of {@see self::rows()}.
     * @param bool $remove Whether it is uninstalled after.
     *
     * @return array{key: string, name: string, status: string, message: string}
     *
     * @since  2.1.0
     */
    private function clearRow(User $user, array $row, bool $remove): array
    {
        $name = $this->displayName(entry: $row);
        $result = ['key' => (string) $row['key'], 'name' => $name];
        $support = $this->support(entry: $row);
        $refusal = match ($support['status']) {
            'remove_only' => Text::sprintf('COM_ALFA_EXTENSIONS_REFUSED_MODULE', $name),
            'unsupported' => Text::sprintf('COM_ALFA_EXTENSIONS_REFUSED_UNSUPPORTED', $name),
            'failed' => Text::sprintf('COM_ALFA_EXTENSIONS_REFUSED_NOT_LOADED', $name, $support['message']),
            default => null,
        };

        if ($refusal === null && $remove && $row['locked']) {
            $refusal = Text::sprintf('COM_ALFA_EXTENSIONS_LOCKED', $name);
        }

        if ($refusal !== null) {
            return $result + ['status' => 'refused', 'message' => $refusal];
        }

        $source = $remove ? ClearDataEvent::REMOVE_WITH_DATA : ClearDataEvent::CLEAR_DATA;
        $run = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));

        // The audit record first: no record, no clear.
        try {
            MaintenanceAudit::record(
                languageKey: 'COM_ALFA_ACTIONLOG_EXTENSION_CLEAR',
                data: ['run' => $run, 'extension' => $name, 'key' => (string) $row['key'], 'action' => $source],
                user: $user,
                line: sprintf('Clear Data of %s (%s) started: %s.', $name, $row['key'], $source),
            );
        } catch (Throwable $e) {
            return $result + ['status' => 'refused', 'message' => Text::sprintf('COM_ALFA_EXTENSIONS_AUDIT_FAILED', $name, ClearDataStatus::clean(text: $e->getMessage()))];
        }

        $plugins = $row['type'] === 'package'
            ? array_values(array_filter((array) $row['children'], static fn (array $child): bool => $child['type'] === 'plugin'))
            : [$row];
        $said = [];
        $failures = [];

        foreach ($plugins as $plugin) {
            $done = $this->clearPlugin(plugin: $plugin, source: $source, run: $run);

            if ($done['status'] === 'cleared') {
                if ($done['message'] !== '') {
                    $said[] = $done['message'];
                }

                continue;
            }

            $failures[] = ($row['type'] === 'package' ? $this->displayName(entry: $plugin) . ': ' : '') . $done['message'];
        }

        $cleared = $failures === [];

        try {
            MaintenanceAudit::record(
                languageKey: 'COM_ALFA_ACTIONLOG_EXTENSION_CLEARED',
                data: ['run' => $run, 'extension' => $name, 'key' => (string) $row['key'], 'outcome' => $cleared ? 'cleared' : 'failed: ' . implode(' ', $failures)],
                user: $user,
                line: sprintf('Clear Data of %s (%s): %s', $name, $row['key'], $cleared ? 'cleared. ' . implode(' ', $said) : 'did not complete: ' . implode(' ', $failures)),
            );
        } catch (Throwable) {
            // The record that opened it is written; the outcome is on the page and in the status record.
        }

        if (!$cleared) {
            return $result + ['status' => 'failed', 'message' => Text::sprintf($remove ? 'COM_ALFA_EXTENSIONS_REMOVE_FAILED' : 'COM_ALFA_EXTENSIONS_CLEAR_FAILED', $name, implode(' ', $failures))];
        }

        if (!$remove) {
            return $result + ['status' => 'cleared', 'message' => trim(Text::sprintf('COM_ALFA_EXTENSIONS_CLEARED', $name, implode(' ', $said)))];
        }

        // Its data is gone: its uninstall is not warned that data stays.
        AlfaInstallerGate::clearedBeforeRemoval(keys: [(string) $row['key'], ...array_map(static fn (array $child): string => (string) $child['key'], (array) $row['children'])]);
        $removed = $this->uninstall(user: $user, row: $row);

        return $result + ($removed
            ? ['status' => 'removed', 'message' => Text::sprintf('COM_ALFA_EXTENSIONS_REMOVED_WITH_DATA', $name)]
            : ['status' => 'kept', 'message' => Text::sprintf('COM_ALFA_EXTENSIONS_NOT_REMOVED_AFTER_CLEAR', $name)]);
    }

    /**
     * Joomla's own uninstall of one row, through com_installer's Manage model: its permission, its locked check, its
     * uninstall (the extension's own script runs, a package's extensions with it) and its messages, which it puts in
     * the message queue itself.
     *
     * @param User $user Who asks.
     * @param array<string, mixed> $row A row of {@see self::rows()}.
     *
     * @return bool Whether the extension is gone.
     *
     * @since  2.1.0
     */
    private function uninstall(User $user, array $row): bool
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);
        $model = $app->bootComponent('com_installer')->getMVCFactory()->createModel('Manage', 'Administrator', ['ignore_request' => true]);

        if ($model instanceof CurrentUserInterface) {
            $model->setCurrentUser($user);
        }

        try {
            $model->remove([(string) (int) $row['extension_id']]);
        } catch (Throwable $e) {
            $this->logFailure(run: 'remove', entry: $row, error: $e);
        }

        return $this->freshRow(extensionId: (int) $row['extension_id']) === null;
    }

    /**
     * The installed Alfa extensions but Alfa Commerce and what it bundles, by id.
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.1.0
     */
    private function separate(): array
    {
        $bundled = [];

        foreach (SchemaRecreator::bundled() as $extension) {
            $bundled[AlfaInstallerGate::key(type: $extension['type'], element: $extension['element'], folder: $extension['folder'])] = true;
        }

        $listed = [];

        foreach (self::installed(db: $this->db) as $entry) {
            if (($entry['type'] === 'component' && $entry['element'] === 'com_alfa') || isset($bundled[$entry['key']])) {
                continue;
            }

            $listed[$entry['extension_id']] = $entry;
        }

        return $listed;
    }

    /**
     * The selected ids, each once, in the order given.
     *
     * @param array<int|string> $ids The ids.
     *
     * @return list<int>
     *
     * @throws RuntimeException When none is selected (400).
     *
     * @since  2.1.0
     */
    private function selected(array $ids): array
    {
        $selected = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($selected === []) {
            throw new RuntimeException(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 400);
        }

        return $selected;
    }

    /**
     * The answer for an id this screen does not list (Alfa Commerce itself, what it bundles, another vendor's).
     *
     * @param int $extensionId The id.
     *
     * @return array{key: string, name: string, status: string, message: string}
     *
     * @since  2.1.0
     */
    private function notListed(int $extensionId): array
    {
        $row = $this->freshRow(extensionId: $extensionId);

        return [
            'key' => $row === null ? '#' . $extensionId : AlfaInstallerGate::key(type: $row['type'], element: $row['element'], folder: $row['type'] === 'plugin' ? $row['folder'] : ''),
            'name' => $row === null ? '#' . $extensionId : (string) $row['name'],
            'status' => 'refused',
            'message' => Text::sprintf('COM_ALFA_EXTENSIONS_REFUSED_NOT_LISTED', $extensionId),
        ];
    }

    /**
     * An extension's row as it is now, or null when it is not installed any more.
     *
     * @param int $extensionId The id.
     *
     * @return array{extension_id: int, type: string, element: string, folder: string, client_id: int, name: string, params: string}|null
     *
     * @since  2.1.0
     */
    private function freshRow(int $extensionId): ?array
    {
        $db = $this->db;
        $row = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id', 'name', 'params']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = ' . $extensionId),
        )->loadAssoc();

        if (!\is_array($row)) {
            return null;
        }

        return [
            'extension_id' => (int) $row['extension_id'],
            'type' => (string) $row['type'],
            'element' => (string) $row['element'],
            'folder' => (string) $row['folder'],
            'client_id' => (int) $row['client_id'],
            'name' => (string) $row['name'],
            'params' => (string) $row['params'],
        ] + ['key' => AlfaInstallerGate::key(type: (string) $row['type'], element: (string) $row['element'], folder: (string) $row['folder'])];
    }

    /**
     * Boot a plugin through Joomla, enabled or not. Booting subscribes it to nothing. A plugin whose code threw while it
     * loaded throws the same again for the rest of the request ({@see self::$unloadable}).
     *
     * @param array<string, mixed> $entry A plugin entry.
     *
     * @throws Throwable What its code threw while it loaded.
     *
     * @since  2.1.0
     */
    private function boot(array $entry): object
    {
        $key = (string) $entry['key'];

        if (isset(self::$unloadable[$key])) {
            throw self::$unloadable[$key];
        }

        try {
            return Factory::getApplication()->bootPlugin((string) $entry['element'], (string) $entry['folder']);
        } catch (Throwable $e) {
            self::$unloadable[$key] = $e;

            throw $e;
        }
    }

    /**
     * Give a plugin the name, group and params Joomla gives an enabled one. A plugin's service provider builds it
     * from PluginHelper::getPlugin(), which knows enabled plugins only, so a disabled one has none; CMSPlugin keeps
     * the name and group protected and has no setter, so they are set from the plugin's own scope.
     *
     * @param object $plugin The booted plugin.
     * @param array<string, mixed> $row Its row ({@see self::freshRow()}).
     *
     * @since  2.1.0
     */
    private function adopt(object $plugin, array $row): void
    {
        if (!$plugin instanceof CMSPlugin) {
            return;
        }

        $identity = function (string $element, string $folder, string $params): void {
            if ((string) $this->_name === '') {
                $this->_name = $element;
            }

            if ((string) $this->_type === '') {
                $this->_type = $folder;
            }

            if (!$this->params instanceof Registry) {
                $this->params = new Registry($params === '' ? '{}' : $params);
            }
        };

        Closure::bind($identity, $plugin, CMSPlugin::class)((string) $row['element'], (string) $row['folder'], (string) $row['params']);
    }

    /**
     * Load an extension's administrator language and its system file, which holds its name.
     *
     * @param array<string, mixed> $entry A listing entry.
     *
     * @since  2.1.0
     */
    private function loadLanguage(array $entry): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();
            [$base, $folder] = match ((string) $entry['type']) {
                'plugin' => [JPATH_ADMINISTRATOR, JPATH_PLUGINS . '/' . $entry['folder'] . '/' . $entry['element']],
                'module' => (int) ($entry['client_id'] ?? 0) === 1
                    ? [JPATH_ADMINISTRATOR, JPATH_ADMINISTRATOR . '/modules/' . $entry['element']]
                    : [JPATH_SITE, JPATH_SITE . '/modules/' . $entry['element']],
                default => [JPATH_ADMINISTRATOR, JPATH_ADMINISTRATOR],
            };

            foreach ([$entry['key'] . '.sys', (string) $entry['key']] as $file) {
                $language->load($file, $base) || $language->load($file, $folder);
            }
        } catch (Throwable) {
            // Untranslated texts are shown by their keys.
        }
    }

    /**
     * Record an outcome, cleaned and bounded, and return it.
     *
     * @param string $key The extension's key.
     * @param string $status cleared, failed, unsupported or unavailable.
     * @param string $message Its words, or core's.
     * @param string $source The action.
     *
     * @return array{status: string, message: string}
     *
     * @since  2.1.0
     */
    private function outcome(string $key, string $status, string $message, string $source): array
    {
        $message = ClearDataStatus::clean(text: $message);

        try {
            ClearDataStatus::mark(key: $key, status: $status, message: $message, source: $source);
        } catch (Throwable $e) {
            MaintenanceAudit::detail(run: 'clear-data', line: sprintf('%s: its outcome (%s) could not be recorded: %s', $key, $status, $e->getMessage()));
        }

        return ['status' => $status, 'message' => $message];
    }

    /**
     * Log what an extension threw, with its class, file and line, in the maintenance log; the page says only that it
     * stopped with an unexpected error.
     *
     * @param string $run The action's or the run's id.
     * @param array<string, mixed> $entry The extension.
     * @param Throwable $error What it threw.
     *
     * @since  2.1.0
     */
    private function logFailure(string $run, array $entry, Throwable $error): void
    {
        MaintenanceAudit::detail(run: $run, line: sprintf(
            '%s (%s): Clear Data stopped with %s: %s (%s:%d)',
            $this->displayName(entry: $entry),
            (string) ($entry['key'] ?? ''),
            $error::class,
            ClearDataStatus::clean(text: $error->getMessage()),
            $error->getFile(),
            $error->getLine(),
        ));
    }

    /**
     * The id Options > About shows: group/element of a plugin, the element otherwise.
     *
     * @param array<string, mixed> $entry A listing entry.
     *
     * @since  2.1.0
     */
    private static function aboutId(array $entry): string
    {
        return $entry['type'] === 'plugin' ? $entry['folder'] . '/' . $entry['element'] : (string) $entry['element'];
    }

    /**
     * The order of the screen: by type ({@see self::ROW_ORDER}), then group, element and client.
     *
     * @param array<string, mixed> $a An entry.
     * @param array<string, mixed> $b Another.
     *
     * @since  2.1.0
     */
    private static function byRowOrder(array $a, array $b): int
    {
        return [self::ROW_ORDER[$a['type']] ?? 9, $a['folder'], $a['element'], $a['client_id']]
            <=> [self::ROW_ORDER[$b['type']] ?? 9, $b['folder'], $b['element'], $b['client_id']];
    }
}
