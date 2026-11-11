<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The background work's card on the Dashboard's alert strip (2.2.0): the standing health notice's text and a link to
 * Tools > Tasks, and one line for each job running or waiting, linking to its progress page (NEW-7). Nothing is
 * printed while neither is there. The staff kick draws it again after each of its ticks (TasksController::
 * backgroundCard()) and swaps [data-alfa-background-card] in (entry 27).
 *
 * @var array $displayData title, data {text, url, jobs: list<{text, url}>}.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$data = (array) ($displayData['data'] ?? []);
$jobs = (array) ($data['jobs'] ?? []);

if (($data['text'] ?? '') === '' && $jobs === []) {
    return;
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<span class="alfa-card-head">
    <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>
</span>

<div class="alfa-attention" data-alfa-background-card>
    <?php if (($data['text'] ?? '') !== '') : ?>
    <a class="alfa-attention-tile" data-severity="warning" href="<?php echo $e((string) $data['url']); ?>">
        <span class="alfa-attention-text"><?php echo $e((string) $data['text']); ?></span>
        <span class="alfa-attention-go"><?php echo $e(Text::_('COM_ALFA_DASHCARD_RUNNER_HEALTH_GO')); ?></span>
    </a>
    <?php endif; ?>
    <?php foreach ($jobs as $job) : ?>
    <a class="alfa-attention-tile" data-severity="info" data-alfa-dash-job href="<?php echo $e((string) $job['url']); ?>">
        <span class="alfa-attention-text"><?php echo $e((string) $job['text']); ?></span>
    </a>
    <?php endforeach; ?>
</div>
