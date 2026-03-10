<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;
use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Session\Session;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Layout\LayoutHelper;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;

// Import CSS
$wa = $this->document->getWebAssetManager();

$wa->useStyle('com_alfa.item');
$wa->useScript('com_alfa.item')
	->useScript('com_alfa.item.recalculate')
	->useScript('com_alfa.item.addtocart');

$priceSettings = $this->priceSettings;
?>


<div class="item-container" data-item-id="<?php echo $this->item->id; ?>">
    <div class="item-images-wrapper">
        <?php echo $this->loadTemplate('images'); ?>
    </div>

    <div class="item-info">
        <div>
            <h1 class="item-name">
                <?php echo $this->escape($this->item->name); ?>
            </h1>
        </div>
        <?php if (!empty($this->item->short_desc)): ?>
            <div class="item-short-description">
                <?php echo $this->escape($this->item->short_desc); ?>
            </div>
        <?php endif; ?>

        <?php echo LayoutHelper::render('price', ['item' => $this->item, 'settings' => $priceSettings]); //passed data as $displayData in layout ?>

        <?php echo LayoutHelper::render('stock_info', ['item' => $this->item]); ?>


        <div class="item-payment-methods">
            <?php echo Text::_('COM_ALFA_ITEM_PAYMENT_METHODS'); ?> :

            <?php
            if (!empty($this->item->payment_methods))
            {
                foreach ($this->item->payment_methods as $payment_method): ?>
                    <div class="item-payment-method">
                        <?php
                        echo PluginLayoutHelper::pluginLayout(
                            $payment_method->events->onItemView->getLayoutPluginType(),
                            $payment_method->events->onItemView->getLayoutPluginName(),
                            $payment_method->events->onItemView->getLayout(),
                        )->render($payment_method->events->onItemView->getLayoutData());
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php } ?>

        </div>

        <div class="item-shipment-methods">
            <?php echo Text::_('COM_ALFA_ITEM_SHIPMENT_METHODS'); ?> :

            <?php
            if (!empty($this->item->shipment_methods))
            {
                foreach ($this->item->shipment_methods as $shipment_method): ?>
                    <div class="item-shipment-method">
                        <?php
                        echo PluginLayoutHelper::pluginLayout(
                            $shipment_method->events->onItemView->getLayoutPluginType(),
                            $shipment_method->events->onItemView->getLayoutPluginName(),
                            $shipment_method->events->onItemView->getLayout(),
                        )->render($shipment_method->events->onItemView->getLayoutData());
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php } ?>

        </div>

        <?php echo LayoutHelper::render('add_to_cart', $this->item); ?>

        <div class="item-full-description">
            <?php echo nl2br($this->escape($this->item->full_desc)); ?>
        </div>

        <div class="item-manufacturers">
            <h2><?php echo Text::_('Categories'); ?></h2>
            <?php foreach ($this->item->categories as $id => $name) : ?>
                <a href="<?php echo Route::_('index.php?option=com_alfa&view=items&category_id=' . (int) $id); ?>"><?php echo $this->escape($name); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="item-manufacturers">
            <h2><?php echo Text::_('Manufacturers'); ?></h2>
            <?php foreach ($this->item->manufacturers as $id => $name) : ?>
                <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $id); ?>"><?php echo $this->escape($name); ?></a>
            <?php endforeach; ?>
        </div>

    </div>

</div>
