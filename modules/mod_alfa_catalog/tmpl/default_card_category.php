<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One category card: its image, its name, and when asked for its description and how many products it holds. The
 * card is the module's (its markup here, its styles in catalog.css, its look from the module's Style tab and the
 * tab), so an override of this file changes the module's category cards and nothing in the shop. It names its place
 * (`data-look`); how it looks there is in the rules the module writes for that place (CardLook::css).
 *
 * The image is the category's thumbnail, loaded lazily since a module's place on the page is not known. A category
 * without an image shows its name alone.
 *
 * @var object $record  The category: id, name, link, image, alt, description, count (null when counts are off)
 * @var string $place   The card's place, which its rules are written for
 * @var array $options The tab's query (CategoriesProvider::query)
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$link = $esc((string) $record->link);

// The link must say what it leads to: a category never translated into the active language has no name, so its id
// names it.
$name = trim((string) $record->name) ?: Text::sprintf('MOD_ALFA_CATALOG_CATEGORY_UNNAMED', (int) $record->id);
?>
<article class="item-card" data-kind="category" data-look="<?php echo $esc($place); ?>" data-category-id="<?php echo (int) $record->id; ?>">
    <?php if ($record->image !== '') : ?>
        <div class="item-card-image">
            <?php // The name's destination again, for the mouse only: one link per card in the tab order and for a screen reader.?>
            <a href="<?php echo $link; ?>" tabindex="-1" aria-hidden="true">
                <img src="<?php echo $esc((string) $record->image); ?>" alt="<?php echo $esc(trim((string) $record->alt) ?: $name); ?>" loading="lazy">
            </a>
        </div>
    <?php endif; ?>
    <div class="item-card-body">
        <div class="item-card-title">
            <a href="<?php echo $link; ?>"><?php echo $esc($name); ?></a>
        </div>
        <?php if ((string) $record->description !== '') : ?>
            <p class="item-card-text"><?php echo $esc(HTMLHelper::_('string.truncate', (string) $record->description, 160, true, false)); ?></p>
        <?php endif; ?>
        <?php if ($record->count !== null) : ?>
            <p class="item-card-count"><?php echo Text::plural('MOD_ALFA_CATALOG_N_PRODUCTS', (int) $record->count); ?></p>
        <?php endif; ?>
    </div>
</article>
