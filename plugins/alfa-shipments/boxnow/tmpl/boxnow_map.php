<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
$app = \Joomla\CMS\Factory::getApplication();
$doc = $app->getDocument();
$wa = $doc->getWebAssetManager();

// load the map script file
// on locker select it calls saveCurrentCartBoxNowData
$wa->registerAndUseScript('box-now-map', 'media/plg_alfa-shipments_boxnow/js/site/map.js'); //['defer' => true]);
?>

<div id="boxNowMapOuter">
    <div id="boxnowmap"></div>
    <a href="#" id="boxnow-map-open" class="boxnow-map-widget-button" style="display: none;">Open Box Now Map</a>
</div>