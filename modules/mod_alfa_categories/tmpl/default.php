<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The categories as a nested list in a navigation landmark. Each level is drawn by default_level.php (a template
 * overrides it as html/mod_alfa_categories/default_level.php), which draws the level below through $level.
 *
 * @var object $module The module instance.
 * @var \Joomla\Registry\Registry $params The module's settings.
 * @var list<object> $nodes The nodes, from AlfaCategoriesHelper::nodes().
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;

$moduleId = (int) $module->id;
$sfx = htmlspecialchars((string) $params->get('moduleclass_sfx', ''), ENT_QUOTES, 'UTF-8');
$levelLayout = ModuleHelper::getLayoutPath('mod_alfa_categories', (string) $params->get('layout', 'default') . '_level');

// Each level in a scope of its own: the level below cannot overwrite the loop of the level that draws it.
$level = static function (array $children) use ($levelLayout, &$level): void {
    require $levelLayout;
};
?>
<nav class="mod-alfa-categories mod-alfa-categories-<?php echo $moduleId; ?><?php echo $sfx !== '' ? ' ' . $sfx : ''; ?>"
     aria-label="<?php echo htmlspecialchars((string) $module->title, ENT_QUOTES, 'UTF-8'); ?>">
    <?php $level($nodes); ?>
</nav>
