<?php

/**
 * One webhook's deliveries: each change the shop owed its address, newest first, with its status, what the address
 * answered and its tries. The Deliveries tab of the webhook's edit screen shows this list in a frame of its own
 * (tmpl=component, modal=1, webhook_id), where Joomla's status filter and pagination post this list's form.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$date = static fn (?string $at): string => (string) $at === '' ? '' : HTMLHelper::_('date', (string) $at, Text::_('DATE_FORMAT_LC6'));
$said = static fn (string $result): string => str_starts_with($result, 'COM_ALFA_') ? Text::_($result) : $result;
$tries = OutboundDelivery::RETRIES + 1;
$badges = [
    OutboundDelivery::STATE_PENDING => ['bg-info text-dark', 'COM_ALFA_WEBHOOK_DELIVERY_WAITING'],
    OutboundDelivery::STATE_SENT => ['bg-success', 'COM_ALFA_WEBHOOK_DELIVERY_SENT'],
    OutboundDelivery::STATE_DEAD => ['bg-danger', 'COM_ALFA_WEBHOOK_DELIVERY_DEAD'],
    OutboundDelivery::STATE_DISCARDED => ['bg-secondary', 'COM_ALFA_WEBHOOK_DELIVERY_DISCARDED'],
];
// The frame's links open in the admin itself, not inside the frame.
$tools = '<a href="' . Route::_('index.php?option=com_alfa&view=tasks&tab=outbound') . '" target="_top">' . Text::_('COM_ALFA_WEBHOOK_DELIVERIES_TOOLS') . '</a>';
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=webhookdeliveries&webhook_id=' . $this->webhookId
          . ($this->isModal ? '&tmpl=component&modal=1' : '')); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <p class="text-muted"><?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERIES_DESC', $tools); ?></p>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_(empty($this->activeFilters) ? 'COM_ALFA_WEBHOOK_DELIVERIES_NONE' : 'JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <div class="table-responsive">
                <table class="table table-striped" id="webhookDeliveriesList">
                    <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERIES_COL_CHANGE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERIES_COL_ANSWER'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERIES_COL_TRIES'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERIES_COL_QUEUED'); ?></th>
                        <th scope="col" class="w-3 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                        $state = (string) $item->state;
                        [$badge, $stateKey] = $badges[$state] ?? ['bg-light text-dark', ''];
                        $topic = (string) $item->topic;
                        $payload = array_pad(explode(':', (string) $item->payload, 2), 2, '');
                        $error = (string) $item->last_error;
                        $attempts = (int) $item->attempts;
                        $settled = match ($state) {
                            OutboundDelivery::STATE_SENT => (string) $item->sent_at,
                            OutboundDelivery::STATE_DEAD, OutboundDelivery::STATE_DISCARDED => (string) $item->dead_at,
                            default => '',
                        };
                        ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td>
                                <span class="badge <?php echo $badge; ?>"><?php echo $stateKey !== '' ? Text::_($stateKey) : $this->escape($state); ?></span>
                                <?php if ($settled !== '') : ?>
                                    <div class="small text-muted alfa-nowrap"><?php echo $date($settled); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo Text::_(OutboundTopics::label(topic: $topic)); ?></span>
                                <div class="small">
                                    <?php if (\in_array($topic, OutboundTopics::MERGED, true)) : ?>
                                        <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_PRODUCT', (int) $item->subject_id); ?><?php if ((int) $item->unit_id > 0) : ?>, <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_COMBINATION', (int) $item->unit_id); ?><?php endif; ?>
                                    <?php else : ?>
                                        <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_ORDER', (int) $item->subject_id); ?>
                                        <?php if ($topic === OutboundTopics::ORDER_STATUS && (string) $item->payload !== '') : ?>
                                            <span class="text-muted">, <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_STATUS_MOVE', $this->escape($payload[0]), $this->escape($payload[1])); ?></span>
                                        <?php elseif ($topic === OutboundTopics::ORDER_DISPUTE && (string) $item->payload !== '') : ?>
                                            <span class="text-muted">, <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_DISPUTE', $this->escape($payload[0]), $this->escape($payload[1])); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small text-break">
                                <?php if ($state === OutboundDelivery::STATE_SENT) : ?>
                                    <span class="badge bg-success"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERY_TAKEN'); ?></span>
                                <?php elseif ($error === '') : ?>
                                    <span class="text-muted"><?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERY_NOT_TRIED'); ?></span>
                                <?php elseif (preg_match('/^HTTP (\d{3})(?::\s*(.*))?$/s', $error, $answer) === 1) : ?>
                                    <span class="badge bg-warning text-dark">HTTP <?php echo (int) $answer[1]; ?></span>
                                    <?php if (($answer[2] ?? '') !== '') : ?>
                                        <div class="text-muted"><?php echo $this->escape($answer[2]); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="text-danger"><?php echo $this->escape($said($error)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small d-none d-md-table-cell">
                                <?php if ($attempts === 0 && $state === OutboundDelivery::STATE_SENT) : ?>
                                    <?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERY_FIRST_TRY'); ?>
                                <?php elseif ($attempts === 0 && $state === OutboundDelivery::STATE_PENDING) : ?>
                                    <?php echo Text::_('COM_ALFA_WEBHOOK_DELIVERY_DUE'); ?>
                                <?php else : ?>
                                    <?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_TRIES', $attempts, $tries); ?>
                                <?php endif; ?>
                                <?php if ($state === OutboundDelivery::STATE_PENDING && (string) $item->next_attempt_at !== '') : ?>
                                    <div class="text-muted alfa-nowrap"><?php echo Text::sprintf('COM_ALFA_WEBHOOK_DELIVERY_NEXT_AFTER', $date((string) $item->next_attempt_at)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small alfa-nowrap d-none d-md-table-cell"><?php echo $date((string) $item->created); ?></td>
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
