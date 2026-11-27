<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns')
    ->usePreset('com_alfa.toast');

$user = $this->getCurrentUser();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$saveOrder = $listOrder == 'a.ordering';

if ($saveOrder && !empty($this->items)) {
    $saveOrderingUrl = 'index.php?option=com_alfa&task=labels.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}

// Plugin-declared columns (onAlfaAdminListColumns), folded after the last core column.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], ['item_count']);
$canEdit = AlfaHelper::can(section: 'labels', action: 'edit', user: $user);
$canChange = AlfaHelper::can(section: 'labels', action: 'edit.state', user: $user);
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=labels'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="labelList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center">
                            <?php echo HTMLHelper::_('grid.checkall'); ?>
                        </th>
                        <th scope="col" class="w-1 text-center d-none d-md-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', '', 'a.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-menu-2'); ?>
                        </th>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_LABELS_NAME', 'name', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_LABELS_SHOWN_ON'); ?>
                        </th>
                        <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_LABELS_FILTERABLE', 'a.filterable', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_LABELS_ITEM_COUNT'); ?>
                        </th>
                        <?php echo Columns::heads($columnsByAnchor, 'item_count'); ?>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>
                    </tr>
                    </thead>
                    <tbody <?php if (!empty($saveOrder)) : ?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                            // The badge as the storefront draws it: its own colours, else the dark badge.
                            $swatch = ((string) $item->bg !== '' ? 'background:' . $item->bg . ';' : '') . ((string) $item->color !== '' ? 'color:' . $item->color . ';' : '');
                            $places = [];

                            if ((int) $item->list_show === 1) {
                                $places[] = Text::_('COM_ALFA_LABELS_ON_CARDS');
                            }

                            if ((int) $item->item_show === 1) {
                                $places[] = Text::_('COM_ALFA_LABELS_ON_PAGE');
                            }
                        ?>
                        <tr class="row<?php echo $i % 2; ?>" data-draggable-group="1" data-transition>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
                            </td>
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
                                    <input type="text" name="order[]" size="5" value="<?php echo (int) $item->ordering; ?>" class="width-20 text-area-order hidden">
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'labels.', $canChange, 'cb', $item->publish_up, $item->publish_down); ?>
                            </td>
                            <td>
                                <?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'labels.', $canEdit); ?>
                                <?php endif; ?>
                                <span class="badge rounded-0<?php echo $swatch === '' ? ' text-bg-dark' : ''; ?>"<?php echo $swatch !== '' ? ' style="' . $this->escape($swatch) . '"' : ''; ?>><?php echo $this->escape((string) $item->name); ?></span>
                                <?php if ($canEdit) : ?>
                                    <a class="ms-2" href="<?php echo Route::_('index.php?option=com_alfa&task=label.edit&id=' . (int) $item->id); ?>"
                                       title="<?php echo Text::_('JACTION_EDIT'); ?> <?php echo $this->escape((string) $item->name); ?>"><?php echo Text::_('JACTION_EDIT'); ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php echo $places !== [] ? $this->escape(implode(', ', $places)) : '<span class="text-muted">' . Text::_('COM_ALFA_LABELS_NOWHERE') . '</span>'; ?>
                            </td>
                            <td class="text-center d-none d-md-table-cell">
                                <?php if ((int) $item->filterable === 1) : ?>
                                    <span class="icon-check" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('JYES'); ?></span>
                                <?php else : ?>
                                    <span class="text-muted"><?php echo Text::_('JNO'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center d-none d-md-table-cell">
                                <?php $itemCount = (int) ($item->item_count ?? 0); ?>
                                <?php if ($itemCount > 0) : ?>
                                    <a class="badge bg-secondary text-decoration-none"
                                       href="<?php echo Route::_('index.php?option=com_alfa&view=items&filter[label][]=' . (int) $item->id . '&limitstart=0'); ?>"
                                       title="<?php echo $this->escape(Text::_('COM_ALFA_LABELS_ITEM_COUNT_VIEW')); ?>"><?php echo $itemCount; ?></a>
                                <?php else : ?>
                                    <span class="text-muted" title="<?php echo $this->escape(Text::_('COM_ALFA_LABELS_ITEM_COUNT_EMPTY')); ?>">0</span>
                                <?php endif; ?>
                            </td>
                            <?php echo Columns::cells($columnsByAnchor, 'item_count', $item); ?>
                            <td class="d-none d-lg-table-cell">
                                <?php echo (int) $item->id; ?>
                            </td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>
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
