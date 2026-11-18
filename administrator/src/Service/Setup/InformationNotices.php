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
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What the administrators are told about the site, not asked to do: Joomla's page cache is on, a library a feature
 * needs is missing or disabled. Each is a dismissible notification in the notification centre, never a step of the
 * setup checklist, which holds only the steps an administrator completes (rulings 177 and 178). Each follows its
 * condition ({@see ConditionNotice}): raised when it starts or changes, withdrawn when it ends; a dismissed one stays
 * dismissed while nothing changes.
 *
 * Run from the bell's background refresh ({@see \Alfa\Component\Alfa\Administrator\Controller\NotificationsController::panel()}),
 * never on a page render: a read of the page cache plugin's row (and of System - Alfa Commerce Sync's while the page
 * cache is on), one of the libraries' rows, and one of each notice's facts last told.
 *
 * @since  2.2.2
 */
final class InformationNotices
{
    /**
     * Joomla's System - Page Cache is enabled.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const PAGE_CACHE = 'alfa.page_cache';

    /**
     * A library the sales documents' PDFs need is missing or disabled ({@see BundledLibraries::PDF}).
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const PDF_LIBRARY = 'alfa.library.pdf';

    /**
     * A library the phone field needs is missing or disabled ({@see BundledLibraries::PHONE}).
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const PHONE_LIBRARY = 'alfa.library.phone';

    /**
     * Bring every notice up to date with the site as it is now. Advisory: a notice that cannot be brought up to date
     * is logged and the others still are.
     *
     * @since  2.2.2
     */
    public static function sync(): void
    {
        $notices = [
            self::PAGE_CACHE => static fn () => self::followPageCache(),
            self::PDF_LIBRARY => static fn () => self::followLibraries(key: self::PDF_LIBRARY, libraries: BundledLibraries::PDF, title: 'COM_ALFA_NOTIFY_PDF_LIBRARY_TITLE', message: 'COM_ALFA_NOTIFY_PDF_LIBRARY_MSG'),
            self::PHONE_LIBRARY => static fn () => self::followLibraries(key: self::PHONE_LIBRARY, libraries: BundledLibraries::PHONE, title: 'COM_ALFA_NOTIFY_PHONE_LIBRARY_TITLE', message: 'COM_ALFA_NOTIFY_PHONE_LIBRARY_MSG'),
        ];

        foreach ($notices as $key => $follow) {
            try {
                $follow();
            } catch (Throwable $e) {
                Log::add('The notice ' . $key . ' could not be brought up to date: ' . $e::class . ': ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
            }
        }
    }

    /**
     * Joomla's page cache: while System - Page Cache is enabled, what Alfa Commerce keeps out of it, in the words of
     * System - Alfa Commerce Sync's state ({@see SyncPluginState}); a note when the plugin runs for visitors who are
     * not logged in, a warning when it does not.
     *
     * @since  2.2.2
     */
    private static function followPageCache(): void
    {
        [$words, $severity] = match (self::pageCacheEnabled() ? SyncPluginState::read() : null) {
            null => [null, ''],
            SyncPluginState::EFFECTIVE => ['COM_ALFA_NOTIFY_PAGECACHE_MSG', 'info'],
            SyncPluginState::NOT_FOR_GUESTS => ['COM_ALFA_NOTIFY_PAGECACHE_ACCESS_MSG', 'warning'],
            default => ['COM_ALFA_NOTIFY_PAGECACHE_UNGUARDED_MSG', 'warning'],
        };

        ConditionNotice::follow(
            key:   self::PAGE_CACHE,
            facts: $words === null ? [] : [$words],
            push:  static fn () => NotificationHelper::push(
                dedupKey: self::PAGE_CACHE,
                title:    NotificationText::of('COM_ALFA_NOTIFY_PAGECACHE_TITLE'),
                options:  [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => $severity,
                    'message' => NotificationText::of((string) $words),
                    'url' => 'index.php?option=com_plugins&view=plugins&filter[folder]=system',
                    'dismissible' => true,
                    'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'core.manage', 'asset' => 'com_plugins'],
                ],
            ),
        );
    }

    /**
     * A set of libraries: while one of them is missing or disabled, which, and what does not work until it is back.
     *
     * @param string $key The notice's dedup key.
     * @param array<string, class-string> $libraries The set ({@see BundledLibraries}).
     * @param string $title The title's key.
     * @param string $message The message's key; its parameter is the libraries' names.
     *
     * @since  2.2.2
     */
    private static function followLibraries(string $key, array $libraries, string $title, string $message): void
    {
        $unavailable = BundledLibraries::unavailable(libraries: $libraries);

        ConditionNotice::follow(
            key:   $key,
            facts: $unavailable,
            push:  static fn () => NotificationHelper::push(
                dedupKey: $key,
                title:    NotificationText::of($title),
                options:  [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => 'warning',
                    'message' => NotificationText::of($message, NotificationText::join(items: $unavailable, separator: ', ')),
                    'dismissible' => true,
                    'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                ],
            ),
        );
    }

    /**
     * Whether Joomla's System - Page Cache is enabled: one indexed read of its extension row.
     *
     * @since  2.2.2
     */
    private static function pageCacheEnabled(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $folder = 'system';
        $element = 'cache';
        $type = 'plugin';

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':type', $type, ParameterType::STRING)
                ->bind(':folder', $folder, ParameterType::STRING)
                ->bind(':element', $element, ParameterType::STRING),
            0,
            1,
        )->loadResult() === 1;
    }
}
