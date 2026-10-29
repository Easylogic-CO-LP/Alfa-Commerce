<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\MultilingualFieldInterface;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Setup\ContentLanguageAddedNotice;
use Alfa\Component\Alfa\Administrator\Service\Setup\SeededNames;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\String\StringHelper;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * MultilingualHelper
 *
 * Central utility for all multilingual read / write operations in the Alfa component.
 *
 * Data model
 * Every translatable entity has one auxiliary table per installed language:
 *   #__alfa_items_en_gb,  #__alfa_items_el_gr, ...
 *
 * Each lang table shares a single PK column (e.g. "id_item") with the parent
 * table and stores one column per translatable field (name, alias, short_desc ...).
 * Tables and columns are created / migrated automatically on first save.
 *
 * The main table (e.g. #__alfa_categories) does NOT need to store translatable
 * fields. Joomla's Table::bind() silently ignores keys that have no matching
 * column, so passing name_en_gb or even name in $data causes no errors.
 * All translatable data lives exclusively in the language tables.
 *
 * Form field naming convention
 * Translatable form fields must follow the pattern:  fieldName_langCode
 * Example POST keys:  name_en_gb,  alias_el_gr,  meta_title_en_gb
 * The MultilingualTextField form-field type generates this naming automatically.
 *
 * Alias / slug fields
 * Alias is a translatable field: every language stores its own URL-safe alias
 * in its lang table.  Fields listed in $aliasFields are treated as slugs:
 * auto-generated from name/title when blank and sanitised via OutputFilter.
 * Default: ['alias'].  Pass [] to opt out entirely.
 *
 * Main table alias
 * Alias is a fully translatable field: it lives ONLY in the language tables
 * (one per-language slug each). The main table keeps NO alias column and there
 * is NO fallback to it: every alias read (lists, routing) goes through the
 * language tables. Models must never write alias to the main table.
 *
 * List query joins
 * addMultilingualJoinToQuery() joins the current-language table and optionally
 * the default-language table. The COALESCE reads ONLY from language tables;
 * it does NOT fall back to the main table column since translatable fields
 * no longer exist there.
 *
 * List query: related entity IDs
 * addRelatedIdsToQuery() adds one correlated scalar subquery per relationship.
 * No JOIN or GROUP BY on the outer query; pagination totals stay correct.
 * Full records are resolved in getItems() after pagination via fetchRelated().
 *
 * getItems: related entity enrichment
 * Three methods handle post-pagination enrichment; use whichever fits the model:
 *
 * fetchRelated()  Pure data, no mutation. Fires one query per relationship,
 *                 returns a [$itemId => [$relatedId => $record]] map.
 *                 Call before the foreach loop so all DB work is grouped.
 *
 * bindRelated()   Pure assignment, no DB. Binds one map onto one item.
 *                 Call inside the foreach loop, one call per relationship.
 *                 All other per-item logic (prices, links, media) goes in
 *                 the same loop.
 *
 * loadRelated()   One-liner convenience: fetchRelated() + foreach bindRelated().
 *                 Use when no extra per-item logic is needed.
 *
 * FIELD SEMANTICS FOR getRecordsByIds() / fetchRelated() / loadRelated()
 * $fields     - structural (non-translatable) columns read directly from the
 *               main table. Same value in every language.
 *               e.g. ['alias', 'image', 'ordering']
 * $langFields - translatable columns whose value differs per language.
 *               When $langTableBase is set: resolved through lang tables via
 *               COALESCE (current language -> default language -> empty string).
 *               When $langTableBase is '' (non-translatable table e.g. #__users):
 *               selected directly from the main table without any join.
 *               e.g. ['name', 'description']
 *
 * Logging
 * All messages go to  logs/com_alfa.php  under category "com_alfa.multilingual"
 * using the consistent format:
 *   [MultilingualHelper::methodName] Human-readable message
 *
 * @since  1.0.0
 */
class MultilingualHelper
{
    //  Constants

    /** Joomla log category for every log() call in this class. */
    private const LOG_CATEGORY = 'com_alfa.multilingual';

    /**
     * Request-scoped content language, set by the API dispatcher from ?lang=.
     * null = follow the application's UI language (site/admin behaviour).
     *
     *
     * @since  2.0.0
     */
    private static ?string $contentLanguageOverride = null;

    /**
     * Every table of the database, read once per request ({@see self::fetchTableList()}) and kept
     * current with the language tables this class creates ({@see self::createLangTable()}): a fill
     * that follows the language sync in the same request sees the tables the sync made. null until read.
     *
     * @var string[]|null
     *
     * @since  2.1.0
     */
    private static ?array $tableList = null;

    /**
     * The default content language as resolved for this request ({@see self::getDefaultLanguageTag()}):
     * the configured site language and the language used, both lower-snake (configured '' when unset),
     * whether the configured one is a published content language, and whether the configuration could
     * be read at all. null until resolved.
     * @var array{configured: string, used: string, valid: bool, read: bool, published: bool}|null
     * @since  2.1.1
     */
    private static ?array $defaultLanguage = null;

    /**
     * Substrings that map a column to LONGTEXT instead of VARCHAR(255).
     * Matched case-insensitively via str_contains() against the field name.
     */
    private const LONGTEXT_HINTS = [
        'desc', 'content', 'body', 'text', 'note', 'html', 'full_', 'short_', 'meta_', 'message',
        'positions', 'json',
    ];

    //  Public API

    /**
     * Declare one storage field per language for every translatable field on a form.
     *
     * A multilingual field draws jform[short_desc_el_gr] while the XML declares only short_desc,
     * and Joomla carries declared fields and nothing else: Form::filter() copies a key only when
     * a field matched it, and Form::bindLevel() keeps a scalar only when findField() does. The
     * typed translations were therefore stripped out of the validated data, stripped again out of
     * the session stash a failed save writes, and could not be bound back at render time, so the
     * fields fell through to their language-table read and a failed save repainted the form from
     * the stored row, silently discarding the edit and re-saving the old text.
     *
     * NOTHING HAS TO BE LISTED HERE. A form's translatable fields are the ones bound to a
     * language table (the xpath), and a field type joins the contract by implementing
     * {@see MultilingualFieldInterface}. Adding a translatable field is an XML edit and nothing
     * else; adding a field TYPE is a class and nothing else.
     *
     * Call it from preprocessForm(), the last hook before binding, so a field declared here is a
     * real field for filtering, validation, the stash and binding alike.
     *
     * @param Form $form The edit form to complete.
     *
     *
     * @since  2.0.0
     */
    public static function declareStorageFields(Form $form): void
    {
        $xml = $form->getXml();

        if (!$xml instanceof SimpleXMLElement) {
            return;
        }

        // Root level only, and only what a language table backs. A grouped or subform field
        // renders a name no flat root key could ever fill, and a field with no language table
        // carries its translations inline under its own declared name.
        $owners = $xml->xpath('//field[@name][@type][@multilingual_table][not(ancestor::field)][not(ancestor::fields[@name])]') ?: [];

        foreach ($owners as $owner) {
            // The field class says whether it posts one key per language. EmailPositions is why
            // the question is asked of the class and not of the XML: it is bound to a language
            // table too, but posts <field>_<lang>_<slot>, which is a different shape.
            if (!FormHelper::loadFieldType((string) $owner['type']) instanceof MultilingualFieldInterface) {
                continue;
            }

            $name = (string) $owner['name'];

            // The owner's filter governs its translations too, so the form XML stays the single
            // lever: filter="safehtml" on the field now actually reaches the stored translation.
            // Unfiltered is the deliberate default: an editor's HTML must reach the database the
            // way it always has, and an ABSENT filter attribute is not the same thing: Joomla
            // falls through to InputFilter and would strip every tag.
            $filter = (string) ($owner['filter'] ?? '') ?: 'raw';

            foreach (self::getLanguageTags() as $tag) {
                $storageName = self::buildLangFieldKey($name, $tag);

                // Form::setField() appends a duplicate rather than skipping it, so the guard has
                // to be explicit, and a form that declares its own key of that name keeps it.
                if ($form->getField($storageName) !== false) {
                    continue;
                }

                // Appended straight onto the form ROOT, which is where Form::setField() lands a
                // field whose fieldset does not exist, but without the eight whole-document
                // xpath queries its syncPaths() runs per call to re-read addfieldpath/prefix
                // attributes this node does not carry (128 of them on the item form alone). At
                // root the storage fields sit outside every fieldset, so getFieldsets() grows no
                // phantom tab and renderFieldset() can never draw one.
                $element = $xml->addChild('field');
                $element->addAttribute('name', $storageName);
                $element->addAttribute('type', 'MultilingualStorage');
                $element->addAttribute('filter', $filter);
            }
        }
    }

    /**
     * Merge the raw jform POST back over validated form data without discarding the validation.
     *
     * Joomla's filter pass emits declared fields and nothing else, so a key the form does not
     * declare never reaches the model. A blanket array_merge($validated, $raw) would recover
     * those keys but also overwrite every validated field with its raw value and resurrect the
     * housekeeping fields the form deliberately unsets, defeating the whole form filter.
     *
     * This recovers ONLY the keys the form did not already validate: each declared field keeps
     * its filtered value, the never-trust fields are never taken from the client, and a field
     * the form unset at runtime is never restored from the request that was just refused.
     *
     * NOT FOR TRANSLATIONS. The per-language keys used to arrive here, and were re-cleaned here
     * to match their base field's safehtml. They are declared form fields now
     * ({@see self::declareStorageFields()}), so they are already in $validated with their own
     * filter applied and this method skips them like any other validated key. Column-mode
     * translations have exactly one path (declared sibling, Joomla's filter and validation,
     * validated data, form state) and no raw-POST route back in.
     *
     * @param array $validated Data returned by the form validate()/filter() pass.
     * @param array $raw Raw jform POST array (filter 'raw').
     * @param Form|null $form Entity form, asked which fields it unsets.
     *
     * @return array The merged data safe to pass to the table.
     *
     * @since  2.0.0
     */
    public static function mergeValidatedFormData(array $validated, array $raw, ?Form $form = null): array
    {
        // A FLOOR, for the case where no form is available to ask. These three
        // are unset by every entity form, so they are named outright.
        static $neverTrust = ['checked_out', 'checked_out_time', 'modified_by'];

        // EVERY field the form unsets, asked of the form rather than listed here.
        //
        // This loop used to collect only the safehtml names, and the hard-coded
        // trio above was the whole guard. That conflates "never declared" with
        // "deliberately dropped": a model may unset a field AT RUNTIME (
        // SeriesModel does exactly that, making next_number and doc_type
        // read-only once the series has issued documents), and the recovery below
        // then restored both from the client verbatim, so a fiscal counter could
        // be rewritten by the same POST the guard had just refused. Measured:
        // 501 -> 999999 on a series with an issued document.
        //
        // Static declarations are covered too, which closes cartrule.xml's
        // created_by/modified: authorship, the very thing this comment has
        // always claimed to protect.
        $unsetFields = [];

        if ($form !== null) {
            foreach ($form->getFieldset() as $field) {
                $name = (string) $field->getAttribute('name');

                if ($name !== '' && strtolower((string) $field->getAttribute('filter')) === 'unset') {
                    $unsetFields[$name] = true;
                }
            }
        }

        foreach ($raw as $key => $value) {
            // Keep every validated field; never resurrect a field the form unset.
            if (
                array_key_exists($key, $validated)
                || \in_array($key, $neverTrust, true)
                || isset($unsetFields[$key])
            ) {
                continue;
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * Save multilingual data for one record.
     *
     * Upserts per-language fields into their respective lang tables.
     * Missing tables / columns are created automatically.
     * All language rows are written inside a single DB transaction.
     *
     * Each language stores what was entered for it, and nothing else. An empty field
     * stays empty: reads fall back to the default language while it is, so another
     * language's text is never copied in, and the edit form keeps showing translators
     * what is still untranslated.
     *
     * Prefer passing $data explicitly (the model already has it).
     * The POST fallback exists only for utility / CLI call-sites.
     *
     * Alias / slug fields
     * Fields listed in $aliasFields are treated as URL slugs and sanitised. A blank one
     * is generated from the same language's name/title (left empty when that is empty
     * too) and made UNIQUE per language (incrementing -2, -3, ... on collision),
     * counting the default language's alias of every record that has none of its own
     * in that language, since the router resolves that one there. A typed alias is
     * written as typed and never renamed: a caller that writes one asks
     * {@see self::aliasClashes()} before it writes anything.
     * Uniqueness is scoped by the main-table columns in $aliasUniqueScope: pass e.g.
     * ['parent_id'] so a category alias is only unique within its parent. Pass [] for
     * global uniqueness across the whole language table, required for entities like
     * items, which can belong to many categories and so need a site-wide-unique
     * slug for unambiguous routing.
     *
     * @param int $currentId PK of the parent record.  Must be > 0.
     * @param string $primaryColumnName PK column in lang tables  (e.g. "id_item").
     * @param string $tableName Base table with Joomla prefix  (e.g. "#__alfa_items").
     * @param array $data Form data to persist.  Falls back to jform POST when empty.
     * @param string[] $aliasFields Field names to treat as URL slugs.
     *                              Default ['alias'].  Pass [] to disable.
     * @param string[] $aliasUniqueScope Main-table column(s) scoping alias uniqueness.
     *                                   [] = global uniqueness across the lang table.
     * @param string $mainPrimaryColumn Main-table PK used to join for scope (default 'id').
     *
     * @return bool True on success, false when there is nothing to save.
     *
     * @throws InvalidArgumentException On bad arguments.
     * @throws RuntimeException On any unrecoverable database error.
     * @since  1.0.0
     */
    public static function saveMultilingualData(
        int $currentId,
        string $primaryColumnName,
        string $tableName,
        array $data = [],
        array $aliasFields = ['alias'],
        array $aliasUniqueScope = [],
        string $mainPrimaryColumn = 'id',
    ): bool {
        self::assertValidArguments(
            currentId:         $currentId,
            primaryColumnName: $primaryColumnName,
            tableName:         $tableName,
            callerMethod:      __METHOD__,
        );

        $app = Factory::getApplication();

        if (empty($data)) {
            // Safety-net fallback: callers should always pass $data explicitly.
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

        $db = self::getDb();
        $langCodes = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );

        $languageData = self::extractLanguageData(
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

        // NESTED-CAPABLE CODE NESTS AS A SAVEPOINT, NEVER AS A SECOND
        // START TRANSACTION.
        //
        // This helper is called from inside save paths that have already opened
        // a transaction. A bare transactionStart() there issues a second START
        // TRANSACTION, which MySQL treats as an IMPLICIT COMMIT of the caller's
        // open work, and Joomla's depth counter is reset to 1, so the caller's
        // later commit/rollback operates on THIS block instead of its own.
        //
        // Measured: a combination-grid save that failed after its delete
        // cascade left the variants deleted, their price rows gone and their
        // inventory zeroed, while the operator was shown an error and the outer
        // rollback had nothing left to undo.
        //
        // All three calls must agree: starting as a savepoint but committing
        // without one would commit the caller's transaction early, which is the
        // same defect wearing a different hat.
        //
        // NO DDL BETWEEN A transactionStart() AND ITS COMMIT, for the same reason.
        // MySQL commits implicitly on CREATE/ALTER TABLE, and Joomla's depth counter
        // never finds out. The tables and columns this save needs are therefore
        // made here, before the transaction; a caller that has ALREADY opened one
        // runs {@see self::ensureLangSchemaForData()} before it does, and this
        // call then finds nothing left to do.
        self::ensureLangSchemaForData(
            tableName:         $tableName,
            primaryColumnName: $primaryColumnName,
            data:              $data,
        );

        // Every language's slugs first: the uniqueness of the default language's alias
        // depends on the languages in which this record has no alias of its own. Only a
        // slug generated here (the alias was posted blank) is made unique.
        $resolvedByLang = [];
        $generatedByLang = [];

        foreach ($languageData as $langCode => $langFields) {
            $resolvedByLang[$langCode] = self::resolveSlugs(
                fields:      $langFields,
                aliasFields: $aliasFields,
                app:         $app,
                langCode:    $langCode,
            );

            foreach ($aliasFields as $aliasField) {
                if (\array_key_exists($aliasField, $langFields)
                    && trim((string) $langFields[$aliasField]) === ''
                    && (string) ($resolvedByLang[$langCode][$aliasField] ?? '') !== '') {
                    $generatedByLang[$langCode][$aliasField] = true;
                }
            }
        }

        $db->transactionStart(true);

        try {
            foreach ($resolvedByLang as $langCode => $processedFields) {
                $langTableName = $db->replacePrefix($tableName . '_' . $langCode);

                // Make each generated slug unique among the aliases the router resolves in
                // this language (scoped by $uniqueScope), incrementing -2/-3/... on collision.
                $processedFields = self::ensureUniqueSlugs(
                    db:                $db,
                    langTableName:     $langTableName,
                    primaryColumnName: $primaryColumnName,
                    currentId:         $currentId,
                    fields:            $processedFields,
                    aliasFields:       $aliasFields,
                    mainTableName:     $tableName,
                    mainPrimaryColumn: $mainPrimaryColumn,
                    aliasUniqueScope:  $aliasUniqueScope,
                    langCode:          $langCode,
                    savedByLang:       $resolvedByLang,
                    generated:         $generatedByLang[$langCode] ?? [],
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

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            self::log(
                callerMethod: __METHOD__,
                message:      'Transaction rolled back: ' . $e->getMessage(),
                priority:     Log::ERROR,
            );
            throw new RuntimeException(
                '[MultilingualHelper::saveMultilingualData] Translations save failed for "'
                . $tableName . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        // A language added later counts what it still lacks: the counts follow what was saved.
        ContentLanguageAddedNotice::afterSave(tableName: $tableName, langCodes: array_keys($languageData));

        return true;
    }

    /**
     * The URL addresses a save would share with other records, judged before anything is written.
     *
     * A record answers in each content language at its effective alias there: its own alias in
     * that language, else its default-language alias ({@see self::getDefaultLanguageTag()}). For
     * every language, the effective alias the posted data leaves the record with is compared with
     * the effective alias of every other record in the same scope (the same parent category, say).
     *
     * - An alias the save GENERATES (posted blank, made from the name in that language) is made
     *   unique when it is written ({@see self::saveMultilingualData()}), so it never clashes.
     * - A clash the save would CREATE goes under 'refuse': a typed or changed alias, a language
     *   left without an alias of its own, or a move to another scope. The caller refuses the save
     *   before it writes anything.
     * - A clash that was already there (data from 2.1.0, say) goes under 'warn': the save goes
     *   ahead, nothing is renamed, and the caller shows the warning after it.
     *
     * Alfa generates and makes unique only an empty alias, and never rewrites one that is not.
     * Each message names the alias, the language and the other record. The scope values are the
     * posted ones (a new parent_id), else the stored ones.
     *
     * @param string $tableName Base main table (e.g. "#__alfa_categories").
     * @param string $primaryColumnName PK column of the language tables (e.g. "id_category").
     * @param int $currentId The record; 0 for a new one.
     * @param array $data The posted data, with its per-language keys (e.g. alias_en_gb).
     * @param string[] $aliasFields The alias fields ({@see MultilingualAliasConfig::FIELDS}).
     * @param string[] $aliasUniqueScope Main-table columns scoping uniqueness ([] = global).
     * @param string $mainPrimaryColumn Main-table PK.
     *
     * @return array{refuse: string[], warn: string[]} The messages, translated.
     *
     * @since  2.1.1
     */
    public static function aliasClashes(
        string $tableName,
        string $primaryColumnName,
        int $currentId,
        array $data,
        array $aliasFields,
        array $aliasUniqueScope = [],
        string $mainPrimaryColumn = 'id',
    ): array {
        $result = ['refuse' => [], 'warn' => []];
        // [refuse|warn][other record][alias] => the languages, so one message names each clash once.
        $clashes = ['refuse' => [], 'warn' => []];

        if ($aliasFields === [] || !self::isSafeIdentifier(name: $primaryColumnName)
            || !self::isSafeIdentifier(name: $mainPrimaryColumn)) {
            return $result;
        }

        $db = self::getDb();
        $app = Factory::getApplication();
        $languages = LanguageHelper::getLanguages('lang_code');
        $langCodes = self::buildLangCodeList(languages: $languages);
        $defaultCode = self::getDefaultLanguageTag();
        $existingTables = self::fetchTableList(db: $db);
        $mainTableReal = $db->replacePrefix($tableName);
        $posted = self::extractLanguageData(data: $data, langCodes: $langCodes);

        $storedScope = $currentId > 0
            ? self::loadScopeValues(
                db:                $db,
                mainTableReal:     $mainTableReal,
                mainPrimaryColumn: $mainPrimaryColumn,
                currentId:         $currentId,
                aliasUniqueScope:  $aliasUniqueScope,
            )
            : [];
        $postedScope = [];

        foreach ($aliasUniqueScope as $column) {
            if (\array_key_exists($column, $data) && \is_scalar($data[$column]) && self::isSafeIdentifier(name: $column)) {
                $postedScope[$column] = $data[$column];
            } elseif (\array_key_exists($column, $storedScope)) {
                $postedScope[$column] = $storedScope[$column];
            }
        }

        $tables = [];

        foreach ($langCodes as $code) {
            $table = $db->replacePrefix($tableName . '_' . $code);

            if (\in_array($table, $existingTables, true)) {
                $tables[$code] = $table;
            }
        }

        $defaultTable = $tables[$defaultCode] ?? '';

        foreach ($aliasFields as $field) {
            if (!self::isSafeIdentifier(name: $field)) {
                continue;
            }

            $stored = [];
            $after = [];
            $generated = [];

            foreach ($tables as $code => $table) {
                $stored[$code] = $currentId > 0
                    ? self::readStoredValue(
                        db:                $db,
                        table:             $table,
                        primaryColumnName: $primaryColumnName,
                        id:                $currentId,
                        field:             $field,
                    )
                    : '';

                if (\array_key_exists($field, $posted[$code] ?? [])) {
                    $raw = trim((string) $posted[$code][$field]);
                    $after[$code] = self::resolveSlugValue(
                        rawValue: $raw,
                        fields:   $posted[$code],
                        app:      $app,
                        langCode: $code,
                    );
                    $generated[$code] = $raw === '' && $after[$code] !== '';
                } else {
                    $after[$code] = $stored[$code];
                    $generated[$code] = false;
                }
            }

            foreach ($tables as $code => $table) {
                $own = $after[$code] !== '';
                $effective = $own ? $after[$code] : ($after[$defaultCode] ?? '');

                // A generated alias is made unique as it is written.
                if ($effective === '' || ($own ? $generated[$code] : ($generated[$defaultCode] ?? false))) {
                    continue;
                }

                $holders = self::effectiveAliasHolders(
                    db:                $db,
                    mainTableReal:     $mainTableReal,
                    mainPrimaryColumn: $mainPrimaryColumn,
                    langTable:         $table,
                    defaultTable:      $defaultTable,
                    primaryColumnName: $primaryColumnName,
                    field:             $field,
                    alias:             $effective,
                    currentId:         $currentId,
                    scopeValues:       $postedScope,
                );

                if ($holders === []) {
                    continue;
                }

                // A clash this save found: the other record answered at this record's address before
                // it too, in the same scope (the column's collation decides what one address is).
                $before = [];
                $effectiveBefore = $currentId > 0 ? ($stored[$code] !== '' ? $stored[$code] : ($stored[$defaultCode] ?? '')) : '';

                if ($effectiveBefore !== '') {
                    $before = self::effectiveAliasHolders(
                        db:                $db,
                        mainTableReal:     $mainTableReal,
                        mainPrimaryColumn: $mainPrimaryColumn,
                        langTable:         $table,
                        defaultTable:      $defaultTable,
                        primaryColumnName: $primaryColumnName,
                        field:             $field,
                        alias:             $effectiveBefore,
                        currentId:         $currentId,
                        scopeValues:       $storedScope,
                    );
                }

                foreach ($holders as $otherId) {
                    $kept = \in_array($otherId, $before, true);
                    $clashes[$kept ? 'warn' : 'refuse'][$otherId][$effective][] = self::languageLabel(langCode: $code);
                }
            }
        }

        foreach ($clashes as $kind => $byRecord) {
            foreach ($byRecord as $otherId => $byAlias) {
                foreach ($byAlias as $alias => $languages) {
                    $result[$kind][] = Text::sprintf(
                        $kind === 'warn' ? 'COM_ALFA_ALIAS_CLASH_KEPT' : 'COM_ALFA_ALIAS_CLASH_REFUSED',
                        htmlspecialchars((string) $alias, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        implode(', ', array_unique($languages)),
                        self::recordLabel(id: (int) $otherId, tableName: $tableName, primaryColumnName: $primaryColumnName),
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Blank the aliases a Save as Copy carries over unchanged from the record it copies, so
     * that the copy gets its own, generated from its name and made unique, as Joomla does for
     * a copied article. An alias typed for the copy is kept and judged like any other
     * ({@see self::aliasClashes()}); the original's aliases are never touched.
     *
     * @param array $data The posted data of the copy.
     * @param string $tableName Base main table (e.g. "#__alfa_items").
     * @param string $primaryColumnName PK column of the language tables (e.g. "id_item").
     * @param int $originalId The record copied.
     * @param string[] $aliasFields The alias fields.
     *
     * @return array $data, with those aliases blank.
     *
     * @since  2.1.1
     */
    public static function releaseCopiedAliases(
        array $data,
        string $tableName,
        string $primaryColumnName,
        int $originalId,
        array $aliasFields,
    ): array {
        if ($originalId <= 0 || !self::isSafeIdentifier(name: $primaryColumnName)) {
            return $data;
        }

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);

        foreach (self::buildLangCodeList(languages: LanguageHelper::getLanguages('lang_code')) as $code) {
            $table = $db->replacePrefix($tableName . '_' . $code);

            if (!\in_array($table, $existingTables, true)) {
                continue;
            }

            foreach ($aliasFields as $field) {
                $key = $field . '_' . $code;

                if (!self::isSafeIdentifier(name: $field) || !isset($data[$key]) || !\is_scalar($data[$key])
                    || trim((string) $data[$key]) === '') {
                    continue;
                }

                // The original's own alias, compared in the column's collation as the router compares.
                try {
                    $same = (bool) $db->setQuery(
                        $db->getQuery(true)
                            ->select('1')
                            ->from($db->qn($table))
                            ->where($db->qn($primaryColumnName) . ' = ' . (int) $originalId)
                            ->where($db->qn($field) . ' = ' . $db->q(trim((string) $data[$key]))),
                        0,
                        1,
                    )->loadResult();
                } catch (Throwable $e) {
                    $same = false;
                }

                if ($same) {
                    $data[$key] = '';
                }
            }
        }

        return $data;
    }

    /**
     * The records that answer at an address in one language, judged as a save is judged
     * ({@see self::aliasClashes()}): their own alias there, else their default-language alias,
     * compared in the column's collation, as the router finds a record by its alias.
     *
     * @param string $tableName Base main table (e.g. "#__alfa_categories").
     * @param string $primaryColumnName PK column of the language tables (e.g. "id_category").
     * @param string $langCode The language, lower-snake ("el_gr") or Joomla's form ("el-GR").
     * @param string $alias The address.
     * @param int $exceptId A record left out (0 for none).
     * @param array $scopeValues [column => value] of the scope ([] = global).
     * @param int[] $onlyIds When given, only these records are looked at.
     * @param string $field The alias field.
     * @param string $mainPrimaryColumn Main-table PK.
     *
     * @return int[] Their ids, lowest first, at most ten; none when the language has no table.
     *
     * @since  2.1.1
     */
    public static function aliasHolders(
        string $tableName,
        string $primaryColumnName,
        string $langCode,
        string $alias,
        int $exceptId = 0,
        array $scopeValues = [],
        array $onlyIds = [],
        string $field = 'alias',
        string $mainPrimaryColumn = 'id',
    ): array {
        if ($alias === '' || !self::isSafeIdentifier(name: $primaryColumnName) || !self::isSafeIdentifier(name: $field)
            || !self::isSafeIdentifier(name: $mainPrimaryColumn)) {
            return [];
        }

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langTable = $db->replacePrefix($tableName . '_' . self::normaliseTag(tag: $langCode));
        $defaultTable = $db->replacePrefix($tableName . '_' . self::getDefaultLanguageTag());

        if (!\in_array($langTable, $existingTables, true)) {
            return [];
        }

        return self::effectiveAliasHolders(
            db:                $db,
            mainTableReal:     $db->replacePrefix($tableName),
            mainPrimaryColumn: $mainPrimaryColumn,
            langTable:         $langTable,
            defaultTable:      \in_array($defaultTable, $existingTables, true) ? $defaultTable : '',
            primaryColumnName: $primaryColumnName,
            field:             $field,
            alias:             $alias,
            currentId:         $exceptId,
            scopeValues:       $scopeValues,
            onlyIds:           $onlyIds,
        );
    }

    /**
     * A record as a message names it: its name in the current language, or else in the
     * default one, with its id; the id alone when it has no name. HTML-escaped.
     *
     * @param int $id The record.
     * @param string $tableName Base main table (e.g. "#__alfa_categories").
     * @param string $primaryColumnName PK column of the language tables (e.g. "id_category").
     *
     * @return string e.g. '"Hats" (#5)'.
     *
     * @since  2.1.1
     */
    public static function recordLabel(int $id, string $tableName, string $primaryColumnName): string
    {
        try {
            $name = trim(strip_tags(self::getTranslatedValue(
                id:                $id,
                tableName:         $tableName,
                primaryColumnName: $primaryColumnName,
                field:             'name',
            )));
        } catch (Throwable) {
            $name = '';
        }

        return $name !== ''
            ? '"' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" (#' . $id . ')'
            : '#' . $id;
    }

    /**
     * A content language as a message names it: its title in the Content Languages manager,
     * with its tag when the title does not carry it. HTML-escaped.
     *
     * The title is read from the database when asked: Joomla's own list is read once per request,
     * before a language the same request publishes, and holds published languages only.
     *
     * @param string $langCode The language, lower-snake ("el_gr") or Joomla's form ("el-GR").
     *
     * @return string e.g. "Greek (el-GR)".
     *
     * @since  2.1.1
     */
    public static function languageLabel(string $langCode): string
    {
        $tag = self::displayTag(tag: self::normaliseTag(tag: $langCode));
        $db = self::getDb();
        $title = trim((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('title'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('lang_code') . ' = :tag')
                ->bind(':tag', $tag),
        )->loadResult());
        $label = $title === '' ? $tag : (str_contains($title, $tag) ? $title : $title . ' (' . $tag . ')');

        return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Load all multilingual data for one record, keyed by lower-snake language code.
     *
     * Returns:  [ 'en_gb' => ['name' => '...', 'alias' => '...'], 'el_gr' => [...], ... ]
     * Languages whose tables do not yet exist are silently skipped.
     *
     *
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException On a database read failure.
     * @since  1.0.0
     */
    public static function getMultilingualData(
        int $currentId,
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

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );
        $result = [];

        foreach ($langCodes as $langCode) {
            $langTableName = $db->replacePrefix($tableName . '_' . $langCode);

            if (!in_array($langTableName, $existingTables, true)) {
                continue; // No translations for this language yet; not an error.
            }

            try {
                $row = $db->setQuery(
                    $db->getQuery(true)
                        ->select('*')
                        ->from($db->qn($langTableName))
                        ->where($db->qn($primaryColumnName) . ' = ' . $db->q($currentId)),
                )->loadAssoc();
            } catch (Throwable $e) {
                throw new RuntimeException(
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
     * Input:   [ 'en_gb' => ['name' => 'Foo', 'alias' => 'foo'], ... ]
     * Output:  [ 'name_en_gb' => 'Foo', 'alias_en_gb' => 'foo', ... ]
     *
     * @param array $multilingualData Return value of getMultilingualData().
     *
     *
     * @since  1.0.0
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
     * Output:  [ 'name_en_gb' => 'Foo', 'alias_en_gb' => 'foo', 'name_el_gr' => '...', ... ]
     *
     *
     * @return array Flat key-value pairs ready for object property assignment.
     *
     * @since  1.0.0
     */
    public static function getMultilingualDataFlat(
        int $currentId,
        string $primaryColumnName,
        string $tableName,
    ): array {
        return self::flattenForFormData(
            self::getMultilingualData(
                currentId:         $currentId,
                primaryColumnName: $primaryColumnName,
                tableName:         $tableName,
            ),
        );
    }

    /**
     * Load all multilingual data for one record and bind it directly onto the item object.
     *
     * This is the single method models should call in getItem(); no foreach needed.
     * Flat keys (e.g. name_en_gb, alias_el_gr) are set as properties on $item directly.
     *
     * In PHP, objects are passed by handle so no & reference is needed:
     * property assignments inside this method mutate the same object the caller holds.
     *
     * Usage in any model's getItem():
     *   MultilingualHelper::bindMultilingualToItem($item, (int) $item->id, 'id_category', '#__alfa_categories');
     *
     * @param object $item The item object to hydrate.
     *
     *
     * @since  1.0.0
     */
    public static function bindMultilingualToItem(
        object $item,
        int $currentId,
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
     * tables; it does NOT fall back to the main table column because
     * translatable fields no longer exist there.
     *
     * LEFT JOIN (not INNER JOIN) is intentional: records without a translation
     * row must still appear in list views.
     *
     * Checks whether the dynamic language tables exist before joining to
     * prevent SQL crashes on fresh installs.
     *
     * @param \Joomla\Database\QueryInterface $query Modified in-place.
     * @param string $mainAlias Alias of the main table  (e.g. "a").
     * @param string $mainPrimaryColumn PK column in the main table  (e.g. "id").
     * @param string $langTableBase Base lang table  (e.g. "#__alfa_items").
     * @param string $langPrimaryColumn FK column in the lang table  (e.g. "id_item").
     * @param string[] $fields Translatable field names to SELECT.
     * @param string|null $langTag Resolve in THIS language (lang_code or snake)
     *                             instead of the current request language; null = current.
     *
     * @return string The resolved join alias (e.g. "en_gb").
     *
     * @since  1.0.0
     */
    public static function addMultilingualJoinToQuery(
        &$query,
        string $mainAlias,
        string $mainPrimaryColumn,
        string $langTableBase,
        string $langPrimaryColumn,
        array $fields = ['name'],
        ?string $langTag = null,
    ): string {
        $db = self::getDb();
        $existingTables = self::fetchTableList($db);

        // Resolve in an explicit language when given (router / language switch),
        // otherwise the current request language.
        $currentTag = ($langTag !== null && $langTag !== '')
            ? self::normaliseTag(tag: $langTag)
            : self::getCurrentLanguageTag();
        $defaultTag = self::getDefaultLanguageTag();

        $currentTable = $db->replacePrefix("{$langTableBase}_{$currentTag}");
        $defaultTable = $db->replacePrefix("{$langTableBase}_{$defaultTag}");

        $currentExists = in_array($currentTable, $existingTables, true);
        $defaultExists = in_array($defaultTable, $existingTables, true);
        $hasDefault = ($currentTag !== $defaultTag && $defaultExists);

        // Column presence per resolved table. A translatable field added to a form
        // materialises its per-language column only on the first save/read through
        // MultilingualHelper; until then the column is absent. Selecting it in this
        // raw JOIN would be a fatal "Unknown column", so a field missing from BOTH
        // tables is emitted as an empty-string placeholder (same as a missing table).
        $currentCols = $currentExists ? self::fetchColumnList($db, $currentTable) : [];
        $defaultCols = $hasDefault ? self::fetchColumnList($db, $defaultTable) : [];

        if ($currentExists) {
            $query->join(
                'LEFT',
                "`{$currentTable}` AS `{$currentTag}`"
                . " ON `{$currentTag}`.`{$langPrimaryColumn}` = `{$mainAlias}`.`{$mainPrimaryColumn}`",
            );
        }

        if ($hasDefault) {
            $query->join(
                'LEFT',
                "`{$defaultTable}` AS `lang_default`"
                . " ON `lang_default`.`{$langPrimaryColumn}` = `{$mainAlias}`.`{$mainPrimaryColumn}`",
            );
        }

        foreach ($fields as $field) {
            $parts = [];

            if ($currentExists && in_array($field, $currentCols, true)) {
                $parts[] = "NULLIF(`{$currentTag}`.`{$field}`, '')";
            }

            if ($hasDefault && in_array($field, $defaultCols, true)) {
                $parts[] = "NULLIF(`lang_default`.`{$field}`, '')";
            }

            // NOTE: We do NOT fall back to `{$mainAlias}`.`{$field}` here.
            // Translatable fields no longer exist in the main table; they live
            // exclusively in the language tables. The main table only holds
            // structural (non-translatable) columns.
            if (empty($parts)) {
                // No language table exists yet: emit an empty string placeholder
                // so the SELECT does not break on fresh installs.
                $query->select("'' AS `{$field}`");
                continue;
            }

            // Coalesce to '' last so the column is NEVER NULL: restores the old
            // main-table DEFAULT '' behaviour and keeps templates safe on PHP 8.1+
            // (passing null to nl2br/htmlspecialchars/etc. is deprecated).
            $coalesce = 'COALESCE(' . implode(', ', $parts) . ", '')";

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
     * Why subquery instead of join + GROUP_CONCAT?
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
     * @param \Joomla\Database\QueryInterface $query Modified in-place.
     * @param string $mainAlias Alias of the outer table     (e.g. 'a').
     * @param string $mainPk PK on the outer table        (e.g. 'id').
     * @param string $junctionTable Junction/relationship table  (e.g. '#__alfa_shipment_categories').
     * @param string $junctionFk FK junction -> outer table    (e.g. 'shipment_id').
     * @param string $junctionValue FK junction -> related table  (e.g. 'category_id').
     * @param string $selectAlias Output column alias          (e.g. 'category_ids').
     *
     * @since  1.0.0
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
        $db = self::getDb();
        $sub = $db->getQuery(true)
            ->select(
                'GROUP_CONCAT(DISTINCT ' . $db->qn("j.{$junctionValue}") . " SEPARATOR ',')",
            )
            ->from($db->qn($junctionTable, 'j'))
            ->where($db->qn("j.{$junctionFk}") . ' = ' . $db->qn("{$mainAlias}.{$mainPk}"));

        $query->select('(' . $sub . ') AS ' . $db->qn($selectAlias));
    }

    /**
     * Fetch records from any table by a list of IDs with optional language resolution.
     *
     * The single batch loader for post-pagination enrichment. Called internally
     * by fetchRelated(); can also be called directly when you need a keyed map
     * without the relationship binding step.
     *
     * Field semantics
     * $fields (structural, non-translatable)
     *   Columns whose value is identical in every language, e.g. alias, image, ordering.
     *   Always selected directly from $table regardless of lang configuration.
     *
     * $langFields (translatable)
     *   Columns whose value differs per language, e.g. name, description.
     *   When $langTableBase is set: resolved through lang tables via COALESCE:
     *     current language -> default language -> empty string.
     *   When $langTableBase is '' (non-translatable table such as Joomla core #__users):
     *     selected directly from $table; no lang join performed.
     *
     * Lang table aliases
     * 'lc' = lang current table, 'ld' = lang default table.
     * Short and unambiguous; never clash with the main table alias 'r'.
     *
     * Usage - Alfa translatable table:
     *   $map = MultilingualHelper::getRecordsByIds(
     *       db:                $db,
     *       ids:               [1, 4, 7],
     *       table:             '#__alfa_categories',
     *       fields:            ['alias', 'image'],       // structural
     *       langTableBase:     '#__alfa_categories',
     *       langPrimaryColumn: 'id_category',
     *       langFields:        ['name', 'description'],  // translatable
     *   );
     *   // Returns: [1 => ['id'=>1, 'alias'=>'...', 'image'=>'...', 'name'=>'...', 'description'=>'...'], ...]
     *
     * Usage - Joomla core table (no translation):
     *   $map = MultilingualHelper::getRecordsByIds(
     *       db:         $db,
     *       ids:        [5, 12],
     *       table:      '#__users',
     *       langFields: ['name'],
     *   );
     *   // Returns: [5 => ['id'=>5, 'name'=>'John'], ...]
     *
     * @param object $db Joomla DatabaseDriver.
     * @param int[] $ids PKs to load.
     * @param string $table Main table                    (e.g. '#__alfa_categories').
     * @param string $idColumn PK column name                (default 'id').
     * @param string[] $fields Non-translatable columns to include.
     * @param string $langTableBase Base lang table. Pass '' to skip translation entirely.
     * @param string $langPrimaryColumn FK in lang tables             (e.g. 'id_category').
     * @param string[] $langFields Translatable fields           (default ['name']).
     *
     * @return array<int, array<string, mixed>> Associative array keyed by ID.
     *
     * @since  1.0.0
     */
    public static function getRecordsByIds(
        object $db,
        array $ids,
        string $table,
        string $idColumn = 'id',
        array $fields = [],
        string $langTableBase = '',
        string $langPrimaryColumn = '',
        array $langFields = ['name'],
    ): array {
        if (empty($ids)) {
            return [];
        }

        // 'r': short neutral alias, never clashes with any caller's scope.
        $alias = 'r';
        $selectParts = [$db->qn("{$alias}.{$idColumn}")];

        // Structural fields: always read directly from the main table.
        foreach ($fields as $field) {
            $selectParts[] = $db->qn("{$alias}.{$field}");
        }

        $query = $db->getQuery(true)
            ->from($db->qn($table, $alias))
            ->whereIn("{$alias}.{$idColumn}", array_unique(array_map('intval', $ids)));

        if (!empty($langTableBase) && !empty($langPrimaryColumn)) {
            // Translatable table: resolve via lang tables
            $existingTables = self::fetchTableList($db);
            $currentTag = self::getCurrentLanguageTag();
            $defaultTag = self::getDefaultLanguageTag();
            $currentTable = $db->replacePrefix("{$langTableBase}_{$currentTag}");
            $defaultTable = $db->replacePrefix("{$langTableBase}_{$defaultTag}");
            $currentExists = in_array($currentTable, $existingTables, true);
            $defaultExists = in_array($defaultTable, $existingTables, true);
            $hasDefault = ($currentTag !== $defaultTag && $defaultExists);

            // 'lc' = lang current, 'ld' = lang default.
            if ($currentExists) {
                $query->join(
                    'LEFT',
                    $db->qn($currentTable, 'lc')
                    . ' ON ' . $db->qn("lc.{$langPrimaryColumn}")
                    . ' = ' . $db->qn("{$alias}.{$idColumn}"),
                );
            }

            if ($hasDefault) {
                $query->join(
                    'LEFT',
                    $db->qn($defaultTable, 'ld')
                    . ' ON ' . $db->qn("ld.{$langPrimaryColumn}")
                    . ' = ' . $db->qn("{$alias}.{$idColumn}"),
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
                //   Both tables exist -> COALESCE(NULLIF(current), NULLIF(default))
                //   Only current      -> NULLIF(current)
                //   Neither exists    -> literal '' (fresh-install safety net)
                $selectParts[] = match (count($parts)) {
                    0 => $db->q('') . ' AS ' . $db->qn($field),
                    1 => $parts[0] . ' AS ' . $db->qn($field),
                    default => 'COALESCE(' . implode(', ', $parts) . ') AS ' . $db->qn($field),
                };
            }
        } else {
            // Non-translatable table: select fields directly
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
     * Pure data method: no mutation. Fires one DB query via getRecordsByIds()
     * and returns a map keyed by the main item's PK. All DB work for this
     * relationship happens here. Call once per relationship before the
     * getItems() foreach loop, then pass each result to bindRelated() inside
     * the loop.
     *
     * Indexing
     * Outer key: the main item's PK ($item->$itemPk, default 'id').
     *            e.g. the shipment ID, NOT the junction FK (shipment_id).
     *            The junction FK was consumed in getListQuery() to build the
     *            GROUP_CONCAT string and is no longer needed here.
     * Inner key: the related record's own PK.
     * Order:     matches the original GROUP_CONCAT string.
     *
     * Return shape
     * [
     *   $itemId => [
     *     $relatedId => ['id' => ..., 'name' => '...', 'alias' => '...'],
     *     ...
     *   ],
     *   ...
     * ]
     *
     * Returns [] when no items carry any IDs for this relationship;
     * bindRelated() handles that gracefully by assigning [] to the property.
     *
     * FIELD SEMANTICS: same as getRecordsByIds()
     * $fields     -> structural (non-translatable): ['alias', 'image', 'ordering']
     * $langFields -> translatable: ['name', 'description']
     * When $langTableBase is '' (e.g. #__users): $langFields selected directly.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param object[] $items Paginated item objects, not mutated.
     * @param string $idsProperty Item property with the GROUP_CONCAT string (e.g. 'category_ids').
     * @param string $table Related entity table                        (e.g. '#__alfa_categories').
     * @param string $itemPk Item PK property used as the outer map key (default 'id').
     * @param string $idColumn PK column on the related table             (default 'id').
     * @param string[] $fields Non-translatable columns to include.
     * @param string $langTableBase Base lang table. Pass '' to skip translation.
     * @param string $langPrimaryColumn FK in lang tables                          (e.g. 'id_category').
     * @param string[] $langFields Translatable fields                        (default ['name']).
     *
     * @return array<int, array<int, array<string, mixed>>> Keyed by item PK then related ID.
     *
     * @since  1.0.0
     */
    public static function fetchRelated(
        object $db,
        array $items,
        string $idsProperty,
        string $table,
        string $itemPk = 'id',
        string $idColumn = 'id',
        array $fields = [],
        string $langTableBase = '',
        string $langPrimaryColumn = '',
        array $langFields = ['name'],
    ): array {
        if (empty($items)) {
            return [];
        }

        // Collect all unique IDs for this relationship across the full page
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

        // One query for all records of this relationship on this page
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

        // Build the per-item map
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
     * Pure assignment: no DB calls. Designed to be called inside the
     * getItems() foreach loop once per relationship, alongside any other
     * per-item logic (prices, links, media, etc.) that runs in the same pass.
     *
     * Always assigns the property (even [] when the item has no related
     * records), so templates never receive undefined property warnings.
     *
     * Usage inside getItems():
     *
     *   // All DB work grouped before the loop, one call per relationship:
     *   $catMap = MultilingualHelper::fetchRelated($db, $items, 'category_ids', ...);
     *   $manMap = MultilingualHelper::fetchRelated($db, $items, 'manufacturer_ids', ...);
     *
     *   // One loop, bind all relationships + any per-item logic:
     *   foreach ($items as $item) {
     *       MultilingualHelper::bindRelated($item, 'categories',    $catMap);
     *       MultilingualHelper::bindRelated($item, 'manufacturers', $manMap);
     *       $item->link = Route::_(...); // other per-item work here
     *   }
     *
     * @param object $item Mutated in place.
     * @param string $property Property name to set on the item (e.g. 'categories').
     * @param array<int, array<int, array<string, mixed>>> $map Return value of fetchRelated().
     *
     *
     * @since  1.0.0
     */
    public static function bindRelated(object $item, string $property, array $map): void
    {
        // Always assign (even []), so templates never see an undefined property.
        $item->$property = $map[(int) $item->id] ?? [];
    }

    /**
     * One-liner convenience: fetch one relationship and bind it onto all items.
     *
     * Combines fetchRelated() + a foreach with bindRelated() into a single call
     * for models that have no additional per-item logic to run in getItems().
     *
     * When you need extra per-item work (prices, links, media, etc.) in the
     * same loop, use fetchRelated() + bindRelated() separately instead; see
     * ShipmentsModel::getItems() for the recommended two-step pattern.
     *
     * @param object[] $items Mutated in place.
     * @param string $idsProperty Item property with the GROUP_CONCAT string.
     * @param string $bindTo Property name to set on each item.
     * @param string[] $fields
     * @param string[] $langFields
     *
     *
     * @since  1.0.0
     */
    public static function loadRelated(
        object $db,
        array $items,
        string $idsProperty,
        string $bindTo,
        string $table,
        string $itemPk = 'id',
        string $idColumn = 'id',
        array $fields = [],
        string $langTableBase = '',
        string $langPrimaryColumn = '',
        array $langFields = ['name'],
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
     * Example:  "en-GB"  ->  "en_gb"
     *
     * @since  1.0.0
     */
    public static function getCurrentLanguageTag(): string
    {
        // An explicit CONTENT language wins over the UI language. The two are
        // one axis in the site/admin apps, but not in the API: a client asks
        // for Greek product data without wanting Greek error prose. Swapping
        // the app's Language object instead would un-translate every Text::
        // string for the rest of the request.
        if (self::$contentLanguageOverride !== null) {
            return self::$contentLanguageOverride;
        }

        return self::normaliseTag(
            tag: Factory::getApplication()->getLanguage()->getTag(),
        );
    }

    /**
     * Pin the language every translatable read resolves in, independently of
     * the application's UI language. Request-scoped; pass null to clear.
     *
     * @param string|null $tag Content language (lang_code or snake), or null.
     *
     *
     * @since  2.0.0
     */
    public static function setContentLanguage(?string $tag): void
    {
        self::$contentLanguageOverride = ($tag === null || $tag === '')
            ? null
            : self::normaliseTag(tag: $tag);
    }

    /**
     * The pinned content language, or null when following the UI language.
     *
     *
     * @since  2.0.0
     */
    public static function getContentLanguageOverride(): ?string
    {
        return self::$contentLanguageOverride;
    }

    /**
     * Return the site's default CONTENT language tag in lower-snake format.
     *
     * THE ONE DEFINITION of the default content language: the language in which a product,
     * category, manufacturer or other record's text and URL alias are read when the current
     * language has none. It is the site language configured in Joomla's Language Manager
     * (com_languages "site") when that is a published content language, as Joomla's language
     * filter itself assumes. Every other place that needs the default content language asks
     * this method. It is never the language of Alfa's own interface texts: that Alfa is
     * written in English plays no part in it ({@see self::languageFallbackChain()} is that
     * other axis).
     *
     * NEVER THE REQUEST'S LANGUAGE. The application's "language" setting is not the
     * configuration in a web request: Joomla sets it to the language of the request, so
     * reading it made the fallback language the current one, and an untranslated name read
     * blank instead of falling back.
     *
     * AN INVALID CONFIGURATION (no site language set, or one that is not a published content
     * language) reads untranslated content in the published content language created first,
     * the lowest lang_id: the Language Manager's ordering cannot move it (Joomla's installer
     * puts every newly installed pack first), nor can installing another language. The
     * administrators are told through the notifications bell until the configuration is valid
     * again ({@see self::contentLanguageProblem()}). With no published content language at all,
     * the configured site language is kept, and "en_gb", Joomla's own value for an unset site
     * language, only when none is configured either or nothing can be read.
     *
     * Reads the database directly rather than through ComponentHelper or LanguageHelper,
     * which require a running application: this must stay callable from CLI and cron,
     * where index rebuilds run.
     *
     * @return string e.g. "en_gb"
     *
     * @since  1.0.0
     */
    public static function getDefaultLanguageTag(): string
    {
        if (self::$defaultLanguage !== null) {
            return self::$defaultLanguage['used'];
        }

        $published = [];
        $configured = '';
        $read = true;

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('lang_code'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('lang_id') . ' ASC');

            foreach ((array) $db->setQuery($query)->loadColumn() as $langCode) {
                $published[] = self::normaliseTag(tag: (string) $langCode);
            }

            $configured = self::normaliseTag(tag: self::configuredSiteLanguage(db: $db));
        } catch (Throwable $e) {
            $read = false;
            Log::add('Alfa: unable to read the content languages: ' . $e->getMessage(), Log::WARNING, 'com_alfa');
        }

        $valid = $configured !== '' && \in_array($configured, $published, true);
        $used = $valid ? $configured : ($published[0] ?? ($configured !== '' ? $configured : 'en_gb'));

        self::$defaultLanguage = [
            'configured' => $configured,
            'used' => $used,
            'valid' => $valid,
            'read' => $read,
            'published' => $published !== [],
        ];

        return $used;
    }

    /**
     * The default language in Joomla's own form ("en-GB"), for the callers that hand a
     * language to Joomla or store it as a language code: {@see self::getDefaultLanguageTag()}.
     *
     * @return string e.g. "en-GB"
     *
     * @since  2.1.1
     */
    public static function getDefaultLanguageCode(): string
    {
        return self::displayTag(tag: self::getDefaultLanguageTag());
    }

    /**
     * Why the configured site language is not the default content language, or null when it is.
     *
     * The case {@see self::getDefaultLanguageTag()} resolves with a fallback: no site language
     * set, or one that is not a published content language. Null as well when the
     * configuration could not be read, since nothing can then be said about it. The answer names,
     * in Joomla's form, the configured tag ('' when none is set) and the language used instead,
     * and says whether any content language is published.
     *
     * @return array{configured: string, used: string, published: bool}|null The case, or null.
     *
     * @since  2.1.1
     */
    public static function contentLanguageProblem(): ?array
    {
        self::getDefaultLanguageTag();
        $state = self::$defaultLanguage;

        if ($state === null || $state['valid'] || !$state['read']) {
            return null;
        }

        return [
            'configured' => $state['configured'] !== '' ? self::displayTag(tag: $state['configured']) : '',
            'used' => self::displayTag(tag: $state['used']),
            'published' => $state['published'],
        ];
    }

    /**
     * The shop's default administrator language: the Language Manager's setting (com_languages "administrator"), read
     * from its extension row, else en-GB. The language a notification's snapshot is written in (2.1.4).
     *
     * @since  2.1.4
     */
    public static function defaultAdministratorLanguage(): string
    {
        try {
            $db = self::getDb();
            $params = json_decode((string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_languages')),
                0,
                1,
            )->loadResult(), true);
            $tag = \is_array($params) ? trim((string) ($params['administrator'] ?? '')) : '';
        } catch (Throwable) {
            $tag = '';
        }

        return preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) === 1 ? $tag : 'en-GB';
    }

    /**
     * The site language as configured in the Language Manager (com_languages "site"),
     * read from its extension row.
     *
     * @param object $db Joomla DatabaseDriver.
     *
     * @return string The configured tag ("el-GR"), or '' when none is set.
     *
     * @since  2.1.1
     */
    private static function configuredSiteLanguage(object $db): string
    {
        $params = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_languages')),
            0,
            1,
        )->loadResult();

        $decoded = json_decode($params, true);

        return \is_array($decoded) ? trim((string) ($decoded['site'] ?? '')) : '';
    }

    /**
     * Resolve a language key to the first non-empty translation across the
     * fallback chain: requested -> en-GB -> the site's default language.
     *
     * The untranslated-key guard applies: a language that echoes the key back
     * (no .ini entry) counts as no translation. Returns '' when nothing in the
     * chain translates the key.
     *
     * Shared by the order-email and document default-content composers, which
     * seed editable HTML from the packaged .ini defaults; both used to carry a
     * verbatim copy of this mechanism.
     *
     * @param string $key Language constant (fully qualified, e.g.
     *                    'COM_ALFA_ORDEREMAIL_DEFAULT_SUBJECT').
     * @param string $langTag Requested content language (e.g. 'el-GR').
     *
     * @return string First translation found, or '' when none.
     *
     * @since  1.0.0
     */
    public static function firstTranslated(string $key, string $langTag): string
    {
        foreach (self::languageFallbackChain(langTag: $langTag) as $tag) {
            $value = self::translateInLanguage(key: $key, langTag: $tag);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Language fallback chain for default resolution: requested -> en-GB (the
     * language the packaged texts are written in) -> the site's default language
     * ({@see self::getDefaultLanguageCode()}). Deduped, empties dropped.
     *
     * @param string $langTag Requested language tag.
     *
     * @return string[]
     *
     * @since  1.0.0
     */
    private static function languageFallbackChain(string $langTag): array
    {
        $chain = [$langTag, 'en-GB', self::getDefaultLanguageCode()];

        return array_values(array_unique(array_filter($chain)));
    }

    /**
     * Translate one key in a specific language, with the untranslated-key
     * guard: a Language that echoes the key back (no .ini entry) yields ''.
     *
     * The Language instance is loaded once per tag and cached for the
     * request; seeding touches the same few tags repeatedly.
     *
     * @param string $key Language constant.
     * @param string $langTag Language to resolve in.
     *
     * @return string Translated text, or '' when untranslated.
     *
     * @since  1.0.0
     */
    private static function translateInLanguage(string $key, string $langTag): string
    {
        static $cache = [];

        if (!isset($cache[$langTag])) {
            // LanguageFactory (not the deprecated Language::getInstance):
            // a standalone instance for $langTag, independent of the admin's
            // current language, so seeding resolves each content language's
            // own .ini.
            $language = Factory::getContainer()
                ->get(LanguageFactoryInterface::class)
                ->createLanguage($langTag);
            $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            $cache[$langTag] = $language;
        }

        $value = $cache[$langTag]->_($key);

        return $value === $key ? '' : $value;
    }

    /**
     * The CURRENT request language in lower-snake format (e.g. "el_gr").
     *
     * @since  1.0.0
     */
    public static function currentLangKey(): string
    {
        return self::normaliseTag(Factory::getApplication()->getLanguage()->getTag());
    }

    /**
     * Recursively collapse every embedded multilingual map to the current
     * language. A "multilingual map" is an associative array whose keys are all
     * installed language codes (e.g. {"en_gb":"Red","fr_fr":"Rouge"}); it is
     * replaced by the current language's value, falling back to the default
     * language, then any non-empty value, then ''.
     *
     * Used to resolve plugin params / field definitions that store translations
     * inline as JSON (the value/JSON storage mode of MultilingualText), so the
     * render and display layers see a plain current-language string while the
     * admin definition editor keeps the full per-language map.
     *
     * @param mixed $data Decoded params/array (objects must be cast to array first).
     * @param string|null $lang Target language key; defaults to the current request language.
     *
     *
     * @since  1.0.0
     */
    public static function collapseToCurrent(mixed $data, ?string $lang = null): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $lang ??= self::currentLangKey();
        $langSet = self::installedLangKeys();

        if (self::isLangMap($data, $langSet)) {
            return self::pickLangValue($data, $lang);
        }

        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = self::collapseToCurrent($value, $lang);
        }

        return $out;
    }

    /**
     * Installed language codes as a lookup set in lower-snake format.
     *
     * @return array<string, true>
     *
     * @since  1.0.0
     */
    private static function installedLangKeys(): array
    {
        $set = [];

        foreach (LanguageHelper::getLanguages('lang_code') as $langCode => $language) {
            $set[self::normaliseTag($langCode)] = true;
        }

        return $set;
    }

    /**
     * Whether $data is a multilingual map: a non-empty associative array whose
     * keys are all installed language codes.
     *
     * @param array<string, true> $langSet
     *
     *
     * @since  1.0.0
     */
    private static function isLangMap(array $data, array $langSet): bool
    {
        if ($data === [] || array_is_list($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if (!isset($langSet[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a {lang: text} map to one string in the target language, falling
     * back to the default language then the first non-empty entry. The shared
     * reader for frozen multilingual snapshots (order item-fields) and
     * plugin-param label maps; accepts a JSON string, array or stdClass.
     *
     * @param mixed $map JSON string / array / object of normalised tag => text.
     * @param string $lang Target language (lang_code or snake); '' = current request language.
     *
     * @since  2.0.0
     */
    public static function resolveLangMap($map, string $lang = ''): string
    {
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            $map = is_array($decoded)
                ? $decoded
                : ($map !== '' ? [self::getDefaultLanguageTag() => $map] : []);
        }

        $map = (array) $map;

        if (!$map) {
            return '';
        }

        $tag = $lang !== '' ? self::normaliseTag(tag: $lang) : self::getCurrentLanguageTag();

        return self::pickLangValue($map, $tag);
    }

    /**
     * Pick a value from a multilingual map: target language -> default language ->
     * first non-empty -> ''.
     *
     *
     *
     * @since  1.0.0
     */
    private static function pickLangValue(array $map, string $lang): string
    {
        if (!empty($map[$lang])) {
            return (string) $map[$lang];
        }

        $default = self::getDefaultLanguageTag();

        if (!empty($map[$default])) {
            return (string) $map[$default];
        }

        foreach ($map as $value) {
            if (!empty($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Resolve a single translatable field for one record in a SPECIFIC language,
     * falling back to the default language, then to ''.
     *
     * Unlike addMultilingualJoinToQuery() (which always reads the CURRENT request
     * language), this targets an explicit tag. The router / language-switch
     * associations need it because a com_alfa record keeps ONE shared id across
     * languages but a DIFFERENT alias per language, so building an `&lang=xx`
     * URL must emit that language's alias, not the current page's.
     *
     * @param int $id Shared record id (main-table id).
     * @param string $tableName Base lang table (e.g. "#__alfa_items").
     * @param string $primaryColumnName FK column in the lang tables (e.g. "id_item").
     * @param string $field Field to read (default "alias").
     * @param string $langTag Target language (lang_code or snake);
     *                        '' = current request language.
     *
     * @return string The field value, or '' when no translation exists.
     *
     * @since  1.0.0
     */
    public static function getTranslatedValue(
        int $id,
        string $tableName,
        string $primaryColumnName,
        string $field = 'alias',
        string $langTag = '',
    ): string {
        if ($id <= 0 || !self::isSafeIdentifier(name: $primaryColumnName) || !self::isSafeIdentifier(name: $field)) {
            return '';
        }

        $tag = $langTag !== '' ? self::normaliseTag(tag: $langTag) : self::getCurrentLanguageTag();
        $default = self::getDefaultLanguageTag();

        // Target language first, then the default; first non-empty wins.
        return self::readFirstFilledField(
            id:                $id,
            tableName:         $tableName,
            primaryColumnName: $primaryColumnName,
            field:             $field,
            langTags:          array_values(array_unique([$tag, $default])),
        );
    }

    /**
     * Read one field for one record from the first of several language tables that has it,
     * in ONE statement: the router asks this for every link a page builds, so the default
     * language's read must not add a statement per link. '' when none has it.
     *
     * @param int $id Record id.
     * @param string $tableName Base table with prefix (e.g. "#__alfa_items").
     * @param string $primaryColumnName PK column of the lang tables (e.g. "id_item").
     * @param string $field Field to read.
     * @param string[] $langTags Lower-snake tags, in the order they are tried.
     *
     *
     * @since  2.1.1
     */
    private static function readFirstFilledField(
        int $id,
        string $tableName,
        string $primaryColumnName,
        string $field,
        array $langTags,
    ): string {
        // Request-level memo, as readFieldFromLangTable(): the same lookups repeat per page.
        static $cache = [];
        $cacheKey = $tableName . '|' . implode(',', $langTags) . '|' . $primaryColumnName . '|' . $field . '|' . $id;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $reads = [];

        foreach ($langTags as $langTag) {
            $table = $db->replacePrefix($tableName . '_' . $langTag);

            if (\in_array($table, $existingTables, true) && \in_array($field, self::fetchColumnList(db: $db, table: $table), true)) {
                $reads[] = 'NULLIF((SELECT ' . $db->qn($field) . ' FROM ' . $db->qn($table)
                    . ' WHERE ' . $db->qn($primaryColumnName) . ' = ' . (int) $id . " LIMIT 1), '')";
            }
        }

        if ($reads === []) {
            return $cache[$cacheKey] = '';
        }

        try {
            $value = $db->setQuery('SELECT COALESCE(' . implode(', ', $reads) . ", '')")->loadResult();
        } catch (Throwable $e) {
            return $cache[$cacheKey] = '';
        }

        return $cache[$cacheKey] = (string) ($value ?? '');
    }

    /**
     * Read one field's value in EVERY installed content language, without the
     * current->default fallback, so a caller (e.g. an inline editor) can show
     * each language exactly as stored, empty where no translation exists.
     *
     * @param int $id Record id.
     * @param string $tableName Base table with prefix (e.g. "#__alfa_items").
     * @param string $primaryColumnName PK column of the lang tables (e.g. "id_item").
     * @param string $field Field to read (e.g. "name").
     *
     * @return array<int, array{tag:string,label:string,value:string}>
     *                                                                 One entry per content language, in installed order.
     *
     * @since  2.0.0
     */
    public static function getFieldTranslations(
        int $id,
        string $tableName,
        string $primaryColumnName,
        string $field,
    ): array {
        if ($id <= 0 || !self::isSafeIdentifier(name: $primaryColumnName) || !self::isSafeIdentifier(name: $field)) {
            return [];
        }

        $out = [];

        foreach (LanguageHelper::getLanguages('lang_code') as $langCode => $language) {
            $out[] = [
                'tag' => $langCode,
                'label' => $language->title ?? $langCode,
                'value' => self::readFieldFromLangTable(
                    id:                $id,
                    tableName:         $tableName,
                    primaryColumnName: $primaryColumnName,
                    field:             $field,
                    langTag:           self::normaliseTag(tag: $langCode),
                ),
            ];
        }

        return $out;
    }

    /**
     * Build the per-language flat key that saveMultilingualData() expects
     * (e.g. "name" + "en-GB" => "name_en_gb"). Single source for the format.
     *
     * @param string $field Field name (e.g. "name").
     * @param string $tag Language tag (e.g. "en-GB").
     *
     *
     * @since  2.0.0
     */
    public static function buildLangFieldKey(string $field, string $tag): string
    {
        return $field . '_' . self::normaliseTag(tag: $tag);
    }

    /**
     * The installed languages as key suffixes, in Joomla's own order: ['en_gb', 'el_gr'].
     *
     * Companion to {@see self::buildLangFieldKey()}: together they are the only definition of
     * how a translatable field's per-language keys are spelled, so nothing else has to know.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public static function getLanguageTags(): array
    {
        return self::buildLangCodeList(languages: LanguageHelper::getLanguages('lang_code'));
    }

    /**
     * Whether one language's table of a translatable entity exists, read from the table list
     * this class keeps for the request ({@see self::fetchTableList()}).
     *
     * @param string $tableName Base table with Joomla prefix (e.g. "#__alfa_items").
     * @param string $langTag Language (lang_code or snake).
     *
     *
     * @since  2.1.1
     */
    public static function hasLanguageTable(string $tableName, string $langTag): bool
    {
        $db = self::getDb();

        return \in_array(
            $db->replacePrefix($tableName . '_' . self::normaliseTag(tag: $langTag)),
            self::fetchTableList(db: $db),
            true,
        );
    }

    /**
     * The published content languages as the database holds them now, in Joomla's order:
     * ['en-GB' => 'en_gb', 'el-GR' => 'el_gr'].
     *
     * Joomla's own list (LanguageHelper::getLanguages()) is read once per request, and the
     * language filter reads it as each request starts, administrator requests included: a
     * language published or saved later in the same request (Content Languages) is not in it.
     * What makes a language's tables reads the languages here, so that request makes them.
     *
     * @return array<string, string> Language tag => table suffix.
     *
     * @throws RuntimeException When the languages cannot be read.
     *
     * @since  2.1.0
     */
    public static function publishedLanguages(): array
    {
        $db = self::getDb();

        try {
            $tags = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('lang_code'))
                    ->from($db->qn('#__languages'))
                    ->where($db->qn('published') . ' = 1')
                    ->order($db->qn('ordering') . ' ASC'),
            )->loadColumn();
        } catch (Throwable $e) {
            throw new RuntimeException(
                '[MultilingualHelper::publishedLanguages] Could not read the content languages: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $languages = [];

        foreach ($tags ?: [] as $tag) {
            $languages[(string) $tag] = self::normaliseTag(tag: (string) $tag);
        }

        return $languages;
    }

    /**
     * Read one field for one record from a single language table, or '' if the
     * table doesn't exist / the row is missing.
     * @since  1.0.0
     */
    private static function readFieldFromLangTable(
        int $id,
        string $tableName,
        string $primaryColumnName,
        string $field,
        string $langTag,
    ): string {
        // Request-level memo: the same single-field lookups (e.g. an item's alias
        // for routing) repeat many times per page. Read-only within a request.
        static $cache = [];
        $cacheKey = $tableName . '|' . $langTag . '|' . $primaryColumnName . '|' . $field . '|' . $id;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $db = self::getDb();
        $table = $db->replacePrefix($tableName . '_' . $langTag);

        if (!in_array($table, self::fetchTableList(db: $db), true)) {
            return $cache[$cacheKey] = '';
        }

        try {
            $value = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn($field))
                    ->from($db->qn($table))
                    ->where($db->qn($primaryColumnName) . ' = ' . (int) $id),
                0,
                1,
            )->loadResult();
        } catch (Throwable $e) {
            return $cache[$cacheKey] = '';
        }

        return $cache[$cacheKey] = (string) ($value ?? '');
    }

    /**
     * Make the language tables and columns a save of $data is about to write, and
     * nothing else. The DDL half of {@see self::saveMultilingualData()}, which runs
     * it first; a caller that wraps that save in a transaction of its own MUST run
     * it before opening the transaction, because MySQL commits implicitly on DDL.
     *
     * Idempotent, and remembered per request: a second call for the same table,
     * language and fields costs nothing.
     *
     * A language table made here gets the names the installation seeds for that
     * table and language ({@see SeededNames}), as the language sync gives them: a
     * save can be the first write of a content language the sync has not reached.
     *
     * @param string $tableName Base table with Joomla prefix (e.g. "#__alfa_taxes").
     * @param string $primaryColumnName PK column for the lang tables (e.g. "id_tax").
     * @param array $data The form data about to be saved (fieldName_langCode keys).
     *
     *
     * @throws InvalidArgumentException On a bad PK identifier.
     * @throws RuntimeException On any unrecoverable database error.
     *
     * @since  2.0.0
     */
    public static function ensureLangSchemaForData(string $tableName, string $primaryColumnName, array $data): void
    {
        static $ensured = [];

        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);

        $languageData = self::extractLanguageData(
            data:      $data,
            langCodes: self::buildLangCodeList(languages: LanguageHelper::getLanguages('lang_code')),
        );

        if (empty($languageData)) {
            return;
        }

        $db = self::getDb();
        $existingTables = null;

        foreach ($languageData as $langCode => $langFields) {
            $langTableName = $db->replacePrefix($tableName . '_' . $langCode);
            $fieldNames = array_keys($langFields);
            sort($fieldNames);
            $memo = $langTableName . '|' . implode(',', $fieldNames);

            if (isset($ensured[$memo])) {
                continue;
            }

            $existingTables ??= self::fetchTableList(db: $db);

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

                $seeded = (new SeededNames(db: $db))->fill(table: $tableName, language: $langCode);

                foreach ($seeded['errors'] as $error) {
                    self::log(
                        callerMethod: __METHOD__,
                        message:      'The seeded names of "' . $langTableName . '" were not written: ' . $error,
                        priority:     Log::WARNING,
                    );
                }
            } else {
                self::ensureColumnsExist(
                    db:            $db,
                    langTableName: $langTableName,
                    fields:        $langFields,
                );
            }

            $ensured[$memo] = true;
        }
    }

    /**
     * Ensure the per-language auxiliary tables + columns exist for one entity,
     * across every installed content language.
     *
     * Proactive counterpart to the lazy on-save creation in saveMultilingualData():
     * the sync tool / installer can create the lang tables (e.g.
     * #__alfa_categories_en_gb) and their columns up-front from a declared field
     * list, without a record being saved first. Existing tables get any missing
     * columns added; nothing is dropped or seeded. The languages are the ones
     * published now ({@see self::publishedLanguages()}), so a content language
     * published earlier in the same request gets its tables.
     *
     * @param string $tableName Base table with Joomla prefix (e.g. "#__alfa_categories").
     * @param string $primaryColumnName PK column for the lang tables (e.g. "id_category").
     * @param string[] $fields Translatable field names (e.g. ['name','alias','desc']).
     *
     * @return array Summary: ['created' => string[], 'existing' => string[], 'languages' => int].
     *
     * @throws InvalidArgumentException On a bad PK identifier.
     * @throws RuntimeException On any unrecoverable database error.
     *
     * @since  1.0.0
     */
    public static function ensureLangSchema(
        string $tableName,
        string $primaryColumnName,
        array $fields,
    ): array {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes = array_values(self::publishedLanguages());

        // createLangTable() / ensureColumnsExist() read array_keys($fields), so
        // present the (safe) field names as a keyed map; values are irrelevant.
        $fieldMap = array_fill_keys(
            array_values(array_filter(
                $fields,
                static fn (string $f): bool => self::isSafeIdentifier(name: $f),
            )),
            '',
        );

        $summary = ['created' => [], 'existing' => [], 'languages' => count($langCodes)];

        if (empty($fieldMap)) {
            return $summary;
        }

        foreach ($langCodes as $langCode) {
            $langTableName = $db->replacePrefix($tableName . '_' . $langCode);

            if (!in_array($langTableName, $existingTables, true)) {
                self::createLangTable(
                    db:                $db,
                    langTableName:     $langTableName,
                    primaryColumnName: $primaryColumnName,
                    fields:            $fieldMap,
                );
                $existingTables[] = $langTableName;
                $summary['created'][] = $langTableName;
            } else {
                self::ensureColumnsExist(
                    db:            $db,
                    langTableName: $langTableName,
                    fields:        $fieldMap,
                );
                $summary['existing'][] = $langTableName;
            }
        }

        return $summary;
    }

    /**
     * Fill EMPTY per-language field values from the best available source, so a
     * record shows something in every language even when only one language was
     * filled in.
     *
     * For each record + field, the source is the first non-empty value across
     * languages in priority order: the DEFAULT language first, then the remaining
     * installed languages in their configured order. Every language whose value
     * is empty (or whose lang row is missing entirely) is set to that source.
     * Existing non-empty translations are NEVER overwritten.
     *
     * Alias / slug fields ($aliasFields) take part too: an empty alias is
     * copied from a sibling language that HAS one; if no language has it, it is
     * left empty (never invented from the name). Because copying a slug into
     * another language's table can collide with an existing slug there, every
     * copied alias is run through the same per-language uniqueness check the
     * save path uses (scoped by $aliasUniqueScope, appending -2, -3 ... only on a
     * real collision) so routing stays correct.
     *
     * Maintenance-time operation (sync / resync), not the hot path. CHUNKED:
     * pass $offset / $limit (over the main table's records, ordered by PK) so a
     * large catalogue can be processed in batches, e.g. driven by the Tools-view
     * progress bar. Only the current chunk's rows are loaded into memory.
     *
     * @param string $tableName Base table with Joomla prefix.
     * @param string $primaryColumnName PK column shared by the lang tables (e.g. "id_item").
     * @param string[] $fields Translatable field names (include alias fields).
     * @param string[] $aliasFields Subset of $fields that are URL slugs (copied then de-duplicated).
     * @param string[] $aliasUniqueScope Main-table column(s) scoping alias uniqueness ([] = global).
     * @param int $offset Record offset into the main table (default 0).
     * @param int $limit Records per chunk; 0 = all in one pass.
     * @param string $mainPrimaryColumn PK of the main table (default 'id').
     *
     * @return array ['written' => int, 'processed' => int, 'total' => int].
     *
     * @since  1.0.0
     */
    public static function backfillEmptyTranslations(
        string $tableName,
        string $primaryColumnName,
        array $fields,
        array $aliasFields = [],
        array $aliasUniqueScope = [],
        int $offset = 0,
        int $limit = 0,
        string $mainPrimaryColumn = 'id',
    ): array {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);
        self::assertSafeIdentifier(name: $mainPrimaryColumn, callerMethod: __METHOD__);

        $fields = array_values(array_filter(
            $fields,
            static fn (string $f): bool => self::isSafeIdentifier(name: $f),
        ));

        // Slug fields that are actually present in this table's field set.
        $aliasFields = array_values(array_filter(
            $aliasFields,
            static fn (string $f): bool => in_array($f, $fields, true),
        ));

        $db = self::getDb();
        $mainTable = $db->replacePrefix($tableName);
        $total = (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->qn($mainTable)),
        )->loadResult();

        $result = ['written' => 0, 'processed' => 0, 'total' => $total];

        if (empty($fields) || $total === 0) {
            return $result;
        }

        $existingTables = self::fetchTableList(db: $db);
        $defaultTag = self::getDefaultLanguageTag();

        // Default language first, then the rest in their configured order.
        $orderedLangs = array_values(array_unique(array_merge(
            [$defaultTag],
            self::buildLangCodeList(languages: LanguageHelper::getLanguages('lang_code')),
        )));

        // Keep only languages whose auxiliary table physically exists.
        $langTables = [];
        foreach ($orderedLangs as $langCode) {
            $langTableName = $db->replacePrefix($tableName . '_' . $langCode);
            if (in_array($langTableName, $existingTables, true)) {
                $langTables[$langCode] = $langTableName;
            }
        }

        // Need at least two languages for one to fill another.
        if (count($langTables) < 2) {
            return $result;
        }

        // The chunk of record IDs to process (from the main table, by PK order).
        $idQuery = $db->getQuery(true)
            ->select($db->qn($mainPrimaryColumn))
            ->from($db->qn($mainTable))
            ->order($db->qn($mainPrimaryColumn) . ' ASC');

        $limit > 0
            ? $db->setQuery($idQuery, $offset, $limit)
            : $db->setQuery($idQuery);

        $ids = array_map('intval', $db->loadColumn() ?: []);

        if (empty($ids)) {
            return $result;
        }

        $result['processed'] = count($ids);

        // Load only this chunk's rows from each lang table: $data[lang][id][field].
        $data = [];
        foreach ($langTables as $langCode => $langTableName) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->qn($langTableName))
                    ->whereIn($db->qn($primaryColumnName), $ids),
            )->loadAssocList($primaryColumnName);

            $data[$langCode] = $rows ?: [];
        }

        foreach ($ids as $id) {
            $pendingByLang = []; // lang => [field => filledValue]

            foreach ($fields as $field) {
                // Source = first non-empty value in priority order.
                $source = '';
                foreach ($langTables as $langCode => $langTableName) {
                    $val = (string) ($data[$langCode][$id][$field] ?? '');
                    if ($val !== '') {
                        $source = $val;
                        break;
                    }
                }

                if ($source === '') {
                    continue; // No language has a value for this field.
                }

                // Fill every language whose value is empty / missing.
                foreach ($langTables as $langCode => $langTableName) {
                    if ((string) ($data[$langCode][$id][$field] ?? '') === '') {
                        $pendingByLang[$langCode][$field] = $source;
                    }
                }
            }

            // Alias fields were copied above like any other field, but a slug
            // copied into another language's table can collide there. For each
            // language that received a COPIED alias (we never invent one when no
            // language has it), de-duplicate it per language exactly as the save
            // path does. The per-record write below runs before the next
            // record's pass, so collisions inside the chunk are seen too.
            if (!empty($aliasFields)) {
                $scopeValues = self::loadScopeValues(
                    db:                $db,
                    mainTableReal:     $mainTable,
                    mainPrimaryColumn: $mainPrimaryColumn,
                    currentId:         (int) $id,
                    aliasUniqueScope:  $aliasUniqueScope,
                );

                foreach ($langTables as $langCode => $langTableName) {
                    foreach ($aliasFields as $aliasField) {
                        // Only a freshly-copied alias needs the collision check.
                        $base = (string) ($pendingByLang[$langCode][$aliasField] ?? '');

                        if ($base === '') {
                            continue;
                        }

                        $pendingByLang[$langCode][$aliasField] = self::makeUniqueSlug(
                            db:                $db,
                            langTableName:     $langTableName,
                            primaryColumnName: $primaryColumnName,
                            currentId:         (int) $id,
                            field:             $aliasField,
                            base:              $base,
                            mainTableReal:     $mainTable,
                            mainPrimaryColumn: $mainPrimaryColumn,
                            scopeValues:       $scopeValues,
                            defaultTable:      $langCode !== $defaultTag ? ($langTables[$defaultTag] ?? '') : '',
                        );
                    }
                }
            }

            // Write each language that needs filling. upsertLangRow only touches
            // the columns it is given; the merge keeps the alias de-duplication
            // above working against the row's full set of values.
            foreach ($pendingByLang as $langCode => $filled) {
                $existing = $data[$langCode][$id] ?? [];
                unset($existing[$primaryColumnName]);

                self::upsertLangRow(
                    db:                $db,
                    langTableName:     $langTables[$langCode],
                    primaryColumnName: $primaryColumnName,
                    currentId:         (int) $id,
                    fields:            array_merge($existing, $filled),
                );

                $result['written']++;
            }
        }

        return $result;
    }

    //  Private: data processing

    /**
     * Convert a Joomla language map into a flat list of lower-snake codes.
     *
     * @param array $languages Output of LanguageHelper::getLanguages('lang_code').
     *
     * @return string[] e.g. ['en_gb', 'el_gr']
     * @since  1.0.0
     */
    private static function buildLangCodeList(array $languages): array
    {
        return array_map(
            static fn (string $code): string => self::normaliseTag(tag: $code),
            array_keys($languages),
        );
    }

    /**
     * Parse a flat POST / data array into a per-language structure.
     *
     * Keys following the convention  fieldName_langCode  are split into:
     *   $languageData[$langCode][$fieldName] = $value
     *
     * @param array $data Flat input (passed $data or jform POST).
     * @param string[] $langCodes Lower-snake codes  (e.g. ['en_gb', 'el_gr']).
     *
     * @return array [ $langCode => [ $fieldName => $value ] ]
     * @since  1.0.0
     */
    private static function extractLanguageData(array $data, array $langCodes): array
    {
        $languageData = [];

        foreach ($data as $key => $value) {
            foreach ($langCodes as $langCode) {
                $suffix = '_' . $langCode;

                if (!str_ends_with($key, $suffix)) {
                    continue;
                }

                $fieldName = substr($key, 0, -strlen($suffix));
                $languageData[$langCode][$fieldName] = $value;

                break; // Each key can match only one language suffix.
            }
        }

        return $languageData;
    }

    /**
     * Process the slug/alias fields of one language's row; every other field is kept as given.
     *
     * @param array $fields [ fieldName => value ] for one language.
     * @param string[] $aliasFields Field names to treat as URL slugs.
     * @param object $app Joomla application (for the unicodeslugs setting).
     * @param string $langCode Lower-snake code of the language row being built (e.g. "el_gr").
     *
     * @return array Processed fields.
     * @since  2.1.1
     */
    private static function resolveSlugs(
        array $fields,
        array $aliasFields,
        object $app,
        string $langCode,
    ): array {
        foreach ($aliasFields as $aliasField) {
            if (\array_key_exists($aliasField, $fields)) {
                $fields[$aliasField] = self::resolveSlugValue(
                    rawValue: (string) $fields[$aliasField],
                    fields:   $fields,
                    app:      $app,
                    langCode: $langCode,
                );
            }
        }

        return $fields;
    }

    /**
     * Resolve the final value for a slug/alias field.
     *
     * Priority:
     *   1. Use the submitted value if not blank.
     *   2. Fall back to name / title from the same language row.
     *   3. Sanitise the chosen source through OutputFilter; nothing, when both are blank.
     *
     * Never another language's name: while this language has none, the router reads
     * the default language's alias, and the language gets its own once a name is saved.
     *
     * TRANSLITERATED IN THE ROW'S OWN LANGUAGE, never the request's. A slug for the
     * el_gr row is Greek text, so it must fold through the el-GR rules; omitting the
     * argument makes {@see OutputFilter::stringURLSafe} use whoever is logged in, so the
     * same Greek name saved from an English admin and a Greek one produces two different
     * aliases (ICU's scholarly "technologia" vs the pack's modern "texnologia"). That is
     * two different URLs for one product, and, because the alias is indexed, a
     * cross-script search bridge spelled the way no Greek shopper types.
     *
     * @param string $rawValue Submitted value (may be empty).
     * @param array $fields All fields for the current language.
     * @param object $app Joomla application (for unicodeslugs config).
     * @param string $langCode Lower-snake code of the row's language (e.g. "el_gr").
     *
     * @return string Sanitised slug, '' when the row has neither an alias nor a name.
     * @since  1.0.0
     */
    private static function resolveSlugValue(
        string $rawValue,
        array $fields,
        object $app,
        string $langCode,
    ): string {
        $source = !empty(trim($rawValue))
            ? $rawValue
            : (string) ($fields['name'] ?? $fields['title'] ?? '');

        // Unicode slugs keep the original script, so they have nothing to transliterate.
        return $app->get('unicodeslugs') == 1
            ? OutputFilter::stringUrlUnicodeSlug($source)
            : OutputFilter::stringURLSafe($source, self::displayTag(tag: $langCode));
    }

    //  Private: alias uniqueness

    /**
     * Make every alias field in $fields that this save generated unique among the aliases
     * the router resolves in this language. A typed alias is left exactly as typed.
     *
     * A record with no alias of its own in a language is routed there by its default
     * language alias. So in any other language, a record without an alias there counts
     * with its default language alias; and the default language's alias of this record
     * also has to be free in every language in which this record has none of its own.
     *
     * Uniqueness is scoped by the main-table columns in $aliasUniqueScope: a
     * collision only counts when another row shares the same scope values (e.g.
     * the same parent_id). With an empty scope, uniqueness is global across the
     * language table. On collision the slug is incremented (-2, -3, ...).
     *
     * @param object $db Joomla DatabaseDriver.
     * @param string $langTableName Prefix-resolved language table name.
     * @param string $primaryColumnName PK column shared with the main table (e.g. id_category).
     * @param int $currentId Row id being saved (excluded from collision checks).
     * @param array $fields Resolved field values for this language.
     * @param string[] $aliasFields Field names treated as slugs.
     * @param string $mainTableName Base main table (e.g. #__alfa_categories).
     * @param string $mainPrimaryColumn Main-table PK used for the scope join.
     * @param string[] $aliasUniqueScope Main-table columns scoping uniqueness ([] = global).
     * @param string $langCode Lower-snake code of this language (e.g. "el_gr").
     * @param array $savedByLang The same save's resolved fields of every language, by language.
     * @param array<string, bool> $generated The alias fields this save generated, by name.
     *
     * @return array $fields with unique slug values.
     * @since  1.0.0
     */
    private static function ensureUniqueSlugs(
        $db,
        string $langTableName,
        string $primaryColumnName,
        int $currentId,
        array $fields,
        array $aliasFields,
        string $mainTableName,
        string $mainPrimaryColumn,
        array $aliasUniqueScope,
        string $langCode,
        array $savedByLang,
        array $generated = [],
    ): array {
        if (empty($aliasFields) || $currentId <= 0 || $generated === []) {
            return $fields;
        }

        $mainTableReal = $db->replacePrefix($mainTableName);
        $scopeValues = self::loadScopeValues(
            db:                $db,
            mainTableReal:     $mainTableReal,
            mainPrimaryColumn: $mainPrimaryColumn,
            currentId:         $currentId,
            aliasUniqueScope:  $aliasUniqueScope,
        );

        $defaultCode = self::getDefaultLanguageTag();
        $existingTables = self::fetchTableList(db: $db);
        $defaultTable = $db->replacePrefix($mainTableName . '_' . $defaultCode);

        foreach ($aliasFields as $aliasField) {
            if (empty($generated[$aliasField]) || !isset($fields[$aliasField])
                || !self::isSafeIdentifier(name: $aliasField)) {
                continue;
            }

            $base = (string) $fields[$aliasField];

            if ($base === '') {
                continue;
            }

            // The default language's alias is this record's alias wherever it has none.
            $readingTables = $langCode === $defaultCode
                ? self::languageTablesWithoutOwnSlug(
                    db:                $db,
                    mainTableName:     $mainTableName,
                    primaryColumnName: $primaryColumnName,
                    currentId:         $currentId,
                    field:             $aliasField,
                    savedByLang:       $savedByLang,
                    exceptLang:        $defaultCode,
                )
                : [];

            $fields[$aliasField] = self::makeUniqueSlug(
                db:                $db,
                langTableName:     $langTableName,
                primaryColumnName: $primaryColumnName,
                currentId:         $currentId,
                field:             $aliasField,
                base:              $base,
                mainTableReal:     $mainTableReal,
                mainPrimaryColumn: $mainPrimaryColumn,
                scopeValues:       $scopeValues,
                readingTables:     $readingTables,
                defaultTable:      $langCode !== $defaultCode && \in_array($defaultTable, $existingTables, true)
                    ? $defaultTable
                    : '',
            );
        }

        return $fields;
    }

    /**
     * The language tables (other than $exceptLang's) in which one record has no alias of
     * its own after a save: the value this save writes when it carries that language,
     * otherwise the stored one.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param string $mainTableName Base main table (e.g. #__alfa_items).
     * @param string $primaryColumnName PK column of the language tables (e.g. id_item).
     * @param int $currentId The record.
     * @param string $field The alias field.
     * @param array $savedByLang The save's resolved fields, by language.
     * @param string $exceptLang Lower-snake code of the language left out (the default).
     *
     * @return string[] Prefix-resolved table names.
     *
     * @since  2.1.1
     */
    private static function languageTablesWithoutOwnSlug(
        $db,
        string $mainTableName,
        string $primaryColumnName,
        int $currentId,
        string $field,
        array $savedByLang,
        string $exceptLang,
    ): array {
        $existingTables = self::fetchTableList(db: $db);
        $tables = [];

        foreach (self::getLanguageTags() as $code) {
            $table = $db->replacePrefix($mainTableName . '_' . $code);

            if ($code === $exceptLang || !\in_array($table, $existingTables, true)) {
                continue;
            }

            if (\array_key_exists($field, $savedByLang[$code] ?? [])) {
                $own = (string) $savedByLang[$code][$field];
            } else {
                try {
                    $own = (string) $db->setQuery(
                        $db->getQuery(true)
                            ->select($db->qn($field))
                            ->from($db->qn($table))
                            ->where($db->qn($primaryColumnName) . ' = ' . (int) $currentId),
                        0,
                        1,
                    )->loadResult();
                } catch (Throwable $e) {
                    $own = '';
                }
            }

            if ($own === '') {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Read the current record's scope-column values from the main table.
     * Returns [] when no (safe) scope columns are requested, i.e. global uniqueness.
     *
     * @return array<string, mixed> [columnName => value]
     * @since  1.0.0
     */
    private static function loadScopeValues(
        $db,
        string $mainTableReal,
        string $mainPrimaryColumn,
        int $currentId,
        array $aliasUniqueScope,
    ): array {
        $safeScope = array_values(array_filter(
            $aliasUniqueScope,
            static fn (string $col): bool => self::isSafeIdentifier(name: $col),
        ));

        if (empty($safeScope) || !self::isSafeIdentifier(name: $mainPrimaryColumn)) {
            return [];
        }

        try {
            $columns = implode(', ', array_map(static fn (string $c): string => $db->qn($c), $safeScope));
            $row = $db->setQuery(
                'SELECT ' . $columns . ' FROM ' . $db->qn($mainTableReal)
                . ' WHERE ' . $db->qn($mainPrimaryColumn) . ' = ' . (int) $currentId,
            )->loadAssoc();
        } catch (Throwable $e) {
            return [];
        }

        return $row ?: [];
    }

    /**
     * Return a slug unique for the given scope, appending -2, -3, ... on collision
     * (capped at 100 attempts, then a time() suffix).
     *
     * A collision is another record's own alias in $langTableName or in any of
     * $readingTables, or, with $defaultTable given, the default language alias of
     * another record that has no alias of its own in $langTableName.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param string $langTableName Prefix-resolved language table the slug is for.
     * @param string $primaryColumnName PK column of the language tables.
     * @param int $currentId Record being written (excluded from collision checks).
     * @param string $field The alias field.
     * @param string $base The wanted slug.
     * @param string $mainTableReal Prefix-resolved main table, for the scope join.
     * @param string $mainPrimaryColumn Main-table PK.
     * @param array $scopeValues [column => value] of the record's scope ([] = global).
     * @param string[] $readingTables Further language tables whose own aliases count.
     * @param string $defaultTable The default language's table, or '' for none.
     *
     * @since  1.0.0
     */
    private static function makeUniqueSlug(
        $db,
        string $langTableName,
        string $primaryColumnName,
        int $currentId,
        string $field,
        string $base,
        string $mainTableReal,
        string $mainPrimaryColumn,
        array $scopeValues,
        array $readingTables = [],
        string $defaultTable = '',
    ): string {
        $candidate = $base;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $taken = false;

            foreach (array_merge([$langTableName], $readingTables) as $table) {
                $query = $db->getQuery(true)
                    ->select('1')
                    ->from($db->qn($table, 't'))
                    ->where($db->qn('t.' . $field) . ' = ' . $db->q($candidate))
                    ->where($db->qn('t.' . $primaryColumnName) . ' <> ' . (int) $currentId);

                $taken = self::slugProbeHits(
                    db:                $db,
                    query:             $query,
                    mainTableReal:     $mainTableReal,
                    mainPrimaryColumn: $mainPrimaryColumn,
                    keyColumn:         't.' . $primaryColumnName,
                    scopeValues:       $scopeValues,
                );

                if ($taken) {
                    break;
                }
            }

            // Records with no alias of their own here are routed by their default language alias.
            if (!$taken && $defaultTable !== '') {
                $query = $db->getQuery(true)
                    ->select('1')
                    ->from($db->qn($defaultTable, 'd'))
                    ->join(
                        'LEFT',
                        $db->qn($langTableName, 't')
                        . ' ON ' . $db->qn('t.' . $primaryColumnName) . ' = ' . $db->qn('d.' . $primaryColumnName),
                    )
                    ->where($db->qn('d.' . $field) . ' = ' . $db->q($candidate))
                    ->where($db->qn('d.' . $primaryColumnName) . ' <> ' . (int) $currentId)
                    ->where('COALESCE(' . $db->qn('t.' . $field) . ", '') = ''");

                $taken = self::slugProbeHits(
                    db:                $db,
                    query:             $query,
                    mainTableReal:     $mainTableReal,
                    mainPrimaryColumn: $mainPrimaryColumn,
                    keyColumn:         'd.' . $primaryColumnName,
                    scopeValues:       $scopeValues,
                );
            }

            if (!$taken) {
                return $candidate;
            }

            $candidate = StringHelper::increment($candidate, 'dash');
        }

        return $base . '-' . time();
    }

    /**
     * Run one collision probe of {@see self::makeUniqueSlug()}, limited to the record's scope.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param \Joomla\Database\QueryInterface $query The probe.
     * @param string $mainTableReal Prefix-resolved main table.
     * @param string $mainPrimaryColumn Main-table PK.
     * @param string $keyColumn The probe's qualified record key (e.g. "t.id_item").
     * @param array $scopeValues [column => value] of the record's scope ([] = global).
     *
     * @return bool True when another record already holds the slug; false also when a
     *              language table or column does not exist yet, so nothing can collide.
     *
     * @since  2.1.1
     */
    private static function slugProbeHits(
        $db,
        $query,
        string $mainTableReal,
        string $mainPrimaryColumn,
        string $keyColumn,
        array $scopeValues,
    ): bool {
        if ($scopeValues !== []) {
            $query->join(
                'INNER',
                $db->qn($mainTableReal, 'm')
                . ' ON ' . $db->qn('m.' . $mainPrimaryColumn) . ' = ' . $db->qn($keyColumn),
            );

            foreach ($scopeValues as $col => $value) {
                $query->where($db->qn('m.' . $col) . ' = ' . $db->q($value));
            }
        }

        try {
            return (bool) $db->setQuery($query, 0, 1)->loadResult();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The other records whose effective alias in one language is $alias, within a scope: their
     * own alias in that language, or their default-language alias when they have none there.
     * One query; at most ten ids, lowest first.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param string $mainTableReal Prefix-resolved main table.
     * @param string $mainPrimaryColumn Main-table PK.
     * @param string $langTable Prefix-resolved language table of the language judged.
     * @param string $defaultTable Prefix-resolved default-language table, '' when there is none.
     * @param string $primaryColumnName PK column of the language tables.
     * @param string $field The alias field.
     * @param string $alias The address.
     * @param int $currentId The record judged, left out.
     * @param array $scopeValues [column => value] of the scope ([] = global).
     * @param int[] $onlyIds When given, only these records are looked at.
     *
     * @return int[] The record ids; none also when a table or column does not exist yet.
     *
     * @since  2.1.1
     */
    private static function effectiveAliasHolders(
        $db,
        string $mainTableReal,
        string $mainPrimaryColumn,
        string $langTable,
        string $defaultTable,
        string $primaryColumnName,
        string $field,
        string $alias,
        int $currentId,
        array $scopeValues,
        array $onlyIds = [],
    ): array {
        $query = $db->getQuery(true)
            ->select($db->qn('m.' . $mainPrimaryColumn))
            ->from($db->qn($mainTableReal, 'm'))
            ->join(
                'LEFT',
                $db->qn($langTable, 't')
                . ' ON ' . $db->qn('t.' . $primaryColumnName) . ' = ' . $db->qn('m.' . $mainPrimaryColumn),
            )
            ->where($db->qn('m.' . $mainPrimaryColumn) . ' <> ' . (int) $currentId)
            ->order($db->qn('m.' . $mainPrimaryColumn) . ' ASC');

        $own = $db->qn('t.' . $field) . ' = ' . $db->q($alias);

        if ($defaultTable !== '' && $defaultTable !== $langTable) {
            $query->join(
                'LEFT',
                $db->qn($defaultTable, 'd')
                . ' ON ' . $db->qn('d.' . $primaryColumnName) . ' = ' . $db->qn('m.' . $mainPrimaryColumn),
            );
            $query->where(
                '(' . $own . ' OR (COALESCE(' . $db->qn('t.' . $field) . ", '') = '' AND "
                . $db->qn('d.' . $field) . ' = ' . $db->q($alias) . '))',
            );
        } else {
            $query->where($own);
        }

        foreach ($scopeValues as $column => $value) {
            if (self::isSafeIdentifier(name: (string) $column)) {
                $query->where($db->qn('m.' . $column) . ' = ' . $db->q((string) $value));
            }
        }

        if ($onlyIds !== []) {
            $query->whereIn($db->qn('m.' . $mainPrimaryColumn), array_map('intval', $onlyIds));
        }

        try {
            return array_map('intval', (array) $db->setQuery($query, 0, 10)->loadColumn());
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * One stored value of one record in one language table, '' when there is none.
     *
     * @param object $db Joomla DatabaseDriver.
     * @param string $table Prefix-resolved language table.
     * @param string $primaryColumnName PK column of the language table.
     * @param int $id The record.
     * @param string $field The column.
     *
     * @since  2.1.1
     */
    private static function readStoredValue($db, string $table, string $primaryColumnName, int $id, string $field): string
    {
        try {
            return (string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn($field))
                    ->from($db->qn($table))
                    ->where($db->qn($primaryColumnName) . ' = ' . (int) $id),
                0,
                1,
            )->loadResult();
        } catch (Throwable $e) {
            return '';
        }
    }

    //  Private: database schema

    /**
     * Return the SQL column definition for a given field name.
     *
     * Fields containing any LONGTEXT_HINTS substring get  LONGTEXT NULL.
     * Everything else gets  VARCHAR(255) NOT NULL DEFAULT ''.
     * @since  1.0.0
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
     * @throws RuntimeException
     * @since  1.0.0
     */
    private static function createLangTable(
        $db,
        string $langTableName,
        string $primaryColumnName,
        array $fields,
    ): void {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);
        self::refuseInsideTransaction(db: $db, langTableName: $langTableName, lacks: 'the table');

        $colDefs = $db->qn($primaryColumnName) . ' INT(11) UNSIGNED NOT NULL';

        foreach (array_keys($fields) as $fieldName) {
            if (!self::isSafeIdentifier(name: $fieldName)) {
                continue;
            }
            $colDefs .= ', ' . $db->qn($fieldName) . ' ' . self::columnTypeForField(fieldName: $fieldName);
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$langTableName} (
                    {$colDefs},
                    PRIMARY KEY (" . $db->qn($primaryColumnName) . ')
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        try {
            $db->setQuery($sql)->execute();
        } catch (Throwable $e) {
            throw new RuntimeException(
                '[MultilingualHelper::createLangTable] Could not create "' . $langTableName
                . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        // The rest of this request sees the new table (a fill right after the sync).
        if (self::$tableList !== null && !in_array($langTableName, self::$tableList, true)) {
            self::$tableList[] = $langTableName;
        }
    }

    /**
     * Refuse a language table change while a transaction is open.
     *
     * MySQL commits the open transaction before any CREATE or ALTER, so the
     * caller's work would be committed half done and its later rollback would
     * undo nothing. A save that needs a table or column made runs
     * ensureLangSchemaForData() before it opens its transaction; the
     * component's language sync makes them at install and update.
     *
     * @param mixed $db The connection.
     * @param string $langTableName The language table.
     * @param string $lacks What it lacks, in words.
     *
     *
     * @throws RuntimeException When a transaction is open.
     *
     * @since  2.0.0
     */
    private static function refuseInsideTransaction($db, string $langTableName, string $lacks): void
    {
        if (TransactionState::isOpen($db)) {
            throw new RuntimeException(
                '[MultilingualHelper] "' . $langTableName . '" lacks ' . $lacks
                . '; it is not changed inside an open transaction, which MySQL would commit.'
                . ' A save makes its language schema before it opens one (ensureLangSchemaForData()).',
            );
        }
    }

    /**
     * Add missing columns to an existing language table (ALTER TABLE ADD COLUMN).
     *
     * Reads information_schema before each ALTER so the statement is only
     * executed when the column genuinely does not exist yet.
     *
     * @throws RuntimeException
     * @since  1.0.0
     */
    private static function ensureColumnsExist(
        $db,
        string $langTableName,
        array $fields,
    ): void {
        $bareTableName = str_replace('`', '', $langTableName);

        try {
            $existingColumns = $db->setQuery(
                'SELECT COLUMN_NAME
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = ' . $db->q($bareTableName),
            )->loadColumn();
        } catch (Throwable $e) {
            throw new RuntimeException(
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

            self::refuseInsideTransaction(db: $db, langTableName: $langTableName, lacks: 'the column "' . $fieldName . '"');

            try {
                $db->setQuery(
                    'ALTER TABLE ' . $langTableName
                    . ' ADD COLUMN ' . $db->qn($fieldName)
                    . ' ' . self::columnTypeForField(fieldName: $fieldName),
                )->execute();

                self::log(
                    callerMethod: __METHOD__,
                    message:      'Added column "' . $fieldName . '" to "' . $langTableName . '".',
                    priority:     Log::INFO,
                );
            } catch (Throwable $e) {
                throw new RuntimeException(
                    '[MultilingualHelper::ensureColumnsExist] ALTER TABLE failed for column "'
                    . $fieldName . '" on "' . $langTableName . '": ' . $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }

    /**
     * Upsert a single language row, writing ONLY the supplied columns.
     *
     * INSERT ... ON DUPLICATE KEY UPDATE, not REPLACE INTO. REPLACE is DELETE +
     * INSERT, so a caller that saves a subset of the columns (the list inline
     * quick-edit saves just `name`) silently blanked every column it did not
     * pass: alias, descriptions, meta. Updating column-by-column makes a
     * partial save safe by construction; a full form save posts every column
     * and is unaffected.
     *
     * `VALUES(col)` is deprecated in MySQL 8.0.20+ in favour of a row alias,
     * but is the form both MySQL 5.7+ and MariaDB accept, so it stays.
     *
     * @throws RuntimeException
     * @since  1.0.0
     */
    private static function upsertLangRow(
        $db,
        string $langTableName,
        string $primaryColumnName,
        int $currentId,
        array $fields,
    ): void {
        self::assertSafeIdentifier(name: $primaryColumnName, callerMethod: __METHOD__);

        $columns = [$db->qn($primaryColumnName)];
        $values = [$db->q($currentId)];
        $updates = [];

        foreach ($fields as $fieldName => $value) {
            if (!self::isSafeIdentifier(name: $fieldName)) {
                continue;
            }
            $columns[] = $db->qn($fieldName);
            $values[] = $db->q((string) $value);
            $updates[] = $db->qn($fieldName) . ' = VALUES(' . $db->qn($fieldName) . ')';
        }

        if (count($columns) === 1) {
            self::log(
                callerMethod: __METHOD__,
                message:      'No valid columns to upsert into "' . $langTableName . '". Row skipped.',
                priority:     Log::WARNING,
            );
            return;
        }

        $sql = 'INSERT INTO ' . $langTableName
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $values) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        try {
            $db->setQuery($sql)->execute();
        } catch (Throwable $e) {
            throw new RuntimeException(
                '[MultilingualHelper::upsertLangRow] Upsert failed for "'
                . $langTableName . '", ID ' . $currentId . ': ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    //  Private: utilities

    /**
     * Fetch all physical table names from the active database.
     * Read once per request ({@see self::$tableList}); a language table this class
     * creates later in the request is added by createLangTable().
     * @since  1.0.0
     */
    private static function fetchTableList($db): array
    {
        if (self::$tableList === null) {
            try {
                self::$tableList = $db->setQuery('SHOW TABLES')->loadColumn();
            } catch (Throwable $e) {
                throw new RuntimeException(
                    '[MultilingualHelper::fetchTableList] Could not retrieve table list: '
                    . $e->getMessage(),
                    previous: $e,
                );
            }
        }

        return self::$tableList;
    }

    /**
     * Column names of a (prefix-expanded) table, cached per request per table.
     *
     * Used by addMultilingualJoinToQuery to skip a translatable field whose
     * per-language column has not been materialised yet. A read failure yields an
     * empty list, so the caller treats every field as absent and emits placeholders
     * rather than throwing.
     *
     * @since  2.0.0
     */
    private static function fetchColumnList($db, string $table): array
    {
        static $cache = [];

        if (!array_key_exists($table, $cache)) {
            try {
                $cache[$table] = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($table))->loadColumn();
            } catch (Throwable $e) {
                $cache[$table] = [];
            }
        }

        return $cache[$table];
    }

    /** Retrieve the DatabaseDriver from the Joomla DI container.
     * @since  1.0.0
     */
    private static function getDb()
    {
        try {
            return Factory::getContainer()->get('DatabaseDriver');
        } catch (Throwable $e) {
            throw new RuntimeException(
                '[MultilingualHelper::getDb] Could not retrieve DatabaseDriver: '
                . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /** Normalise a Joomla language tag to lower-snake format:  "en-GB" -> "en_gb".
     * @since  1.0.0
     */
    public static function normaliseTag(string $tag): string
    {
        return strtolower(str_replace('-', '_', $tag));
    }

    /** The inverse of {@see self::normaliseTag()}:  "el_gr" -> "el-GR".
     *
     * Joomla resolves a transliterator by loading `language/<tag>/localise.php`, so the
     * storage spelling finds nothing and silently degrades to the ICU default.
     *
     * @since  1.0.0
     */
    private static function displayTag(string $tag): string
    {
        $parts = explode('_', strtolower($tag));

        foreach ($parts as $i => $part) {
            if ($i > 0 && \strlen($part) === 2) {
                $parts[$i] = strtoupper($part);
            }
        }

        return implode('-', $parts);
    }

    /**
     * Return true when $name is a safe SQL identifier (letters, digits, underscore).
     * Logs a WARNING and returns false otherwise; callers skip the offending field.
     * @since  1.0.0
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
     * @throws InvalidArgumentException
     * @since  1.0.0
     */
    private static function assertSafeIdentifier(string $name, string $callerMethod): void
    {
        if (!self::isSafeIdentifier(name: $name)) {
            throw new InvalidArgumentException(
                '[' . $callerMethod . '] Unsafe SQL identifier: "' . $name . '"',
            );
        }
    }

    /**
     * Validate the three required arguments shared by save / get operations.
     *
     * @throws InvalidArgumentException
     * @since  1.0.0
     */
    private static function assertValidArguments(
        int $currentId,
        string $primaryColumnName,
        string $tableName,
        string $callerMethod,
    ): void {
        if ($currentId <= 0) {
            throw new InvalidArgumentException(
                '[' . $callerMethod . '] $currentId must be > 0, got: ' . $currentId,
            );
        }
        if (empty(trim($primaryColumnName))) {
            throw new InvalidArgumentException(
                '[' . $callerMethod . '] $primaryColumnName must not be empty.',
            );
        }
        if (empty(trim($tableName))) {
            throw new InvalidArgumentException(
                '[' . $callerMethod . '] $tableName must not be empty.',
            );
        }
    }

    //  Logging

    /**
     * Write a message to the component log file.
     *
     * Format:  [MultilingualHelper::callingMethod] Human-readable message
     * Always pass __METHOD__ as $callerMethod so log lines are grep-friendly.
     *
     * @param string $callerMethod Pass __METHOD__ at every call site.
     * @param string $message Human-readable description.
     * @param int $priority Log::DEBUG | Log::INFO | Log::WARNING | Log::ERROR
     * @since  1.0.0
     */
    private static function log(
        string $callerMethod,
        string $message,
        int $priority = Log::DEBUG,
    ): void {
        // Delegated: AlfaLog owns the whole map, com_alfa.php included.
        // Unguarded on purpose; see AlfaLog::register().
        AlfaLog::register();

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
     * not a hardcoded list, so adding a new site language Just Works.
     * Missing language tables are silently skipped (fresh-install safety).
     *
     * @param int|int[] $ids One or more parent record IDs.
     * @param string $primaryColumnName PK column in the lang tables (e.g. "id_orderstatus").
     * @param string $tableName Base table with Joomla prefix (e.g. "#__alfa_orders_statuses").
     *
     * @return bool True on success, false when there is nothing to delete.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException On any unrecoverable database error.
     * @since  1.0.0
     */
    public static function deleteMultilingualData(
        int|array $ids,
        string $primaryColumnName,
        string $tableName,
    ): bool {
        $idList = array_values(array_filter(
            array_map('intval', is_array($ids) ? $ids : [$ids]),
            static fn (int $id): bool => $id > 0,
        ));

        if (empty($idList)) {
            return false;
        }

        if (empty(trim($primaryColumnName)) || empty(trim($tableName))) {
            throw new InvalidArgumentException(
                '[MultilingualHelper::deleteMultilingualData] $primaryColumnName and $tableName are required.',
            );
        }

        $db = self::getDb();
        $existingTables = self::fetchTableList(db: $db);
        $langCodes = self::buildLangCodeList(
            languages: LanguageHelper::getLanguages('lang_code'),
        );

        // Same contract as above: savepoint-aware, or the caller's open
        // transaction is implicitly committed out from under it.
        $db->transactionStart(true);

        try {
            foreach ($langCodes as $langCode) {
                $langTableName = $db->replacePrefix("{$tableName}_{$langCode}");

                if (!in_array($langTableName, $existingTables, true)) {
                    continue; // Language table doesn't exist for this language; nothing to delete.
                }

                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->qn($langTableName))
                        ->whereIn($db->qn($primaryColumnName), $idList),
                )->execute();

                self::log(
                    callerMethod: __METHOD__,
                    message:      'Deleted ' . count($idList) . ' row(s) from "' . $langTableName . '".',
                    priority:     Log::DEBUG,
                );
            }

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            self::log(
                callerMethod: __METHOD__,
                message:      'Transaction rolled back: ' . $e->getMessage(),
                priority:     Log::ERROR,
            );
            throw new RuntimeException(
                '[MultilingualHelper::deleteMultilingualData] Translations delete failed for "'
                . $tableName . '": ' . $e->getMessage(),
                previous: $e,
            );
        }

        return true;
    }
}
