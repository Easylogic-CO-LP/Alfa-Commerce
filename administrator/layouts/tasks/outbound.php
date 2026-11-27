<?php

/**
 * The Background screen's Outgoing notifications tab: the consumers of the shop's changes (webhooks and plugins) and what each is owed: what waits, what was
 * given up after the last retry or refused, and the last delivery with its answer. Staff put the changes given up back
 * in the queue (Retry), discard them (they are never sent and stop being counted), and pause or resume a consumer
 * (paused, nothing new is queued for it and nothing waiting is sent or lost). Every action is a plain form POST with
 * the form token, answered by OutboundController.
 *
 * Available $displayData:
 *   - rows       (array) OutboundConsumers::cardRows()
 *   - unreadable (bool)  the consumers could not be read: one line instead of the list
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$rows = (array) ($displayData['rows'] ?? []);
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$said = static fn (string $result): string => str_starts_with($result, 'COM_ALFA_') ? Text::_($result) : $result;
$form = static function (string $task, int $id, string $label, string $class): string {
    return '<form method="post" class="d-inline-block me-1 mb-1" action="' . Route::_('index.php?option=com_alfa&task=outbound.' . $task) . '">'
        . '<button type="submit" class="btn btn-sm ' . $class . '">' . Text::_($label) . '</button>'
        . '<input type="hidden" name="id" value="' . $id . '">' . HTMLHelper::_('form.token') . '</form>';
};
?>
<div class="card mb-3" data-alfa-outbound-card>
    <div class="card-header"><?php echo Text::_('COM_ALFA_OUTBOUND_CARD_TITLE'); ?></div>
    <div class="card-body">
        <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_OUTBOUND_CARD_DESC'); ?></p>
        <?php if (!empty($displayData['unreadable'])) : ?>
            <div class="alert alert-warning mb-0"><span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_OUTBOUND_UNREADABLE'); ?></div>
        <?php elseif ($rows === []) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_OUTBOUND_NONE'); ?></p>
        <?php else : ?>
            <div class="table-responsive">
            <table class="table table-sm align-top alfa-fold">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_CONSUMER'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_FIELD_TOPICS'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_WAITING'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_DEAD'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_LAST_DELIVERY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_ACTIONS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $id = (int) $row['id']; ?>
                    <tr>
                        <td>
                            <strong><?php echo $e($row['name']); ?></strong>
                            <?php if ((int) $row['state'] !== 1) : ?>
                                <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_OUTBOUND_PAUSED'); ?></span>
                            <?php endif; ?>
                            <div class="small text-muted">
                                <?php echo $row['kind'] === OutboundConsumers::KIND_PLUGIN
                                    ? Text::sprintf('COM_ALFA_OUTBOUND_KIND_PLUGIN', $e($row['plugin']))
                                    : Text::sprintf('COM_ALFA_OUTBOUND_KIND_WEBHOOK', $e($row['host'])); ?>
                            </div>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?php foreach ($row['topics'] as $topic) : ?>
                                <span class="badge bg-light text-dark"><?php echo Text::_(OutboundTopics::label(topic: (string) $topic)); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end"><?php echo (int) $row['waiting']; ?></td>
                        <td class="text-end">
                            <?php if ((int) $row['dead'] > 0) : ?>
                                <span class="badge bg-danger"><?php echo (int) $row['dead']; ?></span>
                            <?php else : ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell small">
                            <?php if ($row['lastAttemptAt'] !== '') : ?>
                                <span class="alfa-nowrap"><?php echo HTMLHelper::_('date', (string) $row['lastAttemptAt'], Text::_('DATE_FORMAT_LC6')); ?></span>
                                <div class="<?php echo $row['lastOk'] === true ? 'text-success' : 'text-danger'; ?> text-break"><?php echo $e($said((string) $row['lastResult'])); ?></div>
                            <?php else : ?>
                                <?php echo Text::_('COM_ALFA_WEBHOOK_NEVER'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $row['dead'] > 0) : ?>
                                <?php echo $form('retry', $id, 'COM_ALFA_OUTBOUND_RETRY', 'btn-warning'); ?>
                                <?php echo $form('discard', $id, 'COM_ALFA_OUTBOUND_DISCARD', 'btn-outline-secondary'); ?>
                            <?php endif; ?>
                            <?php echo (int) $row['state'] === 1
                                ? $form('pause', $id, 'COM_ALFA_OUTBOUND_PAUSE', 'btn-outline-secondary')
                                : $form('resume', $id, 'COM_ALFA_OUTBOUND_RESUME', 'btn-secondary'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>
