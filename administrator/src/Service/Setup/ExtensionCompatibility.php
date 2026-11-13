<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\SchemaRecreator;
use InvalidArgumentException;
use Joomla\CMS\Language\Text;
use RuntimeException;

/**
 * Which installed Alfa extensions are not known to work with the installed Alfa Commerce (2.2.1): read from the range
 * each extension declares in its manifest ({@see AlfaCoreRange}), never from its version.
 *
 * WHICH EXTENSIONS. The installed Alfa extensions as Options > About lists them ({@see ClearDataExtensions::installed()}:
 * plugins of a group alfa... or of an Alfa\ namespace, which are the task and console plugins, and modules mod_alfa...),
 * the plugins and modules only, without Alfa Commerce and the extensions its manifest bundles
 * ({@see SchemaRecreator::bundled()}): those ship with it and declare its line. A package is not judged itself: each of
 * its plugins and modules is.
 *
 * THE VERDICT. Each one's manifest is read from its folder (Joomla's manifest cache keeps a fixed set of fields, so the
 * element is never in it). A range that includes the line of the installed Alfa Commerce: nothing to say. A range that
 * excludes it: "needs a compatible build". No range, a range that cannot be read, or a manifest that cannot be read:
 * "compatibility not stated", the reason written to the maintenance log by the update.
 *
 * WHERE IT IS SAID. After Alfa Commerce is installed or updated, on the installer's page, in the maintenance log and as
 * one notification to the administrators (script.php postflight, {@see self::notify()} with an alert); a recommended
 * row of the setup checklist reads the same verdict whenever the checklist is computed, and clears when every
 * extension named declares a range that includes this line. When an Alfa extension is installed, updated or
 * uninstalled, System - Alfa Commerce Sync makes the checklist compute again and brings a standing notification up to
 * date ({@see self::notify()} without an alert), clearing it when nothing is left to name.
 *
 * @since  2.2.1
 */
final class ExtensionCompatibility
{
    /**
     * The notification's key.
     *
     * @var string
     *
     * @since  2.2.1
     */
    public const NOTICE = 'alfa.extensions.compatibility';

    /**
     * The verdict of an extension whose declared range excludes the installed line.
     *
     * @var string
     *
     * @since  2.2.1
     */
    public const NEEDS_BUILD = 'needs_build';

    /**
     * The verdict of an extension that declares no range, or one that cannot be read.
     *
     * @var string
     *
     * @since  2.2.1
     */
    public const NOT_STATED = 'not_stated';

    /**
     * The installed Alfa extensions not known to work with the installed Alfa Commerce, in the order of Options > About.
     *
     * The verdict is NEEDS_BUILD or NOT_STATED; why is what the manifest says, in words, for the maintenance log.
     *
     * @return list<array{key: string, name: string, verdict: string, why: string}>
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.1
     */
    public static function named(): array
    {
        $core = AlfaVersion::installed()?->toString() ?? throw new RuntimeException('The installed Alfa Commerce version cannot be read.');
        $bundled = [];

        foreach (SchemaRecreator::bundled() as $extension) {
            $bundled[AlfaInstallerGate::key(type: $extension['type'], element: $extension['element'], folder: $extension['folder'])] = true;
        }

        $extensions = new ClearDataExtensions();
        $named = [];

        foreach (ClearDataExtensions::installed() as $entry) {
            if (!\in_array($entry['type'], ['plugin', 'module'], true) || isset($bundled[$entry['key']])) {
                continue;
            }

            try {
                $range = AlfaCoreRange::ofFile(path: self::manifestPath(entry: $entry));
            } catch (InvalidArgumentException $e) {
                $named[] = ['key' => $entry['key'], 'name' => $extensions->displayName(entry: $entry), 'verdict' => self::NOT_STATED, 'why' => $e->getMessage()];

                continue;
            }

            if ($range === null) {
                $named[] = ['key' => $entry['key'], 'name' => $extensions->displayName(entry: $entry), 'verdict' => self::NOT_STATED, 'why' => 'its manifest has no alfacore element'];
            } elseif (!$range->includes(version: $core)) {
                $named[] = ['key' => $entry['key'], 'name' => $extensions->displayName(entry: $entry), 'verdict' => self::NEEDS_BUILD, 'why' => 'it is built for Alfa Commerce ' . $range->describe()];
            }
        }

        return $named;
    }

