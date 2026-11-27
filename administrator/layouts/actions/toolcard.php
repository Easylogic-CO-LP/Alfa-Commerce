<?php

/**
 * Shared Tools card renderer: ONE file for every maintenance-action card, core and
 * plugin alike. This is what makes a plugin-contributed card indistinguishable from
 * a core one: both arrive as a {@see \Alfa\Component\Alfa\Administrator\Plugin\PluginAction}
 * through {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry} and render here.
 *
 * Rides the shipped Tools script unchanged: the button carries the one set of attributes every tool button carries
 * ({@see ToolButton}), on a Tools card as on the Background screen's toolbar, so an extension never ships JavaScript
 * merely to draw a progress bar.
 *
 * A tool that is a page of its own names it (meta 'link'): its card is a plain link there, nothing is run from the
 * card and it has no progress. A card may say where its tool stands in one line (meta 'status'), read from what is
 * stored, never computed for the card.
 *
 * Available $displayData:
 *   - action   (PluginAction)  The action definition
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ToolButton;
use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Administrator\Plugin\PluginAction $action */
$action = $displayData['action'] ?? null;

if (!$action || !$action->isValid()) {
    return;
}

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
// The card's own progress element, which its button names; the attributes the Tools script reads are the one set
// every tool button carries ({@see ToolButton::attributes()}).
$progressId = 'tool-progress-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($action->id));
$attrs = ToolButton::html(ToolButton::attributes(action: $action, progress: $progressId));

$description = (string) ($action->metadata['description'] ?? '');
$notice      = (string) ($action->metadata['notice'] ?? '');
$status      = (string) ($action->metadata['status'] ?? '');
$link        = (string) ($action->metadata['link'] ?? '');

// TRANSLATIONS ARE EMITTED RAW. Joomla language strings are authored as HTML:
// "Users &amp; groups", "Options &rarr; Search", so escaping them again renders the
// entity itself to the merchant. Only values that are NOT translations are escaped:
// provider identifiers and the URLs built above, all of which land in attributes.
$label = Text::_($action->label);
// A card whose button does something other than its title names it says so on the button (button_label).
$buttonLabel = ToolButton::label($action);
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

        <?php // The status is a finished text that may carry a stored reason, so it is escaped. ?>
        <?php if ($status !== '') : ?>
            <p class="mb-3"><?php echo $e($status); ?></p>
        <?php endif; ?>

        <?php if ($link !== '') : ?>
            <a class="btn <?php echo $e($action->class); ?>" href="<?php echo $e($link); ?>">
                <span class="icon-<?php echo $e($action->icon); ?>" aria-hidden="true"></span>
                <?php echo $buttonLabel; ?>
            </a>
        <?php else : ?>
            <button class="btn <?php echo $e($action->class); ?> tool-btn"<?php echo $attrs; ?>>
                <span class="icon-<?php echo $e($action->icon); ?>" aria-hidden="true"></span>
                <?php echo $buttonLabel; ?>
            </button>

            <div class="tool-progress mt-3" id="<?php echo $e($progressId); ?>" style="display:none;">
                <div class="progress" style="height:1.5rem;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                         style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
                </div>
                <p class="small text-muted mt-2 mb-0" data-status></p>
            </div>
        <?php endif; ?>
    </div>
</div>
