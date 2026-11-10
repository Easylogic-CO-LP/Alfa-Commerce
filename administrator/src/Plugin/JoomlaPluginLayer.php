<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Closure;
use Exception;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Extension\DummyPlugin;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Every Joomla detail of Alfa's plugin integration, in one place (2.2.0, design 2.15.2): PluginHelper, the
 * application's bootPlugin() and its extension container, the shared dispatcher and its listener registry, the
 * view-level plugin lists. Built on Joomla's documented public APIs only; no second dispatcher, no reflection, no
 * private state of Joomla's. A Joomla upgrade is meant to change this class only.
 *
 * @internal Used by AlfaPluginHelper only; business code asks the helper.
 *
 * @since  2.2.0
 */
final class JoomlaPluginLayer
{
    /**
     * @var array<string, object[]> The plugins a named person's view levels admit, per group and view-level signature.
     * @since  2.2.0
     */
    private array $admitted = [];

    /**
     * The request's application, or null in a process that has none (a bare harness).
     *
     * @since  2.2.0
     */
    public function application(): ?CMSApplicationInterface
    {
        try {
            return Factory::getApplication();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The shared dispatcher: the application's, read per call and never created; with no application, the container's.
     *
     * @since  2.2.0
     */
    public function dispatcher(): DispatcherInterface
    {
        $app = $this->application();

        return $app !== null ? $app->getDispatcher() : Factory::getContainer()->get(DispatcherInterface::class);
    }

    /**
     * The enabled plugins of a group in Joomla's order (ordering), as the request's view levels see them.
     *
     * @param string $group The plugin group.
     *
     * @return string[] Their elements.
     *
     * @since  2.2.0
     */
    public function enabledElements(string $group): array
    {
        $rows = PluginHelper::getPlugin($group);

        return array_values(array_map(static fn (object $row): string => (string) $row->name, \is_array($rows) ? $rows : []));
    }

    /**
     * Whether the plugin is enabled for this request: PluginHelper::isEnabled(), the list a plugin's provider reads its
     * configuration from.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function isEnabled(PluginId $id): bool
    {
        return PluginHelper::isEnabled($id->group, $id->element);
    }

    /**
     * The configuration Joomla lists for the plugin in this request (PluginHelper::getPlugin()'s params), or null when
     * Joomla does not list it.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function params(PluginId $id): ?Registry
    {
        $row = PluginHelper::isEnabled($id->group, $id->element) ? PluginHelper::getPlugin($id->group, $id->element) : null;

        return \is_object($row) ? new Registry($row->params ?? '') : null;
    }

    /**
     * Where a plugin layout is read from: the active template's override first, else the plugin's tmpl folder
     * (PluginHelper::getLayoutPath()).
     *
     * @param PluginId $id The plugin.
     * @param string $layout The layout name.
     *
     * @since  2.2.0
     */
    public function layoutPath(PluginId $id, string $layout): string
    {
        return PluginHelper::getLayoutPath($id->group, $id->element, $layout);
    }

    /**
     * The plugin's extension row, whatever the request's view levels: null when none exists, else whether it is
     * enabled.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function rowEnabled(PluginId $id): ?bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $group = $id->group;
        $element = $id->element;
        $enabled = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':folder', $group)
                ->bind(':element', $element),
            0,
            1,
        )->loadResult();

        return $enabled === null ? null : (int) $enabled === 1;
    }

    /**
     * Import one plugin onto the shared dispatcher: PluginHelper::importPlugin(), idempotent through Joomla's own
     * import cache (one registration per dispatcher, group and element).
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function import(PluginId $id): void
    {
        PluginHelper::importPlugin($id->group, $id->element);
    }

    /**
     * The instance Joomla holds for the plugin (the application's bootPlugin(), its extension container; the one
     * instance per element and group that an import registers). A missing plugin comes back as Joomla's DummyPlugin.
     *
     * @param CMSApplicationInterface $app The application.
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    public function boot(CMSApplicationInterface $app, PluginId $id): PluginInterface
    {
        return $app->bootPlugin($id->element, $id->group);
    }

    /**
     * Boot an installed plugin whether it is enabled or not, for maintenance (Clear Data): Joomla's bootPlugin(), then
     * the name and group, and the params when given, that Joomla gives an enabled plugin and a disabled one's provider
     * leaves out (its provider builds it from PluginHelper::getPlugin(), which knows enabled plugins only; CMSPlugin keeps
     * the name and group protected and has no setter, so they are set from the plugin's own scope). Only what is empty
     * is filled. The helper asks this only under the maintenance lock.
     *
     * @param CMSApplicationInterface $app The application.
     * @param PluginId $id The plugin.
     * @param string|null $params Its row's params, or null to leave its params as its provider made them.
     *
     * @since  2.2.0
     */
    public function bootForMaintenance(CMSApplicationInterface $app, PluginId $id, ?string $params): PluginInterface
    {
        $plugin = $app->bootPlugin($id->element, $id->group);

        if ($plugin instanceof CMSPlugin) {
            $identity = function (string $element, string $folder, ?string $params): void {
                if ((string) $this->_name === '') {
                    $this->_name = $element;
                }

                if ((string) $this->_type === '') {
                    $this->_type = $folder;
                }

                if ($params !== null && !$this->params instanceof Registry) {
                    $this->params = new Registry($params === '' ? '{}' : $params);
                }
            };

            Closure::bind($identity, $plugin, CMSPlugin::class)($id->element, $id->group, $params);
        }

        return $plugin;
    }

    /**
     * Whether Joomla answered a missing plugin's boot with its placeholder.
     *
     * @param PluginInterface $plugin What bootPlugin() returned.
     *
     * @since  2.2.0
     */
    public function isPlaceholder(PluginInterface $plugin): bool
    {
        return $plugin instanceof DummyPlugin;
    }

    /**
     * The events a booted plugin puts on the shared dispatcher when imported, for the two-registries check: a
     * subscriber's map; for any other plugin, the given names it has as public methods (Joomla registers every public
     * on* method of a plugin that is not a subscriber).
     *
     * @param PluginInterface $plugin The booted plugin.
     * @param string[] $names The names to check against.
     *
     * @return string[]
     *
     * @since  2.2.0
     */
    public function sharedNames(PluginInterface $plugin, array $names): array
    {
        if ($plugin instanceof SubscriberInterface) {
            return array_values(array_intersect($names, array_keys($plugin::getSubscribedEvents())));
        }

        return array_values(array_filter($names, static fn (string $name): bool => is_callable([$plugin, $name])));
    }

    /**
     * The plugins of a group a NAMED person's view levels admit (2.2.0, design 2.15.3): Joomla's own filter
     * (enabled, a plugin, state 0 or 1, access in that person's view levels, by ordering), for that person rather than
     * the request's identity, so a cart operated by staff is priced by the customer's plugins.
     *
     * @param User $customer The person whose context decides participation.
     * @param string $group The plugin group.
     *
     * @return string[] Their elements, in Joomla's order.
     *
     * @since  2.2.0
     */
    public function admittedElements(User $customer, string $group): array
    {
        $levels = array_values(array_map('intval', $customer->getAuthorisedViewLevels()));

        // A person with no view level sees nothing, which is not the same as no filter at all.
        if ($levels === []) {
            return [];
        }

        $key = $group . '|' . implode(',', $levels);

        if (!isset($this->admitted[$key])) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $this->admitted[$key] = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('element'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('state') . ' IN (0, 1)')
                    ->where($db->quoteName('folder') . ' = :folder')
                    ->whereIn($db->quoteName('access'), $levels, ParameterType::INTEGER)
                    ->order($db->quoteName('ordering'))
                    ->bind(':folder', $group),
            )->loadColumn() ?: [];
        }

        return array_values(array_map('strval', $this->admitted[$key]));
    }

