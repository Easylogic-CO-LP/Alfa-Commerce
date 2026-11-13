<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Whether System - Alfa Commerce Sync runs for a visitor who is not logged in: the one reading the setup checklist's
 * page cache row and the administrator warning for the plugin share.
 *
 * Joomla loads a plugin only for a visitor whose view levels include the plugin's Access. Alfa Commerce Sync keeps a
 * shopper's own pages out of Joomla's page cache, and the page cache only ever stores and serves pages of visitors who
 * are not logged in; so the plugin guards the shop only when it is enabled and its Access is a level such a visitor
 * holds (Public, or any level the guest user group is in). Read from the plugin's extension row, whatever the request's
 * own user may hold.
 *
 * @since  2.2.1
 */
final class SyncPluginState
{
    /**
     * Enabled, with an Access a visitor who is not logged in holds: it runs for that visitor.
     *
     * @var string
     * @since  2.2.1
     */
    public const EFFECTIVE = 'effective';

    /**
     * Enabled, with an Access no visitor who is not logged in holds: Joomla never runs it for that visitor.
     *
     * @var string
     * @since  2.2.1
     */
    public const NOT_FOR_GUESTS = 'not-for-guests';

    /**
     * Installed and disabled.
     *
     * @var string
     * @since  2.2.1
     */
    public const DISABLED = 'disabled';

    /**
     * Not installed.
     *
     * @var string
     * @since  2.2.1
     */
    public const MISSING = 'missing';

    /**
     * The plugin's state now, one of the constants above.
     *
     * @since  2.2.1
     */
    public static function read(): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $folder = 'system';
        $element = 'alfasync';
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('enabled'), $db->quoteName('access')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':folder', $folder, ParameterType::STRING)
                ->bind(':element', $element, ParameterType::STRING),
            0,
            1,
        )->loadObject();

        if ($row === null) {
            return self::MISSING;
        }

        if ((int) $row->enabled !== 1) {
            return self::DISABLED;
        }

        // The view levels of a visitor who is not logged in (user id 0: the guest user group, Public included).
        $guestLevels = array_map('intval', Access::getAuthorisedViewLevels(0));

        return \in_array((int) $row->access, $guestLevels, true) ? self::EFFECTIVE : self::NOT_FOR_GUESTS;
    }
}
