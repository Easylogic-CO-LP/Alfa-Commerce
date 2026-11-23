<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\SchemaRecreator;
use InvalidArgumentException;
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
 * WHERE IT IS SAID. A dismissible notification to the administrators, which opens Tools > Extensions, where each
 * extension shows its verdict ({@see self::verdicts()}); never on the installer's page nor in the setup checklist.
 * It follows the verdict ({@see self::follow()}, {@see ConditionNotice}): after Alfa Commerce is installed or
 * updated (script.php postflight, which also writes one line per extension named to the maintenance log), after an
 * Alfa extension is installed, updated or uninstalled (System - Alfa Commerce Sync), when Tools > Extensions is opened,
 * and at most once an hour from the bell's background refresh. An extension newly named, or a new line of Alfa
 * Commerce, raises it again, unread; one no longer named updates it in place, its read and dismissed state kept;
 * nothing left to name withdraws it.
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
     * The verdict of an extension whose declared range includes the installed line.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const COMPATIBLE = 'compatible';

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
     * The verdict of every installed Alfa plugin and module apart from what Alfa Commerce bundles, in the order of
     * Options > About, as Tools > Extensions shows it beside each one.
     *
     * The verdict is COMPATIBLE, NEEDS_BUILD or NOT_STATED; the range is the one the manifest declares (null when it
     * declares none or it cannot be read); why is what the manifest says, in words, for the maintenance log.
     *
     * @return array<string, array{key: string, name: string, verdict: string, range: ?AlfaCoreRange, why: string}> By key.
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.2
     */
    public static function verdicts(): array
    {
        $core = AlfaVersion::installed()?->toString() ?? throw new RuntimeException('The installed Alfa Commerce version cannot be read.');
        $bundled = [];

        foreach (SchemaRecreator::bundled() as $extension) {
            $bundled[AlfaInstallerGate::key(type: $extension['type'], element: $extension['element'], folder: $extension['folder'])] = true;
        }

        $extensions = new ClearDataExtensions();
        $verdicts = [];

        foreach (ClearDataExtensions::installed() as $entry) {
            if (!\in_array($entry['type'], ['plugin', 'module'], true) || isset($bundled[$entry['key']])) {
                continue;
            }

            $verdict = ['key' => $entry['key'], 'name' => $extensions->displayName(entry: $entry)];

            try {
                $range = AlfaCoreRange::ofFile(path: self::manifestPath(entry: $entry));
            } catch (InvalidArgumentException $e) {
                $verdicts[$entry['key']] = $verdict + ['verdict' => self::NOT_STATED, 'range' => null, 'why' => ErrorText::of($e)];

                continue;
            }

            $verdicts[$entry['key']] = $verdict + match (true) {
                $range === null => ['verdict' => self::NOT_STATED, 'range' => null, 'why' => 'its manifest has no alfacore element'],
                !$range->includes(version: $core) => ['verdict' => self::NEEDS_BUILD, 'range' => $range, 'why' => 'it is built for Alfa Commerce ' . $range->describe()],
                default => ['verdict' => self::COMPATIBLE, 'range' => $range, 'why' => ''],
            };
        }

        return $verdicts;
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
     * Say it to the administrators, or withdraw what was said, from the verdicts read now: {@see self::follow()}.
     * Asked after Alfa Commerce is installed or updated (script.php postflight), after an Alfa extension is installed,
     * updated or uninstalled (System - Alfa Commerce Sync), and at most once an hour by the bell's background refresh
     * ({@see InformationNotices::sync()}), so the notice follows the extensions also while Alfa Commerce Sync is off.
     *
     * It answers what it named: the extensions not known to work with the installed Alfa Commerce, in the order of
     * Options > About, each with its verdict (NEEDS_BUILD or NOT_STATED) and why, in words, for the maintenance log.
     *
     * @return list<array{key: string, name: string, verdict: string, why: string}>
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.1
     */
    public static function notify(): array
    {
        return self::follow(verdicts: self::verdicts());
    }

    /**
     * The notification follows the verdicts ({@see ConditionNotice::follow()}): its facts are each extension named
     * with its verdict, and the installed line of Alfa Commerce while one is named. An extension newly named, or a new
     * line of Alfa Commerce, raises it again, unread; an extension no longer named brings it up to date in place, its
     * read and dismissed state kept; none named withdraws it. An installation that names nothing new leaves a dismissed
     * notice dismissed. A notice standing from before its facts were kept is withdrawn when nothing is named.
     *
     * @param array<string, array{key: string, name: string, verdict: string, range: ?AlfaCoreRange, why: string}> $verdicts From {@see self::verdicts()}.
     *
     * @return list<array{key: string, name: string, verdict: string, why: string}> What it named.
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.2
     */
    public static function follow(array $verdicts): array
    {
        $named = [];

        foreach ($verdicts as $entry) {
            if ($entry['verdict'] !== self::COMPATIBLE) {
                $named[] = ['key' => $entry['key'], 'name' => $entry['name'], 'verdict' => $entry['verdict'], 'why' => $entry['why']];
            }
        }

        $facts = array_map(static fn (array $entry): string => $entry['key'] . ':' . $entry['verdict'], $named);

        if ($facts === []) {
            if (ConditionNotice::told(key: self::NOTICE) === null && NotificationHelper::isOpen(dedupKey: self::NOTICE)) {
                NotificationHelper::clear(dedupKey: self::NOTICE);
            }
        } else {
            $facts[] = 'core:' . self::coreLine();
        }

        ConditionNotice::follow(key: self::NOTICE, facts: $facts, push: static fn () => self::push(named: $named));

        return $named;
    }

    /**
     * The notification naming the extensions: those that need a compatible build, those that do not say, and what to
     * do; it opens Tools > Extensions.
     *
     * @param list<array{key: string, name: string, verdict: string, why: string}> $named From {@see self::follow()}.
     *
     * @throws RuntimeException When the installed Alfa Commerce version cannot be read.
     *
     * @since  2.2.2
     */
    private static function push(array $named): void
    {
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
