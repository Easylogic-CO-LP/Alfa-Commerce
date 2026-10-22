<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Tools > Clear everything. Outside maintenance: what core would remove, each installed Alfa plugin
 * with whether it clears its own data, what stays, then the typed phrase. In maintenance: the run's
 * state, each plugin's status, and the actions the state allows.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cleardata\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$action = Route::_('index.php?option=com_alfa');
$state = $this->state;
$preview = $this->preview;
$button = static function (string $task, string $label, string $class, string $icon): string {
    return '<form action="' . Route::_('index.php?option=com_alfa') . '" method="post" class="d-inline-block me-2">'
        . '<button type="submit" class="btn ' . $class . '"><span class="' . $icon . '" aria-hidden="true"></span> ' . Text::_($label) . '</button>'
        . '<input type="hidden" name="task" value="cleardata.' . $task . '">'
        . HTMLHelper::_('form.token')
        . '</form>';
};
$statusLine = function (array $extension): string {
    $name = $this->escape((string) ($extension['name'] ?? ''));
    $status = (string) ($extension['status'] ?? '');
    $html = '<li class="mb-2"><strong>' . $name . '</strong>: ' . Text::_('COM_ALFA_CLEAR_STATUS_' . strtoupper($status));

    if (\in_array($status, FactoryReset::WARNING_STATUSES, true)) {
        $html .= '<div class="text-warning">' . $this->escape(FactoryReset::warning(extension: $extension)) . '</div>';
    }

    if ((string) ($extension['message'] ?? '') !== '') {
        $html .= '<div class="small"><code>' . $this->escape((string) $extension['message']) . '</code></div>';
    }

    return $html . '</li>';
};
?>
<div class="com-alfa-cleardata p-3">

<?php if ($state !== null) : ?>
    <?php
    $phase = (string) ($state['phase'] ?? '');
    $stopped = $phase === 'running' && !$this->running;
    $title = match (true) {
        $phase === 'paused' => 'COM_ALFA_CLEAR_STATE_PAUSED',
        $phase === 'failed' || $stopped => 'COM_ALFA_CLEAR_STATE_FAILED',
        default => 'COM_ALFA_CLEAR_STATE_RUNNING',
    };
    ?>
    <div class="alert <?php echo $phase === 'running' && !$stopped ? 'alert-warning' : 'alert-danger'; ?>">
        <h2 class="h4"><?php echo Text::_($title); ?></h2>
        <p><?php echo Text::sprintf(
            'COM_ALFA_CLEAR_STATE_STEP',
            $this->escape(Text::_('COM_ALFA_CLEAR_STEP_' . strtoupper((string) ($state['step'] ?? '')))),
            $this->escape((string) ($state['user']['name'] ?? '')),
            $this->escape((string) ($state['started'] ?? '')),
        ); ?></p>
        <?php if (!empty($state['error'])) : ?>
            <p><strong><?php echo Text::_('COM_ALFA_CLEAR_STATE_ERROR'); ?></strong> <?php echo $this->escape((string) $state['error']); ?></p>
        <?php endif; ?>
        <p class="mb-0"><?php echo Text::_('COM_ALFA_CLEAR_STATE_MAINTENANCE'); ?></p>
    </div>

    <?php if ((array) ($state['extensions'] ?? []) !== []) : ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_CLEAR_EXTENSIONS_TITLE'); ?></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <?php foreach ((array) $state['extensions'] as $extension) : ?>
                        <?php echo $statusLine((array) $extension); ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($phase === 'paused') : ?>
        <p><?php echo Text::_('COM_ALFA_CLEAR_PAUSED_DESC'); ?></p>
        <div class="mb-3">
            <?php echo $button('retry', 'COM_ALFA_CLEAR_RETRY', 'btn-primary', 'icon-refresh'); ?>
            <?php echo $button('proceed', 'COM_ALFA_CLEAR_PROCEED', 'btn-danger', 'icon-arrow-right'); ?>
        </div>
    <?php elseif ($phase === 'failed' || $stopped) : ?>
        <p><?php echo Text::_('COM_ALFA_CLEAR_RESUME_DESC'); ?></p>
        <?php echo $button('resume', 'COM_ALFA_CLEAR_RESUME', 'btn-danger', 'icon-refresh'); ?>
    <?php endif; ?>

