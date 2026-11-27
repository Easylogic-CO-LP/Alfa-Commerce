<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * A collection of sections (key, title, body) in one of three presentations, from the same data:
 *
 *   stacked     every section one under the other, a heading and its body; no script (this file)
 *   tabs        a tab bar before the sections, enhanced by the tabs script (common/tabs.php)
 *   accordion   native details and summary, the first open; no script (common/accordion.php)
 *
 * Every section's element id is alfa-section-<key>, so a link to a section is the same in every mode. Overridable
 * as html/layouts/com_alfa/common/sections.php, tabs.php and accordion.php.
 *
 * @var array $displayData ['sections' => list<array{key, title, body}>, 'mode' => string, 'label' => string]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;

$sections = (array) ($displayData['sections'] ?? []);
$mode = (string) ($displayData['mode'] ?? 'stacked');

if ($sections === []) {
    return;
}

if ($mode === 'tabs' || $mode === 'accordion') {
    echo Layout::render('common.' . $mode, $displayData);

    return;
}

$esc = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
?>
<div class="alfa-sections">
    <?php foreach ($sections as $section) : ?>
    <section class="alfa-section" id="alfa-section-<?php echo $esc($section['key']); ?>" data-alfa-section="<?php echo $esc($section['key']); ?>">
        <h2 class="alfa-section-title"><?php echo $esc($section['title']); ?></h2>
        <div class="alfa-section-body"><?php echo $section['body']; ?></div>
    </section>
    <?php endforeach; ?>
</div>
