<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Sections as tabs: a tab bar of links before the sections, every section visible. The tabs script
 * (com_alfa.site.tabs, switched on by data-alfa-tabs) makes the bar a tab list and shows one section at a time;
 * without it the links jump to their sections and nothing is hidden. The bar's look is tabs.css, loaded with the
 * script, here, so a page that draws tabs gets both and a page that draws none gets neither.
 *
 * Overridable as html/layouts/com_alfa/common/tabs.php.
 *
 * @var array $displayData ['sections' => list<array{key, title, body}>, 'label' => string]
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$sections = (array) ($displayData['sections'] ?? []);

if ($sections === []) {
    return;
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->useStyle('com_alfa.site.tabs')
    ->useScript('com_alfa.site.tabs');

$esc = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$label = (string) ($displayData['label'] ?? Text::_('COM_ALFA_SECTIONS_NAV_LABEL'));
?>
<div class="alfa-sections alfa-sections-tabs" data-alfa-tabs>
    <nav class="alfa-tabs" aria-label="<?php echo $esc($label); ?>">
        <?php foreach ($sections as $section) : ?>
        <a href="#alfa-section-<?php echo $esc($section['key']); ?>" class="alfa-tab"><?php echo $esc($section['title']); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php foreach ($sections as $section) : ?>
    <section class="alfa-section" id="alfa-section-<?php echo $esc($section['key']); ?>" data-alfa-section="<?php echo $esc($section['key']); ?>">
        <h2 class="alfa-section-title"><?php echo $esc($section['title']); ?></h2>
        <div class="alfa-section-body"><?php echo $section['body']; ?></div>
    </section>
    <?php endforeach; ?>
</div>
