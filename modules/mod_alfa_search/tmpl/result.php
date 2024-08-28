<?php
/**
 *---------------------------------------------------------------------------------------
 * @package       VP Ajax Search Module
 *---------------------------------------------------------------------------------------
 * @copyright     Copyright (C) 2012-2022 VirtuePlanet Services LLP. All rights reserved.
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 * @authors       Abhishek Das
 * @email         info@virtueplanet.com
 * @link          https://www.virtueplanet.com
 *---------------------------------------------------------------------------------------
 */

use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

// $unitDesc = JText::sprintf ('COM_VIRTUEMART_PRODUCT_UNITPRICE', vmText::_('COM_VIRTUEMART_UNIT_SYMBOL_' . $product->product_unit));

// $url = Route::_('index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $product->virtuemart_product_id . '&virtuemart_category_id=' . $product->virtuemart_category_id);

if (!isset($product)) {
    $product = $displayData['product'];
}
// print_r($product);

?>

    <div class="product-item">
        <a href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$product->id); ?>">
            <h3><?php echo $product->name; ?></h3>
        </a>
        <p><?php echo $product->short_desc; ?></p>
        <!--    <p>--><?php //echo $product->full_desc; ?><!--</p>-->
    </div>
<?php /*
<!-- <div class="searched-product">
	< ?php if($params->get('show_images', 1) && !empty($product->images[0])) : ?>
		<div class="searched-product-image">
			<a href="< ?php echo $url ?>">
				< ?php echo $product->images[0]->displayMediaThumb ('class="img-responsive"', false); ?>
			</a>
		</div>
	< ?php endif; ?>
	<div class="searched-product-info">
		<div class="searched-product-info-inner">
			<div class="searched-product-title">
				<a href="< ?php echo $url ?>">< ?php echo $product->product_name ?></a>
			</div>
			< ?php if($showPrice) : ?>
				< ?php if($product->prices['salesPrice'] <= 0 && VmConfig::get ('askprice', 1) && isset($product->images[0]) && !$product->images[0]->file_is_downloadable) : ?>
					< ?php $ask_url = JRoute::_('index.php?option=com_virtuemart&view=productdetails&task=askquestion&virtuemart_product_id=' . $product->virtuemart_product_id . '&virtuemart_category_id=' . $product->virtuemart_category_id . '&tmpl=component', false); ?>
					<a class="btn btn-info btn-sm" href="< ?php echo $url ?>">< ?php echo JText::_ ('COM_VIRTUEMART_PRODUCT_ASKPRICE') ?></a>
				< ?php else : ?>
					<div class="searched-product-price">
						< ?php if($priceType == 'unitPrice') : ?>
							< ?php echo $currency->createPriceDiv('unitPrice', $unitDesc, $product->prices); ?>
						< ?php else : ?>
							< ?php echo $currency->createPriceDiv($priceType, '', $product->prices); ?>
						< ?php endif; ?>
					</div>
				< ?php endif; ?>
			< ?php endif; ?>
		</div>
	</div>
</div> -->
*/
?>