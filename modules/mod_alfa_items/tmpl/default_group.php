<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One tab: its cards as a grid or as a slider (default_slider.php), then its
 * "view all" link. Each card renders through default_card.php.
 *
 * @var object $module        The module instance
 * @var \Joomla\Registry\Registry $params        Module parameters
 * @var array $group         ['key', 'label', 'items' => object[], 'lazy', 'link']
 * @var array $display       Module-wide settings (AlfaItemsHelper::displayOptions), with `place`: the name of the tab's cards
 * @var array $priceSettings Price visibility settings
 * @var int $moduleId      Module instance id
 */

\defined('_JEXEC') or die;

use Alfa\Module\AlfaItems\Site\Helper\AlfaItemsHelper;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;

$items = $group['items'] ?? [];
$link = (string) ($group['link'] ?? '');
?>
<?php if ($items === []) : ?>
    <p class="empty"><?php echo Text::_('MOD_ALFA_ITEMS_EMPTY'); ?></p>
<?php elseif ($display['mode'] === 'slider') : ?>
    <?php
    $sliderItems = $items;
    $slider = $display['bands'];
    include ModuleHelper::getLayoutPath('mod_alfa_items', (string) $params->get('layout', 'default') . '_slider');
    ?>
<?php else : ?>
    <div class="grid">
        <?php foreach ($items as $item) : ?>
            <?php echo AlfaItemsHelper::renderCard(item: $item, params: $params, place: $display['place'], priceSettings: $priceSettings, reloadUrl: AlfaItemsHelper::reloadUrl(moduleId: $moduleId, tab: (string) $group['key'], item: $item)); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($link !== '') : ?>
    <p class="view-all">
        <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" data-action="view-all">
            <?php echo Text::_('MOD_ALFA_ITEMS_VIEW_ALL'); ?>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </p>
<?php endif; ?>
