<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Api;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Who holds the permissions of the REST API's addresses (2.2.2), for Tools, Security: the user groups granted them,
 * whether each may sign in to the API with a token, and the accounts in each that have an enabled Joomla API token.
 * One computation for every address, core's and the plugins', each group and account read once.
 *
 * @since  2.2.2
 */
final class ApiPermissionHolders
{
    /**
     * The user groups, in their tree's order: id => title.
     *
     * @var array<int, string>
     * @since  2.2.2
     */
    private array $groups;

    /**
     * The groups the token's sign-in takes: the Allowed User Groups of the plugin User - Joomla API Token.
     *
     * @var list<int>
     * @since  2.2.2
     */
    private array $tokenGroups;

    /**
     * Each group's facts once read: title, superUsers, apiLogin, tokenGroup, accounts.
     *
     * @var array<int, array{id: int, title: string, superUsers: bool, apiLogin: bool, tokenGroup: bool, accounts: list<array{id: int, name: string, username: string, superUser: bool}>}>
     * @since  2.2.2
     */
    private array $facts = [];

    /**
     * Each permission's groups once read: permission => group ids.
     *
     * @var array<string, list<int>>
     * @since  2.2.2
     */
    private array $holders = [];

    /**
     * Whether each account read is a Super User: user id => bool.
     *
     * @var array<int, bool>
     * @since  2.2.2
     */
    private array $superUsers = [];

    /**
     * Read the user groups and the token's groups.
     *
     * @param DatabaseInterface $db The database.
     *
     * @since  2.2.2
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
        $this->groups = array_map('strval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'title']))
                ->from($db->quoteName('#__usergroups'))
                ->order($db->quoteName('lft')),
        )->loadAssocList('id', 'title') ?: []);

        // The token's sign-in (API Authentication - Web Services Joomla Token) reads the allowed groups from the plugin
        // User - Joomla API Token, Super Users by default.
        $userToken = PluginHelper::getPlugin('user', 'token');
        $this->tokenGroups = array_values(array_map('intval', (array) (new Registry(\is_object($userToken) ? (string) ($userToken->params ?? '') : ''))->get('allowedUserGroups', [8])));
    }

    /**
     * The groups that hold every one of these permissions on com_alfa (a group with core.admin holds them all), in the
     * tree's order, each with whether it may sign in to the API (API Login, a Super Users group always), whether it is
     * among the token's groups, and its accounts with an enabled token. No permission, no group.
     *
     * @param list<string> $permissions The permissions, each one required.
     *
     * @return list<array{id: int, title: string, superUsers: bool, apiLogin: bool, tokenGroup: bool, accounts: list<array{id: int, name: string, username: string, superUser: bool}>}>
     *
     * @since  2.2.2
     */
    public function groupsHolding(array $permissions): array
    {
        if ($permissions === []) {
            return [];
        }

        $ids = null;

        foreach ($permissions as $permission) {
            $holding = $this->holders[$permission] ??= $this->read(permission: $permission);
            $ids = $ids === null ? $holding : array_values(array_intersect($ids, $holding));
        }

        return array_map(fn (int $id): array => $this->facts($id), $ids ?? []);
    }

    /**
     * The groups that hold one permission, in the tree's order.
     *
     * @param string $permission The permission.
     *
     * @return list<int>
     *
     * @since  2.2.2
     */
    private function read(string $permission): array
    {
        $ids = [];

        foreach (array_keys($this->groups) as $id) {
            if (Access::checkGroup((int) $id, 'core.admin') === true || Access::checkGroup((int) $id, $permission, 'com_alfa') === true) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * A group's facts, read once.
     *
     * @param int $id The group.
     *
     * @return array{id: int, title: string, superUsers: bool, apiLogin: bool, tokenGroup: bool, accounts: list<array{id: int, name: string, username: string, superUser: bool}>}
     *
     * @since  2.2.2
     */
    private function facts(int $id): array
    {
        if (isset($this->facts[$id])) {
            return $this->facts[$id];
        }

        $superUsers = Access::checkGroup($id, 'core.admin') === true;

        return $this->facts[$id] = [
            'id' => $id,
            'title' => $this->groups[$id] ?? (string) $id,
            'superUsers' => $superUsers,
            'apiLogin' => $superUsers || Access::checkGroup($id, 'core.login.api') === true,
            'tokenGroup' => \in_array($id, $this->tokenGroups, true),
            'accounts' => $this->tokenHolders(groupId: $id),
        ];
    }

    /**
     * The accounts directly in a group, not blocked, with an enabled Joomla API token, each marked when it is a Super
     * User.
     *
     * @param int $groupId The group.
     *
     * @return list<array{id: int, name: string, username: string, superUser: bool}>
     *
     * @since  2.2.2
     */
    private function tokenHolders(int $groupId): array
    {
        $db = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['u.id', 'u.name', 'u.username']))
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm') . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
                ->join('INNER', $db->quoteName('#__user_profiles', 'e') . ' ON ' . $db->quoteName('e.user_id') . ' = ' . $db->quoteName('u.id')
                    . ' AND ' . $db->quoteName('e.profile_key') . ' = ' . $db->quote('joomlatoken.enabled')
                    . ' AND ' . $db->quoteName('e.profile_value') . ' = ' . $db->quote('1'))
                ->join('INNER', $db->quoteName('#__user_profiles', 't') . ' ON ' . $db->quoteName('t.user_id') . ' = ' . $db->quoteName('u.id')
                    . ' AND ' . $db->quoteName('t.profile_key') . ' = ' . $db->quote('joomlatoken.token'))
                ->where($db->quoteName('m.group_id') . ' = :group')
                ->where($db->quoteName('u.block') . ' = 0')
                ->bind(':group', $groupId, ParameterType::INTEGER)
                ->order($db->quoteName('u.username')),
        )->loadObjectList() ?: [];

        return array_values(array_map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'username' => (string) $row->username,
            'superUser' => $this->superUsers[(int) $row->id] ??= Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $row->id)->authorise('core.admin'),
        ], $rows));
    }
}
