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
 * @var list<object> $children The nodes of this level: id, name, link, count (null when counts are off), current,
 *                   open, children
 * @var callable(array): void $level Draws a level below
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
?>
<ul class="tree">
    <?php foreach ($children as $node) : ?>
        <?php // A link must say what it leads to: a category never translated into the active language is named by its id.?>
        <?php $name = trim((string) $node->name) ?: Text::sprintf('MOD_ALFA_CATALOG_CATEGORY_UNNAMED', (int) $node->id); ?>
        <li class="node"<?php echo $node->open ? ' data-state="open"' : ''; ?>>
            <a class="entry-link" href="<?php echo htmlspecialchars((string) $node->link, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $node->current ? ' aria-current="page"' : ''; ?>>
                <span class="entry-name"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($node->count !== null) : ?>
                    <span class="entry-count"><?php echo (int) $node->count; ?></span>
                <?php endif; ?>
            </a>
            <?php if ($node->children !== []) : ?>
                <?php $level($node->children); ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
