<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Card;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Card\CollectCardsEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Service\Card\CoreCardProvider;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * Where admin cards come from, where they go, and in what order.
 *
 * A **surface** is a screen that hosts cards; a **position** is a slot on it. A
 * template renders positions and nothing else: it never knows which cards exist,
 * so adding, moving or removing one is a declaration, never an edit here.
 *
 * Collection follows the pattern the Tools registry paid for in bugs: core first
 * and through the same event, ownership stamped after each contributor (including
 * after one throws, so its half-registered cards cannot be inherited by the next),
 * broken contributors logged rather than silently dropped, and the result keyed by
 * **owner + id**: card ids are shared vocabulary (every analytics plugin will want
 * `sales.summary`), so the id alone is not an identity.
 *
 * Cards can be hidden per viewer, so the cache is per viewer too: the alternative
 * is the first person through the door deciding what everyone else may see. It is
 * also per what that viewer may see, so a permission change shows at once.
 *
 * @since  2.0.0
 */
final class CardRegistry
{
    /**
     * @var string Its own cache group, cleared without touching anything else.
     * @since  2.0.0
     */
    public const CACHE_GROUP = 'com_alfa.cards';

    /**
     * @var string The store's home screen.
     * @since  2.0.0
     */
    public const SCREEN_DASHBOARD = 'dashboard';

    /**
     * @var string[] Entities whose save/delete can make a card's figures wrong.
     * @since  2.0.0
     */
    public const WATCHED_ENTITIES = ['order', 'item', 'combination', 'payment', 'orderstatus'];

    /**
     * @var int Minutes a resolved surface stays cached.
     * @since  2.0.0
     */
    private const TTL_MINUTES = 5;

    /**
     * Every card on a screen, resolved and placed, keyed by surface.
     *
     * @param string $screen Screen id.
     * @param bool $fresh Recompute instead of reading the cache.
     * @param string $scope Extra cache dimension the SCREEN owns: the dashboard's
     *                      period, for instance. Empty means the screen has only one
     *                      reading and every viewer shares one entry.
     *
     * @return array<string, array[]> Surface value => cards, each already ordered.
     *
     * @since  2.0.0
     */
    public static function forScreen(string $screen, bool $fresh = false, string $scope = ''): array
    {
        if ($fresh) {
            self::invalidate();
        }

        $app = Factory::getApplication();
        $viewer = (int) ($app->getIdentity()->id ?? 0);

        // THE LANGUAGE IS PART OF THE KEY, not decoration. A card's resolved DATA can
        // hold translated strings: a chart's series names and axis titles are
        // translated when the DTO is built, because the renderer must never own a
        // language key, so an entry made in one admin language would otherwise be
        // served to the next viewer in another.
        try {
            $tag = (string) $app->getLanguage()->getTag();
        } catch (Throwable) {
            $tag = '';
        }

        // SO IS THE BASE PATH. Resolved data carries routed URLs (every tile's
        // landing), and a route is only right for the base it was built under. A
        // probe run from the CLI as the same super user resolved the cards with a
        // CLI-shaped base and the browser was then served links to
        // /administrator/administrator/..., a 404 on every tile until the cache turned.
        try {
            $base = md5((string) Uri::base(true));
        } catch (Throwable) {
            $base = '';
        }

        // AND SO IS WHAT THE VIEWER MAY SEE. The predicates run inside the cached
        // resolve(), so the entry holds their answer: which cards, and which of
        // their links. A grant, a revocation or a group change has to reach the
        // dashboard at once, and nothing clears this cache when one is made: the
        // Permissions tab stores each change without a save event, and a group
        // change is made in Users. The viewer's groups and the component's rules
        // (inherited ones included) are what every such check reads. Computed inside
        // the guard below: without it no entry is safe to serve, so the cards are
        // resolved directly instead.
        try {
            $rights = md5(
                implode(',', $app->getIdentity()?->getAuthorisedGroups() ?? [])
                . '|' . Access::getAssetRules('com_alfa', true),
            );

            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => self::CACHE_GROUP,
                    'lifetime' => self::TTL_MINUTES,
                    'caching' => true,
                ]);

