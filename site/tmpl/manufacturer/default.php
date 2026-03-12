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
use Joomla\Utilities\ArrayHelper;


$user   = Factory::getApplication()->getIdentity();
$userId = $user->get('id');

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.manufacturer');
?>

<section>
    <div class="manufacturer_fields">

        <article>
            <div class="manufacturer-img">
                <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
            </div>
            <div class="manufacturer-name">
                <h3><?php echo $this->escape($this->item->name); ?></h3>
            </div>

            <div class="manufacturer-description">
				<?php echo $this->item->desc; ?>
            </div>

            <div class="manufacturer-products">
                <a href="<?php echo Route::_('index.php?option=com_alfa&view=items'); ?>">
					<?php echo Text::_('COM_ALFA_MANUFACTURER_SHOW_ALL_PRODUCTS'); ?> </a>
            </div>

        </article>

    </div>
</section>









