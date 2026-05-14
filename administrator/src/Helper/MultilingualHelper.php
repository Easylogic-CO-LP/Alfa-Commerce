<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Log\Log;

/**
 * MultilingualHelper
 *
 * Central utility for all multilingual read / write operations in the Alfa component.
 *
 * DATA MODEL
 * ----------
 * Every translatable entity has one auxiliary table per installed language:
 *   #__alfa_items_en_gb,  #__alfa_items_el_gr, …
 *
 * Each lang table shares a single PK column (e.g. "id_item") with the parent
 * table and stores one column per translatable field (name, alias, short_desc …).
 * Tables and columns are created / migrated automatically on first save.
 *
 * The main table (e.g. #__alfa_categories) does NOT need to store translatable
 * fields. Joomla's Table::bind() silently ignores keys that have no matching
 * column, so passing name_en_gb or even name in $data causes no errors.
 * All translatable data lives exclusively in the language tables.
 *
 * FORM FIELD NAMING CONVENTION
 * ----------------------------
 * Translatable form fields must follow the pattern:  fieldName_langCode
 * Example POST keys:  name_en_gb,  alias_el_gr,  meta_title_en_gb
 * The MultilingualTextField form-field type generates this naming automatically.
 *
 * ALIAS / SLUG FIELDS
 * -------------------
 * Alias is a translatable field — every language stores its own URL-safe alias
 * in its lang table.  Fields listed in $aliasFields are treated as slugs:
 * auto-generated from name/title when blank and sanitised via OutputFilter.
 * Default: ['alias'].  Pass [] to opt out entirely.
 *
 * MAIN TABLE ALIAS
 * ----------------
 * The parent table (e.g. #__alfa_items) also has an alias column.  That column
 * holds the default-language alias and acts as a universal fallback for code
 * that reads the main table directly.  It is the calling model's responsibility
 * to resolve and write that value before calling parent::save().
 * See ItemModel::resolveItemAlias().
 *
 * LIST QUERY JOINS
 * ----------------
 * addMultilingualJoinToQuery() joins the current-language table and optionally
 * the default-language table. The COALESCE reads ONLY from language tables —
 * it does NOT fall back to the main table column since translatable fields
 * no longer exist there.
 *
 * LIST QUERY — RELATED ENTITY IDS
 * ---------------------------------
 * addRelatedIdsToQuery() adds one correlated scalar subquery per relationship.
 * No JOIN or GROUP BY on the outer query — pagination totals stay correct.
 * Full records are resolved in getItems() after pagination via fetchRelated().
 *
 * GETITEMS — RELATED ENTITY ENRICHMENT
 * --------------------------------------
 * Three methods handle post-pagination enrichment — use whichever fits the model:
 *
 * fetchRelated()  Pure data, no mutation. Fires one query per relationship,
 *                 returns a [$itemId => [$relatedId => $record]] map.
 *                 Call before the foreach loop so all DB work is grouped.
 *
 * bindRelated()   Pure assignment, no DB. Binds one map onto one item.
 *                 Call inside the foreach loop — one call per relationship.
 *                 All other per-item logic (prices, links, media) goes in
 *                 the same loop.
 *
 * loadRelated()   One-liner convenience: fetchRelated() + foreach bindRelated().
 *                 Use when no extra per-item logic is needed.
 *
 * FIELD SEMANTICS FOR getRecordsByIds() / fetchRelated() / loadRelated()
 * -----------------------------------------------------------------------
 * $fields     — structural (non-translatable) columns read directly from the
 *               main table. Same value in every language.
 *               e.g. ['alias', 'image', 'ordering']
 * $langFields — translatable columns whose value differs per language.
 *               When $langTableBase is set: resolved through lang tables via
 *               COALESCE (current language → default language → empty string).
 *               When $langTableBase is '' (non-translatable table e.g. #__users):
 *               selected directly from the main table without any join.
 *               e.g. ['name', 'description']
 *
 * LOGGING
 * -------
 * All messages go to  logs/com_alfa.php  under category "com_alfa.multilingual"
 * using the consistent format:
 *   [MultilingualHelper::methodName] Human-readable message
 *
 * @since  1.0.1
 */
class MultilingualHelper
{
    // =========================================================================
    //  Constants
    // =========================================================================

    /** Joomla log category for every log() call in this class. */
    private const LOG_CATEGORY = 'com_alfa.multilingual';

    /**
     * Substrings that map a column to LONGTEXT instead of VARCHAR(255).
     * Matched case-insensitively via str_contains() against the field name.
     */
    private const LONGTEXT_HINTS = [
        'desc', 'content', 'body', 'text', 'note', 'html', 'full_', 'short_', 'meta_',
    ];

    // =========================================================================
    //  Public API
    // =========================================================================

    /**
     * Save multilingual data for one record.
     *
     * Upserts per-language fields into their respective lang tables.
     * Missing tables / columns are created automatically.
     * All language rows are written inside a single DB transaction.
     *
     * Prefer passing $data explicitly (the model already has it).
     * The POST fallback exists only for utility / CLI call-sites.
     *
     * @param  int      $currentId          PK of the parent record.  Must be > 0.
     * @param  string   $primaryColumnName  PK column in lang tables  (e.g. "id_item").
     * @param  string   $tableName          Base table with Joomla prefix  (e.g. "#__alfa_items").
     * @param  array    $data               Form data to persist.  Falls back to jform POST when empty.
     * @param  string[] $aliasFields        Field names to treat as URL slugs.
     *                                      Default ['alias'].  Pass [] to disable.
     *
     * @return bool  True on success, false when there is nothing to save.
     *
     * @throws \InvalidArgumentException  On bad arguments.
     * @throws \RuntimeException          On any unrecoverable database error.
     */
    public static function saveMultilingualData(
        int    $currentId,
        string $primaryColumnName,
        string $tableName,
        array  $data = [],
        array  $aliasFields = ['alias'],
    ): bool {
        self::assertValidArguments(
            currentId:         $currentId,
            primaryColumnName: $primaryColumnName,
            tableName:         $tableName,
            callerMethod:      __METHOD__,
        );

        $app = Factory::getApplication();

        if (empty($data)) {
            // Safety-net fallback — callers should always pass $data explicitly.
            $data = $app->input->get('jform', [], 'raw');
        }

        if (empty($data)) {
            self::log(
                callerMethod: __METHOD__,
                message:      'No form data for table "' . $tableName . '". Skipping.',
                priority:     Log::WARNING,
            );
            return false;
        }

        $db             = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes      = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );

