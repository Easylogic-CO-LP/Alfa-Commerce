<?php

/**
 * The Webhooks list: each webhook's address and topics, whether it delivers, what waits for it and what was given up,
 * its last delivery with its result, and its test.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

$user = $this->getCurrentUser();
$canEdit = AlfaHelper::can(section: 'webhooks', action: 'edit', user: $user);
$canChange = AlfaHelper::can(section: 'webhooks', action: 'edit.state', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$said = static fn (string $result): string => str_starts_with($result, 'COM_ALFA_') ? Text::_($result) : $result;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=webhooks'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <p class="text-muted"><?php echo Text::_('COM_ALFA_WEBHOOKS_DESC'); ?></p>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_WEBHOOKS_NONE'); ?>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                <table class="table table-striped" id="webhooksList">
                    <thead>
                    <tr>
                        <th class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_WEBHOOK_FIELD_NAME', 'a.name', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_FIELD_TOPICS'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_WAITING'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_DEAD'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_LAST_DELIVERY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_WEBHOOK_TEST'); ?></th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->name); ?>
                            </td>
                            <td class="text-center">
                                <?php echo HTMLHelper::_('jgrid.state', [
                                    1 => ['unpublish', 'COM_ALFA_WEBHOOK_STATE_ENABLED', 'COM_ALFA_WEBHOOKS_TOGGLE_PAUSE', 'COM_ALFA_WEBHOOK_STATE_ENABLED', true, 'publish', 'publish'],
                                    0 => ['publish', 'COM_ALFA_WEBHOOK_STATE_PAUSED', 'COM_ALFA_WEBHOOKS_TOGGLE_ENABLE', 'COM_ALFA_WEBHOOK_STATE_PAUSED', true, 'unpublish', 'unpublish'],
                                ], $item->state, $i, 'webhooks.', $canChange); ?>
                            </td>
                            <td>
                                <?php if ($canEdit) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=webhook.edit&id=' . (int) $item->id); ?>"><strong><?php echo $this->escape($item->name); ?></strong></a>
                                <?php else : ?>
                                    <strong><?php echo $this->escape($item->name); ?></strong>
                                <?php endif; ?>
                                <div class="small text-muted text-break"><?php echo $this->escape($item->url); ?></div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php foreach (OutboundTopics::parse(topics: (string) $item->topics) as $topic) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_(OutboundTopics::label(topic: $topic)); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end"><?php echo (int) $item->waiting; ?></td>
                            <td class="text-end">
                                <?php if ((int) $item->dead > 0) : ?>
                                    <span class="badge bg-danger"><?php echo (int) $item->dead; ?></span>
                                <?php else : ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <?php if (!empty($item->last_attempt_at)) : ?>
                                    <span class="nowrap"><?php echo HTMLHelper::_('date', (string) $item->last_attempt_at, Text::_('DATE_FORMAT_LC6')); ?></span>
                                    <div class="<?php echo (int) $item->last_ok === 1 ? 'text-success' : 'text-danger'; ?> text-break"><?php echo $this->escape($said((string) $item->last_result)); ?></div>
                                <?php else : ?>
                                    <?php echo Text::_('COM_ALFA_WEBHOOK_NEVER'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canEdit) : ?>
                                    <button type="submit" form="webhook-test-<?php echo (int) $item->id; ?>" class="btn btn-sm btn-secondary"><?php echo Text::_('COM_ALFA_WEBHOOK_TEST_SEND'); ?></button>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell"><?php echo (int) $item->id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>

<?php // Each webhook's test is a form of its own, outside the list's form; its button above names it. ?>
<?php if ($canEdit) : ?>
    <?php foreach ($this->items as $item) : ?>
        <form id="webhook-test-<?php echo (int) $item->id; ?>" method="post" action="<?php echo Route::_('index.php?option=com_alfa&task=webhook.test'); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endforeach; ?>
<?php endif; ?>
