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
use Joomla\CMS\Layout\LayoutHelper;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;

// Import CSS
$wa = $this->document->getWebAssetManager();

$categorySettings = AlfaHelper::getCategorySettings();

$wa->useStyle('com_alfa.item');
$wa->useScript('com_alfa.item')
    ->useScript('com_alfa.item.recalculate')
    ->useScript('com_alfa.item.addtocart');
?>

<section>
    <div class="item-container row" data-item-id="<?php echo $this->item->id;?>">
        <div class="col-md-6">
            <section class="item-images-wrapper">
                <?php echo $this->loadTemplate('images'); ?>
            </section>
        </div>
        <div class="col-md-6">
            <section class="item-info-section">
                <h1>
                    <?php echo $this->item->name; ?>
                </h1>
                <?php if (!empty($this->item->short_desc)): ?>
                    <div class="item-info">
                        <?php echo $this->item->short_desc; ?>
                    </div>
                <?php endif; ?>

                <?php echo LayoutHelper::render('price', ['item'=>$this->item, 'settings'=>$categorySettings] ); //passed data as $displayData in layout ?>

                <?php echo LayoutHelper::render('stock_info', ['item'=>$this->item,'quantity'=>$this->item->quantity_min]); ?>

                <?php echo LayoutHelper::render('add_to_cart', $this->item); ?>
                    
                <div class="item-desc-details">
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
                            <a href="<?php echo Route::_('index.php?option=com_alfa&view=items&filter[category_id]=' . (int)$id); ?>"><?php echo $name; ?></a>
                        <?php endforeach; ?>
                        <h2><?php echo Text::_('COM_ALFA_TITLE_MANUFACTURERS'); ?></h2>
                        <?php foreach ($this->item->manufacturers as $id => $name) : ?>
                            <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int)$id); ?>"><?php echo $name; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php //echo 'Price: <pre>';print_r($this->item->price); echo '</pre>'; ?>
                
            </section>
        </div>
    </div>
</section>