            // Per viewer, deliberately: cards carry visibility predicates, so a
            // shared entry would let whoever loaded the page first decide what the
            // next person sees, either leaking figures or hiding them.
            $cards = (array) $cache->get(
                [self::class, 'resolve'],
                [$screen],
                'cards.' . $screen . '.u' . $viewer . '.' . $tag . '.' . $base . '.' . $rights . ($scope !== '' ? '.' . $scope : ''),
            );
        } catch (Throwable) {
            // Cache or rights unavailable -> compute directly (correct, just uncached).
            $cards = self::resolve($screen);
        }

        // collect() loads a contributor's language while asking it for cards, but a
        // cache HIT skips collect() entirely, and the titles then render as raw
        // PLG_... keys on the dashboard. Loading is idempotent and keyed per extension,
        // so doing it here covers both paths for the price of nothing on a repeat.
        self::loadOwnerLanguages($cards);

        return $cards;
    }

    /**
     * The cards of one surface, ordered.
     *
     * @param string $screen Screen id.
     * @param DashboardSurface|string $surface The surface.
     * @param string $scope Extra cache dimension, see forScreen().
     *
     * @return array[]
     *
     * @since  2.0.0
     */
    public static function forSurface(string $screen, DashboardSurface|string $surface, string $scope = ''): array
    {
        $key = $surface instanceof DashboardSurface ? $surface->value : $surface;

        return self::forScreen($screen, false, $scope)[$key] ?? [];
    }

    /**
     * Collect, filter, resolve and order every card of a screen.
     *
     * @param string $screen Screen id.
     *
     * @return array<string, array[]>
     *
     * @since  2.0.0
     */
    public static function resolve(string $screen): array
    {
        $placed = [];

        foreach (self::collect($screen) as $card) {
            if (!$card->isVisible()) {
                continue;
            }

            $data = $card->resolve();

            if ($data === null) {
                continue;
            }

            $placed[$card->surface][] = [
                'id' => $card->id,
                'title' => $card->title,
                'subtitle' => $card->subtitle,
                'icon' => $card->icon,
                'url' => $card->url,
                'width' => $card->width,
                'layout' => $card->layout,
                'layout_base' => self::layoutBase($card),
                'ordering' => $card->ordering,
                'surface' => $card->surface,
                'data' => $data,
                'owner_group' => (string) ($card->metadata['owner_group'] ?? ''),
                'owner_name' => (string) ($card->metadata['owner_name'] ?? ''),
            ];
        }

        // Ascending, like the admin menu: a surface is layout, not run order.
        foreach ($placed as &$cards) {
            usort($cards, static fn (array $a, array $b): int => $a['ordering'] <=> $b['ordering']);
        }

        return $placed;
    }

    /**
     * Core first, then every enabled platform plugin, each stamped with its owner.
     *
     * @param string $screen Screen id.
     *
     * @return AdminCard[]
     *
     * @since  2.0.0
     */
    private static function collect(string $screen): array
    {
        $app = Factory::getApplication();
        $event = new CollectCardsEvent(CollectCardsEvent::NAME, ['screen' => $screen]);
        $skipped = [];

        // Core through the same door as everyone else; the plugin path is therefore
        // exercised on every page load rather than only when a plugin is installed.
        (new CoreCardProvider())->onAlfaCollectCards($event);
        $event->attributeTo(CoreCardProvider::OWNER_GROUP, CoreCardProvider::OWNER_NAME);

        $group = PanelDispatcher::PLATFORM_GROUP;

        try {
            PluginHelper::importPlugin(type: $group, plugin: null, autocreate: true);
            $descriptors = PluginHelper::getPlugin($group);
        } catch (Throwable $e) {
            // No plugin list (install, CLI) simply means core-only cards.
            $descriptors = [];
            $skipped[] = 'platform group unavailable (' . $e->getMessage() . ')';
        }

        foreach ($descriptors as $descriptor) {
            try {
                $plugin = $app->bootPlugin($descriptor->name, $group);

                if (!method_exists($plugin, CollectCardsEvent::NAME)) {
                    continue;
                }

                // The plugin's own language, so the keys its cards name resolve where
                // the host prints them.
                AlfaHelper::loadPluginLanguage(group: $group, element: (string) $descriptor->name);

                $plugin->{CollectCardsEvent::NAME}($event);
                $event->attributeTo($group, (string) $descriptor->name);
            } catch (Throwable $e) {
                // Pin whatever the thrower managed to add TO the thrower before the
                // next contributor's stamp could claim it, then carry on.
                $event->attributeTo($group, (string) $descriptor->name);
                $skipped[] = $group . '/' . $descriptor->name . ' (' . $e->getMessage() . ')';
            }
        }

        if ($skipped !== []) {
            // Never silent: a dashboard that is quietly missing a panel looks healthy.
            Log::add(
                entry:    'CardRegistry: skipped broken card contributors on screen ' . $screen . ': ' . implode('; ', $skipped),
                priority: Log::WARNING,
                category: 'com_alfa.cards',
            );
        }

        $indexed = [];

        foreach ($event->getCards() as $card) {
            if ($card->screen !== $screen) {
                continue;
            }

            $key = ($card->metadata['owner_group'] ?? '') . '/' . ($card->metadata['owner_name'] ?? '') . ':' . $card->id;

            if (!isset($indexed[$key])) {
                $indexed[$key] = $card;
            }
        }

        return array_values($indexed);
    }

    /**
     * Load the language of every contributor represented in a resolved card list.
     *
     * @param array<string, array<int, array<string, mixed>>> $cards Cards keyed by surface.
     *
     *
     * @since  2.0.0
     */
    private static function loadOwnerLanguages(array $cards): void
    {
        $seen = [];

        try {
            $language = Factory::getApplication()->getLanguage();
        } catch (Throwable) {
            return;                                     // no application: nothing to translate into
        }

        foreach ($cards as $surfaceCards) {
            foreach ((array) $surfaceCards as $card) {
                $group = (string) ($card['owner_group'] ?? '');
                $name = (string) ($card['owner_name'] ?? '');

                if ($group === '' || $name === '' || $group === CoreCardProvider::OWNER_GROUP) {
                    continue;
                }

                $key = $group . '.' . $name;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                AlfaHelper::loadPluginLanguage(group: $group, element: (string) $name);
            }
        }
    }

    /**
     * Where a contributor's layouts live.
     *
     * A card names a layout, never a path; the host knows the folder, exactly as
     * the panel host does with `$identity->pluginPath() . '/layouts'`.
     *
     * Precedence is **the owner's folder first, core's as the fallback**, not
     * "the same name borrows core's". A plugin that ships `layouts/card/metric.php`
     * draws ITS cards with its own file and core's cards are untouched, because a
     * core card resolves straight to the component. A plugin that names
     * `card.metric` without shipping it reuses core's tile and writes no markup.
     *
     * @param AdminCard $card The card.
     *
     * @return string Base path whose layout file exists, core's as the fallback.
     *
     * @since  2.0.0
     */
    private static function layoutBase(AdminCard $card): string
    {
        $core = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';
        $group = (string) ($card->metadata['owner_group'] ?? '');
        $name = (string) ($card->metadata['owner_name'] ?? '');

        if ($group === '' || $group === CoreCardProvider::OWNER_GROUP) {
            return $core;
        }

        $own = JPATH_PLUGINS . '/' . $group . '/' . $name . '/layouts';

        return is_file($own . '/' . str_replace('.', '/', $card->layout) . '.php') ? $own : $core;
    }

    /**
     * Render one surface: every card through its own layout, no exceptions.
     *
     * @param string $screen Screen id.
     * @param DashboardSurface|string $surface The surface.
     * @param string $scope Extra cache dimension, see forScreen().
     *
     *
     * @since  2.0.0
     */
    public static function render(string $screen, DashboardSurface|string $surface, string $scope = ''): string
    {
        $cards = self::forSurface($screen, $surface, $scope);

        if ($cards === []) {
            return '';
        }

        return LayoutHelper::render(
            'card.surface',
            [
                'screen' => $screen,
                'surface' => $surface instanceof DashboardSurface ? $surface->value : $surface,
                'class' => $surface instanceof DashboardSurface ? $surface->cssClass() : '',
                'cards' => $cards,
            ],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Drop the cached screens (all viewers).
     *
     *
     * @since  2.0.0
     */
    public static function invalidate(): void
    {
        try {
            // Via the controller factory, NOT Factory::getCache($group, ''); an empty
            // handler there throws and the catch would eat the clean.
            Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => self::CACHE_GROUP])
                ->cache
                ->clean();
        } catch (Throwable) {
            // A cache that cannot be cleaned still expires on its own.
        }
    }

    /**
     * Drop them when something a card counts has changed.
     *
     * @param string $entity Entity name the model reports.
     *
     *
     * @since  2.0.0
     */
    public static function invalidateFor(string $entity): void
    {
        if (\in_array($entity, self::WATCHED_ENTITIES, true)) {
            self::invalidate();
        }
    }
}
