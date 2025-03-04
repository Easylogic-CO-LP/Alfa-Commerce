<?php

use \Joomla\CMS\Language\Text;
use \Alfa\Component\Alfa\Site\Helper\PriceFormat;


$item = $displayData['item'];
$settings = $displayData['settings'];

if(empty($item) || empty($settings)) {
    echo 'Price layout: Item object or settings object is empty';
    return;
}

$price = $item->price;

?>

<div data-item-prices>

    <?php if($settings->prices['base_price_show']): ?>
        <p>
            <?php if($settings->prices['base_price_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_BASE_PRICE'); ?>:</strong>
            <?php endif; ?>
            
            <span>
                <?php echo PriceFormat::format($price['base_price']); ?>
            </span>
        </p>
    <?php endif;?>

    <?php if($settings->prices['base_price_with_discounts_show'] && $price['base_price'] != $price['base_price_with_discount']): ?>
        <p>
            <?php if($settings->prices['base_price_with_discounts_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_BASE_PRICE_WITH_DISCOUNTS'); ?>:</strong>
            <?php endif; ?>
            <span>
                <?php echo PriceFormat::format($price['base_price_with_discount']); ?>
            </span>
        </p>
    <?php endif; ?>

    <?php if($settings->prices['tax_amount_show']): ?>
        <p>
            <?php if($settings->prices['tax_amount_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_TAX_AMOUNT'); ?>:</strong>
            <?php endif; ?>
            <span>
                <?php echo PriceFormat::format($price['tax_totals']['amount']); ?>
            </span>
        </p>
    <?php endif; ?>

    <?php if($settings->prices['base_price_with_tax_show']): ?>
        <p>
            <?php if($settings->prices['base_price_with_tax_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_BASE_PRICE_WITH_TAX'); ?>:</strong>
            <?php endif; ?>
            <span>
                <?php echo PriceFormat::format($price['base_price_with_tax']); ?>
            </span>
        </p>
    <?php endif; ?>

    <?php if($settings->prices['discount_amount_show'] && $price['discounts_totals']['amount'] > 0): ?>
        <p>
            <?php if($settings->prices['discount_amount_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_DISCOUNT_AMOUNT'); ?>:</strong>
            <?php endif; ?>
            <span>
                <?php echo PriceFormat::format($price['discounts_totals']['amount']); ?>
            </span>
        </p>
    <?php endif; ?>

    <?php if($settings->prices['final_price_show']): ?>
        <p>
            <?php if($settings->prices['final_price_show_label']): ?>
                <strong><?php echo Text::_('COM_ALFA_FINAL_PRICE'); ?>:</strong>
            <?php endif; ?>
            <span>
                <?php echo PriceFormat::format($price['final_price']); ?>
            </span>
        </p>
    <?php endif; ?>

</div>