<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Sections as an accordion: native details and summary, the title as the summary, the first section open,
 * several may be open at once. No script.
 *
 * Overridable as html/layouts/com_alfa/common/accordion.php.
 *
 * @var array $displayData ['sections' => list<array{key, title, body}>]
 */

defined('_JEXEC') or die;

$sections = (array) ($displayData['sections'] ?? []);

if ($sections === []) {
    return;
}

$esc = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$first = true;
?>
<div class="sections sections-accordion">
    <?php foreach ($sections as $section) : ?>
    <details class="section" id="section-<?php echo $esc($section['key']); ?>" data-section-key="<?php echo $esc($section['key']); ?>"<?php echo $first ? ' open' : ''; ?>>
        <summary class="section-summary"><h2 class="section-title"><?php echo $esc($section['title']); ?></h2></summary>
        <div class="section-body"><?php echo $section['body']; ?></div>
    </details>
    <?php $first = false; ?>
    <?php endforeach; ?>
</div>
