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
 * @var array $displayData One resolved card. data:
 *   ['rows' => [['severity', 'kind', 'value', 'text', 'go', 'url']]]
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
<span class="alfa-card-head">
    <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>
    <span class="alfa-card-sub"><?php echo $e(Text::plural('COM_ALFA_DASHCARD_ATTENTION_COUNT', \count($rows))); ?></span>
</span>

<div class="alfa-attention">
    <?php foreach ($rows as $row) : ?>
        <?php
        $row = (array) $row;
        $url = (string) ($row['url'] ?? '');
        $tag = $url !== '' ? 'a' : 'div';
        ?>
        <<?php echo $tag; ?> class="alfa-attention-tile" data-severity="<?php echo $e((string) ($row['severity'] ?? 'warning')); ?>"<?php if ($url !== '') : ?> href="<?php echo $e($url); ?>"<?php endif; ?>>
            <span class="alfa-attention-kind"><?php echo $e((string) ($row['kind'] ?? '')); ?></span>
            <span class="alfa-attention-figure"><?php echo $e((string) ($row['value'] ?? '')); ?></span>
            <span class="alfa-attention-text"><?php echo $e((string) ($row['text'] ?? '')); ?></span>
            <?php if ($url !== '') : ?>
                <span class="alfa-attention-go"><?php echo $e((string) ($row['go'] ?? '')); ?></span>
            <?php endif; ?>
        </<?php echo $tag; ?>>
    <?php endforeach; ?>
</div>
