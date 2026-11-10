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

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Log\Log;
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
 *    belong to no single panel, so they are raised on the shared dispatcher (2.2.0, mode A),
 *    after the `alfa` PLATFORM group is imported: every enabled and loaded listener hears them,
 *    whatever its group. Capability modules (alfa-search, alfa-payments, alfa-shipments, ...) are
 *    not imported for it, so opening any Alfa admin page cannot wake a search engine or a payment
 *    gateway; a capability that needs to be announced in the shared backend ships a platform
 *    adapter in the `alfa` group (plg_alfa_enterprise-search is the worked example).
 *
 * A listener that throws is logged and the next one runs (Alfa's isolated policy), never allowed
 * to empty the menu or break a screen.
 *
 * @since  2.0.0
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
     * @since  2.0.0
     */
    public const PLATFORM_GROUP = 'alfa';

    /**
     * Fire one event at the plugin that owns this panel.
     *
     * @param PanelIdentity $identity The resolved owner.
     * @param AbstractEvent $event The event to hand it.
     * @param string $method The event's name (e.g. onAlfaPanelSave), named in the log; the plugin's declaration maps it
     *                       to its handler (2.2.0).
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
     * @since  2.0.0
     */
    public static function toOwner(PanelIdentity $identity, AbstractEvent $event, string $method, bool $silent = true): AbstractEvent
    {
        // The panel's owner alone, through the plugin helper (2.2.0, mode B): its own declaration of the event. A
        // display asks first and skips an owner without the hook; a mutation addressed to an owner that declares no
        // handler is the helper's NO_HANDLER, an error the controller shows, never a silent success (A-C14B-12). The
        // handler's failure is absorbed for a display and propagates for a mutation.
        try {
            $plugins = AlfaPluginHelper::get();
            $plugin = PanelResolver::boot($identity);

            if (!$silent || $plugins->declares($plugin, $event->getName())) {
                $plugins->dispatchTo($plugin, $event);
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
     * Raise a menu or access event on the shared dispatcher (2.2.0, design 2.15.3): mode A, a display phase, under
     * Alfa's isolated policy, the alfa group imported first. Every enabled and loaded listener hears it, whatever its
     * group; each contributing plugin names itself in the event's typed methods and loads its own language.
     *
     * @param AbstractEvent $event The event (onAlfaBuildMenu or onAlfaGetAccessActions).
     *
     * @return AbstractEvent The same event, after its listeners ran.
     *
     * @since  2.0.0
     */
    public static function broadcast(AbstractEvent $event): AbstractEvent
    {
        $plugins = AlfaPluginHelper::get();
        $plugins->importGroup(self::PLATFORM_GROUP);
        $plugins->dispatchIsolated($event);

        return $event;
    }

    /**
     * @param string $message The warning to record.
     *
     *
     * @since  2.0.0
     */
    private static function log(string $message): void
    {
        Log::add('Alfa Panel: ' . $message, Log::WARNING, 'com_alfa.panel');
    }
}
