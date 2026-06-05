<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

// Check if categories exist
if (empty($this->categories)) return;

?>

<div class="list-container categories-list">

	<?php foreach ($this->categories as $category) { ?>

        <a class="list-item category-item"
           href="<?= $category->link ?>">
            <?php if (!empty($category->medias[0])): ?>
                <img class="category-item-img" src="<?= $category->medias[0]->url ?>"
                     alt="<?= htmlspecialchars($category->name, ENT_QUOTES, 'UTF-8') ?>"
                />
            <?php endif; ?>
            <h3 class="category-item-title"><?php echo htmlspecialchars($category->name, ENT_QUOTES, 'UTF-8'); ?></h3>
        </a>

	<?php } ?>
</div>