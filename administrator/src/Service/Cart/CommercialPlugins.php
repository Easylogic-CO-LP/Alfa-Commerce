<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Which plugins get a say in what a cart costs.
 *
 * PluginHelper::getPlugin() answers "which plugins may the person making this
 * request see", filtering #__extensions by the AMBIENT identity's view levels.
 * For a shopper that is the right question and the right person. For a cart
 * being operated on somebody's behalf it is neither: the plugins that add
 * charge lines, veto a placement or choose an inventory strategy would then be
 * selected by whoever happens to be at the keyboard, so two members of staff on
 * different view levels would produce two different totals for the same cart.
 * Commercial participation is a fact about the customer's context, not about
 * the operator's account.
 *
 * So this asks the same question about a NAMED person. The filter is otherwise
 * identical to Joomla's (enabled, plugin, state in (0,1), access in that
 * person's view levels, in ordering), which is what makes it a no-op on the
 * storefront, where the named person and the ambient one are the same.
 *
 * It deliberately does NOT cover every plugin call in the component. The
 * ordinary presentational and integration events keep using PluginHelper, and
 * should: this is for the three dispatch points where a listener can change
 * what the customer is charged or whether the order may proceed at all.
 *
 * @since  1.10.0
 */
final class CommercialPlugins
{
    /**
     * @var array<string, object[]> Rows per group and view-level signature.
     * @since  1.10.0
     */
    private static array $cache = [];

    /**
     * The plugins in a group that this customer's context admits.
     *
     * Answers the same shape PluginHelper::getPlugin() does (objects carrying
     * name, type and params), so a caller substitutes it without changing the
     * loop that follows.
     *
     * @param User $customer The person whose context decides participation.
     * @param string $group The plugin folder.
     *
     * @return object[]
     *
     * @since  1.10.0
     */
    public static function for(User $customer, string $group = 'alfa'): array
    {
        $levels = $customer->getAuthorisedViewLevels();

        if ($levels === []) {
            // A person with no view level sees nothing, which is not the same
            // as "no filter at all"; never widen a query by omitting it.
            return [];
        }

        $key = $group . '|' . implode(',', $levels);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('folder', 'type'),
                    $db->quoteName('element', 'name'),
                    $db->quoteName('params'),
                    $db->quoteName('extension_id', 'id'),
                ],
            )
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('state') . ' IN (0, 1)')
            ->where($db->quoteName('folder') . ' = :folder')
            ->whereIn($db->quoteName('access'), $levels)
            ->order($db->quoteName('ordering'))
            ->bind(':folder', $group);

        return self::$cache[$key] = $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Forget what has been read. For test harnesses that change view levels
     * inside one process; production reads each group once per request.
     *
     *
     * @since  1.10.0
     */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
