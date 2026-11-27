<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The product's related products (the "related" slot): a part of the page of its own, after the product's
 * container, so a variant change of the product (which replaces that container) leaves it as it is. The cards are the
 * listing's own (html/com_alfa/items/default_item.php when the template overrides it), in the order staff set, only the
 * products the visitor may see, at most the count of the product, its category, the menu item or the Options. Nothing
 * is drawn when none shows.
 *
 * Overridable as html/com_alfa/item/default_related.php; a variant of it (default_related-<name>.php) is chosen per
 * product, category, menu item or in the Options.
 *
 * @var \Alfa\Component\Alfa\Site\View\Item\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

if ($this->relatedCards === []) {
    return;
}

// The cards' look is the listing's (list.css); the part's own spacing is the product page's (item.css).
$this->getDocument()->getWebAssetManager()->useStyle('com_alfa.list');
$headingId = 'item-related-heading-' . (int) $this->item->id;
?>
<section class="item-related" aria-labelledby="<?php echo $headingId; ?>">
    <h2 class="item-related-heading" id="<?php echo $headingId; ?>"><?php echo Text::_('COM_ALFA_ITEM_RELATED_HEADING'); ?></h2>
    <div class="list-container item-related-list">
        <?php echo implode("\n", $this->relatedCards); ?>
    </div>
</section>
