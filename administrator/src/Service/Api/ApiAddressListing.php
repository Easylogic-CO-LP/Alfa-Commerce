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

use Alfa\Component\Alfa\Administrator\Event\Access\GetAccessActionsEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Every address of the REST API and who may call it, for the table of Tools, Security: core's routes
 * ({@see ApiRouteRegistrar::collectCore()}) and the plugins' ({@see ApiRouteRegistrar::collect()}), the registrar's
 * own readings, each with the permissions it asks and the groups and token holders of {@see ApiPermissionHolders}.
 * The table cannot say other than the router and the controllers' checks: it reads what they read.
 *
 * @since  2.2.2
 */
final class ApiAddressListing
{
    /**
     * The source of core's addresses.
     *
     * @since  2.2.2
     */
    public const SOURCE_CORE = 'core';

    /**
     * The start of a plugin's source, then its element.
     *
     * @since  2.2.2
     */
    public const SOURCE_PLUGIN = 'plugin.';

    /**
     * Read every address: core's in the webservices plugin's order, then each plugin's; the declarations core refused
     * and the plugins that cannot be loaded; the permissions' titles; whether the token's sign-in and the webservices
     * plugin are enabled. Building it boots the webservices plugin, the REST controllers and the alfa plugins that
     * declare routes, on this screen only.
     *
     * @return array{addresses: list<ApiAddress>, refused: list<array{name: string, refusal: ApiRouteRefusal}>, unavailable: list<array{name: string, reason: string}>, titles: array<string, string>, tokenPlugin: bool, webservices: bool}
     *
     * @since  2.2.2
     */
    public static function read(): array
    {
        $root = rtrim(Uri::root(), '/') . '/api/index.php/';
        $holders = new ApiPermissionHolders(Factory::getContainer()->get(DatabaseInterface::class));
        $core = Text::_('COM_ALFA_TOOLS_API_ROUTES_CORE');
        $addresses = [];

        foreach (ApiRouteRegistrar::collectCore() as $entry) {
            $access = $entry['access'];
            $permissions = $access->permissions ?? [];
            $groups = $holders->groupsHolding(permissions: $access === null || $access->perRecord ? [] : $permissions);
            $addresses[] = new ApiAddress(
                source: self::SOURCE_CORE,
                sourceName: $core,
                methods: array_values(array_map('strval', $entry['route']->getMethods())),
                pattern: $entry['route']->getPattern(),
                address: $root . $entry['route']->getPattern(),
                permissions: $permissions,
                perRecord: $access !== null && $access->perRecord,
                declared: $access !== null,
                groups: $groups,
                accounts: self::accounts(groups: $groups),
            );
        }

        $refused = [];
        $unavailable = [];

        foreach (ApiRouteRegistrar::collect() as $plugin) {
            AlfaHelper::loadPluginLanguage(group: PanelDispatcher::PLATFORM_GROUP, element: $plugin['element']);

            if ($plugin['unavailable'] !== '') {
                $unavailable[] = ['name' => $plugin['name'], 'reason' => $plugin['unavailable']];

                continue;
            }

            foreach ($plugin['refused'] as $refusal) {
                $refused[] = ['name' => $plugin['name'], 'refusal' => $refusal];
            }

            foreach ($plugin['accepted'] as $declaration) {
                $groups = $holders->groupsHolding(permissions: [$declaration->permission]);
                $addresses[] = new ApiAddress(
                    source: self::SOURCE_PLUGIN . $plugin['element'],
                    sourceName: $plugin['name'],
                    methods: $declaration->methods,
                    pattern: $declaration->pattern,
                    address: $root . $declaration->pattern,
                    permissions: [$declaration->permission],
                    perRecord: false,
                    declared: true,
                    groups: $groups,
                    accounts: self::accounts(groups: $groups),
                );
            }
        }

        return [
            'addresses' => $addresses,
            'refused' => $refused,
            'unavailable' => $unavailable,
            'titles' => self::titles(addresses: $addresses),
            'tokenPlugin' => PluginHelper::isEnabled('api-authentication', 'token'),
            'webservices' => PluginHelper::isEnabled('webservices', ApiRouteRegistrar::WEBSERVICES_PLUGIN),
        ];
    }

