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
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider;
use InvalidArgumentException;
use Joomla\CMS\Log\Log;
use LogicException;
use RuntimeException;

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
 * @since  2.0.0
 */
final class ToolRegistry
{
    /**
     * Core's owner key, `group/name`: its tools are its own, collected before any plugin is asked.
     *
     * @since  2.2.0
     */
    private const CORE_OWNER = CoreToolProvider::OWNER_GROUP . '/' . CoreToolProvider::OWNER_NAME;

    /**
     * Collected actions for this request, keyed by action id.
     *
     * Per-request only. Booting plugins is cheap on an admin screen loaded by hand,
     * and a persisted cache would keep offering an action after the plugin providing
     * it was disabled.
     *
     * @var PluginAction[]|null
     *
     * @since  2.0.0
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
     * @since  2.0.0
     */
    private static array $resolved = [];

    /**
     * The claims dropped in this request's collection (2.2.0, A-C14B-8), keyed `owner_group/owner_name:action_id`: why
     * the owner named did not confirm it (unlisted, unavailable, or its own listing failed), so a run asked under that
     * name is refused for the true reason.
     *
     * @var array<string, array{why: string, code: int, text: string}>
     *
     * @since  2.2.0
     */
    private static array $dropped = [];

    /**
     * The owners' own answers in this request (A-C14B-8), by `owner_group/owner_name`: each owner is asked itself once,
     * at collection or, for a hinted run its collection did not hold, at the run (A-INT-1).
     *
     * @var array<string, array{actions: array<string, PluginAction>, why: string, text: string}>
     *
     * @since  2.2.0
     */
    private static array $answers = [];

