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
    use Alfa\Component\Alfa\Administrator\Helper\Actions;
    use Alfa\Component\Alfa\Administrator\Helper\Columns;
    use Joomla\CMS\Layout\LayoutHelper;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Session\Session;

    $wa = $this->getDocument()->getWebAssetManager();
    $wa->useStyle('com_alfa.admin')
        ->useScript('com_alfa.admin')
        ->useScript('table.columns')
        ->usePreset('com_alfa.toast')
        ->useStyle('com_alfa.multilingual-fields')
        ->useStyle('com_alfa.admin.inline-edit')
        ->useScript('com_alfa.admin.inline-edit');

    // Only JS-side strings need registering: the injected pencil's label and a
    // generic fallback for load/network errors. Save success/failure text comes
    // back from the controller in the JSON response and is shown via notify().
    Text::script('COM_ALFA_INLINE_EDIT');
    Text::script('COM_ALFA_INLINE_LOAD_FAILED');

    $app       = Factory::getApplication();
    $user      = $this->getCurrentUser();
    $userId    = $user->id;
    $listOrder = $this->escape($this->state->get('list.ordering'));
    $listDirn  = $this->escape($this->state->get('list.direction'));
    $orderName = 'a.ordering';
    $saveOrder = $listOrder == $orderName;

    if ($saveOrder && !empty($this->items)) {
        $saveOrderingUrl = 'index.php?option=com_alfa&task=categories.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
        HTMLHelper::_('draggablelist.draggable');
    }

    // Plugin-declared columns (onAlfaAdminListColumns) - END-only folding: every
    // contributed column lands after the last core column, the table stays rectangular.
    $columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=categories'); ?>"
      method="post"
      name="adminForm"
      id="adminForm"
>
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                	<div class="alert alert-info">
                		<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo \Joomla\CMS\Language\Text::_("INFO"); ?></span>
                		<?php echo \Joomla\CMS\Language\Text::_("JGLOBAL_NO_MATCHING_RESULTS"); ?>
                	</div>
                <?php else : ?>
                <table class="table table-striped" id="categoryList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center">
                            <?php echo HTMLHelper::_('grid.checkall'); ?>
                        </th>

                        <?php if (isset($this->items[0]->ordering)): ?>
                            <th scope="col" class="w-1 text-center d-none d-md-table-cell">

                                <?php echo HTMLHelper::_('searchtools.sort', '', $orderName, $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-sort'); ?>

                            </th>
                        <?php endif; ?>


                        <th  scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>

                        <th class='left'>
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_CATEGORIES_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_CATEGORIES_ITEM_COUNT'); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell" >

                            <?php echo HTMLHelper::_('searchtools.sort',  'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>					</th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
                    <?php foreach ($this->items as $i => $item) :
                        $ordering   = ($listOrder == 'a.ordering');
                        $canEdit    = AlfaHelper::can(section: 'categories', action: 'edit', user: $user);
                        $canCheckin = $canEdit;
                        $canChange  = AlfaHelper::can(section: 'categories', action: 'edit.state', user: $user);
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
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'categories.', $canChange, 'cb'); ?>
                            </td>

                            <td>
                                <?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'categories.', $canCheckin); ?>
                                <?php endif; ?>
                                <?php // The depth prefix stays OUTSIDE the marker: inline-edit.js
                                      // replaces the marker's text after a save. The row is a flex
                                      // container because the marker itself is a full-width flex
                                      // box; as a bare sibling the prefix would sit on its own line. ?>
                                <div class="alfa-tree-row">
                                    <?php if ((int) $item->depth > 0) : ?>
                                        <span class="alfa-tree-prefix" aria-hidden="true"><?php echo str_repeat('- ', (int) $item->depth); ?></span>
                                    <?php endif; ?>
                                <?php echo LayoutHelper::render('inline.field', [
                                    'view'     => 'category',
                                    'element'  => 'name',
                                    'id'       => (int) $item->id,
                                    'type'     => 'multilang',
                                    'display'  => $item->name,
                                    'editHref' => Route::_('index.php?option=com_alfa&task=category.edit&id=' . (int) $item->id, false),
                                    'canEdit'  => $canEdit,
                                ]); ?>
                                </div>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php $itemCount = (int) ($item->item_count ?? 0); ?>
                                <?php if ($itemCount > 0) : ?>
                                    <?php // Presenting a `filter` array REPLACES the remembered filter node
                                          // wholesale, so search/state/manufacturer cannot stack with this.
                                          // limitstart is remembered separately and must be pinned to page 1. ?>
                                    <a class="badge bg-secondary text-decoration-none"
                                       href="<?php echo Route::_('index.php?option=com_alfa&view=items&filter[category][]=' . (int) $item->id . '&limitstart=0'); ?>"
                                       title="<?php echo $this->escape(Text::_('COM_ALFA_CATEGORIES_ITEM_COUNT_VIEW')); ?>"><?php echo $itemCount; ?></a>
                                <?php else : ?>
                                    <span class="text-muted"
                                          title="<?php echo $this->escape(Text::_('COM_ALFA_CATEGORIES_ITEM_COUNT_EMPTY')); ?>">&ndash;</span>
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

                <?php // Load the batch processing form. Must stay inside #adminForm so the
                      // cid[] checkboxes post with it. Only for users who may run it (edit). ?>
                <?php if (AlfaHelper::can(section: 'categories', action: 'edit', user: $user)) : ?>
                <template id="joomla-dialog-batch"><?php echo $this->loadTemplate('batch_body'); ?></template>
                <?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>
            </div>
        </div>
    </div>
</form>