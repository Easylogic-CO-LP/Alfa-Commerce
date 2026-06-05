<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The panel region of the badge component — the `.alfa-notify-dropdown` itself (the JS
 * moves nothing; it's position:fixed and the JS only sets its coordinates). `data-region`
 * lets the JS target it; `data-open` + the `show` class carry the open state so a refresh
 * restores it. Visibility is already filtered upstream; here we only decide per row
 * whether to show the link (url-access). Styling: notifications.css.
 *
 * @var array $displayData  ['items' => object[], 'is_open' => bool]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$items  = $displayData['items'] ?? [];
$isOpen = !empty($displayData['is_open']);
?>
<div class="alfa-notify-dropdown<?php echo $isOpen ? ' show' : ''; ?>" data-region="panel" data-open="<?php echo $isOpen ? '1' : '0'; ?>" role="menu">
<div class="alfa-notify-head">
    <span><?php echo Text::_('COM_ALFA_NOTIFY_HISTORY_TITLE'); ?></span>
    <?php if (!empty($items)) : ?>
        <small><?php echo \count($items); ?></small>
    <?php endif; ?>
</div>

<?php if (empty($items)) : ?>
    <div class="alfa-notify-empty">
        <span class="alfa-notify-empty-ico icon-checkmark-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_NOTIFY_EMPTY'); ?>
    </div>
<?php else : ?>
    <ul class="alfa-notify-list">
        <?php foreach ($items as $n) : ?>
            <?php
            $severity = \in_array($n->severity, ['success', 'info', 'warning', 'danger'], true) ? $n->severity : 'info';
            $unread   = empty($n->readed);
            $safeUrl  = NotificationHelper::safeUrl((string) $n->url);
            $hasLink  = $safeUrl !== '' && NotificationHelper::canUseLink(notification: $n);
            ?>
            <li class="alfa-notify-item<?php echo $unread ? ' is-unread' : ''; ?>" data-id="<?php echo (int) $n->id; ?>">
                <span class="alfa-notify-dot sev-<?php echo $severity; ?>" aria-hidden="true"></span>
                <div class="alfa-notify-body">
                    <div class="alfa-notify-title"><?php echo htmlspecialchars((string) $n->title, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ((string) $n->message !== '') : ?>
                        <div class="alfa-notify-msg"><?php echo htmlspecialchars((string) $n->message, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="alfa-notify-meta">
                        <?php if ($hasLink) : ?>
                            <a class="alfa-notify-link" href="<?php echo htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo Text::_('COM_ALFA_NOTIFY_OPEN'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($n->created)) : ?>
                            <span class="alfa-notify-time"><?php echo HTMLHelper::_('date.relative', $n->created); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="alfa-notify-actions">
                    <?php if ($unread) : ?>
                        <button type="button" class="alfa-notify-act" data-action="read" data-id="<?php echo (int) $n->id; ?>"
                                title="<?php echo Text::_('COM_ALFA_NOTIFY_MARK_READ'); ?>">
                            <span class="icon-checkmark" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                    <?php if ((int) $n->dismissible === 1) : ?>
                        <button type="button" class="alfa-notify-act" data-action="dismiss" data-id="<?php echo (int) $n->id; ?>"
                                title="<?php echo Text::_('COM_ALFA_NOTIFY_DISMISS'); ?>">
                            <span class="icon-cancel" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="alfa-notify-foot">
    <a href="<?php echo Route::_('index.php?option=com_alfa&view=notifications'); ?>">
        <?php echo Text::_('COM_ALFA_NOTIFY_SHOW_ALL'); ?>
    </a>
</div>
</div>