    /**
     * Every enabled action, ordered by priority.
     *
     * @return array<string, PluginAction> Keyed `owner_group/owner_name:action_id`.
     *
     * @since  2.0.0
     */
    public static function actions(): array
    {
        if (self::$actions !== null) {
            return self::$actions;
        }

        $skipped = [];
        $indexed = [];

        // Core first, its own event, its tools named core.com_alfa. Core is not a plugin and is never asked through the
        // broadcast. Its tools must not be disableable: a disabled extension is precisely the state someone opens Tools
        // to repair, so they are collected here rather than from a plugin group.
        $core = new GetToolActionsEvent('onGetToolActions', []);
        (new CoreToolProvider())->onGetToolActions($core);

        foreach ($core->getActions() as $action) {
            $key = self::ownerOf($action) . ':' . $action->id;

            if ($action->id !== '' && self::ownerOf($action) === self::CORE_OWNER && !isset($indexed[$key])) {
                $indexed[$key] = $action;
            }
        }

        // The claims (2.2.0, design 2.15.3): every listener on the shared dispatcher (mode A, a display phase,
        // isolated), the platform group imported first; each claim names the plugin it is for, and a listener that
        // fails is logged while the others' claims stand.
        $event = new GetToolActionsEvent('onGetToolActions', []);
        $plugins = AlfaPluginHelper::get();

        foreach (self::groups() as $group) {
            $plugins->importGroup($group);
        }

        foreach ($plugins->dispatchIsolated($event) as $failure) {
            $skipped[] = $failure->listener . ' (' . $failure->error->getMessage() . ')';
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

        // A TOOL'S ENTRY IS ITS OWNER'S OWN ANSWER (A-C14B-8). A claim says only which plugin a tool is for; that plugin
        // is asked itself, once in this request, and only what it lists for itself is kept: its label, its card, its
        // enabled and mutates flags. So the card, the run, mutates() and the POST and one-time-key rule all read the
        // owner's own entry, and another plugin's claim in its name can never change how its tool is shown or guarded.
        // A claim its owner does not confirm is dropped and logged.
        //
        // KEYED BY OWNER + ID, not by id alone. Action ids are deliberately shared
        // vocabulary (every search engine declares `search.rebuild`), so the id
        // cannot be the identity. Keying by id silently dropped the second engine's
        // card while executing fine (execution has always routed by owner), which is
        // the worst kind of failure: correct behaviour, missing UI, no error. The
        // composite makes a collision what it should be: two DIFFERENT actions.
        foreach ($event->getActions() as $claim) {
            $owner = self::ownerOf($claim);
            $key = $owner . ':' . $claim->id;

            if ($claim->id === '' || isset($indexed[$key]) || isset(self::$dropped[$key])) {
                continue;
            }

            $answer = self::answerOf(owner: $owner);
            $own = $answer['actions'][$claim->id] ?? null;

            if ($own !== null) {
                $indexed[$key] = $own;

                continue;
            }

            [$why, $text] = [$answer['why'], $answer['text']];
            self::$dropped[$key] = ['why' => $why, 'code' => self::refusalCode(why: $why), 'text' => $text];
            Log::add(
                entry:    'ToolRegistry: a claim of the tool ' . $claim->id . ' in the name of ' . $owner . ' is dropped: '
                    . match ($why) {
                        'unlisted' => 'that plugin does not list it itself',
                        'nohandler' => 'that plugin declares no onExecuteToolAction, so the tool could never run',
                        default => $text,
                    } . '.',
                priority: Log::WARNING,
                category: 'com_alfa.tools',
            );
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
     * @since  2.0.0
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
     * @since  2.0.0
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
     * THE OWNER HINT IS NOT AN AUTHORISATION. The rendered button knows its provider
     * and passes it back; it never decides whether the action may run: the action must
     * be one the named plugin listed for itself in this request's collection (2.2.0,
     * every action names its plugin), enabled, and it then runs on that plugin alone,
     * through its own declaration of onExecuteToolAction. A HINTED RUN NEVER FALLS BACK
     * (2.2.0, A-INT-1): a hint under which this request's collection holds no such
     * action is refused, naming the hinted plugin and why (its own listing failed, it is
     * unavailable, it declares no handler, or it does not list the action), and no other
     * plugin's action of the same id runs, whatever a stale page or a failed listing
     * left. Only a run without a hint is looked up by id across the collection.
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
     * @since  2.0.0
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
            // A hinted run (A-C14B-8, A-INT-1): refused for the true reason, naming the hinted plugin, never run
            // elsewhere. A claim its owner did not confirm says why it was dropped; otherwise the hinted plugin's own
            // answer says it (asked once in the request).
            $hint = trim($ownerHint);

            if ($hint !== '') {
                $refusal = self::$dropped[$hint . ':' . $actionId] ?? self::refusalFor(owner: $hint, actionId: $actionId);

                throw new RuntimeException(match ($refusal['why']) {
                    'failed' => 'Tool provider failed to list its tools: ' . $hint . ' (' . $refusal['text'] . ')',
                    'unavailable' => 'Tool provider unavailable: ' . $hint . ' (' . $refusal['text'] . ')',
                    'nohandler' => 'Provider cannot execute tool actions: ' . $hint,
                    default => 'Tool action is not listed by its provider: ' . $hint . ':' . $actionId,
                }, $refusal['code']);
            }

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

        $event = new ExecuteToolActionEvent('onExecuteToolAction', [
            'action' => $actionId,
            'offset' => max(0, $offset),
            'planning' => $planning,
            'data' => $data,
        ]);

        // Core is not a plugin and must never be booted as one. A plugin's tool runs on that plugin alone (2.2.0, mode
        // B): booted through Joomla's list, its own declaration of onExecuteToolAction; a handler's exception is the
        // operation's and reaches the caller.
        if ($group === CoreToolProvider::OWNER_GROUP) {
            (new CoreToolProvider())->onExecuteToolAction($event);
        } else {
            $plugins = AlfaPluginHelper::get();

            try {
                $owner = $plugins->bootSingle(PluginId::from($group, $name));
            } catch (PluginUnavailable|InvalidArgumentException $e) {
                throw new RuntimeException('Tool provider unavailable: ' . $group . '/' . $name . ' (' . $e->getMessage() . ')', 503, $e);
            }

            if (!$plugins->declares($owner, 'onExecuteToolAction')) {
                throw new RuntimeException('Provider cannot execute tool actions: ' . $group . '/' . $name, 500);
            }

            // The entry is the owner's own (actions() keeps nothing else), so the tool it runs is one it listed itself.
            $plugins->dispatchTo($owner, $event);
        }

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
     * @since  2.0.0
     */
    public static function mutates(string $actionId, string $ownerHint = ''): bool
    {
        return self::resolve($actionId, $ownerHint)?->mutates ?? true;
    }

    /**
     * Find one action, under its claimed provider when that plugin listed it.
     *
     * @param string $actionId The action to find.
     * @param string $ownerHint Untrusted `group/name`; '' to scan everything.
     *
     * @return PluginAction|null Null when no enabled action of that id exists.
     *
     * @since  2.0.0
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
     * The search behind {@see self::resolve()}: under the hinted provider alone when
     * there is a hint (A-INT-1), the whole collection by id when there is none.
     *
     * @param string $actionId The action.
     * @param string $ownerHint Untrusted `group/name`; '' to scan everything.
     *
     *
     * @since  2.0.0
     */
    private static function resolveUncached(string $actionId, string $ownerHint): ?PluginAction
    {
        // A hint names the one plugin the action must be listed under (A-INT-1): its own entry, or nothing; never
        // another plugin's action of the same id.
        if (trim($ownerHint) !== '') {
            return self::actions()[trim($ownerHint) . ':' . $actionId] ?? null;
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
     * @since  2.0.0
     */
    private static function groups(): array
    {
        return [PanelDispatcher::PLATFORM_GROUP];
    }

    /**
     * Forget the collected actions.
     *
     *
     * @since  2.0.0
     */
    public static function invalidate(): void
    {
        self::$actions = null;
        self::$resolved = [];
        self::$dropped = [];
        self::$answers = [];
    }

    /**
     * One owner's own answer, asked once in the request (core lists its own tools, never asked as a plugin).
     *
     * @param string $owner The owner, `group/name`.
     *
     * @return array{actions: array<string, PluginAction>, why: string, text: string}
     *
     * @since  2.2.0
     */
    private static function answerOf(string $owner): array
    {
        return self::$answers[$owner] ??= $owner === self::CORE_OWNER
            ? ['actions' => [], 'why' => 'unlisted', 'text' => 'core lists its tools itself']
            : self::ownersAnswer(plugins: AlfaPluginHelper::get(), owner: $owner);
    }

    /**
     * Why a hinted run finds nothing under its hint (A-INT-1): the hinted plugin's own answer says it. It lists the
     * action itself only when its listing in this request's collection failed (the collection would hold it).
     *
     * @param string $owner The hinted owner, `group/name`.
     * @param string $actionId The action.
     *
     * @return array{why: string, code: int, text: string}
     *
     * @since  2.2.0
     */
    private static function refusalFor(string $owner, string $actionId): array
    {
        $answer = substr_count($owner, '/') === 1
            ? self::answerOf(owner: $owner)
            : ['actions' => [], 'why' => 'unlisted', 'text' => ''];
        $why = $answer['why'] === 'unlisted' && isset($answer['actions'][$actionId]) ? 'failed' : $answer['why'];
        $text = $why === 'failed' && $answer['text'] === '' ? 'its listing in this request failed' : $answer['text'];

        return ['why' => $why, 'code' => self::refusalCode(why: $why), 'text' => $text];
    }

    /**
     * The answer a refused run gives, by why: 403 not listed, 503 its owner unavailable, 500 its owner's listing
     * failed or it declares no handler.
     *
     * @param string $why unlisted, unavailable, failed or nohandler.
     *
     * @since  2.2.0
     */
    private static function refusalCode(string $why): int
    {
        return ['failed' => 500, 'nohandler' => 500, 'unavailable' => 503][$why] ?? 403;
    }

    /**
     * An action's owner, `group/name`, as its contributor named it.
     *
     * @param PluginAction $action The action.
     *
     * @since  2.2.0
     */
    private static function ownerOf(PluginAction $action): string
    {
        return ($action->metadata['owner_group'] ?? '') . '/' . ($action->metadata['owner_name'] ?? '');
    }

    /**
     * One owner's own answer to onGetToolActions (A-C14B-8): booted through the plugin helper and asked alone through
     * its own subscriber map (askSubscriber()); only the tools it lists in its own name, each id once, first first.
     *
     * @param AlfaPluginHelper $plugins The plugin helper.
     * @param string $owner The owner, `group/name`.
     *
     * @return array{actions: array<string, PluginAction>, why: string, text: string} Its tools by id; why it confirms
     *                                                                                nothing it does not list
     *                                                                                (unlisted, unavailable, failed,
     *                                                                                nohandler).
     *
     * @since  2.2.0
     */
    private static function ownersAnswer(AlfaPluginHelper $plugins, string $owner): array
    {
        [$group, $name] = explode('/', $owner, 2) + ['', ''];

        try {
            $plugin = $plugins->bootSingle(PluginId::from($group, $name));
        } catch (PluginUnavailable|InvalidArgumentException $e) {
            return ['actions' => [], 'why' => 'unavailable', 'text' => $e->getMessage()];
        }

        // A tool is confirmed only when its owner can run it (A-INT-2): an owner that declares no onExecuteToolAction
        // confirms nothing, so no card is shown that would always fail.
        if (!$plugins->declares($plugin, 'onExecuteToolAction')) {
            return ['actions' => [], 'why' => 'nohandler', 'text' => 'it declares no onExecuteToolAction'];
        }

        $answer = new GetToolActionsEvent('onGetToolActions', []);

        try {
            $failure = $plugins->askSubscriber($plugin, $answer);
        } catch (PluginUnavailable|LogicException $e) {
            // Not a subscriber, its map naming nothing for the event, or the name declared as a targeted handler: it
            // lists no tool itself.
            return ['actions' => [], 'why' => 'unlisted', 'text' => $e->getMessage()];
        }

        if ($failure !== null) {
            return ['actions' => [], 'why' => 'failed', 'text' => $failure->listener . ': ' . $failure->error->getMessage()];
        }

        $own = [];

        foreach ($answer->getActions() as $action) {
            if ($action->id !== '' && self::ownerOf($action) === $owner && !isset($own[$action->id])) {
                $own[$action->id] = $action;
            }
        }

        return ['actions' => $own, 'why' => 'unlisted', 'text' => ''];
    }
}
