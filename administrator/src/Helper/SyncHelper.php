<?php

/**
 * @package     Alfa Commerce
 * @subpackage  Administrator.Helper
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Database\DatabaseInterface;
use stdClass;

/**
 * SyncHelper — single source of truth for all Alfa ↔ Joomla sync operations.
 *
 * Used by three consumers:
 *  1. script.php (installer)       — seeds all existing users/groups on install/update.
 *  2. PlgSystemAlfasync (plugin)   — keeps tables in sync on every save/delete event.
 *  3. Frontend registration logic  — inserts the new user row immediately on sign-up.
 *
 * Batch strategy
 * --------------
 * All bulk inserts use a single multi-row INSERT statement per chunk rather
 * than looping with individual insertObject() calls.  For 2 000 users this
 * means ~4 round-trips to the DB instead of 2 000.
 *
 * Chunks are capped at {@see self::INSERT_CHUNK_SIZE} rows to stay well
 * within MySQL's default max_allowed_packet (typically 64 MB – 1 GB).
 *
 * Adding a new price field
 * ------------------------
 * 1. Add the key + default to $defaultParams in script.php.
 * 2. Add the key to PRICES_DISPLAY_KEYS below.
 * Done — every consumer picks it up automatically.
 *
 * @since  1.0.0
 */
