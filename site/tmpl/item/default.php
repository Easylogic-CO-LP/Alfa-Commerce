<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     *
     * The product page. What an override of this file (html/com_alfa/item/default.php) can read:
     *
     *   $this->item->fields          the product's fields by machine name, each an ItemField:
     *                                  ->label        the field's name in the page's language
     *                                  ->value        the pool values the item carries for it (objects with
     *                                                 display_name), [] when none
     *                                  ->placement    where the shop placed it (a FieldPlacement value)
     *                                  ->render()     the field as its placement draws it, with its visibility
     *                                                 rules and any template override of the field
     *                                  ...            every property of the definition (id, type, values, ...)
     *                                A field placed as Not shown automatically is drawn nowhere by this file:
     *                                  echo $this->item->fields['colour']->render();
     *                                prints it where the override wants. Any field prints the same way.
     *   Surface::render($this->item, ItemSurface::Title, only: ['colour'])
     *                                one slot's contributions, or some fields of it (only / except), see Surface.
     *   $this->item->surfaces        every slot's contributions, read-only (SurfaceMap).
     *   $this->sectionsMode          how the page sections are drawn: stacked, tabs or accordion (the menu item's
     *                                choice), '' on a buy-form reload. The sections themselves are the layout
     *                                item.sections (PageSections::collect builds them, common/sections.php draws).
     *
     * A single field's markup is overridden without touching this file: html/layouts/com_alfa/item/field/<field
     * name>.php, or item/field/<field type>.php for every field of a type (see layouts/item/field.php).
     */

    // No direct access
    defined('_JEXEC') or die;


    use Alfa\Component\Alfa\Site\Helper\Layout;
    use \Joomla\CMS\HTML\HTMLHelper;
    use \Joomla\CMS\Factory;
    use \Joomla\CMS\Uri\Uri;
    use \Joomla\CMS\Router\Route;
    use \Joomla\CMS\Language\Text;
    use \Joomla\CMS\Session\Session;
    use Joomla\Utilities\ArrayHelper;
    use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
    use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
    use Alfa\Component\Alfa\Site\Helper\Surface;

    // Whether the shop sells online: in catalogue-only mode the item says so, and the page draws no buy form and
    // asks for none of its scripts. An override reads the same property.
    $sellsOnline = (bool) ($this->item->sellsOnline ?? true);

    // Import CSS
    $wa = $this->document->getWebAssetManager();

    $wa->useStyle('com_alfa.item');
    $wa->useScript('com_alfa.item');

    if ($sellsOnline) {
        $wa->useScript('com_alfa.item.recalculate')
            ->usePreset('com_alfa.item.addtocart')
            ->useScript('com_alfa.item.variant')
            ->useScript('com_alfa.showon.engine')
            ->useScript('com_alfa.validate.engine')
            ->useStyle('com_alfa.validate');
    }

    $priceSettings = $this->priceSettings;
    $medias = $this->item->medias;
?>

<div class="com-alfa item-container" data-item-id="<?php echo $this->item->id; ?>">
    <?php if (!empty($medias)): ?>
        <div class="item-images-wrapper">
            <?php echo $this->loadTemplate($this->imagesTpl); ?>
            <?php echo Surface::render($this->item, ItemSurface::Image); ?>
        </div>
    <?php endif; ?>

    <div class="item-info">
        <div>
            <h1 class="item-name">
                <?php echo $this->escape($this->item->name); ?>
            </h1>
        </div>
        <?php echo Surface::render($this->item, ItemSurface::Title); ?>
        <?php if (!empty($this->item->short_desc)): ?>
            <div class="item-short-description">
                <?php echo $this->item->short_desc; ?>
            </div>
        <?php endif; ?>
        <?php echo Surface::render($this->item, ItemSurface::ShortDescription); ?>

        <?php echo Layout::render('item.price', ['item' => $this->item, 'settings' => $priceSettings]); //passed data as $displayData in layout ?>
        <?php echo Surface::render($this->item, ItemSurface::Price); ?>

        <?php echo Layout::render('item.stock_info', ['item' => $this->item]); ?>

        <?php if ($sellsOnline): ?>
        <form class="item-buy-form" data-item-form
              data-action="<?php echo $this->escape($this->item->reloadUrl); ?>"
              action="<?php echo $this->escape($this->item->addToCartUrl); ?>" method="post">
            <input type="hidden" name="item_id" value="<?php echo (int) $this->item->id; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <?php if (!empty($this->item->editLine)): ?>
            <input type="hidden" name="edit_line" value="<?php echo (int) $this->item->editLine; ?>">
            <?php endif; ?>
        <?php else: ?>
        <?php // Catalogue only: a plain block, no form; the fields arrive as read-only lists. ?>
        <div class="item-catalogue">
        <?php endif; ?>

        <div class="item-fields-surfaces">
            <?php echo Surface::render($this->item, ItemSurface::BuyForm); ?>
        </div>

        <?php if (Surface::has($this->item, ItemSurface::Specifications)): ?>
        <div class="item-specifications">
            <span class="item-specifications-title"><?php echo Text::_('COM_ALFA_ITEM_SPECIFICATIONS'); ?>:</span>

            <div class="item-specifications-inner">
                <?php echo Surface::render($this->item, ItemSurface::Specifications); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php // Payment and shipping: one framed block, one method per row (the part its plugin draws, else its image
              // and name), drawn while at least one method is set to show on the product; each method's own switch
              // is the one switch. ?>
        <?php if (Surface::has($this->item, ItemSurface::PaymentMethods) || Surface::has($this->item, ItemSurface::ShipmentMethods)): ?>
        <div class="item-methods">
            <span class="item-methods-title"><?php echo Text::_('COM_ALFA_ITEM_PAYMENT_AND_SHIPPING'); ?></span>

            <div class="item-methods-inner">
                <?php echo Surface::render($this->item, ItemSurface::PaymentMethods); ?>
                <?php echo Surface::render($this->item, ItemSurface::ShipmentMethods); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php echo Layout::render('cart.wrapper', $this->item); ?>
        <?php echo Surface::render($this->item, ItemSurface::NearAddToCart); ?>

        <?php echo $sellsOnline ? '</form>' : '</div>'; ?>

        <?php echo $this->loadTemplate($this->manufacturersTpl); ?>
        <?php echo $this->loadTemplate($this->categoriesTpl); ?>

    </div>
</div>

<?php // The page sections (the description, the specifications, the shop's and the product's own sections, the fields
      // placed in a section of their own, the plugins' sections), under the two columns and apart from the product's
      // container: a variant change replaces the container and leaves them, and the chosen tab, as they are. ?>
<?php echo Layout::render('item.sections', ['item' => $this->item, 'mode' => $this->sectionsMode]); ?>

<?php // The related products, a part of their own after the product's container: a variant change replaces the
      // container and leaves them as they are. An override of this page shows them where it loads the part. ?>
<?php echo $this->loadTemplate($this->relatedTpl); ?>
