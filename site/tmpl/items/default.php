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

$user = Factory::getApplication()->getIdentity();
$userId = $user->get('id');
$listOrder = $this->state->get('list.ordering');
$listDirn = $this->state->get('list.direction');
$canCreate = $user->authorise('core.create', 'com_alfa') && file_exists(JPATH_COMPONENT . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'itemform.xml');
$canEdit = $user->authorise('core.edit', 'com_alfa') && file_exists(JPATH_COMPONENT . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'itemform.xml');
$canCheckin = $user->authorise('core.manage', 'com_alfa');
$canChange = $user->authorise('core.edit.state', 'com_alfa');
$canDelete = $user->authorise('core.delete', 'com_alfa');

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list')
    ->useStyle('com_alfa.item')
    ->useScript('com_alfa.item.recalculate')
    ->useScript('com_alfa.item.addtocart');
?>  

    <?php echo $this->loadTemplate('categories'); ?>

    <form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
      <?php if (!empty($this->filterForm)) { echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); } ?>

        <input type="hidden" name="task" value=""/>
        <input type="hidden" name="boxchecked" value="0"/>
        <input type="hidden" name="filter_order" value=""/>
        <input type="hidden" name="filter_order_Dir" value=""/>
      <?php echo HTMLHelper::_('form.token'); ?>
    </form>


    <section>
        <div class="list-container items-list">
            <?php foreach ($this->items as $item) : ?>

                <?php //echo '<pre>'; print_r($item); echo '</pre>';?>

                <article class="list-item item-item" data-item-id="<?php echo $item->id;?>">
                    <div>
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$item->id); ?>">
                            <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
                        </a>
                        </a>
                    </div>
                    <div class="item-title">
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$item->id); ?>">
                            <?php echo $this->escape($item->name); ?></a>
                    </div>
                    <div class="item-price" >
                        <?php echo LayoutHelper::render('price',$item->price); //passed data as $displayData in layout ?>
                    </div>
                    
                    <?php echo LayoutHelper::render('addtocart'); ?>

                </article>
            <?php endforeach; ?>
        </div>
    </section>
