<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Tools > Security > API routes: every Alfa Commerce endpoint and who may call it. The report is built in the view
 * from a fixed-path partial (NOT the overridable layout system), so a template override cannot hide an endpoint, a
 * permission or an account that holds a token; this template only echoes it.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Apiroutes\HtmlView $this
 */

\defined('_JEXEC') or die;

$this->getDocument()->getWebAssetManager()->useStyle('com_alfa.admin');
?>
<div id="j-main-container" class="j-main-container">
    <?php echo $this->apiRoutesHtml; ?>
</div>
