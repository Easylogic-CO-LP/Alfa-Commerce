<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Tools\ExecuteToolActionEvent;
use Alfa\Component\Alfa\Administrator\Event\Tools\GetToolActionsEvent;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use RuntimeException;
use Throwable;

/**
 * What maintenance actions exist, who provides them, and how one is run.
 *
 * THE ROUTING KEY IS NOT IN THE ACTION NAME. Elsewhere in the component a stored
 * record names its own plugin (a payment row carries `params->type`, a field row
 * carries `type`), so the executor is read from the database and never from the
 * request. Tools have no such record, so ownership is established the only other
 * honest way: each plugin is booted and asked individually, and whatever it registers
 * is tagged with the plugin that was asked.
 *
 * That is why an action may be called `search.rebuild` rather than `example.rebuild`.
 * The prefix is a readable namespace; it is never parsed to decide what runs. Every
 * indexed-search engine can therefore offer the same action id and the Tools screen
 * stays engine-agnostic.
 *
 * WHY THE CLIENT DOES NOT SEND THE OWNER. Not chiefly secrecy: redundancy. Before
 * running anything the component must answer "does this action exist, and is it
 * enabled right now?", because a plugin may legitimately publish a disabled action
 * when its prerequisites are unmet. Only this registry can answer that, and the
 * lookup yields the owner in the same breath. Accepting an owner from the browser
 * would add a second source of truth that can disagree with the first, for nothing.
 *
 * GROUPS ARE DISCOVERED, NOT LISTED. Any enabled plugin group whose folder begins
 * `alfa` participates, so shipping a new group never requires editing this file.
 *
 * @since  1.5.0
 */
final class ToolRegistry
{
    /**
     * Collected actions for this request, keyed by action id.
     *
     * Per-request only. Booting plugins is cheap on an admin screen loaded by hand,
     * and a persisted cache would keep offering an action after the plugin providing
     * it was disabled.
     *
     * @var PluginAction[]|null
     *
     * @since  1.5.0
     */
    private static ?array $actions = null;

    /**
     * Resolutions already made this request, keyed `hint|id`.
     *
     * The endpoint asks twice per request (is it mutating, then run it) and
     * booting the provider twice to answer the same question is the cost the
     * order-action dispatcher documents avoiding. Null is remembered too: an
     * action nobody declares is not re-searched for.
     *
     * @var array<string, PluginAction|null>
     *
     * @since  1.10.0
     */
    private static array $resolved = [];

