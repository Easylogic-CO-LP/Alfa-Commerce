<?php

extract($displayData);
if(!isset($method)) return;
?>

<div>
    <h5><?= $method->name; ?></h5>
    <p><?= $method->description; ?></p>
</div>