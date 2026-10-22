<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Create core's tables again the way a fresh install creates them: the install SQL of the installed
 * component, then the install SQL of every extension the installed component manifest bundles
 * (alfa.xml `<plugins>` and `<modules>`) that is installed, each run through Joomla's own SQL runner
 * (Installer::parseSQLFiles(), the runner the install route and the installer script's completion of
 * an unfinished install use), from the files that are installed. There is no second, hand-written
 * schema.
 *
 * Core's only: an extension the component does not bundle makes and clears its own tables
 * (the Clear Data contract), and its install SQL is never run here. A bundled extension that is not
 * installed gets no table.
 *
 * READY OR NOT. Every planned manifest and install SQL file must be readable before Clear everything
 * changes anything ({@see self::plan()}: an entry that cannot run refuses the run); a statement the
 * database refuses while it runs fails the step with the database's answer.
 *
 * @since  2.1.0
 */
final class SchemaRecreator
{
    /**
     * The installed component manifest, relative to the administrator folder.
     *
     * @var string
     *
     * @since  2.1.0
     */
    private const COMPONENT_MANIFEST = 'components/com_alfa/alfa.xml';

    /**
     * Messages Joomla's runner logged while a file ran (it says why a statement was refused there).
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    private static array $runnerMessages = [];

    /**
     * Whether the runner's messages are being collected.
     *
     *
     * @since  2.1.0
     */
    private static bool $listening = false;

    /**
     * @param DatabaseInterface $db The site's connection.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The extensions the installed component manifest bundles, as it lists them: plugins by group and
     * element, modules by element. Empty when the manifest cannot be read.
     *
     * @return list<array{type: string, folder: string, element: string}>
     *
     * @since  2.1.0
     */
    public static function bundled(): array
    {
        $file = JPATH_ADMINISTRATOR . '/' . self::COMPONENT_MANIFEST;
        $xml = is_file($file) ? @simplexml_load_file($file) : false;

        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $bundled = [];

        foreach ($xml->plugins->plugin ?? [] as $plugin) {
            $bundled[] = ['type' => 'plugin', 'folder' => (string) $plugin['group'], 'element' => (string) $plugin['plugin']];
        }

        foreach ($xml->modules->module ?? [] as $module) {
            $bundled[] = ['type' => 'module', 'folder' => '', 'element' => (string) $module['module']];
        }

        return array_values(array_filter($bundled, static fn (array $entry): bool => $entry['element'] !== ''));
    }

    /**
     * The installed component and the installed bundled extensions that ship install SQL, in the
     * order they are run (the component first), each with its install SQL files and whether it can
     * run; when not, why. An extension whose manifest cannot be read is listed, never passed over.
     *
     * @return list<array{extension: string, root: string, manifest: string, files: list<string>, run: bool, reason: string}>
     *
     * @since  2.1.0
     */
    public function plan(): array
    {
        $plan = [];

        foreach ($this->installed() as $extension) {
            $xml = is_file($extension['manifest']) ? @simplexml_load_file($extension['manifest']) : false;

            if (!$xml instanceof SimpleXMLElement) {
                $plan[] = $extension + ['files' => [], 'run' => false, 'reason' => 'its manifest ' . $extension['manifest'] . ' cannot be read'];

                continue;
            }

            if (!isset($xml->install->sql) || !\count($xml->install->sql->children())) {
                continue;
            }

            $files = [];
            $missing = [];

            foreach ($xml->install->sql->children() as $file) {
                $path = $extension['root'] . '/' . trim((string) $file);

                if (is_file($path) && is_readable($path)) {
                    $files[] = $path;
                } else {
                    $missing[] = $path;
                }
            }

            $plan[] = $extension + [
                'files' => $files,
                'run' => $missing === [],
                'reason' => $missing === [] ? '' : 'its install SQL cannot be read: ' . implode(', ', $missing),
            ];
        }

        return $plan;
    }

    /**
     * Why core's schema could not be made again now, or null when it can: the first planned entry
     * that cannot run.
     *
     * @since  2.1.0
     */
    public function unready(): ?string
    {
        foreach ($this->plan() as $entry) {
            if (!$entry['run']) {
                return $entry['extension'] . ': ' . $entry['reason'];
            }
        }

        return null;
    }

