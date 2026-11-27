<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A tab of categories as a list: a nested list of links in a navigation landmark, the shop's category tree from a
 * parent down to the levels asked for. Each level is drawn by default_list_category_level.php (a template overrides
 * it as html/mod_alfa_catalog/default_list_category_level.php), which draws the level below through $level.
 *
 * @var object $module  The module instance
 * @var \Joomla\Registry\Registry $params  Module parameters
 * @var object[] $records The nodes of the first level (CategoriesProvider::records)
 * @var array $group   The tab
 */

\defined('_JEXEC') or die;

use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;

$levelLayout = AlfaCatalogHelper::listLayout($params, (string) $group['kind'], '_level');

// Each level in a scope of its own: the level below cannot overwrite the loop of the level that draws it.
$level = static function (array $children) use ($levelLayout, &$level): void {
    if ($levelLayout !== '') {
        require $levelLayout;
    }
};

// The landmark is named after the module, and after the tab where the module's title is not at hand.
$landmark = trim((string) ($module->title ?? '')) ?: (string) ($group['label'] ?? '');
?>
<nav class="tree-nav" aria-label="<?php echo htmlspecialchars($landmark, ENT_QUOTES, 'UTF-8'); ?>">
    <?php $level($records); ?>
</nav>
