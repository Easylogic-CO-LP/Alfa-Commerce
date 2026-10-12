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
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;

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
    $saveOrderingUrl = 'index.php?option=com_alfa&task=formfields.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding


?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=formfields'); ?>" method="post"
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
                <table class="table table-striped" id="formfieldList">
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
                            <?php echo HTMLHelper::_('searchtools.sort',  'COM_ALFA_FORM_FIELDS_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>

                        <th class="d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_FORM_FIELDS_TYPE'); ?>
                        </th>

                        <th class="d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_FORM_FIELDS_GROUP'); ?>
                        </th>

                        <th class="text-center d-none d-md-table-cell" title="<?php echo $this->escape(Text::_('COM_ALFA_FORM_FIELDS_SHOWN_ON_DESC')); ?>">
                            <?php echo Text::_('COM_ALFA_FORM_FIELDS_SHOWN_ON'); ?>
                        </th>

                        <th class="d-none d-lg-table-cell">
                            <?php echo Text::_('COM_ALFA_SHOWON_LBL'); ?>
                        </th>

                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>



                    </tr>
                    </thead>
                    <tbody <?php if (!empty($saveOrder)) :?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" <?php endif; ?>>
                    <?php foreach ($this->items as $i => $item) :
                        $ordering   = ($listOrder == 'a.ordering');
                        $canEdit    = AlfaHelper::can(section: 'formfields', action: 'edit', user: $user);
                        $canCheckin = $canEdit;
                        $canChange  = AlfaHelper::can(section: 'formfields', action: 'edit.state', user: $user);
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
                                <?php if (!empty($item->is_system)) : ?>
                                    <?php // A padlock, not an inert tick: a system row is always
                                          // published, and the icon has to say so rather than look
                                          // like a switch that stopped working. ?>
                                    <span class="tbody-icon active" title="<?php echo $this->escape(Text::_('COM_ALFA_FORMFIELD_SYSTEM_ALWAYS_PUBLISHED')); ?>">
                                        <span class="icon-lock" aria-hidden="true"></span>
                                    </span>
                                    <span class="visually-hidden"><?php echo Text::_('COM_ALFA_FORMFIELD_SYSTEM_ALWAYS_PUBLISHED'); ?></span>
                                <?php else : ?>
                                    <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'formfields.', $canChange, 'cb'); ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
                                    <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->uEditor, $item->checked_out_time, 'formfields.', $canCheckin); ?>
                                <?php endif; ?>
                                <?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=formfield.edit&id='.(int) $item->id); ?>">
                                        <?php echo $this->escape($item->name); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo $this->escape($item->name); ?>
                                <?php endif; ?>
                                <?php // Says what the padlock means, and why this row has no Delete:
                                      // the product owns the definition, the merchant owns where it shows. ?>
                                <?php if (!empty($item->is_system)) : ?>
                                    <span class="badge bg-secondary ms-1" title="<?php echo $this->escape(Text::_('COM_ALFA_FORMFIELD_SYSTEM_BADGE_DESC')); ?>">
                                        <?php echo Text::_('COM_ALFA_FORMFIELD_SYSTEM_BADGE'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="d-none d-md-table-cell">
                                <?php echo $this->escape($item->type); ?>
                            </td>

                            <td class="d-none d-md-table-cell">
                                <?php if (!empty($item->group_title)) : ?>
                                    <?php echo $this->escape($item->group_title); ?>
                                    <?php if ((int) ($item->group_state ?? 1) === 0) : ?>
                                        <span class="badge bg-warning text-dark ms-1"><?php echo Text::_('COM_ALFA_FORM_FIELDS_GROUP_DISABLED'); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php
                                // WHERE THE FIELD IS ASKED, answered where the fields are listed.
                                // One click moves one section one step (hidden -> optional ->
                                // required -> hidden); a system row cannot fall below its floor,
                                // so those chips are shown locked instead of pretending to cycle.
                                $sections = [
                                    'registration' => 'COM_ALFA_FIELD_SECTION_REGISTRATION_SHORT',
                                    'shipping'     => 'COM_ALFA_FIELD_SECTION_DELIVERY_SHORT',
                                    'billing'      => 'COM_ALFA_FIELD_SECTION_INVOICE_SHORT',
                                ];
                                $levelNames = ['COM_ALFA_FORM_FIELD_LEVEL_HIDDEN', 'COM_ALFA_FORM_FIELD_LEVEL_OPTIONAL', 'COM_ALFA_FORM_FIELD_LEVEL_REQUIRED'];
                                $levelClass = ['btn-outline-secondary text-muted', 'btn-outline-primary', 'btn-primary'];
                                ?>
                                <div class="btn-group btn-group-sm" role="group">
                                    <?php foreach ($sections as $section => $shortLabel) : ?>
                                        <?php
                                        $level = max(0, min(2, (int) ($item->$section ?? 0)));
                                        $floor = !empty($item->is_system) && !empty($item->role)
                                            ? RoleRegistry::floor((string) $item->role, $section)
                                            : 0;
                                        $next = ($level + 1) % 3;
                                        $next = max($next, $floor);
                                        $locked = !$canChange || $floor >= 2;
                                        $title = Text::_($shortLabel) . ': ' . Text::_($levelNames[$level])
                                            . ($locked ? ' - ' . Text::_('COM_ALFA_FORMFIELD_SYSTEM_ALWAYS_REQUIRED') : ' - ' . Text::sprintf('COM_ALFA_FORMFIELD_VISIBILITY_CLICK', Text::_($levelNames[$next])));
                                        ?>
                                        <?php if ($locked) : ?>
                                            <?php // Not `.disabled`: that greys the chip, and grey already
                                                  // means "hidden" here: the two opposite ends would look
                                                  // alike. The colour keeps saying WHAT the state is, the
                                                  // padlock says it cannot be changed. A span is inert anyway. ?>
                                            <span class="btn btn-sm <?php echo $levelClass[$level]; ?>" aria-disabled="true" title="<?php echo $this->escape($title); ?>">
                                                <span class="icon-lock me-1" aria-hidden="true"></span><?php echo Text::_($shortLabel); ?>
                                            </span>
                                        <?php else : ?>
                                            <a class="btn btn-sm <?php echo $levelClass[$level]; ?>"
                                               title="<?php echo $this->escape($title); ?>"
                                               href="<?php echo Route::_('index.php?option=com_alfa&task=formfields.setVisibility&id=' . (int) $item->id . '&section=' . $section . '&level=' . $next . '&' . Session::getFormToken() . '=1'); ?>">
                                                <?php echo Text::_($shortLabel); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
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

                <template id="joomla-dialog-batch"><?php echo $this->loadTemplate('batch_body'); ?></template>
                <template id="joomla-dialog-delete"><?php echo $this->loadTemplate('delete_body'); ?></template>
            </div>
        </div>
    </div>
</form>
