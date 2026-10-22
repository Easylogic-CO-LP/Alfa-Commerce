<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * The one installer gate of every Alfa extension, Alfa Commerce's own included: the minimum Alfa Commerce version an
 * extension needs, in one wording; and the warning, in one wording, that uninstalling an Alfa extension keeps its data.
 *
 * NO INSTALLER LOCKING. Nothing is ever refused because Clear everything is running, paused or did not finish: an
 * administrator may install, update or uninstall any extension through Joomla at any time. Clear everything records an
 * extension that disappears under it as unavailable and goes on.
 *
 * INSTALLER-SAFE. It depends on Joomla's database connection, log and texts and on {@see AlfaVersion} only (loaded
 * from beside this file when it is not loaded yet), never on another class of the component: it runs from a package
 * being installed and while the component's data may be half made.
 *
 * AN EXTENSION'S PREFLIGHT, exactly (the warning on uninstall is given by Alfa Commerce for every Alfa extension):
 *
 *     public const MIN_ALFA_VERSION = '2.1.0';
 *
 *     public function preflight($type, $parent): bool
 *     {
 *         $gate = \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate::class;
 *         $file = JPATH_ROOT . '/administrator/components/com_alfa/src/Service/Setup/AlfaInstallerGate.php';
 *
 *         if (!class_exists($gate) && is_file($file)) {
 *             require_once $file;
 *         }
 *
 *         if ($type === 'uninstall') {
 *             return true;
 *         }
 *
 *         if (!class_exists($gate)) {
 *             // No Alfa Commerce, or one older than 2.1.0.
 *             Factory::getApplication()->enqueueMessage(sprintf('<Name> requires Alfa Commerce %1$s or later,'
 *                 . ' and this site has no Alfa Commerce %1$s or later installed. Install or update Alfa'
 *                 . ' Commerce first, then install <Name> again. Nothing was changed.', self::MIN_ALFA_VERSION), 'error');
 *
 *             return false;
 *         }
 *
 *         $refusal = $gate::refusal(name: '<Name>', route: (string) $type, minimum: self::MIN_ALFA_VERSION);
 *
 *         if ($refusal !== null) {
 *             Factory::getApplication()->enqueueMessage($refusal, 'error');
 *
 *             return false;
 *         }
 *
 *         return true;
 *     }
 *
 * The refusal is plain English: no language file exists yet at an install's preflight.
 *
 * @since  2.1.0
 */
final class AlfaInstallerGate
{
    /**
     * This file, relative to the site root: where another extension's installer loads it from when
     * Joomla's namespace map does not know the component.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const FILE = 'administrator/components/com_alfa/src/Service/Setup/AlfaInstallerGate.php';

    /**
     * The table of the durable state: never dropped by Clear everything, kept by an uninstall.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const TABLE = '#__alfa_maintenance';

    /**
     * The refusal of an extension that needs a newer Alfa Commerce. %1$s: the extension's name; %2$s:
     * the minimum; %3$s: {@see self::VERSION_FOUND} or {@see self::VERSION_MISSING}.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const VERSION = '%1$s requires Alfa Commerce %2$s or later, and %3$s. Install or update Alfa Commerce first,'
        . ' then install %1$s again. Nothing was changed.';

    /**
     * What {@see self::VERSION} says of an older Alfa Commerce. %s: its version.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const VERSION_FOUND = 'this site has Alfa Commerce %s';

    /**
     * What {@see self::VERSION} says when no Alfa Commerce is installed. %s: the minimum.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const VERSION_MISSING = 'this site has no Alfa Commerce %s or later installed';

    /**
     * The warning of an uninstall: the extension's data stays. %1$s: the extension's name. The language
     * file holds the same text (COM_ALFA_EXTENSIONS_UNINSTALL_LEFTOVERS), used when it is loaded.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const LEFTOVERS = 'Uninstalling %1$s keeps its data: database rows and files it owns may remain. Complete cleanup'
        . ' is done before uninstalling, in Alfa Commerce > Tools > Extensions > Clear Data. Once the extension is removed,'
        . ' removing its data may require installing it again and running Clear Data, manual database and file cleanup,'
        . ' or the help of the extension\'s developer.';

    /**
     * The routes that install code, where a minimum version is checked.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    private const INSTALL_ROUTES = ['install', 'update', 'discover_install'];

    /**
     * The maintenance log: Joomla's Log category and its file.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    private const LOG = ['category' => 'com_alfa.maintenance', 'file' => 'com_alfa_maintenance.php'];

    /**
     * The keys warned about in this request, and the keys whose data this request cleared just before their
     * uninstall. Request memory only: nothing is stored.
     *
     * @var array<string, bool>
     *
     * @since  2.1.0
     */
    private static array $quiet = [];

