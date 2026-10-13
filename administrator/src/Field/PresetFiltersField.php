<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Preset Filters: locked constraints for an Items menu landing page.
 *
 * DRY by design: mounts the ONE canonical filter definition
 * (components/com_alfa/forms/filter_items.xml) into the menu item's params, so
 * every filter that exists (or is added later) is presettable with zero
 * duplication. Values store as params.presets.filter.* and are applied by
 * ItemsModel::populateState() as DEFAULTS: they seed the same filtering
 * pipeline the visitor's URL uses, and the visitor always wins (a preset
 * applies only while its key is absent from the request). A page becomes
 * fixed simply by not showing the filter UI (menu option / module assignment).
 *
 * Admin-mount adjustments:
 *   - the 'list' group (ordering/limit) is dropped: presets are constraints,
 *     not list preferences;
 *   - onchange="this.form.submit()" attributes are stripped: they belong to
 *     the storefront panel and would submit the menu form here;
 *   - XOR with the Category identity (request.category_id): while an identity
 *     is set, the categories preset renders disabled with an explanatory note
 *     (runtime enforces the same rule regardless of stored data);
 *   - a hint appears when exactly ONE preset category is chosen and no
 *     identity is set: a single category belongs in the Category field.
 *
 * @since  2.0.0
 */
class PresetFiltersField extends FormField
{
    protected $type = 'PresetFilters';

    /**
     * Render the mounted filter fields plus the XOR note and single-category hint.
     *
     * @return string The composite input markup
     * @since  2.0.0
     */
    protected function getInput(): string
    {
        // The mounted fields carry SITE language keys; load their catalogues here.
        $language = Factory::getApplication()->getLanguage();
        $language->load('com_alfa', JPATH_SITE);
        $language->load('com_users', JPATH_SITE);

        $filterForm = Form::getInstance(
            'com_alfa.menu_preset_filters.' . $this->id,
            JPATH_SITE . '/components/com_alfa/forms/filter_items.xml',
            ['control' => $this->name],
        );

        // Constraints only: ordering/limit are list preferences, not identity.
        foreach ($filterForm->getGroup('list') as $field) {
            $filterForm->removeField($field->fieldname, 'list');
        }

        // The item-field facets build their own filter[fields][<id>][] input names,
        // which cannot round-trip through jform[params][presets]. Presets do not
        // cover them; drop the field rather than render one that cannot save.
        $filterForm->removeField('fields', 'filter');

        $identityId = (int) ($this->form instanceof Form ? $this->form->getValue('category_id', 'request', 0) : 0);

        // The search label key lives in com_users' ADMIN catalogue on the frontend
        // context only; use the always-available core key here.
        $filterForm->setFieldAttribute('search', 'label', 'JSEARCH_FILTER', 'filter');

        foreach ($filterForm->getGroup('filter') as $field) {
            // Storefront auto-submit has no place inside the menu edit form.
            $filterForm->setFieldAttribute($field->fieldname, 'onchange', '', 'filter');

            if ($field->fieldname === 'category' && $identityId > 0) {
                $filterForm->setFieldAttribute('category', 'disabled', 'true', 'filter');
            }
        }

        // Saved values: params.presets.filter.* (tolerate object/array/empty).
        $value = is_object($this->value) ? json_decode(json_encode($this->value), true) : $this->value;
        $filterValues = \is_array($value) ? ($value['filter'] ?? []) : [];
        $filterForm->bind(['filter' => $filterValues]);

        $presetCategories = array_filter((array) ($filterValues['category'] ?? []), static fn ($v) => $v !== '' && $v !== null);

        $html = [];
        $html[] = '<div class="alfa-preset-filters">';

        foreach ($filterForm->getGroup('filter') as $field) {
            if ($field->fieldname === 'category') {
                // Own wrapper, own id: the XOR hides THIS container; no coupling
                // to whatever enhancement wraps the native select.
                $html[] = '<div id="alfa-preset-cats-wrap"' . ($identityId > 0 ? ' hidden' : '') . '>' . $field->renderField() . '</div>';
                $html[] = '<div id="alfa-preset-cats-note" class="alert alert-info"' . ($identityId > 0 ? '' : ' hidden') . '>'
                    . Text::_('COM_ALFA_PRESET_CATS_CONTROLLED_BY_IDENTITY') . '</div>';
                $html[] = '<div id="alfa-preset-single-hint" class="alert alert-warning"'
                    . (\count($presetCategories) === 1 && $identityId === 0 ? '' : ' hidden') . '>'
                    . Text::_('COM_ALFA_PRESET_SINGLE_CATEGORY_HINT') . '</div>';

                continue;
            }

            $html[] = $field->renderField();
        }

        $html[] = '</div>';

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->useScript('com_alfa.admin-menu-presets');

        return implode("\n", $html);
    }
}
