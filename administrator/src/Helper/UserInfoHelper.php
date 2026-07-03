<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;

/**
 * Helper for #__alfa_user_info operations.
 *
 * Shared by OrderPlaceHelper (cart checkout) and PlgSystemAlfasync
 * (registration) so that field normalization and persistence logic
 * live in one place.
 *
 * @since  1.0.1
 */
class UserInfoHelper
{
    /**
     * Normalizes field values for storage in #__alfa_user_info.
     *
     * Arrays and objects are JSON-encoded; all other scalar values
     * are left as-is. This mirrors the normalization that was previously
     * inlined in OrderPlaceHelper::saveUserInfo().
     *
     * @param   array  $data  Raw field values keyed by field_name.
     *
     * @return  array  Normalized field values.
     *
     * @since   1.0.1
     */
    public static function normalizeFieldValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $data[$key] = json_encode($value);
            }
        }

        return $data;
    }

    /**
     * Returns the #__alfa_user_info.id to reference for the given field values.
     *
     * Decision order (logged-in users):
     *   1. An identical row already exists → reuse it (no write).
     *   2. The user has an editable row not yet used in any order → UPDATE it
     *      (partial/merge update: columns absent from $data — e.g. the fields left
     *      empty at registration — are preserved). The caller may name that row via
     *      $existingId; otherwise the user's free row is used (e.g. the partial row
     *      created at registration).
     *   3. Otherwise (no free row, or it is frozen by a past order) → INSERT a new
     *      snapshot, so past orders keep their own data intact.
     *
     * Guests (id_user = 0) always INSERT.
     *
     * @param   int       $userId      Owner id (0 for guests).
     * @param   array     $data        Raw field values keyed by field_name.
     * @param   int|null  $existingId  Row the caller is editing, if known.
     *
     * @return  int  The id to reference (e.g. as id_address_delivery).
     *
     * @since   1.0.1
     */
    public static function insertData(int $userId, array $data, ?int $existingId = null): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $data = self::normalizeFieldValues($data);

        // Guests always get a fresh row.
        if ($userId <= 0) {
            $row = (object) $data;
            $row->id_user = 0;
            $db->insertObject('#__alfa_user_info', $row, 'id');

            return (int) $row->id;
        }

        // 1) Content dedup — identical row already saved → reuse it.
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

        // 2) Update the user's editable (not-yet-ordered) row, merging into it.
        $target = $existingId ?? (self::getFreeRow(userId: $userId)?->id);

        if (
            $target
            && self::getRow(userId: $userId, id: (int) $target) !== null
            && !self::isUsedInOrders(id: (int) $target)
        ) {
            $row = (object) $data; // partial → only submitted columns are touched
            $row->id = (int) $target;
            $db->updateObject('#__alfa_user_info', $row, 'id');

            return (int) $target;
        }

        // 3) No free row, or it is frozen by a past order → new snapshot.
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
     * Returns the user's most recent row that is NOT referenced by any order
     * (their editable "draft"), or null when none exists.
     *
     * @param int $userId Owner id.
     *
     * @return object|null
     *
     * @since 1.0.1
     */

    public static function getFreeRow(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('ui.*')
            ->from($db->quoteName('#__alfa_user_info', 'ui'))
            ->where($db->quoteName('ui.id_user') . ' = :userId')
            ->where(
                'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_orders', 'o')
                . ' WHERE o.' . $db->quoteName('id_address_delivery') . ' = ui.id'
                . ' OR o.' . $db->quoteName('id_address_invoice') . ' = ui.id)'
            )
            ->order($db->quoteName('ui.id') . ' DESC')
            ->setLimit(1)
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    /**
     * Loads a single row, scoped to its owner (null when not found / not owned).
     *
     * @param   int  $userId  Owner id.
     * @param   int  $id      Row id.
     *
     * @return  object|null
     *
     * @since   1.0.1
     */
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
     * @param   int  $id  Row id.
     *
     * @return  bool
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
            //to id_address_invoice den kserw ti kanei mallon den xrismopoieitai ston alfa_user_info
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
     * @param   int  $userId  Owner id.
     * @param   int  $id      Row id.
     *
     * @return  bool  True when a row was deleted.
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
     * visible to the current user are considered — removed or hidden fields
     * are ignored even if the row still has that column.
     *
     * Returns the first 3 non-empty field values joined by ", ".
     * Falls back to "#id" if no active fields have a value in the row.
     *
     * @param   object  $row  A row from #__alfa_user_info.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    public static function label(object $row): string
    {
        $fields = FieldsHelper::getFields('cart.form');

        $parts = [];
        foreach ($fields as $field) {
            $key = $field->field_name;
            if (!isset($row->$key) || trim((string) $row->$key) === '') {
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