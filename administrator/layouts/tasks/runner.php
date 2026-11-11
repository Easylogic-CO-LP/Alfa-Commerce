<?php

/**
 * Tools > Tasks: how the shop's background work runs. Its last run, the cron command, and the web-cron address. The
 * address's key is shown once, in the answer to Generate, which also stops the old key; at any other time the card
 * says whether a key exists. Generate is a plain form POST with the form token, answered by TasksController.
 *
 * Available $displayData:
 *   - lastTick (array|null) runner.last_tick: at, trigger, singles, chunks, jobs, ms
 *   - cli      (string)     the cron command
 *   - cron     (string)     the web-cron address up to its key
 *   - keyKept  (bool)       a key exists
 *   - newKey   (string)     the key made in this request, '' at any other time
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.2.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$lastTick = $displayData['lastTick'] ?? null;
$newKey   = (string) ($displayData['newKey'] ?? '');
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$trigger  = static function (string $code) use ($e): string {
    $key = 'COM_ALFA_TASKS_TRIGGER_' . strtoupper($code);

    return Text::_($key) === $key ? $e($code) : Text::_($key);
};
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TASKS_RUNNER_TITLE'); ?></div>
    <div class="card-body">
        <p class="text-muted"><?php echo Text::_('COM_ALFA_TASKS_RUNNER_DESC'); ?></p>
        <p>
            <?php if (is_array($lastTick) && (string) ($lastTick['at'] ?? '') !== '') : ?>
                <?php // When and how; the counts and the time it took in the line's title (UX-07). ?>
                <span title="<?php echo $e(Text::sprintf('COM_ALFA_TASKS_RUNNER_LAST_DETAIL', (int) ($lastTick['singles'] ?? 0), (int) ($lastTick['chunks'] ?? 0), (int) ($lastTick['ms'] ?? 0))); ?>"><?php
                    echo Text::sprintf(
                        'COM_ALFA_TASKS_RUNNER_LAST',
                        HTMLHelper::_('date', (string) $lastTick['at'], Text::_('DATE_FORMAT_LC6')),
                        $trigger((string) ($lastTick['trigger'] ?? '')),
                    ); ?></span>
            <?php else : ?>
                <?php echo Text::_('COM_ALFA_TASKS_RUNNER_NEVER'); ?>
            <?php endif; ?>
        </p>

        <h3 class="h6"><?php echo Text::_('COM_ALFA_TASKS_CLI_TITLE'); ?></h3>
        <p class="small text-muted mb-1"><?php echo Text::_('COM_ALFA_TASKS_CLI_DESC'); ?></p>
        <?php // The whole crontab line, every minute (UX-13). ?>
        <p><code><?php echo $e('* * * * * ' . ($displayData['cli'] ?? '')); ?></code></p>

        <h3 class="h6"><?php echo Text::_('COM_ALFA_TASKS_CRON_TITLE'); ?></h3>
        <p class="small text-muted mb-1"><?php echo Text::_('COM_ALFA_TASKS_CRON_DESC'); ?></p>
        <?php if ($newKey !== '') : ?>
            <div class="alert alert-warning">
                <label class="form-label" for="alfa-cron-address"><?php echo Text::_('COM_ALFA_TASKS_CRON_NEW'); ?></label>
                <input type="text" class="form-control mb-2" id="alfa-cron-address" readonly
                    value="<?php echo $e(($displayData['cron'] ?? '') . $newKey); ?>">
                <span class="small"><?php echo Text::_('COM_ALFA_TASKS_CRON_ONCE'); ?></span>
            </div>
        <?php elseif (!empty($displayData['keyKept'])) : ?>
            <p><?php echo Text::_('COM_ALFA_TASKS_CRON_KEPT'); ?></p>
        <?php else : ?>
            <p><?php echo Text::_('COM_ALFA_TASKS_CRON_NONE'); ?></p>
        <?php endif; ?>
        <?php // A new address stops the one a web-cron service may be calling: asked first when one exists (UX-11). ?>
        <form method="post" action="<?php echo Route::_('index.php?option=com_alfa&task=tasks.generateKey'); ?>"<?php
            echo empty($displayData['keyKept']) ? '' : ' data-alfa-confirm="' . $e(Text::_('COM_ALFA_TASKS_CRON_GENERATE_CONFIRM')) . '"'; ?>>
            <button type="submit" class="btn btn-secondary" title="<?php echo $e(Text::_('COM_ALFA_TASKS_CRON_GENERATE_DESC')); ?>">
                <?php echo Text::_('COM_ALFA_TASKS_CRON_GENERATE'); ?></button>
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
</div>
