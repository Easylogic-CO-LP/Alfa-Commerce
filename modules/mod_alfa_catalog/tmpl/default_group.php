<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One tab: its records as a grid of cards, as a slider of cards (default_slider.php) or as a list (the list layout
 * of its kind: default_list_product.php, default_list_category.php, default_list_manufacturer.php), then its
 * "view all" link. The three presentations are the same for every kind; only the card and the list row are a
 * kind's own. Each card renders through the card layout of its kind (AlfaCatalogHelper::renderCard).
 *
 * @var object $module   The module instance
 * @var \Joomla\Registry\Registry $params   Module parameters
 * @var array $group    A group: key, label, kind, display, query, link, `items` (its records)
 * @var array $display  `mode` (grid, slider or list), `bands` (a slider's settings per device), `place` (the name of the tab's cards)
 * @var int $moduleId Module instance id
 */

\defined('_JEXEC') or die;

use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;

$records = $group['items'] ?? [];
$link = (string) ($group['link'] ?? '');
?>
<?php if ($records === []) : ?>
    <p class="empty"><?php echo Text::_('MOD_ALFA_CATALOG_EMPTY'); ?></p>
<?php elseif ($display['mode'] === 'list') : ?>
    <?php $listLayout = AlfaCatalogHelper::listLayout($params, (string) $group['kind']); ?>
    <?php if ($listLayout !== '') : ?>
        <?php include $listLayout; ?>
    <?php endif; ?>
<?php elseif ($display['mode'] === 'slider') : ?>
    <?php
    $slider = $display['bands'];
    include ModuleHelper::getLayoutPath('mod_alfa_catalog', (string) $params->get('layout', 'default') . '_slider');
    ?>
<?php else : ?>
    <div class="grid">
        <?php foreach ($records as $record) : ?>
            <?php echo AlfaCatalogHelper::renderCard(group: $group, record: $record, params: $params, place: $display['place'], moduleId: $moduleId); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($link !== '') : ?>
    <p class="view-all">
        <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" data-action="view-all">
            <?php echo Text::_('MOD_ALFA_CATALOG_VIEW_ALL'); ?>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </p>
<?php endif; ?>
