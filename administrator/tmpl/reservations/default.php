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

$stateClasses = [
    'active' => 'bg-success',
    'partial' => 'bg-info',
    'fulfilled' => 'bg-secondary',
    'released' => 'bg-warning text-dark',
    'expired' => 'bg-danger',
    'cancelled' => 'bg-dark',
];

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=reservations'); ?>" method="post"
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
                <table class="table table-striped" id="reservationsList">
                    <thead>
                    <tr>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JDATE', 'a.created', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_ITEM'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_LOCATION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_SOURCE'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_RESERVATION_HEADING_QUANTITIES'); ?></th>
                        <th scope="col" class="text-center"><?php echo Text::_('JSTATUS'); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_RESERVATION_HEADING_EXPIRES', 'a.expires_at', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                        $remainder = max(0, (float) $item->quantity - (float) $item->fulfilled_quantity - (float) $item->released_quantity);
                        // PARTIAL is derived, never stored: an active row that
                        // has begun fulfilment but still holds a remainder.
                        $displayState = (string) $item->state;

                        if ($displayState === 'active' && (float) $item->fulfilled_quantity > 0 && $remainder > 0) {
                            $displayState = 'partial';
                        }
                        ?>
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
                                <?php echo $this->escape($item->source_type); ?><?php echo $item->source_id ? ' #' . (int) $item->source_id : ''; ?>
                                <?php if (!empty($item->source_line_id)) : ?>
                                    <span class="small text-muted"><?php echo Text::sprintf('COM_ALFA_INVENTORY_LINE_N', (int) $item->source_line_id); ?></span>
                                <?php endif; ?>
                                <?php if ((int) $item->priority !== 0) : ?>
                                    <div class="small text-muted"><?php echo Text::sprintf('COM_ALFA_RESERVATION_PRIORITY_N', (int) $item->priority); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong><?php echo rtrim(rtrim(number_format($remainder, 2, '.', ''), '0'), '.'); ?></strong>
                                <div class="small text-muted">
                                    <?php echo Text::sprintf(
                                        'COM_ALFA_RESERVATION_QUANTITIES_DETAIL',
                                        rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                                        rtrim(rtrim(number_format((float) $item->fulfilled_quantity, 2, '.', ''), '0'), '.'),
                                        rtrim(rtrim(number_format((float) $item->released_quantity, 2, '.', ''), '0'), '.'),
                                    ); ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo $stateClasses[$displayState] ?? 'bg-secondary'; ?>">
                                    <?php echo Text::_('COM_ALFA_RESERVATION_STATE_' . strtoupper($displayState)); ?>
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell text-nowrap">
                                <?php echo $item->expires_at ? HTMLHelper::_('date', $item->expires_at, 'Y-m-d H:i') : '-'; ?>
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
