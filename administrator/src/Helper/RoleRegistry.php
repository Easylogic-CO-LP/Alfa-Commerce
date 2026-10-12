<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * The identity ROLES a form field can carry, and what each one means.
 *
 * A role is the semantic key consumers ask for ("the customer's email")
 * instead of guessing a column by its name. The role lives on the
 * `#__alfa_form_fields` row (`role`, UNIQUE); this registry declares, per
 * role, whether the row is locked (system), which `#__users` column the
 * ACCOUNT owns for a logged-in customer, and the visibility floors the
 * merchant cannot go below. It is code, not a table: two sources that can
 * disagree are worse than one authority.
 *
 * Identity vs address: `#__users` is the account (name, email, username,
 * password); `#__alfa_user_info` rows are delivery/invoice records and carry
 * the RECIPIENT's name and phone. A member's email is never stored on a
 * record (the account's address is authoritative); a guest's is.
 *
 * @since  1.10.0
 */
final class RoleRegistry
{
    public const NAME = 'name';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const COUNTRY = 'country';

    /**
     * Per role: system lock, the `#__users` column the account owns for
     * members (null = none), and the visibility floors per section
     * (0 hidden, 1 optional, 2 required) the editor may not go below.
     *
     * The floors are a CAPTURE contract for new orders: a delivery record
     * needs a recipient name, and a guest order without an email can be
     * neither confirmed nor invoiced nor served its downloads.
     */
    private const ROLES = [
        self::NAME => [
            'system' => true,
            'users' => 'name',
            'floors' => ['registration' => 2, 'shipping' => 2, 'billing' => 0],
        ],
        self::EMAIL => [
            'system' => true,
            'users' => 'email',
            'floors' => ['registration' => 2, 'shipping' => 2, 'billing' => 0],
        ],
        self::PHONE => [
            'system' => false,
            'users' => null,
            'floors' => ['registration' => 0, 'shipping' => 0, 'billing' => 0],
        ],
        // The delivery place shipping zones, taxes and method scopes are
        // judged against. Product-known but never locked: a single-country
        // shop may drop it. (Its VALUE is resolved by field type (see
        // FieldsHelper::PLACE_FIELD_TYPE); the role marks the shipped row.)
        self::COUNTRY => [
            'system' => false,
            'users' => null,
            'floors' => ['registration' => 0, 'shipping' => 0, 'billing' => 0],
        ],
    ];

    /** @var array<string, object|null> Role => field row, memoised per request. */
    private static array $rows = [];

    /**
     * Every declared role.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * Whether a value is a declared role.
     *
     * @param string|null $role The candidate.
     *
     *
     * @since  1.10.0
     */
    public static function isRole(?string $role): bool
    {
        return $role !== null && isset(self::ROLES[$role]);
    }

    /**
     * Whether the role's row is locked (product-owned definition).
     *
     * @param string $role The role.
     *
     *
     * @since  1.10.0
     */
    public static function isSystem(string $role): bool
    {
        return (bool) (self::ROLES[$role]['system'] ?? false);
    }

    /**
     * The minimum visibility level the merchant may set for a role in a
     * section (`registration` | `shipping` | `billing`).
     *
     * @param string $role The role.
     * @param string $section The section column.
     *
     * @return int 0 hidden, 1 optional, 2 required.
     *
     * @since  1.10.0
     */
    public static function floor(string $role, string $section): int
    {
        return (int) (self::ROLES[$role]['floors'][$section] ?? 0);
    }

    /**
     * The `#__users` column the ACCOUNT owns for this role, for members.
     *
     * @param string $role The role.
     *
     * @return string|null Column name, or null when the role is record-only (phone).
     *
     * @since  1.10.0
     */
    public static function accountColumn(string $role): ?string
    {
        return self::ROLES[$role]['users'] ?? null;
    }

    /**
     * Whether a member's value for this role lives on the ACCOUNT only and
     * must never be stored on a `#__alfa_user_info` record.
     *
     * Email: the account's address is authoritative and edited in the
     * profile; a copy on a record would go stale and be read as truth.
     * Name is stored: the recipient of a delivery may differ from the
     * account holder.
     *
     * @param string $role The role.
     * @param int $userId The owner of the record (0 = guest).
     *
     *
     * @since  1.10.0
     */
    public static function accountOwned(string $role, int $userId): bool
    {
        return $userId > 0 && $role === self::EMAIL;
    }

    /**
     * Whether a role's field is left OUT of a form for this customer.
     *
     * - Email is not rendered for members outside registration: the account
     *   owns it (see accountOwned()). A field that is never injected is
     *   neither required nor posted, so nothing downstream has to special-case it.
     * - On the registration form Joomla's own name / email inputs render the
     *   identity until the core fields go through this pipeline; the rows are
     *   skipped there so nothing is asked twice. (Transitional: removed when
     *   registration renders through the pipeline.)
     *
     * @param string $role The role.
     * @param string $context The FieldsHelper context.
     * @param int $userId The customer the form is built for (0 = guest).
     *
     *
     * @since  1.10.0
     */
    public static function hiddenIn(string $role, string $context, int $userId): bool
    {
        if ($context === 'user.register') {
            return $role === self::NAME || $role === self::EMAIL;
        }

        return $role === self::EMAIL && $userId > 0;
    }

    /**
     * The field row carrying a role (any state: a system row is always
     * published; a default row may be unpublished, and a consumer must then
     * still find its column for historical records).
     *
     * @param string $role The role.
     *
     * @return object|null The `#__alfa_form_fields` row, or null when no field carries the role.
     *
     * @since  1.10.0
     */
    public static function field(string $role): ?object
    {
        if (array_key_exists($role, self::$rows)) {
            return self::$rows[$role];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_form_fields'))
            ->where($db->quoteName('role') . ' = :role')
            ->bind(':role', $role, ParameterType::STRING);

        $db->setQuery($query, 0, 1);

        return self::$rows[$role] = ($db->loadObject() ?: null);
    }

    /**
     * The `#__alfa_user_info` column holding a role's value.
     *
     * @param string $role The role.
     *
     * @return string|null The column (field_name), or null when no field carries the role.
     *
     * @since  1.10.0
     */
    public static function column(string $role): ?string
    {
        $field = self::field($role);

        return $field ? (string) $field->field_name : null;
    }

    /**
     * The role values held by a `#__alfa_user_info` record.
     *
     * @param object|null $row The record (null = nothing).
     *
     * @return array<string, string> Role => trimmed value ('' when absent).
     *
     * @since  1.10.0
     */
    public static function valuesFromRow(?object $row): array
    {
        $values = [];

        foreach (self::roles() as $role) {
            $column = self::column($role);
            $values[$role] = ($row !== null && $column !== null && isset($row->$column))
                ? trim((string) $row->$column)
                : '';
        }

        return $values;
    }

    /**
     * The role values inside a submitted field bucket (field_name => value).
     *
     * @param array $data The bucket.
     *
     * @return array<string, string> Role => trimmed value ('' when absent).
     *
     * @since  1.10.0
     */
    public static function valuesFromData(array $data): array
    {
        return self::valuesFromRow((object) $data);
    }

    /**
     * The label shown for a role's field when its translation row is missing
     * (a site whose content language was never given the seeded labels).
     *
     * @param string $role The role.
     *
     *
     * @since  1.10.0
     */
    public static function labelFallback(string $role): string
    {
        return Text::_('COM_ALFA_SYSTEM_FIELD_' . strtoupper($role));
    }

    /**
     * Forget the memoised rows (after a field save in the same request).
     *
     *
     * @since  1.10.0
     */
    public static function clearCache(): void
    {
        self::$rows = [];
    }
}
