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
    ->useStyle('com_alfa.item')
    ->useScript('com_alfa.item.recalculate')
    ->usePreset('com_alfa.item.addtocart')
    ->useScript('com_alfa.item.variant')
    ->useScript('com_alfa.showon.engine');

// NO search assets load here, on purpose. The enhancement widget loads in the
// `list.filter_form` layout, beside the input that declares `data-alfa-search`. The
// click-attribution beacon is not the component's AT ALL any more: only the Enterprise
// engine ever issues the attribution it consumes, so the engine's own `search_input`
// layout (claimed via onAlfaSearchInputLayout) loads it from the PLUGIN's media; a
// tier-specific script never ships with the component. This template only DECLARES the
// attribution (the container attribute below); declaring is data-only and engine-neutral.

// Price display settings, resolved per user group by the view. To change what the
// price layout shows on this page, override with an Alfa\Component\Alfa\Site\Helper\
// PriceSettings preset (except()/only()/minimal()/full()/compact(), or the make()
// builder); the full API is documented in the manual.
$priceSettings = $this->priceSettings;
?>

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

<section>
    <?php echo $this->pagination->getListFooter(); ?>
    <?php
    // CLICK ATTRIBUTION IS DECLARED, NEVER INFERRED. The search event id belongs to the RESULT
    // SET, so it is emitted once on the container rather than repeated on every card; the
    // per-card rank is declared by the card itself. Absent when this listing is not an
    // attributable search, and the beacon script then does nothing at all.
    //
    // The offset makes the rank GLOBAL: without it page 2 would report positions 1-25 again
    // and every ranking projection built on this data would be wrong in a way nobody notices.
    $searchEventId = $this->searchEventId ?? null;
    $positionOffset = (int) ($this->pagination->limitstart ?? 0);
    $position = $positionOffset;
    ?>
    <div class="list-container items-list"<?php echo $searchEventId
        // The handle is OPAQUE and engine-issued (int|string); escape it, never cast it:
        // an integer cast would mangle a string handle from an external engine to 0.
        ? ' data-alfa-search-event="' . htmlspecialchars((string) $searchEventId, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
        <?php foreach ($this->items as $item) : ?>
            <?php // ONE overridable card sub-template ("item" slot), shared with the
                  // single-card reload. Chain per row: item -> category -> menu/global. ?>
            <?php $this->item = $item; $this->itemPosition = ++$position; ?>
            <?php echo $this->loadTemplate($this->cardTplFor($item)); ?>
        <?php endforeach; ?>
    </div>
    <?php echo $this->pagination->getListFooter(); ?>
</section>