<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Order processing template: shown while waiting for external payment confirmation.
 *
 * This page is used by external gateway plugins (card, wallet and bank gateways)
 * that need a "waiting for payment" step between checkout and order completion.
 *
 * The plugin's onOrderProcessView() draws the page through
 * $event->orderProcess(); the view hands it here as $this->gatewayBlock.
 * Each plugin is responsible for including its own retry/cancel buttons
 * within its layout template if needed.
 *
 * Offline/instant plugins (e.g. Standard) draw nothing in
 * onOrderProcessView(), which causes the HtmlView to skip this page
 * and redirect straight to default_order_completed. When the order's
 * gateway of record cannot be loaded, or its handler fails, the page
 * shows a neutral line instead and never moves on; never a dead
 * end either: the order's number above the line, and under it
 * Try again (this same address, which asks the gateway again) and
 * Continue shopping, in the order pages' look.
 */

if ($this->providerUnavailable) :
    $this->getDocument()->getWebAssetManager()->useStyle('com_alfa.order-completed'); ?>
    <div class="cart-wrapper order-completed order-process-unavailable">
        <p class="order-number"><?php echo Text::sprintf('COM_ALFA_ORDER_COMPLETED_NUMBER', $this->escape($this->orderNumber)); ?></p>
        <p class="order-provider-unavailable"><?php echo Text::_('COM_ALFA_ORDER_PROVIDER_UNAVAILABLE'); ?></p>
        <div class="order-completed-actions">
            <a class="action primary" href="<?php echo $this->retryUrl; ?>"><?php echo Text::_('COM_ALFA_ORDER_PROVIDER_RETRY'); ?></a>
            <a class="action secondary" href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>
        </div>
    </div>
<?php elseif ($this->gatewayBlock !== null): ?>
    <div class="order-process">
        <?php
        echo Layout::render(
            $this->gatewayBlock['layout'],
            $this->gatewayBlock['layoutData'],
            $this->gatewayBlock['layoutPluginType'],
            $this->gatewayBlock['layoutPluginName'],
        );
        ?>
    </div>
<?php endif; ?>
