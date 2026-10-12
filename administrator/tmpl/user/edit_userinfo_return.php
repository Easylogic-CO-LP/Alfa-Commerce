<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * User Info popup success page: reloads the parent so the tab shows the
 * fresh rows (the modal dies with the reload; one view at a time).
 */

defined('_JEXEC') or die;
?>
<div class="text-center p-5">
    <div class="spinner-border text-primary" role="status"></div>
</div>
<script>
window.parent.location.reload();
</script>
