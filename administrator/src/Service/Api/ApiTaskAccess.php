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
 * What a task of a core REST controller asks before it answers: the permissions on com_alfa, each one required,
 * or that the record decides. The controller's check (HasApiGuards::assertAuthorisedForTask()) and the API routes
 * page (ApiAddressListing) read the same declaration, so the two cannot differ.
 *
 * @since  2.3.0
 */
final class ApiTaskAccess
{
    /**
     * @param list<string> $permissions The permissions the task asks on com_alfa, each one required (alfa.items.view,
     *                                  alfa.tools).
     * @param bool $perRecord Whether the task decides who may act after it reads the record (a task is read by the
     *                        account that made it); the permissions listed are then asked by the task itself, after
     *                        the record.
     *
     * @since  2.3.0
     */
    private function __construct(
        public readonly array $permissions,
        public readonly bool $perRecord,
    ) {
    }

    /**
     * A task that asks these permissions before it runs, each one required.
     *
     * @param string ...$permissions The permissions, alfa.<section>.<action> or alfa.tools.
     *
     * @since  2.3.0
     */
    public static function asks(string ...$permissions): self
    {
        return new self(permissions: array_values($permissions), perRecord: false);
    }

    /**
     * A task whose record decides who may act; it asks these permissions itself, after the record.
     *
     * @param string ...$permissions The permissions the task asks besides the record's own rule.
     *
     * @since  2.3.0
     */
    public static function perRecord(string ...$permissions): self
    {
        return new self(permissions: array_values($permissions), perRecord: true);
    }
}
