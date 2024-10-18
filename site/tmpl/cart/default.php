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
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Layout\LayoutHelper;
// use \Alfa\Component\Alfa\Site\Helper\CartHelper;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.general')
    ->useStyle('com_alfa.cart')
    ->useScript('com_alfa.cart');

?>

<div id="cart-outer" data-cart-outer>
    
    <h2 class="text-center">Your Cart</h2>

    <section class="container mt-5">
        <?php echo $this->loadTemplate('cart_items'); ?>
    </section>

    <section>
        <!-- Customer Form -->
        <div class="row">
            <div class="col-md-12">
                <?php echo $this->loadTemplate('cart_form'); ?>
            </div>
        </div>

    </section>
</div>