<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Search\CollectSearchEnginesEvent;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Throwable;

/**
 * Discovers installed search engines and decides which one serves.
 *
 * INVARIANT PROTECTED: the read half and the write half can never be mismatched.
 * The registry stores whole engines keyed by {@see SearchEngineInterface::key()},
 * never `providers[]` alongside `indexers[]`. Collecting the halves separately would
 * let core pair one engine's provider with another engine's indexer: two halves that
 * each work and together find nothing. The engine is the unit of installation, of
 * configuration and therefore of discovery.
 *
 * Discovery REQUIRES a booted CMS application, and that is inherent rather than
 * accidental: engines arrive as plugins, and `PluginHelper::importPlugin()` cannot
 * resolve plugins without one. A rebuild driven from cron or a script must therefore
 * enter through Joomla's console bootstrap (alias `session.cli`, then fetch
 * `Joomla\Console\Application`), exactly as `cli/joomla.php` does. That is a
 * REQUIREMENT on any future CLI driver, not a description of one: no console command
 * for rebuilding ships yet.
 *
 * That is a genuine boundary in this subsystem: everything BELOW discovery (the
 * document source, the builder, the language resolution) is deliberately app-free
 * so it survives any entry point. Only engine discovery is not, because plugins are
 * not.
 *
 * Discovery BOOTS AND CALLS the group directly rather than dispatching. Taking the
 * dispatcher from the container looked like scoping, but the container's dispatcher IS
 * the application's, so every plugin already registered on it, the `alfa` platform
 * group included, received onCollectSearchEngines by accident. Booting each alfa-search
 * plugin and calling the method is what actually scopes discovery to the group that
 * owns it, and it is the same shape PanelDispatcher and AlfaAdminDispatcher use.
 *
 * It deliberately does NOT boot the component to find a "core" engine. That was tried
 * and it fails under CLI (`bootComponent()` resolves the request URI and throws on a
 * console entry point), which is precisely the environment a scheduled rebuild runs in.
 * Loading one plugin group is both simpler and the only form that works everywhere.
 *
 * @since  1.5.0
 */
final class SearchEngineRegistry
{
    /**
     * The plugin group search engines are installed into.
     *
     * A dedicated group, like `alfa-payments` and `alfa-shipments`, because an engine
     * is a TYPE the merchant selects rather than a feature that merely reacts to
     * events. It also keeps discovery cheap: only engines are loaded, and enabling
     * one cannot pull unrelated feature plugins into the request.
     *
     * @var string
     * @since  1.5.0
     */
    public const PLUGIN_GROUP = 'alfa-search';

    /**
     * Discovered engines, keyed by engine key.
     *
     * @var array<string, SearchEngineInterface>|null
     * @since  1.5.0
     */
    private ?array $engines = null;

    /**
     * Which extension contributed each engine, for diagnostics.
     *
     * @var array<string, string>
     * @since  1.5.0
     */
    private array $owners = [];

    /**
     * Every discovered engine, keyed by engine key.
     *
     * @return array<string, SearchEngineInterface>
     *
     * @since  1.5.0
     */
    public function all(): array
    {
        if ($this->engines === null) {
            $this->discover();
        }

        return $this->engines;
    }

    /**
     * The engine that should serve reads right now.
     *
     * Resolution: the configured engine if it is ready; otherwise NOTHING, so the
     * caller serves from the live catalogue tables.
     *
     * Null is a normal answer, not an error. It means "no engine can answer correctly
     * right now": nothing installed, nothing rebuilt yet, an unreachable service, or
     * an index that no longer matches what the merchant declared searchable. Every one
     * of those is served completely by {@see CatalogJoinProvider}, just more slowly.
     *
     * @return SearchEngineInterface|null Null whenever the live tables should answer.
     *
     * @since  1.5.0
     */
    public function active(): ?SearchEngineInterface
    {
        $engines = $this->all();

        if ($engines === []) {
            return null;
        }

        $configured = $this->configuredKey();

        // A NAMED ENGINE IS AUTHORITATIVE, AND ITS FAILURE IS FINAL.
        //
        // When the merchant has chosen an engine and it cannot answer, this returns
        // null so the storefront falls through to the live catalogue tables, never to
        // a DIFFERENT engine. That second engine stopped receiving writes the moment
        // it stopped being the active one, so its index is a snapshot of an older
        // catalogue: serving from it would answer confidently with data from another
        // generation, which is worse than answering more slowly from the truth.
        //
        // Degrade to the source, never to another cache.
        if ($configured !== '') {
            if (isset($engines[$configured]) && $this->isReady($engines[$configured], $configured)) {
                return $engines[$configured];
            }

            Log::add(
                entry: sprintf(
                    "SearchEngineRegistry: configured engine '%s' is %s; serving from the live catalogue.",
                    $configured,
                    isset($engines[$configured]) ? 'not ready' : 'not installed',
                ),
                priority: Log::WARNING,
                category: 'com_alfa.search',
            );

            return null;
        }

        // NOTHING CHOSEN MEANS THE APPLICATION'S OWN SEARCH, not "pick something".
        //
        // The component always ships a complete catalogue search over the live tables.
        // That is a real, selectable mode (the baseline every installation runs on),
        // not a degraded state, so leaving the setting untouched selects it rather
        // than making core hunt for an engine to promote.
        //
        // Auto-selecting an installed engine was the last place core made a decision
        // that belongs to the merchant: it silently turned installing a plugin into
        // switching search over to it, before anyone had rebuilt an index or agreed to
        // the change.
        return null;
    }

