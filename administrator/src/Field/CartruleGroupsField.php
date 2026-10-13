<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Throwable;

/**
 * Condition-group builder.
 *
 * Replaces the nested joomla-field-subform this screen used to lean on. That
 * cloner skips nested contents when it renumbers, so an added group posted its
 * conditions under the template's placeholder index and the tree persisted as a
 * condition-less group, which matches every cart. Owning the renumbering is
 * simpler than working around it, because our shape is known: groups, each with
 * condition rows, exactly two levels.
 *
 * Emits ORDINARY array-named inputs, not a JSON blob:
 *
 *   jform[groups][0][min_quantity]
 *   jform[groups][0][conditions][0][type]
 *   jform[groups][0][conditions][0][exclude]
 *   jform[groups][0][conditions][0][values][]
 *
 * so PHP receives precisely the structure CartruleModel::syncConditionTree()
 * already consumes: no serialise/parse round trip, and a rejected save
 * repopulates like any other form input.
 *
 * Rows are cloned from server-rendered <template>s; nothing builds markup in JS.
 * The value picker stays Joomla's joomla-field-fancy-select: a web component
 * upgrades whatever <select> it finds, so the remote search keeps working
 * inside our own rows.
 *
 * WHAT A USER MAY SEE. A match type lists records of its own section (products,
 * categories, manufacturers), so it is offered only to a user who may view that
 * section, the same permission cartrule.conditionValues asks. A saved condition of
 * a type the user may not view is kept as it is: its type and values post back
 * unchanged, shown by id, with no search and no other type to switch to.
 *
 * @since  2.0.0
 */
