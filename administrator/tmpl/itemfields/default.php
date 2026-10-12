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

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
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
    $saveOrderingUrl = 'index.php?option=com_alfa&task=itemfields.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=itemfields'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_("INFO"); ?></span>
                        <?php echo Text::_("JGLOBAL_NO_MATCHING_RESULTS"); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="itemfieldList">
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

                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>

                        <th class='left'>
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_ITEMFIELDS_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>

                        <th class="d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_FIELD_NAME'); ?>
                        </th>

                        <th class="d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_TYPE'); ?>
                        </th>

                        <th class="d-none d-lg-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_VISIBILITY'); ?>
                        </th>

                        <th class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_INFORMATIONAL'); ?>
                        </th>

                        <th class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_FILTERABLE'); ?>
                        </th>

                        <th class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMFIELDS_SEARCHABLE'); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>


                    </tr>
                    </thead>
                    <tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
                    <?php foreach ($this->items as $i => $item) :
                        $canEdit    = AlfaHelper::can(section: 'itemfields', action: 'edit', user: $user);
                        $canCheckin = $canEdit;
                        $canChange  = AlfaHelper::can(section: 'itemfields', action: 'edit.state', user: $user);
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
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'itemfields.', $canChange, 'cb'); ?>
                            </td>

                            <td>
                                <?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor ?? '', $item->checked_out_time, 'itemfields.', $canCheckin); ?>
                                <?php endif; ?>
                                <?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=itemfield.edit&id=' . (int) $item->id); ?>">
                                        <?php echo $this->escape($item->name); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo $this->escape($item->name); ?>
                                <?php endif; ?>

                                <?php // The items using this field, one click away: same filter contract
                                      // as the storefront facets, plus state=* so unpublished products are
                                      // not quietly missing from the answer. Brackets stay literal (Uri
                                      // passes the query through untouched) so the link reads and shares.
                                      // Deliberately an action, NOT a count: COUNT(DISTINCT item_id) over
                                      // the projection measures 4.4s on a 100k-item catalogue. ?>
                                <div class="mt-1">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?php echo Route::_('index.php?option=com_alfa&view=items'
                                           . '&filter[fields][' . (int) $item->id . ']=&filter[state]=*', false); ?>"
                                       title="<?php echo $this->escape(Text::_('COM_ALFA_ITEMFIELDS_SHOW_ITEMS_DESC')); ?>">
                                        <span class="alfa-icon" aria-hidden="true"
                                              style="--alfa-icon:url('<?php echo Uri::root(true); ?>/media/com_alfa/images/admin/menu/items.svg')"></span>
                                        <?php echo Text::_('COM_ALFA_ITEMFIELDS_SHOW_ITEMS'); ?>
                                    </a>
                                </div>
                            </td>

                            <td class="d-none d-md-table-cell">
                                <code><?php echo $this->escape($item->field_name); ?></code>
                            </td>

                            <td class="d-none d-md-table-cell">
                                <?php echo $this->escape($item->type); ?>
                            </td>

                            <td class="d-none d-lg-table-cell">
                                <?php $showonText = $this->showonSummaries[(int) $item->id] ?? ''; ?>
                                <?php if ($showonText !== '') : ?>
                                    <span class="text-info" title="<?php echo $this->escape($showonText); ?>">
                                        <span class="icon-eye" aria-hidden="true"></span>
                                        <?php echo $this->escape($showonText); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php if ((int) $item->informational === 1) : ?>
                                    <span class="icon-info-circle text-info" aria-hidden="true" title="<?php echo Text::_('COM_ALFA_ITEMFIELDS_INFORMATIONAL'); ?>"></span>
                                <?php else : ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php if ((int) $item->filterable === 1) : ?>
                                    <span class="icon-check text-success" aria-hidden="true"></span>
                                <?php else : ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php if ((int) $item->searchable === 1) : ?>
                                    <span class="icon-check text-success" aria-hidden="true"></span>
                                <?php else : ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

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
