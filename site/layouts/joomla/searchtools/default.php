<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Storefront override of joomla.searchtools.default.
 *
 * Core opens the Filter Options section by itself whenever ANY filter is active
 * (`filtersHidden = empty(activeFilters)`), and it decides that in MARKUP: the
 * `js-stools-container-filters-visible` class is stamped server-side, and
 * searchtools.js takes its initial state from that class alone; the
 * `filtersHidden` script option it also receives is never consulted at init. So
 * an option handed to the core layout changes nothing a shopper can see, and the
 * only honest place to decide the section's initial state is here, in the markup.
 *
 * Auto-opening suits an admin list, where the section is the only place the
 * active filters show. On a storefront a filter usually arrives from elsewhere (
 * a filters module, a landing link, a shared URL) and the component cannot know
 * which module a site pairs it with, nor should it: it knows the listing is
 * filtered and what the merchant asked for. That ask is
 * `collapse_items_filters_when_filtered` (config, overridable per Items menu
 * item); Layout::searchtools() turns it into `options.filtersHidden`, which
 * keeps the section closed until the visitor opens it with the Filter Options
 * button. Absent, this is core behaviour: open while filtered. As in core, the
 * option can only ever force the section CLOSED.
 *
 * Everything else (the DOM, the class names searchtools.js binds to, the option
 * set handed to `searchtools.form`, the sublayouts) is core's, verbatim: diffed
 * against layouts/joomla/searchtools/default.php this file is the header and
 * the one `$filtersActiveClass` line. Keep it that way so a core change is a
 * one-line re-sync. Reached the same way the `filters` override beside it is:
 * Layout::searchtools() resolves by component, so the active template's
 * html/layouts/com_alfa may override this, and every sublayout not found under
 * this folder still falls back to core.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$data = $displayData;

// Receive overridable options
$data['options'] = !empty($data['options']) ? $data['options'] : [];

$noResultsText     = '';
$hideActiveFilters = false;
$showFilterButton  = false;
$showSelector      = false;
$selectorFieldName = $data['options']['selectorFieldName'] ?? 'client_id';

// If a filter form exists.
if (isset($data['view']->filterForm) && !empty($data['view']->filterForm)) {
    // Checks if a selector (e.g. client_id) exists.
    if ($selectorField = $data['view']->filterForm->getField($selectorFieldName)) {
        $showSelector = $selectorField->getAttribute('filtermode', '') === 'selector' ? true : $showSelector;

        // Checks if a selector should be shown in the current layout.
        if (isset($data['view']->layout)) {
            $showSelector = $selectorField->getAttribute('layout', 'default') != $data['view']->layout ? false : $showSelector;
        }

        // Unset the selector field from active filters group.
        unset($data['view']->activeFilters[$selectorFieldName]);
    }

    // Checks if the filters button should exist.
    $filters = $data['view']->filterForm->getGroup('filter');
    $showFilterButton = isset($filters['filter_search']) && count($filters) === 1 ? false : true;

    // Checks if it should show the be hidden.
    $hideActiveFilters = empty($data['view']->activeFilters);

    // Check if the no results message should appear.
    if (isset($data['view']->total) && (int) $data['view']->total === 0) {
        $noResults = $data['view']->filterForm->getFieldAttribute('search', 'noresults', '', 'filter');
        if (!empty($noResults)) {
            $noResultsText = Text::_($noResults);
        }
    }
}

// Set some basic options.
$customOptions = [
    'filtersHidden'       => isset($data['options']['filtersHidden']) && $data['options']['filtersHidden'] ? $data['options']['filtersHidden'] : $hideActiveFilters,
    'filterButton'        => isset($data['options']['filterButton']) && $data['options']['filterButton'] ? $data['options']['filterButton'] : $showFilterButton,
    'defaultLimit'        => $data['options']['defaultLimit'] ?? Factory::getApplication()->get('list_limit', 20),
    'searchFieldSelector' => '#filter_search',
    'selectorFieldName'   => $selectorFieldName,
    'showSelector'        => $showSelector,
    'orderFieldSelector'  => '#list_fullordering',
    'showNoResults'       => !empty($noResultsText),
    'noResultsText'       => !empty($noResultsText) ? $noResultsText : '',
    'formSelector'        => !empty($data['options']['formSelector']) ? $data['options']['formSelector'] : '#adminForm',
];

// Merge custom options in the options array.
$data['options'] = array_merge($customOptions, $data['options']);

// THE ONE DIVERGENCE FROM CORE. Core stamps the class from the active-filter test
// alone; here it follows the EFFECTIVE option (a truthy caller option, else that
// same test), so the markup the script initialises from says what the merchant
// decided. The pre-merge value is used on purpose: it is the one that can only be
// forced closed, exactly as core's own option contract reads.
$filtersActiveClass = $customOptions['filtersHidden'] ? '' : ' js-stools-container-filters-visible';

// Load search tools
HTMLHelper::_('searchtools.form', $data['options']['formSelector'], $data['options']);
?>
<div class="js-stools" role="search">
    <?php if ($data['options']['showSelector']) : ?>
    <div class="js-stools-container-selector">
        <?php echo $this->sublayout('selector', $data); ?>
    </div>
    <?php endif; ?>
    <div class="js-stools-container-bar">
        <div class="btn-toolbar">
            <?php echo $this->sublayout('bar', $data); ?>
            <?php echo $this->sublayout('list', $data); ?>
        </div>
    </div>
    <!-- Filters div -->
    <div class="js-stools-container-filters clearfix<?php echo $filtersActiveClass; ?>">
        <?php if ($data['options']['filterButton']) : ?>
            <?php echo $this->sublayout('filters', $data); ?>
        <?php endif; ?>
    </div>
</div>
<?php if ($data['options']['showNoResults']) : ?>
    <?php echo $this->sublayout('noitems', $data); ?>
<?php endif; ?>
