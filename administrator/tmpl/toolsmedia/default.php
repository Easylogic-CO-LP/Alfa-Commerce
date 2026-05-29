<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \Alfa\Component\Alfa\Administrator\View\Toolsmedia\HtmlView $this */

$isFiles   = $this->mode === 'files';
$status    = (string) $this->state->get('filter.status', '');
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));

// State-driven note describing exactly what the current listing shows.
if ($isFiles) {
    $note = Text::_('COM_ALFA_TOOLSMEDIA_NOTE_FILES');
} elseif ($status === 'orphan') {
    $note = Text::_('COM_ALFA_TOOLSMEDIA_NOTE_ORPHAN');
} elseif ($status === 'missing') {
    $note = Text::_('COM_ALFA_TOOLSMEDIA_NOTE_MISSING');
} else {
    $note = Text::_('COM_ALFA_TOOLSMEDIA_NOTE_ALL');
}

$colspan = $isFiles ? 5 : 7;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=toolsmedia'); ?>"
      method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="alert <?php echo $isFiles ? 'alert-warning' : 'alert-info'; ?>" role="alert">
                    <?php echo $note; ?>
                </div>

                <div class="clearfix"></div>
                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_ALFA_TOOLSMEDIA_NO_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="toolsmediaList">
                    <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_TITLE_TOOLS_MEDIA'); ?></caption>
                    <thead>
                    <tr>
                        <td class="w-1 text-center">
                            <?php echo HTMLHelper::_('grid.checkall'); ?>
                        </td>
                        <th scope="col" class="w-5 text-center"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_COL_PREVIEW'); ?></th>
                        <?php if ($isFiles) : ?>
                            <th scope="col">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_TOOLSMEDIA_COL_PATH', 'path', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="w-10"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_COL_SIZE'); ?></th>
                            <th scope="col" class="w-15"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_COL_MODIFIED'); ?></th>
                        <?php else : ?>
                            <th scope="col">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_TOOLSMEDIA_COL_ORIGIN', 'a.origin', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="w-5">
                                <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_TOOLSMEDIA_COL_ITEM_ID', 'a.item_id', $listDirn, $listOrder); ?>
                            </th>
                            <th scope="col" class="w-15"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_COL_STATUS'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_COL_PATH'); ?></th>
                            <th scope="col" class="w-3 d-none d-lg-table-cell">
                                <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                            </th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tfoot>
                    <tr>
                        <td colspan="<?php echo $colspan; ?>">
                            <?php echo $this->pagination->getListFooter(); ?>
                        </td>
                    </tr>
                    </tfoot>
                    <tbody>
                    <?php if ($isFiles) : ?>
                        <?php foreach ($this->items as $i => $file) : ?>
                            <?php $isImage = (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|svg|bmp)$/i', $file->path); ?>
                            <tr class="row<?php echo $i % 2; ?>">
                                <td class="text-center">
                                    <input type="checkbox" id="cb<?php echo $i; ?>" name="cid[]"
                                           value="<?php echo $this->escape($file->path); ?>"
                                           onclick="Joomla.isChecked(this.checked);">
                                </td>
                                <td class="text-center">
                                    <?php if ($isImage) : ?>
                                        <img src="<?php echo $this->escape($file->url); ?>" alt=""
                                             style="max-width:48px;max-height:48px;object-fit:cover;">
                                    <?php else : ?>
                                        <span class="icon-file" aria-hidden="true"></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo $this->escape($file->path); ?></code></td>
                                <td><?php echo HTMLHelper::_('number.bytes', $file->size); ?></td>
                                <td><?php echo HTMLHelper::_('date', date('c', $file->mtime), Text::_('DATE_FORMAT_LC4')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php foreach ($this->items as $i => $row) : ?>
                            <tr class="row<?php echo $i % 2; ?>">
                                <td class="text-center">
                                    <?php echo HTMLHelper::_('grid.id', $i, $row->id); ?>
                                </td>
                                <td class="text-center">
                                    <?php $preview = $row->thumbnail_url ?: $row->url; ?>
                                    <?php if (!$row->file_missing && $preview !== '') : ?>
                                        <img src="<?php echo $this->escape($preview); ?>" alt=""
                                             style="max-width:48px;max-height:48px;object-fit:cover;">
                                    <?php else : ?>
                                        <span class="icon-image" aria-hidden="true"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $this->escape((string) $row->origin); ?></td>
                                <td><?php echo (int) $row->item_id; ?></td>
                                <td>
                                    <?php if ($row->is_orphan) : ?>
                                        <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_BADGE_ORPHAN'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($row->file_missing) : ?>
                                        <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_BADGE_MISSING'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!$row->is_orphan && !$row->file_missing) : ?>
                                        <span class="badge bg-success"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_BADGE_OK'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo $this->escape((string) $row->path); ?></code></td>
                                <td class="d-none d-lg-table-cell"><?php echo (int) $row->id; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <template id="joomla-dialog-delete"><?php echo $this->loadTemplate('delete_body'); ?></template>
                <?php if (!$isFiles) : ?>
                    <template id="joomla-dialog-delete-orphans"><?php echo $this->loadTemplate('delete_orphans_body'); ?></template>
                    <template id="joomla-dialog-delete-missing"><?php echo $this->loadTemplate('delete_missing_body'); ?></template>
                <?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>
            </div>
        </div>
    </div>
</form>
