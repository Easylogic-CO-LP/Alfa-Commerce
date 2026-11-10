<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app = Factory::getApplication();
$params = $app->getParams();

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list')
    ->useScript('com_alfa.list')
    ->useStyle('com_alfa.item')
    ->useScript('com_alfa.item.recalculate')
    ->usePreset('com_alfa.item.addtocart')
    ->useScript('com_alfa.item.variant')
    ->useScript('com_alfa.showon.engine');

// NO search assets load here, on purpose. The enhancement widget loads in the
// `list.filter_form` layout, beside the input that declares `data-alfa-search`. A consumer
// of the attribution belongs to the engine that issued the handle, which loads it from its
// own media when the view announces the result set (onAlfaResultSetRender). This template
// only DECLARES the attribution, through the view's two calls; declaring is data-only and
// engine-neutral.

// Price display settings, resolved per user group by the view. To change what the
// price layout shows on this page, override with an Alfa\Component\Alfa\Site\Helper\
// PriceSettings preset (except()/only()/minimal()/full()/compact(), or the make()
// builder); the full API is documented in the manual.
$priceSettings = $this->priceSettings;

// One h1 per page: the page heading when it shows, the category title then an h2; else the title is the h1.
$titleTag = $this->showPageHeading ? 'h2' : 'h1';
?>

<?php if ($this->showPageHeading) : ?>
<div class="page-header">
    <h1><?php echo $this->escape($this->params->get('page_heading')); ?></h1>
</div>
<?php endif; ?>

<?php if ($this->showCategoryTitle) : ?>
<<?php echo $titleTag; ?> class="category-title"><?php echo $this->escape($this->category->name); ?></<?php echo $titleTag; ?>>
<?php endif; ?>

<?php // The category's description as the editor saved it (filtered at save), no content plugins, as the
      // manufacturer page prints its own. ?>
<?php if ($this->showCategoryDescription) : ?>
<div class="category-description"><?php echo $this->category->desc; ?></div>
<?php endif; ?>

<?php // Subcategory strip. Gone entirely once a filter has replaced the category
      // context, when the merchant asked for that (hide_categories_when_filtered):
      // the view already left $this->categories empty, so an overriding sub-template
      // that reads it directly obeys the setting without knowing about it. ?>
<?php if ($this->showCategories): ?>
<?php echo $this->loadTemplate($this->listCategoriesTpl); ?>
<?php endif; ?>

<?php if ($this->showFilters): ?>
<?php echo Layout::render('list.filter_form', ['view' => $this]); ?>
<?php endif; ?>

<?php // THE RESULTS REGION (2.2.0, design 2.20): the pagination and the list. Focusable by its markup (tabindex -1),
      // which an override keeps or drops; list-form.js brings it into view and focuses it after a filter swap
      // (alfa:results:updated) and on a page the view marks (data-results-reveal: a later page, a submitted filter). ?>
<section data-results tabindex="-1"<?php echo $this->revealResults ? ' data-results-reveal' : ''; ?>>
    <?php echo $this->pagination->getListFooter(); ?>
    <?php
    // CLICK ATTRIBUTION IS DECLARED, NEVER INFERRED, and only through the view's two calls
    // (2.2.0): resultSetAttributes() on the container (the handle and the shown order, once for
    // the result set), resultAttributes() on each card (its global position). With no handle
    // neither renders anything. An override keeps these two calls.
    //
    // The offset makes the rank GLOBAL: without it page 2 would report positions 1-25 again
    // and every ranking projection built on this data would be wrong in a way nobody notices.
    $positionOffset = (int) ($this->pagination->limitstart ?? 0);
    $position = $positionOffset;
    ?>
    <div class="list-container items-list"<?php echo $this->resultSetAttributes(); ?>>
        <?php foreach ($this->items as $item) : ?>
            <?php // ONE overridable card sub-template ("item" slot), shared with the
                  // single-card reload. Chain per row: item -> category -> menu/global. ?>
            <?php $this->item = $item; $this->itemPosition = ++$position; ?>
            <?php echo $this->loadTemplate($this->cardTplFor($item)); ?>
        <?php endforeach; ?>
    </div>
    <?php echo $this->pagination->getListFooter(); ?>
</section>