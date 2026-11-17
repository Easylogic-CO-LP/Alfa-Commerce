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
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\LoadedPlugin;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\Input\Input;

/**
 * Turns a request into a {@see PanelIdentity} and boots the plugin behind one: the two
 * side-effecting operations kept OFF the identity value object so nothing that merely holds
 * an identity can reach the database or boot a plugin through it.
 *
 * {@see self::fromInput()} enforces the platform boundary at the source of truth: three
 * reserved params must be present, the group must be the `alfa` PLATFORM group, and the plugin
 * must be installed and enabled in #__extensions; otherwise it returns null and a crafted URL
 * can never reach code that is not a live platform plugin. {@see self::boot()} then loads that plugin's namespace
 * and language when a screen is actually served.
 *
 * @since  2.0.0
 */
final class PanelResolver
{
    /**
     * Resolve the identity from request input, or null when it is absent, out of the
     * alfa* boundary, or not a live plugin.
     *
     * @param Input $input Request input.
     *
     *
     * @since  2.0.0
     */
    public static function fromInput(Input $input): ?PanelIdentity
    {
        $group = RequestScalar::cmd($input, 'group');
        $element = RequestScalar::cmd($input, 'element');
        $panel = RequestScalar::cmd($input, 'panel');

        if ($group === '' || $element === '' || $panel === '') {
            return null;
        }

        // The platform boundary: an admin screen belongs to the PLATFORM group. Capability
        // groups (alfa-payments, alfa-search, ...) are search/payment/shipping LOGIC and own no
        // backend; a capability's screens ship as a platform plugin of their own.
        if ($group !== PanelDispatcher::PLATFORM_GROUP) {
            return null;
        }

        if (!self::isLivePlugin($group, $element)) {
            return null;
        }

        return new PanelIdentity($group, $element, $panel);
    }

    /**
     * Boot the owning plugin through the plugin helper (2.2.0, mode B: Joomla's list asked, so a disabled or missing
     * owner is never booted), load its language from where an installed plugin keeps it (Joomla auto-loads the
     * component's language on this page but never a plugin's), and return the helper's handle on it.
     *
     * @param PanelIdentity $identity The panel whose plugin to boot.
     *
     * @return LoadedPlugin The booted owner, for dispatchTo().
     *
     * @throws PluginUnavailable When the owner cannot be booted.
     *
     * @since  2.0.0
     */
    public static function boot(PanelIdentity $identity): LoadedPlugin
    {
        $plugin = AlfaPluginHelper::get()->bootSingle(PluginId::from($identity->group, $identity->element));

        // Through the ONE loader: administrator/language/ first, where an installed
        // plugin's INI actually lives, then the plugin's own folder.
        AlfaHelper::loadPluginLanguage(group: $identity->group, element: $identity->element);

        return $plugin;
    }

    /**
     * Is this an installed, enabled plugin in an alfa* group? Asked of the plugin helper, the one availability
     * authority (2.2.0: PluginHelper::isEnabled(), the list a plugin's provider reads its configuration from), so an
     * identity can never name a plugin that is not live for this request.
     *
     * @param string $group Plugin group.
     * @param string $element Plugin element.
     *
     * @since  2.0.0
     */
    private static function isLivePlugin(string $group, string $element): bool
    {
        if (!str_starts_with($group, 'alfa')) {
            return false;
        }

        try {
            return AlfaPluginHelper::get()->isAvailable(PluginId::from($group, $element));
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
