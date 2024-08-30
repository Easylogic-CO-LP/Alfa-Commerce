<?php

// use Joomla\CMS\Factory;
// use Joomla\CMS\HTML\HTMLHelper;

$price = $displayData;

?>

<p><strong>Base Price:</strong> <?php echo($price['base_price']); ?></p>
<p><strong>Discounted Price:</strong> <?php echo($price['discounted_price']); ?></p>
<p><strong>Price with Tax:</strong> <?php echo($price['price_with_tax']); ?></p>