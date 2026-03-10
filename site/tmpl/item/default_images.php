<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.glightbox')
	->useStyle('com_alfa.keen-slider')
	->useScript('com_alfa.glightbox')
	->useScript('com_alfa.keen-slider');
?>
<div class="main-images navigation-controls">
    <a class="item-image" href="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>">
        <img src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
             alt="Image"
             width="600"
             height="600"
             fetchpriority="high">
    </a>
    <a class="item-image" href="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>">
        <img src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
             alt="Image"
             width="600"
             height="600"
             loading="lazy">
    </a>
    <a class="item-image" href="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>">
        <img src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
             alt="Image"
             width="600"
             height="600"
             loading="lazy">
    </a>
    <a class="item-image" href="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>">
        <img src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
             alt="Image"
             width="600"
             height="600"
             loading="lazy">
    </a>
</div>
<div class="thumbnail-images keen-slider d-flex flex-row mt-3">
    <img class="item-image"
         src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
         alt="Image"
         width="100"
         height="100"
         loading="lazy"
    >
    <img class="item-image"
         src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
         alt="Image"
         width="100"
         height="100"
         loading="lazy"
    >
    <img class="item-image"
         src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
         alt="Image"
         width="100"
         height="100"
         loading="lazy"
    >
    <img class="item-image"
         src="<?= Uri::root().'/media/com_alfa/images/placeholder_600x.webp' ?>"
         alt="Image"
         width="100"
         height="100"
         loading="lazy"
    >
</div>