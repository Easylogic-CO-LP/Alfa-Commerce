<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One Keen slider of cards, of any kind. Arrows and dots are rendered here and driven by
 * catalog.js through their data-action hooks; the slider's settings on every screen,
 * wide, tablet (up to 1023px) and mobile (up to 767px), travel as one data attribute.
 *
 * @var object[] $records       The tab's records, one card each
 * @var array $slider        The slider's settings on every device, widest first (CardLook::bands)
 * @var array $display       Module-wide settings, with `place`: the name of the tab's cards
 * @var \Joomla\Registry\Registry $params Module parameters
 * @var array $group         The tab (its key, kind and query)
 * @var int $moduleId      Module instance id
 */

\defined('_JEXEC') or die;

use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;
use Joomla\CMS\Language\Text;

// Where every card fits there is nothing to slide to, and the module or the tab may switch
// the arrows or the dots off on a screen: the screens each goes without are listed for the
// module's CSS, which hides it there. One no screen shows is not drawn at all.
$count = \count($records);
$without = ['arrows' => [], 'dots' => []];

foreach ($slider as $band) {
    foreach (['arrows', 'dots'] as $control) {
        if ($count <= $band['perView'] || !$band[$control]) {
            $without[$control][] = $band['key'] === '' ? 'desktop' : $band['key'];
        }
    }
}

$arrows = \count($without['arrows']) < \count($slider);
$dots = \count($without['dots']) < \count($slider);
$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
// What the script reads: each device's width and its cards per view, spacing, autoplay and loop.
$bands = array_map(static fn (array $band): array => array_diff_key($band, ['key' => 1, 'arrows' => 1, 'dots' => 1]), $slider);
?>
<div class="slider"
     data-slider<?php echo $arrows && $without['arrows'] !== [] ? ' data-no-arrows="' . $esc(implode(' ', $without['arrows'])) . '"' : ''; ?><?php echo $dots && $without['dots'] !== [] ? ' data-no-dots="' . $esc(implode(' ', $without['dots'])) . '"' : ''; ?>
     data-state="idle"
     data-bands="<?php echo $esc((string) json_encode($bands)); ?>">

    <?php if ($arrows) : ?>
        <button type="button" class="arrow arrow-prev" data-action="slider-prev"
                aria-label="<?php echo htmlspecialchars(Text::_('MOD_ALFA_CATALOG_PREVIOUS'), ENT_QUOTES, 'UTF-8'); ?>" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
    <?php endif; ?>

    <div class="track keen-slider" data-slider-track>
        <?php foreach ($records as $record) : ?>
            <div class="slide keen-slider__slide">
                <?php echo AlfaCatalogHelper::renderCard(group: $group, record: $record, params: $params, place: $display['place'], moduleId: $moduleId); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($arrows) : ?>
        <button type="button" class="arrow arrow-next" data-action="slider-next"
                aria-label="<?php echo htmlspecialchars(Text::_('MOD_ALFA_CATALOG_NEXT'), ENT_QUOTES, 'UTF-8'); ?>" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </button>
    <?php endif; ?>

    <?php if ($dots) : ?>
        <div class="dots" data-slider-dots>
            <?php foreach (array_keys($records) as $i) : ?>
                <button type="button" class="dot" data-action="slider-dot" data-index="<?php echo (int) $i; ?>"
                        aria-label="<?php echo htmlspecialchars(Text::sprintf('MOD_ALFA_CATALOG_GO_TO_SLIDE', $i + 1), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $i === 0 ? ' data-state="active"' : ''; ?>></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