    /**
     * The addresses a filter keeps: of one source, asking one permission, with the search in the address (any case).
     * An empty filter keeps every address.
     *
     * @param list<ApiAddress> $addresses The addresses.
     * @param string $source A source, or ''.
     * @param string $permission A permission, or ''.
     * @param string $search Words to find in the address, or ''.
     *
     * @return list<ApiAddress>
     *
     * @since  2.2.2
     */
    public static function filter(array $addresses, string $source, string $permission, string $search): array
    {
        $search = trim($search);

        return array_values(array_filter($addresses, static fn (ApiAddress $address): bool => ($source === '' || $address->source === $source)
            && ($permission === '' || \in_array($permission, $address->permissions, true))
            && ($search === '' || mb_stripos($address->address, $search) !== false)));
    }

    /**
     * The counts the table opens with: the addresses, those plugins add, the accounts with an enabled token that hold
     * the permission of at least one, and the Super Users among them.
     *
     * @param list<ApiAddress> $addresses The addresses.
     *
     * @return array{addresses: int, plugins: int, accounts: int, superUsers: int}
     *
     * @since  2.2.2
     */
    public static function summary(array $addresses): array
    {
        $accounts = [];

        foreach ($addresses as $address) {
            foreach ($address->accounts as $account) {
                $accounts[$account['id']] = $account['superUser'];
            }
        }

        return [
            'addresses' => \count($addresses),
            'plugins' => \count(array_filter($addresses, static fn (ApiAddress $address): bool => $address->fromPlugin())),
            'accounts' => \count($accounts),
            'superUsers' => \count(array_filter($accounts)),
        ];
    }

    /**
     * The sources the addresses come from, in their order: source => name.
     *
     * @param list<ApiAddress> $addresses The addresses.
     *
     * @return array<string, string>
     *
     * @since  2.2.2
     */
    public static function sources(array $addresses): array
    {
        $sources = [];

        foreach ($addresses as $address) {
            $sources[$address->source] ??= $address->sourceName;
        }

        return $sources;
    }

    /**
     * The accounts of some groups, each once, in the groups' order.
     *
     * @param list<array{id: int, title: string, superUsers: bool, apiLogin: bool, tokenGroup: bool, accounts: list<array{id: int, name: string, username: string, superUser: bool}>}> $groups The groups.
     *
     * @return list<array{id: int, name: string, username: string, superUser: bool}>
     *
     * @since  2.2.2
     */
    private static function accounts(array $groups): array
    {
        $accounts = [];

        foreach ($groups as $group) {
            foreach ($group['accounts'] as $account) {
                $accounts[$account['id']] ??= $account;
            }
        }

        return array_values($accounts);
    }

    /**
     * The title of every permission the addresses ask, as Options, Permissions names it: core's from access.xml, a
     * plugin's from its onAlfaGetAccessActions; a permission declared nowhere keeps its name only (title '').
     *
     * @param list<ApiAddress> $addresses The addresses.
     *
     * @return array<string, string>
     *
     * @since  2.2.2
     */
    private static function titles(array $addresses): array
    {
        $titles = [];

        foreach ($addresses as $address) {
            foreach ($address->permissions as $permission) {
                $titles[$permission] = '';
            }
        }

        foreach (Access::getActionsFromFile(JPATH_ADMINISTRATOR . '/components/com_alfa/access.xml') ?: [] as $action) {
            if (isset($titles[(string) $action->name])) {
                $titles[(string) $action->name] = Text::_((string) $action->title);
            }
        }

        if (\in_array('', $titles, true)) {
            $event = PanelDispatcher::broadcast(new GetAccessActionsEvent('onAlfaGetAccessActions', []));

            if ($event instanceof GetAccessActionsEvent) {
                foreach ($event->all() as $action) {
                    if (($titles[$action->name] ?? null) === '') {
                        $titles[$action->name] = Text::_((string) $action->title);
                    }
                }
            }
        }

        return $titles;
    }
}
