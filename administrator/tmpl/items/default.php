<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     */

    // No direct access
    defined('_JEXEC') or die;

    use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
    use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
    use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
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
        $saveOrderingUrl = 'index.php?option=com_alfa&task=items.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
        HTMLHelper::_('draggablelist.draggable');
    }

    // Plugin-declared columns (onAlfaAdminListColumns). END-only folding: every
    // contributed column lands after the last core column, the table stays rectangular.
    $columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], ['available']);

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=items'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                	<div class="alert alert-info">
                		<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo \Joomla\CMS\Language\Text::_("INFO"); ?></span>
                		<?php echo \Joomla\CMS\Language\Text::_("JGLOBAL_NO_MATCHING_RESULTS"); ?>
                	</div>
                <?php else : ?>
                <table class="table table-striped" id="itemList">
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


                        <th  scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>

                        <th class='left'>
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_ITEMS_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="left d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMS_CATEGORIES'); ?>
                        </th>
                        <th scope="col" class="left d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_ITEMS_MANUFACTURERS'); ?>
                        </th>
                        <th class='left'>
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_ITEMS_SKU', 'a.sku', $listDirn, $listOrder); ?>
                        </th>
                        <th class='left'>
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_ITEMS_STOCK', 'a.stock', $listDirn, $listOrder); ?>
                        </th>
                        <th class="left" title="<?php echo $this->escape(Text::_('COM_ALFA_ITEMS_AVAILABLE_HINT')); ?>">
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_INVENTORY_HEADING_AVAILABLE', 'a.available_quantity', $listDirn, $listOrder); ?>
                        </th>

                        <?php // A plugin column that asked for 'available' lands here, before the id. ?>
                        <?php echo Columns::heads($columnsByAnchor, 'available'); ?>
                        <th scope="col" class="w-3 d-none d-lg-table-cell" >

                            <?php echo HTMLHelper::_('searchtools.sort',  'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>					</th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
                    <?php foreach ($this->items as $i => $item) :
                        $ordering   = ($listOrder == 'a.ordering');
                        $canEdit    = AlfaHelper::can(section: 'items', action: 'edit', user: $user);
                        $canCheckin = $canEdit;
                        $canChange  = AlfaHelper::can(section: 'items', action: 'edit.state', user: $user);
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
                                <?php // An archived product leaves the archive only through the
                                      // Restore to active catalogue action, never through a
                                      // state toggle, so its state is shown without one. ?>
                                <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'items.', $canChange && CombinationState::isCurrent(state: $item->state), 'cb'); ?>
                            </td>

                            <td>
                                <?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'items.', $canCheckin); ?>
                                <?php endif; ?>
                                <?php echo LayoutHelper::render('inline.field', [
                                    'view'     => 'item',
                                    'element'  => 'name',
                                    'id'       => (int) $item->id,
                                    'type'     => 'multilang',
                                    'display'  => $item->name,
                                    'editHref' => Route::_('index.php?option=com_alfa&task=item.edit&id=' . (int) $item->id, false),
                                    'canEdit'  => $canEdit,
                                ]); ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php echo !empty($item->categories)
                                    ? $this->escape(implode(', ', array_column($item->categories, 'name')))
                                    : '<span class="text-muted">&ndash;</span>'; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php echo !empty($item->manufacturers)
                                    ? $this->escape(implode(', ', array_column($item->manufacturers, 'name')))
                                    : '<span class="text-muted">&ndash;</span>'; ?>
                            </td>
                            <?php
                                $hasVariants = (int) ($item->combination_count ?? 0) > 0;
                                // A display of the stock projection the inventory service
                                // maintains; sellability is judged from the stock records.
                                $stockShown  = InventoryAvailabilityService::formatQuantity((float) ($item->stock ?? 0));
                            ?>
                            <td>
                                <?php if ($hasVariants) : ?>
                                    <?php
                                        // Variants own their SKU, so the item value is never what
                                        // ships on an order line. A dash here would read as "nobody
                                        // filled this in"; the variant count says where the real
                                        // codes live, mirroring the Stock column's "per variant".
                                        $variantCount = (int) $item->combination_count;
                                    ?>
                                    <span class="alfa-cell text-muted"
                                          title="<?php echo $this->escape(Text::_('COM_ALFA_INLINE_SKU_VARIANT_OWNED')); ?>">
                                        <?php if ((string) $item->sku !== '') : ?>
                                            <span class="alfa-cell-value"><?php echo $this->escape($item->sku); ?></span>
                                        <?php endif; ?>
                                        <span class="alfa-cell-meta"><?php echo Text::plural('COM_ALFA_ITEMS_SKU_VARIANT_COUNT', $variantCount); ?></span>
                                    </span>
                                <?php else : ?>
                                    <?php echo LayoutHelper::render('inline.field', [
                                        'view'    => 'item',
                                        'element' => 'sku',
                                        'id'      => (int) $item->id,
                                        'type'    => 'text',
                                        'display' => (string) $item->sku,
                                        'canEdit' => $canEdit,
                                    ]); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Variants BEFORE manage_stock: a variant product with the flag
                                      // off is stocked per variant, not untracked. No visible
                                      // qualifier: the SKU cell on this row already says
                                      // "N variants", and the location breakdown is what the editor
                                      // opens with. The tooltip and the absent pencil carry it.
                                      // A tracked product whose stock is not edited inline (an
                                      // archived one) shows its figure without the pencil. ?>
                                <?php if ($hasVariants) : ?>
                                    <span class="text-muted" title="<?php echo $this->escape(Text::_('COM_ALFA_ITEMS_STOCK_PER_VARIANT_HINT')); ?>">
                                        <?php echo $this->escape($stockShown); ?>
                                    </span>
                                <?php elseif ((int) ($item->manage_stock ?? 0) !== 1) : ?>
                                    <span class="text-muted"><?php echo Text::_('COM_ALFA_ITEMS_STOCK_NOT_TRACKED'); ?></span>
                                <?php else : ?>
                                    <?php echo LayoutHelper::render('inline.field', [
                                        'view'    => 'item',
                                        'element' => 'stock',
                                        'id'      => (int) $item->id,
                                        'type'    => 'locations',
                                        'display' => $stockShown,
                                        'canEdit' => $canEdit && !empty($item->stock_inline_editable),
                                    ]); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Salable = physical - reserved, the projection maintained by
                                      // the inventory kernel; the badge also reads the stock-record
                                      // check (ledger_missing, which includes an open integrity
                                      // hold) and the smallest sale the product's quantity rules
                                      // accept. A product with combinations is tracked when any
                                      // combination tracks stock, and its quantity rules apply per
                                      // combination. Shared layout: an inline stock save re-renders
                                      // this same file so the two can never disagree. ?>
                                <?php echo LayoutHelper::render('inventory.availability', [
                                    'id'            => (int) $item->id,
                                    'manageStock'   => $hasVariants
                                        ? (int) $item->tracked_units > 0
                                        : (int) ($item->manage_stock ?? 0) === 1,
                                    'hasVariants'   => $hasVariants,
                                    'available'     => (float) ($item->available_quantity ?? 0),
                                    'ledgerMissing' => (bool) $item->ledger_missing,
                                    'integrityHold' => false,
                                    'saleMinimum'   => $hasVariants
                                        ? 1
                                        : CartQuantityPolicy::saleMinimum(
                                            min:  CartQuantityPolicy::ruleValue(combination: null, item: $item->quantity_min),
                                            step: CartQuantityPolicy::ruleValue(combination: null, item: $item->quantity_step),
                                            max:  CartQuantityPolicy::ruleValue(combination: null, item: $item->quantity_max),
                                        ),
                                    // The EFFECTIVE threshold: a product on
                                    // "Use Global" has one, and the raw column
                                    // alone never badged it as low.
                                    'stockLow'      => InventoryAvailabilityService::lowStockThreshold(
                                        combination: null,
                                        item: $item->stock_low === null ? null : (float) $item->stock_low,
                                    ) ?: null,
                                    'minQty'        => (float) ($item->min_qty ?? 0),
                                    'backorder'     => (string) ($item->backorder ?? ''),
                                ]); ?>
                            </td>

                            <?php echo Columns::cells($columnsByAnchor, 'available', $item); ?>
                            <td class="d-none d-lg-table-cell">
                                <?php echo $item->id; ?>

                            </td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>



                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>


                <?php // Load the batch processing form, only for users who may run it (item.batch needs edit). ?>
                <?php if (AlfaHelper::can(section: 'items', action: 'edit', user: $user)) : ?>
                <template id="joomla-dialog-batch"><?php echo $this->loadTemplate('batch_body'); ?></template>
                <?php endif; ?>


                <?php echo $this->filterForm->renderControlFields(); ?>
            </div>
        </div>
    </div>
</form>