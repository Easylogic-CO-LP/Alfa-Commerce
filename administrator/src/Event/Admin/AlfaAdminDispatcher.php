<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Throwable;

/**
 * The ONE way com_alfa's admin MVC calls into plugins, so all ~25 admin entities dispatch
 * identically instead of through the three hand-rolled dialects the orders and items screens grew.
 *
 * IT ASKS THE `alfa` GROUP, AND ONLY THE `alfa` GROUP, because of what these events ARE, not
 * because of who is allowed a user interface. The distinction is OWNERSHIP:
 *
 *   `alfa`          the PLATFORM extension layer. It owns what is SHARED: the global backend menu,
 *                   common dashboard areas, generic panels, platform-wide toolbars and actions, and
 *                   cross-capability admin extensions. Reaching into a screen the plugin does not
 *                   own (putting a column on the orders grid, a tab on the product form) is
 *                   precisely that, and precisely what this contract offers.
 *   `alfa-payments`, `alfa-shipments`, `alfa-item-fields`, `alfa-search`, ...
 *                   CAPABILITY providers. Each owns its OWN domain: business logic, services, and
 *                   its own screens and layouts: a payment gateway's configuration screen, a shipment screen, a
 *                   search synonyms panel all belong to their capability. They are woken only when
 *                   that capability is required (this order's payment method, this field's type),
 *                   and nothing here may make them global backend contributors by default.
 *
 * So this is NOT a rule about who may ship UI. A capability plugin owning its own domain screens is
 * correct and expected. What it must not do is become a listener on every admin screen in the
 * product; for that it ships a SECOND plugin in the `alfa` group (plg_alfa_mygateway-admin alongside
 * plg_alfa-payments_mygateway), which is what keeps a tax grid from waking a payment gateway. It is also
 * the rule the orders grid already follows: see OrdersModel::getListQuery().
 *
 * BECAUSE OF THAT, THE WHOLE CONTRACT IS FREE. AlfaComponent::boot() already calls
 * PluginHelper::importPlugin('alfa') before the component dispatches, so every platform plugin is
 * loaded before any model or view runs. Asking one of them whether it handles an event is a
 * method_exists() call on an object that already exists: no query, no boot, nothing to cache. A
 * plugin's LANGUAGE is the one thing still loaded lazily: only for a plugin that actually handles
 * the event being dispatched.
 *
 * FAILURE POLICY, from the phase itself. A display dispatch absorbs a plugin's exception:
 * a broken plugin must never blank a grid or an edit form. An ABORTABLE dispatch (validate,
 * before-save, before-delete) rethrows, because the operation has not happened yet and
 * swallowing the throw would let a refused save look like a successful one. An AFTER dispatch
 * absorbs again: the record is already written or gone, so throwing there would report failure
 * for work that succeeded; it is logged instead.
 *
 * @since  1.8.0
 */
final class AlfaAdminDispatcher
{
    /**
     * The phases where a handler's exception must stop the operation. Everything else is
     * absorbed and logged.
     *
     * @var string[]
     * @since  1.8.0
     */
    private const ABORTABLE_PHASES = [
        AlfaAdminContext::PHASE_VALIDATE,
        AlfaAdminContext::PHASE_BEFORE_SAVE,
        AlfaAdminContext::PHASE_BEFORE_DELETE,
    ];

    /**
     * The one plugin group that may extend the admin: the platform extension point.
     *
     * @var string
     * @since  1.8.0
     */
    public const GROUP = 'alfa';

    /**
     * @var object[]|null The enabled platform plugin descriptors ({folder, element}), once per request.
     * @since  1.8.0
     */
    private static ?array $descriptors = null;

    /**
     * @var array<string, PluginInterface|null> Booted plugin instances by 'folder/element' (null =
     *                                          it could not be booted, so we stop trying).
     * @since  1.8.0
     */
    private static array $booted = [];

    /**
     * @var array<string, bool> Whether any booted plugin implements an event, by event name.
     * @since  1.8.0
     */
    private static array $subscribed = [];

    /**
     * @var array<string, bool> Plugins whose language files have been loaded, by 'folder/element'.
     * @since  1.8.0
     */
    private static array $languages = [];

    /**
     * Whether ANY enabled platform plugin implements this event.
     *
     * The whole point is the negative answer: an admin screen dispatches up to nine times, and a
     * site with no contributor for a phase must pay nothing for it: no context to build, no rows
     * to re-derive, no form to re-bind. Callers whose dispatch has a setup cost ask first.
     *
     * @param string $event The event name (an AlfaAdminEvent::NAME).
     *
     *
     * @since  1.8.0
     */
    public static function hasSubscribers(string $event): bool
    {
        if (isset(self::$subscribed[$event])) {
            return self::$subscribed[$event];
        }

        self::$subscribed[$event] = false;

        foreach (self::descriptors() as $descriptor) {
            $plugin = self::boot($descriptor->folder, $descriptor->element);

            if ($plugin !== null && method_exists($plugin, $event)) {
                self::$subscribed[$event] = true;

                break;
            }
        }

        return self::$subscribed[$event];
    }

