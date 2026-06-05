<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Toolbar notification badge — the whole component as ONE node: the `btn btn-action`
 * (icon + label + count, so it matches the other toolbar buttons and reads clearly when
 * the toolbar collapses to one-button-per-line on mobile) plus the panel. Pure markup —
 * the assets/script options are registered by NotificationHelper::toolbarBadge() on page
 * load; the JS just refreshes this same layout and swaps the node in place.
 *
 * @var array $displayData  ['summary' => ['count'=>int,'severity'=>string], 'is_open' => bool]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\Language\Text;

$summary  = $displayData['summary'] ?? ['count' => 0, 'severity' => 'none'];
$isOpen   = !empty($displayData['is_open']);
$count    = (int) ($summary['count'] ?? 0);
$severity = $count > 0 ? ($summary['severity'] ?? 'info') : 'none';
?>
<div class="alfa-notify" data-region="component">
    <button type="button" class="btn btn-action alfa-notify-btn" data-region="badge" data-severity="<?php echo $severity; ?>"
            aria-label="<?php echo Text::_('COM_ALFA_NOTIFY_HISTORY_TITLE'); ?>">
        <span class="icon-bell" aria-hidden="true"></span>
        <span class="alfa-notify-label"><?php echo Text::_('COM_ALFA_NOTIFY_HISTORY_TITLE'); ?></span>
        <span class="alfa-notify-count"<?php echo $count > 0 ? '' : ' style="display:none"'; ?>><?php echo $count > 99 ? '99+' : $count; ?></span>
    </button>
    <?php echo NotificationHelper::renderActivePanel($isOpen); ?>
</div>