    /**
     * Import the `alfa-search` plugin group, dispatch the collection event and index
     * the result by engine key.
     *
     * A failing or malformed engine is logged and skipped: one broken package must
     * never take search down for the whole site.
     *
     *
     * @since  1.5.0
     */
    private function discover(): void
    {
        $this->engines = [];
        $this->owners = [];

        try {
            // One dedicated group, matching how every other pluggable TYPE in this
            // component is discovered (alfa-payments, alfa-shipments, alfa-item-fields).
            // Asking only this group means enabling a search engine never drags in
            // unrelated feature plugins, and every engine (the one Easylogic ships
            // and a third-party one) arrives by exactly the same route.
            $app = Factory::getApplication();
            $event = new CollectSearchEnginesEvent('onCollectSearchEngines', ['engines' => []]);

            foreach (PluginHelper::getPlugin(self::PLUGIN_GROUP) as $descriptor) {
                // PER PLUGIN, not around the whole loop. With one try outside
                // this foreach, a single broken engine plugin took down engine
                // discovery for EVERY engine, including the working ones,
                // and the site fell back to no search at all.
                try {
                    $plugin = $app->bootPlugin($descriptor->name, self::PLUGIN_GROUP);

                    if (method_exists($plugin, 'onCollectSearchEngines')) {
                        $plugin->onCollectSearchEngines($event);
                    }
                } catch (Throwable $e) {
                    Log::add(
                        entry:    "SearchEngineRegistry: engine plugin '{$descriptor->name}' failed and was skipped: "
                                  . $e->getMessage(),
                        priority: Log::ERROR,
                        category: 'com_alfa.search',
                    );
                }
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'SearchEngineRegistry: engine discovery failed: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.search',
            );

            return;
        }

        foreach ($event->getEngines() as $entry) {
            $engine = $entry['engine'] ?? null;
            $element = (string) ($entry['element'] ?? '');

            if (!$engine instanceof SearchEngineInterface) {
                continue;
            }

            try {
                $key = trim($engine->key());
            } catch (Throwable $e) {
                Log::add(
                    entry:    "SearchEngineRegistry: engine from '{$element}' failed to identify itself: " . $e->getMessage(),
                    priority: Log::WARNING,
                    category: 'com_alfa.search',
                );

                continue;
            }

            if ($key === '') {
                continue;
            }

            // First registration wins, so discovery order cannot silently change which
            // implementation a key resolves to. Two engines claiming one key is a
            // packaging error, and it must be visible as one.
            if (isset($this->engines[$key])) {
                Log::add(
                    entry:    "SearchEngineRegistry: '{$element}' claims engine key '{$key}', already registered by '"
                              . ($this->owners[$key] ?? '?') . "'; ignoring the duplicate.",
                    priority: Log::WARNING,
                    category: 'com_alfa.search',
                );

                continue;
            }

            $this->engines[$key] = $engine;
            $this->owners[$key] = $element;
        }
    }

    /**
     * The engine the merchant CONFIGURED, ready or not.
     *
     * The write path needs this and must never use {@see self::active()}: active
     * requires readiness, and an engine being rebuilt for the FIRST time is not ready
     * by definition: routing writes by active() therefore dropped every product save
     * made during a first build, leaving the freshly activated index already stale.
     * Writes follow the merchant's choice; the engine's writeTargets() decides which
     * generations (if any) can receive them.
     *
     * @return SearchEngineInterface|null Null when the database search is selected
     *                                    or the chosen engine is not installed.
     *
     * @since  0.1.0
     */
    public function configured(): ?SearchEngineInterface
    {
        $key = $this->configuredKey();

        return $key === '' ? null : ($this->all()[$key] ?? null);
    }

    /**
     * The engine key the merchant configured, if any.
     *
     * PUBLISHED, and deliberately distinct from {@see self::active()}. Active means
     * "chosen AND able to answer right now"; configured means "chosen", full stop.
     * An engine plugin needs the second to decide whether to offer its operational
     * tools, because the state in which a merchant most needs the rebuild button is
     * precisely the state in which the engine is NOT ready: a fresh index that has
     * never been built. Gating on active() would remove the button that fixes the
     * problem it is reporting.
     *
     * @return string Empty when the merchant has chosen the database search.
     *
     * @since  1.5.0
     */
    public function configuredKey(): string
    {
        try {
            return trim((string) ComponentHelper::getParams('com_alfa')->get('search_engine', ''));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Readiness check that treats a throwing engine as not ready.
     *
     * An engine that explodes while reporting its own health must not be able to
     * take the caller down with it.
     *
     * @param SearchEngineInterface $engine The engine to probe.
     * @param string $key Its key, for the log entry.
     *
     *
     * @since  1.5.0
     */
    private function isReady(SearchEngineInterface $engine, string $key): bool
    {
        try {
            return $engine->isReady();
        } catch (Throwable $e) {
            Log::add(
                entry:    "SearchEngineRegistry: engine '{$key}' failed its readiness check: " . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.search',
            );

            return false;
        }
    }
}
