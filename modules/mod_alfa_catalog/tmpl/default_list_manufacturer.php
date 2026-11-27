<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A tab of manufacturers as a list: one plain row per manufacturer with its name, and when asked for its small logo
 * and how many products it has. A manufacturer asked for its logo that has none shows its name, so no row is ever
 * empty; the logo always carries a description (its own, else the manufacturer's name).
 *
 * @var object[] $records The manufacturers: id, name, link, image, alt, count (null when counts are off)
 * @var array $group   The tab; its query's `show` is logo_name, logo or name
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$show = (string) ($group['query']['show'] ?? 'logo_name');
?>
<ul class="list" data-kind="manufacturer">
    <?php foreach ($records as $record) : ?>
        <?php
        $name = trim((string) $record->name) ?: Text::sprintf('MOD_ALFA_CATALOG_MANUFACTURER_UNNAMED', (int) $record->id);
        $logo = $show !== 'name' && $record->image !== '';
        ?>
        <li class="entry" data-manufacturer-id="<?php echo (int) $record->id; ?>">
            <a class="entry-link" href="<?php echo $esc((string) $record->link); ?>">
                <?php if ($logo) : ?>
                    <img class="entry-image" src="<?php echo $esc((string) $record->image); ?>" alt="<?php echo $esc(trim((string) $record->alt) ?: $name); ?>" loading="lazy">
                <?php endif; ?>
                <?php if (!$logo || $show !== 'logo') : ?>
                    <span class="entry-name"><?php echo $esc($name); ?></span>
                <?php endif; ?>
                <?php if ($record->count !== null) : ?>
                    <span class="entry-count"><?php echo (int) $record->count; ?></span>
                <?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
