<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
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

    // Import CSS
    $wa = $this->document->getWebAssetManager();

    $wa->useStyle('com_alfa.item');
    $wa->useScript('com_alfa.item')
        ->useScript('com_alfa.item.recalculate')
        ->usePreset('com_alfa.item.addtocart')
        ->useScript('com_alfa.item.variant')
        ->useScript('com_alfa.showon.engine')
        ->useScript('com_alfa.validate.engine')
        ->useStyle('com_alfa.validate');

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

        <?php echo Layout::render('item.stock_info', ['item' => $this->item]); ?>

        <form class="item-buy-form" data-item-form
              data-action="<?php echo $this->escape($this->item->reloadUrl); ?>"
              action="<?php echo $this->escape($this->item->addToCartUrl); ?>" method="post">
            <input type="hidden" name="item_id" value="<?php echo (int) $this->item->id; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <?php if (!empty($this->item->editLine)): ?>
            <input type="hidden" name="edit_line" value="<?php echo (int) $this->item->editLine; ?>">
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

        <?php if (Surface::has($this->item, ItemSurface::PaymentMethods)): ?>
        <div class="item-payment-methods">
            <span class="item-payment-method-title"><?php echo Text::_('COM_ALFA_ITEM_PAYMENT_METHODS'); ?>:</span>

            <div class="item-payment-method-inner">
                <?php echo Surface::render($this->item, ItemSurface::PaymentMethods); ?>
            </div>
        </div>

        <?php endif; ?>

        <?php if (Surface::has($this->item, ItemSurface::ShipmentMethods)): ?>
        <div class="item-shipment-methods">
            <span class="item-shipment-method-title"><?php echo Text::_('COM_ALFA_ITEM_SHIPMENT_METHODS'); ?>:</span>

            <?php echo Surface::render($this->item, ItemSurface::ShipmentMethods); ?>
        </div>

        <?php endif; ?>

        <?php echo Layout::render('cart.wrapper', $this->item); ?>
        <?php echo Surface::render($this->item, ItemSurface::NearAddToCart); ?>

        </form>

        <div class="item-full-description">
            <?php echo nl2br($this->item->full_desc); ?>
        </div>
        <?php echo Surface::render($this->item, ItemSurface::FullDescription); ?>

        <?php echo $this->loadTemplate($this->manufacturersTpl); ?>
        <?php echo $this->loadTemplate($this->categoriesTpl); ?>

    </div>
</div>