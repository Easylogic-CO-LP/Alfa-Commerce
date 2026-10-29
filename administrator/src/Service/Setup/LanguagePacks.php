<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use SimpleXMLElement;
use Throwable;

/**
 * The Alfa Commerce language packs of the site.
 *
 * A language pack is a Joomla package named pkg_alfacommerce_<tag>, whose manifest declares that language in its
 * languagepack element. It translates Alfa Commerce and cannot work without it. Core does what the pack's own script
 * does not: after Joomla installs or updates a pack, the language sync gives the seeded records their names in the
 * pack's language ({@see self::afterInstall()}), and when Alfa Commerce is uninstalled, every pack is uninstalled with
 * it through Joomla's installer, and any pack left whole or in part is named ({@see self::uninstallAll()}). Joomla's own language packages (pkg_<tag>) and every
 * other extension never match.
 *
 * @since  2.1.4
 */
final class LanguagePacks
{
    /**
     * The element of every language pack, up to its language tag.
     *
     * @var string
     * @since  2.1.4
     */
    public const PACKAGE_PREFIX = 'pkg_alfacommerce_';

    /**
     * The language tag of the pack a package is, or null when it is not an Alfa language pack: its element is
     * {@see self::PACKAGE_PREFIX} followed by a tag in Joomla's form (xx-XX or xxx-XX), and its manifest is a package's
     * that declares the same tag in its languagepack element.
     *
     * @param string $element The package's element.
     * @param SimpleXMLElement|null $manifest The package's manifest.
     *
     * @return string|null The tag, such as "el-GR".
     *
     * @since  2.1.4
     */
    public static function tagOf(string $element, ?SimpleXMLElement $manifest): ?string
    {
        $tag = substr($element, \strlen(self::PACKAGE_PREFIX));

        if (
            $manifest === null
            || !str_starts_with($element, self::PACKAGE_PREFIX)
            || preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) !== 1
            || (string) $manifest['type'] !== 'package'
            || (string) $manifest->languagepack['tag'] !== $tag
        ) {
            return null;
        }

