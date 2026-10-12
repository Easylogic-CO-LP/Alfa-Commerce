<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

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
	->useScript('com_alfa.admin');

$user      = Factory::getApplication()->getIdentity();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));

?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=usergroups'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>

                <div class="clearfix"></div>
                <?php if (empty($this->items)) : ?>
                	<div class="alert alert-info">
                		<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo \Joomla\CMS\Language\Text::_("INFO"); ?></span>
                		<?php echo \Joomla\CMS\Language\Text::_("JGLOBAL_NO_MATCHING_RESULTS"); ?>
                	</div>
                <?php else : ?>
<?php
// Plugin-contributed columns. No anchors are declared for this grid yet, so every
// contribution folds into Columns::END and renders after the last core column.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);
?><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>                <table class="table table-striped" id="usergroupList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center">
							<?php echo HTMLHelper::_('grid.checkall'); ?>
                        </th>
                        <th class='left'>
							<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_USERGROUPS_NAME', 'ug.title', $listDirn, $listOrder); ?>
                        </th>
                        <th class="text-center d-none d-md-table-cell">
							<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_HEADING_USERGROUP_ID', 'a.usergroup_id', $listDirn, $listOrder); ?>
                        </th>
                        <th class="text-center d-none d-md-table-cell">
							<?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_HEADING_PRICES_ENABLE', 'a.prices_enable', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell" >
							<?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>
                    </tr>
                    </thead>
                    <tbody>
					<?php foreach ($this->items as $i => $item) :
						$canEdit = AlfaHelper::can(section: 'usergroups', action: 'edit', user: $user);
						?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
								<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
                            </td>

                            <td>
								<?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=usergroup.edit&id='.(int) $item->id); ?>">
										<?php echo $this->escape($item->core_title); ?>
                                    </a>
								<?php else : ?>
									<?php echo $this->escape($item->core_title); ?>
								<?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <span class="badge bg-body-tertiary text-body-emphasis border"><?php echo (int) $item->usergroup_id; ?></span>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
								<?php echo $item->prices_enable ? Text::_('JYES') : Text::_('JNO'); ?>
                            </td>

                            <td class="d-none d-lg-table-cell">
								<?php echo $item->id; ?>
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