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
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\User\User;

/**
 * The one result accumulator of onAlfaAdminListRowActions and onAlfaAdminListBulkActions:
 * the same store either way, because a row action and a bulk action are the same descriptor
 * pointed at one record or at the checked ones. The event a handler subscribes to is what
 * decides which, so a plugin never has to say so twice.
 *
 * Mirrors {@see AdminColumns}: every action() opens a NEW builder stamped with the current
 * contributor, and getActions() hands the framework plain descriptors in insertion order,
 * dropping any contribution that would render as a dead control, and any the user may not use.
 *
 * @since  2.0.0
 */
final class AdminActions
{
    /**
     * @var ActionBuilder[] Contributed actions, insertion order.
     * @since  2.0.0
     */
    private array $actions = [];

    /**
     * Declare an action.
     *
     * @param string $contributor The declaring plugin, group.element (2.2.0): its tmpl folder is the
     *                            action's layout default, and its identity the permission derivation's.
     * @param string $key The action's identity key (unique per contributor).
     *
     * @return ActionBuilder For chaining.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function action(string $contributor, string $key): ActionBuilder
    {
        $owner = PluginId::of($contributor);
        $action = new ActionBuilder(key: $key);

        $action->setPluginType($owner->group)->setPluginName($owner->element);

        $this->actions[] = $action;

        return $action;
    }

    /**
     * The declared actions this user may use, as plain descriptors: insertion order, only
     * contributions that actually do something (see {@see ActionBuilder::isActionable()}) and whose
     * permission the user holds (see {@see ActionBuilder::permission()}).
     *
     * @param User $user The user the list is drawn for.
     *
     * @return array[] Action descriptors (see ActionBuilder::toDescriptor()).
     *
     * @since  2.0.0
     */
    public function getActions(User $user): array
    {
        $out = [];

        foreach ($this->actions as $action) {
            if ($action->isActionable() && self::permitted(permission: $action->permission(), user: $user)) {
                $out[] = $action->toDescriptor();
            }
        }

        return $out;
    }

    /**
     * Whether the user holds an action's permission. A permission nothing could determine is
     * never held: the action stays hidden until its plugin declares one.
     *
     * @param array|null $permission What ActionBuilder::permission() answered.
     * @param User $user The user.
     *
     *
     * @since  2.0.0
     */
    private static function permitted(?array $permission, User $user): bool
    {
        if ($permission === null) {
            return false;
        }

        if ($permission === []) {
            return true;
        }

        if (isset($permission['section'])) {
            return AlfaHelper::can(section: $permission['section'], action: $permission['action'], user: $user);
        }

        if (isset($permission['screen'])) {
            return AlfaHelper::mayOpenScreen(view: $permission['screen'], layout: $permission['layout'], id: $permission['id'], user: $user);
        }

        return (bool) $user->authorise($permission['action'], $permission['asset']);
    }
}
