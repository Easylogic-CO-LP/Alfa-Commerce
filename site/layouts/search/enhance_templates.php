<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * THE MARKUP OF THE SEARCH-ENHANCEMENT PANEL: every element, class and translated
 * string the widget will ever show. `enhance.js` CREATES NOTHING: it clones these
 * fragments and fills the parts marked `data-alfa-search-slot`, so overriding THIS FILE
 * (html/layouts/com_alfa/search/enhance_templates.php in a template) restyles or
 * restructures the whole dropdown with zero JS knowledge.
 *
 * THE CONTRACT WITH THE SCRIPT IS ATTRIBUTES, NEVER STRUCTURE:
 *   template[data-alfa-search-template="..."]  names a fragment
 *   [data-alfa-search-slot="..."]              names a part the script fills
 * Classes here are styling hooks only; the script never selects by class, so an
 * override may rename or drop any of them freely. An override that removes a whole
 * fragment or slot FAILS CLOSED: that part (or the whole enhancement, for `panel`/`row`)
 * simply does not render, and the plain input keeps working.
 *
 * Fragments and their slots:
 *   panel                 the dropdown container (script toggles `hidden`, appends parts)
 *   suggestions           wrapper for completion chips
 *   suggestion            one chip; slot `text` (may be the root element itself)
 *   section-products,
 *   section-categories,
 *   section-manufacturers one result section each, heading baked in; slot `rows`
 *   row                   one result; slots `link`, `title`, `description`. The script
 *                         removes `href` on unrouted rows and stamps `data-unrouted`;
 *                         it stamps `data-section` with the wire section name and
 *                         `data-active` on the keyboard-highlighted element, which
 *                         also gets an `id` (when it has none) and `aria-selected`
 *                         so the input can name it in `aria-activedescendant`.
 *   empty                 the no-results notice
 *
 * A listbox may hold options only, so every wrapper and heading between the panel and
 * its options is `role="presentation"`. An override that adds one must do the same.
 *
 * @since  1.9.0
 */
?>
<template data-alfa-search-template="panel">
	<div class="alfa-search-enhance" role="listbox"
		aria-label="<?php echo htmlspecialchars(Text::_('COM_ALFA_SEARCH_RESULTS_LABEL'), ENT_QUOTES, 'UTF-8'); ?>" hidden></div>
</template>

<template data-alfa-search-template="suggestions">
	<div class="search-suggestions" role="presentation"></div>
</template>

<template data-alfa-search-template="suggestion">
	<button type="button" class="search-suggestion" role="option" data-alfa-search-slot="text"></button>
</template>

<template data-alfa-search-template="section-products">
	<div class="search-section" role="presentation" data-section="products">
		<h3 class="search-section-title" role="presentation"><?php echo Text::_('COM_ALFA_SEARCH_PRODUCTS_HEADING'); ?></h3>
		<div class="search-section-rows" role="presentation" data-alfa-search-slot="rows"></div>
	</div>
</template>

<template data-alfa-search-template="section-categories">
	<div class="search-section" role="presentation" data-section="categories">
		<h3 class="search-section-title" role="presentation"><?php echo Text::_('COM_ALFA_SEARCH_CATEGORIES_HEADING'); ?></h3>
		<div class="search-section-rows" role="presentation" data-alfa-search-slot="rows"></div>
	</div>
</template>

<template data-alfa-search-template="section-manufacturers">
	<div class="search-section" role="presentation" data-section="manufacturers">
		<h3 class="search-section-title" role="presentation"><?php echo Text::_('COM_ALFA_SEARCH_MANUFACTURERS_HEADING'); ?></h3>
		<div class="search-section-rows" role="presentation" data-alfa-search-slot="rows"></div>
	</div>
</template>

<template data-alfa-search-template="row">
	<a class="search-row" role="option" data-alfa-search-slot="link">
		<span class="search-row-title" data-alfa-search-slot="title"></span>
		<span class="search-row-desc" data-alfa-search-slot="description"></span>
	</a>
</template>

<template data-alfa-search-template="empty">
	<p class="search-empty" role="presentation"><?php echo Text::_('COM_ALFA_SEARCH_NO_RESULTS'); ?></p>
</template>
