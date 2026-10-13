<?php

/**
 * Shared Tools card renderer: ONE file for every maintenance-action card, core and
 * plugin alike. This is what makes a plugin-contributed card indistinguishable from
 * a core one: both arrive as a {@see \Alfa\Component\Alfa\Administrator\Plugin\PluginAction}
 * through {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry} and render here.
 *
 * Rides the shipped Tools script unchanged: an action flagged `chunked` uses the
 * plan/chunk loop, any other uses the single-request path; one flagged `mutating`
 * is sent as a POST carrying a one-time key, which the endpoint demands of it.
 * That is why an extension never ships JavaScript merely to draw a progress bar.
 *
 * The owner travels in the AJAX URL as an optimisation only; the server asks that
 * provider whether it really declares this action, enabled, before running it.
 *
 * Available $displayData:
 *   - action   (PluginAction)  The action definition
 *   - ajaxBase (string)        AJAX base URL carrying the CSRF token
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Administrator\Plugin\PluginAction $action */
$action   = $displayData['action'] ?? null;
$ajaxBase = (string) ($displayData['ajaxBase'] ?? '');

if (!$action || !$action->isValid()) {
    return;
}

$e       = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$owner   = ($action->metadata['owner_group'] ?? '') . '/' . ($action->metadata['owner_name'] ?? '');
$chunked = (bool) ($action->metadata['chunked'] ?? false);
$params  = '&action=' . rawurlencode($action->id) . '&owner=' . rawurlencode($owner);

$attrs = $chunked
    ? ' data-action="backfill"'
        . ' data-plan-url="' . $e($ajaxBase . '&task=toolaction.plan' . $params) . '"'
        . ' data-chunk-url="' . $e($ajaxBase . '&task=toolaction.chunk' . $params) . '"'
    : ' data-action="single" data-url="' . $e($ajaxBase . '&task=toolaction.run' . $params) . '"';

// A state-changing action is POSTed with a one-time key; a read-only one is
// fetched. The endpoint refuses a mutating action sent any other way.
if ($action->mutates) {
    $attrs .= ' data-mutating="1"';
}

$description = (string) ($action->metadata['description'] ?? '');
$notice      = (string) ($action->metadata['notice'] ?? '');

// TRANSLATIONS ARE EMITTED RAW. Joomla language strings are authored as HTML:
// "Users &amp; groups", "Options &rarr; Search", so escaping them again renders the
// entity itself to the merchant. Only values that are NOT translations are escaped:
// provider identifiers and the URLs built above, all of which land in attributes.
$label = Text::_($action->label);

// Translations that land in an ATTRIBUTE only have the attribute-ending character
// neutralised; entity-escaping them would corrupt them the same way as above.
$attr = static fn (string $v): string => str_replace('"', '&quot;', $v);

$tooltip = $action->tooltip === null ? '' : ' title="' . $attr(Text::_($action->tooltip)) . '"';

$confirm = $action->requires_confirmation
    ? ' data-confirm-message="' . $attr(Text::_($action->confirmation_message ?? 'JGLOBAL_CONFIRM_DELETE')) . '"'
    : '';
?>
<div class="card mb-3">
    <div class="card-header"><?php echo $label; ?></div>
    <div class="card-body">
        <?php if ($description !== '') : ?>
            <p class="text-muted mb-3"><?php echo Text::_($description); ?></p>
        <?php endif; ?>

        <?php if ($notice !== '') : ?>
            <div class="alert alert-warning"><span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_($notice); ?></div>
        <?php endif; ?>

        <button type="button" class="btn <?php echo $e($action->class); ?> alfa-tool-btn"<?php
            echo $attrs, $confirm, $tooltip, $action->enabled ? '' : ' disabled'; ?>>
            <span class="icon-<?php echo $e($action->icon); ?>" aria-hidden="true"></span>
            <?php echo $label; ?>
        </button>

        <div class="alfa-progress mt-3" style="display:none;">
            <div class="progress" style="height:1.5rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
            </div>
            <p class="small text-muted mt-2 mb-0" data-status></p>
        </div>
    </div>
</div>