    /**
     * The stored phase (`running`, `paused` or `failed`), or null when Clear everything is idle or the
     * state table does not exist. A plain query, for com_alfa's own installer, which steps over its
     * data steps while core's schema may be half made. A state that cannot be decoded reads as
     * `failed`.
     *
     * @throws Throwable When the state cannot be read.
     *
     * @since  2.1.0
     */
    public static function phase(): ?string
    {
        $stored = self::stored();

        return $stored === null ? null : self::phaseOf(state: $stored['state']);
    }

    /**
     * Why an installer must refuse now, or null when it may go on: on the routes that install code, an
     * Alfa Commerce that is missing or older than the extension's minimum. Never for the state of Clear
     * everything.
     *
     * @param string $name The extension's name, as the messages show it.
     * @param string $route Joomla's route: install, update, discover_install or uninstall.
     * @param string|null $minimum The extension's MIN_ALFA_VERSION; null for com_alfa itself.
     *
     * @since  2.1.0
     */
    public static function refusal(string $name, string $route, ?string $minimum = null): ?string
    {
        if ($minimum === null || !\in_array($route, self::INSTALL_ROUTES, true)) {
            return null;
        }

        self::loadVersionReader();
        $installed = AlfaVersion::installed();

        if ($installed !== null && $installed->atLeast(minimum: $minimum)) {
            return null;
        }

        return sprintf(
            self::VERSION,
            $name,
            $minimum,
            $installed === null ? sprintf(self::VERSION_MISSING, $minimum) : sprintf(self::VERSION_FOUND, $installed->toString()),
        );
    }

    /**
     * The warning of an uninstall of an Alfa extension: its data stays. Also written to the maintenance log
     * (com_alfa_maintenance.php). Null when it was said for this extension already in this request, or when this
     * request cleared its data just before ({@see self::clearedBeforeRemoval()}). Not a refusal: the uninstall
     * goes on and keeps the data.
     *
     * @param string $name The extension's name, as the message shows it.
     * @param string $key The extension's key ({@see self::key()}).
     *
     * @since  2.1.0
     */
    public static function uninstallNotice(string $name, string $key): ?string
    {
        if (isset(self::$quiet[$key])) {
            return null;
        }

        self::$quiet[$key] = true;
        $notice = Text::_('COM_ALFA_EXTENSIONS_UNINSTALL_LEFTOVERS') === 'COM_ALFA_EXTENSIONS_UNINSTALL_LEFTOVERS'
            ? sprintf(self::LEFTOVERS, $name)
            : Text::sprintf('COM_ALFA_EXTENSIONS_UNINSTALL_LEFTOVERS', $name);

        try {
            Log::addLogger(['text_file' => self::LOG['file']], Log::ALL, [self::LOG['category']]);
            Log::add($notice . ' (' . $key . ')', Log::WARNING, self::LOG['category']);
        } catch (Throwable) {
            // The page says it; the log is the second copy.
        }

        return $notice;
    }

