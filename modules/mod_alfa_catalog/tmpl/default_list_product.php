<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A tab of products as a list: one plain row per product with its small image, its name and its price. A row has
 * no buy form: it leads to the product's page.
 *
 * The image always carries a description: its own, else the product's name.
 *
 * @var object[] $records The decorated products (the catalogue's listing model)
 * @var array $group   The tab
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$priceSettings = PriceSettings::get();
?>
<ul class="list" data-kind="product">
    <?php foreach ($records as $item) : ?>
        <?php
        // A product never translated into the active language has no name: its SKU names it, and failing that its id.
        $name = trim((string) ($item->name ?? '')) ?: (trim((string) ($item->sku ?? '')) ?: Text::sprintf('MOD_ALFA_CATALOG_ITEM_UNNAMED', (int) $item->id));
        ?>
        <li class="entry" data-item-id="<?php echo (int) $item->id; ?>">
            <a class="entry-link" href="<?php echo $esc((string) ($item->link ?? '')); ?>">
                <?php if (!empty($item->medias[0]->thumbnail)) : ?>
                    <img class="entry-image" src="<?php echo $esc((string) $item->medias[0]->thumbnail_url); ?>" alt="<?php echo $esc(trim((string) ($item->medias[0]->alt ?? '')) ?: $name); ?>" loading="lazy">
                <?php endif; ?>
                <span class="entry-name"><?php echo $esc($name); ?></span>
            </a>
            <div class="entry-price">
                <?php echo Layout::render(layout: 'item.price', data: ['item' => $item, 'settings' => $priceSettings]); ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
