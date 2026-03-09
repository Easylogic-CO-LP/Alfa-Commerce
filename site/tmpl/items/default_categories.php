<?php

use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

// Check if categories exist
if (empty($this->categories)) return;

?>

<div class="list-container categories-list">

	<?php foreach ($this->categories as $category) { ?>

        <a class="list-item category-item"
           href="<?= $category->link ?>">
            <img src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
                 alt="<?= $category->name ?>"
            />
            <h3><?php echo htmlspecialchars($category->name, ENT_QUOTES, 'UTF-8'); ?></h3>
        </a>

	<?php } ?>
</div>