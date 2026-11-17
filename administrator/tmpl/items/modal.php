<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The products list in a modal, to pick one product (2.2.2): the field Modal_Item opens it (the product form's Related
 * tab). The list's own search, filters and ordering, kept apart from the products screen's (modal=1). A product's name
 * picks it through Joomla's modal-content-select script, as Joomla's own article picker does; every state is listed,
 * since a product may be linked while it is unpublished.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Items\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$this->getDocument()->getWebAssetManager()
    ->useScript('core')
    ->useScript('modal-content-select');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$iconStates = [-2 => 'icon-trash', 0 => 'icon-unpublish', 1 => 'icon-publish', 2 => 'icon-archive'];
?>
<div class="container-popup">
    <form action="<?php echo Route::_('index.php?option=com_alfa&view=items&layout=modal&tmpl=component&modal=1&' . Session::getFormToken() . '=1'); ?>"
          method="post" name="adminForm" id="adminForm">

        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
            </div>
        <?php else : ?>
            <table class="table table-sm">
                <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_ITEMS_SELECT_A_PRODUCT'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_ITEMS_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="d-none d-md-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_ITEMS_SKU', 'a.sku', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-1 d-none d-md-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                        // The name the field shows once picked: the product's name, else its SKU, else its number.
                        $title = trim((string) ($item->name ?? '')) ?: (trim((string) ($item->sku ?? '')) ?: '#' . (int) $item->id);
                        $attributes = 'data-content-select data-content-type="com_alfa.item"'
                            . ' data-id="' . (int) $item->id . '"'
                            . ' data-title="' . $this->escape($title) . '"';
                        ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <span class="tbody-icon">
                                    <span class="<?php echo $iconStates[(int) $item->state] ?? 'icon-unpublish'; ?>" aria-hidden="true"></span>
                                </span>
                            </td>
                            <th scope="row">
                                <a class="select-link" href="javascript:void(0)" <?php echo $attributes; ?>>
                                    <?php echo $this->escape($title); ?>
                                </a>
                                <?php if (!empty($item->categories)) : ?>
                                    <div class="small">
                                        <?php echo Text::_('COM_ALFA_ITEMS_CATEGORIES') . ': ' . $this->escape(implode(', ', array_column($item->categories, 'name'))); ?>
                                    </div>
                                <?php endif; ?>
                            </th>
                            <td class="small d-none d-md-table-cell"><?php echo $this->escape((string) ($item->sku ?? '')); ?></td>
                            <td class="small d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>
