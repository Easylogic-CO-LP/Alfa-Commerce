<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart rules list: ONE entity for coupons (code) and automatic rules
 * (codeless); the Activation filter separates the two presets.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user      = $this->getCurrentUser();
$canChange = AlfaHelper::can(section: 'cartrules', action: 'edit.state', user: $user);
$canEdit   = AlfaHelper::can(section: 'cartrules', action: 'edit', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));

$classLabels = [
    'product' => 'COM_ALFA_CARTRULE_CLASS_PRODUCT',
    'order' => 'COM_ALFA_CARTRULE_CLASS_ORDER',
    'shipping' => 'COM_ALFA_CARTRULE_CLASS_SHIPPING',
];

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], ['priority']);   // END-only folding
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=cartrules'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>


				<?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
						<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
				<?php else : ?>
                    <table class="table table-striped" id="cartruleList">
                        <thead>
                        <tr>
                            <th class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                            <th class="w-1 text-center">
								<?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                            </th>
                            <th class="left">
								<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTRULE_NAME_LBL', 'name', $listDirn, $listOrder); ?>
                            </th>
                            <th class="left">
								<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTRULE_CODE_LBL', 'a.code', $listDirn, $listOrder); ?>
                            </th>
                            <th class="left">
								<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTRULE_CLASS_LBL', 'a.discount_class', $listDirn, $listOrder); ?>
                            </th>
                            <th class="left"><?php echo Text::_('COM_ALFA_CARTRULE_ACTION_LBL'); ?></th>
                            <th class="w-5 text-center"><?php echo Text::_('COM_ALFA_CARTRULE_USED_LBL'); ?></th>
                            <th class="w-3 text-center">
								<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTRULE_PRIORITY_LBL', 'a.priority', $listDirn, $listOrder); ?>
                            </th>
                            <?php // A plugin column that asked for 'priority' lands here, before the id. ?>
                            <?php echo Columns::heads($columnsByAnchor, 'priority'); ?>
                            <th class="w-3 d-none d-lg-table-cell">
								<?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                            </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                        </tr>
                        </thead>
                        <tbody>
						<?php foreach ($this->items as $i => $item) : ?>
                            <tr>
                                <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>

                                <td class="text-center">
									<?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'cartrules.', $canChange, 'cb'); ?>
                                </td>

                                <td>
									<?php if (isset($item->checked_out) && $item->checked_out) : ?>
										<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor ?? '', $item->checked_out_time, 'cartrules.', $canEdit); ?>
									<?php endif; ?>
									<?php if ($canEdit) : ?>
                                        <a href="<?php echo Route::_('index.php?option=com_alfa&task=cartrule.edit&id=' . (int) $item->id); ?>">
											<?php echo $this->escape($item->name); ?>
                                        </a>
									<?php else : ?>
										<?php echo $this->escape($item->name); ?>
									<?php endif; ?>
									<?php if ((int) $item->highlight === 1) : ?>
                                        <span class="badge bg-info"><?php echo Text::_('COM_ALFA_CARTRULE_HIGHLIGHT_LBL'); ?></span>
									<?php endif; ?>
                                </td>

                                <td>
									<?php if ($item->code !== null && $item->code !== '') : ?>
                                        <span class="badge bg-primary text-mono"><?php echo $this->escape($item->code); ?></span>
									<?php else : ?>
                                        <span class="text-muted"><?php echo Text::_('COM_ALFA_CARTRULES_AUTOMATIC'); ?></span>
									<?php endif; ?>
                                </td>

                                <td><?php echo Text::_($classLabels[$item->discount_class] ?? $item->discount_class); ?></td>

                                <td>
									<?php // A fixed amount is money: the figure the engine takes, at the currency's
									      // scale. A percentage by the one percentage rule. ?>
									<?php if ($item->action === 'amount') : ?>
										<?php echo $this->escape(Money::of($item->action_value, $this->amountCurrency)->format()); ?>
									<?php else : ?>
										<?php echo $this->escape(AlfaHelper::percentDisplay(value: $item->action_value)); ?>
									<?php endif; ?>
									<?php // Free shipping is derived, never stored: a shipping rule that
                                          // takes the whole cost IS free shipping.
									if ($item->discount_class === 'shipping' && $item->action === 'percent'
										&& (float) $item->action_value >= 100) : ?>
                                        <span class="badge bg-success"><?php echo Text::_('COM_ALFA_CARTRULE_ACTION_FREE_SHIPPING'); ?></span>
									<?php endif; ?>
                                </td>

                                <td class="text-center">
									<?php
                                    $used = (int) ($item->used_count ?? 0);
                                    $limit = (int) $item->usage_limit_total;
									?>
									<?php if ($limit > 0) : ?>
                                        <span class="badge <?php echo $used >= $limit ? 'bg-danger' : 'bg-secondary'; ?>">
                                            <?php echo $used; ?> / <?php echo $limit; ?>
                                        </span>
									<?php elseif ($used > 0) : ?>
                                        <span class="badge bg-secondary"><?php echo $used; ?></span>
									<?php else : ?>
                                        <span class="text-muted">-</span>
									<?php endif; ?>
                                </td>

                                <td class="text-center"><?php echo (int) $item->priority; ?></td>

                                <?php echo Columns::cells($columnsByAnchor, 'priority', $item); ?>
                                <td class="d-none d-lg-table-cell"><?php echo (int) $item->id; ?></td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>

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

	<?php echo HTMLHelper::_('form.token'); ?>
</form>
