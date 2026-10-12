<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\Database\ParameterType;

/**
 * Helper for #__alfa_user_info operations.
 *
 * Shared by OrderPlacement (cart checkout) and PlgSystemAlfasync
 * (registration) so that field normalization and persistence logic
 * live in one place.
 *
 * @since  1.0.1
 */
class UserInfoHelper
{
    /**
     * The checkout-field form backing ONE saved-info row in the backend.
     *
     * Built here rather than inline at each call site so the screen that RENDERS
     * the row and the controller that SAVES it are guaranteed to agree on the
     * field set: a validator working from a different form than the one shown is
     * how required/ShowOn rules silently stop matching.
     *
     * @param array|null $data Data to seed the injected fields with.
     *
     * @return Form The form, control name `uinfo`, fields under FIELDS_KEY.
     *
     * @since  1.9.0
     */
    public static function adminForm(?array $data = null, int $userId = 0): Form
    {
        $form = new Form('com_alfa.admin_userinfo', ['control' => 'uinfo']);

        // A saved row is role-neutral: it can serve as a delivery OR an invoice
        // address, so the backend edits it against every cart field, not one
        // role's subset. Built for the row's OWNER, not the administrator: the
        // identity rules (a member's email is the account's) and the per-user
        // field visibility are the customer's.
        FieldsHelper::prepareForm('cart.form', $form, $data, 'userinfo', userId: $userId);

        return $form;
    }

    /**
     * Normalizes field values for storage in #__alfa_user_info.
     *
     * Arrays and objects are JSON-encoded; scalar values pass through. A value
     * the ACCOUNT owns for a member (the email: RoleRegistry::accountOwned)
     * is never written to a record: it would be a copy that goes stale and is
     * read as truth.
     *
     * @param array $data Raw field values keyed by field_name.
     * @param int $userId The record's owner (0 = guest, everything is stored).
     *
     * @return array Normalized field values.
     *
     * @since   1.0.1
     */
    public static function normalizeFieldValues(array $data, int $userId = 0): array
    {
        // Whitelist: only real #__alfa_user_info columns may be written
        // (never id / id_user, and never an arbitrary posted key); the
        // rows are populated from user input, so mass-assignment is closed
        // here at the single write funnel.
        $columns = self::writableColumns();

        foreach ($data as $key => $value) {
            if (!isset($columns[$key])) {
                unset($data[$key]);
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $data[$key] = json_encode($value);
            }
        }

        if ($userId > 0) {
            foreach (RoleRegistry::roles() as $role) {
                $column = RoleRegistry::column($role);

                if ($column !== null && RoleRegistry::accountOwned($role, $userId)) {
                    unset($data[$column]);
                }
            }
        }

        return $data;
    }

    /**
     * The writable columns of #__alfa_user_info (everything except the
     * identity columns), memoised for the request.
     *
     * @return array<string, true> Column name => true.
     *
     * @since  1.5.0
     */
    private static function writableColumns(): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $columns = [];