final class SyncHelper
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * Maximum number of rows per INSERT statement.
     *
     * Keeps individual queries small enough to be safe regardless of the
     * server's max_allowed_packet setting.
     *
     * @var int
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Keys that are copied from com_alfa component params into the
     * prices_display JSON column of every new #__alfa_usergroups row.
     *
     * Must mirror PRICES_DISPLAY_KEYS in script.php.
     *
     * @var string[]
     */
    public const PRICES_DISPLAY_KEYS = [
        'base_price_show',
        'base_price_show_label',
        'discount_amount_show',
        'discount_amount_show_label',
        'base_price_with_discounts_show',
        'base_price_with_discounts_show_label',
        'tax_amount_show',
        'tax_amount_show_label',
        'base_price_with_tax_show',
        'base_price_with_tax_show_label',
        'final_price_show',
        'final_price_show_label',
        'price_breakdown_show',
    ];

    // =========================================================================
    // prices_display builder
    // =========================================================================

    /**
     * Builds the default prices_display JSON for a new #__alfa_usergroups row.
     *
     * Two modes depending on the caller:
     *
     *  A) script.php (installer)
     *     Passes $sourceParams = $this->defaultParams directly because the
     *     component params may not be committed to DB yet at postflight time.
     *
     *  B) Plugin / frontend
     *     Passes null → reads from the already-seeded com_alfa component params
     *     via ComponentHelper so the component configuration panel is the live
     *     control for these defaults.
     *
     * @param array|null $sourceParams Explicit key→value map, or null to
     *                                 read from ComponentHelper at runtime.
     *
     * @return string JSON-encoded prices_display value.
     */
    public static function buildDefaultPricesDisplay(?array $sourceParams = null): string
    {
        if ($sourceParams === null) {
            // Runtime path: read from component params (seeded by installer).
            $componentParams = ComponentHelper::getParams('com_alfa');
            $sourceParams = [];

            foreach (self::PRICES_DISPLAY_KEYS as $key) {
                $sourceParams[$key] = $componentParams->get($key, '0');
            }
        }

        $display = [];

        foreach (self::PRICES_DISPLAY_KEYS as $key) {
            // String cast matches how Joomla stores boolean-like integers.
            $display[$key] = (string) ($sourceParams[$key] ?? '0');
        }

        return json_encode($display);
    }

    // =========================================================================
    // Existence checks (single-row, used by plugin on save events)
    // =========================================================================

    /**
     * Returns true if a row for $userId already exists in #__alfa_users.
     */
    public static function alfaUserExists(DatabaseInterface $db, int $userId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__alfa_users'))
            ->where($db->quoteName('id_user') . ' = ' . $userId);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Returns true if a row for $groupId already exists in #__alfa_usergroups.
     */
    public static function alfaUsergroupExists(DatabaseInterface $db, int $groupId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__alfa_usergroups'))
            ->where($db->quoteName('usergroup_id') . ' = ' . $groupId);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    // =========================================================================
    // Single-row insert (used by plugin on save events)
    // =========================================================================

    /**
     * Inserts a single user row into #__alfa_users if it does not already exist.
     *
     * Used by the plugin's onUserAfterSave handler where only one user is
     * being added at a time, so chunking is unnecessary.
     *
     *
     * @return bool True if a row was inserted, false if it already existed.
     */
    public static function insertAlfaUser(DatabaseInterface $db, int $userId): bool
    {
        if (self::alfaUserExists($db, $userId)) {
            return false;
        }

        $row = new stdClass();
        $row->id_user = $userId;
        $row->note = '';

        try {
            $db->insertObject('#__alfa_users', $row);
        } catch (Exception $e) {
            // Silently drop duplicate-key races (concurrent registrations).
            if (stripos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * Inserts a single usergroup row into #__alfa_usergroups if it does not
     * already exist.
     *
     * @param string|null $pricesDisplay JSON string; pass null to
     *                                   build from component params.
     *
     * @return bool True if a row was inserted, false if it already existed.
     */
    public static function insertAlfaUsergroup(
        DatabaseInterface $db,
        int $groupId,
        ?string $pricesDisplay = null,
    ): bool {
        if (self::alfaUsergroupExists($db, $groupId)) {
            return false;
        }

        $row = new stdClass();
        $row->usergroup_id = $groupId;
        $row->prices_enable = 0;
        $row->prices_display = $pricesDisplay ?? self::buildDefaultPricesDisplay();

        try {
            $db->insertObject('#__alfa_usergroups', $row);
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    // =========================================================================
    // Bulk sync (used by installer script.php)
    // =========================================================================

    /**
     * Bulk-inserts all Joomla users that are missing from #__alfa_users.
     *
     * Uses a single multi-row INSERT per chunk instead of one query per row.
     * For 2 000 missing users this means ~4 DB round-trips instead of 2 000.
     *
     *
     * @return int Total number of rows inserted.
     */
    public static function bulkSyncUsers(DatabaseInterface $db): int
    {
        $missing = self::getMissingUserIds($db);

        if (empty($missing)) {
            return 0;
        }

        $inserted = 0;

        foreach (array_chunk($missing, self::INSERT_CHUNK_SIZE) as $chunk) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__alfa_users'))
                ->columns([
                    $db->quoteName('id_user'),
                    $db->quoteName('note'),
                ]);

            foreach ($chunk as $userId) {
                $query->values((int) $userId . ', ' . $db->quote(''));
            }

            try {
                $db->setQuery($query)->execute();
                $inserted += count($chunk);
            } catch (Exception $e) {
                // If the chunk fails (e.g. partial duplicate after a crash),
                // fall back to row-by-row so the rest of the chunk still lands.
                $inserted += self::insertUsersOneByOne($db, $chunk);
            }
        }

        return $inserted;
    }

    /**
     * Bulk-inserts all Joomla usergroups that are missing from
     * #__alfa_usergroups.
     *
     * The prices_display JSON for every new row is built from $sourceParams
     * (pass $this->defaultParams from script.php) or from the live component
     * params when $sourceParams is null (runtime / plugin use).
     *
     * @param array|null $sourceParams See buildDefaultPricesDisplay().
     *
     * @return int Total number of rows inserted.
     */
    public static function bulkSyncUsergroups(
        DatabaseInterface $db,
        ?array $sourceParams = null,
    ): int {
        $missing = self::getMissingGroupIds($db);

        if (empty($missing)) {
            return 0;
        }

        // Build once — every missing group gets the same default JSON.
        $pricesDisplay = self::buildDefaultPricesDisplay($sourceParams);

        $inserted = 0;

        foreach (array_chunk($missing, self::INSERT_CHUNK_SIZE) as $chunk) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__alfa_usergroups'))
                ->columns([
                    $db->quoteName('usergroup_id'),
                    $db->quoteName('prices_enable'),
                    $db->quoteName('prices_display'),
                ]);

            foreach ($chunk as $groupId) {
                $query->values(
                    (int) $groupId
                    . ', 0'
                    . ', ' . $db->quote($pricesDisplay),
                );
            }

            try {
                $db->setQuery($query)->execute();
                $inserted += count($chunk);
            } catch (Exception $e) {
                $inserted += self::insertGroupsOneByOne($db, $chunk, $pricesDisplay);
            }
        }

        return $inserted;
    }

    // =========================================================================
    // ID discovery helpers
    // =========================================================================

    /**
     * Returns the IDs of all #__users rows that have no matching #__alfa_users row.
     *
     *
     * @return int[]
     */
    public static function getMissingUserIds(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('u.id'))
            ->from($db->quoteName('#__users', 'u'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_users', 'a')
                . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.id_user'),
            )
            ->where($db->quoteName('a.id_user') . ' IS NULL');

        return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
    }

    /**
     * Returns the IDs of all #__usergroups rows that have no matching
     * #__alfa_usergroups row.
     *
     *
     * @return int[]
     */
    public static function getMissingGroupIds(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('g.id'))
            ->from($db->quoteName('#__usergroups', 'g'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_usergroups', 'ag')
                . ' ON ' . $db->quoteName('g.id') . ' = ' . $db->quoteName('ag.usergroup_id'),
            )
            ->where($db->quoteName('ag.usergroup_id') . ' IS NULL');

        return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
    }

    // =========================================================================
    // Fallback: one-by-one inserts (used when a chunk INSERT fails)
    // =========================================================================

    /**
     * Inserts user IDs one-by-one, silently skipping duplicates.
     * Only called as a fallback when a chunk INSERT fails.
     *
     * @param int[] $userIds
     *
     * @return int Number of rows successfully inserted.
     */
    private static function insertUsersOneByOne(DatabaseInterface $db, array $userIds): int
    {
        $inserted = 0;

        foreach ($userIds as $userId) {
            if (self::insertAlfaUser($db, (int) $userId)) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Inserts usergroup IDs one-by-one, silently skipping duplicates.
     * Only called as a fallback when a chunk INSERT fails.
     *
     * @param int[] $groupIds
     */
    private static function insertGroupsOneByOne(
        DatabaseInterface $db,
        array $groupIds,
        string $pricesDisplay,
    ): int {
        $inserted = 0;

        foreach ($groupIds as $groupId) {
            if (self::insertAlfaUsergroup($db, (int) $groupId, $pricesDisplay)) {
                $inserted++;
            }
        }

        return $inserted;
    }
}