    /**
     * Every enabled action, ordered by priority.
     *
     * @return array<string, PluginAction> Keyed `owner_group/owner_name:action_id`.
     *
     * @since  1.5.0
     */
    public static function actions(): array
    {
        if (self::$actions !== null) {
            return self::$actions;
        }

        $app = Factory::getApplication();
        $event = new GetToolActionsEvent('onGetToolActions', []);
        $skipped = [];

        // Core first, and through the same door. Its tools must not be disableable:
        // a disabled extension is precisely the state someone opens Tools to repair,
        // so they are collected here rather than from a plugin group.
        $core = new CoreToolProvider();
        $core->onGetToolActions($event);
        $event->attributeTo(CoreToolProvider::OWNER_GROUP, CoreToolProvider::OWNER_NAME);

        foreach (self::groups() as $group) {
            PluginHelper::importPlugin(type: $group, plugin: null, autocreate: true);

            foreach (PluginHelper::getPlugin($group) as $descriptor) {
                try {
                    $plugin = $app->bootPlugin($descriptor->name, $group);

                    if (!method_exists($plugin, 'onGetToolActions')) {
                        continue;
                    }

                    $plugin->onGetToolActions($event);

                    // Tag whatever this contributor just added, before asking the next
                    // one: this is what makes ownership observed rather than claimed.
                    $event->attributeTo($group, $descriptor->name);
                } catch (Throwable $e) {
                    // One broken plugin must not empty the whole Tools screen, but its
                    // half-registered actions must not be inherited either. attributeTo()
                    // only tags untagged actions, so tagging here pins whatever the
                    // thrower managed to add to the THROWER; without this, the next
                    // contributor's attributeTo() would claim them and execution would
                    // boot the wrong plugin for another plugin's action.
                    $event->attributeTo($group, $descriptor->name);
                    $skipped[] = $group . '/' . $descriptor->name . ' (' . $e->getMessage() . ')';
                }
            }
        }

        if ($skipped !== []) {
            // Silence here is the same defect the groups() fallback had: a screen that
            // looks healthy while missing features. The cards stay gone; the log says why.
            Log::add(
                entry:    'ToolRegistry: skipped broken tool contributors: ' . implode('; ', $skipped),
                priority: Log::WARNING,
                category: 'com_alfa.tools',
            );
        }

        $indexed = [];

        // KEYED BY OWNER + ID, not by id alone. Action ids are deliberately shared
        // vocabulary (every search engine declares `search.rebuild`), so the id
        // cannot be the identity. Keying by id silently dropped the second engine's
        // card while executing fine (execution has always routed by owner), which is
        // the worst kind of failure: correct behaviour, missing UI, no error. The
        // composite makes a collision what it should be: two DIFFERENT actions.
        foreach ($event->getActions() as $action) {
            $owner = ($action->metadata['owner_group'] ?? '') . '/' . ($action->metadata['owner_name'] ?? '');
            $key = $owner . ':' . $action->id;

            if ($action->id !== '' && !isset($indexed[$key])) {
                $indexed[$key] = $action;
            }
        }

        return self::$actions = $indexed;
    }

    /**
     * Actions belonging to one tab.
     *
     * @param string $surface Tab id. Actions declare theirs with `meta('surface', ...)`.
     *
     * @return PluginAction[]
     *
     * @since  1.5.0
     */
    public static function forSurface(string $surface): array
    {
        $actions = array_values(array_filter(
            self::actions(),
            static fn (PluginAction $action): bool => (string) ($action->metadata['surface'] ?? '') === $surface,
        ));

        // Priority DESC: the ordering every provider declares and, until the Tools
        // template became a single registry loop, nothing ever expressed. Core's
        // priorities encode RUN order (a language table that does not exist cannot be
        // backfilled), so the screen now reads top-to-bottom as "do them in this order".
        usort($actions, static fn (PluginAction $a, PluginAction $b): int => $b->priority <=> $a->priority);

        return $actions;
    }

    /**
     * Surfaces that no core tab renders, each of which becomes a tab of its own.
     *
     * @param string[] $known Tab ids the Tools template already renders.
     *
     * @return array<string, PluginAction[]> Keyed by surface id, in first-seen order.
     *
     * @since  1.5.0
     */
    public static function extraSurfaces(array $known): array
    {
        $extra = [];

        foreach (self::actions() as $action) {
            $surface = (string) ($action->metadata['surface'] ?? '');

            if ($surface === '' || \in_array($surface, $known, true)) {
                continue;
            }

            $extra[$surface][] = $action;
        }

        foreach ($extra as &$group) {
            usort($group, static fn (PluginAction $a, PluginAction $b): int => $b->priority <=> $a->priority);
        }

        return $extra;
    }

