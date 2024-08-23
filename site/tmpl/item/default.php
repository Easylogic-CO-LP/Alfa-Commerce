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
            <section class="product-preview-section">
                <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
            </section>
        </div>
        <div class="col-md-6">
            <section class="product-info-section">
                <h1>
                    <?php echo $this->item->name; ?>
                </h1>
                <div class="product-info">
                    <?php echo nl2br($this->item->short_desc); ?>
                </div>
                <div class="product-desc-details">
                    <ul class="tab">
                        <li>
                            <button class="tablinks active"
                                    onclick="openCity(event, 'description')"><?php echo Text::_('COM_ALFA_ITEM_LBL_DESC'); ?></button>
                        </li>
                        <li>
                            <button class="tablinks"
                                    onclick="openCity(event, 'details')"><?php echo Text::_('COM_ALFA_ITEM_LBL_DETAILS'); ?></button>
                        </li>
                    </ul>
                    <div id="description" class="tabcontent">
                        <?php echo nl2br($this->item->full_desc); ?>
                    </div>
                    <div id="details" class="tabcontent">
                        <h2><?php echo Text::_('COM_ALFA_TITLE_CATEGORIES'); ?></h2>
                        <?php foreach ($this->item->categories as $id => $name) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=category&id=' . (int) $id); ?>"><?php echo $name . '<br>'; ?></a>
                        <?php endforeach; ?>
                        <h2><?php echo Text::_('COM_ALFA_TITLE_MANUFACTURERS'); ?></h2>
                        <?php foreach ($this->item->manufacturers as $id => $name) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $id); ?>"><?php echo $name . '<br>'; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>
<?php
echo '<pre>';
print_r($this->item);
echo '</pre>';
?>
<div class="item_fields">

    <table class="table">


        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_NAME'); ?></th>
            <td><?php echo $this->item->name; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_ID'); ?></th>
            <td><?php echo $this->item->id; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_SHORT_DESC'); ?></th>
            <td><?php echo nl2br($this->item->short_desc); ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_FULL_DESC'); ?></th>
            <td><?php echo nl2br($this->item->full_desc); ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_SKU'); ?></th>
            <td><?php echo $this->item->sku; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_GTIN'); ?></th>
            <td><?php echo $this->item->gtin; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_MPN'); ?></th>
            <td><?php echo $this->item->mpn; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK'); ?></th>
            <td><?php echo $this->item->stock; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK_ACTION'); ?></th>
            <td><?php echo $this->item->stock_action; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_MANAGE_STOCK'); ?></th>
            <td><?php echo $this->item->manage_stock; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_ALIAS'); ?></th>
            <td><?php echo $this->item->alias; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_META_TITLE'); ?></th>
            <td><?php echo $this->item->meta_title; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_META_DESC'); ?></th>
            <td><?php echo nl2br($this->item->meta_desc); ?></td>
        </tr>

    </table>

</div>

