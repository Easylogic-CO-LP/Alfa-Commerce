<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user      = $this->getCurrentUser();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$orderName = 'a.ordering';
$saveOrder = $listOrder == $orderName;

if ($saveOrder && !empty($this->items)) {
    $saveOrderingUrl = 'index.php?option=com_alfa&task=formfieldgroups.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=formfieldgroups'); ?>"
      method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="clearfix"></div>

                <?php if (empty($this->items)): ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_FORMFIELDGROUPS_EMPTY'); ?>
                    </div>
                <?php else: ?>
                <table class="table table-striped" id="formfieldgroupList">
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
                            <th class="left">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_FORMFIELDGROUPS_TITLE', 'a.title', $listDirn, $listOrder); ?>
                            </th>
                            <th class="w-10 text-center d-none d-md-table-cell">
                                <?php echo Text::_('COM_ALFA_FORMFIELDGROUPS_FIELD_COUNT'); ?>
                            </th>
                            <th class="w-5 d-none d-lg-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                            </th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <td colspan="6">
                                <?php echo $this->pagination->getListFooter(); ?>
                            </td>
                        </tr>
                    </tfoot>
                    <tbody <?php if (!empty($saveOrder)): ?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>"<?php endif; ?>>
                        <?php foreach ($this->items as $i => $item):
                            $canEdit   = $user->authorise('core.edit',       'com_alfa');
                            $canChange = $user->authorise('core.edit.state', 'com_alfa');
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
                                    <span class="sortable-handler<?php echo $iconClass; ?>">
                                        <span class="icon-ellipsis-v" aria-hidden="true"></span>
                                    </span>
                                    <?php if ($canChange && $saveOrder): ?>
                                        <input type="text" name="order[]" size="5" value="<?php echo (int) $item->ordering; ?>" class="width-20 text-area-order hidden">
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'formfieldgroups.', $canChange, 'cb'); ?>
                                </td>

                                <td>
                                    <?php if ($canEdit): ?>
                                        <a href="<?php echo Route::_('index.php?option=com_alfa&task=formfieldgroup.edit&id=' . (int) $item->id); ?>">
                                            <?php echo $this->escape($item->title); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $this->escape($item->title); ?>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center d-none d-md-table-cell">
                                    <?php
                                    $count = (int) ($item->field_count ?? 0);
                                    if ($count > 0):
                                        $filterUrl = Route::_(
                                            'index.php?option=com_alfa&view=formfields'
                                            . '&filter[group_id]=' . (int) $item->id
                                        );
                                        ?>
                                        <a href="<?php echo $filterUrl; ?>" class="badge bg-secondary text-decoration-none"
                                           title="<?php echo Text::_('COM_ALFA_FORMFIELDGROUPS_FIELD_COUNT_VIEW'); ?>">
                                            <?php echo $count; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark"><?php echo $count; ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="d-none d-lg-table-cell">
                                    <?php echo (int) $item->id; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>
            </div>
        </div>
    </div>
</form>
