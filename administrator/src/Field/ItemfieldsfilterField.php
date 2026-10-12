<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * Catalog filter for item-field facets (Size, Color, ...).
 *
 * Unlike category / manufacturer (one dimension -> one ListField), item-fields are
 * MANY independent dimensions, so this one field renders one fancy-select PER
 * filterable field, reusing the very same `joomla.form.field.list-fancy-select`
 * widget those filters use, so the look is identical. Each sub-select emits the
 * shared `filter[fields][<id>][]` contract, exactly like the mod_alfa_filtrs
 * module, so populateState / getListQuery / facets handle them identically (no
 * extra mapping).
 *
 * THE COUNT IS NOT WHAT COSTS: do not "optimise" it away. Measured on
 * a 100k-product catalogue (6M projection rows): the aggregate WITH the count is
 * 3062ms, the same query reduced to "which (field, value) pairs are reachable" is
 * 2860ms. Roughly 7%. The scan is the cost, and it is unavoidable here: deciding
 * which fields to HIDE (a field with nothing reachable drops out entirely) needs
 * the same reachability pass, so once it has run the count is nearly free.
 *
 * If this ever has to get faster the lever is caching the result ACROSS requests:
 * the site model already computes a $stateKey (every active filter plus a value
 * fingerprint) and uses it only as a per-request memo.
 *
 * Options come from the Items model's memoised getAvailableItemFieldValues(), so
 * the View and the module share the SAME computation: no separate/duplicate query.
 *
 * @since  1.3.0
 */
class ItemfieldsfilterField extends FormField
{
    /**
     * @var string
     * @since  1.3.0
     */
    protected $type = 'Itemfieldsfilter';

    /**
     * Render one fancy multi-select per filterable item-field.
     *
     * @return string The field HTML ('' when nothing is filterable).
     * @since  1.3.0
     */
    protected function getInput()
    {
        // Two catalogues, one contract. The storefront asks "what can a shopper
        // still narrow to": published, in scope, ShowOn-pruned. The admin asks
        // "what exists in my catalogue", which has to include the fields a
        // merchant has since unpublished, because finding those products is the
        // whole reason to filter. Both emit filter[fields][<id>][], so the models
        // and the module handle them identically.
        if ((string) $this->element['scope'] === 'admin') {
            return $this->adminInput();
        }

        // The site form is also MOUNTED IN THE ADMIN: com_menus renders
        // components/com_alfa/forms/filter_items.xml for a menu item's preset
        // filters. siteInput() boots the Site model, which reads
        // SiteApplication::getParams(); on the administrator application that
        // method does not exist and the whole menu editor dies. Render nothing
        // there rather than guess at a rendering this context cannot support.
        if (!Factory::getApplication()->isClient('site')) {
            return '';
        }

        return $this->siteInput();
    }