    /**
     * CORE ONLY, just before Tools > Extensions hands an extension to Joomla's uninstall in Remove extension and its
     * data: its Clear Data succeeded in this request, so its uninstall is not warned that data stays.
     *
     * @param string[] $keys The extensions' keys (a package's and its plugins').
     *
     * @since  2.1.0
     */
    public static function clearedBeforeRemoval(array $keys): void
    {
        foreach ($keys as $key) {
            self::$quiet[(string) $key] = true;
        }
    }

    /**
     * The lock Clear everything holds while it works, and Tools > Extensions while it clears an extension's
     * data: one per database and table prefix, within the 64 characters a lock name may have.
     *
     * @since  2.1.0
     */
    public static function lockName(): string
    {
        $db = self::db();

        return 'alfa_clear_' . substr(md5((string) $db->setQuery('SELECT DATABASE()')->loadResult() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * The key of an extension: the name Joomla gives it (`plg_<folder>_<element>` for a plugin, the
     * element for a module, a package or a component). The key its Clear Data is recorded under, and
     * the one {@see self::uninstallNotice()} is asked with.
     *
     * @param string $type plugin, module, package or component.
     * @param string $element The extension's element.
     * @param string $folder A plugin's folder (group).
     *
     * @since  2.1.0
     */
    public static function key(string $type, string $element, string $folder = ''): string
    {
        return $type === 'plugin' ? 'plg_' . $folder . '_' . $element : $element;
    }

    /**
     * The durable state as stored: the row of {@see self::TABLE}, or null when the table does not
     * exist. Core's maintenance state and Clear data records read it through here, so the runtime and
     * every installer read it the same way.
     *
     * @param DatabaseInterface|null $db The connection; the container's by default.
     *
     * @return array{state: string|null, last_clear: string|null, clear_status: string|null, seq: int}|null
     *
     * @throws Throwable When the table exists and cannot be read.
     *
     * @since  2.1.0
     */
    public static function stored(?DatabaseInterface $db = null): ?array
    {
        $db ??= self::db();

        try {
            $row = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName(['state', 'last_clear', 'clear_status', 'seq']))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('id') . ' = 1'),
            )->loadAssoc();
        } catch (Throwable $e) {
            if (!self::tableExists(db: $db)) {
                return null;
            }

            throw $e;
        }

        if (!\is_array($row)) {
            return ['state' => null, 'last_clear' => null, 'clear_status' => null, 'seq' => 0];
        }

        return [
            'state' => self::text(value: $row['state'] ?? null),
            'last_clear' => self::text(value: $row['last_clear'] ?? null),
            'clear_status' => self::text(value: $row['clear_status'] ?? null),
            'seq' => (int) ($row['seq'] ?? 0),
        ];
    }

    /**
     * The phase a stored state names.
     *
     * @param string|null $state The stored state.
     *
     * @since  2.1.0
     */
    private static function phaseOf(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $decoded = json_decode($state, true);
        $phase = \is_array($decoded) ? ($decoded['phase'] ?? null) : null;

        return \in_array($phase, ['running', 'paused', 'failed'], true) ? $phase : 'failed';
    }

    /**
     * A stored text, or null when there is none.
     *
     * @param mixed $value The column's value.
     *
     * @since  2.1.0
     */
    private static function text(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    /**
     * Whether the state table exists.
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.1.0
     */
    private static function tableExists(DatabaseInterface $db): bool
    {
        return \in_array(strtolower($db->replacePrefix(self::TABLE)), array_map('strtolower', $db->getTableList()), true);
    }

    /**
     * Load the version reader from beside this file when it is not loaded yet: the package's copy when
     * the gate came from a package being installed, the installed one otherwise.
     *
     * @since  2.1.0
     */
    private static function loadVersionReader(): void
    {
        if (!class_exists(AlfaVersion::class, false) && is_file(__DIR__ . '/AlfaVersion.php')) {
            require_once __DIR__ . '/AlfaVersion.php';
        }
    }

    /**
     * Joomla's connection.
     *
     * @since  2.1.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
