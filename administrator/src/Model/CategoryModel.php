<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\EntityChangeNotifier;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RuleScopeGuard;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ScopeDeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\VisibilityNotifier;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Category model.
 *
 * @since  1.0.0
 */
class CategoryModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity this model serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'category';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - delete() guards (a child left behind, or a category still set as an item's
     *   DEFAULT category) return before parent::delete(), so the contract's delete
     *   bracket fires only when something actually reaches the table: nothing was
     *   deleted, nothing to observe.
     * - save() always reaches parent::save(), so the Validate/BeforeSave/AfterSave
     *   bracket covers every category save.
     */

    /**
     * @var string The prefix to use with controller messages.
     *
     * @since  1.0.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     *
     * @since  1.0.0
     */
    public $typeAlias = 'com_alfa.category';

    /**
     * @var null Item data
     *
     * @since  1.0.0
     */
    protected $item = null;

    /**
     * Value carrying "top level" through the batch dialog. It cannot be '0':
     * core's AdminModel::batch() dispatches on `!empty($commands[$identifier])`
     * and would drop the command silently.
     *
     * @since  2.0.0
     */
    private const BATCH_PARENT_ROOT = 'root';

    /**
     * Categories are reparented in place, so the core copy/move branch is
     * disabled; otherwise AdminModel's default ('category_id') would try to
     * copy/move on an unrelated command name.
     *
     * @var bool
     *
     * @since  2.0.0
     */
    protected $batch_copymove = false;

    /**
     * Batch commands offered by the categories list. Deliberately parent only.
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    protected $batch_commands = [
        'parent_id' => 'batchParent',
    ];

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return Form|bool A Form object on success, false on failure
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.category',
            'category',
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.category.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        $pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

        if (isset($this->item[$pk])) {
            return $this->item[$pk];
        }

        if ($item = parent::getItem($pk)) {
            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';

            // Decoded for form binding (the layouts / list_options field groups);
            // Table re-encodes on save.
            $item->layouts = json_decode($item->layouts ?: '{}', true) ?: [];
            $item->list_options = json_decode($item->list_options ?: '{}', true) ?: [];

            $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_users', 'category_id', 'user_id');
            $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');

            // get media origin (cat, man, item...)
            $item->medias = MediaHelper::getMediaData(
                origin: $this->name,
                itemIDs: $item->id,
            );
        }

        $this->item[$pk] = $item;

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since  1.0.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        $table = $this->getTable();
        $pk = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;
        $parentId = (int) ($data['parent_id'] ?? 0);

        // Alias lives exclusively in the language tables (lang-tables-only), so
        // only the parent change matters for cache clearing here.
        $oldParentId = null;

        if (!$isNew && $table->load($pk)) {
            $oldParentId = (int) $table->parent_id;
        }

        // A NEW parent must be available: not archived or trashed, and not inside an archived or trashed category
        // (CategoryPickerPolicy). A parent the category already has is kept, whatever its state.
        if ($parentId > 0 && $parentId !== $oldParentId) {
            $parentRefused = CategoryPickerPolicy::refusedNew(posted: [$parentId], stored: []);

            if ($parentRefused !== []) {
                $this->setError(Text::sprintf(
                    'COM_ALFA_CATEGORY_PARENT_REFUSED',
                    CategoryPickerPolicy::label(
                        text:   CategoryPickerPolicy::names(ids: [$parentId])[$parentId] ?? '#' . $parentId,
                        reason: $parentRefused[$parentId],
                    ),
                ));

                return false;
            }
        }

        // Always. The cached category data, path and tree are now filtered by the
        // visitor's allowlist, so editing ONLY "allowed users / user groups" (which
        // changes neither the parent nor newness) must still invalidate them, or a
        // category stays visible (or stays hidden) for the cache lifetime of a year.
        // Categories are saved rarely, so clearing unconditionally is far cheaper
        // than a condition that has to be remembered every time a field is added.
        $needsCacheClearing = true;

        $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

        // Fetch uploads separately so $data (with the lang keys) is not clobbered.
        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        // URL ALIASES, judged before anything is written: a save that would put this category
        // on another one's address in any language is refused; an address it already shared
        // is kept and said after the save. A copy takes its own aliases, generated.
        if ($input->get('task') === 'save2copy') {
            $data = MultilingualHelper::releaseCopiedAliases(
                data:              $data,
                tableName:         '#__alfa_categories',
                primaryColumnName: 'id_category',
                originalId:        $input->getInt('id'),
                aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_categories'],
            );
        }

        $aliasClashes = MultilingualHelper::aliasClashes(
            tableName:         '#__alfa_categories',
            primaryColumnName: 'id_category',
            currentId:         $isNew ? 0 : $pk,
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_categories'],
            aliasUniqueScope:  MultilingualAliasConfig::SCOPE['#__alfa_categories'],
        );

        if ($aliasClashes['refuse'] !== []) {
            $this->setError(implode(' ', $aliasClashes['refuse']));

            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // MULTILINGUAL: persist per-language translations (name, alias, desc,
        // meta_title, meta_desc) to the language tables. A blank alias slug is
        // generated, sanitised and made unique within the parent category.
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_category',
            tableName:         '#__alfa_categories',
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_categories'],
            aliasUniqueScope:  MultilingualAliasConfig::SCOPE['#__alfa_categories'] ?? [],
        );

        foreach ($aliasClashes['warn'] as $warning) {
            $app->enqueueMessage($warning, 'warning');
        }

        if (!empty($data['media'])) {
            $defaultLangTag = MultilingualHelper::getDefaultLanguageTag();

            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    $this->name,
                customFileName: $data['alias_' . $defaultLangTag] ?? '',
            );
        }

        // VALIDATION: Check for circular references AFTER save
        if ($this->hasCircularReference($currentId)) {
            // Fix the circular reference by setting parent_id to 0 (root level)
            $table->load($currentId);
            $table->parent_id = 0;
            $table->store();

            // Enqueue warning message
            Factory::getApplication()->enqueueMessage(
                Text::_('COM_ALFA_WARNING_CATEGORY_CIRCULAR_REFERENCE_FIXED'),
                'warning',
            );

            // Update cache clearing flag since we modified parent_id
            $needsCacheClearing = true;
            $parentId = 0; // Update for cache clearing
        }

        AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'] ?? [], '#__alfa_categories_users', 'category_id', 'user_id');
        AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');

        // The dangerous one: under inherit-from-default-category this changes the
        // EFFECTIVE audience of every product whose default category this is. The
        // event resolves that fan-out; core does not schedule it.
        VisibilityNotifier::changed(categoryIds: [$currentId]);

        // Clear cache if needed
        if ($needsCacheClearing) {
            $this->clearRelatedCache($currentId, $oldParentId, $parentId);
        }

        $this->resyncAssignedItems($currentId);

        // AFTER the per-language rows are written (saveMultilingualData above): a
        // listener materialising this category re-reads names/descriptions, so the
        // signal must not race the data it points at.
        EntityChangeNotifier::changed(entity: 'category', ids: [$currentId]);

        return true;
    }

    /**
     * Re-sync the search documents of every item assigned to this category.
     *
     * The category's name and publication state are copied into each assigned item's
     * stored document, so one edit here changes many documents. Directly assigned
     * items only, never the descendant closure: the document carries assigned
     * categories alone, so a rename cannot reach an item that does not list this
     * category itself.
     *
     * Run unconditionally, not only when category names are searchable: with the
     * setting off the projected text is identical and the indexer's hash comparison
     * makes the sweep write-free, whereas skipping it would leave a stale index the
     * day the setting is enabled.
     *
     * Never fatal: a search index is downstream of the catalogue and must not be
     * able to fail a save or a state change.
     *
     * @param int ...$categoryIds The saved, renamed or state-changed category ids.
     *
     *
     * @since  2.0.0
     */
    private function resyncAssignedItems(int ...$categoryIds): void
    {
        $categoryIds = array_values(array_filter($categoryIds, static fn (int $id): bool => $id > 0));

        if ($categoryIds === []) {
            return;
        }

        try {
            $db = $this->getDatabase();

            $affectedItems = array_map('intval', (array) $db->setQuery(
                $db->getQuery(true)
                    ->select('DISTINCT ' . $db->quoteName('item_id'))
                    ->from($db->quoteName('#__alfa_items_categories'))
                    ->whereIn($db->quoteName('category_id'), $categoryIds),
            )->loadColumn());

            if ($affectedItems !== []) {
                (new ProjectionManager())->syncItems($affectedItems);
            }
        } catch (Throwable $e) {
            Log::add(
                '[CategoryModel] Search re-sync failed for category(ies) '
                . implode(', ', $categoryIds) . ': ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }
    }

    /**
     * Run the side effects a rename needs when the record was NOT saved through
     * the edit form: currently the list's inline quick-edit.
     *
     * Both are the form save's own calls: the persistent category cache stores the
     * language-resolved name and path (so the storefront would keep serving the old
     * name for the cache lifetime), and the name is embedded in the search document
     * of every assigned item.
     *
     * @param int $categoryId The renamed category id.
     *
     *
     * @since  2.0.0
     */
    public function applyRenameSideEffects(int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $table = $this->getTable();
        $parentId = $table->load($categoryId) ? (int) $table->parent_id : 0;

        // A rename cannot move the row, so old and new parent are the same one.
        $this->clearRelatedCache($categoryId, $parentId, $parentId);

        $this->resyncAssignedItems($categoryId);

        EntityChangeNotifier::changed(entity: 'category', ids: [$categoryId]);
    }

    /**
     * Batch command: file the selected categories under one new parent.
     *
     * The tree is a plain adjacency list, so the move IS the `parent_id` update and
     * descendants follow their moved ancestor with nothing to rebuild. What the move
     * does invalidate is the persistent category cache (paths and trees), cleaned
     * once at the end.
     *
     * Refuses per row, and reports how many and why, rather than silently repairing:
     * a row that would form a cycle, a row checked out by somebody else, a row the
     * user may not edit, and a row whose URL alias is already taken under the
     * destination parent (auto-appending "-2" would change a live public URL behind
     * the merchant's back).
     *
     * @param mixed $value 'root' for top level, otherwise the destination category id.
     * @param array $pks The selected category ids.
     * @param array $contexts Per-id ACL asset names, keyed by id.
     *
     * @return bool False only when nothing could be attempted at all.
     *
     * @since  2.0.0
     */
    protected function batchParent($value, $pks, $contexts)
    {
        $app = Factory::getApplication();
        $raw = \is_array($value) ? (string) reset($value) : (string) $value;

        if ($raw === '') {
            return true;
        }

        $isRoot = $raw === self::BATCH_PARENT_ROOT;
        $targetId = $isRoot ? 0 : (int) $raw;
        $nodes = $this->loadCategoryNodes();

        // Anything that is neither the root sentinel nor a live category id is a
        // stale or crafted request; do NOT let a junk string cast down to 0 and
        // silently mean "top level". Trashed/archived destinations are refused too,
        // and so is a destination inside an archived or trashed category.
        $tree = array_map(static fn (array $node): array => ['parent' => $node['parent_id'], 'state' => $node['state']], $nodes);

        if (!$isRoot && (!\in_array((int) ($nodes[$targetId]['state'] ?? -99), [0, 1], true)
            || !CategoryPickerPolicy::offered(CategoryPickerPolicy::reason(id: $targetId, tree: $tree)))) {
            $this->setError(Text::_('COM_ALFA_CATEGORY_BATCH_PARENT_INVALID'));

            return false;
        }

        $pks = array_values(array_filter(array_unique(array_map('intval', (array) $pks))));

        $refused = [
            'COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_DENIED' => [],
            'COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_CYCLE' => [],
            'COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_LOCKED' => [],
            'COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_ALIAS' => [],
        ];

        $cycle = array_fill_keys($this->cycleFormingIds(targetId: $targetId, candidates: $pks, nodes: $nodes), true);
        $userId = (int) $this->user->id;

        // Reparenting is editing. Alfa grants it per section, never per record, so it is asked
        // once; the controller refuses first, this keeps the command safe on any other path.
        $mayEdit = AlfaHelper::can(section: 'categories', action: 'edit', user: $this->user);

        // Candidates surviving the cheap checks; only these are alias-tested, so a
        // row refused for another reason cannot poison the destination alias set.
        $eligible = [];
        $unchanged = 0;

        foreach ($pks as $pk) {
            if (!isset($nodes[$pk])) {
                continue;
            }

            if (!$mayEdit) {
                $refused['COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_DENIED'][] = $pk;

                continue;
            }

            if (isset($cycle[$pk])) {
                $refused['COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_CYCLE'][] = $pk;

                continue;
            }

            $checkedOut = (int) $nodes[$pk]['checked_out'];

            if ($checkedOut > 0 && $checkedOut !== $userId) {
                $refused['COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_LOCKED'][] = $pk;

                continue;
            }

            if ((int) $nodes[$pk]['parent_id'] === $targetId) {
                $unchanged++;

                continue;
            }

            $eligible[] = $pk;
        }

        $clashing = $this->aliasClashesUnderParent(targetId: $targetId, candidates: $eligible);
        $moved = array_values(array_diff($eligible, array_keys($clashing)));

        $refused['COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_ALIAS'] = array_keys($clashing);

        if ($moved !== []) {
            $this->applyParentChange(targetId: $targetId, ids: $moved, userId: $userId);

            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_CATEGORY_BATCH_PARENT_MOVED', \count($moved)),
                'message',
            );
        } else {
            $app->enqueueMessage(Text::_('COM_ALFA_CATEGORY_BATCH_PARENT_NONE_MOVED'), 'info');
        }

        if ($unchanged > 0) {
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_CATEGORY_BATCH_PARENT_UNCHANGED', $unchanged),
                'info',
            );
        }

        foreach ($refused as $key => $ids) {
            if ($ids !== []) {
                $app->enqueueMessage(Text::sprintf($key, \count($ids), implode(', ', $ids)), 'warning');
            }
        }

        // Each address refusal names the language, the alias and the category already there.
        foreach ($clashing as $id => $clash) {
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_CATEGORY_BATCH_PARENT_REFUSED_ALIAS_CLASH',
                    MultilingualHelper::recordLabel(id: $id, tableName: '#__alfa_categories', primaryColumnName: 'id_category'),
                    MultilingualHelper::languageLabel(langCode: $clash['language']),
                    htmlspecialchars($clash['alias'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    MultilingualHelper::recordLabel(id: $clash['other'], tableName: '#__alfa_categories', primaryColumnName: 'id_category'),
                ),
                'warning',
            );
        }

        return true;
    }

    /**
     * Snapshot of the whole category table needed by the batch guards, in one query.
     *
     * The raw parent_id chain is deliberate: the storefront descendant helpers are
     * published-only and visibility-filtered, so a cycle through an unpublished or
     * restricted node would slip past them.
     *
     * @return array<int, array{parent_id: int, state: int, checked_out: int}>
     *
     * @since  2.0.0
     */
    private function loadCategoryNodes(): array
    {
        $db = $this->getDatabase();

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'parent_id', 'state', 'checked_out']))
                ->from($db->quoteName('#__alfa_categories')),
        )->loadAssocList('id');

        $nodes = [];

        foreach ($rows ?: [] as $id => $row) {
            $nodes[(int) $id] = [
                'parent_id' => (int) $row['parent_id'],
                'state' => (int) $row['state'],
                'checked_out' => (int) $row['checked_out'],
            ];
        }

        return $nodes;
    }

    /**
     * The selected ids that would create a cycle by moving under $targetId.
     *
     * Every selected id receives the SAME new parent, so the only reachable cycles
     * are "a category under itself" and "a category under one of its own
     * descendants": both appear as the id sitting on the destination's ancestor
     * chain, the destination included.
     *
     * @param int $targetId Destination parent (0 = top level, never cyclic).
     * @param list<int> $candidates Selected category ids.
     * @param array $nodes Snapshot from loadCategoryNodes().
     *
     * @return list<int> The refused ids.
     *
     * @since  2.0.0
     */
    private function cycleFormingIds(int $targetId, array $candidates, array $nodes): array
    {
        if ($targetId <= 0) {
            return [];
        }

        $selected = array_fill_keys($candidates, true);
        $hits = [];
        $seen = [];
        $node = $targetId;

        // $seen bounds the walk, so an already-corrupt tree cannot hang the request.
        while ($node > 0 && !isset($seen[$node])) {
            $seen[$node] = true;

            if (isset($selected[$node])) {
                $hits[] = $node;
            }

            $node = (int) ($nodes[$node]['parent_id'] ?? 0);
        }

        return $hits;
    }

    /**
     * The candidates whose URL address is already taken under the destination parent, in any
     * published content language, each with the clash that refuses it.
     *
     * Alias uniqueness is scoped by parent_id, so a move can put two siblings on one address
     * and leave the router resolving the segment ambiguously. Each category counts with the
     * alias it answers at in a language: its own there, else its default-language alias
     * ({@see MultilingualHelper::getDefaultLanguageTag()}). Addresses are compared as the edit
     * form compares a move ({@see MultilingualHelper::aliasHolders()}): in the column's
     * collation, which reads an accent-only difference as the same address, as the router does.
     * Two selected categories that would share an address refuse the later one. Each refused id
     * comes with the first clash found: the language (lower-snake), the alias, the category there.
     *
     * @param int $targetId Destination parent id.
     * @param list<int> $candidates Ids that passed the earlier guards.
     *
     * @return array<int, array{language: string, alias: string, other: int}> The refused ids.
     *
     * @since  2.0.0
     */
    private function aliasClashesUnderParent(int $targetId, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $db = $this->getDatabase();
        $tables = $db->getTableList() ?: [];
        $defaultTable = $db->replacePrefix('#__alfa_categories_' . MultilingualHelper::getDefaultLanguageTag());
        $hasDefault = \in_array($defaultTable, $tables, true);
        $mineByLanguage = [];
        $clash = [];

        foreach (array_keys(LanguageHelper::getLanguages('lang_code')) as $tag) {
            $code = MultilingualHelper::normaliseTag(tag: (string) $tag);
            $langTable = $db->replacePrefix('#__alfa_categories_' . $code);

            if (!\in_array($langTable, $tables, true)) {
                continue;
            }

            // Each candidate's address in this language: one query per language.
            $query = $db->getQuery(true)
                ->select([$db->quoteName('c.id'), $db->quoteName('l.alias', 'own')])
                ->from($db->quoteName('#__alfa_categories', 'c'))
                ->join(
                    'LEFT',
                    $db->quoteName($langTable, 'l') . ' ON ' . $db->quoteName('l.id_category') . ' = ' . $db->quoteName('c.id'),
                )
                ->whereIn($db->quoteName('c.id'), $candidates, ParameterType::INTEGER);

            if ($hasDefault && $defaultTable !== $langTable) {
                $query->select($db->quoteName('d.alias', 'fallback'))
                    ->join(
                        'LEFT',
                        $db->quoteName($defaultTable, 'd') . ' ON ' . $db->quoteName('d.id_category') . ' = ' . $db->quoteName('c.id'),
                    );
            }

            foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
                $alias = (string) ($row['own'] ?? '');
                $alias = $alias !== '' ? $alias : (string) ($row['fallback'] ?? '');

                if ($alias !== '') {
                    $mineByLanguage[$code][(int) $row['id']] = $alias;
                }
            }

            // A child of the destination that answers at a candidate's address there.
            foreach ($candidates as $id) {
                if (isset($clash[$id]) || !isset($mineByLanguage[$code][$id])) {
                    continue;
                }

                $holders = MultilingualHelper::aliasHolders(
                    tableName:         '#__alfa_categories',
                    primaryColumnName: 'id_category',
                    langCode:          $code,
                    alias:             $mineByLanguage[$code][$id],
                    exceptId:          $id,
                    scopeValues:       ['parent_id' => $targetId],
                );

                if ($holders !== []) {
                    $clash[$id] = ['language' => $code, 'alias' => $mineByLanguage[$code][$id], 'other' => $holders[0]];
                }
            }
        }

        // Selected categories that would share an address with each other: the first one in keeps it.
        foreach ($mineByLanguage as $code => $mine) {
            $kept = [];

            foreach ($candidates as $id) {
                if (isset($clash[$id]) || !isset($mine[$id])) {
                    continue;
                }

                $holders = $kept === [] ? [] : MultilingualHelper::aliasHolders(
                    tableName:         '#__alfa_categories',
                    primaryColumnName: 'id_category',
                    langCode:          $code,
                    alias:             $mine[$id],
                    exceptId:          $id,
                    onlyIds:           $kept,
                );

                if ($holders !== []) {
                    $clash[$id] = ['language' => $code, 'alias' => $mine[$id], 'other' => $holders[0]];

                    continue;
                }

                $kept[] = $id;
            }
        }

        return $clash;
    }

    /**
     * Write the new parent for the moved rows, renumber the destination's display
     * order, and drop the storefront caches.
     *
     * One clean for the whole batch: the persistent keys are language- and
     * visitor-scoped, so CategoryHelper cleans the entire `com_alfa.categories`
     * group whatever id it is handed.
     *
     * The visibility notification is not optional: VisibilityNotifier's contract is
     * that NO write to `#__alfa_categories` may be silent, batch actions expressly
     * included, so a consumer holding a copy of the catalogue is never left with a
     * tree it has no way of knowing changed.
     *
     * @param int $targetId Destination parent id (0 = top level).
     * @param list<int> $ids The categories to move.
     * @param int $userId The acting user, stamped as modified_by.
     *
     *
     * @since  2.0.0
     */
    private function applyParentChange(int $targetId, array $ids, int $userId): void
    {
        $db = $this->getDatabase();
        $now = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_categories'))
            ->set($db->quoteName('parent_id') . ' = :targetId')
            ->set($db->quoteName('modified') . ' = :modified')
            ->set($db->quoteName('modified_by') . ' = :userId')
            ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->bind(':targetId', $targetId, ParameterType::INTEGER)
            ->bind(':modified', $now)
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        // The moved rows keep the `ordering` value they had under their old parent,
        // which can duplicate a value already used by a destination sibling, and
        // both the list and the storefront tree sort on that column alone, so the
        // duplicates would display in whatever order the engine happened to return.
        // Renumbering the destination's siblings 1..n restores a total order.
        $this->getTable()->reorder($db->quoteName('parent_id') . ' = ' . $targetId);

        CategoryHelper::clearCache();

        VisibilityNotifier::changed(categoryIds: $ids);
    }

    /**
     * Check if a category has circular references in its parent chain
     *
     * Walks up the parent chain from the given category to root (0)
     * to detect any circular references or invalid parent relationships.
     *
     * @param int $categoryId Category ID to check
     *
     * @return bool True if circular reference detected, false if valid
     * @since  1.0.0
     */
    protected function hasCircularReference(int $categoryId): bool
    {
        if ($categoryId <= 0) {
            return false;
        }

        $db = $this->getDatabase();
        $currentId = $categoryId;
        $visited = [];
        $maxIterations = 100; // Safety limit
        $iterations = 0;

        while ($currentId > 0 && $iterations < $maxIterations) {
            // Already visited this ID - circular reference detected!
            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $iterations++;

            // Get parent of current category
            $query = $db->getQuery(true)
                ->select($db->quoteName('parent_id'))
                ->from($db->quoteName('#__alfa_categories'))
                ->where($db->quoteName('id') . ' = :currentId')
                ->bind(':currentId', $currentId, ParameterType::INTEGER);

            $parentId = (int) $db->setQuery($query)->loadResult();

            // Reached root - valid chain
            if ($parentId === 0) {
                return false;
            }

            // Self-reference detected
            if ($currentId === $parentId) {
                return true;
            }

            $currentId = $parentId;
        }

        // Hit max iterations - likely circular reference
        return $iterations >= $maxIterations;
    }

    /**
     * Clear cache for affected categories
     *
     * Clears cache when:
     * - New category is created
     * - Category parent_id changes
     * - Category alias changes
     *
     * @param int $categoryId Current category ID
     * @param int|null $oldParentId Old parent ID (if changed)
     * @param int $newParentId New parent ID
     *
     * @since  1.0.0
     */
    protected function clearRelatedCache(int $categoryId, ?int $oldParentId, int $newParentId): void
    {
        // Clear current category and all descendants (recursive)
        CategoryHelper::clearCacheRecursive($categoryId);

        // If parent changed, clear old parent's cache
        if ($oldParentId !== null && $oldParentId !== $newParentId && $oldParentId > 0) {
            CategoryHelper::clearCache($oldParentId);
        }

        // Clear new parent's cache
        if ($newParentId > 0) {
            CategoryHelper::clearCache($newParentId);
        }

        // Clear all tree caches (affects navigation menus)
        // We do this by clearing the entire category group
        //		CategoryHelper::clearCache();
    }

    /**
     * Method to delete one or more records
     *
     * Refused, with nothing written, when the set is the last category of a
     * discount or tax rule (with no category left the rule would apply to every
     * category), or a cart rule excludes one of them ({@see RuleScopeGuard}). Only
     * the requested categories that exist are deleted and stripped.
     *
     * @param array &$pks Record primary keys
     *
     * @return bool True on success
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        $pks = array_map('intval', (array) $pks);

        // Permission first: the guards below query the tree and explain refusals, which a
        // user who may not delete categories must neither trigger nor read.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();

        // GUARDS: children would lose their parent (broken tree) and items
        // their DEFAULT category; move those first, then delete.
        foreach ($pks as $pk) {
            $children = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_categories'))
                    ->where($db->quoteName('parent_id') . ' = ' . $pk),
            )->loadResult();

            if ($children > 0) {
                $deletingChildrenToo = (int) $db->setQuery(
                    $db->getQuery(true)
                        ->select('COUNT(*)')
                        ->from($db->quoteName('#__alfa_categories'))
                        ->where($db->quoteName('parent_id') . ' = ' . $pk)
                        ->whereIn($db->quoteName('id'), $pks),
                )->loadResult();

                if ($deletingChildrenToo < $children) {
                    // A model error, not a queued message: the REST delete answers it
                    // with 409 and the sentence instead of 204 for a row still there.
                    $this->setError(new DeleteRefusedException(Text::sprintf('COM_ALFA_CATEGORY_DELETE_HAS_CHILDREN', $pk)));

                    return false;
                }
            }

            $defaultUse = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_items'))
                    ->where($db->quoteName('id_category_default') . ' = ' . $pk),
            )->loadResult();

            if ($defaultUse > 0) {
                $this->setError(new DeleteRefusedException(Text::sprintf('COM_ALFA_CATEGORY_DELETE_IS_DEFAULT', $pk, $defaultUse)));

                return false;
            }
        }

        // ONE TRANSACTION: the rule-scope judgement, the rows and every pivot that
        // names them. The judgement locks the rule pivots it reads, so no rule editor
        // can empty a scope between it and the delete, and a refusal or a failure
        // leaves every row where it was.
        $affectedItems = [];
        $requested = $pks;

        // Every delete veto, Alfa's and Joomla's, before the transaction and its locks (2.1.4, design 0.2): no plugin code
        // runs under them.
        if (!$this->alfaAskBeforeDelete(pks: (array) $pks)) {
            return false;
        }

        $nested = TransactionState::isOpen(db: $db);
        $db->transactionStart(true);

        try {
            // Only the categories that exist, locked: the rows deleted, judged and
            // stripped below are the same set.
            $pks = RuleScopeGuard::lockExisting(db: $db, axis: RuleScopeGuard::AXIS_CATEGORY, ids: $pks);

            if ($pks !== []) {
                // A discount or tax whose last category is in the set would apply to
                // every category, and a cart rule excluding one of them would reach its
                // products: the delete is refused, naming the rules.
                RuleScopeGuard::assertScopesSurvive(db: $db, axis: RuleScopeGuard::AXIS_CATEGORY, ids: $pks);

                // The last moment these can be read: every one of these items loses a
                // category facet from its search document, and the rules scoped by the
                // category stop reaching it.
                $affectedItems = array_map('intval', (array) $db->setQuery(
                    $db->getQuery(true)
                        ->select('DISTINCT ' . $db->quoteName('item_id'))
                        ->from($db->quoteName('#__alfa_items_categories'))
                        ->whereIn($db->quoteName('category_id'), $pks),
                )->loadColumn());

                $this->alfaDeleteAsked = true;

                if (!parent::delete($pks)) {
                    // Every close of this level discards what was deferred inside it.
                    TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The categories were not deleted.'));

                    return false;
                }

                // Pivots referencing the deleted categories: item links, category
                // visibility, and every module scoped BY category.
                foreach ([
                    '#__alfa_items_categories' => 'category_id',
                    '#__alfa_categories_usergroups' => 'category_id',
                    '#__alfa_categories_users' => 'category_id',
                    '#__alfa_discount_categories' => 'category_id',
                    '#__alfa_payment_categories' => 'category_id',
                    '#__alfa_shipment_categories' => 'category_id',
                    '#__alfa_tax_categories' => 'category_id',
                    '#__alfa_item_field_categories' => 'category_id',
                ] as $pivotTable => $pivotColumn) {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->quoteName($pivotTable))
                            ->whereIn($db->quoteName($pivotColumn), $pks),
                    )->execute();
                }
            }

            TransactionState::commitLevel(db: $db);
        } catch (ScopeDeleteRefusedException $refused) {
            // A refusal is answered only while a caller's transaction still stands.
            if (TransactionState::rollBackLevel(db: $db, failure: $refused) && $nested) {
                throw $refused;
            }

            $this->setError($refused);

            return false;
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and in
            // the driver's count.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // None of the requested categories exists: core answers that itself.
        if ($pks === []) {
            $this->alfaDeleteAsked = true;

            return parent::delete($requested);
        }

        // Clear cache for all deleted categories (includes children)
        foreach ($pks as $pk) {
            CategoryHelper::clearCacheRecursive((int) $pk);
        }

        if ($affectedItems !== []) {
            (new ProjectionManager())->syncItems($affectedItems);

            // PRICE INDEX: the items that lost a category lost the rules scoped by
            // it; every such rule kept another category, so nothing else moved.
            // Non-fatal: a failure logs a warning but must not undo the delete.
            try {
                (new PriceIndexSyncService())->syncItems($affectedItems);
            } catch (Throwable $priceIndexException) {
                Log::add(
                    '[CategoryModel::delete] Price index re-sync failed: ' . $priceIndexException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        // MULTILINGUAL: remove the per-language rows for the deleted categories.
        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_category',
            tableName:         '#__alfa_categories',
        );

        // Remove the categories' media (rows; files when media_full_deletion is on).
        MediaHelper::deleteMediaForItems($pks, 'category');

        // The rows can no longer be re-read, so the signal says REMOVED: a
        // listener deletes its materialised copies instead of re-projecting.
        EntityChangeNotifier::changed(entity: 'category', ids: $pks, removed: true);

        return true;
    }

    /**
     * Method to change the published state of one or more records
     *
     * The category's state is copied into every directly assigned item's stored
     * document, and the entity event hands item fan-out to ProjectionManager by
     * contract, so those items are re-synced here, as save() already does. Cost: one
     * pivot read for the whole selection, then one syncItems() pass over its items.
     *
     * @param array &$pks A list of the primary keys to change
     * @param int $value The value of the published state
     *
     * @return bool True on success
     * @since  1.0.0
     */
    public function publish(&$pks, $value = 1)
    {
        // THE IDS ARE TAKEN BEFORE THE CALL. AdminModel::publish() prunes every row
        // already at the target state and can return true with the list emptied,
        // which would re-sync nothing on exactly the retry that needs it.
        $targets = array_map('intval', (array) $pks);

        $result = parent::publish($pks, $value);

        if ($result) {
            $this->resyncAssignedItems(...$targets);

            // Clear cache for published/unpublished categories
            // State changes affect visibility (getCategoryPath filters by state = 1)
            foreach ($pks as $pk) {
                CategoryHelper::clearCacheRecursive((int) $pk);
            }

            // A state change decides whether the category's entity document may exist
            // at all; the listener re-reads the rows and draws that conclusion itself.
            EntityChangeNotifier::changed(entity: 'category', ids: (array) $pks);
        }

        return $result;
    }

    /**
     * Persist a new display order, then drop the cached navigation tree.
     *
     * `CategoryHelper::buildCategoryTree()` orders by `c.ordering` and stores the
     * result in a PERSISTENT cache with a one-year lifetime, so a reorder is
     * invisible on the storefront until that expires. Core's own `cleanCache()`
     * does not help: it cleans group `com_alfa`, while the tree lives in
     * `com_alfa.categories`.
     *
     * @param array $pks Ordered primary keys.
     * @param array|null $order Ordering values.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function saveorder($pks = [], $order = null)
    {
        $result = parent::saveorder($pks, $order);

        if ($result) {
            CategoryHelper::clearCache();
        }

        return $result;
    }

    /**
     * Prepare and sanitize the table prior to saving.
     *
     * @param Table $table Table Object
     *
     *
     * @since  1.0.0
     */
    protected function prepareTable($table): void
    {
        $table->modified = Factory::getDate()->toSql();

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }
    }
}
