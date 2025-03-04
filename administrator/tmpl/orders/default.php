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
use \Joomla\CMS\Layout\LayoutHelper;
use \Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

HTMLHelper::_('bootstrap.tooltip');
HTMLHelper::_('behavior.multiselect');

// Import CSS
$wa =  $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user      = Factory::getApplication()->getIdentity();
$userId    = $user->get('id');
$listOrder = $this->state->get('list.ordering');
$listDirn  = $this->state->get('list.direction');
$canOrder  = $user->authorise('core.edit.state', 'com_alfa');

$saveOrder = $listOrder == 'a.ordering';

if (!empty($saveOrder))
{
	$saveOrderingUrl = 'index.php?option=com_alfa&task=orders.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
	HTMLHelper::_('draggablelist.draggable');
}

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=orders'); ?>" method="post"
	  name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
			<?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

				<div class="clearfix"></div>
				<table class="table table-striped" id="orderList">
					<thead>
					<tr>
						<th class="w-1 text-center">
							<input type="checkbox" autocomplete="off" class="form-check-input" name="checkall-toggle" value=""
								   title="<?php echo Text::_('JGLOBAL_CHECK_ALL'); ?>" onclick="Joomla.checkAll(this)"/>
						</th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Customer', 'user_name', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Original Price', 'a.original_price', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Shipping Tracking Number', 'a.shipping_tracking_number', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Date', 'a.created', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Payment', 'payment_method_name', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'Order Status', 'a.id_order_status', $listDirn, $listOrder); ?>
                        </th>

                    </tr>
					</thead>
					<tfoot>
					<tr>
						<td colspan="<?php echo isset($this->items[0]) ? count(get_object_vars($this->items[0])) : 10; ?>">
							<?php echo $this->pagination->getListFooter(); ?>
						</td>
					</tr>
					</tfoot>
					<tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
					<?php foreach ($this->items as $i => $item) :
						$ordering   = ($listOrder == 'a.ordering');
						$canCreate  = $user->authorise('core.create', 'com_alfa');
						$canEdit    = $user->authorise('core.edit', 'com_alfa');
						$canCheckin = $user->authorise('core.manage', 'com_alfa');
						$canChange  = $user->authorise('core.edit.state', 'com_alfa');
						?>
						<tr class="row<?php echo $i % 2; ?>" data-draggable-group='1' data-transition>
							<td class="text-center">
								<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
							</td>

							<td class="d-none d-lg-table-cell text-center">
								<?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
									<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'orders.', $canCheckin); ?>
								<?php endif; ?>

								<?php if ($canEdit) : ?>
									<a href="<?php echo Route::_('index.php?option=com_alfa&task=order.edit&id='.(int) $item->id); ?>">
									<?php echo $this->escape($item->id); ?>
									</a>
								<?php else : ?>
									<?php echo $this->escape($item->id); ?>
								<?php endif; ?>
							</td>

                            <td class="text-center">
                                <?php echo $item->user_name; ?>
                            </td>

                            <td class="text-center">
                                <?php echo $item->original_price; ?>
                            </td>

                            <td class="text-center">
                                <?php echo $item->shipping_tracking_number; ?>
                            </td>

                            <td class="text-center">
                            	<?php echo HtmlHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC6')); ?>
                            </td>

                            <td class="text-center text-nowrap">
                                <a style="color: <?php echo $item->payment_method_color; ?>;
                                          background-color: <?php echo $item->payment_method_bg_color; ?>;
                                          border-radius: 5px;
                                          text-decoration:none;
                                          padding: 5px 10px ;
                                                "><?php echo $item->payment_method_name; ?></a>
                            </td>

                            <td class="text-center">
                                <a style="color: <?php echo $this->orderStatuses[$item->id_order_status]->color; ?>;
                                          background-color: <?php echo $this->orderStatuses[$item->id_order_status]->bg_color; ?>;
                                          border-radius: 5px;
                                          text-decoration:none;
                                          padding: 5px 10px ;
                                                "><?php echo $this->orderStatuses[$item->id_order_status]->name; ?></a>
                            </td>




                        </tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<input type="hidden" name="task" value=""/>
				<input type="hidden" name="boxchecked" value="0"/>
				<input type="hidden" name="list[fullorder]" value="<?php echo $listOrder; ?> <?php echo $listDirn; ?>"/>
				<?php echo HTMLHelper::_('form.token'); ?>
			</div>
		</div>
	</div>
</form>