    /**
     * Forget the admitted lists read in this process (test harnesses that enable or disable plugins in one process).
     *
     * @since  2.2.0
     */
    public function forgetAdmitted(): void
    {
        $this->admitted = [];
    }

    /**
     * The event's listeners on the shared dispatcher, in the order its dispatch() calls them (getListeners(), sorted by
     * priority).
     *
     * @param string $eventName The event.
     *
     * @return callable[]
     *
     * @since  2.2.0
     */
    public function listeners(string $eventName): array
    {
        return array_values($this->dispatcher()->getListeners($eventName));
    }

    /**
     * How many listeners the event has on the shared dispatcher.
     *
     * @param string $eventName The event.
     *
     * @since  2.2.0
     */
    public function countListeners(string $eventName): int
    {
        return (int) $this->dispatcher()->countListeners($eventName);
    }

    /**
     * Joomla's dispatch() of the event on the shared dispatcher, unchanged.
     *
     * @param EventInterface $event The event.
     *
     * @since  2.2.0
     */
    public function dispatch(EventInterface $event): EventInterface
    {
        return $this->dispatcher()->dispatch($event->getName(), $event);
    }

    /**
     * Add a listener to the shared dispatcher.
     *
     * @param string $eventName The event.
     * @param callable $listener The listener.
     * @param int $priority Joomla's priority (higher runs first).
     *
     * @since  2.2.0
     */
    public function addListener(string $eventName, callable $listener, int $priority): void
    {
        $this->dispatcher()->addListener($eventName, $listener, $priority);
    }

    /**
     * Remove a listener from the shared dispatcher.
     *
     * @param string $eventName The event.
     * @param callable $listener The listener.
     *
     * @since  2.2.0
     */
    public function removeListener(string $eventName, callable $listener): void
    {
        $this->dispatcher()->removeListener($eventName, $listener);
    }
}
