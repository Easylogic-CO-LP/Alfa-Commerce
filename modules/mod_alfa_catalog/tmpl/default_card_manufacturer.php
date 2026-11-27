<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One manufacturer card: its logo, its name or both, and when asked for how many products it has. The card is the
 * module's (its markup here, its styles in catalog.css, its look from the module's Style tab and the tab), so an
 * override of this file changes the module's manufacturer cards and nothing in the shop.
 *
 * A manufacturer asked for its logo that has none shows its name, so no card is ever empty. The logo always carries
 * a description (its own, else the manufacturer's name). Beside the name its link is for the mouse only, so the name
 * is read once; alone, the logo is the card's link.
 *
 * @var object $record  The manufacturer: id, name, link, image, alt, count (null when counts are off)
 * @var string $place   The card's place, which its rules are written for
 * @var array $options The tab's query (ManufacturersProvider::query): `show` is logo_name, logo or name
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$link = $esc((string) $record->link);
$name = trim((string) $record->name) ?: Text::sprintf('MOD_ALFA_CATALOG_MANUFACTURER_UNNAMED', (int) $record->id);
$show = (string) ($options['show'] ?? 'logo_name');
$logo = $show !== 'name' && $record->image !== '';
$named = !$logo || $show !== 'logo';
?>
<article class="item-card" data-kind="manufacturer" data-look="<?php echo $esc($place); ?>" data-manufacturer-id="<?php echo (int) $record->id; ?>">
    <?php if ($logo) : ?>
        <div class="item-card-image">
            <a href="<?php echo $link; ?>"<?php echo $named ? ' tabindex="-1" aria-hidden="true"' : ''; ?>>
                <img src="<?php echo $esc((string) $record->image); ?>" alt="<?php echo $esc(trim((string) $record->alt) ?: $name); ?>" loading="lazy">
            </a>
        </div>
    <?php endif; ?>
    <?php if ($named || $record->count !== null) : ?>
        <div class="item-card-body">
            <?php if ($named) : ?>
                <div class="item-card-title">
                    <a href="<?php echo $link; ?>"><?php echo $esc($name); ?></a>
                </div>
            <?php endif; ?>
            <?php if ($record->count !== null) : ?>
                <p class="item-card-count"><?php echo Text::plural('MOD_ALFA_CATALOG_N_PRODUCTS', (int) $record->count); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