    /**
     * Run one action in the plugin that declared it.
     *
     * THE OWNER HINT IS AN OPTIMISATION, NOT AN AUTHORISATION. The rendered button
     * already knows its provider, so passing it back lets this boot ONE plugin
     * instead of every one. It never decides whether the action may run: the named
     * plugin is asked for its own actions and must itself declare this one, enabled.
     * A hint pointing anywhere else simply fails that check and falls back to a full
     * scan, which is also what happens when a stale page sends a hint for a plugin
     * that has since been disabled.
     *
     * @param string $actionId The action to run.
     * @param int $offset Units already processed; 0 on the first call.
     * @param bool $planning Whether this is the sizing pass.
     * @param array<string, mixed> $data Untrusted client payload.
     * @param string $ownerHint Untrusted `group/name` from the button.
     *
     * @return ExecuteToolActionEvent The answered event.
     *
     * @throws RuntimeException When the action is unknown, disabled, or its provider
     *                          cannot be booted.
     *
     * @since  1.5.0
     */
    public static function execute(
        string $actionId,
        int $offset = 0,
        bool $planning = false,
        array $data = [],
        string $ownerHint = '',
    ): ExecuteToolActionEvent {
        $action = self::resolve($actionId, $ownerHint);

        if ($action === null) {
            throw new RuntimeException('Unknown or unavailable tool action: ' . $actionId, 404);
        }

        // Disabled actions are COLLECTED (the card renders greyed out, explaining
        // itself) but never RUN: visibility is a UI decision, runnability is not.
        if (!$action->enabled) {
            throw new RuntimeException('Tool action is disabled: ' . $actionId, 403);
        }

        $group = (string) ($action->metadata['owner_group'] ?? '');
        $name = (string) ($action->metadata['owner_name'] ?? '');

        if ($group === '' || $name === '') {
            throw new RuntimeException('Tool action has no provider: ' . $actionId, 500);
        }

        // Core is not a plugin and must never be booted as one; everything downstream
        // of this line is identical for both, which is the point of the reserved owner.
        $plugin = $group === CoreToolProvider::OWNER_GROUP
            ? new CoreToolProvider()
            : Factory::getApplication()->bootPlugin($name, $group);

        if (!method_exists($plugin, 'onExecuteToolAction')) {
            throw new RuntimeException('Provider cannot execute tool actions: ' . $group . '/' . $name, 500);
        }

        $event = new ExecuteToolActionEvent('onExecuteToolAction', [
            'action' => $actionId,
            'offset' => max(0, $offset),
            'planning' => $planning,
            'data' => $data,
        ]);

        $plugin->onExecuteToolAction($event);

        // A cursor that does not advance turns the client's progress loop into an
        // infinite one. Refuse it here, where it is one error, rather than in the
        // browser, where it is a hung page.
        $next = $event->getNextOffset();

        // Planning has no cursor semantics: offset is always 0 and a provider that
        // answers the sizing pass with a cursor is sizing, not looping.
        if (!$planning && $next !== null && $next <= $offset) {
            $event->setNextOffset($offset);
            $event->setError('Tool action did not advance its cursor: ' . $actionId);
        }

        return $event;
    }

    /**
     * Whether an action changes state: true unless its provider declared it
     * read-only with {@see \Alfa\Component\Alfa\Administrator\Plugin\PluginAction::readOnly()}.
     *
     * Asked by the Tools endpoint BEFORE it executes, so a state-changing tool
     * is held to the same rule as a state-changing order action: a POST with a
     * one-time key, never a URL. An action nobody declares answers true, as an
     * action an order row does not offer does.
     *
     * @param string $actionId The action.
     * @param string $ownerHint The `group/name` the card sent back, or ''.
     *
     *
     * @since  1.10.0
     */
    public static function mutates(string $actionId, string $ownerHint = ''): bool
    {
        return self::resolve($actionId, $ownerHint)?->mutates ?? true;
    }

    /**
     * Find one action, asking only its claimed provider when that is enough.
     *
     * @param string $actionId The action to find.
     * @param string $ownerHint Untrusted `group/name`; '' to scan everything.
     *
     * @return PluginAction|null Null when no enabled action of that id exists.
     *
     * @since  1.5.0
     */
    private static function resolve(string $actionId, string $ownerHint): ?PluginAction
    {
        $key = $ownerHint . '|' . $actionId;

        if (!\array_key_exists($key, self::$resolved)) {
            self::$resolved[$key] = self::resolveUncached($actionId, $ownerHint);
        }

        return self::$resolved[$key];
    }

