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
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user = $this->getCurrentUser();
$canChange = AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user);
$canEdit = AlfaHelper::can(section: 'documents', action: 'edit', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));

// Plugin-declared columns (onAlfaAdminListColumns) - END-only folding: every
// contributed column lands after the last core column, the table stays rectangular.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=documenttypes'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_DOCUMENTTYPES_REGISTRY_HINT'); ?>
                </div>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="documentTypesList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_DOCUMENTTYPE_FIELD_NAME', 'a.alias', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_HEADING_FAMILY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_HEADING_POOL'); ?></th>
                        <th scope="col" class="text-center"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_HEADING_PROPERTIES'); ?></th>
                        <th scope="col" class="text-center"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_HEADING_ORIGIN'); ?></th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->alias); ?>
                            </td>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'documenttypes.', $canChange); ?>
                            </td>
                            <td>
                                <?php if ($canEdit) : ?>
                                <a href="<?php echo Route::_('index.php?option=com_alfa&task=documenttype.edit&id=' . (int) $item->id); ?>">
                                    <strong><?php echo $this->escape($item->name !== null && $item->name !== '' ? $item->name : DocumentHelper::typeLabel((string) $item->alias)); ?></strong>
                                </a>
                                <?php else : ?>
                                    <strong><?php echo $this->escape($item->name !== null && $item->name !== '' ? $item->name : DocumentHelper::typeLabel((string) $item->alias)); ?></strong>
                                <?php endif; ?>
                                <div class="small text-muted"><code class="raw-value"><?php echo $this->escape($item->alias); ?></code></div>
                            </td>
                            <td>
                                <?php echo Text::_('COM_ALFA_DOCTYPE_FAMILY_' . strtoupper((string) $item->family)); ?>
                            </td>
                            <td>
                                <?php echo Text::_('COM_ALFA_DOCTYPE_POOL_' . strtoupper((string) $item->quantity_pool)); ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $item->shows_prices === 1) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_PRICES'); ?></span>
                                <?php endif; ?>
                                <?php if ((string) $item->movement_mode !== 'none') : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_MOVEMENT'); ?></span>
                                <?php endif; ?>
                                <?php if ((int) $item->requires_buyer_tax_id === 1) : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_TAX_ID'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $item->is_core === 1) : ?>
                                    <span class="badge bg-primary"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_CORE'); ?></span>
                                <?php else : ?>
                                    <span class="badge bg-info"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_PACKAGE'); ?></span>
                                    <?php if (!empty($item->provider)) : ?>
                                        <div class="small text-muted">
                                            <code class="raw-value"><?php echo $this->escape($item->provider); ?></code>
                                            <?php if (!empty($item->provider_version)) : ?>
                                                <?php echo $this->escape($item->provider_version); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
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