    /**
     * Run the install SQL of every planned extension, the component first.
     *
     * @return list<string> The extensions whose install SQL ran.
     *
     * @throws RuntimeException When an entry cannot run, or a statement is refused: the message names
     *                          the extension and the reason or the database's answer.
     *
     * @since  2.1.0
     */
    public function run(): array
    {
        $ran = [];

        foreach ($this->plan() as $entry) {
            if (!$entry['run']) {
                throw new RuntimeException($entry['extension'] . ': ' . $entry['reason']);
            }

            $this->runInstallSql(extension: $entry['extension'], manifest: $entry['manifest'], root: $entry['root']);
            $ran[] = $entry['extension'];
        }

        return $ran;
    }

    /**
     * The tables an install SQL file creates (its CREATE TABLE statements), with the #__ prefix as
     * written.
     *
     * @param string $sql The file's content.
     *
     * @return list<string>
     *
     * @since  2.1.0
     */
    public static function createdTables(string $sql): array
    {
        $tables = [];

        foreach (Installer::splitSql($sql) as $statement) {
            if (preg_match('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([^`\s(]+)`?/i', $statement, $match)) {
                $tables[] = $match[1];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Run one extension's install SQL through Joomla's runner, from its installed folder.
     *
     * @param string $extension The extension, for the message.
     * @param string $manifest Its installed manifest.
     * @param string $root The folder its install SQL paths are relative to.
     *
     * @throws RuntimeException When a statement is refused.
     *
     * @since  2.1.0
     */
    private function runInstallSql(string $extension, string $manifest, string $root): void
    {
        $xml = simplexml_load_file($manifest);
        $installer = new Installer();
        $installer->setDatabase($this->db);
        $installer->setPath('extension_root', $root);

        self::listen();
        self::$runnerMessages = [];
        self::$listening = true;

        try {
            $result = $installer->parseSQLFiles($xml->install->sql);
        } catch (Throwable $e) {
            // A statement the database refused to prepare.
            throw new RuntimeException($extension . ': ' . $e->getMessage(), 0, $e);
        } finally {
            self::$listening = false;
        }

        if ($result === false) {
            throw new RuntimeException($extension . ': ' . (implode(' ', self::$runnerMessages) ?: 'the database refused a statement of its install SQL'));
        }
    }

    /**
     * The installed component and the bundled extensions that are installed (recorded by Joomla,
     * enabled or not), each with its manifest and the folder its install SQL is read from. A module is
     * looked for in the folder of its client.
     *
     * @return list<array{extension: string, root: string, manifest: string}>
     *
     * @since  2.1.0
     */
    private function installed(): array
    {
        $db = $this->db;
        $root = JPATH_ADMINISTRATOR . '/components/com_alfa';
        $found = [['extension' => 'com_alfa', 'root' => $root, 'manifest' => $root . '/alfa.xml']];
        $rows = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName(['type', 'folder', 'element', 'client_id']))
                ->from($db->quoteName('#__extensions'))
                ->whereIn($db->quoteName('type'), ['plugin', 'module'], ParameterType::STRING)
                ->where($db->quoteName('state') . ' >= 0')
                ->order([$db->quoteName('type') . ' ASC', $db->quoteName('folder') . ' ASC', $db->quoteName('element') . ' ASC', $db->quoteName('client_id') . ' ASC']),
        )->loadObjectList() ?: [];
        $installed = [];

        foreach ($rows as $row) {
            $installed[$row->type . '/' . ($row->type === 'plugin' ? $row->folder . '/' : '') . $row->element][] = (int) $row->client_id;
        }

        foreach (self::bundled() as $extension) {
            $key = $extension['type'] . '/' . ($extension['type'] === 'plugin' ? $extension['folder'] . '/' : '') . $extension['element'];

            foreach ($installed[$key] ?? [] as $clientId) {
                if ($extension['type'] === 'plugin') {
                    $folder = JPATH_PLUGINS . '/' . $extension['folder'] . '/' . $extension['element'];
                    $found[] = ['extension' => 'plg_' . $extension['folder'] . '_' . $extension['element'], 'root' => $folder, 'manifest' => $folder . '/' . $extension['element'] . '.xml'];
                } else {
                    $folder = ($clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/modules/' . $extension['element'];
                    $found[] = ['extension' => $extension['element'], 'root' => $folder, 'manifest' => $folder . '/' . $extension['element'] . '.xml'];
                }
            }
        }

        return $found;
    }

    /**
     * Collect what Joomla's runner logs in the `jerror` category while a file runs. Registered
     * once per request; it keeps nothing when no file is running.
     *
     * @since  2.1.0
     */
    private static function listen(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        Log::addLogger(
            ['logger' => 'callback', 'callback' => static function (LogEntry $entry): void {
                if (self::$listening) {
                    self::$runnerMessages[] = (string) $entry->message;
                }
            }],
            Log::ALL,
            ['jerror'],
        );
    }
}
