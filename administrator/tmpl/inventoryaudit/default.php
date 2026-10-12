<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The inventory audit page. The report is rendered by the view from a fixed-path partial;
 * this template only echoes it, so an override cannot change what the audit reports.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Inventoryaudit\HtmlView $this
 */

\defined('_JEXEC') or die;
?>
<div class="p-3">
    <?php echo $this->reportHtml; ?>
</div>
