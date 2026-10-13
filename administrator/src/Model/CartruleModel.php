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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\CartRules\CartRulesEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * Cart rule model: the admin form behind ONE cart rule (coupon or
 * automatic). The rule row lives on `#__alfa_cart_rules`; the condition
 * groups persist RELATIONALLY (groups -> conditions -> values, never JSON) and
 * travel through the form as the `groups` subform structure:
 *
 *   groups: [{min_quantity, conditions: [{type, exclude, values: []}]}]
 *
 * @since  1.3.0
 */
class CartruleModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'cartrule';

    /**
     * @var string The prefix to use with controller messages.
     * @since  1.3.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Type alias.
     * @since  1.3.0
     */
    public $typeAlias = 'com_alfa.cartrule';

    /**
     * @var object|null Item cache.
     * @since  1.3.0
     */
    protected $item = null;

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data.
     *
     * @return \Joomla\CMS\Form\Form|bool
     *
     * @since  1.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.cartrule', 'cartrule', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        return $form ?: false;
    }

    /**
     * Save the rule row, its targeting and its relational condition tree.
     *
     * CONTRACT COVERAGE NOTE (decided at migration): the
     * onAlfaAdminValidate/BeforeSave/AfterSave bracket fires inside
     * parent::save(), i.e. AfterSave observers see the rule ROW saved, but
     * the targeting pivots and the condition tree are synced right after.
     *
     * @param array $data The form data.
     *
     * @return bool
     *
     * @since  1.3.0
     */
    public function save($data)
    {
        // Before anything is written: a minimum no cart can hold would make the
        // rule one that never applies.
        if (!$this->validateMinimumAmount($data['min_amount'] ?? 0)) {
            return false;
        }

        // Before anything is written: a group with no usable condition can
        // neither be stored (it matches every line) nor quietly dropped
        // (a rule with zero groups is UNCONDITIONAL, wider still). Only the
        // admin can resolve it, so refuse the save and say which group.
        if (!$this->validateConditionGroups((array) ($data['groups'] ?? []))) {
            return false;
        }

        // The form filter drops the per-language keys (name_en_gb, ...) because
        // they are not declared fields; this puts them back, cleaned like their
        // base field.
        $rawData = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        if (!parent::save($data)) {
            return false;
        }

        $ruleId = (int) ($data['id'] ?? 0) ?: (int) $this->getState($this->getName() . '.id');

        // Translatable name: no main-table copy, so this IS the save.
        MultilingualHelper::saveMultilingualData(
            currentId:         $ruleId,
            primaryColumnName: 'id_cart_rule',
            tableName:         '#__alfa_cart_rules',
            data:              $data,
            aliasFields:       [],
        );

        AlfaHelper::setAssocsToDb($ruleId, $data['allowedUsers'] ?? [], '#__alfa_cart_rules_users', 'id_cart_rule', 'user_id');
        AlfaHelper::setAssocsToDb($ruleId, $data['allowedUserGroups'] ?? [], '#__alfa_cart_rules_usergroups', 'id_cart_rule', 'usergroup_id');

        $this->syncConditionTree($ruleId, (array) ($data['groups'] ?? []));

        return true;
    }

    /**
     * Reject a minimum amount that no cart can reach.
     *
     * A rule's minimum is compared in the cart's currency, in its minor units.
     * In the shop's currency with the most decimals those units cannot hold
     * every amount the column can (at three decimals, nothing from
     * 9,223,372,036,854,775.808 on), and a cart in that currency could never
     * reach such a minimum. The bound is that currency's, so a cart in any
     * currency of the shop can reach whatever is saved.
     *
     * @param mixed $minimum The submitted minimum; zero or less is no minimum.
     *
     * @return bool True when a cart can reach the minimum.
     *
     * @since  1.14.0
     */
    protected function validateMinimumAmount(mixed $minimum): bool
    {
        if (!is_numeric($minimum) || (float) $minimum <= 0) {
            return true;
        }

        $db = $this->getDatabase();
        $widest = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_currencies'))
                ->order([$db->quoteName('decimal_place') . ' DESC', $db->quoteName('id') . ' ASC']),
            0,
            1,
        )->loadResult();
        $currency = Currency::loadById($widest);

        if (CartRulesEngine::minimumAmount(stated: $minimum, currency: $currency) !== null) {
            return true;
        }

        $scale = $currency->getDecimalPlaces();
        $digits = (string) PHP_INT_MAX;
        $most = $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        $this->setError(Text::sprintf('COM_ALFA_CARTRULE_ERR_MIN_AMOUNT_UNHOLDABLE', $most, $currency->getName(), $scale));

        return false;
    }

    /**
     * Reject a submitted tree carrying a group that resolves to no condition.
     *
     * A condition row counts only when it actually selected values: an empty
     * row matches nothing and is skipped downstream, so a group made only of
     * empty rows is indistinguishable from a group with none at all.
     *
     * @param array $groups The `groups` subform rows as submitted.
     *
     * @return bool True when every group carries at least one usable condition.
     *
     * @since  1.9.0
     */
    protected function validateConditionGroups(array $groups): bool
    {
        $position = 0;

        foreach ($groups as $group) {
            $group = (array) $group;
            $position++;
            $usable = false;

            foreach ((array) ($group['conditions'] ?? []) as $condition) {
                $condition = (array) $condition;
                $values = array_filter(array_map('intval', (array) ($condition['values'] ?? [])));

                if ($values !== []) {
                    $usable = true;
                    break;
                }
            }

            if (!$usable) {
                $this->setError(Text::sprintf('COM_ALFA_CARTRULE_ERR_GROUP_EMPTY', $position));

                return false;
            }
        }

        return true;
    }

    /**
     * Replace the rule's condition tree with the submitted subform structure
     * (delete + reinsert: the tree is small and has no external references).
     *
     * @param int $ruleId The rule PK.
     * @param array $groups The `groups` subform rows.
     *
     *
     * @since  1.3.0
     */
    protected function syncConditionTree(int $ruleId, array $groups): void
    {
        $db = $this->getDatabase();

        // The whole wipe + reinsert must be atomic: a failure mid-reinsert would
        // leave the rule live with an empty condition tree, i.e. applying its
        // discount to every cart until an admin re-saves it.
        $db->transactionStart(true);

        try {
            // Wipe the existing tree bottom-up.
            $db->setQuery(
                'DELETE v FROM ' . $db->quoteName('#__alfa_cart_rule_condition_values', 'v')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_rule_conditions', 'c') . ' ON c.id = v.id_condition'
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_rule_groups', 'g') . ' ON g.id = c.id_group'
                . ' WHERE g.id_cart_rule = ' . $ruleId,
            );
            $db->execute();

            $db->setQuery(
                'DELETE c FROM ' . $db->quoteName('#__alfa_cart_rule_conditions', 'c')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_rule_groups', 'g') . ' ON g.id = c.id_group'
                . ' WHERE g.id_cart_rule = ' . $ruleId,
            );
            $db->execute();

            $db->setQuery('DELETE FROM ' . $db->quoteName('#__alfa_cart_rule_groups') . ' WHERE id_cart_rule = ' . $ruleId);
            $db->execute();

            foreach ($groups as $group) {
                $group = (array) $group;

                // Resolve the surviving condition rows BEFORE touching the
                // tables. A group that keeps none must never be persisted:
                // lineMatchesGroup() returns true on an empty condition list,
                // so a condition-less group silently matches every cart.
                $conditions = [];

                foreach ((array) ($group['conditions'] ?? []) as $condition) {
                    $condition = (array) $condition;
                    $type = (string) ($condition['type'] ?? 'categories');
                    // Each value once: the value table keys on (condition, value).
                    $values = array_values(array_unique(array_filter(array_map(
                        'intval',
                        (array) ($condition['values'] ?? []),
                    ))));

                    // A condition without values matches nothing; skip it
                    // instead of persisting a dead row.
                    if ($values === []) {
                        continue;
                    }

                    $conditions[] = [
                        'type' => $type,
                        'exclude' => (int) ($condition['exclude'] ?? 0) === 1 ? 1 : 0,
                        'values' => $values,
                    ];
                }

                if ($conditions === []) {
                    // Not surfaced to the admin: an empty row is a normal way
                    // to abandon a group. Logged because rules migrated from
                    // before the subform fix can surface here in bulk.
                    Log::add(
                        'Cart rule group discarded: no resolved conditions (rule ' . $ruleId . ').',
                        Log::WARNING,
                        'com_alfa.cartrules',
                    );

                    continue;
                }

                $groupRow = (object) [
                    'id_cart_rule' => $ruleId,
                    'min_quantity' => max(1, (int) ($group['min_quantity'] ?? 1)),
                ];
                $db->insertObject('#__alfa_cart_rule_groups', $groupRow, 'id');

                foreach ($conditions as $condition) {
                    $conditionRow = (object) [
                        'id_group' => (int) $groupRow->id,
                        'type' => $condition['type'],
                        'exclude' => $condition['exclude'],
                    ];
                    $db->insertObject('#__alfa_cart_rule_conditions', $conditionRow, 'id');

                    foreach ($condition['values'] as $value) {
                        $valueRow = (object) ['id_condition' => (int) $conditionRow->id, 'id_value' => $value];
                        $db->insertObject('#__alfa_cart_rule_condition_values', $valueRow);
                    }
                }
            }

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }
    }

    /**
     * Get a single rule with its targeting and condition tree shaped for the
     * `groups` subform.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.3.0
     */
    public function getItem($pk = null)
    {
        if (!$item = parent::getItem($pk)) {
            return $item;
        }

        $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_cart_rules_users', 'id_cart_rule', 'user_id');
        $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_cart_rules_usergroups', 'id_cart_rule', 'usergroup_id');
        $item->groups = $this->loadConditionTree((int) $item->id);

        return $item;
    }

    /**
     * The rule's condition tree, shaped for the subform.
     *
     * @param int $ruleId The rule PK.
     *
     * @return array groups rows.
     *
     * @since  1.3.0
     */
    protected function loadConditionTree(int $ruleId): array
    {
        if ($ruleId <= 0) {
            return [];
        }

        $db = $this->getDatabase();

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_cart_rule_groups'))
                ->where($db->quoteName('id_cart_rule') . ' = ' . $ruleId)
                ->order($db->quoteName('id') . ' ASC'),
        );
        $groupRows = $db->loadObjectList('id') ?: [];

        if ($groupRows === []) {
            return [];
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_cart_rule_conditions'))
                ->whereIn($db->quoteName('id_group'), array_keys($groupRows))
                ->order($db->quoteName('id') . ' ASC'),
        );
        $conditionRows = $db->loadObjectList('id') ?: [];

        $valuesByCondition = [];

        if ($conditionRows !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_cart_rule_condition_values'))
                    ->whereIn($db->quoteName('id_condition'), array_keys($conditionRows)),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                $valuesByCondition[(int) $row->id_condition][] = (int) $row->id_value;
            }
        }

        $groups = [];

        foreach ($groupRows as $groupId => $groupRow) {
            $conditions = [];

            foreach ($conditionRows as $conditionId => $conditionRow) {
                if ((int) $conditionRow->id_group !== (int) $groupId) {
                    continue;
                }

                $conditions[] = [
                    'type' => (string) $conditionRow->type,
                    'exclude' => (int) $conditionRow->exclude,
                    'values' => $valuesByCondition[(int) $conditionId] ?? [],
                ];
            }

            $groups[] = [
                'min_quantity' => (int) $groupRow->min_quantity,
                'conditions' => $conditions,
            ];
        }

        return $groups;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.3.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.cartrule.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
        }

        return $data;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param \Joomla\CMS\Table\Table $table Table Object.
     *
     * @return void
     *
     * @since  1.3.0
     */
    protected function prepareTable($table)
    {
        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);

        if (empty($table->id)) {
            $table->created = Factory::getDate()->toSql();
            $table->created_by = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        }

        // An empty calendar submits '', which a datetime column rejects under
        // strict mode: the open-ended window is NULL, as on every other entity.
        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }
    }
    /**
     * Delete rules with their whole condition tree (groups -> conditions ->
     * values) and their targeting pivots: nothing survives a rule.
     *
     * CONTRACT COVERAGE NOTE: the tree ids are only COLLECTED before
     * parent::delete(); the contract's delete bracket fires inside the parent
     * call and the dependent rows are removed after it succeeds.
     *
     * @param array &$pks Record primary keys.
     *
     * @return bool True on success.
     *
     * @since  1.5.0
     */
    public function delete(&$pks)
    {
        $pks = array_map('intval', (array) $pks);

        // Permission first: the condition-tree reads below are work a user who may not
        // delete cart rules must not trigger.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();

        $groupIds = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_cart_rule_groups'))
                ->whereIn($db->quoteName('id_cart_rule'), $pks),
        )->loadColumn() ?: []);

        $conditionIds = $groupIds === [] ? [] : array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_cart_rule_conditions'))
                ->whereIn($db->quoteName('id_group'), $groupIds),
        )->loadColumn() ?: []);

        if (!parent::delete($pks)) {
            return false;
        }

        if ($conditionIds !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_rule_condition_values'))
                    ->whereIn($db->quoteName('id_condition'), $conditionIds),
            )->execute();
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_rule_conditions'))
                    ->whereIn($db->quoteName('id'), $conditionIds),
            )->execute();
        }

        if ($groupIds !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_rule_groups'))
                    ->whereIn($db->quoteName('id'), $groupIds),
            )->execute();
        }

        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_cart_rule',
            tableName:         '#__alfa_cart_rules',
        );

        foreach (['#__alfa_cart_rules_users', '#__alfa_cart_rules_usergroups'] as $pivotTable) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($pivotTable))
                    ->whereIn($db->quoteName('id_cart_rule'), $pks),
            )->execute();
        }

        return true;
    }
}
