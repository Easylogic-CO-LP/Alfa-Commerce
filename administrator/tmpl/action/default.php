<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * Echo the plugin action's layout, like a frontend layout, on its own
 * standalone (tmpl=component) page. The plugin registered its scripts/styles
 * via WAM during the view dispatch, so they are already in this page's head.
 * Any footer buttons declared on the event (addButton) render below it.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Action\HtmlView $this
 */
$event = $this->actionEvent;
?>
<div class="alfa-action p-2">
    <?php
    if ($event && $event->getLayout()) {
        echo PluginLayoutHelper::pluginLayout($this->group, $this->type, $event->getLayout())
            ->render($event->getLayoutData());
    }
    ?>
</div>
<?php
echo LayoutHelper::render('action.buttons', ['buttons' => $event->getButtons()], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
?>
