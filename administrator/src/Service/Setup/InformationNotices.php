<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Site\Helper\ShopMode;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What the administrators are told about the site, not asked to do: Joomla's page cache is on, a library a feature
 * needs is missing or disabled, an Alfa extension is not built for this version, online sales are switched off,
 * products sell from a quantity no price covers. Each is a dismissible notification
 * in the notification centre, never a step of the setup checklist, which holds only the steps an administrator
 * completes. Each follows its condition ({@see ConditionNotice}): raised when it starts or something new holds,
 * withdrawn when it ends; a dismissed one stays dismissed while nothing new holds.
 *
 * Run from the bell's background refresh ({@see \Alfa\Component\Alfa\Administrator\Controller\NotificationsController::panel()}),
 * never on a page render: a read of the page cache plugin's row (and of System - Alfa Commerce Sync's while the page
 * cache is on), one of the libraries' rows, the Options, and one of each notice's facts last told; the products'
 * minimums at most once in {@see self::PRICES_MINUTES} minutes and the extensions' manifests at most once in
 * {@see self::EXTENSIONS_MINUTES} minutes for the whole site (an installation through Joomla already brings that
 * notice up to date, {@see ExtensionCompatibility::notify()}).
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
     * Online sales are switched off: the shop shows its catalogue only ({@see ShopMode::sellsOnline()}).
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const CATALOGUE_ONLY = 'alfa.catalogue_only';

    /**
     * Published products sell from a quantity no price row covers ({@see self::minimumUnderTier()}).
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const MINIMUM_UNDER_TIER = 'alfa.minimum_under_tier';

    /**
     * How many of those products the notice's facts name, by id: a product newly named beyond them raises nothing
     * again; the message gives the count.
     *
     * @var int
     *
     * @since  2.2.2
     */
    private const MINIMUM_FACTS = 1000;

    /**
     * Minutes between two looks of the bell's refresh at the products' minimums (two reads over the published
     * products).
     *
     * @var int
     *
     * @since  2.2.2
     */
    private const PRICES_MINUTES = 10;

    /**
     * Minutes between two looks of the bell's refresh at the extensions' manifests.
     *
     * @var int
     *
     * @since  2.2.2
     */
    private const EXTENSIONS_MINUTES = 60;

    /**
     * The cache group of that look's throttle.
     *
     * @var string
     *
     * @since  2.2.2
     */
    private const CACHE_GROUP = 'com_alfa.notices';

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
            self::CATALOGUE_ONLY => static fn () => self::followCatalogueOnly(),
            self::MINIMUM_UNDER_TIER => static fn () => self::throttled(look: 'lookAtMinimums', minutes: self::PRICES_MINUTES),
            ExtensionCompatibility::NOTICE => static fn () => self::throttled(look: 'lookAtExtensions', minutes: self::EXTENSIONS_MINUTES),
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
     * A look at most once in the given minutes for the whole site (a cache callback, as the collation look's); with no
     * cache, every time.
     *
     * @param string $look The public static look of this class, the cache callback.
     * @param int $minutes The throttle.
     *
     * @since  2.2.2
     */
    private static function throttled(string $look, int $minutes): void
    {
        try {
            $controller = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => self::CACHE_GROUP,
                    'lifetime' => $minutes,
                    'caching' => true,
                ]);
        } catch (Throwable) {
            \call_user_func([self::class, $look]);

            return;
        }

        $controller->get([self::class, $look], [], $look);
    }

    /**
     * The extensions' look ({@see self::throttled()}): the compatibility notice follows the verdicts read now. Public
     * only because it is the cache callback.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.2.2
     */
    public static function lookAtExtensions(): bool
    {
        ExtensionCompatibility::notify();

        return true;
    }

    /**
     * The products' look ({@see self::throttled()}): while published products sell from a quantity no price covers,
     * how many and the first ten by name, and what to do; withdrawn when none is left. Public only because it is the
     * cache callback.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.2.2
     */
    public static function lookAtMinimums(): bool
    {
        $under = self::minimumUnderTier();

        ConditionNotice::follow(
            key:   self::MINIMUM_UNDER_TIER,
            facts: array_map(static fn (int $id): string => 'item:' . $id, $under['ids']),
            push:  static fn () => NotificationHelper::push(
                dedupKey: self::MINIMUM_UNDER_TIER,
                title:    NotificationText::of('COM_ALFA_NOTIFY_MINIMUM_UNDER_TIER_TITLE'),
                options:  [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_MINIMUM_UNDER_TIER_MSG', $under['count'], NotificationText::join(items: $under['names'], separator: ', ')),
                    'url' => 'index.php?option=com_alfa&view=items',
                    'dismissible' => true,
                    'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                ],
            ),
        );

        return true;
    }

    /**
     * Online sales: while the shop shows its catalogue only, a note that nothing can be bought online; withdrawn when
     * it sells again. One read of the Options, which Joomla holds for the request.
     *
     * @since  2.2.2
     */
    private static function followCatalogueOnly(): void
    {
        ConditionNotice::follow(
            key:   self::CATALOGUE_ONLY,
            facts: ShopMode::sellsOnline() ? [] : ['catalogue_only'],
            push:  static fn () => NotificationHelper::push(
                dedupKey: self::CATALOGUE_ONLY,
                title:    NotificationText::of('COM_ALFA_NOTIFY_CATALOGUE_ONLY_TITLE'),
                options:  [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => 'info',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_CATALOGUE_ONLY_MSG'),
                    'url' => 'index.php?option=com_config&view=component&component=com_alfa',
                    'dismissible' => true,
                    'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
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

    /**
     * The published products whose minimum quantity no price row covers (2.2.2): the product has price rows for every
     * shopper of a currency (no group, customer or place of their own; an empty scope read as none, as 0), and none of
     * them covers the minimum, whether the rows begin above it or all end below it. A row of any currency counts, so a
     * shop with one row per currency is named only where none covers the minimum. A product priced only for a group, a
     * customer or a place is not named: it is priced for them on purpose. Product rows only (a variant's own rows and
     * minimum are not read); a row's publish window is not read.
     *
     * Read only: what the notice MINIMUM_UNDER_TIER says; nothing is written.
     *
     * @return array{count: int, names: list<string>, ids: list<int>} How many, the names of the first ten in the default
     *                                                                language, and the ids of the first MINIMUM_FACTS.
     *
     * @since  2.2.2
     */
    public static function minimumUnderTier(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $minimum = 'GREATEST(' . $db->quoteName('i.quantity_min') . ', 1)';
        $general = $db->quoteName('p.item_id') . ' = ' . $db->quoteName('i.id') . ' AND ' . $db->quoteName('p.combination_id') . ' IS NULL'
            . ' AND ' . $db->quoteName('p.state') . ' = 1'
            . ' AND COALESCE(' . $db->quoteName('p.usergroup_id') . ', 0) = 0 AND COALESCE(' . $db->quoteName('p.user_id') . ', 0) = 0'
            . ' AND COALESCE(' . $db->quoteName('p.country_id') . ', 0) = 0';
        $where = $db->quoteName('i.state') . ' = 1'
            . ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_prices', 'p') . ' WHERE ' . $general . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_prices', 'p') . ' WHERE ' . $general
            . ' AND COALESCE(' . $db->quoteName('p.quantity_start') . ', 0) <= ' . $minimum
            . ' AND (' . $db->quoteName('p.quantity_end') . ' IS NULL OR ' . $db->quoteName('p.quantity_end') . ' >= ' . $minimum . '))';
        $count = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items', 'i') . ' WHERE ' . $where)->loadResult();

        if ($count === 0) {
            return ['count' => 0, 'names' => [], 'ids' => []];
        }

        $ids = $db->setQuery(
            'SELECT ' . $db->quoteName('i.id') . ' FROM ' . $db->quoteName('#__alfa_items', 'i') . ' WHERE ' . $where . ' ORDER BY ' . $db->quoteName('i.id'),
            0,
            self::MINIMUM_FACTS,
        )->loadColumn() ?: [];

        $names = $db->setQuery(
            'SELECT COALESCE(NULLIF(' . $db->quoteName('n.name') . ", ''), CONCAT('#', " . $db->quoteName('i.id') . ')) FROM ' . $db->quoteName('#__alfa_items', 'i')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_items_' . MultilingualHelper::getDefaultLanguageTag(), 'n') . ' ON ' . $db->quoteName('n.id_item') . ' = ' . $db->quoteName('i.id')
            . ' WHERE ' . $where . ' ORDER BY ' . $db->quoteName('i.id'),
            0,
            10,
        )->loadColumn() ?: [];

        return ['count' => $count, 'names' => array_map('strval', $names), 'ids' => array_map('intval', $ids)];
    }
}
