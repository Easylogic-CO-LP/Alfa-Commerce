<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Closure;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use LogicException;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The single boundary between Alfa Commerce and Joomla's plugin system (2.2.0, design 2.15). Two explicit modes, a propagating and an isolating method in each; no method changes behaviour on an optional
 * argument.
 *
 * Mode A, broadcast: Alfa raises its public events on the application's shared dispatcher, so every enabled plugin that
 * Joomla or Alfa has loaded and that registered a listener for the event receives it, whatever its group. Before-phase
 * events use Joomla's dispatch() unchanged (a listener's exception aborts the operation and reaches the caller).
 * After-phase and display events use Alfa's isolated execution policy over Joomla's shared listener registry: the same
 * dispatcher's listeners, read through its public getListeners() in Joomla's priority order, each called with the same
 * event object, each listener's Throwable caught on its own, logged with the event and the listener's name, and the next
 * listener run; stopPropagation() stops the rest, as in Joomla; an exception alone never does, except one that ended the
 * caller's database transaction, which reaches the caller.
 *
 * Mode B, targeted: one selected plugin, booted through Joomla's own lifecycle, its handler found in Alfa's
 * targeted-handler declaration (TargetedHandlersInterface, or a family base class's TARGETED_EVENTS) and called with
 * the event; never the shared dispatcher, never a method called because of its name, no reflection. A plugin that is
 * missing, disabled or cannot be booted is PluginUnavailable, each caller mapping it to its own contract; there is no
 * fallback to another plugin.
 *
 * @since  2.2.0
 */
final class AlfaPluginHelper
{
    private const LOG = 'com_alfa.plugins';

    /**
     * The base class each family's plugins must extend (bootSingle()'s family check); any other group: a plugin.
     *
     * @since  2.2.0
     */
    private const FAMILIES = [
        'alfa-payments' => PaymentsPlugin::class,
        'alfa-shipments' => ShipmentsPlugin::class,
        'alfa-item-fields' => ItemFieldsPlugin::class,
        'alfa-form-fields' => FormFieldsPlugin::class,
        'alfa-search' => SearchEnginePlugin::class,
    ];

    /**
     * The operations invoke() may call: public methods declared on a family's base class.
     *
     * @since  2.2.0
     */
    private const OPERATIONS = [
        PaymentsPlugin::class => ['unbookedProviderRefunds', 'supportsOperatorPlacement', 'renewalsAllowed'],
        FormFieldsPlugin::class => ['prepareDom'],
    ];

    /**
     * The helper of a process with no application (a bare harness).
     *
     * @since  2.2.0
     */
    private static ?self $bare = null;

    /**
     * @var array<string, PluginInterface> The plugins booted through the helper in this request, by identity.
     * @since  2.2.0
     */
    private array $booted = [];

    /**
     * @var array<string, true> The plugins whose two registries were checked, by identity.
     * @since  2.2.0
     */
    private array $checked = [];

    /**
     * @var array<string, true> The plugins whose import failed in this request, by identity: their listeners cannot be
     *                          known.
     * @since  2.2.0
     */
    private array $importFailed = [];

    /**
     * The plugins' names read in this request ({@see self::name()}), by group.element.
     *
     * @var array<string, string>
     *
     * @since  2.2.0
     */
    private array $names = [];

    /**
     * @param JoomlaPluginLayer $layer The Joomla integration layer.
     *
     * @since  2.2.0
     */
    public function __construct(private readonly JoomlaPluginLayer $layer)
    {
    }

    /**
     * The name of a plugin in every message staff read, a notice, a refusal, a History line, a locked field: the name
     * Extensions: Plugins shows (UX-25, A-UX-1). A plugin that is not installed has no name there, so its element is
     * said. group.element is never staff text. Read once per plugin and request.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function name(PluginId $id): string
    {
        if (!isset($this->names[$id->key()])) {
            try {
                $this->names[$id->key()] = $this->layer->rowName($id) ?? $id->element;
            } catch (Throwable) {
                return $id->element;
            }
        }

        return $this->names[$id->key()];
    }

    /**
     * The mark a plugin's name takes where staff pick or read a plugin that is not enabled (UX-38): "disabled" when it
     * is installed and disabled, "not installed" when it is missing, '' when it is enabled.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function mark(PluginId $id): string
    {
        return match ($this->layer->rowEnabled($id)) {
            null => Text::_('COM_ALFA_PLUGIN_MARK_MISSING'),
            false => Text::_('COM_ALFA_PLUGIN_MARK_DISABLED'),
            true => '',
        };
    }

    /**
     * Why a plugin of record cannot be used, as a phrase staff read after its name (UX-27, A-UX-2): it is disabled, it is
     * not installed, or it is installed but could not be loaded. Every other reason has its own message.
     *
     * @param string $reason A PluginUnavailable reason.
     *
     * @since  2.2.0
     */
    public static function statePhrase(string $reason): string
    {
        return Text::_(match ($reason) {
            PluginUnavailable::DISABLED => 'COM_ALFA_PLUGIN_STATE_DISABLED',
            PluginUnavailable::MISSING => 'COM_ALFA_PLUGIN_STATE_MISSING',
            default => 'COM_ALFA_PLUGIN_STATE_UNLOADABLE',
        });
    }

    /**
     * The helper: com_alfa's shared service (booting the component, the same in site, administrator, API and CLI); in a
     * process with no application, a helper on the container's dispatcher (mode A reaches the listeners already
     * registered and imports nothing, mode B answers NO_APPLICATION).
     *
     * @since  2.2.0
     */
    public static function get(): self
    {
        $layer = new JoomlaPluginLayer();
        $app = $layer->application();

        if ($app === null) {
            return self::$bare ??= new self($layer);
        }

        $component = $app->bootComponent('com_alfa');

        return $component instanceof AlfaComponent ? $component->getPluginHelper() : (self::$bare ??= new self($layer));
    }

    // Mode A: the shared dispatcher.

    /**
     * Import every enabled plugin of a group onto the shared dispatcher, one plugin at a time, so one whose boot throws is
     * logged with its identity and the others still import; idempotent through Joomla's own import cache; nothing in
     * maintenance, nothing without an application.
     *
     * @param string $group The plugin group.
     *
     * @return list<string>|null The group's plugins (group.element) whose import failed in this request, so their
     *                           listeners cannot be known (Joomla tries a plugin once per dispatcher, so a failure is
     *                           remembered); null when nothing was imported (no application, or maintenance).
     *
     * @since  2.2.0
     */
    public function importGroup(string $group): ?array
    {
        if ($this->layer->application() === null) {
            Log::add('Plugins: no application, the ' . $group . ' group is not imported; listeners already registered still hear mode A events.', Log::DEBUG, self::LOG);

            return null;
        }

        if (MaintenanceState::active()) {
            return null;
        }

        foreach ($this->layer->enabledElements($group) as $element) {
            try {
                $this->importSingle(PluginId::from($group, $element));
            } catch (Throwable $e) {
                $this->rethrowWhenTransactionEnded($e);
                $this->importFailed[$group . '.' . $element] = true;
                Log::add('Plugins: ' . $group . '.' . $element . ' could not be imported (' . $e::class . ': ' . $e->getMessage() . '); the other plugins of the group are imported.', Log::WARNING, self::LOG);
            }
        }

        return array_values(array_filter(array_keys($this->importFailed), static fn (string $key): bool => str_starts_with($key, $group . '.')));
    }

    /**
     * Before phases: Joomla's dispatch() on the shared dispatcher; a listener's exception propagates to the caller.
     *
     * @param EventInterface $event The event.
     *
     * @since  2.2.0
     */
    public function dispatch(EventInterface $event): EventInterface
    {
        return $this->layer->dispatch($event);
    }

    /**
     * After and display phases: Alfa's isolated policy over the shared dispatcher's listeners (Joomla's priority order,
     * the same event object, each listener's Throwable caught, logged and the next run, stopPropagation() honoured).
     *
     * @param EventInterface $event The event.
     *
     * @return ListenerFailure[] What failed; never throws a listener's error.
     *
     * @since  2.2.0
     */
    public function dispatchIsolated(EventInterface $event): array
    {
        $failures = [];

        foreach ($this->listeners($event->getName()) as $name => $listener) {
            if ($event->isStopped()) {
                break;
            }

            try {
                $listener($event);
            } catch (Throwable $e) {
                $this->rethrowWhenTransactionEnded($e);
                Log::add('Plugins: ' . $event->getName() . ': the listener ' . $name . ' failed (' . $e::class . ': ' . $e->getMessage() . '); the next listeners run.', Log::ERROR, self::LOG);
                $failures[] = new ListenerFailure(listener: $name, error: $e);
            }
        }

        return $failures;
    }

    /**
     * Ask ONE booted plugin for its own answer to a broadcast question (2.2.0, A-C14B-7), through the plugin's public
     * Joomla subscriber map: getSubscribedEvents() names its method for the event (an entry is the method's name, or
     * the method's name and its priority), and that method is called on the booted instance with the event. No
     * dispatcher is read and nothing is reflected, so only the plugin's own declared answer is heard. A name the plugin
     * declares as a targeted handler is never delivered here: dispatchTo() delivers it.
     *
     * @param LoadedPlugin $plugin The plugin, from bootSingle().
     * @param EventInterface $event The event.
     *
     * @return ListenerFailure|null Null when the plugin answered; its failure when its method threw (logged at ERROR
     *                              with the event and Class::method), for the caller to report as that failure.
     *
     * @throws PluginUnavailable NO_HANDLER when the plugin is not a subscriber or its map does not name the event (it
     *                           lists nothing for it).
     * @throws LogicException When the event is a name in the plugin's targeted declaration.
     *
     * @since  2.2.0
     */
    public function askSubscriber(LoadedPlugin $plugin, EventInterface $event): ?ListenerFailure
    {
        $instance = $this->instanceOf($plugin);
        $name = $event->getName();

        if (isset(self::declarationOf($instance)[$name])) {
            throw new LogicException('The plugin ' . $plugin->id . ' declares ' . $name . ' as a targeted handler: it is delivered with dispatchTo(), never asked as a broadcast.');
        }

        $entry = $instance instanceof SubscriberInterface ? ($instance::getSubscribedEvents()[$name] ?? null) : null;
        $method = \is_array($entry) ? ($entry[0] ?? null) : $entry;

        if (!\is_string($method) || $method === '') {
            throw new PluginUnavailable(PluginUnavailable::NO_HANDLER, $plugin->id, 'The plugin ' . $plugin->id . ' does not name ' . $name . ' in its subscriber map.');
        }

        try {
            $instance->{$method}($event);
        } catch (Throwable $e) {
            $this->rethrowWhenTransactionEnded($e);
            $listener = $instance::class . '::' . $method;
            Log::add('Plugins: ' . $name . ', asked of ' . $plugin->id . ': ' . $listener . ' failed (' . $e::class . ': ' . $e->getMessage() . ').', Log::ERROR, self::LOG);

            return new ListenerFailure(listener: $listener, error: $e);
        }

        return null;
    }

    /**
     * The event's listeners on the shared dispatcher in Joomla's order, each named without reflection: Class::method,
     * an invokable's class, a function's name, else closure; a repeated name gets #2, #3.
     *
     * @param string $eventName The event.
     *
     * @return array<string, callable>
     *
     * @since  2.2.0
     */
    public function listeners(string $eventName): array
    {
        $named = [];

        foreach ($this->layer->listeners($eventName) as $listener) {
            $name = self::nameOf($listener);
            $key = $name;

            for ($n = 2; isset($named[$key]); $n++) {
                $key = $name . '#' . $n;
            }

            $named[$key] = $listener;
        }

        return $named;
    }

    /**
     * Whether the event has any listener on the shared dispatcher.
     *
     * @param string $eventName The event.
     *
     * @since  2.2.0
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->layer->countListeners($eventName) > 0;
    }

    /**
     * Add one of Alfa's own code listeners to a Joomla event on the shared dispatcher.
     *
     * @param string $eventName The event.
     * @param callable $listener The listener.
     * @param int $priority Joomla's priority (higher runs first).
     *
     * @since  2.2.0
     */
    public function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->layer->addListener($eventName, $listener, $priority);
    }

    /**
     * Remove one of Alfa's own code listeners.
     *
     * @param string $eventName The event.
     * @param callable $listener The listener.
     *
     * @since  2.2.0
     */
    public function forget(string $eventName, callable $listener): void
    {
        $this->layer->removeListener($eventName, $listener);
    }

    // Mode B: one selected plugin.

    /**
     * Whether the plugin is enabled for this request (PluginHelper::isEnabled(), the one authority: the list a plugin's
     * provider reads its configuration from).
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function isAvailable(PluginId $id): bool
    {
        return $this->layer->isEnabled($id);
    }

    /**
     * Boot one plugin through Joomla's lifecycle (the instance an import registers) and check its family; registers
     * nothing on the shared dispatcher.
     *
     * @param PluginId $id The plugin.
     *
     * @throws PluginUnavailable DISABLED, NOT_LOADED, MISSING, UNLOADABLE, WRONG_FAMILY or NO_APPLICATION.
     *
     * @since  2.2.0
     */
    public function bootSingle(PluginId $id): LoadedPlugin
    {
        // Asked every time: availability is Joomla's list, and the instance Joomla's own cache (one per element and
        // group); the helper only remembers which instance the last boot returned.
        $app = $this->layer->application();

        if ($app === null) {
            throw new PluginUnavailable(PluginUnavailable::NO_APPLICATION, $id, 'No application: the plugin ' . $id . ' cannot be booted.');
        }

        if (!$this->isAvailable($id)) {
            [$reason, $why] = match ($this->layer->rowEnabled($id)) {
                null => [PluginUnavailable::MISSING, 'is not installed'],
                false => [PluginUnavailable::DISABLED, 'is not enabled'],
                true => [PluginUnavailable::NOT_LOADED, 'is enabled, but Joomla does not load it for this request\'s user (its Access is a view level the user does not hold)'],
            };

            throw new PluginUnavailable($reason, $id, 'The plugin ' . $id . ' ' . $why . '.');
        }

        // A plugin whose import failed in this request is not booted again: Joomla would answer a placeholder for it.
        if (isset($this->importFailed[$id->key()])) {
            throw new PluginUnavailable(PluginUnavailable::UNLOADABLE, $id, 'The plugin ' . $id . ' could not be imported in this request.');
        }

        try {
            $plugin = $this->layer->boot($app, $id);
        } catch (Throwable $e) {
            // Remembered as importGroup() remembers a failed import: a second boot in this request would meet Joomla's
            // placeholder and read as missing (A-C14A-30).
            $this->importFailed[$id->key()] = true;

            throw new PluginUnavailable(PluginUnavailable::UNLOADABLE, $id, 'The plugin ' . $id . ' could not be booted (' . $e::class . ': ' . $e->getMessage() . ').', $e);
        }

        if ($this->layer->isPlaceholder($plugin)) {
            throw new PluginUnavailable(PluginUnavailable::MISSING, $id, 'The plugin ' . $id . ' is enabled but its files are missing.');
        }

        $family = self::FAMILIES[$id->group] ?? null;

        if ($family !== null && !$plugin instanceof $family) {
            throw new PluginUnavailable(PluginUnavailable::WRONG_FAMILY, $id, 'The plugin ' . $id . ' is not a ' . $family . '.');
        }

        $this->booted[$id->key()] = $plugin;
        $this->checkRegistries($id, $plugin);

        return new LoadedPlugin($id);
    }

    /**
     * Boot an installed plugin whether it is enabled or not, for maintenance alone (Clear Data, A-C14B-1): never a way
     * around bootSingle(). Asked only while the maintenance lock is held (MaintenanceState::lock(), on the connection
     * given); without it, it throws and nothing is booted. A disabled plugin is given the name, group and params Joomla
     * gives an enabled one (from its row), so its onClearData may run while it is disabled. The family is checked; the
     * plugin's own declaration is then asked with declares() and its handler run with dispatchTo(), as everywhere.
     *
     * @param PluginId $id The plugin.
     * @param DatabaseInterface $db The connection that holds the maintenance lock.
     * @param string|null $params Its row's params, or null to leave its params as its provider made them.
     *
     * @throws LogicException When the connection does not hold the maintenance lock.
     * @throws PluginUnavailable MISSING (its files are gone), WRONG_FAMILY or NO_APPLICATION.
     * @throws Throwable What its code threw while it loaded.
     *
     * @since  2.2.0
     */
    public function bootForMaintenance(PluginId $id, DatabaseInterface $db, ?string $params = null): LoadedPlugin
    {
        if (!MaintenanceState::holdsLock(db: $db)) {
            throw new LogicException('The plugin ' . $id . ' is booted for maintenance only under the maintenance lock.');
        }

        $app = $this->layer->application();

        if ($app === null) {
            throw new PluginUnavailable(PluginUnavailable::NO_APPLICATION, $id, 'No application: the plugin ' . $id . ' cannot be booted.');
        }

        $plugin = $this->layer->bootForMaintenance($app, $id, $params);

        if ($this->layer->isPlaceholder($plugin)) {
            throw new PluginUnavailable(PluginUnavailable::MISSING, $id, 'The plugin ' . $id . ' is installed but its files are missing.');
        }

        $family = self::FAMILIES[$id->group] ?? null;

        if ($family !== null && !$plugin instanceof $family) {
            throw new PluginUnavailable(PluginUnavailable::WRONG_FAMILY, $id, 'The plugin ' . $id . ' is not a ' . $family . '.');
        }

        $this->booted[$id->key()] = $plugin;
        $this->checkRegistries($id, $plugin);

        return new LoadedPlugin($id);
    }

    /**
     * What this request already holds through the helper; never boots.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function resolve(PluginId $id): ?LoadedPlugin
    {
        return isset($this->booted[$id->key()]) ? new LoadedPlugin($id) : null;
    }

    /**
     * Engage one plugin's own ambient subscriptions on the shared dispatcher (PluginHelper::importPlugin() for that
     * plugin, idempotent); dispatches nothing. A plugin not enabled is not imported.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function importSingle(PluginId $id): void
    {
        $app = $this->layer->application();

        if ($app === null || !$this->isAvailable($id)) {
            return;
        }

        $this->layer->import($id);

        if (!isset($this->checked[$id->key()])) {
            $this->checkRegistries($id, $this->layer->boot($app, $id));
        }
    }

    /**
     * Whether the selected plugin declares a targeted handler for the event: a caller that tolerates a plugin with no
     * handler asks this first, so that anything leaving dispatchTo() afterwards is the handler's own failure.
     *
     * @param LoadedPlugin $plugin The plugin, from bootSingle() or resolve().
     * @param string $eventName The event.
     *
     * @since  2.2.0
     */
    public function declares(LoadedPlugin $plugin, string $eventName): bool
    {
        return isset(self::declarationOf($this->instanceOf($plugin))[$eventName]);
    }

    /**
     * Deliver the event to the selected plugin's declared handler; the handler's exception propagates (the operation's
     * own contract), a PluginUnavailable it throws included: that is the handler's failure, never the helper's answer.
     *
     * @param LoadedPlugin $plugin The plugin, from bootSingle() or resolve().
     * @param EventInterface $event The event.
     *
     * @throws PluginUnavailable NO_HANDLER when the plugin declares no handler for the event (ask declares() first).
     *
     * @since  2.2.0
     */
    public function dispatchTo(LoadedPlugin $plugin, EventInterface $event): EventInterface
    {
        $instance = $this->instanceOf($plugin);
        $method = self::declarationOf($instance)[$event->getName()] ?? null;

        if ($method === null) {
            throw new PluginUnavailable(PluginUnavailable::NO_HANDLER, $plugin->id, 'The plugin ' . $plugin->id . ' declares no targeted handler for ' . $event->getName() . '.');
        }

        $instance->{$method}($event);

        return $event;
    }

    /**
     * Call an operation its family's base class declares on the selected plugin; its exceptions propagate.
     *
     * @param LoadedPlugin $plugin The plugin, from bootSingle() or resolve().
     * @param string $operation The operation (PaymentsPlugin: unbookedProviderRefunds, supportsOperatorPlacement,
     *                          renewalsAllowed; FormFieldsPlugin: prepareDom).
     * @param mixed ...$arguments Its arguments.
     *
     * @throws PluginUnavailable NO_HANDLER when the operation is not one of its family's.
     *
     * @since  2.2.0
     */
    public function invoke(LoadedPlugin $plugin, string $operation, mixed ...$arguments): mixed
    {
        $instance = $this->instanceOf($plugin);

        foreach (self::OPERATIONS as $family => $operations) {
            if ($instance instanceof $family && \in_array($operation, $operations, true)) {
                return $instance->{$operation}(...$arguments);
            }
        }

        throw new PluginUnavailable(PluginUnavailable::NO_HANDLER, $plugin->id, 'The plugin ' . $plugin->id . ' has no operation ' . $operation . ' of its family.');
    }

    // Plugin lists.

    /**
     * The enabled plugins of a group in Joomla's order, as the request's view levels see them.
     *
     * @param string $group The plugin group.
     *
     * @return PluginId[]
     *
     * @since  2.2.0
     */
    public function available(string $group): array
    {
        return array_map(static fn (string $element): PluginId => PluginId::from($group, $element), $this->layer->enabledElements($group));
    }

    /**
     * The configuration Joomla lists for an enabled plugin in this request (its params), or null when the plugin is not
     * enabled for the request's user. A read only: nothing is imported or booted.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function params(PluginId $id): ?Registry
    {
        return $this->layer->params($id);
    }

    /**
     * Where one of a plugin's layouts is read from: the active template's override, else the plugin's own tmpl folder.
     * A path only: the plugin is neither imported nor booted.
     *
     * @param PluginId $id The plugin.
     * @param string $layout The layout name.
     *
     * @since  2.2.0
     */
    public function layoutPath(PluginId $id, string $layout): string
    {
        return $this->layer->layoutPath($id, $layout);
    }

    /**
     * The plugins of a group a NAMED person's view levels admit, in Joomla's order (the customer's access
     * levels decide which plugins price a cart and judge its placement, whoever operates it).
     *
     * @param User $customer The person whose context decides participation.
     * @param string $group The plugin group.
     *
     * @return PluginId[]
     *
     * @since  2.2.0
     */
    public function admitted(User $customer, string $group): array
    {
        return array_map(static fn (string $element): PluginId => PluginId::from($group, $element), $this->layer->admittedElements($customer, $group));
    }

    // Mode B over a selection.

    /**
     * One event object to each plugin in the order given, each once, each through its own declaration; a plugin that is
     * unavailable or declares no handler takes no part; a handler's exception propagates and the rest do not run; stops
     * when the event is stopped. A selected plugin Joomla does not load for the request's user (NOT_LOADED) cannot take
     * its part, so nothing runs: the selection is refused before the first handler.
     *
     * @param iterable<PluginId> $plugins The selection.
     * @param EventInterface $event The event.
     *
     * @throws PluginUnavailable NOT_LOADED, naming the first such plugin, before any handler runs.
     *
     * @since  2.2.0
     */
    public function dispatchToEach(iterable $plugins, EventInterface $event): EventInterface
    {
        [$selected, $notLoaded] = $this->selection($plugins, $event);

        if ($notLoaded !== []) {
            throw $notLoaded[0];
        }

        foreach ($selected as [$id, $instance, $method]) {
            if ($event->isStopped()) {
                break;
            }

            $this->callFor($id, $event, static fn () => $instance->{$method}($event));
        }

        return $event;
    }

    /**
     * The same, each handler's Throwable logged with the event and the plugin's identity and the next plugin run. A
     * selected plugin Joomla does not load for the request's user (NOT_LOADED) is a failure of its own, logged at
     * WARNING and answered with its PluginUnavailable, never dropped silently; the others run.
     *
     * @param iterable<PluginId> $plugins The selection.
     * @param EventInterface $event The event.
     *
     * @return ListenerFailure[]
     *
     * @since  2.2.0
     */
    public function dispatchToEachIsolated(iterable $plugins, EventInterface $event): array
    {
        $failures = [];
        [$selected, $notLoaded] = $this->selection($plugins, $event);

        foreach ($notLoaded as $unavailable) {
            $failures[] = new ListenerFailure(listener: (string) $unavailable->plugin?->key(), error: $unavailable);
        }

        foreach ($selected as [$id, $instance, $method]) {
            if ($event->isStopped()) {
                break;
            }

            try {
                $this->callFor($id, $event, static fn () => $instance->{$method}($event));
            } catch (Throwable $e) {
                $this->rethrowWhenTransactionEnded($e);
                Log::add('Plugins: ' . $event->getName() . ': the plugin ' . $id . ' failed (' . $e::class . ': ' . $e->getMessage() . '); the next plugins run.', Log::ERROR, self::LOG);
                $failures[] = new ListenerFailure(listener: $id->key(), error: $e);
            }
        }

        return $failures;
    }

    /**
     * Forget the admitted lists read in this process (test harnesses that enable or disable plugins in one process).
     *
     * @internal
     *
     * @since  2.2.0
     */
    public function forgetAdmitted(): void
    {
        $this->layer->forgetAdmitted();
    }

    // Inside.

    /**
     * The selection's plugins that take part: each identity once, booted, with its declared handler for the event; and
     * apart, the selected plugins Joomla does not load for the request's user (NOT_LOADED), which cannot take part and
     * must not be left out silently. A plugin missing or unloadable takes no part, logged at WARNING; any other
     * unavailable one at DEBUG.
     *
     * @param iterable<PluginId> $plugins The selection.
     * @param EventInterface $event The event.
     *
     * @return array{0: list<array{0: PluginId, 1: PluginInterface, 2: string}>, 1: list<PluginUnavailable>}
     *
     * @since  2.2.0
     */
    private function selection(iterable $plugins, EventInterface $event): array
    {
        $out = [];
        $notLoaded = [];
        $seen = [];

        foreach ($plugins as $id) {
            if (isset($seen[$id->key()])) {
                continue;
            }

            $seen[$id->key()] = true;

            try {
                $instance = $this->instanceOf($this->bootSingle($id));
            } catch (PluginUnavailable $unavailable) {
                if ($unavailable->reason === PluginUnavailable::NOT_LOADED) {
                    // Only a plugin that declares the event has a part to take (A-C14A-28): one that does not takes no
                    // part, silently, whoever operates the cart.
                    $declared = $this->declarationOfUnloaded($id);

                    if ($declared !== null && !isset($declared[$event->getName()])) {
                        Log::add('Plugins: ' . $event->getName() . ': ' . $id . ' is not loaded for this request\'s user and declares no handler for the event; it takes no part.', Log::DEBUG, self::LOG);

                        continue;
                    }

                    Log::add('Plugins: ' . $event->getName() . ': ' . $unavailable->getMessage() . ' It cannot take its part here.', Log::WARNING, self::LOG);
                    $notLoaded[] = $unavailable;

                    continue;
                }

                $level = \in_array($unavailable->reason, [PluginUnavailable::MISSING, PluginUnavailable::UNLOADABLE], true) ? Log::WARNING : Log::DEBUG;
                Log::add('Plugins: ' . $event->getName() . ': ' . $unavailable->getMessage() . ' It takes no part.', $level, self::LOG);

                continue;
            }

            $method = self::declarationOf($instance)[$event->getName()] ?? null;

            if ($method !== null) {
                $out[] = [$id, $instance, $method];
            }
        }

        return [$out, $notLoaded];
    }

    /**
     * The targeted declaration of a plugin Joomla does not load for the request's user, read from an instance built
     * without its parameters (Joomla's own boot, as its provider builds it from an empty list entry), or from the one
     * already built. Only the declaration is read: no handler of that instance is ever called, it is not kept as this
     * helper's instance, and it never replaces one already built. Null when it cannot be read (the selection then keeps
     * the refusal).
     *
     * @param PluginId $id The plugin.
     *
     * @return array<string, string>|null
     *
     * @since  2.2.0
     */
    private function declarationOfUnloaded(PluginId $id): ?array
    {
        $app = $this->layer->application();

        if ($app === null) {
            return null;
        }

        try {
            $instance = $this->booted[$id->key()] ?? $this->layer->boot($app, $id);
        } catch (Throwable) {
            return null;
        }

        return $this->layer->isPlaceholder($instance) ? null : self::declarationOf($instance);
    }

    /**
     * Run one plugin's handler in a selection loop, the event told which plugin is answering when it checks the
     * contributors its typed methods are given.
     *
     * @param PluginId $id The plugin.
     * @param EventInterface $event The event.
     * @param Closure $call The handler's call.
     *
     * @since  2.2.0
     */
    private function callFor(PluginId $id, EventInterface $event, Closure $call): void
    {
        if (!$event instanceof SelectionContributorsInterface) {
            $call();

            return;
        }

        $event->answering($id);

        try {
            $call();
        } finally {
            $event->answering(null);
        }
    }

    /**
     * Isolation never hides a failure that ended the caller's transaction (a deadlock inside it): that one reaches the
     * caller, which must not commit work the server already discarded.
     *
     * @param Throwable $e What failed.
     *
     * @throws Throwable The failure, when it ended the caller's transaction.
     *
     * @since  2.2.0
     */
    private function rethrowWhenTransactionEnded(Throwable $e): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        if (TransactionState::endedBy(db: $db, failure: $e)) {
            throw $e;
        }
    }

    /**
     * The instance the helper booted for a loaded plugin.
     *
     * @param LoadedPlugin $plugin The plugin.
     *
     * @throws PluginUnavailable NO_HANDLER when the plugin was not booted through this helper.
     *
     * @since  2.2.0
     */
    private function instanceOf(LoadedPlugin $plugin): PluginInterface
    {
        return $this->booted[$plugin->id->key()]
            ?? throw new PluginUnavailable(PluginUnavailable::NO_HANDLER, $plugin->id, 'The plugin ' . $plugin->id . ' was not booted through the helper.');
    }

    /**
     * A plugin's targeted-handler declaration, event => its method: the ONE reader (2.2.0, A-C14B-15), used by
     * declares(), dispatchTo(), the selection loops, the maintenance boot's sites and the gates. A plugin that
     * implements TargetedHandlersInterface (every plugin of an Alfa group, a family base's TARGETED_EVENTS among them)
     * answers through it. A plugin of a group Joomla loads itself (task, system, user, content and the others) may
     * declare with a public getTargetedHandlers() of the same shape alone, so its class names no Alfa interface and
     * still loads when Alfa Commerce is not installed. Nothing else is read: a plugin without either declares nothing.
     * Only string event names mapped to string method names are kept.
     *
     * @param PluginInterface $plugin The plugin.
     *
     * @return array<string, string>
     *
     * @since  2.2.0
     */
    public static function declarationOf(PluginInterface $plugin): array
    {
        $reader = [$plugin, 'getTargetedHandlers'];

        if (!$plugin instanceof TargetedHandlersInterface && !\is_callable($reader)) {
            return [];
        }

        $declared = $reader();

        $handlers = [];

        foreach (\is_array($declared) ? $declared : [] as $event => $method) {
            if (\is_string($event) && $event !== '' && \is_string($method) && $method !== '') {
                $handlers[$event] = $method;
            }
        }

        return $handlers;
    }

    /**
     * Log an ERROR naming the plugin and the event when a name is in both of its registries (a handler runs
     * through one path only).
     *
     * @param PluginId $id The plugin.
     * @param PluginInterface $plugin Its instance.
     *
     * @since  2.2.0
     */
    private function checkRegistries(PluginId $id, PluginInterface $plugin): void
    {
        $this->checked[$id->key()] = true;
        $targeted = array_keys(self::declarationOf($plugin));

        if ($targeted === []) {
            return;
        }

        foreach ($this->layer->sharedNames($plugin, $targeted) as $event) {
            Log::add('Plugins: ' . $id . ' lists ' . $event . ' both as a targeted handler and on the shared dispatcher; a handler runs through one path only.', Log::ERROR, self::LOG);
        }
    }

    /**
     * A listener's name without reflection: Class::method, an invokable's class, a function's name, else closure.
     *
     * @param callable $listener The listener.
     *
     * @since  2.2.0
     */
    private static function nameOf(callable $listener): string
    {
        if (\is_array($listener)) {
            return (\is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]) . '::' . (string) $listener[1];
        }

        if ($listener instanceof Closure) {
            return 'closure';
        }

        if (\is_object($listener)) {
            return $listener::class;
        }

        return \is_string($listener) ? $listener : 'listener';
    }
}
