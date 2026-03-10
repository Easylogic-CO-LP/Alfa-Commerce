<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
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

//echo $this->form;

?>

<div id="cart-outer" data-cart-outer>

    <h2 class="text-center"><?php echo Text::_('COM_ALFA_CART_HEADING'); ?></h2>

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