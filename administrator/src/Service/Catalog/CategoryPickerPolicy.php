<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Which categories a record may be newly given, and how an administrator's picker names the others.
 *
 * A category is available for a new assignment when it is published or unpublished and none of its parent categories,
 * at any level, is archived or trashed: its availability follows the hierarchy, not only its own state. A category a
 * record already holds is always kept, shown and marked, whatever its state or its parents'; staff may remove it.
 * The pickers list the available categories and the held ones; every save refuses a NEW assignment of a category that
 * is not available, and never drops or refuses one the record already holds. Every state other than published is
 * said in words after the category's name ("Shoes (Parent in trash)"), in the list and in the chosen value alike.
 *
 * The storefront has its own narrowing (published categories the visitor may see) and does not use this.
 *
 * @since  2.1.1
 */
final class CategoryPickerPolicy
{
    /**
     * The own states a new choice may take; the parent categories decide too (reason()).
     *
     * @var int[]
     *
     * @since  2.1.1
     */
    public const OFFERED_STATES = [1, 0];

    /**
     * Why a category is marked: its own state, or the state of one of its parent categories.
     *
     * @since  2.1.1
     */
    public const UNPUBLISHED = 'unpublished';

    /** @since  2.1.1 */
    public const ARCHIVED = 'archived';

    /** @since  2.1.1 */
    public const TRASHED = 'trashed';

    /** @since  2.1.1 */
    public const PARENT_ARCHIVED = 'parent_archived';

    /** @since  2.1.1 */
    public const PARENT_TRASHED = 'parent_trashed';

    /** @since  2.1.1 */
    public const MISSING = 'missing';

    /**
     * The language key of the words put after a marked category, by reason.
     *
     * @var array<string, string>
     *
     * @since  2.1.1
     */
    private const MARKS = [
        self::UNPUBLISHED => 'COM_ALFA_CATEGORY_MARK_UNPUBLISHED',
        self::ARCHIVED => 'COM_ALFA_CATEGORY_MARK_ARCHIVED',
        self::TRASHED => 'COM_ALFA_CATEGORY_MARK_TRASHED',
        self::PARENT_ARCHIVED => 'COM_ALFA_CATEGORY_MARK_PARENT_ARCHIVED',
        self::PARENT_TRASHED => 'COM_ALFA_CATEGORY_MARK_PARENT_TRASHED',
        self::MISSING => 'COM_ALFA_CATEGORY_MARK_MISSING',
    ];