    /**
     * The extensions named, with what is said of each, as the setup checklist's row names them: "Name (needs a
     * compatible build); Name (compatibility not stated)", in the administrator's language.
     *
     * @param list<array{key: string, name: string, verdict: string, why: string}> $named From {@see self::named()}.
     *
     * @since  2.2.1
     */
    public static function describe(array $named): string
    {
        return implode('; ', array_map(
            static fn (array $entry): string => $entry['name'] . ' (' . Text::_($entry['verdict'] === self::NEEDS_BUILD
                ? 'COM_ALFA_EXTENSION_COMPATIBILITY_NEEDS_BUILD'
                : 'COM_ALFA_EXTENSION_COMPATIBILITY_NOT_STATED') . ')',
            $named,
        ));
    }

    /**
     * The installed line of Alfa Commerce (2.2 for 2.2.1).
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.1
     */
    public static function coreLine(): string
    {
        $core = AlfaVersion::installed()?->toString() ?? throw new RuntimeException('The installed Alfa Commerce version cannot be read.');

        return AlfaCoreRange::line(version: $core);
    }

    /**
     * Say it to the administrators, or clear what was said. With an alert (Alfa Commerce installed or updated): the
     * notification of an earlier installation is removed and, when an extension is named, a new one is raised, so it
     * is shown unread even when the last one was dismissed. Without an alert (an Alfa extension installed, updated or
     * uninstalled): a standing notification is brought up to date, its read state kept, or cleared when nothing is
     * named any more; none is raised.
     *
     * @param bool $alert Whether this is Alfa Commerce's own installation.
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.1
     */
    public static function notify(bool $alert): void
    {
        if (!$alert && !NotificationHelper::isOpen(dedupKey: self::NOTICE)) {
            return;
        }

        $named = self::named();

        if ($alert && NotificationHelper::exists(dedupKey: self::NOTICE)) {
            NotificationHelper::clear(dedupKey: self::NOTICE, hard: true);
        }

        if ($named === []) {
            if (!$alert) {
                NotificationHelper::clear(dedupKey: self::NOTICE);
            }

            return;
        }

        $line = self::coreLine();
        $names = static fn (string $verdict): array => array_values(array_map(
            static fn (array $entry): string => $entry['name'],
            array_filter($named, static fn (array $entry): bool => $entry['verdict'] === $verdict),
        ));
        $sentences = [];

        if (($needs = $names(self::NEEDS_BUILD)) !== []) {
            $sentences[] = NotificationText::key('COM_ALFA_NOTIFY_EXTENSIONS_NEED_BUILD', NotificationText::join(items: $needs, separator: ', '));
        }

        if (($unstated = $names(self::NOT_STATED)) !== []) {
            $sentences[] = NotificationText::key('COM_ALFA_NOTIFY_EXTENSIONS_NOT_STATED', NotificationText::join(items: $unstated, separator: ', '));
        }

        $sentences[] = NotificationText::key('COM_ALFA_NOTIFY_EXTENSIONS_WHAT_TO_DO', $line);

        NotificationHelper::push(
            dedupKey: self::NOTICE,
            title:    NotificationText::of('COM_ALFA_NOTIFY_EXTENSIONS_COMPATIBILITY_TITLE', $line),
            options:  [
                'group' => NotificationHelper::GROUP_SETUP,
                'severity' => 'warning',
                'message' => NotificationText::sentences(...$sentences),
                'url' => 'index.php?option=com_alfa&view=extensions',
                'dismissible' => true,
                'constant' => $alert,
                'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
            ],
        );
    }

    /**
     * Where an installed plugin's or module's manifest is: the file Joomla's installer refreshes its manifest cache from
     * (`<element>.xml` in its folder).
     *
     * @param array{type: string, element: string, folder: string, client_id: int} $entry An installed() entry.
     *
     * @since  2.2.1
     */
    private static function manifestPath(array $entry): string
    {
        if ($entry['type'] === 'plugin') {
            return JPATH_PLUGINS . '/' . $entry['folder'] . '/' . $entry['element'] . '/' . $entry['element'] . '.xml';
        }

        return ($entry['client_id'] === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/modules/' . $entry['element'] . '/' . $entry['element'] . '.xml';
    }
}
