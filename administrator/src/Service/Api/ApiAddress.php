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

/**
 * One address of the REST API as Tools, Security lists it: who adds it, its methods and pattern, the
 * permissions it asks and who holds them.
 *
 * @since  2.2.2
 */
final class ApiAddress
{
    /**
     * @param string $source ApiAddressListing::SOURCE_CORE, or ApiAddressListing::SOURCE_PLUGIN and the plugin's element.
     * @param string $sourceName Who adds it, as the screen names it.
     * @param list<string> $methods The HTTP methods, upper case.
     * @param string $pattern The route's pattern, v1/alfa/...
     * @param string $address The full address.
     * @param list<string> $permissions The permissions it asks on com_alfa, each one required.
     * @param bool $perRecord Whether the record decides who may act ({@see ApiTaskAccess::$perRecord}).
     * @param bool $declared Whether its controller declares what it asks; false for a task no Alfa check maps.
     * @param list<array{id: int, title: string, superUsers: bool, apiLogin: bool, tokenGroup: bool, accounts: list<array{id: int, name: string, username: string, superUser: bool}>}> $groups The groups that hold every permission ({@see ApiPermissionHolders::groupsHolding()}).
     * @param list<array{id: int, name: string, username: string, superUser: bool}> $accounts The accounts in those groups with an enabled token, each once.
     * @param string $mediaType The one media type it answers.
     * @param int|null $maxBody The largest body it takes, in bytes; null when the server's own limit applies.
     *
     * @since  2.2.2
     */
    public function __construct(
        public readonly string $source,
        public readonly string $sourceName,
        public readonly array $methods,
        public readonly string $pattern,
        public readonly string $address,
        public readonly array $permissions,
        public readonly bool $perRecord,
        public readonly bool $declared,
        public readonly array $groups,
        public readonly array $accounts,
        public readonly string $mediaType,
        public readonly ?int $maxBody,
    ) {
    }

    /**
     * Whether it is added by a plugin rather than by Alfa Commerce.
     *
     * @since  2.2.2
     */
    public function fromPlugin(): bool
    {
        return $this->source !== ApiAddressListing::SOURCE_CORE;
    }
}
