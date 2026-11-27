<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Module root: one tab per group; a single group renders alone, several as a tab strip whose tabs are fetched when
 * opened. Each group renders through default_group.php (AlfaCatalogHelper::renderGroup), the same layout the
 * on-demand fetch returns. Behaviour hooks are data attributes only; the script builds no markup, and a module
 * without a tab strip or a slider loads no script at all.
 *
 * @var object $module  The module instance
 * @var \Joomla\Registry\Registry $params  Module parameters
 * @var array $groups  Groups from AlfaCatalogHelper::getGroups()
 * @var array $needs   What the tabs use (AlfaCatalogHelper::needs)
 * @var string $lazyUrl On-demand tab endpoint
 */

\defined('_JEXEC') or die;

use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;

$moduleId = (int) $module->id;
$sfx = htmlspecialchars((string) $params->get('moduleclass_sfx', ''), ENT_QUOTES, 'UTF-8');
$idBase = 'mod-alfa-catalog-' . $moduleId;
$tabbed = \count($groups) > 1;
// What the catalogue draws inside a product (price, stock, add to cart) is styled under the catalogue's own root.
$shop = $needs['shop'] ? 'com-alfa ' : '';
?>
<div class="<?php echo $shop; ?>mod-alfa-catalog mod-alfa-catalog-<?php echo $moduleId; ?><?php echo $sfx !== '' ? ' ' . $sfx : ''; ?>"
     data-mod-alfa-catalog
     data-module-id="<?php echo $moduleId; ?>"
     data-arrangement="<?php echo $tabbed ? 'tabs' : 'single'; ?>"<?php echo $tabbed ? ' data-lazy-url="' . htmlspecialchars($lazyUrl, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>

    <?php if ($tabbed) : ?>
        <div class="tabs" role="tablist">
            <?php foreach ($groups as $i => $group) : ?>
                <button type="button"
                        class="tab"
                        role="tab"
                        id="<?php echo $idBase . '-tab-' . $group['key']; ?>"
                        aria-controls="<?php echo $idBase . '-panel-' . $group['key']; ?>"
                        aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                        tabindex="<?php echo $i === 0 ? '0' : '-1'; ?>"
                        data-action="tab"
                        data-tab="<?php echo $group['key']; ?>"<?php echo $i === 0 ? ' data-state="active"' : ''; ?>>
                    <?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $i => $group) : ?>
        <?php if ($tabbed) : ?>
            <div class="panel"
                 role="tabpanel"
                 id="<?php echo $idBase . '-panel-' . $group['key']; ?>"
                 aria-labelledby="<?php echo $idBase . '-tab-' . $group['key']; ?>"
                 data-panel="<?php echo $group['key']; ?>"
                 data-kind="<?php echo $group['kind']; ?>"
                 data-state="<?php echo $group['lazy'] ? 'idle' : 'loaded'; ?>"<?php echo $i === 0 ? '' : ' hidden'; ?>>
                <?php echo $group['lazy'] ? '' : AlfaCatalogHelper::renderGroup(module: $module, params: $params, group: $group); ?>
            </div>
        <?php else : ?>
            <div class="panel" data-panel="<?php echo $group['key']; ?>" data-kind="<?php echo $group['kind']; ?>">
                <?php echo AlfaCatalogHelper::renderGroup(module: $module, params: $params, group: $group); ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

</div>
