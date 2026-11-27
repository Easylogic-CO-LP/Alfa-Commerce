<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * What the shop needs a decision about, ranked by the kind of trouble and the money
 * behind it: one tile per finding, every finding on the table.
 *
 * A tile is a ticket: what kind of trouble it is (the eyebrow), how much (the figure),
 * what that means (the sentence), and where to go and deal with it (the verb). The
 * whole tile is the link, and it lands on the list ALREADY NARROWED to the finding.
 * A tile whose url the provider blanked (the viewer may not open the target) is a
 * plain card with no link and no verb.
 * The tint is the weight: red, amber, blue. Nothing folds and nothing is cut: a merchant who is told
 * "one more finding" has been told something is being withheld, and a grid has room
 * for all of them where a strip did not.
 *
 * IT RENDERS NOTHING WHEN THERE IS NOTHING WRONG. Its provider returns an empty array
 * once every signal is quiet, and an empty return here is what the registry reads as
 * "no card", so the card removes itself rather than reporting daily that all is well.
 *
 * A tile may carry lines instead of its sentence: one fact a line, each a link of its own (the kinds of a finding,
 * each leading to its own section). Such a tile is a block whose lines and verb are the links, never an anchor around
 * anchors; with more than two lines it takes the whole row of the grid, the grid's own span, no new mechanism.
 *
 * @var array $displayData One resolved card. data:
 *   ['rows' => [['severity', 'kind', 'value', 'text', 'go', 'url', 'lines' => [['text', 'url']]]]]
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$data = (array) ($displayData['data'] ?? []);
$rows = (array) ($data['rows'] ?? []);

if ($rows === []) {
    return;
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<span class="dash-card-head">
    <span class="dash-card-title"><?php echo Text::_($displayData['title']); ?></span>
    <span class="dash-card-sub"><?php echo $e(Text::plural('COM_ALFA_DASHCARD_ATTENTION_COUNT', \count($rows))); ?></span>
</span>

<div class="attention">
    <?php foreach ($rows as $row) : ?>
        <?php
        $row = (array) $row;
        $url = (string) ($row['url'] ?? '');
        $lines = array_values(array_filter((array) ($row['lines'] ?? []), static fn ($line): bool => \is_array($line) && (string) ($line['text'] ?? '') !== ''));
        ?>
        <?php if ($lines !== []) : ?>
            <div class="attention-tile<?php echo \count($lines) > 2 ? ' attention-tile-wide' : ''; ?>" data-severity="<?php echo $e((string) ($row['severity'] ?? 'warning')); ?>">
                <span class="attention-kind"><?php echo $e((string) ($row['kind'] ?? '')); ?></span>
                <span class="attention-figure"><?php echo $e((string) ($row['value'] ?? '')); ?></span>
                <ul class="attention-lines">
                    <?php foreach ($lines as $line) : ?>
                        <li>
                            <?php if ((string) ($line['url'] ?? '') !== '') : ?>
                                <a href="<?php echo $e((string) $line['url']); ?>"><?php echo $e((string) $line['text']); ?></a>
                            <?php else : ?>
                                <?php echo $e((string) $line['text']); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($url !== '') : ?>
                    <a class="attention-go" href="<?php echo $e($url); ?>"><?php echo $e((string) ($row['go'] ?? '')); ?></a>
                <?php endif; ?>
            </div>
            <?php continue; ?>
        <?php endif; ?>
        <?php $tag = $url !== '' ? 'a' : 'div'; ?>
        <<?php echo $tag; ?> class="attention-tile" data-severity="<?php echo $e((string) ($row['severity'] ?? 'warning')); ?>"<?php if ($url !== '') : ?> href="<?php echo $e($url); ?>"<?php endif; ?>>
            <span class="attention-kind"><?php echo $e((string) ($row['kind'] ?? '')); ?></span>
            <span class="attention-figure"><?php echo $e((string) ($row['value'] ?? '')); ?></span>
            <span class="attention-text"><?php echo $e((string) ($row['text'] ?? '')); ?></span>
            <?php if ($url !== '') : ?>
                <span class="attention-go"><?php echo $e((string) ($row['go'] ?? '')); ?></span>
            <?php endif; ?>
        </<?php echo $tag; ?>>
    <?php endforeach; ?>
</div>
