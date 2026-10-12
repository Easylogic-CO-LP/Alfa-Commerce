<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Panel;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Throwable;

/**
 * The one way the shared Panel MVC calls into alfa* plugins: boot-and-call, the same
 * pattern the admin contract's dispatcher uses, factored out so every panel event
 * fires identically.
 *
 * Two shapes:
 *
 *  - {@see self::toOwner()}: a PANEL request already knows its owner (group + element in
 *    the URL), so it boots exactly that plugin and calls one method. Targeted; nothing
 *    else is booted.
 *  - {@see self::broadcast()}: SHARED backend discovery. The menu and the ACL action list
 *    belong to no single panel, so the `alfa` PLATFORM group is asked as a whole. Only that
 *    group: capability modules (alfa-search, alfa-payments, alfa-shipments, ...) own their own
 *    domain screens but never participate in shared discovery, so opening any Alfa admin page
 *    cannot wake a search engine or a payment gateway. A capability that needs to be announced
 *    in the shared backend ships a platform adapter in the `alfa` group; plg_alfa_enterprise-search
 *    is the worked example.
 *
 * A plugin that throws is logged and skipped, never allowed to empty the menu or break a
 * screen.
 *
 * @since  1.7.0
 */
final class PanelDispatcher
{
    /**
     * The one plugin group the Alfa backend belongs to: the platform extension layer. Shared
     * discovery asks it, and {@see PanelResolver} lets only it own a panel; capability groups
     * (alfa-payments, alfa-search, ...) are domain logic and ship their backend as a platform
     * plugin of their own.
     *
     * @var string
     * @since  1.7.0
     */
    public const PLATFORM_GROUP = 'alfa';

    /**
     * Fire one event at the plugin that owns this panel.
     *
     * @param PanelIdentity $identity The resolved owner.
     * @param AbstractEvent $event The event to hand it.
     * @param string $method The listener method (its own name, e.g. onAlfaPanelSave).
     * @param bool $silent Whether to absorb a plugin exception. DISPLAY calls
     *                     (menu, view) pass true so a broken plugin can never blank
     *                     a page; MUTATION calls (save, delete, task) pass false so
     *                     a throw surfaces to the controller instead of looking like
     *                     a successful no-op.
     *
     * @return AbstractEvent The same event, after the owner has (maybe) mutated it.
     *
     * @throws Throwable When $silent is false and the owner's handler throws.
     *
     * @since  1.7.0
     */
    public static function toOwner(PanelIdentity $identity, AbstractEvent $event, string $method, bool $silent = true): AbstractEvent
    {
        try {
            $plugin = PanelResolver::boot($identity);

            if (method_exists($plugin, $method)) {
                $plugin->$method($event);
            }
        } catch (Throwable $e) {
            self::log($identity->key() . ': ' . $method . ': ' . $e->getMessage());

            if (!$silent) {
                throw $e;
            }
        }

        return $event;
    }

    /**
     * Fire one event at every enabled plugin of the `alfa` platform group.
     *
     * @param AbstractEvent $event The event to hand each plugin.
     * @param string $method The listener method (e.g. onAlfaBuildMenu).
     *
     * @return AbstractEvent The same event, after all plugins have (maybe) mutated it.
     *
     * @since  1.7.0
     */
    public static function broadcast(AbstractEvent $event, string $method): AbstractEvent
    {
        $app = Factory::getApplication();

        foreach (self::plugins() as $descriptor) {
            try {
                $plugin = $app->bootPlugin($descriptor->element, $descriptor->folder);

                if (!method_exists($plugin, $method)) {
                    continue;
                }

                // The plugin's own language, so any keys it contributes (menu titles,
                // action labels) resolve where the host prints them. Through the ONE loader:
                // an installed plugin's INI lives under administrator/language/, and a
                // plugin-folder-only load finds nothing there.
                AlfaHelper::loadPluginLanguage(group: $descriptor->folder, element: $descriptor->element);

                $plugin->$method($event);
            } catch (Throwable $e) {
                self::log($descriptor->folder . '/' . $descriptor->element . ': ' . $method . ': ' . $e->getMessage());
            }
        }

        return $event;
    }

    /**
     * The enabled plugins of the `alfa` platform group, as {folder, element} rows.
     *
     * Joomla has already loaded this list (AlfaComponent::boot() imports the group before the
     * component dispatches), so shared discovery costs no query and no boot, and it inherits
     * PluginHelper's rules: the access-level filter and the admin-configured plugin ordering.
     *
     * @return object[] Rows of {folder, element}.
     *
     * @since  1.7.0
     */
    private static function plugins(): array
    {
        try {
            $rows = [];

            foreach (PluginHelper::getPlugin(self::PLATFORM_GROUP) as $plugin) {
                $rows[] = (object) ['folder' => self::PLATFORM_GROUP, 'element' => $plugin->name];
            }

            return $rows;
        } catch (Throwable $e) {
            self::log('could not enumerate platform plugins: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param string $message The warning to record.
     *
     *
     * @since  1.7.0
     */
    private static function log(string $message): void
    {
        Log::add('Alfa Panel: ' . $message, Log::WARNING, 'com_alfa.panel');
    }
}
