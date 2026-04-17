<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

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
    $saveOrderingUrl = 'index.php?option=com_alfa&task=users.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=users'); ?>"
      method="post"
      name="adminForm"
      id="adminForm">

    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">

                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="clearfix"></div>

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
                            </th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <td colspan="5">
                                <?php echo $this->pagination->getListFooter(); ?>
                            </td>
                        </tr>
                    </tfoot>

                    <tbody>
                        <?php foreach ($this->items as $i => $item) :
                            $editLink = Route::_('index.php?option=com_alfa&task=user.edit&id=' . (int) $item->id);
                        ?>
                            <tr class="row<?php echo $i % 2; ?>">

                                <td>
                                    <a href="<?php echo $editLink; ?>"
                                       title="<?php echo Text::_('JACTION_EDIT'); ?>">
                                        <?php echo $this->escape($item->username); ?>
                                    </a>
                                </td>

                                <td class="d-none d-md-table-cell">
                                    <?php echo $this->escape($item->email); ?>
                                </td>

                                <td class="d-none d-md-table-cell">
                                    <?php echo $item->note !== ''
                                        ? $this->escape($item->note)
                                        : '<span class="text-muted">—</span>'; ?>
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
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->filterForm->renderControlFields(); ?>
                <?php echo HTMLHelper::_('form.token'); ?>

            </div>
        </div>
    </div>

</form>