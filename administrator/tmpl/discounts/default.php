<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$app       = Factory::getApplication();
$user      = $this->getCurrentUser();
$userId    = $user->id;
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$orderName = 'a.ordering';
$saveOrder = $listOrder == $orderName;

if ($saveOrder && !empty($this->items)) {
	$saveOrderingUrl = 'index.php?option=com_alfa&task=discounts.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
	HTMLHelper::_('draggablelist.draggable');
}

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=discounts'); ?>" method="post"
	  name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
			<?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

				<div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
						<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
					</div>
				<?php else : ?>
				<div class="table-responsive">
				<table class="table table-striped" id="discountList">
					<thead>
					<tr>
						<th class="w-1 text-center">
                            <?php echo HTMLHelper::_('grid.checkall'); ?>
						</th>
						
					<?php if (isset($this->items[0]->ordering)): ?>
					<th scope="col" class="w-1 text-center d-none d-md-table-cell">

					<?php echo HTMLHelper::_('searchtools.sort', '', 'a.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-menu-2'); ?>

					</th>
					<?php endif; ?>



						
					<th  scope="col" class="w-1 text-center">
						<?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
					</th>

                    <th class='left'>
                        <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_DISCOUNTS_NAME', 'name', $listDirn, $listOrder); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_DISCOUNTS_SHOW_TAG', 'a.tag_list_show', $listDirn, $listOrder); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_DISCOUNTS_BEHAVIOR', 'a.behavior', $listDirn, $listOrder); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_DISCOUNTS_VALUE', 'a.value', $listDirn, $listOrder); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_DISCOUNTS_APPLY_BEFORE_TAX', 'a.apply_before_tax', $listDirn, $listOrder); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo Text::_('COM_ALFA_DISCOUNTS_CATEGORIES'); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo Text::_('COM_ALFA_DISCOUNTS_MANUFACTURERS'); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo Text::_('COM_ALFA_DISCOUNTS_PLACES'); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo Text::_('COM_ALFA_DISCOUNTS_USERGROUPS'); ?>
                    </th>

                    <th scope="col" class="left">
                        <?php echo Text::_('COM_ALFA_DISCOUNTS_USERS'); ?>
                    </th>
						
					<th scope="col" class="w-3 d-none d-lg-table-cell" >

						<?php echo HTMLHelper::_('searchtools.sort',  'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>					</th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

					</tr>
					</thead>
					<tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
					<?php foreach ($this->items as $i => $item) :
						$ordering   = ($listOrder == 'a.ordering');
						$canEdit    = AlfaHelper::can(section: 'discounts', action: 'edit', user: $user);
						$canCheckin = $canEdit; // check-in is part of editing
						$canChange  = AlfaHelper::can(section: 'discounts', action: 'edit.state', user: $user);
						?>
						<tr class="row<?php echo $i % 2; ?>" data-draggable-group='1' data-transition>
							<td class="text-center">
								<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
							</td>

                            <?php if (isset($this->items[0]->ordering)) : ?>
                                <td class="text-center d-none d-md-table-cell">
                                    <?php
                                    $iconClass = '';
                                    if (!$canChange) {
                                        $iconClass = ' inactive';
                                    } elseif (!$saveOrder) {
                                        $iconClass = ' inactive" title="' . Text::_('JORDERINGDISABLED');
                                    }
                                    ?>
                                    <span class="sortable-handler<?php echo $iconClass ?>">
                                    <span class="icon-ellipsis-v" aria-hidden="true"></span>
                                </span>
                                    <?php if ($canChange && $saveOrder) : ?>
                                        <input type="text" name="order[]" size="5" value="<?php echo $item->ordering; ?>" class="width-20 text-area-order hidden">
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

							
							<td class="text-center">
								<?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'discounts.', $canChange, 'cb'); ?>
							</td>
							
							<td>
								<?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
									<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'discounts.', $canCheckin); ?>
								<?php endif; ?>
								<?php if ($canEdit) : ?>
									<a href="<?php echo Route::_('index.php?option=com_alfa&task=discount.edit&id='.(int) $item->id); ?>">
									<?php echo $this->escape($item->name); ?>
									</a>
								<?php else : ?>
												<?php echo $this->escape($item->name); ?>
								<?php endif; ?>
							</td>

                            <td>
                                <?php
                                    $tagContexts = [];
                                    if ($item->tag_list_show) { $tagContexts[] = Text::_('COM_ALFA_DISCOUNTS_TAG_CARD'); }
                                    if ($item->tag_item_show) { $tagContexts[] = Text::_('COM_ALFA_DISCOUNTS_TAG_PAGE'); }
                                    echo $tagContexts ? implode(', ', $tagContexts) : '-';
                                ?>
                            </td>

                            <td>
                                <?php if($item->behavior == 0)
                                        echo Text::_('COM_ALFA_TAX_BEHAVIOR_ONLY_THIS_DISCOUNT');
                                    else if($item->behavior == 1)
                                        echo Text::_('COM_ALFA_TAX_BEHAVIOR_COMBINED');
                                    else
                                        echo Text::_('COM_ALFA_TAX_BEHAVIOR_ONE_AFTER_ANOTHER');
                                ?>
                            </td>

                            <td>
                                <?php // A fixed amount is a stored per-unit figure in the shop's currency (the
                                      // stored branch of the one unit rule); a percentage by the one percentage rule. ?>
                                <?php echo $this->escape($item->is_amount
                                    ? $this->amountCurrency->formatStored(value: $item->value)
                                    : AlfaHelper::percentDisplay(value: $item->value)); ?>
                            </td>

                            <td>
                                <?php echo $item->apply_before_tax ? Text::_('JYES') : Text::_('JNO');?>
                            </td>

                            <td>
                                <?php
                                    echo $this->escape(implode(', ', array_column($item->categories, 'name')));
                                ?>
                            </td>

                            <td>
                                <?php
                                    echo $this->escape(implode(', ', array_column($item->manufacturers, 'name')));
                                ?>
                            </td>

                            <td>
                                <?php
                                    echo $this->escape(implode(', ', array_column($item->places, 'name')));
                                ?>
                            </td>

                            <td>
                                <?php
                                    // Joomla core usergroups expose `title`, not `name`.
                                    echo $this->escape(implode(', ', array_column($item->usergroups, 'title')));
                                ?>
                            </td>

                            <td>
                                <?php
                                    echo $this->escape(implode(', ', array_column($item->users, 'name')));
                                ?>
                            </td>

							
							<td class="d-none d-lg-table-cell">
							    <?php echo $item->id; ?>
							</td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>


						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<?php echo $this->pagination->getListFooter(); ?>
				<?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>
			</div>
		</div>
	</div>
</form>