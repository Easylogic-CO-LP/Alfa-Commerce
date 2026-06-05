<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$user = $this->getCurrentUser();

$severityLabels = [
    'success' => 'COM_ALFA_NOTIFY_SEVERITY_SUCCESS',
    'info'    => 'COM_ALFA_NOTIFY_SEVERITY_INFO',
    'warning' => 'COM_ALFA_NOTIFY_SEVERITY_WARNING',
    'danger'  => 'COM_ALFA_NOTIFY_SEVERITY_DANGER',
];
?>
<form action="<?php echo Route::_('index.php?option=com_alfa&view=notifications'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                    <table class="table table-striped" id="notificationList">
                        <thead>
                            <tr>
                                <th class="w-1 text-center"><?php echo Text::_('COM_ALFA_NOTIFY_COL_SEVERITY'); ?></th>
                                <th><?php echo Text::_('COM_ALFA_NOTIFY_COL_TITLE'); ?></th>
                                <th class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_NOTIFY_COL_GROUP'); ?></th>
                                <th class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_NOTIFY_COL_CREATED'); ?></th>
                                <th class="d-none d-md-table-cell text-center"><?php echo Text::_('COM_ALFA_NOTIFY_COL_STATE'); ?></th>
                                <th class="w-5 text-center"><?php echo Text::_('JACTION_DELETE'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->items as $item) : ?>
                            <?php
                            // Per-row view-access gate (kept here, not in SQL — ACL is per-user).
                            if (!NotificationHelper::canSee($item, $user)) {
                                continue;
                            }

                            $severity = \in_array($item->severity, ['success', 'info', 'warning', 'danger'], true) ? $item->severity : 'info';
                            $safeUrl  = NotificationHelper::safeUrl((string) $item->url);
                            $hasLink  = $safeUrl !== '' && NotificationHelper::canUseLink($item, $user);
                            ?>
                            <tr>
                                <td class="text-center"><span class="badge text-bg-<?php echo $severity; ?>"><?php echo Text::_($severityLabels[$severity] ?? 'COM_ALFA_NOTIFY_SEVERITY_INFO'); ?></span></td>
                                <td>
                                    <?php if ($hasLink) : ?>
                                        <a href="<?php echo htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                            <strong><?php echo htmlspecialchars((string) $item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </a>
                                    <?php else : ?>
                                        <strong><?php echo htmlspecialchars((string) $item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php endif; ?>
                                    <?php if ((string) $item->message !== '') : ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string) $item->message, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-md-table-cell"><?php echo htmlspecialchars((string) $item->notify_group, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="d-none d-md-table-cell">
                                    <?php echo $item->created ? HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC5')) : ''; ?>
                                </td>
                                <td class="d-none d-md-table-cell text-center">
                                    <?php if (!empty($item->dismissed)) : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_NOTIFY_STATE_DISMISSED'); ?></span>
                                    <?php elseif (empty($item->readed)) : ?>
                                        <span class="badge bg-primary"><?php echo Text::_('COM_ALFA_NOTIFY_UNREAD'); ?></span>
                                    <?php else : ?>
                                        <span class="badge bg-light text-dark"><?php echo Text::_('COM_ALFA_NOTIFY_STATE_ACTIVE'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (empty($item->dismissed) && (int) $item->dismissible === 1) : ?>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?php echo Route::_('index.php?option=com_alfa&task=notifications.dismiss&id=' . (int) $item->id . '&' . Session::getFormToken() . '=1'); ?>">
                                            <?php echo Text::_('COM_ALFA_NOTIFY_DISMISS'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
