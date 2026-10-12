<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The panel page: just the owning plugin's screen body. The Alfa menu is drawn into
 * Joomla's native sidebar by MenuInjector, and the toolbar/title into the native toolbar,
 * so the page itself is only the body the plugin chose (its list or its edit form).
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Panel\HtmlView $this
 */

\defined('_JEXEC') or die;
?>
<div class="alfa-panel alfa-panel--<?php echo htmlspecialchars(preg_replace('/[^a-z0-9_-]/i', '', $this->screen), ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo $this->body; ?>
</div>
