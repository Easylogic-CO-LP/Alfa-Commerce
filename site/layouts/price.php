<?php

use \Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;

$app = Factory::getApplication();
$filters = $app->input->get('filter', []);
$price = $displayData;

//If filters are empty, or there's no 'category_id' key, or if there is a 'category_id' key, but it has no value.
$categoryId = $filters['category_id'] ?? 0;
$settings = AlfaHelper::getCategorySettings($categoryId);
//$currencySettings = AlfaHelper::getGeneralCurrencySettings();


?>

<div data-item-prices>

<?php if($settings->prices['base_price_show']): ?>
    <p>
        <?php if($settings->prices['base_price_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_BASE_PRICE'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['base_price']); ?>
    </span>
    </p>
<?php endif;?>

<?php if($settings->prices['base_price_with_discounts_show'] && $price['base_price'] != $price['base_price_with_discount']): ?>
    <p>
        <?php if($settings->prices['base_price_with_discounts_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_BASE_PRICE_WITH_DISCOUNTS'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['base_price_with_discount']); ?>
    </span>
    </p>
<?php endif; ?>

<?php if($settings->prices['tax_amount_show']): ?>
    <p>
        <?php if($settings->prices['tax_amount_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_TAX_AMOUNT'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['tax_totals']['amount']); ?>
    </span>
    </p>
<?php endif; ?>

<?php if($settings->prices['base_price_with_tax_show']): ?>
    <p>
        <?php if($settings->prices['base_price_with_tax_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_BASE_PRICE_WITH_TAX'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['base_price_with_tax']); ?>
    </span>
    </p>
<?php endif; ?>

<?php if($settings->prices['discount_amount_show'] && $price['discounts_totals']['amount'] > 0): ?>
    <p>
        <?php if($settings->prices['discount_amount_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_DISCOUNT_AMOUNT'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['discounts_totals']['amount']); ?>
    </span>
    </p>
<?php endif; ?>

<?php if($settings->prices['final_price_show']): ?>
    <p>
        <?php if($settings->prices['final_price_show_label']): ?>
            <strong><?php echo Text::_('COM_ALFA_FINAL_PRICE'); ?>:</strong>
        <?php endif; ?>
        <span>
        <?php echo AlfaHelper::formattedPrice($price['final_price']); ?>
    </span>
    </p>
<?php endif; ?>
