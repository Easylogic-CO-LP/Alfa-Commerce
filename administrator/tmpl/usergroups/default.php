<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;

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
                <table class="table table-striped" id="usergroupList">
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
                        </th>
                    </tr>
                    </thead>
                    <tfoot>
                    <tr>
                        <td colspan="6"> <?php echo $this->pagination->getListFooter(); ?>
                        </td>
                    </tr>
                    </tfoot>
                    <tbody>
					<?php foreach ($this->items as $i => $item) :
						$canEdit = $user->authorise('core.edit', 'com_alfa');
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
                                <span class="badge bg-light text-dark border"><?php echo (int) $item->usergroup_id; ?></span>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
								<?php echo $item->prices_enable ? Text::_('JYES') : Text::_('JNO'); ?>
                            </td>

                            <td class="d-none d-lg-table-cell">
								<?php echo $item->id; ?>
                            </td>
                        </tr>
					<?php endforeach; ?>
                    </tbody>
                </table>
				<?php echo $this->filterForm->renderControlFields(); ?>
            </div>
        </div>
    </div>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>