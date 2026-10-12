<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user = $this->getCurrentUser();
$canEdit = AlfaHelper::can(section: 'locations', action: 'edit', user: $user);
$canChange = AlfaHelper::can(section: 'locations', action: 'edit.state', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=locations'); ?>" method="post"
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
                <table class="table table-striped" id="locationsList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_LOCATION_FIELD_TITLE', 'a.title', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_LOCATION_FIELD_TYPE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_LOCATION_FIELD_CAPABILITIES'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_LOCATION_HEADING_UNITS'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_LOCATION_HEADING_PHYSICAL'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_LOCATION_HEADING_RESERVED'); ?></th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->title); ?>
                            </td>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'locations.', $canChange); ?>
                            </td>
                            <td>
                                <?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=location.edit&id=' . (int) $item->id); ?>">
                                        <strong><?php echo $this->escape($item->title); ?></strong>
                                    </a>
                                <?php else : ?>
                                    <strong><?php echo $this->escape($item->title); ?></strong>
                                <?php endif; ?>
                                <?php if ((int) $item->is_default === 1) : ?>
                                    <span class="badge bg-primary ms-1"><?php echo Text::_('COM_ALFA_LOCATION_BADGE_DEFAULT'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item->name) && $item->name !== $item->title) : ?>
                                    <div class="small text-muted"><?php echo $this->escape($item->name); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item->kind)) : ?>
                                    <div class="small text-muted"><?php echo Text::_('COM_ALFA_LOCATION_KIND_' . strtoupper((string) $item->kind === 'pickup_point' ? 'pickup' : (string) $item->kind)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo Text::_('COM_ALFA_LOCATION_TYPE_' . strtoupper((string) $item->location_type)); ?>
                            </td>
                            <td>
                                <?php foreach (array_filter(explode(',', (string) $item->capabilities)) as $capability) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_LOCATION_CAP_' . strtoupper($capability)); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end"><?php echo (int) $item->stock_units; ?></td>
                            <td class="text-end"><?php echo rtrim(rtrim(number_format((float) $item->stock_physical, 2, '.', ''), '0'), '.'); ?></td>
                            <td class="text-end"><?php echo rtrim(rtrim(number_format((float) $item->stock_reserved, 2, '.', ''), '0'), '.'); ?></td>
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
