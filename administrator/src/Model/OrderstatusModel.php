<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

/**
 * Admin model for a single order-status row.
 *
 * Holds the form, the multilingual sync (via MultilingualHelper) and
 * the role-flag invariants on `#__alfa_orders_statuses`:
 *
 *   • is_initial   — singleton: exactly one holder, kept that way by
 *                    the post-save enforcer.
 *   • is_cancelled — multi-row family (Returned, Refunded, Cancelled).
 *   • is_completed — multi-row family (Delivered, Picked up, Paid).
 *
 * Guards read state through OrderStatusHelper::getAll() (request-cached),
 * so checks add one DB roundtrip per request, not per call.
 *
 *   canSaveStatus()      → Save would not (a) put two roles on one row
 *                           or (b) drop the last published holder of a
 *                           role.
 *   removableStatuses()  → Bulk delete / unpublish: returns the subset
 *                           of submitted PKs that's safe to remove
 *                           (admin sees a warning per skipped row, the
 *                           rest still process).
 *   clearSingletonStatusOnOtherRows() → post-save side effect that
 *                           keeps is_initial at exactly one holder.
 *
 * @since   1.0.1
 */
class OrderstatusModel extends AdminModel
{
    /**
     * Content-history alias used by Joomla's UCM layer.
     *
     * @var string
     */
    public $typeAlias = 'com_alfa.orderstatus';

    /**
     * Form id — matches `forms/orderstatus.xml`.
     *
     * @var string
     */
    protected $formName = 'orderstatus';

    /**
     * Role flags. A row may hold at most ONE of these (within-row
     * exclusion); each one is mandatory ≥1 published holder once
     * first nominated.
     *
     * @var string[]
     */
    private const EXCLUSIVE_FLAGS = ['is_initial', 'is_cancelled', 'is_completed'];

    /**
     * Subset of EXCLUSIVE_FLAGS that are cross-row singletons.
     * clearSingletonOnOtherRows() clears these on other rows when this
     * row claims them.
     *
     * @var string[]
     */
    private const SINGLETON_FLAGS = ['is_initial'];

    /**
     * Role flag → language key for the human-readable role name,
     * sprintf'd into warning messages so admins see "Cancellation"
     * rather than "is_cancelled".
     *
     * @var array<string, string>
     */
    private const ROLE_LANG_KEYS = [
        'is_initial' => 'COM_ALFA_ORDERSTATUS_ROLE_INITIAL',
        'is_cancelled' => 'COM_ALFA_ORDERSTATUS_ROLE_CANCELLED',
        'is_completed' => 'COM_ALFA_ORDERSTATUS_ROLE_COMPLETED',
    ];

