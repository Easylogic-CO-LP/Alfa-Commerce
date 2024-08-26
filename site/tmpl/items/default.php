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
$wa->useStyle('com_alfa.list');
?>

    <section>
        <div class="list-container products-list">
            <?php foreach ($this->items as $item) : ?>
                <article class="list-item product-item">
                    <div>
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$item->id); ?>">
                            <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
                        </a>
                        </a>
                    </div>
                    <div class="product-title">
                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$item->id); ?>">
                            <?php echo $this->escape($item->name); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!--    <form action="--><?php //echo htmlspecialchars(Uri::getInstance()->toString()); ?><!--" method="post"-->
    <!--          name="adminForm" id="adminForm">-->
    <!--        --><?php //if (!empty($this->filterForm)) {
//            echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this));
//        } ?>
    <!--        <div class="table-responsive">-->
    <!--            <table class="table table-striped" id="itemList">-->
    <!--                <thead>-->
    <!--                <tr>-->
    <!---->
    <!--                    <th class=''>-->
    <!--                        --><?php //echo HTMLHelper::_('grid.sort', 'COM_ALFA_ITEMS_ID', 'a.id', $listDirn, $listOrder); ?>
    <!--                    </th>-->
    <!---->
    <!--                    <th class=''>-->
    <!--                        --><?php //echo HTMLHelper::_('grid.sort', 'COM_ALFA_ITEMS_NAME', 'a.name', $listDirn, $listOrder); ?>
    <!--                    </th>-->
    <!---->
    <!--                    <th class=''>-->
    <!--                        --><?php //echo HTMLHelper::_('grid.sort', 'COM_ALFA_ITEMS_SKU', 'a.sku', $listDirn, $listOrder); ?>
    <!--                    </th>-->
    <!---->
    <!--                    <th class=''>-->
    <!--                        --><?php //echo HTMLHelper::_('grid.sort', 'COM_ALFA_ITEMS_STOCK', 'a.stock', $listDirn, $listOrder); ?>
    <!--                    </th>-->
    <!---->
    <!--                    <th>-->
    <!--                        --><?php //echo HTMLHelper::_('grid.sort', 'JPUBLISHED', 'a.state', $listDirn, $listOrder); ?>
    <!--                    </th>-->
    <!---->
    <!--                    --><?php //if ($canEdit || $canDelete): ?>
    <!--                        <th class="center">-->
    <!--                            --><?php //echo Text::_('COM_ALFA_ITEMS_ACTIONS'); ?>
    <!--                        </th>-->
    <!--                    --><?php //endif; ?>
    <!---->
    <!--                </tr>-->
    <!--                </thead>-->
    <!--                <tfoot>-->
    <!--                <tr>-->
    <!--                    <td colspan="--><?php //echo isset($this->items[0]) ? count(get_object_vars($this->items[0])) : 10; ?><!--">-->
    <!--                        <div class="pagination">-->
    <!--                            --><?php //echo $this->pagination->getPagesLinks(); ?>
    <!--                        </div>-->
    <!--                    </td>-->
    <!--                </tr>-->
    <!--                </tfoot>-->
    <!--                <tbody>-->
    <!--                --><?php //foreach ($this->items as $i => $item) : ?>
    <!--                    --><?php //$canEdit = $user->authorise('core.edit', 'com_alfa'); ?>
    <!---->
    <!--                    <tr class="row--><?php //echo $i % 2; ?><!--">-->
    <!---->
    <!--                        <td>-->
    <!--                            --><?php //echo $item->id; ?>
    <!--                        </td>-->
    <!--                        <td>-->
    <!--                            --><?php //$canCheckin = Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_alfa.' . $item->id) || $item->checked_out == Factory::getApplication()->getIdentity()->id; ?>
    <!--                            --><?php //if ($canCheckin && $item->checked_out > 0) : ?>
    <!--                                <a href="--><?php //echo Route::_('index.php?option=com_alfa&task=item.checkin&id=' . $item->id . '&' . Session::getFormToken() . '=1'); ?><!--">-->
    <!--                                    --><?php //echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'item.', false); ?><!--</a>-->
    <!--                            --><?php //endif; ?>
    <!--                            <a href="--><?php //echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$item->id); ?><!--">-->
    <!--                                --><?php //echo $this->escape($item->name); ?><!--</a>-->
    <!--                        </td>-->
    <!--                        <td>-->
    <!--                            --><?php //echo $item->sku; ?>
    <!--                        </td>-->
    <!--                        <td>-->
    <!--                            --><?php //echo $item->stock; ?>
    <!--                        </td>-->
    <!--                        <td>-->
    <!--                            --><?php //$class = ($canChange) ? 'active' : 'disabled'; ?>
    <!--                            <a class="btn btn-micro --><?php //echo $class; ?><!--"-->
    <!--                               href="--><?php //echo ($canChange) ? Route::_('index.php?option=com_alfa&task=item.publish&id=' . $item->id . '&state=' . (($item->state + 1) % 2), false, 2) : '#'; ?><!--">-->
    <!--                                --><?php //if ($item->state == 1): ?>
    <!--                                    <i class="icon-publish"></i>-->
    <!--                                --><?php //else: ?>
    <!--                                    <i class="icon-unpublish"></i>-->
    <!--                                --><?php //endif; ?>
    <!--                            </a>-->
    <!--                        </td>-->
    <!--                        --><?php //if ($canEdit || $canDelete): ?>
    <!--                            <td class="center">-->
    <!--                            </td>-->
    <!--                        --><?php //endif; ?>
    <!---->
    <!--                    </tr>-->
    <!--                --><?php //endforeach; ?>
    <!--                </tbody>-->
    <!--            </table>-->
    <!--        </div>-->
    <!--        --><?php //if ($canCreate) : ?>
    <!--            <a href="--><?php //echo Route::_('index.php?option=com_alfa&task=itemform.edit&id=0', false, 0); ?><!--"-->
    <!--               class="btn btn-success btn-small"><i-->
    <!--                        class="icon-plus"></i>-->
    <!--                --><?php //echo Text::_('COM_ALFA_ADD_ITEM'); ?><!--</a>-->
    <!--        --><?php //endif; ?>
    <!---->
    <!--        <input type="hidden" name="task" value=""/>-->
    <!--        <input type="hidden" name="boxchecked" value="0"/>-->
    <!--        <input type="hidden" name="filter_order" value=""/>-->
    <!--        <input type="hidden" name="filter_order_Dir" value=""/>-->
    <!--        --><?php //echo HTMLHelper::_('form.token'); ?>
    <!--    </form>-->
    <!---->
<?php
//if ($canDelete) {
//    $wa->addInlineScript("
//			jQuery(document).ready(function () {
//				jQuery('.delete-button').click(deleteItem);
//			});
//
//			function deleteItem() {
//
//				if (!confirm(\"" . Text::_('COM_ALFA_DELETE_MESSAGE') . "\")) {
//					return false;
//				}
//			}
//		", [], [], ["jquery"]);
//}
//?>