    /**
     * The storefront facet bar: one fancy-select per filterable field.
     *
     * @return string The field HTML ('' when nothing is filterable).
     * @since  1.3.0
     */
    protected function siteInput()
    {
        $app = Factory::getApplication();

        $model = $app->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Items', 'Site', ['ignore_request' => false]);

        if (!$model) {
            return '';
        }

        $facets = $model->getAvailableItemFieldValues();

        if (empty($facets)) {
            return '';
        }

        // Currently selected value ids per field, read from the model's EFFECTIVE state
        // rather than the raw request. A selection belonging to a facet ShowOn has hidden
        // is pruned from that state, and the raw request still carries it; reading the
        // request would tick a box for a filter that is no longer being applied.
        $active = (array) ($model->getActiveFilters()['fields'] ?? []);

        // Full default set the fancy-select layout extracts (missing keys would be
        // undefined variables inside it).
        $defaults = [
            'autocomplete' => 'off', 'autofocus' => false, 'class' => '', 'description' => '',
            'disabled' => false, 'group' => null, 'hidden' => false, 'hint' => '', 'id' => '',
            'label' => '', 'labelclass' => '', 'multiple' => true, 'name' => '',
            'onchange' => 'this.form.submit();', 'onclick' => '', 'pattern' => '', 'readonly' => false,
            'repeat' => false, 'required' => false, 'size' => 0, 'spellcheck' => false,
            'validate' => '', 'value' => [], 'options' => [], 'dataAttribute' => '', 'dataAttributes' => [],
        ];

        $html = [];

        foreach ($facets as $fieldId => $facet) {
            $fieldId = (int) $fieldId;
            $values = $facet['values'] ?? [];

            if (empty($values)) {
                continue;
            }

            $selected = array_map('strval', (array) ($active[$fieldId] ?? []));
            $options = [];

            foreach ($values as $valueId => $value) {
                $options[] = HTMLHelper::_(
                    'select.option',
                    (string) (int) $valueId,
                    ($value['name'] ?? '') . ' (' . (int) ($value['count'] ?? 0) . ')',
                );
            }

            $select = LayoutHelper::render('joomla.form.field.list-fancy-select', array_merge($defaults, [
                'id' => 'filter_fields_' . $fieldId,
                'name' => 'filter[fields][' . $fieldId . '][]',
                'value' => $selected,
                'options' => $options,
                'hint' => $facet['label'] ?? '',
            ]));

            $label = htmlspecialchars((string) ($facet['label'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html[] = '<div class="item-field-filter" data-field-id="' . $fieldId . '">'
                . '<label class="item-field-filter-label" for="filter_fields_' . $fieldId . '">' . $label . '</label>'
                . $select
                . '</div>';
        }

        return $html
            ? '<div class="item-field-filters">' . implode('', $html) . '</div>'
            : '';
    }

    /**
     * The admin grid's facet bar.
     *
     * Rendering only: the narrowing lives in the model, exactly as it does for
     * the storefront: the facets offered are the ones reachable from the filters
     * already applied (a category, a manufacturer, another field), each counted
     * against that same set, and a field with nothing left to offer is not shown
     * at all. Publication state is deliberately not a criterion (an unpublished
     * field is the one somebody is hunting for), so those are labelled instead.
     *
     * @return string The field HTML ('' when the catalogue uses no item fields).
     * @since  1.3.0
     */
    protected function adminInput()
    {
        $model = Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Items', 'Administrator', ['ignore_request' => false]);

        if (!$model) {
            return '';
        }

        $facets = $model->getAvailableItemFieldValues();

        if (!$facets) {
            return '';
        }

        $selectedAll = (array) $this->value;

        // Full default set the fancy-select layout extracts (missing keys would be
        // undefined variables inside it).
        $defaults = [
            'autocomplete' => 'off', 'autofocus' => false, 'class' => '', 'description' => '',
            'disabled' => false, 'group' => null, 'hidden' => false, 'hint' => '', 'id' => '',
            'label' => '', 'labelclass' => '', 'multiple' => true, 'name' => '',
            'onchange' => 'this.form.submit();', 'onclick' => '', 'pattern' => '', 'readonly' => false,
            'repeat' => false, 'required' => false, 'size' => 0, 'spellcheck' => false,
            'validate' => '', 'value' => [], 'options' => [], 'dataAttribute' => '', 'dataAttributes' => [],
        ];

        $html = [];

        foreach ($facets as $fieldId => $facet) {
            $fieldId = (int) $fieldId;
            $options = [];

            foreach ($facet['values'] as $valueId => $value) {
                $options[] = HTMLHelper::_(
                    'select.option',
                    (string) (int) $valueId,
                    $value['name'] . ' (' . (int) $value['count'] . ')',
                );
            }

            $label = (string) $facet['label'];

            if (!empty($facet['retired'])) {
                $label .= ' - ' . Text::_('COM_ALFA_COMBI_FIELD_RETIRED');
            }

            $select = LayoutHelper::render('joomla.form.field.list-fancy-select', array_merge($defaults, [
                'id' => 'filter_fields_' . $fieldId,
                'name' => 'filter[fields][' . $fieldId . '][]',
                'value' => array_map('strval', (array) ($selectedAll[$fieldId] ?? [])),
                'options' => $options,
                // Generic placeholder, matching the category/manufacturer selects
                // beside it: the field's own name is the label above, and once a
                // value is picked the placeholder is replaced by chips, which is
                // exactly when that label has to still be there to say what they are.
                'hint' => Text::_('JGLOBAL_SELECT_SOME_OPTIONS'),
            ]));

            $html[] = '<div class="item-field-filter" data-field-id="' . $fieldId . '">'
                . '<label class="item-field-filter-label" for="filter_fields_' . $fieldId . '">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
                . $select
                . '</div>';
        }

        return $html
            ? '<div class="item-field-filters">' . implode('', $html) . '</div>'
            : '';
    }
}