    /**
     * Load the orderstatus edit form.
     *
     * @param array $data Pre-populated form data, or empty array.
     * @param bool $loadData Hydrate from the bound item / user-state.
     *
     * @return Form|false The bound form on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
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
     * Hydrate the form with session-stashed input or the loaded item row.
     *
     * @return array|object Data the form will bind to.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_alfa.edit.orderstatus.data', []);

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
        }

        return $data;
    }

    /**
     * Load the row and hydrate `admin_recipient_ids` from the
     * `#__alfa_orderstatus_recipients` join table so the multi-select
     * field renders its current selection.
     *
     * @param int|null $pk Row PK or null to use the state.
     *
     * @return object|false Row on success, false on failure.
     *
     * @since   1.0.5
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->id)) {
            $item->admin_recipient_ids = $this->loadAdminRecipientIds(statusId: (int) $item->id);
        }

        return $item;
    }

    /**
     * Pull the form-submitted admin recipient ids into a clean int list.
     *
     * The `sql` multi-select can submit the value as an array, a single
     * scalar, or empty. Normalises to int[] dropping zero / negative.
     *
     * @param array $data Submitted form payload.
     *
     * @return int[] Sanitised user ids.
     *
     * @since   1.0.5
     */
    private static function extractAdminRecipientIds(array $data): array
    {
        $raw = $data['admin_recipient_ids'] ?? [];

        if (!is_array($raw)) {
            $raw = ($raw === '' || $raw === null) ? [] : (array) $raw;
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Read the admin recipient user ids for a status from the join table.
     *
     * @param int $statusId Status PK.
     *
     * @return int[] User ids in stored order.
     *
     * @since   1.0.5
     */
    private function loadAdminRecipientIds(int $statusId): array
    {
        if ($statusId <= 0) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id_user'))
            ->from($db->quoteName('#__alfa_orderstatus_recipients'))
            ->where($db->quoteName('id_orderstatus') . ' = ' . (int) $statusId);

        $db->setQuery(query: $query);

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * Replace the admin recipient set for a status with $userIds.
     *
     * Delete-then-insert pattern — simpler than diffing for a small
     * recipient list. PRIMARY KEY (id_orderstatus, id_user) prevents
     * duplicates if $userIds itself contains repeats (extractAdminRecipientIds
     * already de-duplicates, this is just belt-and-suspenders).
     *
     * @param int $statusId Status PK.
     * @param int[] $userIds Sanitised user ids to write.
     *
     *
     * @since   1.0.5
     */
    private function saveAdminRecipients(int $statusId, array $userIds): void
    {
        if ($statusId <= 0) {
            return;
        }

        $db = $this->getDatabase();

        $delete = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_orderstatus_recipients'))
            ->where($db->quoteName('id_orderstatus') . ' = ' . (int) $statusId);

        $db->setQuery(query: $delete);
        $db->execute();

        if (empty($userIds)) {
            return;
        }

        $columns = [$db->quoteName('id_orderstatus'), $db->quoteName('id_user')];
        $values = [];

        foreach ($userIds as $userId) {
            $values[] = (int) $statusId . ', ' . (int) $userId;
        }

        $insert = $db->getQuery(true)
            ->insert($db->quoteName('#__alfa_orderstatus_recipients'))
            ->columns($columns)
            ->values($values);

        $db->setQuery(query: $insert);
        $db->execute();
    }

    /**
     * Bundle the EmailPositionsField's per-position POST keys into one
     * JSON value per language under the key MultilingualHelper expects.
     *
     * The EmailPositionsField in `forms/orderstatus.xml` submits one
     * key per language × position:
     *   jform[email_positions_customer_en_gb_subject]
     *   jform[email_positions_customer_en_gb_header]
     *   jform[email_positions_customer_en_gb_body]
     *   …
     *
     * MultilingualHelper::saveMultilingualData() recognises per-language
     * keys by the `_<langCode>` suffix (str_ends_with), so we collect
     * the per-position values into a single JSON payload and write it
     * back to `$data` under `<field>_<langCode>`. The helper then
     * persists the JSON string to the per-language column.
     *
     * Per-position keys are removed from `$data` after assembly so
     * downstream code doesn't see two parallel representations of the
     * same data.
     *
     * @param array $data Form payload (modified by reference).
     *
     *
     * @since   1.0.4
     */
    private static function bundleEmailPositionsIntoLanguageKeys(array &$data): void
    {
        $fields = ['email_positions_customer', 'email_positions_admin'];
        $languages = LanguageHelper::getLanguages('lang_code') ?: [];

        foreach ($fields as $field) {
            // Section show/hide flags are NOT per language — they post once as
            // <field>_showstruct_<slug>. Harvest them up front and fan the
            // result into every language's JSON as _show_<slug>, so the flag
            // is present whichever language the email later renders in. (The
            // per-language harvest below can't match these: its prefix carries
            // a real language suffix, never "showstruct".)
            $showFlags = [];
            $showPrefix = $field . '_showstruct_';

            foreach (array_keys($data) as $key) {
                if (!str_starts_with($key, $showPrefix)) {
                    continue;
                }

                $slug = substr($key, strlen($showPrefix));

                if ($slug !== '') {
                    $showFlags['_show_' . $slug] = ((int) $data[$key]) === 1 ? '1' : '0';
                }

                unset($data[$key]);
            }

            foreach ($languages as $langCode => $language) {
                $suffix = strtolower(str_replace('-', '_', (string) $langCode));
                $prefix = $field . '_' . $suffix . '_';
                $perLanguage = [];
                $sawAny = false;

                // Harvest every posted key under this field+language prefix
                // rather than a fixed position list. The remainder after the
                // prefix is the position name — works for any layout's
                // positions AND for stale slots EmailPositionsField re-rendered
                // for migration. (Snapshot the keys first: we unset as we go.)
                foreach (array_keys($data) as $key) {
                    if (!str_starts_with($key, $prefix)) {
                        continue;
                    }

                    $position = substr($key, strlen($prefix));

                    if ($position === '') {
                        continue;
                    }

                    $perLanguage[$position] = (string) $data[$key];
                    $sawAny = true;
                    unset($data[$key]);
                }

                // Merge the (non-per-language) section flags into this
                // language's payload so render-time visibility resolves
                // regardless of the email's language.
                if (!empty($showFlags)) {
                    $perLanguage += $showFlags;
                    $sawAny = true;
                }

                if (!$sawAny) {
                    continue;
                }

                $data[$field . '_' . $suffix] = json_encode($perLanguage, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    /**
     * Save a status row.
     *
     * Guards run before `parent::save()` so a rejected row never
     * touches the DB. On success: sync the per-language tables, enforce
     * the singleton, invalidate the helper cache.
     *
     * @param array $data Submitted form payload.
     *
     * @return bool True on success, false when a guard or the parent
     *              rejects.
     *
     * @since   1.0.1
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // The 'raw' filter keeps the per-language flat keys (name_en_gb,
        // name_el_gr …) that the default 'array' filter strips before
        // MultilingualHelper can pick them up.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = array_merge($data, $rawData);

        $pk = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;
        $prev = !$isNew ? $this->getItem($pk) : null;

        // The admin recipients live in the `#__alfa_orderstatus_recipients`
        // join table, not on the status row. Strip the field from $data
        // so parent::save() doesn't try to bind it to a non-existent
        // column, then sync the join table after the row is persisted.
        $adminRecipientIds = self::extractAdminRecipientIds(data: $data);
        unset($data['admin_recipient_ids']);

        if (!$this->canSaveStatus(data: $data, prev: $prev)) {
            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // EmailPositionsField submits one flat key per
        // language × position (`<field>_<langCode>_<position>`). Bundle
        // them into one JSON value per language under the `<field>_<langCode>`
        // key MultilingualHelper expects, so the saveMultilingualData
        // call below writes the JSON to the per-language column.
        self::bundleEmailPositionsIntoLanguageKeys($data);

        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_orderstatus',
            tableName:         '#__alfa_orders_statuses',
            data:              $data,
            aliasFields:       [],
        );

        $this->clearSingletonStatusOnOtherRows(currentId: $currentId, data: $data);
        $this->saveAdminRecipients(statusId: $currentId, userIds: $adminRecipientIds);
        OrderStatusHelper::clearCache();

        return true;
    }

    /**
     * Change the published state of one or more rows.
     *
     * Re-publishing (state = 1) always passes — it adds a published
     * holder. Any other target state goes through canRemoveStatuses()
     * with requireState = 1 so a transition that would leave a role
     * without a published holder is refused.
     *
     * @param array $pks Row primary keys (by reference — Joomla).
     * @param int $value 1 published, 0 unpublished, 2 archived,
     *                   -2 trashed.
     *
     * @return bool True on success, false when the guard or the
     *              parent rejects.
     *
     * @since   1.0.1
     */
    public function publish(&$pks, $value = 1)
    {
        if ((int) $value !== 1) {
            $pks = $this->removableStatuses(
                pks:          $pks,
                messageKey:   'COM_ALFA_ORDERSTATUS_PUBLISH_LAST_ROLE_HOLDER',
                requireState: 1,
            );

            if (empty($pks)) {
                return false;
            }
        }

        $result = parent::publish($pks, $value);

        if ($result) {
            OrderStatusHelper::clearCache();
        }

        return $result;
    }

    /**
     * Delete one or more rows.
     *
     * Blocks deletions that would orphan any role (≥1 holder must
     * survive, regardless of state — a deleted row is gone) before
     * delegating, then drops the matching per-language rows so the
     * language tables don't keep orphans.
     *
     * @param array $pks Row primary keys (by reference — Joomla).
     *
     * @return bool True on success, false when the guard or the
     *              parent rejects.
     *
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        $pks = $this->removableStatuses(
            pks:        $pks,
            messageKey: 'COM_ALFA_ORDERSTATUS_DELETE_LAST_ROLE_HOLDER',
        );

        if (empty($pks)) {
            return false;
        }

        $result = parent::delete($pks);

        if ($result && !empty($pks)) {
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_orderstatus',
                tableName:         '#__alfa_orders_statuses',
            );

            $this->dropAdminRecipientsFor(statusIds: $pks);
            OrderStatusHelper::clearCache();
        }

        return $result;
    }

    /**
     * Drop every `#__alfa_orderstatus_recipients` row pointing at any of
     * the deleted status ids.
     *
     * No FK to `#__alfa_orders_statuses` (MyISAM) — Joomla won't cascade
     * the rows away for us. Called by delete() once the parent removal
     * succeeded.
     *
     * @param array $statusIds Status PKs that were deleted.
     *
     *
     * @since   1.0.5
     */
    private function dropAdminRecipientsFor(array $statusIds): void
    {
        $ids = array_values(array_filter(
            array_map('intval', $statusIds),
            static fn (int $id): bool => $id > 0,
        ));

        if (empty($ids)) {
            return;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_orderstatus_recipients'))
            ->whereIn($db->quoteName('id_orderstatus'), $ids);

        $db->setQuery(query: $query);
        $db->execute();
    }

    /**
     * Stamp modified / modified_by before the row is persisted.
     *
     * @param \Joomla\CMS\Table\Table $table Bound table instance.
     *
     * @return void
     *
     * @since   1.0.1
     */
    protected function prepareTable($table)
    {
        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $this->getCurrentUser()->id;

        parent::prepareTable($table);
    }

    // ====================================================================
    //  Invariant guards — read state via OrderStatusHelper::getAll().
    // ====================================================================

    /**
     * Can this save go through? Combines the two save-time invariants:
     *
     *   1. At most one role flag per row (within-row exclusion).
     *   2. The save doesn't drop the last published holder of a role
     *      — checked only when $prev is set (existing rows). Catches
     *      both un-ticking the flag and unpublishing the row via the
     *      Status dropdown.
     *
     * @param array $data Submitted form payload.
     * @param object|null $prev Row state before save, or null on insert.
     *
     * @return bool True when the save is legal; false (with a queued
     *              warning) when an invariant would be violated.
     *
     * @since   1.0.1
     */
    protected function canSaveStatus(array $data, ?object $prev): bool
    {
        // Rule 1 — within-row exclusion.
        $rolesSet = array_filter(
            self::EXCLUSIVE_FLAGS,
            static fn (string $flag): bool => (int) ($data[$flag] ?? 0) === 1,
        );

        if (count($rolesSet) > 1) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_ORDERSTATUS_SAVE_MULTIPLE_ROLES',
                    implode(', ', $rolesSet),
                ),
                'warning',
            );

            return false;
        }

        // Rule 2 — only existing rows can orphan a role.
        if ($prev === null) {
            return true;
        }

        $id = (int) ($prev->id ?? 0);

        if ($id <= 0) {
            return true;
        }

        $wasPublished = (int) ($prev->state ?? 0) === 1;
        $willBePublished = (int) ($data['state'] ?? $prev->state ?? 0) === 1;

        foreach (self::EXCLUSIVE_FLAGS as $flag) {
            $wasHolder = (int) ($prev->{$flag} ?? 0) === 1;
            $willHold = (int) ($data[$flag] ?? $prev->{$flag} ?? 0) === 1;

            // Was this row a published holder, and will it stop being one?
            if (!($wasPublished && $wasHolder)) {
                continue;
            }
            if ($willBePublished && $willHold) {
                continue;
            }

            if ($this->hasAnotherPublishedHolder(flag: $flag, excludeId: $id)) {
                continue;
            }

            $messageKey = $willHold
                ? 'COM_ALFA_ORDERSTATUS_SAVE_LAST_PUBLISHED_HOLDER'
                : 'COM_ALFA_ORDERSTATUS_SAVE_LAST_ROLE_HOLDER';

            Factory::getApplication()->enqueueMessage(
                Text::sprintf($messageKey, Text::_(self::ROLE_LANG_KEYS[$flag])),
                'warning',
            );

            return false;
        }

        return true;
    }

    /**
     * Return the subset of $pks that can be removed without orphaning
     * any role.
     *
     * Shared by delete() and publish(): both remove rows from the
     * "active" set, the only difference is what "active" means:
     *
     *   • delete:  exists at all          → $requireState = null
     *   • publish: state = 1 (published)  → $requireState = 1
     *
     * Walks $pks in admin-selection order with a running holder count
     * per flag. If removing a row would drop a role's count to zero,
     * the row is excluded from the returned set and an admin warning
     * is queued naming which role saved it. The remaining rows get
     * passed on to `parent::delete()` / `parent::publish()`.
     *
     * @param array $pks Row PKs submitted by the toolbar
     *                   or controller.
     * @param string $messageKey Language key for the per-skip
     *                           warning (sprintf'd with the role
     *                           name).
     * @param int|null $requireState When set, only rows in that state
     *                               count as holders.
     *
     * @return int[] PKs safe to remove, in admin-selection order.
     *               Empty when every submitted PK would orphan a role.
     *
     * @since   1.0.1
     */
    protected function removableStatuses(array $pks, string $messageKey, ?int $requireState = null): array
    {
        $candidates = array_values(array_filter(
            array_map('intval', $pks),
            static fn (int $id): bool => $id > 0,
        ));

        if (empty($candidates)) {
            return [];
        }

        // Snapshot of remaining holders per flag (filtered by state).
        // Decremented each time we approve a removal so later
        // candidates see the post-removal count.
        $remainingHolders = [];

        foreach (self::EXCLUSIVE_FLAGS as $flag) {
            $remainingHolders[$flag] = 0;

            foreach (OrderStatusHelper::getAll() as $row) {
                if ((int) ($row->{$flag} ?? 0) !== 1) {
                    continue;
                }
                if ($requireState !== null && (int) ($row->state ?? 0) !== $requireState) {
                    continue;
                }
                $remainingHolders[$flag]++;
            }
        }

        $approved = [];

        foreach ($candidates as $pk) {
            $row = OrderStatusHelper::getById($pk);

            if ($row === null) {
                // Phantom PK — let the parent decide how to react.
                $approved[] = $pk;
                continue;
            }

            $orphanFlag = null;

            foreach (self::EXCLUSIVE_FLAGS as $flag) {
                if ((int) ($row->{$flag} ?? 0) !== 1) {
                    continue;
                }
                if ($requireState !== null && (int) ($row->state ?? 0) !== $requireState) {
                    continue;
                }

                if ($remainingHolders[$flag] - 1 <= 0) {
                    $orphanFlag = $flag;
                    break;
                }
            }

            if ($orphanFlag !== null) {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf($messageKey, Text::_(self::ROLE_LANG_KEYS[$orphanFlag])),
                    'warning',
                );
                continue;
            }

            // Approve: decrement the running counts for every flag
            // this row contributed to.
            foreach (self::EXCLUSIVE_FLAGS as $flag) {
                if ((int) ($row->{$flag} ?? 0) !== 1) {
                    continue;
                }
                if ($requireState !== null && (int) ($row->state ?? 0) !== $requireState) {
                    continue;
                }
                $remainingHolders[$flag]--;
            }

            $approved[] = $pk;
        }

        return $approved;
    }

    /**
     * Clear singleton role flags on every status OTHER than $currentId.
     *
     * Called after `parent::save()` whenever the saved row claims a
     * singleton flag, so the "= 1" invariant holds. Multi-row flags
     * are skipped — multiple statuses may carry them.
     *
     * @param int $currentId PK of the status just saved.
     * @param array $data Submitted form payload.
     *
     *
     * @since   1.0.1
     */
    protected function clearSingletonStatusOnOtherRows(int $currentId, array $data): void
    {
        if ($currentId <= 0) {
            return;
        }

        $db = $this->getDatabase();

        foreach (self::SINGLETON_FLAGS as $flag) {
            if ((int) ($data[$flag] ?? 0) !== 1) {
                continue;
            }

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_orders_statuses'))
                ->set($db->quoteName($flag) . ' = 0')
                ->where($db->quoteName('id') . ' <> ' . (int) $currentId)
                ->where($db->quoteName($flag) . ' = 1');

            $db->setQuery(query: $query);
            $db->execute();
        }
    }

    /**
     * Does any row OTHER than $excludeId carry $flag = 1 AND state = 1?
     *
     * Helper for canSaveStatus's "is this the last published holder?"
     * check. Reads the cached row set, returns early on first hit.
     *
     * @param string $flag Role column name.
     * @param int $excludeId The PK to skip (the row being saved).
     *
     * @return bool True when a sibling published holder exists.
     *
     * @since   1.0.1
     */
    protected function hasAnotherPublishedHolder(string $flag, int $excludeId): bool
    {
        foreach (OrderStatusHelper::getAll() as $row) {
            if ((int) $row->id === $excludeId) {
                continue;
            }
            if ((int) ($row->{$flag} ?? 0) !== 1) {
                continue;
            }
            if ((int) ($row->state ?? 0) !== 1) {
                continue;
            }

            return true;
        }

        return false;
    }
}