        [$languageData, $fallbackData] = self::extractLanguageData(
            data:      $data,
            langCodes: $langCodes,
        );

        if (empty($languageData)) {
            self::log(
                callerMethod: __METHOD__,
                message:      'No per-language keys found for table "' . $tableName . '". '
                              . 'Keys must follow the pattern fieldName_langCode (e.g. name_en_gb).',
                priority:     Log::WARNING,
            );
            return false;
        }

        $db->transactionStart();

        try {
            foreach ($languageData as $langCode => $langFields) {
                $langTableName = $db->replacePrefix($tableName . '_' . $langCode);

                if (!in_array($langTableName, $existingTables, true)) {
                    self::createLangTable(
                        db:                $db,
                        langTableName:     $langTableName,
                        primaryColumnName: $primaryColumnName,
                        fields:            $langFields,
                    );
                    $existingTables[] = $langTableName;
                    self::log(
                        callerMethod: __METHOD__,
                        message:      'Created language table "' . $langTableName . '".',
                        priority:     Log::INFO,
                    );
                } else {
                    self::ensureColumnsExist(
                        db:            $db,
                        langTableName: $langTableName,
                        fields:        $langFields,
                    );
                }

                $processedFields = self::applyFallbackAndSlugs(
                    fields:       $langFields,
                    fallbackData: $fallbackData,
                    aliasFields:  $aliasFields,
                    app:          $app,
                );

                self::upsertLangRow(
                    db:                $db,
                    langTableName:     $langTableName,
                    primaryColumnName: $primaryColumnName,
                    currentId:         $currentId,
                    fields:            $processedFields,
                );

                self::log(
                    callerMethod: __METHOD__,
                    message:      'Upserted lang "' . $langCode . '" for ID ' . $currentId
                                  . ' in "' . $langTableName . '".',
                    priority:     Log::DEBUG,
                );
            }

            $db->transactionCommit();

        } catch (\Throwable $e) {
            $db->transactionRollback();
            self::log(
                callerMethod: __METHOD__,
                message:      'Transaction rolled back: ' . $e->getMessage(),
                priority:     Log::ERROR,
            );
            throw new \RuntimeException(
                '[MultilingualHelper::saveMultilingualData] Translations save failed for "'
                . $tableName . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        return true;
    }

    /**
     * Load all multilingual data for one record, keyed by lower-snake language code.
     *
     * Returns:  [ 'en_gb' => ['name' => '…', 'alias' => '…'], 'el_gr' => […], … ]
     * Languages whose tables do not yet exist are silently skipped.
     *
     * @param  int    $currentId
     * @param  string $primaryColumnName
     * @param  string $tableName
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException          On a database read failure.
     */
    public static function getMultilingualData(
        int    $currentId,
        string $primaryColumnName,
        string $tableName,
    ): array {
        if ($currentId <= 0) {
            return [];
        }

        self::assertValidArguments(
            currentId:         $currentId,
            primaryColumnName: $primaryColumnName,
            tableName:         $tableName,
            callerMethod:      __METHOD__,
        );

        $db             = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes      = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );
        $result = [];

        foreach ($langCodes as $langCode) {
            $langTableName = $db->replacePrefix($tableName . '_' . $langCode);

            if (!in_array($langTableName, $existingTables, true)) {
                continue; // No translations for this language yet — not an error.
            }

            try {
                $row = $db->setQuery(
                    $db->getQuery(true)
                        ->select('*')
                        ->from($db->qn($langTableName))
                        ->where($db->qn($primaryColumnName) . ' = ' . $db->q($currentId))
                )->loadAssoc();
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    '[MultilingualHelper::getMultilingualData] Read failed for "'
                    . $langTableName . '", ID ' . $currentId . ': ' . $e->getMessage(),
                    previous: $e,
                );
            }

            if ($row) {
                unset($row[$primaryColumnName]); // Callers only need the translatable fields.
                $result[$langCode] = $row;
            }
        }

