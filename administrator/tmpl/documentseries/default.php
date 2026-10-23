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

<form action="<?php echo Route::_('index.php?option=com_alfa&view=documentseries'); ?>" method="post"
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
                <?php // Nine columns are wider than the main column at 1280 px with the menu open: the
                      // list scrolls in its own box, never the page. ?>
                <div class="table-responsive">
                <table class="table table-striped" id="seriesList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_SERIES_FIELD_DOC_TYPE', 'a.doc_type', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_SERIES_FIELD_CODE', 'a.code', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_SERIES_FIELD_PREFIX'); ?></th>
                        <th scope="col" class="text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_SERIES_FIELD_NEXT_NUMBER', 'a.next_number', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="text-center"><?php echo Text::_('COM_ALFA_SERIES_HEADING_DOCUMENTS'); ?></th>
                        <th scope="col" class="text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_SERIES_FIELD_IS_DEFAULT', 'a.is_default', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->code); ?>
                            </td>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'documentseries.', $canChange); ?>
                            </td>
                            <td>
                                <?php echo DocumentHelper::typeLabel((string) $item->doc_type); ?>
                            </td>
                            <td>
                                <?php if ($canEdit) : ?>
                                <a href="<?php echo Route::_('index.php?option=com_alfa&task=series.edit&id=' . (int) $item->id); ?>">
                                    <strong><?php echo $this->escape($item->code); ?></strong>
                                </a>
                                <?php else : ?>
                                    <strong><?php echo $this->escape($item->code); ?></strong>
                                <?php endif; ?>
                                <?php if (!empty($item->title)) : ?>
                                    <div class="small text-muted"><?php echo $this->escape($item->title); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="raw-value"><?php echo $this->escape($item->prefix); ?></code>
                                <?php if ((int) $item->year_scoped === 1) : ?>
                                    <span class="badge bg-info"><?php echo Text::_('COM_ALFA_SERIES_BADGE_YEARLY'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo (int) $item->next_number; ?></td>
                            <td class="text-center">
                                <?php // The number is what has been issued; the padlock is whether the
                                      // counter is locked, which any document (draft included) does. ?>
                                <?php if ((int) $item->references_count > 0) : ?>
                                    <span class="badge bg-secondary" title="<?php echo Text::_('COM_ALFA_SERIES_COUNTER_LOCKED_HINT'); ?>">
                                        <?php echo (int) $item->documents_count; ?> <span class="icon-lock" aria-hidden="true"></span>
                                    </span>
                                <?php else : ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $item->is_default === 1) : ?>
                                    <span class="icon-featured" aria-hidden="true"></span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell"><?php echo (int) $item->id; ?></td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
