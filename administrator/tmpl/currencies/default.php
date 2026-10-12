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
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Actions;

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
	$saveOrderingUrl = 'index.php?option=com_alfa&task=currencies.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
	HTMLHelper::_('draggablelist.draggable');
}

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=currencies'); ?>" method="post"
	  name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
			<?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

				<div class="clearfix"></div>
				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo \Joomla\CMS\Language\Text::_("INFO"); ?></span>
						<?php echo \Joomla\CMS\Language\Text::_("JGLOBAL_NO_MATCHING_RESULTS"); ?>
					</div>
				<?php else : ?>
<?php
// Plugin-contributed columns, grouped by the core column they anchor after. Empty when no
// alfa* platform plugin contributed any, in which case every helper below emits nothing.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], ['state', 'name', 'code', 'id']);
?><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>				<table class="table table-striped" id="currencyList">

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
					</th><?php echo Columns::heads($columnsByAnchor, 'state'); ?>
						
						<th class='left'>
							<?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_CURRENCIES_NAME', 'a.name', $listDirn, $listOrder); ?>
						</th><?php echo Columns::heads($columnsByAnchor, 'name'); ?>
						<th class='left'>
							<?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_CURRENCIES_CODE', 'a.code', $listDirn, $listOrder); ?>
						</th><?php echo Columns::heads($columnsByAnchor, 'code'); ?>
						
					<th scope="col" class="w-3 d-none d-lg-table-cell" >

						<?php echo HTMLHelper::_('searchtools.sort',  'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>					</th><?php echo Columns::heads($columnsByAnchor, 'id'); ?><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>
					</tr>
					</thead>
					<tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
					<?php foreach ($this->items as $i => $item) :
						$ordering   = ($listOrder == 'a.ordering');
						$canEdit    = AlfaHelper::can(section: 'currencies', action: 'edit', user: $user);
						$canCheckin = $canEdit; // check-in is part of editing
						$canChange  = AlfaHelper::can(section: 'currencies', action: 'edit.state', user: $user);
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
								<?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'currencies.', $canChange, 'cb'); ?>
							</td><?php echo Columns::cells($columnsByAnchor, 'state', $item); ?>
							
							<td>
								<?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
									<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'currencies.', $canCheckin); ?>
								<?php endif; ?>
								<?php if ($canEdit) : ?>
									<a href="<?php echo Route::_('index.php?option=com_alfa&task=currency.edit&id='.(int) $item->id); ?>">
									<?php echo $this->escape($item->name); ?>
									</a>
								<?php else : ?>
												<?php echo $this->escape($item->name); ?>
								<?php endif; ?>
							</td><?php echo Columns::cells($columnsByAnchor, 'name', $item); ?>
							<td>
								<?php echo $item->code; ?>
							</td><?php echo Columns::cells($columnsByAnchor, 'code', $item); ?>
							
							<td class="d-none d-lg-table-cell">
							<?php echo $item->id; ?>

							</td><?php echo Columns::cells($columnsByAnchor, 'id', $item); ?><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>


						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php echo $this->pagination->getListFooter(); ?>
				<?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>
			</div>
		</div>
	</div>
</form>