        return $result;
    }

    /**
     * Flatten the structured output of getMultilingualData() into a single-level
     * array that can be merged directly into a Joomla item object / form data.
     *
     * Input:   [ 'en_gb' => ['name' => 'Foo', 'alias' => 'foo'], … ]
     * Output:  [ 'name_en_gb' => 'Foo', 'alias_en_gb' => 'foo', … ]
     *
     * @param  array $multilingualData  Return value of getMultilingualData().
     *
     * @return array
     *
     * @since  1.0.1
     */
    public static function flattenForFormData(array $multilingualData): array
    {
        $flat = [];

        foreach ($multilingualData as $langCode => $fields) {
            foreach ($fields as $fieldName => $value) {
                $flat[$fieldName . '_' . $langCode] = $value;
            }
        }

        return $flat;
    }

    /**
     * Load all multilingual data for one record and return it as a flat array
     * ready to be merged into a Joomla item object or form data.
     *
     * Combines getMultilingualData() + flattenForFormData() into a single call.
     *
     * Output:  [ 'name_en_gb' => 'Foo', 'alias_en_gb' => 'foo', 'name_el_gr' => '…', … ]
     *
     * @param  int    $currentId
     * @param  string $primaryColumnName
     * @param  string $tableName
     *
     * @return array  Flat key-value pairs ready for object property assignment.
     *
     * @since  1.0.1
     */
    public static function getMultilingualDataFlat(
        int    $currentId,
        string $primaryColumnName,
        string $tableName,
    ): array {
        return self::flattenForFormData(
            self::getMultilingualData(
                currentId:         $currentId,
                primaryColumnName: $primaryColumnName,
                tableName:         $tableName,
            )
        );
    }

    /**
     * Load all multilingual data for one record and bind it directly onto the item object.
     *
     * This is the single method models should call in getItem() — no foreach needed.
     * Flat keys (e.g. name_en_gb, alias_el_gr) are set as properties on $item directly.
     *
     * In PHP, objects are passed by handle so no & reference is needed —
     * property assignments inside this method mutate the same object the caller holds.
     *
     * Usage in any model's getItem():
     *   MultilingualHelper::bindMultilingualToItem($item, (int) $item->id, 'id_category', '#__alfa_categories');
     *
     * @param  object  $item               The item object to hydrate.
     * @param  int     $currentId
     * @param  string  $primaryColumnName
     * @param  string  $tableName
     *
     * @return void
     *
     * @since  1.0.1
     */
    public static function bindMultilingualToItem(
        object $item,
        int    $currentId,
        string $primaryColumnName,
        string $tableName,
    ): void {
        foreach (self::getMultilingualDataFlat(
            currentId:         $currentId,
            primaryColumnName: $primaryColumnName,
            tableName:         $tableName,
        ) as $key => $value) {
            $item->$key = $value;
        }
    }

    /**
     * Add per-language LEFT JOINs to a list-view query.
     *
     * Joins the current-language table and, when it differs from the default,
     * also the default-language table. The COALESCE reads ONLY from language
     * tables — it does NOT fall back to the main table column because
     * translatable fields no longer exist there.
     *
     * LEFT JOIN (not INNER JOIN) is intentional: records without a translation
     * row must still appear in list views.
     *
     * Checks whether the dynamic language tables exist before joining to
     * prevent SQL crashes on fresh installs.
     *
     * @param  \Joomla\Database\QueryInterface $query              Modified in-place.
     * @param  string                          $mainAlias          Alias of the main table  (e.g. "a").
     * @param  string                          $mainPrimaryColumn  PK column in the main table  (e.g. "id").
     * @param  string                          $langTableBase      Base lang table  (e.g. "#__alfa_items").
     * @param  string                          $langPrimaryColumn  FK column in the lang table  (e.g. "id_item").
     * @param  string[]                        $fields             Translatable field names to SELECT.
     *
     * @return string  The current-language join alias (e.g. "en_gb").
     *
     * @since  1.0.1
     */
    public static function addMultilingualJoinToQuery(
        &$query,
        string $mainAlias,
        string $mainPrimaryColumn,
        string $langTableBase,
        string $langPrimaryColumn,
        array  $fields = ['name'],
    ): string {
        $db             = self::getDb();
        $existingTables = self::fetchTableList($db);

        $currentTag = self::getCurrentLanguageTag();
        $defaultTag = self::getDefaultLanguageTag();

        $currentTable = $db->replacePrefix("{$langTableBase}_{$currentTag}");
        $defaultTable = $db->replacePrefix("{$langTableBase}_{$defaultTag}");

        $currentExists = in_array($currentTable, $existingTables, true);
        $defaultExists = in_array($defaultTable, $existingTables, true);
        $hasDefault    = ($currentTag !== $defaultTag && $defaultExists);

        if ($currentExists) {
            $query->join(
                'LEFT',
                "`{$currentTable}` AS `{$currentTag}`"
                . " ON `{$currentTag}`.`{$langPrimaryColumn}` = `{$mainAlias}`.`{$mainPrimaryColumn}`"
            );
        }

        if ($hasDefault) {
            $query->join(
                'LEFT',
                "`{$defaultTable}` AS `lang_default`"
                . " ON `lang_default`.`{$langPrimaryColumn}` = `{$mainAlias}`.`{$mainPrimaryColumn}`"
            );
        }

        foreach ($fields as $field) {
            $parts = [];

            if ($currentExists) {
                $parts[] = "NULLIF(`{$currentTag}`.`{$field}`, '')";
            }

            if ($hasDefault) {
                $parts[] = "NULLIF(`lang_default`.`{$field}`, '')";
            }

            // NOTE: We do NOT fall back to `{$mainAlias}`.`{$field}` here.
            // Translatable fields no longer exist in the main table — they live
            // exclusively in the language tables. The main table only holds
            // structural (non-translatable) columns.
            if (empty($parts)) {
                // No language table exists yet — emit an empty string placeholder
                // so the SELECT does not break on fresh installs.
                $query->select("'' AS `{$field}`");
                continue;
            }

            $coalesce = count($parts) > 1
                ? 'COALESCE(' . implode(', ', $parts) . ')'
                : $parts[0];

            $query->select("{$coalesce} AS `{$field}`");
        }

        return $currentTag;
    }

    /**
     * Add a correlated GROUP_CONCAT subquery for related entity IDs to a list query.
     *
     * Emits one scalar correlated subquery SELECT per call onto $query:
     *
     *   (SELECT GROUP_CONCAT(DISTINCT `j`.`category_id` SEPARATOR ',')
     *    FROM `#__alfa_shipment_categories` AS `j`
     *    WHERE `j`.`shipment_id` = `a`.`id`) AS `category_ids`
     *
     * WHY SUBQUERY INSTEAD OF JOIN + GROUP_CONCAT?
     * ---------------------------------------------
     * A JOIN + GROUP_CONCAT on the outer query requires a GROUP BY clause.
     * GROUP BY forces MySQL to materialise the full result set before applying
     * LIMIT, which breaks pagination performance and conflicts with DISTINCT a.*
     * under MySQL strict mode. A correlated scalar subquery executes once per
     * outer row and requires no GROUP BY on the outer query whatsoever.
     *
     * The IDs string produced here is consumed by fetchRelated() in getItems()
     * to batch-load full translated records in one query per relationship,
     * after pagination has already been applied.
     *
     * Usage:
     *   MultilingualHelper::addRelatedIdsToQuery(
     *       query:         $query,
     *       mainAlias:     'a',
     *       mainPk:        'id',
     *       junctionTable: '#__alfa_shipment_categories',
     *       junctionFk:    'shipment_id',
     *       junctionValue: 'category_id',
     *       selectAlias:   'category_ids',
     *   );
     *
     * @param  \Joomla\Database\QueryInterface $query          Modified in-place.
     * @param  string                          $mainAlias      Alias of the outer table     (e.g. 'a').
     * @param  string                          $mainPk         PK on the outer table        (e.g. 'id').
     * @param  string                          $junctionTable  Junction/relationship table  (e.g. '#__alfa_shipment_categories').
     * @param  string                          $junctionFk     FK junction → outer table    (e.g. 'shipment_id').
     * @param  string                          $junctionValue  FK junction → related table  (e.g. 'category_id').
     * @param  string                          $selectAlias    Output column alias          (e.g. 'category_ids').
     *
     * @return void
     *
     * @since  1.0.1
     */
    public static function addRelatedIdsToQuery(
        &$query,
        string $mainAlias,
        string $mainPk,
        string $junctionTable,
        string $junctionFk,
        string $junctionValue,
        string $selectAlias,
    ): void {
        $db  = self::getDb();
        $sub = $db->getQuery(true)
            ->select(
                'GROUP_CONCAT(DISTINCT ' . $db->qn("j.{$junctionValue}") . " SEPARATOR ',')"
            )
            ->from($db->qn($junctionTable, 'j'))
            ->where($db->qn("j.{$junctionFk}") . ' = ' . $db->qn("{$mainAlias}.{$mainPk}"));

        $query->select('(' . $sub . ') AS ' . $db->qn($selectAlias));
    }

    /**
     * Fetch records from any table by a list of IDs with optional language resolution.
     *
     * The single batch loader for post-pagination enrichment. Called internally
     * by fetchRelated() — can also be called directly when you need a keyed map
     * without the relationship binding step.
     *
     * FIELD SEMANTICS
     * ---------------
     * $fields (structural, non-translatable)
     *   Columns whose value is identical in every language — e.g. alias, image, ordering.
     *   Always selected directly from $table regardless of lang configuration.
     *
     * $langFields (translatable)
     *   Columns whose value differs per language — e.g. name, description.
     *   When $langTableBase is set: resolved through lang tables via COALESCE:
     *     current language → default language → empty string.
     *   When $langTableBase is '' (non-translatable table such as Joomla core #__users):
     *     selected directly from $table — no lang join performed.
     *
     * LANG TABLE ALIASES
     * ------------------
     * 'lc' = lang current table, 'ld' = lang default table.
     * Short and unambiguous — never clash with the main table alias 'r'.
     *
     * Usage — Alfa translatable table:
     *   $map = MultilingualHelper::getRecordsByIds(
     *       db:                $db,
     *       ids:               [1, 4, 7],
     *       table:             '#__alfa_categories',
     *       fields:            ['alias', 'image'],       // structural
     *       langTableBase:     '#__alfa_categories',
     *       langPrimaryColumn: 'id_category',
     *       langFields:        ['name', 'description'],  // translatable
     *   );
     *   // Returns: [1 => ['id'=>1, 'alias'=>'…', 'image'=>'…', 'name'=>'…', 'description'=>'…'], …]
     *
     * Usage — Joomla core table (no translation):
     *   $map = MultilingualHelper::getRecordsByIds(
     *       db:         $db,
     *       ids:        [5, 12],
     *       table:      '#__users',
     *       langFields: ['name'],
     *   );
     *   // Returns: [5 => ['id'=>5, 'name'=>'John'], …]
     *
     * @param  object   $db                Joomla DatabaseDriver.
     * @param  int[]    $ids               PKs to load.
     * @param  string   $table             Main table                    (e.g. '#__alfa_categories').
     * @param  string   $idColumn          PK column name                (default 'id').
     * @param  string[] $fields            Non-translatable columns to include.
     * @param  string   $langTableBase     Base lang table. Pass '' to skip translation entirely.
     * @param  string   $langPrimaryColumn FK in lang tables             (e.g. 'id_category').
     * @param  string[] $langFields        Translatable fields           (default ['name']).
     *
     * @return array<int, array<string, mixed>>  Associative array keyed by ID.
     *
     * @since  1.0.1
     */
    public static function getRecordsByIds(
        object $db,
        array  $ids,
        string $table,
        string $idColumn          = 'id',
        array  $fields            = [],
        string $langTableBase     = '',
        string $langPrimaryColumn = '',
        array  $langFields        = ['name'],
    ): array {
        if (empty($ids)) {
            return [];
        }

        // 'r' — short neutral alias, never clashes with any caller's scope.
        $alias       = 'r';
        $selectParts = [$db->qn("{$alias}.{$idColumn}")];

        // Structural fields — always read directly from the main table.
        foreach ($fields as $field) {
            $selectParts[] = $db->qn("{$alias}.{$field}");
        }

        $query = $db->getQuery(true)
            ->from($db->qn($table, $alias))
            ->whereIn("{$alias}.{$idColumn}", array_unique(array_map('intval', $ids)));

        if (!empty($langTableBase) && !empty($langPrimaryColumn)) {
            // ── Translatable table — resolve via lang tables ───────────────────
            $existingTables = self::fetchTableList($db);
            $currentTag     = self::getCurrentLanguageTag();
            $defaultTag     = self::getDefaultLanguageTag();
            $currentTable   = $db->replacePrefix("{$langTableBase}_{$currentTag}");
            $defaultTable   = $db->replacePrefix("{$langTableBase}_{$defaultTag}");
            $currentExists  = in_array($currentTable, $existingTables, true);
            $defaultExists  = in_array($defaultTable, $existingTables, true);
            $hasDefault     = ($currentTag !== $defaultTag && $defaultExists);

            // 'lc' = lang current, 'ld' = lang default.
            if ($currentExists) {
                $query->join(
                    'LEFT',
                    $db->qn($currentTable, 'lc')
                    . ' ON ' . $db->qn("lc.{$langPrimaryColumn}")
                    . ' = '  . $db->qn("{$alias}.{$idColumn}")
                );
            }

            if ($hasDefault) {
                $query->join(
                    'LEFT',
                    $db->qn($defaultTable, 'ld')
                    . ' ON ' . $db->qn("ld.{$langPrimaryColumn}")
                    . ' = '  . $db->qn("{$alias}.{$idColumn}")
                );
            }

            foreach ($langFields as $field) {
                $parts = [];

                if ($currentExists) {
                    $parts[] = 'NULLIF(' . $db->qn("lc.{$field}") . ", '')";
                }
                if ($hasDefault) {
                    $parts[] = 'NULLIF(' . $db->qn("ld.{$field}") . ", '')";
                }

                // Degradation:
                //   Both tables exist → COALESCE(NULLIF(current), NULLIF(default))
                //   Only current      → NULLIF(current)
                //   Neither exists    → literal '' (fresh-install safety net)
                $selectParts[] = match (count($parts)) {
                    0       => $db->q('')  . ' AS ' . $db->qn($field),
                    1       => $parts[0]   . ' AS ' . $db->qn($field),
                    default => 'COALESCE(' . implode(', ', $parts) . ') AS ' . $db->qn($field),
                };
            }
        } else {
            // ── Non-translatable table — select fields directly ────────────────
            // e.g. #__users is Joomla core with no lang auxiliary tables.
            foreach ($langFields as $field) {
                $selectParts[] = $db->qn("{$alias}.{$field}");
            }
        }

        $query->select($selectParts);

        return $db->setQuery($query)->loadAssocList($idColumn);
    }

    /**
     * Fetch related records for ONE relationship across a set of items.
     *
     * Pure data method — no mutation. Fires one DB query via getRecordsByIds()
     * and returns a map keyed by the main item's PK. All DB work for this
     * relationship happens here. Call once per relationship before the
     * getItems() foreach loop, then pass each result to bindRelated() inside
     * the loop.
     *
     * INDEXING
     * --------
     * Outer key: the main item's PK ($item->$itemPk, default 'id').
     *            e.g. the shipment ID — NOT the junction FK (shipment_id).
     *            The junction FK was consumed in getListQuery() to build the
     *            GROUP_CONCAT string and is no longer needed here.
     * Inner key: the related record's own PK.
     * Order:     matches the original GROUP_CONCAT string.
     *
     * Return shape
     * ------------
     * [
     *   $itemId => [
     *     $relatedId => ['id' => …, 'name' => '…', 'alias' => '…'],
     *     …
     *   ],
     *   …
     * ]
     *
     * Returns [] when no items carry any IDs for this relationship —
     * bindRelated() handles that gracefully by assigning [] to the property.
     *
     * FIELD SEMANTICS — same as getRecordsByIds()
     * --------------------------------------------
     * $fields     → structural (non-translatable): ['alias', 'image', 'ordering']
     * $langFields → translatable: ['name', 'description']
     * When $langTableBase is '' (e.g. #__users): $langFields selected directly.
     *
     * @param  object   $db                Joomla DatabaseDriver.
     * @param  object[] $items             Paginated item objects — not mutated.
     * @param  string   $idsProperty       Item property with the GROUP_CONCAT string (e.g. 'category_ids').
     * @param  string   $table             Related entity table                        (e.g. '#__alfa_categories').
     * @param  string   $itemPk            Item PK property used as the outer map key (default 'id').
     * @param  string   $idColumn          PK column on the related table             (default 'id').
     * @param  string[] $fields            Non-translatable columns to include.
     * @param  string   $langTableBase     Base lang table. Pass '' to skip translation.
     * @param  string   $langPrimaryColumn FK in lang tables                          (e.g. 'id_category').
     * @param  string[] $langFields        Translatable fields                        (default ['name']).
     *
     * @return array<int, array<int, array<string, mixed>>>  Keyed by item PK then related ID.
     *
     * @since  1.0.1
     */
    public static function fetchRelated(
        object $db,
        array  $items,
        string $idsProperty,
        string $table,
        string $itemPk            = 'id',
        string $idColumn          = 'id',
        array  $fields            = [],
        string $langTableBase     = '',
        string $langPrimaryColumn = '',
        array  $langFields        = ['name'],
    ): array {
        if (empty($items)) {
            return [];
        }

        // ── Collect all unique IDs for this relationship across the full page ──
        $allIds = [];

        foreach ($items as $item) {
            $raw = $item->$idsProperty ?? null;

            if ($raw !== null && $raw !== '') {
                foreach (explode(',', $raw) as $id) {
                    $allIds[] = (int) $id;
                }
            }
        }

        $allIds = array_unique($allIds);

        if (empty($allIds)) {
            return [];
        }

        // ── One query for all records of this relationship on this page ───────
        $records = self::getRecordsByIds(
            db:                $db,
            ids:               $allIds,
            table:             $table,
            idColumn:          $idColumn,
            fields:            $fields,
            langTableBase:     $langTableBase,
            langPrimaryColumn: $langPrimaryColumn,
            langFields:        $langFields,
        );

        // ── Build the per-item map ────────────────────────────────────────────
        // Outer key: $item->$itemPk (e.g. shipment ID, NOT the junction FK).
        // Inner key: related record PK.
        // Order preserved from the original GROUP_CONCAT string.
        $map = [];

        foreach ($items as $item) {
            $raw = $item->$idsProperty ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            foreach (explode(',', $raw) as $id) {
                $id = (int) $id;

                if (isset($records[$id])) {
                    $map[(int) $item->$itemPk][$id] = $records[$id];
                }
            }
        }

        return $map;
    }

    /**
     * Bind one pre-fetched relationship map onto a single item.
     *
     * Pure assignment — no DB calls. Designed to be called inside the
     * getItems() foreach loop once per relationship, alongside any other
     * per-item logic (prices, links, media, etc.) that runs in the same pass.
     *
     * Always assigns the property — even [] when the item has no related
     * records — so templates never receive undefined property warnings.
     *
     * Usage inside getItems():
     *
     *   // All DB work grouped before the loop — one call per relationship:
     *   $catMap = MultilingualHelper::fetchRelated($db, $items, 'category_ids', …);
     *   $manMap = MultilingualHelper::fetchRelated($db, $items, 'manufacturer_ids', …);
     *
     *   // One loop — bind all relationships + any per-item logic:
     *   foreach ($items as $item) {
     *       MultilingualHelper::bindRelated($item, 'categories',    $catMap);
     *       MultilingualHelper::bindRelated($item, 'manufacturers', $manMap);
     *       $item->link = Route::_(…); // other per-item work here
     *   }
     *
     * @param  object                                       $item      Mutated in place.
     * @param  string                                       $property  Property name to set on the item (e.g. 'categories').
     * @param  array<int, array<int, array<string, mixed>>> $map       Return value of fetchRelated().
     *
     * @return void
     *
     * @since  1.0.1
     */
    public static function bindRelated(object $item, string $property, array $map): void
    {
        // Always assign — even [] — so templates never see an undefined property.
        $item->$property = $map[(int) $item->id] ?? [];
    }

    /**
     * One-liner convenience: fetch one relationship and bind it onto all items.
     *
     * Combines fetchRelated() + a foreach with bindRelated() into a single call
     * for models that have no additional per-item logic to run in getItems().
     *
     * When you need extra per-item work (prices, links, media, etc.) in the
     * same loop, use fetchRelated() + bindRelated() separately instead — see
     * ShipmentsModel::getItems() for the recommended two-step pattern.
     *
     * @param  object   $db
     * @param  object[] $items             Mutated in place.
     * @param  string   $idsProperty       Item property with the GROUP_CONCAT string.
     * @param  string   $bindTo            Property name to set on each item.
     * @param  string   $table
     * @param  string   $itemPk
     * @param  string   $idColumn
     * @param  string[] $fields
     * @param  string   $langTableBase
     * @param  string   $langPrimaryColumn
     * @param  string[] $langFields
     *
     * @return void
     *
     * @since  1.0.1
     */
    public static function loadRelated(
        object $db,
        array  $items,
        string $idsProperty,
        string $bindTo,
        string $table,
        string $itemPk            = 'id',
        string $idColumn          = 'id',
        array  $fields            = [],
        string $langTableBase     = '',
        string $langPrimaryColumn = '',
        array  $langFields        = ['name'],
    ): void {
        $map = self::fetchRelated(
            db:                $db,
            items:             $items,
            idsProperty:       $idsProperty,
            table:             $table,
            itemPk:            $itemPk,
            idColumn:          $idColumn,
            fields:            $fields,
            langTableBase:     $langTableBase,
            langPrimaryColumn: $langPrimaryColumn,
            langFields:        $langFields,
        );

        foreach ($items as $item) {
            self::bindRelated($item, $bindTo, $map);
        }
    }

    /**
     * Return the current request language tag in lower-snake format.
     * Example:  "en-GB"  →  "en_gb"
     *
     * @since  1.0.1
     */
    public static function getCurrentLanguageTag(): string
    {
        return self::normaliseTag(
            tag: Factory::getApplication()->getLanguage()->getTag(),
        );
    }

    /**
     * Return the site's default language tag in lower-snake format.
     * Falls back to "en_gb" when no language is explicitly marked as default.
     *
     * @since  1.0.1
     */
    public static function getDefaultLanguageTag(): string
    {
        foreach (LanguageHelper::getLanguages('lang_code') as $langCode => $language) {
            if (!empty($language->default)) {
                return self::normaliseTag(tag: $langCode);
            }
        }

        return 'en_gb';
    }

    // =========================================================================
    //  Private — data processing
    // =========================================================================

    /**
     * Convert a Joomla language map into a flat list of lower-snake codes.
     *
     * @param  array $languages  Output of LanguageHelper::getLanguages('lang_code').
     *
     * @return string[]  e.g. ['en_gb', 'el_gr']
     */
    private static function buildLangCodeList(array $languages): array
    {
        return array_map(
            static fn(string $code): string => self::normaliseTag(tag: $code),
            array_keys($languages),
        );
    }

    /**
     * Parse a flat POST / data array into a per-language structure.
     *
     * Keys following the convention  fieldName_langCode  are split into:
     *   $languageData[$langCode][$fieldName] = $value
     *
     * Also builds a $fallbackData map — the first non-empty value per field
     * across all languages — used to fill gaps in sparse translations.
     *
     * @param  array    $data       Flat input (passed $data or jform POST).
     * @param  string[] $langCodes  Lower-snake codes  (e.g. ['en_gb', 'el_gr']).
     *
     * @return array  [ $languageData, $fallbackData ]
     */
    private static function extractLanguageData(array $data, array $langCodes): array
    {
        $languageData = [];
        $fallbackData = [];

        foreach ($data as $key => $value) {
            foreach ($langCodes as $langCode) {
                $suffix = '_' . $langCode;

                if (!str_ends_with($key, $suffix)) {
                    continue;
                }

                $fieldName                           = substr($key, 0, -strlen($suffix));
                $languageData[$langCode][$fieldName] = $value;

                if (!empty($value) && !isset($fallbackData[$fieldName])) {
                    $fallbackData[$fieldName] = $value;
                }

                break; // Each key can match only one language suffix.
            }
        }

        return [$languageData, $fallbackData];
    }

    /**
     * Fill empty field values from the fallback map and process slug/alias fields.
     *
     * Slug fields ($aliasFields): auto-generate from name/title when blank, then sanitise.
     * All other fields: copy from $fallbackData when blank.
     *
     * @param  array    $fields       [ fieldName => value ] for one language.
     * @param  array    $fallbackData First non-empty value per field (cross-language).
     * @param  string[] $aliasFields  Field names to treat as URL slugs.
     * @param  object   $app          Joomla application (for the unicodeslugs setting).
     *
     * @return array  Processed fields.
     */
    private static function applyFallbackAndSlugs(
        array  $fields,
        array  $fallbackData,
        array  $aliasFields,
        object $app,
    ): array {
        foreach ($fields as $fieldName => &$value) {
            if (in_array($fieldName, $aliasFields, true)) {
                $value = self::resolveSlugValue(
                    rawValue:     $value,
                    fields:       $fields,
                    fallbackData: $fallbackData,
                    app:          $app,
                );
                continue;
            }

            if (empty(trim((string) $value)) && isset($fallbackData[$fieldName])) {
                $value = $fallbackData[$fieldName];
            }
        }
        unset($value);

        return $fields;
    }

    /**
     * Resolve the final value for a slug/alias field.
     *
     * Priority:
     *   1. Use the submitted value if not blank.
     *   2. Fall back to name / title from the same language row.
     *   3. Fall back to name / title from $fallbackData (another language).
     *   4. Sanitise the chosen source through OutputFilter.
     *
     * @param  string $rawValue     Submitted value (may be empty).
     * @param  array  $fields       All fields for the current language.
     * @param  array  $fallbackData Cross-language fallback values.
     * @param  object $app          Joomla application (for unicodeslugs config).
     *
     * @return string  Sanitised slug.
     */
    private static function resolveSlugValue(
        string $rawValue,
        array  $fields,
        array  $fallbackData,
        object $app,
    ): string {
        $source = !empty(trim($rawValue))
            ? $rawValue
            : ($fields['name']
                ?? $fields['title']
                ?? $fallbackData['name']
                ?? $fallbackData['title']
                ?? '');

        return $app->get('unicodeslugs') == 1
            ? OutputFilter::stringUrlUnicodeSlug($source)
            : OutputFilter::stringURLSafe($source);
    }

    // =========================================================================
    //  Private — database schema
    // =========================================================================

    /**
     * Return the SQL column definition for a given field name.
     *
     * Fields containing any LONGTEXT_HINTS substring get  LONGTEXT NULL.
     * Everything else gets  VARCHAR(255) NOT NULL DEFAULT ''.
     */
    private static function columnTypeForField(string $fieldName): string
    {
        $lower = strtolower($fieldName);

        foreach (self::LONGTEXT_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return 'LONGTEXT NULL';
            }
        }

        return "VARCHAR(255) NOT NULL DEFAULT ''";
    }

    /**
     * CREATE TABLE IF NOT EXISTS for a new language auxiliary table.
     *
     * @throws \RuntimeException
     */
    private static function createLangTable(
        $db,
        string $langTableName,
        string $primaryColumnName,
        array  $fields,
    ): void {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);

        $colDefs = $db->qn($primaryColumnName) . ' INT(11) UNSIGNED NOT NULL';

        foreach (array_keys($fields) as $fieldName) {
            if (!self::isSafeIdentifier(name: $fieldName)) {
                continue;
            }
            $colDefs .= ', ' . $db->qn($fieldName) . ' ' . self::columnTypeForField(fieldName: $fieldName);
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$langTableName} (
                    {$colDefs},
                    PRIMARY KEY (" . $db->qn($primaryColumnName) . ")
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[MultilingualHelper::createLangTable] Could not create "' . $langTableName
                . '": ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Add missing columns to an existing language table (ALTER TABLE ADD COLUMN).
     *
     * Reads information_schema before each ALTER so the statement is only
     * executed when the column genuinely does not exist yet.
     *
     * @throws \RuntimeException
     */
    private static function ensureColumnsExist(
        $db,
        string $langTableName,
        array  $fields,
    ): void {
        $bareTableName = str_replace('`', '', $langTableName);

        try {
            $existingColumns = $db->setQuery(
                "SELECT COLUMN_NAME
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = " . $db->q($bareTableName)
            )->loadColumn();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[MultilingualHelper::ensureColumnsExist] Could not read column list for "'
                . $langTableName . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        foreach (array_keys($fields) as $fieldName) {
            if (!self::isSafeIdentifier(name: $fieldName)
                || in_array($fieldName, $existingColumns, true)) {
                continue;
            }

            try {
                $db->setQuery(
                    'ALTER TABLE ' . $langTableName
                    . ' ADD COLUMN ' . $db->qn($fieldName)
                    . ' ' . self::columnTypeForField(fieldName: $fieldName)
                )->execute();

                self::log(
                    callerMethod: __METHOD__,
                    message:      'Added column "' . $fieldName . '" to "' . $langTableName . '".',
                    priority:     Log::INFO,
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    '[MultilingualHelper::ensureColumnsExist] ALTER TABLE failed for column "'
                    . $fieldName . '" on "' . $langTableName . '": ' . $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }

    /**
     * Execute REPLACE INTO (upsert) for a single language row.
     *
     * REPLACE INTO = DELETE + INSERT on duplicate PK — ensures a clean update
     * without a prior SELECT.
     *
     * @throws \RuntimeException
     */
    private static function upsertLangRow(
        $db,
        string $langTableName,
        string $primaryColumnName,
        int    $currentId,
        array  $fields,
    ): void {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);

        $columns = [$db->qn($primaryColumnName)];
        $values  = [$db->q($currentId)];

        foreach ($fields as $fieldName => $value) {
            if (!self::isSafeIdentifier(name: $fieldName)) {
                continue;
            }
            $columns[] = $db->qn($fieldName);
            $values[]  = $db->q((string) $value);
        }

        if (count($columns) === 1) {
            self::log(
                callerMethod: __METHOD__,
                message:      'No valid columns to upsert into "' . $langTableName . '". Row skipped.',
                priority:     Log::WARNING,
            );
            return;
        }

        $sql = 'REPLACE INTO ' . $langTableName
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $values) . ')';

        try {
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[MultilingualHelper::upsertLangRow] REPLACE INTO failed for "'
                . $langTableName . '", ID ' . $currentId . ': ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    // =========================================================================
    //  Private — utilities
    // =========================================================================

    /**
     * Fetch all physical table names from the active database.
     * Result is statically cached for the duration of the request.
     */
    private static function fetchTableList($db): array
    {
        static $tables = null;

        if ($tables === null) {
            try {
                $tables = $db->setQuery('SHOW TABLES')->loadColumn();
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    '[MultilingualHelper::fetchTableList] Could not retrieve table list: '
                    . $e->getMessage(),
                    previous: $e,
                );
            }
        }

        return $tables;
    }

    /** Retrieve the DatabaseDriver from the Joomla DI container. */
    private static function getDb()
    {
        try {
            return Factory::getContainer()->get('DatabaseDriver');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                '[MultilingualHelper::getDb] Could not retrieve DatabaseDriver: '
                . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /** Normalise a Joomla language tag to lower-snake format:  "en-GB" → "en_gb". */
    private static function normaliseTag(string $tag): string
    {
        return strtolower(str_replace('-', '_', $tag));
    }

    /**
     * Return true when $name is a safe SQL identifier (letters, digits, underscore).
     * Logs a WARNING and returns false otherwise — callers skip the offending field.
     */
    private static function isSafeIdentifier(string $name): bool
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            return true;
        }

        self::log(
            callerMethod: __METHOD__,
            message:      'Skipped unsafe SQL identifier: "' . $name . '"',
            priority:     Log::WARNING,
        );
        return false;
    }

    /**
     * Throw immediately when $name is not a safe SQL identifier.
     * Used for structurally required identifiers (e.g. PK column names).
     *
     * @throws \InvalidArgumentException
     */
    private static function assertSafeIdentifier(string $name, string $callerMethod): void
    {
        if (!self::isSafeIdentifier(name: $name)) {
            throw new \InvalidArgumentException(
                '[' . $callerMethod . '] Unsafe SQL identifier: "' . $name . '"',
            );
        }
    }

    /**
     * Validate the three required arguments shared by save / get operations.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidArguments(
        int    $currentId,
        string $primaryColumnName,
        string $tableName,
        string $callerMethod,
    ): void {
        if ($currentId <= 0) {
            throw new \InvalidArgumentException(
                '[' . $callerMethod . '] $currentId must be > 0, got: ' . $currentId,
            );
        }
        if (empty(trim($primaryColumnName))) {
            throw new \InvalidArgumentException(
                '[' . $callerMethod . '] $primaryColumnName must not be empty.',
            );
        }
        if (empty(trim($tableName))) {
            throw new \InvalidArgumentException(
                '[' . $callerMethod . '] $tableName must not be empty.',
            );
        }
    }

    // =========================================================================
    //  Logging
    // =========================================================================

    /**
     * Write a message to the component log file.
     *
     * Format:  [MultilingualHelper::callingMethod] Human-readable message
     * Always pass __METHOD__ as $callerMethod so log lines are grep-friendly.
     *
     * @param  string $callerMethod  Pass __METHOD__ at every call site.
     * @param  string $message       Human-readable description.
     * @param  int    $priority      Log::DEBUG | Log::INFO | Log::WARNING | Log::ERROR
     */
    private static function log(
        string $callerMethod,
        string $message,
        int    $priority = Log::DEBUG,
    ): void {
        static $registered = false;

        if (!$registered) {
            Log::addLogger(
                options:    ['text_file' => 'com_alfa.php'],
                priorities: Log::ALL,
                categories: [self::LOG_CATEGORY],
            );
            $registered = true;
        }

        Log::add(
            entry:    '[' . $callerMethod . '] ' . $message,
            priority: $priority,
            category: self::LOG_CATEGORY,
        );
    }

    /**
     * Delete multilingual rows for one or more parent records across every
     * installed language table.
     *
     * Iterates over every language returned by LanguageHelper::getLanguages(),
     * not a hardcoded list — so adding a new site language Just Works.
     * Missing language tables are silently skipped (fresh-install safety).
     *
     * @param  int|int[]  $ids                One or more parent record IDs.
     * @param  string     $primaryColumnName  PK column in the lang tables (e.g. "id_orderstatus").
     * @param  string     $tableName          Base table with Joomla prefix (e.g. "#__deliveryplus_order_status").
     *
     * @return bool  True on success, false when there is nothing to delete.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException          On any unrecoverable database error.
     */
    public static function deleteMultilingualData(
        int|array $ids,
        string    $primaryColumnName,
        string    $tableName,
    ): bool {
        $idList = array_values(array_filter(
            array_map('intval', is_array($ids) ? $ids : [$ids]),
            static fn(int $id): bool => $id > 0,
        ));

        if (empty($idList)) {
            return false;
        }

        if (empty(trim($primaryColumnName)) || empty(trim($tableName))) {
            throw new \InvalidArgumentException(
                '[MultilingualHelper::deleteMultilingualData] $primaryColumnName and $tableName are required.',
            );
        }

        $db             = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes      = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );

        $db->transactionStart();

        try {
            foreach ($langCodes as $langCode) {
                $langTableName = $db->replacePrefix("{$tableName}_{$langCode}");

                if (!in_array($langTableName, $existingTables, true)) {
                    continue; // Language table doesn't exist for this language — nothing to delete.
                }

                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->qn($langTableName))
                        ->whereIn($db->qn($primaryColumnName), $idList)
                )->execute();

                self::log(
                    callerMethod: __METHOD__,
                    message:      'Deleted ' . count($idList) . ' row(s) from "' . $langTableName . '".',
                    priority:     Log::DEBUG,
                );
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            self::log(
                callerMethod: __METHOD__,
                message:      'Transaction rolled back: ' . $e->getMessage(),
                priority:     Log::ERROR,
            );
            throw new \RuntimeException(
                '[MultilingualHelper::deleteMultilingualData] Translations delete failed for "'
                . $tableName . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        return true;
    }
}