class CartruleGroupsField extends FormField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $type = 'CartruleGroups';

    /**
     * Placeholder indexes in the clone templates, swapped for real ones on add.
     *
     * @since  2.0.0
     */
    private const GROUP_PLACEHOLDER = '__GIDX__';
    private const CONDITION_PLACEHOLDER = '__CIDX__';

    /**
     * How many options ship with a picker so it is browsable before typing.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const FIRST_PAGE = 30;

    /**
     * The match types a condition row may use.
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    private const TYPES = [
        'items' => 'COM_ALFA_CARTRULE_CONDITION_ITEMS',
        'categories' => 'COM_ALFA_CARTRULE_CONDITION_CATEGORIES',
        'manufacturers' => 'COM_ALFA_CARTRULE_CONDITION_MANUFACTURERS',
    ];

    /**
     * The match types the current user may view, in TYPES order, resolved once per field.
     *
     * @var string[]|null
     *
     * @since  2.0.0
     */
    private ?array $readableTypes = null;

    /**
     * Render the builder: the saved tree, the clone templates, the add button.
     *
     *
     * @since  2.0.0
     */
    protected function getInput(): string
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->usePreset('choicesjs')
            ->useScript('webcomponent.field-fancy-select')
            ->useStyle('com_alfa.admin')
            ->useScript('com_alfa.cartrule.conditions');

        Text::script('JGLOBAL_SELECT_NO_RESULTS_MATCH');
        Text::script('JGLOBAL_SELECT_PRESS_TO_SELECT');
        Text::script('COM_ALFA_CARTRULE_GROUP_REMOVE_CONFIRM');

        $groups = $this->normalise($this->value);
        $rows = '';

        foreach (array_values($groups) as $index => $group) {
            $rows .= $this->groupRow((string) $index, $group);
        }

        // Without a single type to match by, a new group could hold no condition at all.
        $addGroup = $this->readableTypes() === [] ? ''
            : '<button type="button" class="btn btn-success btn-sm" data-add-group>'
                . '<span class="icon-plus" aria-hidden="true"></span> '
                . $this->esc(Text::_('COM_ALFA_CARTRULE_GROUP_ADD')) . '</button>';

        return '<div class="alfa-conditions" data-alfa-conditions'
            . ' data-name="' . $this->esc($this->name) . '">'
            . '<div class="alfa-conditions-groups" data-groups>' . $rows . '</div>'
            . $addGroup
            . $this->templates()
            . '</div>';
    }

    /**
     * The match types the current user may view: each type lists records of its own section.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function readableTypes(): array
    {
        if ($this->readableTypes === null) {
            // The type names its section; can() resolves it through PERMISSION_SECTIONS.
            $this->readableTypes = array_values(array_filter(
                array_keys(self::TYPES),
                static fn (string $type): bool => AlfaHelper::can(section: $type, action: 'view'),
            ));
        }

        return $this->readableTypes;
    }

    /**
     * The saved tree, defensively shaped.
     *
     * @param mixed $value Whatever the form handed us.
     *
     * @return array<int, array{min_quantity: int, conditions: array}>
     *
     * @since  2.0.0
     */
    private function normalise($value): array
    {
        $groups = [];

        foreach ((array) $value as $group) {
            $group = (array) $group;
            $conditions = [];

            foreach ((array) ($group['conditions'] ?? []) as $condition) {
                $condition = (array) $condition;
                $type = (string) ($condition['type'] ?? 'categories');

                $conditions[] = [
                    'type' => isset(self::TYPES[$type]) ? $type : 'categories',
                    'exclude' => (int) ($condition['exclude'] ?? 0) === 1 ? 1 : 0,
                    'values' => array_values(array_filter(array_map('intval', (array) ($condition['values'] ?? [])))),
                ];
            }

            $groups[] = [
                'min_quantity' => max(1, (int) ($group['min_quantity'] ?? 1)),
                'conditions' => $conditions,
            ];
        }

        return $groups;
    }

    /**
     * One group: its quantity gate plus its condition rows.
     *
     * @param string $gi Group index (or the placeholder, in the template).
     * @param array $group The group data.
     *
     *
     * @since  2.0.0
     */
    private function groupRow(string $gi, array $group): string
    {
        $base = $this->name . '[' . $gi . ']';
        $conditions = '';

        foreach (array_values($group['conditions'] ?? []) as $ci => $condition) {
            $conditions .= $this->conditionRow($gi, (string) $ci, $condition);
        }

        $addCondition = $this->readableTypes() === [] ? ''
            : '<button type="button" class="btn btn-secondary btn-sm" data-add-condition>'
                . '<span class="icon-plus" aria-hidden="true"></span> '
                . $this->esc(Text::_('COM_ALFA_CARTRULE_CONDITION_ADD')) . '</button>';

        return '<fieldset class="alfa-condition-group" data-group>'
            . '<div class="alfa-condition-group-head">'
            . '<label>' . $this->esc(Text::_('COM_ALFA_CARTRULE_GROUP_MIN_QTY_LBL')) . '</label>'
            . '<input type="number" min="1" step="1" class="form-control"'
            . ' name="' . $this->esc($base . '[min_quantity]') . '"'
            . ' value="' . (int) ($group['min_quantity'] ?? 1) . '">'
            . '<span class="alfa-condition-group-hint">'
            . $this->esc(Text::_('COM_ALFA_CARTRULE_GROUP_MIN_QTY_DESC')) . '</span>'
            . '<button type="button" class="btn btn-danger btn-sm ms-auto" data-remove-group'
            . ' title="' . $this->esc(Text::_('COM_ALFA_CARTRULE_GROUP_REMOVE')) . '">'
            . '<span class="icon-minus" aria-hidden="true"></span></button>'
            . '</div>'
            . '<div class="alfa-conditions-rows" data-conditions>' . $conditions . '</div>'
            . $addCondition
            . '</fieldset>';
    }

    /**
     * One condition row, laid out inline: Match by - operator - values.
     *
     * The type choice offers only the types the user may view. A row saved with a type they may
     * not view offers just that type (it cannot be switched) and shows its values by id.
     *
     * @param string $gi Group index.
     * @param string $ci Condition index.
     * @param array $condition The condition data.
     *
     *
     * @since  2.0.0
     */
    private function conditionRow(string $gi, string $ci, array $condition): string
    {
        $base = $this->name . '[' . $gi . '][conditions][' . $ci . ']';
        $type = (string) ($condition['type'] ?? 'categories');
        $exclude = (int) ($condition['exclude'] ?? 0);
        $values = (array) ($condition['values'] ?? []);
        $locked = !\in_array($type, $this->readableTypes(), true);

        $typeSelect = '<select class="form-select" name="' . $this->esc($base . '[type]') . '" data-condition-type>';

        foreach ($locked ? [$type] : $this->readableTypes() as $key) {
            $typeSelect .= '<option value="' . $this->esc($key) . '"'
                . ($key === $type ? ' selected' : '') . '>' . $this->esc(Text::_(self::TYPES[$key])) . '</option>';
        }

        $typeSelect .= '</select>';

        $operator = '<select class="form-select" name="' . $this->esc($base . '[exclude]') . '">'
            . '<option value="0"' . ($exclude === 0 ? ' selected' : '') . '>'
            . $this->esc(Text::_('COM_ALFA_CARTRULE_CONDITION_OPERATOR_IS')) . '</option>'
            . '<option value="1"' . ($exclude === 1 ? ' selected' : '') . '>'
            . $this->esc(Text::_('COM_ALFA_CARTRULE_CONDITION_OPERATOR_IS_NOT')) . '</option>'
            . '</select>';

        return '<div class="alfa-condition-row" data-condition>'
            . '<span class="alfa-condition-lead">' . $this->esc(Text::_('COM_ALFA_CARTRULE_CONDITION_TYPE_LBL')) . '</span>'
            . $typeSelect
            . $operator
            . ($locked
                ? $this->lockedValues($base . '[values][]', $type, $values)
                : $this->valuesPicker($base . '[values][]', $type, $values))
            . '<button type="button" class="btn btn-outline-danger btn-sm" data-remove-condition'
            . ' title="' . $this->esc(Text::_('COM_ALFA_CARTRULE_CONDITION_REMOVE')) . '">'
            . '<span class="icon-minus" aria-hidden="true"></span></button>'
            . '</div>';
    }

    /**
     * The searchable values picker for one row, for a type the user may view.
     *
     * Only the chosen values plus a small browsable first page; the rest
     * arrives from cartrule.conditionValues as the merchant types.
     *
     * @param string $name The input name.
     * @param string $type The match type.
     * @param array $selected Chosen ids.
     *
     *
     * @since  2.0.0
     */
    private function valuesPicker(string $name, string $type, array $selected): string
    {
        $options = [];
        $seen = [];

        foreach ($this->labelsFor($type, $selected) as $id => $label) {
            $options[] = HTMLHelper::_('select.option', (string) $id, $label);
            $seen[(string) $id] = true;
        }

        foreach ($this->firstPage($type) as $row) {
            if (!isset($seen[$row['value']])) {
                $options[] = HTMLHelper::_('select.option', $row['value'], $row['text']);
                $seen[$row['value']] = true;
            }
        }

        $select = HTMLHelper::_(
            'select.genericlist',
            $options,
            $name,
            'multiple',
            'value',
            'text',
            array_map('strval', $selected),
            false,
        );

        $url = 'index.php?option=com_alfa&task=cartrule.conditionValues&format=json'
            . '&type=' . $type . '&' . Session::getFormToken() . '=1';

        return '<joomla-field-fancy-select class="alfa-condition-values"'
            . ' placeholder="' . $this->esc(Text::_('JGLOBAL_TYPE_OR_SELECT_SOME_OPTIONS')) . '"'
            . ' remote-search term-key="term" min-term-length="1"'
            . ' url="' . $this->esc($url) . '" data-alfa-condition-values>'
            . $select
            . '</joomla-field-fancy-select>';
    }

    /**
     * The values of a saved condition whose type the user may not view: kept as they are, shown
     * by id, with nothing to search.
     *
     * A hidden multiple select carries them, so the saved condition posts back unchanged and the
     * builder's renumbering (which looks for that select) keeps working.
     *
     * @param string $name The input name.
     * @param string $type The match type.
     * @param array $selected Chosen ids.
     *
     *
     * @since  2.0.0
     */
    private function lockedValues(string $name, string $type, array $selected): string
    {
        $ids = array_values(array_filter(array_map('intval', $selected)));
        $options = '';

        foreach ($ids as $id) {
            $options .= '<option value="' . $id . '" selected>#' . $id . '</option>';
        }

        $shown = $ids === [] ? '-' : implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids));

        return '<div class="alfa-condition-values alfa-condition-values-locked">'
            . '<select multiple hidden name="' . $this->esc($name) . '">' . $options . '</select>'
            . '<span class="form-control-plaintext">' . $this->esc($shown) . '</span>'
            . '<span class="form-text">'
            . $this->esc(Text::sprintf('COM_ALFA_CARTRULE_CONDITION_LOCKED', Text::_(self::TYPES[$type] ?? $type)))
            . '</span>'
            . '</div>';
    }

    /**
     * Resolve labels for chosen ids, through the language tables.
     *
     * These entities keep their name per language, so the base table has no
     * `name` column; the join is the only way to a label.
     *
     * @param string $type The match type.
     * @param array $ids Chosen ids.
     *
     * @return array<int, string> id => label
     *
     * @since  2.0.0
     */
    private function labelsFor(string $type, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        [$table, $langPk] = $this->source($type);
        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('a.id'))
                ->from($db->quoteName($table, 'a'))
                ->whereIn($db->quoteName('a.id'), $ids);

            MultilingualHelper::addMultilingualJoinToQuery(
                query:             $query,
                mainAlias:         'a',
                mainPrimaryColumn: 'id',
                langTableBase:     $table,
                langPrimaryColumn: $langPk,
                fields:            ['name'],
            );

            $labels = [];

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $labels[(int) $row->id] = (string) ($row->name ?: ('#' . $row->id));
            }

            return $labels;
        } catch (Throwable $e) {
            // A visible id beats a broken form.
            return array_combine($ids, array_map(static fn (int $id): string => '#' . $id, $ids));
        }
    }

    /**
     * A browsable first page for a match type.
     *
     * @param string $type The match type.
     *
     * @return array<int, array{value: string, text: string}>
     *
     * @since  2.0.0
     */
    private function firstPage(string $type): array
    {
        try {
            $model = Factory::getApplication()
                ->bootComponent('com_alfa')
                ->getMVCFactory()
                ->createModel($type, 'Administrator', ['ignore_request' => true]);

            if (!$model) {
                return [];
            }

            $model->getState('list.ordering');
            $model->setState('filter.state', '*');
            $model->setState('filter.search', '');
            $model->setState('list.limit', self::FIRST_PAGE);
            $model->setState('list.start', 0);

            $rows = [];

            foreach ($model->getItems() ?: [] as $row) {
                $rows[] = [
                    'value' => (string) ($row->id ?? ''),
                    'text' => (string) ($row->name ?? $row->title ?? $row->id ?? ''),
                ];
            }

            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Table + language PK for a match type.
     *
     * @param string $type The match type.
     *
     * @return array{0: string, 1: string}
     *
     * @since  2.0.0
     */
    private function source(string $type): array
    {
        return match ($type) {
            'items' => ['#__alfa_items', 'id_item'],
            'manufacturers' => ['#__alfa_manufacturers', 'id_manufacturer'],
            default => ['#__alfa_categories', 'id_category'],
        };
    }

    /**
     * The clone sources: rendered server-side, never assembled in JS. None when the user may view
     * no match type: there is nothing a new condition could match by.
     *
     *
     * @since  2.0.0
     */
    private function templates(): string
    {
        $readable = $this->readableTypes();

        if ($readable === []) {
            return '';
        }

        $emptyGroup = ['min_quantity' => 1, 'conditions' => []];
        $emptyCondition = ['type' => \in_array('categories', $readable, true) ? 'categories' : $readable[0], 'exclude' => 0, 'values' => []];

        return '<template data-group-template>'
            . $this->groupRow(self::GROUP_PLACEHOLDER, $emptyGroup)
            . '</template>'
            . '<template data-condition-template>'
            . $this->conditionRow(self::GROUP_PLACEHOLDER, self::CONDITION_PLACEHOLDER, $emptyCondition)
            . '</template>';
    }

    /**
     * Escape for attribute/text output.
     *
     * @param string $value Raw value.
     *
     *
     * @since  2.0.0
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
