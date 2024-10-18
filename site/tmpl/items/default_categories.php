<?php

use \Joomla\CMS\Uri\Uri;

// Check if categories are returned
if (empty($this->categories)) { return; } ?>



<div class="list-container categories-list">

    <?php foreach ($this->categories as $category) { ?>

    <a class="list-item category-item" href="<?php echo Uri::root().'index.php?option=com_alfa&view=items&filter[category_id]='.$category->id;?>" >
        <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258">
        <h3><?php echo htmlspecialchars($category->name, ENT_QUOTES, 'UTF-8');?></h3>
    </a>

    <?php } ?>
</div>