        foreach (array_keys($db->getTableColumns('#__alfa_user_info')) as $name) {
            if ($name !== 'id' && $name !== 'id_user') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    /**
     * Returns the #__alfa_user_info.id to reference for the given field values.
     *
     * Decision order (logged-in users):
     *   1. An identical row already exists -> reuse it (no write).
     *   2. The caller EXPLICITLY named an owned row ($existingId, the
     *      User Info edit screen) and it is not frozen by an order ->
     *      UPDATE it (partial/merge: absent columns are preserved).
     *      Nothing is ever updated implicitly.
     *   3. Otherwise -> INSERT a new row: anything different from the saved
     *      rows is NEW info (registration created the first row; every new
     *      combination at checkout becomes another one). Rows referenced by
     *      orders are never touched; history stays exact.
     *
     * Guests (id_user = 0) always INSERT.
     *
     * @param int $userId Owner id (0 for guests).
     * @param array $data Raw field values keyed by field_name.
     *
     * @return int The id to reference (e.g. as id_address_delivery).
     *
     * @since   1.0.1
     */
    /**
     * Checkout write path: reuse, complete, or insert; never edit.
     *
     * Dedup first (identical row -> reuse). Then COMPLETION: when the data
     * agrees with every non-empty column of exactly one saved row and only
     * fills columns that were empty, that row is completed in place: a
     * field may enter the form later (shown conditionally, added by the
     * merchant) without forking the customer's identity. Any conflict with
     * a stored value, or an ambiguous match, means different details: a
     * NEW row. Content-driven on purpose: no posted ids are trusted.
     *
     * @param int $userId The owning user id (guests always insert).
     * @param array $data Submitted field values.
     *
     * @return int The reused, completed or created row id.
     *
     * @since  1.4.0
     */
    public static function insertOrComplete(int $userId, array $data): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $data = self::normalizeFieldValues($data, $userId);

        if ($userId <= 0) {
            $row = (object) $data;
            $row->id_user = 0;
            $db->insertObject('#__alfa_user_info', $row, 'id');

            return (int) $row->id;
        }

        $candidate = null;
        $candidateFills = [];

        foreach (self::getRowsByUser(userId: $userId) as $row) {
            $conflict = false;
            $fills = [];

            foreach ($data as $key => $value) {
                $stored = isset($row->$key) ? trim((string) $row->$key) : '';
                $submitted = trim((string) $value);

                if ($submitted == $stored) { // loose: "12.5" == "12.50000"
                    continue;
                }

                if ($stored !== '') { // changed or cleared a recorded value
                    $conflict = true;
                    break;
                }

                $fills[$key] = $value;
            }

            if ($conflict) {
                continue;
            }

            if (empty($fills)) {
                return (int) $row->id; // identical -> reuse
            }

            // A row an order already points at is history: completing its
            // blanks would rewrite what that order's documents show (a phone
            // never given for it). Identical -> reuse still applies; a
            // completion lands on a NEW row instead.
            if (self::isUsedInOrders(id: (int) $row->id)) {
                continue;
            }

            if ($candidate !== null) {
                $candidate = null; // ambiguous -> different details, new row
                break;
            }

            $candidate = $row;
            $candidateFills = $fills;
        }

        if ($candidate !== null) {
            $update = (object) $candidateFills; // only the filled blanks
            $update->id = (int) $candidate->id;
            $db->updateObject('#__alfa_user_info', $update, 'id');

            return (int) $candidate->id;
        }

        $row = (object) $data;
        $row->id_user = $userId;
        $db->insertObject('#__alfa_user_info', $row, 'id');

        return (int) $row->id;
    }

    public static function insertData(int $userId, array $data, ?int $existingId = null): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $data = self::normalizeFieldValues($data, $userId);

        // Guests always get a fresh row.
        if ($userId <= 0) {
            $row = (object) $data;
            $row->id_user = 0;
            $db->insertObject('#__alfa_user_info', $row, 'id');

            return (int) $row->id;
        }

        // 1) Content dedup: identical row already saved -> reuse it.
        foreach (self::getRowsByUser(userId: $userId) as $row) {
            $cols = array_diff(array_keys(get_object_vars($row)), ['id', 'id_user']);
            $same = true;

            foreach (array_unique(array_merge($cols, array_keys($data))) as $key) {
                $stored = isset($row->$key) ? trim((string) $row->$key) : '';
                $submitted = isset($data[$key]) ? trim((string) $data[$key]) : '';

                if ($stored != $submitted) { // loose: "12.5" == "12.50000", ""/null equal
                    $same = false;
                    break;
                }
            }

            if ($same) {
                return (int) $row->id;
            }
        }

        // 2) Explicit edit intent only: the caller picked a row (saved-info
        //    dropdown) and it is not frozen by an order -> merge into it.
        //    No implicit free-row updates; unpicked different data is NEW.
        $target = $existingId;

