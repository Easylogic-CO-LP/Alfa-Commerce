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
use \Joomla\CMS\Layout\LayoutHelper;
use \Joomla\CMS\Session\Session;
use \Joomla\CMS\User\UserFactoryInterface;

HTMLHelper::_('bootstrap.tooltip');
HTMLHelper::_('behavior.multiselect');
HTMLHelper::_('formbehavior.chosen', 'select');

$user       = Factory::getApplication()->getIdentity();
$userId     = $user->get('id');
$listOrder  = $this->state->get('list.ordering');
$listDirn   = $this->state->get('list.direction');
$canCreate  = $user->authorise('core.create', 'com_alfa') && file_exists(JPATH_COMPONENT . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'manufacturerform.xml');
$canEdit    = $user->authorise('core.edit', 'com_alfa') && file_exists(JPATH_COMPONENT .  DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'manufacturerform.xml');
$canCheckin = $user->authorise('core.manage', 'com_alfa');
$canChange  = $user->authorise('core.edit.state', 'com_alfa');
$canDelete  = $user->authorise('core.delete', 'com_alfa');

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list');
?>

<form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>" method="post"
	name="adminForm" id="adminForm">
	<?php if (!empty($this->filterForm)) {
		echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this));
	} ?>

	<input type="hidden" name="task" value="" />
	<input type="hidden" name="boxchecked" value="0" />
	<input type="hidden" name="filter_order" value="" />
	<input type="hidden" name="filter_order_Dir" value="" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>


<section>
    <div class="manufacturer-list list-container">
        <?php foreach ($this->items as $item) : ?>
            <article>
                <div class="manufacturer-item list-item">
                    <a href="<?php echo Route::_('index.php?option=com_alfa&view=items');?>">
                        <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
                        </a>
                    </div>
                <div class="manufacturer-title">
                    <h3><?php echo $item->name; ?></h3>
                </div>
        
                <div class="manufacturer-description">
                    <?php echo($item->desc); ?>
                </div>
                <div class="manufacturer-products">
                    <a href="<?php echo Route::_('index.php?option=com_alfa&view=items');?>">
                    <?php echo Text::_('COM_ALFA_MANUFACTURER_SHOW_ALL_PRODUCTS'); ?> </a>
                </div>
              
                <div class="manufacturer-url">
                    <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $item->id); ?>">
                        <?php echo Text::_('COM_ALFA_MANUFACTURER_DETAILS'); ?> </a>
                </div>
                </article>
            <?php endforeach; ?>
        </div>
</section>