        return $tag;
    }

    /**
     * Every Alfa language pack installed, read from Joomla's records and the packages' installed manifests.
     *
     * @return array<int, array{element: string, tag: string}> Extension id => the pack.
     *
     * @since  2.1.4
     */
    public static function installed(): array
    {
        $packs = [];

        foreach (self::packageRows() as $row) {
            $file = JPATH_MANIFESTS . '/packages/' . $row['element'] . '.xml';
            $manifest = is_file($file) ? @simplexml_load_file($file) : false;
            $tag = self::tagOf(element: (string) $row['element'], manifest: $manifest === false ? null : $manifest);

            if ($tag !== null) {
                $packs[(int) $row['extension_id']] = ['element' => (string) $row['element'], 'tag' => $tag];
            }
        }

        return $packs;
    }

    /**
     * After Joomla installed or updated an extension: when it is an Alfa language pack, the language sync names the
     * seeded records in its language, except in maintenance, where Clear everything makes the language tables itself;
     * then the administrator is told when Joomla's own language of that tag is not installed. A failure is logged and
     * never fails the installation.
     *
     * @param int $extensionId The extension Joomla installed or updated (0 when it failed).
     * @param SimpleXMLElement|null $manifest Its manifest, as the installer read it.
     *
     * @since  2.1.4
     */
    public static function afterInstall(int $extensionId, ?SimpleXMLElement $manifest): void
    {
        // Only a package can be a pack: any other extension costs no query.
        if ($extensionId < 1 || $manifest === null || (string) $manifest['type'] !== 'package') {
            return;
        }

        $db = self::db();
        $element = (string) $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = :id')
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                ->bind(':id', $extensionId, ParameterType::INTEGER),
        )->loadResult();
        $tag = self::tagOf(element: $element, manifest: $manifest);

        if ($tag === null) {
            return;
        }

        try {
            $errors = MaintenanceState::active() ? [] : SyncHelper::syncLanguageSchema()['errors'];
        } catch (Throwable $e) {
            $errors = ['sync' => $e->getMessage()];
        }

        if ($errors !== []) {
            self::log(message: $element . ': the language sync after its installation did not complete: ' . json_encode($errors));
        }

        try {
            $missing = self::missingJoomlaLanguage(db: $db, tag: $tag);

            if ($missing !== []) {
                $app = Factory::getApplication();
                $app->getLanguage()->load('com_alfa.sys', JPATH_ADMINISTRATOR);
                $app->enqueueMessage(Text::sprintf('COM_ALFA_LANGUAGE_PACK_NO_JOOMLA_LANGUAGE', $tag, implode(', ', $missing)), 'warning');
            }
        } catch (Throwable $e) {
            self::log(message: $element . ': Joomla\'s own ' . $tag . ' language could not be checked: ' . $e->getMessage());
        }
    }

    /**
     * Uninstall every Alfa language pack through Joomla's installer, as Extensions: Manage does: Joomla removes each
     * package with its file extensions and every file they list. Nothing else is touched.
     *
     * A pack counts as uninstalled only when Joomla removed its rows and no file its file extensions list is left:
     * Joomla also removes the rows when a language folder cannot be written, or when a file extension lost its
     * installed manifest, and the files stay. So the files are read from the file extensions' manifests first and
     * looked for afterwards: a pack whose rows are gone but whose files stayed, or could not be listed, is uninstalled
     * with files left. A pack Joomla did not uninstall is not uninstalled; a pack row with no readable package manifest
     * is not attempted (Joomla cannot uninstall it either) and is reported apart (since 2.1.4).
     *
     * @return array{removed: string[], files_left: string[], failed: string[], no_manifest: string[], reasons: array<string, string[]>} By outcome.
     *
     * @since  2.1.4
     */
    public static function uninstallAll(): array
    {
        $result = ['removed' => [], 'files_left' => [], 'failed' => [], 'no_manifest' => [], 'reasons' => []];

        foreach (self::withoutManifest() as $element) {
            $result['no_manifest'][] = $element;
            $result['reasons'][$element] = ['it has no readable installed package manifest, so Joomla cannot uninstall it'];
        }

        foreach (self::installed() as $extensionId => $pack) {
            ['files' => $files, 'unread' => $unread] = self::listedFiles(packageId: $extensionId);
            $reasons = array_map(
                static fn (string $child): string => 'its file extension ' . $child . ' had no readable installed manifest, so the files it installed could not be listed or removed',
                $unread,
            );

            try {
                $installer = new Installer();
                $installer->setDatabase(self::db());
                $removed = $installer->uninstall('package', $extensionId);
            } catch (Throwable $e) {
                self::log(message: $pack['element'] . ': not uninstalled: ' . $e->getMessage());
                $removed = false;
            }

            $rowsGone = $removed && !self::packageRowExists(extensionId: $extensionId);
            $left = array_values(array_filter($files, static fn (string $path): bool => is_file(JPATH_ROOT . '/' . $path)));

            if ($left !== []) {
                $reasons[] = 'these files it installed are still there: ' . implode(', ', $left);
            }

            if (!$rowsGone) {
                $outcome = 'failed';
                array_unshift($reasons, 'Joomla\'s installer did not uninstall it');
            } else {
                $outcome = $reasons === [] ? 'removed' : 'files_left';
            }

            $result[$outcome][] = $pack['element'];

            if ($reasons !== []) {
                $result['reasons'][$pack['element']] = $reasons;
            }
        }

        return $result;
    }

    /**
     * Whether Joomla still records a package.
     *
     * @param int $extensionId The package's extension id.
     *
     * @since  2.1.4
     */
    private static function packageRowExists(int $extensionId): bool
    {
        $db = self::db();

        return (bool) $db->setQuery(
            $db->createQuery()
                ->select('1')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = :id')
                ->bind(':id', $extensionId, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * The package rows named like an Alfa language pack (the tag in Joomla's form) whose installed manifest is missing
     * or cannot be read: {@see self::installed()} cannot tell whether they are packs, and Joomla cannot uninstall them.
     *
     * @return string[] Their elements.
     *
     * @since  2.1.4
     */
    public static function withoutManifest(): array
    {
        $found = [];

        foreach (self::packageRows() as $row) {
            $file = JPATH_MANIFESTS . '/packages/' . $row['element'] . '.xml';

            if (
                preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', substr((string) $row['element'], \strlen(self::PACKAGE_PREFIX))) === 1
                && (!is_file($file) || @simplexml_load_file($file) === false)
            ) {
                $found[] = (string) $row['element'];
            }
        }

        return $found;
    }

    /**
     * The files a pack's file extensions list, as Joomla installs them (each filename under the target of its files
     * element), read from their installed manifests; and the file extensions whose manifest cannot be read.
     *
     * @param int $packageId The pack's extension id.
     *
     * @return array{files: string[], unread: string[]} Site-relative paths, and the elements not read.
     *
     * @since  2.1.4
     */
    public static function listedFiles(int $packageId): array
    {
        $db = self::db();
        $children = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('package_id') . ' = :id')
                ->where($db->quoteName('type') . ' = ' . $db->quote('file'))
                ->order($db->quoteName('extension_id'))
                ->bind(':id', $packageId, ParameterType::INTEGER),
        )->loadColumn() ?: [];
        $listed = ['files' => [], 'unread' => []];

        foreach ($children as $element) {
            $file = JPATH_MANIFESTS . '/files/' . $element . '.xml';
            $xml = is_file($file) ? @simplexml_load_file($file) : false;

            if ($xml === false) {
                $listed['unread'][] = (string) $element;

                continue;
            }

            foreach ($xml->fileset->files ?? [] as $files) {
                $target = trim((string) $files['target'], '/');

                foreach ($files->filename ?? [] as $name) {
                    $listed['files'][] = ($target === '' ? '' : $target . '/') . trim((string) $name, '/');
                }
            }
        }

        return $listed;
    }

    /**
     * The package rows whose element begins like a pack's.
     *
     * @return list<array{extension_id: int|string, element: string}>
     *
     * @since  2.1.4
     */
    private static function packageRows(): array
    {
        $db = self::db();

        return $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName(['extension_id', 'element']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                ->where($db->quoteName('element') . ' LIKE ' . $db->quote($db->escape(self::PACKAGE_PREFIX, true) . '%'))
                ->order($db->quoteName('extension_id')),
        )->loadAssocList() ?: [];
    }

    /**
     * Where Joomla's own language for a tag is not installed: the site, the administrator, or both, as Joomla's
     * language names them.
     *
     * @param DatabaseInterface $db The database.
     * @param string $tag The language tag.
     *
     * @return string[]
     *
     * @since  2.1.4
     */
    private static function missingJoomlaLanguage(DatabaseInterface $db, string $tag): array
    {
        $clients = array_map('intval', $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('client_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('language'))
                ->where($db->quoteName('element') . ' = :tag')
                ->bind(':tag', $tag),
        )->loadColumn() ?: []);
        $missing = [];

        foreach ([0 => 'JSITE', 1 => 'JADMINISTRATOR'] as $client => $label) {
            if (!\in_array($client, $clients, true)) {
                $missing[] = Text::_($label);
            }
        }

        return $missing;
    }

    /**
     * Say a failure in the language log. An installation request has not booted the component, so the log is
     * registered here first.
     *
     * @param string $message The line.
     *
     * @since  2.1.4
     */
    private static function log(string $message): void
    {
        try {
            AlfaLog::register();
            Log::add($message, Log::WARNING, 'com_alfa.multilingual');
        } catch (Throwable) {
            // Tools > Language tables runs the same sync.
        }
    }

    /**
     * The site's database connection.
     *
     * @since  2.1.4
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