    /**
     * Every category's parent and state, the tree the rule reads.
     *
     * @param DatabaseInterface|null $db The database; the application's when absent.
     *
     * @return array<int, array{parent: int, state: int}>
     *
     * @since  2.1.1
     */
    public static function tree(?DatabaseInterface $db = null): array
    {
        $db ??= Factory::getContainer()->get('DatabaseDriver');
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'parent_id', 'state']))
                ->from($db->quoteName('#__alfa_categories')),
        )->loadAssocList() ?: [];

        return self::treeOf(rows: $rows);
    }

    /**
     * The tree of rows a caller already holds (id, parent_id, state, as arrays or objects).
     *
     * @param iterable $rows The category rows.
     *
     * @return array<int, array{parent: int, state: int}>
     *
     * @since  2.1.1
     */
    public static function treeOf(iterable $rows): array
    {
        $tree = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $tree[(int) $row['id']] = ['parent' => (int) ($row['parent_id'] ?? 0), 'state' => (int) ($row['state'] ?? 1)];
        }

        return $tree;
    }

    /**
     * Why a category is marked, or null when it is published and none of its parent categories is archived or
     * trashed. Its own archived or trashed state comes first; then a trashed parent at any level, then an archived
     * one; then its own unpublished state.
     *
     * @param int $id The category.
     * @param array<int, array{parent: int, state: int}> $tree The tree (tree()).
     *
     * @return string|null One of the reason constants, or null.
     *
     * @since  2.1.1
     */
    public static function reason(int $id, array $tree): ?string
    {
        if (!isset($tree[$id])) {
            return self::MISSING;
        }

        $state = $tree[$id]['state'];

        if ($state === -2) {
            return self::TRASHED;
        }

        if ($state === 2) {
            return self::ARCHIVED;
        }

        $archivedAbove = false;
        $seen = [$id => true];
        $node = $tree[$id]['parent'];

        while ($node > 0 && isset($tree[$node]) && !isset($seen[$node])) {
            $seen[$node] = true;

            if ($tree[$node]['state'] === -2) {
                return self::PARENT_TRASHED;
            }

            $archivedAbove = $archivedAbove || $tree[$node]['state'] === 2;
            $node = $tree[$node]['parent'];
        }

        if ($archivedAbove) {
            return self::PARENT_ARCHIVED;
        }

        return $state === 0 ? self::UNPUBLISHED : null;
    }

    /**
     * Whether a new choice may take a category with this reason.
     *
     * @param string|null $reason The category's reason (reason()).
     *
     * @return bool True when it is available.
     *
     * @since  2.1.1
     */
    public static function offered(?string $reason): bool
    {
        return $reason === null || $reason === self::UNPUBLISHED;
    }

    /**
     * Whether a picker lists a category: available, or held by the record being edited.
     *
     * @param string|null $reason The category's reason (reason()).
     * @param bool $held Whether the record already holds the category.
     *
     * @return bool True when it is listed.
     *
     * @since  2.1.1
     */
    public static function lists(?string $reason, bool $held): bool
    {
        return $held || self::offered($reason);
    }

    /**
     * The text a picker shows for a category: its text, then its reason in words when it has one.
     *
     * @param string $text The category's name, path or indented name.
     * @param string|null $reason The category's reason (reason()).
     *
     * @return string The text to show.
     *
     * @since  2.1.1
     */
    public static function label(string $text, ?string $reason): string
    {
        if ($reason === null || !isset(self::MARKS[$reason])) {
            return $text;
        }

        // A picker is also drawn on other components' screens (a module's or a plugin's settings).
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

        return $text . ' (' . Text::_(self::MARKS[$reason]) . ')';
    }

    /**
     * The options a picker offers for a new choice of category: each candidate that is available (offered()), its text
     * followed by its reason in words (label()), in the candidates' order. Without candidates, every category whose own
     * state may be offered is read, named in the active language: the list of the Items list's batch dialog.
     *
     * @param array<int, array{value: string, text: string}>|null $candidates The categories, as value (id) and text.
     * @param DatabaseInterface|null $db The database; the application's when absent.
     *
     * @return array<int, array{value: string, text: string}> The options.
     *
     * @since  2.1.4
     */
    public static function offeredOptions(?array $candidates = null, ?DatabaseInterface $db = null): array
    {
        $db ??= Factory::getContainer()->get('DatabaseDriver');

        if ($candidates === null) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['a.id', 'a.state']))
                ->from($db->quoteName('#__alfa_categories', 'a'))
                ->whereIn($db->quoteName('a.state'), self::OFFERED_STATES);

            // The name in the active language; translatable columns live in the per-language tables.
            MultilingualHelper::addMultilingualJoinToQuery(
                query:             $query,
                mainAlias:         'a',
                mainPrimaryColumn: 'id',
                langTableBase:     '#__alfa_categories',
                langPrimaryColumn: 'id_category',
                fields:            ['name'],
            );

            $candidates = array_map(
                static fn (array $category): array => ['value' => (string) $category['id'], 'text' => (string) $category['name']],
                $db->setQuery($query)->loadAssocList() ?: [],
            );
        }

        $tree = self::tree(db: $db);
        $options = [];

        foreach ($candidates as $candidate) {
            $reason = self::reason(id: (int) $candidate['value'], tree: $tree);

            // A category inside an archived or trashed category is not available either.
            if (self::offered($reason)) {
                $options[] = ['value' => $candidate['value'], 'text' => self::label(text: $candidate['text'], reason: $reason)];
            }
        }

        return $options;
    }

    /**
     * The categories a save would newly assign that are not available: the posted ones the record does not hold yet.
     *
     * @param array $posted The category ids the save asks for.
     * @param array $stored The category ids the record holds now (none for a new record).
     * @param array<int, array{parent: int, state: int}>|null $tree The tree; read when absent.
     *
     * @return array<int, string> Category id => reason, in the posted order; empty when the save may go on.
     *
     * @since  2.1.1
     */
    public static function refusedNew(array $posted, array $stored, ?array $tree = null): array
    {
        $held = array_fill_keys(array_map('intval', $stored), true);
        $new = array_values(array_unique(array_filter(
            array_map('intval', $posted),
            static fn (int $id): bool => $id > 0 && !isset($held[$id]),
        )));

        if ($new === []) {
            return [];
        }

        $tree ??= self::tree();
        $refused = [];

        foreach ($new as $id) {
            $reason = self::reason(id: $id, tree: $tree);

            if (!self::offered($reason)) {
                $refused[$id] = (string) $reason;
            }
        }

        return $refused;
    }

    /**
     * The refusal of a save that would newly give a record an unavailable category, or null when it may go on. The
     * record's held categories are read from its junction table.
     *
     * @param int $recordId The record (0 for a new one).
     * @param array $posted The category ids the save asks for.
     * @param string $table The junction table (#__alfa_payment_categories, ...).
     * @param string $recordColumn The junction table's record column (payment_id, ...).
     * @param array $alsoHeld Further category ids the record holds elsewhere (a product's default category).
     *
     * @return string|null The message, or null.
     *
     * @since  2.1.1
     */
    public static function refusalForSave(int $recordId, array $posted, string $table, string $recordColumn, array $alsoHeld = []): ?string
    {
        $stored = $recordId > 0 ? (array) AlfaHelper::getAssocsFromDb($recordId, $table, $recordColumn, 'category_id') : [];
        $refused = self::refusedNew(posted: $posted, stored: array_merge($stored, $alsoHeld));

        return $refused === [] ? null : self::refusal(refused: $refused);
    }

    /**
     * The sentence refusing a save, naming every refused category with its reason.
     *
     * @param array<int, string> $refused Category id => reason (refusedNew()).
     *
     * @return string The message.
     *
     * @since  2.1.1
     */
    public static function refusal(array $refused): string
    {
        $names = self::names(ids: array_keys($refused));
        $listed = [];

        foreach ($refused as $id => $reason) {
            $listed[] = self::label(text: $names[$id] ?? '#' . $id, reason: $reason);
        }

        return Text::sprintf('COM_ALFA_CATEGORY_ASSIGN_REFUSED', implode(', ', $listed));
    }

    /**
     * The categories' names in the default content language.
     *
     * @param int[] $ids The categories.
     *
     * @return array<int, string> Category id => name; a category without a name is left out.
     *
     * @since  2.1.1
     */
    public static function names(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id_category', 'name']))
                    ->from($db->quoteName('#__alfa_categories_' . MultilingualHelper::getDefaultLanguageTag()))
                    ->whereIn($db->quoteName('id_category'), $ids),
            )->loadAssocList('id_category', 'name') ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_map('strval', $rows);
    }
}
