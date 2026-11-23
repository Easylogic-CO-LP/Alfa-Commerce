<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One level of the categories. The visitor's category is marked aria-current="page"; the categories on its branch,
 * it included, carry data-state="open".
 *
 * @var list<object> $children The nodes of this level (AlfaCategoriesHelper::nodes(): id, name, link, count,
 *                   current, open, children).
 * @var callable(array): void $level Draws a level below.
 */

\defined('_JEXEC') or die;
?>
<ul class="tree">
    <?php foreach ($children as $node) : ?>
        <li class="node"<?php echo $node->open ? ' data-state="open"' : ''; ?>>
            <a class="link" href="<?php echo htmlspecialchars($node->link, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $node->current ? ' aria-current="page"' : ''; ?>>
                <span class="name"><?php echo htmlspecialchars($node->name, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($node->count !== null) : ?>
                    <span class="count"><?php echo $node->count; ?></span>
                <?php endif; ?>
            </a>
            <?php if ($node->children !== []) : ?>
                <?php $level($node->children); ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
