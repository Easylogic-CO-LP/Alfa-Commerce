<?php

/**
 * @package     Alfa Commerce
 * @subpackage  com_alfa
 *
 * @copyright   (C) 2026 Easylogic. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model\Traits;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Decides which filters count as "active" from the filter FORM rather than from
 * `$this->filter_fields`.
 *
 * Searchtools uses ONLY the emptiness of getActiveFilters() to decide whether the filter
 * panel starts open (layouts/joomla/searchtools/default.php:
 * `$hideActiveFilters = empty($data['view']->activeFilters)`). Core derives it from
 * `$this->filter_fields`, but that array is really the ORDERING whitelist
 * (`in_array($fullOrdering, $this->filter_fields)` in ListModel::populateState), so a
 * filter that is not ALSO a sortable column is invisible to it and the panel closes on a
 * page that IS filtered. That is why Items reopened on a category (a real column) while
 * Orders and Stock Movements did not on a date range.
 *
 * The obvious fix (adding those names to filter_fields) is what core itself does for
 * `tag` and `level` in com_content, but it would also make them ACCEPTED ORDERING
 * COLUMNS here: our models pass list.ordering into `$query->order($db->quoteName(...))`, so
 * `list[fullordering]=date_range ASC` would reach SQL as an unknown column and 500. So
 * the form answers instead: it is the authoritative declaration of what a user can
 * filter by, it already carries plugin-injected filters, and nothing has to be kept in
 * step by hand. Same discipline as getStoreId(), which serialises every filter.* key
 * rather than naming them one by one.
 *
 * Lives in a trait because the two list bases that need it (AlfaListModel and the
 * plugin-panel PanelModel) do not share one.
 *
 * @since  1.10.0
 */
trait HasFormDerivedFiltersTrait
{
    /**
     * Which filters are currently narrowing the list.
     *
     * `search` is deliberately excluded, matching core: the search box is always visible,
     * so typing in it should not pop the panel open.
     *
     * @return array Active filters, keyed by filter name.
     *
     * @since   1.10.0
     */
    public function getActiveFilters()
    {
        $active = parent::getActiveFilters();

        $form = $this->getFilterForm();

        if (!$form) {
            return $active;
        }

        foreach ($form->getGroup('filter') as $field) {
            $name = $field->fieldname;

            if ($name === 'search' || isset($active[$name])) {
                continue;
            }

            $value = $this->state->get('filter.' . $name);

            // Read straight off the registry, exactly as core's getActiveFilters() does
            // on the line above: no getState() priming call. State is already populated
            // by the time anything asks which filters are active.
            //
            // Core's own test: 0 is a legitimate filter value (state = unpublished), so
            // numeric zero counts while '' and null do not.
            if (!empty($value) || is_numeric($value)) {
                $active[$name] = $value;
            }
        }

        return $active;
    }
}
