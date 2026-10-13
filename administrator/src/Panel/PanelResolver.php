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
use Joomla\CMS\Factory;
use Joomla\Input\Input;
use Throwable;

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
        $group = $input->getCmd('group', '');
        $element = $input->getCmd('element', '');
        $panel = $input->getCmd('panel', '');

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
     * Boot the owning plugin so its namespace autoloads, load its language from its own
     * folder (Joomla auto-loads the component's language on this page but never a
     * plugin's), and return the booted instance.
     *
     * @param PanelIdentity $identity The panel whose plugin to boot.
     *
     * @return object The booted plugin instance.
     *
     * @since  2.0.0
     */
    public static function boot(PanelIdentity $identity): object
    {
        $app = Factory::getApplication();
        $plugin = $app->bootPlugin($identity->element, $identity->group);

        // Through the ONE loader: administrator/language/ first, where an installed
        // plugin's INI actually lives, then the plugin's own folder.
        AlfaHelper::loadPluginLanguage(group: $identity->group, element: $identity->element);

        return $plugin;
    }

    /**
     * Is this an installed, enabled plugin in an alfa* group? Guards the boundary at the
     * source of truth (#__extensions) so an identity can never name a plugin that is not
     * live.
     *
     * @param string $group Plugin group.
     * @param string $element Plugin element.
     *
     *
     * @since  2.0.0
     */
    private static function isLivePlugin(string $group, string $element): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('folder') . ' = :group')
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%'))
                ->bind(':group', $group)
                ->bind(':element', $element);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
