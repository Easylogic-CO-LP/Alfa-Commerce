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
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Alfa\Component\Alfa\Administrator\Helper\Actions;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
   ->useScript('com_alfa.admin')
   ->useScript('table.columns');

$app       = Factory::getApplication();
$user      = $this->getCurrentUser();
$userId    = $user->id;
$canEdit   = AlfaHelper::can(section: 'users', action: 'edit', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
// No manual ordering here on purpose: #__alfa_users has no ordering column, the
// model keeps 'a.ordering' out of its filter_fields, and there is no saveOrderAjax
// task, so the drag-and-drop this used to arm could never fire.

// Plugin-declared columns (onAlfaAdminListColumns). END-only folding: every
// contributed column lands after the last core column, the table stays rectangular.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=users'); ?>"
      method="post"
      name="adminForm"
      id="adminForm">

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
                <table class="table table-striped" id="userList">
                    <thead>
                        <tr>
                            <th scope="col">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_HEADING_USERNAME', 'u.username', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="d-none d-md-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'JGLOBAL_EMAIL', 'u.email', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="d-none d-md-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_HEADING_NOTE', 'a.note', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="d-none d-lg-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_HEADING_LAST_VISIT', 'u.lastvisitDate', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="w-3 d-none d-lg-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                            </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                        </tr>
                    </thead>


                    <tbody>
                        <?php foreach ($this->items as $i => $item) :
                            $editLink = Route::_('index.php?option=com_alfa&task=user.edit&id=' . (int) $item->id);
                        ?>
                            <tr class="row<?php echo $i % 2; ?>">

                                <td>
                                    <?php if ($canEdit) : ?>
                                    <a href="<?php echo $editLink; ?>"
                                       title="<?php echo Text::_('JACTION_EDIT'); ?>">
                                        <?php echo $this->escape($item->username); ?>
                                    </a>
                                    <?php else : ?>
                                        <?php echo $this->escape($item->username); ?>
                                    <?php endif; ?>
                                </td>

                                <td class="d-none d-md-table-cell">
                                    <?php echo $this->escape($item->email); ?>
                                </td>

                                <td class="d-none d-md-table-cell">
                                    <?php echo $item->note !== ''
                                        ? $this->escape($item->note)
                                        : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td class="d-none d-lg-table-cell">
                                    <?php
                                    $nullDate = Factory::getContainer()
                                        ->get(\Joomla\Database\DatabaseInterface::class)
                                        ->getNullDate();

                                    echo ($item->lastvisitDate && $item->lastvisitDate !== $nullDate)
                                        ? HTMLHelper::_('date', $item->lastvisitDate, Text::_('DATE_FORMAT_LC4'))
                                        : Text::_('JNEVER');
                                    ?>
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
                <?php echo HTMLHelper::_('form.token'); ?>

            </div>
        </div>
    </div>

</form>