<?php else : ?>
    <div class="alert alert-danger">
        <h2 class="h4"><?php echo Text::_('COM_ALFA_CLEAR_WARNING_TITLE'); ?></h2>
        <p class="mb-0"><?php echo Text::_('COM_ALFA_CLEAR_WARNING_DESC'); ?></p>
    </div>

    <?php if ($this->last !== null) : ?>
        <div class="text-muted mb-3">
            <p class="mb-1"><?php echo Text::sprintf(
                'COM_ALFA_CLEAR_LAST',
                $this->escape((string) ($this->last['finished'] ?? '')),
                $this->escape((string) ($this->last['user']['name'] ?? '')),
                $this->escape((string) ($this->last['headline'] ?? '')),
            ); ?></p>
            <?php $lastWarnings = array_filter((array) ($this->last['extensions'] ?? []), static fn ($e): bool => \in_array($e['status'] ?? '', FactoryReset::WARNING_STATUSES, true)); ?>
            <?php if ($lastWarnings !== []) : ?>
                <ul class="small">
                    <?php foreach ($lastWarnings as $extension) : ?>
                        <li><?php echo $this->escape(FactoryReset::warning(extension: (array) $extension)); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($this->last['files']['not_removed'])) : ?>
                <p class="small mb-1"><?php echo Text::_('COM_ALFA_CLEAR_LAST_NOT_REMOVED'); ?></p>
                <ul class="small">
                    <?php foreach ((array) $this->last['files']['not_removed'] as $line) : ?>
                        <li><code><?php echo $this->escape((string) $line); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_CLEAR_CORE_TITLE'); ?></div>
        <div class="card-body">
            <h3 class="h6"><?php echo Text::sprintf('COM_ALFA_CLEAR_TABLES', \count($preview['tables']), (int) $preview['rows']); ?></h3>
            <div class="mb-3" style="max-height: 20rem; overflow: auto;">
                <table class="table table-sm">
                    <thead><tr><th scope="col"><?php echo Text::_('COM_ALFA_CLEAR_TABLE'); ?></th><th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_CLEAR_ROWS'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['tables'] as $table => $rows) : ?>
                        <tr><td><code><?php echo $this->escape((string) $table); ?></code></td><td class="text-end"><?php echo (int) $rows; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $store = $preview['store']; ?>
            <h3 class="h6"><?php echo Text::_('COM_ALFA_CLEAR_AREA_DOWNLOADS'); ?></h3>
            <?php if ($store['path'] === null) : ?>
                <p class="text-muted"><?php echo Text::_('COM_ALFA_CLEAR_AREA_NONE'); ?></p>
            <?php elseif ($store['refused'] !== null) : ?>
                <p class="text-danger"><?php echo Text::sprintf('COM_ALFA_CLEAR_AREA_REFUSED', '<code>' . $this->escape((string) $store['path']) . '</code>', $this->escape((string) $store['refused'])); ?></p>
            <?php else : ?>
                <p><?php echo Text::sprintf('COM_ALFA_CLEAR_AREA_FILES', '<code>' . $this->escape((string) $store['path']) . '</code>', (int) $store['files'], FactoryReset::bytes(bytes: (int) $store['bytes'])); ?></p>
                <?php if ($store['left'] !== []) : ?>
                    <p class="text-warning"><?php echo Text::sprintf('COM_ALFA_CLEAR_AREA_LEFT', \count($store['left'])); ?></p>
                    <ul class="small">
                        <?php foreach (\array_slice($store['left'], 0, 20) as $left) : ?>
                            <li><code><?php echo $this->escape((string) $left['path']); ?></code>: <?php echo $this->escape((string) $left['reason']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <p class="small text-muted"><?php echo Text::_('COM_ALFA_CLEAR_FILES_NOTE'); ?></p>

            <p><?php echo Text::_('COM_ALFA_CLEAR_OPTIONS_NOTE'); ?></p>
            <p><strong><?php echo Text::_('COM_ALFA_CLEAR_PAYMENTS_NOTE'); ?></strong></p>

            <?php $images = $preview['images']; ?>
            <h3 class="h6"><?php echo Text::_('COM_ALFA_CLEAR_IMAGES_TITLE'); ?></h3>
            <p class="mb-0"><?php echo Text::sprintf('COM_ALFA_CLEAR_IMAGES_DESC', '<code>' . $this->escape((string) $images['path']) . '</code>', (int) $images['referenced'], (int) $images['present']); ?></p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_CLEAR_EXTENSIONS_TITLE'); ?></div>
        <div class="card-body">
            <?php if ($preview['extensions'] === []) : ?>
                <p class="text-muted mb-0"><?php echo Text::_('COM_ALFA_CLEAR_EXTENSIONS_NONE_LISTED'); ?></p>
            <?php else : ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($preview['extensions'] as $extension) : ?>
                        <?php $name = $this->escape((string) $extension['name']) . ($extension['enabled'] ? '' : ' ' . Text::_('COM_ALFA_CLEAR_EXTENSION_DISABLED')); ?>
                        <li class="mb-2">
                            <?php if ($extension['status'] === 'supported') : ?>
                                <?php echo Text::sprintf('COM_ALFA_CLEAR_EXTENSION_SUPPORTED', '<strong>' . $name . '</strong>'); ?>
                                <?php if ((string) $extension['description'] !== '') : ?>
                                    <div class="small text-muted"><?php echo $this->escape((string) $extension['description']); ?></div>
                                <?php endif; ?>
                            <?php elseif ($extension['status'] === 'unsupported') : ?>
                                <div class="alert alert-warning py-2 mb-0"><?php echo Text::sprintf('COM_ALFA_CLEAR_EXTENSION_UNSUPPORTED', $name); ?></div>
                            <?php elseif ($extension['status'] === 'busy') : ?>
                                <span class="text-muted"><?php echo '<strong>' . $name . '</strong>: ' . $this->escape((string) $extension['message']); ?></span>
                            <?php else : ?>
                                <div class="alert alert-warning py-2 mb-0"><?php echo Text::sprintf('COM_ALFA_CLEAR_EXTENSION_LIKELY_FAIL', $name, $this->escape((string) $extension['message'])); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-muted"><?php echo Text::_('COM_ALFA_CLEAR_JOOMLA_KEPT'); ?></p>

    <?php if ($preview['refusal'] !== null) : ?>
        <div class="alert alert-danger"><?php echo $this->escape((string) $preview['refusal']); ?></div>
    <?php else : ?>
        <form action="<?php echo $action; ?>" method="post" class="card card-body border-danger" autocomplete="off">
            <?php echo LayoutHelper::render('actions.typedconfirm', ['phrase' => FactoryReset::PHRASE, 'id' => 'alfa-clear-phrase'], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
            <div>
                <button type="submit" class="btn btn-danger">
                    <span class="icon-trash" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_CLEAR_BUTTON'); ?>
                </button>
            </div>
            <input type="hidden" name="task" value="cleardata.run">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
<?php endif; ?>

</div>