        if (
            $target
            && self::getRow(userId: $userId, id: (int) $target) !== null
            && !self::isUsedInOrders(id: (int) $target)
        ) {
            $row = (object) $data; // partial -> only submitted columns are touched
            $row->id = (int) $target;
            $db->updateObject('#__alfa_user_info', $row, 'id');

            return (int) $target;
        }

        // 3) Anything different becomes a NEW row; history stays exact.
        $row = (object) $data;
        $row->id_user = $userId;
        $db->insertObject('#__alfa_user_info', $row, 'id');

        return (int) $row->id;
    }

    /**
     * All saved rows for a user (the "my info" list), newest first.
     * Guest rows (id_user = 0) are excluded.
     *
     * @param int $userId Joomla user id.
     *
     * @return object[]
     *
     * @since 1.0.1
     */
    public static function getRowsByUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_user_info'))
            ->where($db->quoteName('id_user') . ' = :userId')
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->order($db->quoteName('id') . ' DESC');

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Loads a single row, scoped to its owner (null when not found / not owned).
     *
     * @param int $id Row id.
     *
     *
     * @since   1.0.1
     */
    /**
     * One address row by id alone, whoever it belongs to.
     *
     * The cart points at its addresses by id, and a guest's cart points at
     * rows no user owns; the pointer is the authority, not the account.
     *
     * @param int $id The row.
     *
     * @since  1.10.0
     */
    public static function getRowById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_user_info'))
            ->where($db->quoteName('id') . ' = :id')
            ->order($db->quoteName('id') . ' DESC')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->setLimit(1);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    public static function getRow(int $userId, int $id): ?object
    {
        if ($userId <= 0 || $id <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_user_info'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('id_user') . ' = :uid')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':uid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    /**
     * True when a row is referenced by any order (delivery or invoice address):
     * such a row must never be edited in place or deleted, to protect history.
     *
     * @param int $id Row id.
     *
     *
     * @since   1.0.1
     */
    public static function isUsedInOrders(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__alfa_orders'))
            ->where('(' . $db->quoteName('id_address_delivery') . ' = :a OR ' . $db->quoteName('id_address_invoice') . ' = :b)')
            ->bind(':a', $id, ParameterType::INTEGER)
            ->bind(':b', $id, ParameterType::INTEGER);
        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * Deletes a user's row, but only when it is owned by them and not referenced
     * by any order.
     *
     * @param int $userId Owner id.
     * @param int $id Row id.
     *
     * @return bool True when a row was deleted.
     *
     * @since   1.0.1
     */
    public static function deleteRow(int $userId, int $id): bool
    {
        if (self::getRow($userId, $id) === null || self::isUsedInOrders($id)) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_user_info'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('id_user') . ' = :uid')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':uid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);
        $db->execute();

        return true;
    }

    /**
     * Builds a short human-readable label for a user info row using the
     * currently active cart fields. Only fields that are published and
     * visible to the current user are considered; removed or hidden fields
     * are ignored even if the row still has that column.
     *
     * The recipient's name comes first when the row has one, then the first
     * non-empty address values, 3 parts in all ("Maria P., Ermou 5, Athens").
     * Falls back to "#id" if no active fields have a value in the row.
     *
     * @param object $row A row from #__alfa_user_info.
     *
     *
     * @since   1.0.1
     */
    public static function label(object $row): string
    {
        $fields = FieldsHelper::getFields('cart.form');
        $nameColumn = RoleRegistry::column(RoleRegistry::NAME);

        $parts = [];

        if ($nameColumn !== null && trim((string) ($row->$nameColumn ?? '')) !== '') {
            $parts[] = trim((string) $row->$nameColumn);
        }

        foreach ($fields as $field) {
            $key = $field->field_name;
            if ($key === $nameColumn || !isset($row->$key) || trim((string) $row->$key) === '') {
                continue;
            }
            $parts[] = trim((string) $row->$key);
            if (count($parts) >= 3) {
                break;
            }
        }

        return $parts ? implode(', ', $parts) : '#' . $row->id;
    }
}
