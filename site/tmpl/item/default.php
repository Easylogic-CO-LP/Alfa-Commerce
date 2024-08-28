<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Session\Session;
use Joomla\Utilities\ArrayHelper;

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.product');
$wa->useScript('com_alfa.product');
?>

<section>
    <div class="product-container row">
        <div class="col-md-6">
            <section class="product-images-wrapper">
                <?php echo $this->loadTemplate('images'); ?>
            </section>
        </div>
        <div class="col-md-6">
            <section class="product-info-section">
                <h1>
                    <?php echo $this->item->name; ?>
                </h1>
                <?php if (!empty($this->item->short_desc)): ?>
                    <div class="product-info">
                        <?php echo nl2br($this->item->short_desc); ?>
                    </div>
                <?php endif; ?>
                <div class="add-to-cart-container mb-3">
                    <div class="custom-number-container">
                        <button class="custom-decrement" onclick="decrementValue()">-</button>
                        <input type="number" id="customNumberInput" class="custom-number-input" min="1" value="1">
                        <button class="custom-increment" onclick="incrementValue()">+</button>
                    </div>
                    <div class="add-to-cart">
                        <button class="add-to-cart-btn">
                            Add to Cart
                        </button>
                    </div>
                    <div class="wishlist">
                        <button class="wishlist-btn">
                            <svg fill="#000000"
                                 xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                 viewBox="0 0 471.701 471.701" xml:space="preserve">
                            <g>
                                <path d="M433.601,67.001c-24.7-24.7-57.4-38.2-92.3-38.2s-67.7,13.6-92.4,38.3l-12.9,12.9l-13.1-13.1
                                    c-24.7-24.7-57.6-38.4-92.5-38.4c-34.8,0-67.6,13.6-92.2,38.2c-24.7,24.7-38.3,57.5-38.2,92.4c0,34.9,13.7,67.6,38.4,92.3
                                    l187.8,187.8c2.6,2.6,6.1,4,9.5,4c3.4,0,6.9-1.3,9.5-3.9l188.2-187.5c24.7-24.7,38.3-57.5,38.3-92.4
                                    C471.801,124.501,458.301,91.701,433.601,67.001z M414.401,232.701l-178.7,178l-178.3-178.3c-19.6-19.6-30.4-45.6-30.4-73.3
                                    s10.7-53.7,30.3-73.2c19.5-19.5,45.5-30.3,73.1-30.3c27.7,0,53.8,10.8,73.4,30.4l22.6,22.6c5.3,5.3,13.8,5.3,19.1,0l22.4-22.4
                                    c19.6-19.6,45.7-30.4,73.3-30.4c27.6,0,53.6,10.8,73.2,30.3c19.6,19.6,30.3,45.6,30.3,73.3
                                    C444.801,187.101,434.001,213.101,414.401,232.701z"/>
                            </g>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="product-desc-details">
                    <ul class="tab">
                        <li>
                            <button class="tablinks active"
                                    onclick="openTab(event, 'description')"><?php echo Text::_('COM_ALFA_ITEM_LBL_DESC'); ?></button>
                        </li>
                        <li>
                            <button class="tablinks"
                                    onclick="openTab(event, 'details')"><?php echo Text::_('COM_ALFA_ITEM_LBL_DETAILS'); ?></button>
                        </li>
                    </ul>
                    <div id="description" class="tabcontent">
                        <?php echo nl2br($this->item->full_desc); ?>
                    </div>
                    <div id="details" class="tabcontent">
                        <h2><?php echo Text::_('COM_ALFA_TITLE_CATEGORIES'); ?></h2>
                        <?php foreach ($this->item->categories as $id => $name) : ?>
                            <a href="<?php echo Route::_('index.php?option=com_alfa&view=category&id=' . (int)$id); ?>"><?php echo $name . '<br>'; ?></a>
                        <?php endforeach; ?>
                        <h2><?php echo Text::_('COM_ALFA_TITLE_MANUFACTURERS'); ?></h2>
                        <?php foreach ($this->item->manufacturers as $id => $name) : ?>
                            <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int)$id); ?>"><?php echo $name . '<br>'; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>
<?php
//echo '<pre>';
//print_r($this->item);
//echo '</pre>';
?>