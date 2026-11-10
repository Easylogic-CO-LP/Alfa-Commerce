<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;

/**
 * Order processing template: shown while waiting for external payment confirmation.
 *
 * This page is used by external gateway plugins (card, wallet and bank gateways)
 * that need a "waiting for payment" step between checkout and order completion.
 *
 * The plugin's onOrderProcessView() sets a layout which is rendered here.
 * Each plugin is responsible for including its own retry/cancel buttons
 * within its layout template if needed.
 *
 * Offline/instant plugins (e.g. Standard) should NOT set a layout in
 * onOrderProcessView(), which causes the HtmlView to skip this page
 * and redirect straight to default_order_completed. When the order's
 * gateway of record cannot be loaded, or its handler fails, the page
 * shows a neutral line instead and never moves on (2.2.0).
 */

if ($this->providerUnavailable) : ?>
    <p class="order-provider-unavailable"><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_ORDER_PROVIDER_UNAVAILABLE'); ?></p>
<?php elseif (!empty($this->event->onOrderProcessView)): ?>
    <div class="order-process">
        <?php
        echo Layout::render(
            $this->event->onOrderProcessView->getLayout(),
            $this->event->onOrderProcessView->getLayoutData(),
            $this->event->onOrderProcessView->getLayoutPluginType(),
            $this->event->onOrderProcessView->getLayoutPluginName(),
        );
        ?>
    </div>
<?php endif; ?>
