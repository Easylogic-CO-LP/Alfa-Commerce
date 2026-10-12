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

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.general')
	->useStyle('com_alfa.cart')
	->useScript('com_alfa.cart');

//echo $this->form;

?>

<div class="com-alfa cart-wrapper" data-cart-outer>

    <h2 class="cart-heading"><?php echo Text::_('COM_ALFA_CART_HEADING'); ?></h2>

    <section class="cart-items-section">
		<?php echo $this->loadTemplate($this->cartItemsTpl); ?>
    </section>

    <section class="cart-form-section">
        <!-- Customer Form -->
		<?php echo $this->loadTemplate($this->cartFormTpl); ?>
    </section>
</div>