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
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use stdClass;
use Throwable;

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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @since  1.0.0
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

    // =========================================================================
    // Language schema sync (multilingual auxiliary tables)
    // =========================================================================

    /**
     * Create / update the per-language auxiliary tables for every translatable
     * entity, across all installed content languages.
     *
     * The translatable schema is DISCOVERED from the component's form XML — every
     * <field> carrying a `multilingual_table` attribute (the MultilingualText /
     * MultilingualTextarea / MultilingualEditor field types) declares its table,
     * PK column and field name. This keeps the sync self-maintaining: adding a
     * translatable field to a form is picked up automatically, with no list to
     * maintain here.
     *
     * Idempotent: missing lang tables are created, missing columns added; nothing
     * is dropped or seeded. Shared by the installer (script.php postflight), the
     * PlgSystemAlfasync runtime sync, and the admin "Resync languages" button.
     *
     * @return array ['tables' => [table => ensureLangSchema summary], 'errors' => [table => message]].
     * @since  1.0.0
     */
    public static function syncLanguageSchema(): array
    {
        $schema = self::getMultilingualSchema();
        $summary = ['tables' => [], 'errors' => []];

        foreach ($schema as $table => $definition) {
            try {
                $summary['tables'][$table] = MultilingualHelper::ensureLangSchema(
                    tableName:         $table,
                    primaryColumnName: $definition['pk'],
                    fields:            $definition['fields'],
                );
            } catch (Exception $e) {
                $summary['errors'][$table] = $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * Return the discovered translatable schema for the whole component, e.g.
     *   [ '#__alfa_categories' => [
     *         'pk'          => 'id_category',
     *         'fields'      => ['name', 'desc', 'alias', 'meta_title', 'meta_desc'],
     *         'aliasFields' => ['alias'],
     *         'aliasScope'  => ['parent_id'],
     *     ], … ]
     *
     * Scans the component's admin + site form XML for every field carrying a
     * `multilingual_table` attribute. Public so the Tools view / SyncController
     * can build the backfill plan from the same single source of truth used by
     * the schema sync. Alias fields + uniqueness scope come from MultilingualAliasConfig.
     *
     * @return array<string, array{pk: string, fields: string[], aliasFields: string[], aliasScope: string[]}>
     * @since  1.0.0
     */
    public static function getMultilingualSchema(): array
    {
        return self::discoverMultilingualSchema([
            JPATH_ADMINISTRATOR . '/components/com_alfa/forms',
            JPATH_SITE . '/components/com_alfa/forms',
        ]);
    }

    /**
     * Scan the given form directories for translatable-field declarations.
     *
     * Collects every <field multilingual_table="…" multilingual_pk="…" name="…">.
     * Alias slug fields and their uniqueness scope are NOT read from the XML —
     * they come from MultilingualAliasConfig.
     *
     * @param string[] $formDirs Absolute paths to directories of *.xml form files.
     *
     * @return array<string, array{pk: string, fields: string[], aliasFields: string[], aliasScope: string[]}>
     * @since  1.0.0
     */
    private static function discoverMultilingualSchema(array $formDirs): array
    {
        $schema = [];

        foreach ($formDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.xml') ?: [] as $file) {
                $xml = @simplexml_load_file($file);

                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//field[@multilingual_table]') ?: [] as $field) {
                    $table = (string) $field['multilingual_table'];
                    $pk = (string) $field['multilingual_pk'];
                    $name = (string) $field['name'];

                    if ($table === '' || $pk === '' || $name === '') {
                        continue;
                    }

                    $schema[$table]['pk'] = $pk;
                    $schema[$table]['fields'][$name] = $name; // keyed → de-duplicated
                }
            }
        }

        // Flatten the field map; alias fields + scope come from the shared config.
        foreach ($schema as $table => $definition) {
            $schema[$table]['fields'] = array_values($definition['fields']);
            $schema[$table]['aliasFields'] = MultilingualAliasConfig::FIELDS[$table] ?? [];
            $schema[$table]['aliasScope'] = MultilingualAliasConfig::SCOPE[$table] ?? [];
        }

        return $schema;
    }

    /**
     * Keep the Security notification in sync with the integrity verdict. Designed to
     * be called on every admin load (by PlgSystemAlfasync) — the expensive work is
     * throttled to once per 24h here via Joomla's cache (same `com_alfa.integrity`
     * group as {@see IntegrityHelper::cachedVerdict()}), so callers don't worry about
     * scheduling. The actual push/clear runs only on a cache miss.
     *
     *
     * @since  1.0.0
     */
    public static function syncIntegrity(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.integrity',
                    'lifetime' => 1440, // 24h, in minutes
                    'caching' => true,
                ]);

            $cache->get([self::class, 'doSyncIntegrity'], [], 'sync');
        } catch (Throwable) {
            // Cache unavailable → just do it (correct, only less throttled).
            self::doSyncIntegrity();
        }
    }

    /**
     * Push the Security notification while the integrity check isn't clean, or clear
     * it (hard — a fluctuating live state shouldn't fill history) when it is. Public
     * so it can be the cache callback for {@see self::syncIntegrity()}.
     *
     * @return bool Always true (so the cache stores a hit).
     *
     * @since  1.0.0
     */
    public static function doSyncIntegrity(): bool
    {
        self::applyIntegrityVerdict(IntegrityHelper::cachedVerdict());

        return true;
    }

    /**
     * Push/clear the Security notification to match a verdict. Split out so a FRESH
     * check (the Tools Security tab) can update the badge IMMEDIATELY instead of waiting
     * for the next 24h sync — and so the tab and the badge never disagree.
     *
     * @param array $verdict A verdict from IntegrityHelper (verifyAgainstOfficial/cachedVerdict).
     *
     *
     * @since  1.0.0
     */
    public static function applyIntegrityVerdict(array $verdict): void
    {
        $status = $verdict['status'] ?? 'unreachable';

        if ($status === 'official') {
            NotificationHelper::clear(dedupKey: 'alfa.integrity', hard: true);

            return;
        }

        $map = [
            'modified' => ['danger',  'COM_ALFA_NOTIFY_INTEGRITY_MODIFIED'],
            'ahead' => ['info',    'COM_ALFA_NOTIFY_INTEGRITY_AHEAD'],
            'unreachable' => ['warning', 'COM_ALFA_NOTIFY_INTEGRITY_UNVERIFIED'],
            'bad_signature' => ['danger',  'COM_ALFA_NOTIFY_INTEGRITY_BADSIG'],
        ];

        [$severity, $messageKey] = $map[$status] ?? ['warning', 'COM_ALFA_NOTIFY_INTEGRITY_UNVERIFIED'];

        NotificationHelper::push(
            dedupKey: 'alfa.integrity',
            title: Text::_('COM_ALFA_NOTIFY_INTEGRITY_TITLE'),
            options: [
                'group' => Text::_('COM_ALFA_NOTIFY_GROUP_SECURITY'),
                'severity' => $severity,
                'message' => Text::_($messageKey),
                // Raw URL (no &amp;) — output encoding adds the entities once, not twice.
                'url' => Route::_('index.php?option=com_alfa&view=tools', false),
                'dismissible' => false,
                // Constant: while the install isn't clean, re-alert each 24h sync even if read.
                'constant' => true,
                // Everyone SEES the integrity state (awareness), but only admins with
                // alfa.tools get the clickable link to Tools (others see it link-less).
                'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
            ],
        );
    }

    /**
     * Cheap, NON-blocking read of the last persisted integrity verdict's alarm state.
     * The 24h sync stores the verdict as the `alfa.integrity` notification; this just
     * reads that row (no CDN fetch, no hashing), returning true only for the genuine
     * tamper alarms (severity `danger`: modified / bad signature). Lets the dispatcher
     * raise a global, override-proof banner without ever running the real check.
     *
     *
     * @since  1.0.0
     */
    public static function hasIntegrityAlarm(): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('severity'))
                ->from($db->quoteName('#__alfa_notifications'))
                ->where($db->quoteName('dedup_key') . ' = ' . $db->quote('alfa.integrity'));
            $db->setQuery($query, 0, 1);

            return $db->loadResult() === 'danger';
        } catch (Throwable) {
            return false;
        }
    }
}