    /**
     * The search behind {@see self::resolve()}: the claimed provider first, the
     * whole collection when that does not answer.
     *
     * @param string $actionId The action.
     * @param string $ownerHint Untrusted `group/name`; '' to scan everything.
     *
     *
     * @since  1.10.0
     */
    private static function resolveUncached(string $actionId, string $ownerHint): ?PluginAction
    {
        if ($ownerHint !== '' && substr_count($ownerHint, '/') === 1) {
            [$group, $name] = explode('/', $ownerHint, 2);

            foreach (self::askPlugin(trim($group), trim($name)) as $action) {
                if ($action->id === $actionId) {
                    return $action;
                }
            }
        }

        // Full scan. The map is keyed by owner + id, and a caller without a hint has
        // named only the id; first enabled match wins, exactly as collection order
        // would have decided before the composite key existed.
        foreach (self::actions() as $action) {
            if ($action->id === $actionId) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Ask ONE plugin what it offers, tagged with its own identity.
     *
     * Returns nothing for a group outside this component, a plugin that is disabled
     * or missing, or one that offers no tools, each of which makes a hint useless
     * rather than dangerous, because the caller then falls back to a full scan.
     *
     * @param string $group Plugin group.
     * @param string $name Plugin element name.
     *
     * @return PluginAction[] Enabled actions only.
     *
     * @since  1.5.0
     */
    private static function askPlugin(string $group, string $name): array
    {
        if ($group === CoreToolProvider::OWNER_GROUP) {
            $event = new GetToolActionsEvent('onGetToolActions', []);
            (new CoreToolProvider())->onGetToolActions($event);
            $event->attributeTo(CoreToolProvider::OWNER_GROUP, CoreToolProvider::OWNER_NAME);

            return $event->getActions();
        }

        if ($group === '' || $name === '' || !\in_array($group, self::groups(), true)) {
            return [];
        }

        try {
            if (!PluginHelper::isEnabled($group, $name)) {
                return [];
            }

            PluginHelper::importPlugin(type: $group, plugin: $name, autocreate: true);

            $plugin = Factory::getApplication()->bootPlugin($name, $group);

            if (!method_exists($plugin, 'onGetToolActions')) {
                return [];
            }

            $event = new GetToolActionsEvent('onGetToolActions', []);
            $plugin->onGetToolActions($event);
            $event->attributeTo($group, $name);

            return $event->getActions();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * The plugin groups a tool card may come from: the PLATFORM group, and only it.
     *
     * THIS USED TO BE `LIKE 'alfa%'`, i.e. every alfa* group, which imported and booted every
     * enabled plugin of all seven of them on every Tools page load. The Tools screen is a SHARED
     * backend surface, so under Alfa's ownership rule only the platform group contributes to it;
     * capability modules (alfa-payments, alfa-search, ...) stay lazy and are woken by the domain that
     * needs them, never by someone opening Tools.
     *
     * Nothing was lost in the narrowing. The index-rebuild card was never the engine's: it drives
     * the component's own RebuildRunner over the component's own catalogue, and now lives in
     * {@see CoreToolProvider}, which asks the configured engine only for its label and its owed
     * state through Service\Search\RebuildableEngine. Enterprise Search's analytics purge, the one
     * genuinely capability-owned tool, moved to plg_alfa_enterprise-search in this group. Both
     * action ids are unchanged, so a stale Tools tab or a bookmarked toolaction URL still resolves.
     *
     * A constant, not a query: there is one platform group and discovering it would be theatre.
     *
     * @return string[]
     *
     * @since  1.5.0
     */
    private static function groups(): array
    {
        return [PanelDispatcher::PLATFORM_GROUP];
    }

    /**
     * Forget the collected actions.
     *
     *
     * @since  1.5.0
     */
    public static function invalidate(): void
    {
        self::$actions = null;
        self::$resolved = [];
    }
}
