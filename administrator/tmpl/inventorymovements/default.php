<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));

// The ledger is append-only: rows render read-only by design: no
// checkboxes, no tasks, no edit routes. Corrections are opposite
// movements written by the inventory service.

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=inventorymovements'
          . ($this->isModal ? '&tmpl=component&modal=1' : '')); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_INVENTORYMOVEMENTS_LEDGER_HINT'); ?>
                </div>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="inventoryMovementsList">
                    <thead>
                    <tr>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JDATE', 'a.created', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_ITEM'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_LOCATION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_TYPE'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_QTY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_SOURCE'); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_OPERATION'); ?></th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr>
                            <td class="text-nowrap"><?php echo HTMLHelper::_('date', $item->created, 'Y-m-d H:i'); ?></td>
                            <td>
                                <strong><?php echo $this->escape($item->name ?: ('#' . (int) $item->item_id)); ?></strong>
                                <?php if (!empty($item->item_sku)) : ?>
                                    <div class="small text-muted"><code><?php echo $this->escape($item->item_sku); ?></code></div>
                                <?php endif; ?>
                                <?php if ((int) $item->combination_id > 0) : ?>
                                    <div class="small text-muted"><?php echo Text::sprintf('COM_ALFA_INVENTORY_COMBINATION_N', (int) $item->combination_id); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $this->escape($item->location_title ?: ('#' . (int) $item->location_id)); ?></td>
                            <td>
                                <span class="badge <?php echo (float) $item->qty_change >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo Text::_('COM_ALFA_INVENTORY_MOVE_' . strtoupper((string) $item->movement_type)); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php $qty = (float) $item->qty_change; ?>
                                <strong><?php echo ($qty > 0 ? '+' : '') . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'); ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($item->source_type)) : ?>
                                    <?php echo $this->escape($item->source_type); ?><?php echo $item->source_id ? ' #' . (int) $item->source_id : ''; ?>
                                    <?php if (!empty($item->source_line_id)) : ?>
                                        <span class="small text-muted"><?php echo Text::sprintf('COM_ALFA_INVENTORY_LINE_N', (int) $item->source_line_id); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($item->note)) : ?>
                                    <div class="small text-muted"><?php echo $this->escape($item->note); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <?php if (!empty($item->operation_id)) : ?>
                                    <code class="small"><?php echo $this->escape($item->operation_id); ?></code>
                                <?php endif; ?>
                                <?php if (!empty($item->correlation_id)) : ?>
                                    <div class="small text-muted"><code><?php echo $this->escape($item->correlation_id); ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell"><?php echo (int) $item->id; ?></td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