    /**
     * Hand one event to every enabled platform plugin that implements it.
     *
     * @param AlfaAdminEvent $event The event, carrying the context every handler reads and writes.
     *
     * @return AlfaAdminEvent The same event, after every handler has (maybe) contributed.
     *
     * @throws Throwable When a handler throws during an abortable phase (validate, before-save,
     *                   before-delete); the caller turns that into a refused operation.
     *
     * @since  1.8.0
     */
    public static function dispatch(AlfaAdminEvent $event): AlfaAdminEvent
    {
        $context = $event->getAdminContext();
        $method = $event::NAME;
        $abort = \in_array($context->phase, self::ABORTABLE_PHASES, true);

        foreach (self::descriptors() as $descriptor) {
            $plugin = self::boot($descriptor->folder, $descriptor->element);

            if ($plugin === null || !method_exists($plugin, $method)) {
                continue;
            }

            // Its own language, from its own folder, so any key the handler contributes (a column
            // header, an action label) resolves where the host prints it. Loaded here rather than at
            // boot so a plugin that handles nothing never costs a language lookup.
            self::loadLanguage($descriptor->folder, $descriptor->element);

            // Contributions default to their own author's layouts, so a plugin naming a cell
            // or action layout never has to repeat who it is.
            $context->withContributor(group: $descriptor->folder, element: $descriptor->element);

            try {
                $plugin->$method($event);
            } catch (Throwable $e) {
                self::log($descriptor->folder . '/' . $descriptor->element . ' - ' . $method
                    . ' - ' . $context->key() . ': ' . $e->getMessage());

                if ($abort) {
                    throw $e;
                }
            }
        }

        // The stamp belongs to a dispatch, not to the context: leaving the last contributor in
        // place would silently adopt anything the component itself adds afterwards.
        $context->withContributor(group: '', element: '');

        return $event;
    }

    /**
     * Drop the per-request caches. For tests and for the rare caller that enables or disables a
     * plugin mid-request and then dispatches again.
     *
     *
     * @since  1.8.0
     */
    public static function reset(): void
    {
        self::$descriptors = null;
        self::$booted = [];
        self::$subscribed = [];
        self::$languages = [];
    }

    /**
     * The enabled plugins of the `alfa` platform group, as {folder, element} rows.
     *
     * Joomla has already loaded this list (AlfaComponent::boot() imports the group before the
     * component dispatches), so this costs no query and no boot, and it inherits PluginHelper's
     * rules: the access-level filter, and the admin-configured plugin ORDERING, which is how a site
     * decides whose contribution lands first when two plugins touch the same screen.
     *
     * Capability groups (alfa-payments, alfa-shipments, ...) are deliberately absent: they stay lazy
     * and are woken only by the domain that needs them. See the class docblock.
     *
     * @return object[] Rows of {folder, element}.
     *
     * @since  1.8.0
     */
    private static function descriptors(): array
    {
        if (self::$descriptors !== null) {
            return self::$descriptors;
        }

        self::$descriptors = [];

        try {
            foreach (PluginHelper::getPlugin(self::GROUP) as $plugin) {
                self::$descriptors[] = (object) ['folder' => self::GROUP, 'element' => $plugin->name];
            }
        } catch (Throwable $e) {
            // No plugin list yet (install, CLI, a broken connection) simply means no contributors.
            self::log('could not enumerate platform plugins: ' . $e->getMessage());
        }

        return self::$descriptors;
    }

    /**
     * Boot one plugin. Cached per request; a plugin that cannot be booted is remembered as
     * unusable rather than retried on every dispatch.
     *
     * Never actually a cold boot in practice: the `alfa` group is imported before the component
     * dispatches, so this resolves to Joomla's cached instance.
     *
     * @param string $folder The plugin group.
     * @param string $element The plugin element.
     *
     * @return PluginInterface|null The booted plugin, or null when it could not be booted.
     *
     * @since  1.8.0
     */
    private static function boot(string $folder, string $element): ?PluginInterface
    {
        $key = $folder . '/' . $element;

        if (\array_key_exists($key, self::$booted)) {
            return self::$booted[$key];
        }

        self::$booted[$key] = null;

        try {
            self::$booted[$key] = Factory::getApplication()->bootPlugin($element, $folder);
        } catch (Throwable $e) {
            self::log($key . ': could not boot: ' . $e->getMessage());
        }

        return self::$booted[$key];
    }

    /**
     * Load one plugin's language files, once per request.
     *
     * @param string $folder The plugin group.
     * @param string $element The plugin element.
     *
     *
     * @since  1.8.0
     */
    private static function loadLanguage(string $folder, string $element): void
    {
        $key = $folder . '/' . $element;

        if (isset(self::$languages[$key])) {
            return;
        }

        self::$languages[$key] = true;

        try {
            AlfaHelper::loadPluginLanguage(group: $folder, element: $element);
        } catch (Throwable $e) {
            self::log($key . ': could not load language: ' . $e->getMessage());
        }
    }

    /**
     * @param string $message The warning to record.
     *
     *
     * @since  1.8.0
     */
    private static function log(string $message): void
    {
        Log::add('Alfa admin events: ' . $message, Log::WARNING, 'com_alfa.admin');
    }
}
