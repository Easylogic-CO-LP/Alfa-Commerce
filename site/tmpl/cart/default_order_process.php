<?php

use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;
use Joomla\CMS\Language\Text;

/**
 * Order processing template — shown while waiting for external payment confirmation.
 *
 * This page is used by external gateway plugins (Stripe, PayPal, Revolut, Viva, etc.)
 * that need a "waiting for payment" step between checkout and order completion.
 *
 * The plugin's onOrderProcessView() sets a layout which is rendered here,
 * along with a retry button in case the payment fails or times out.
 *
 * Offline/instant plugins (e.g. Standard) should NOT set a layout in
 * onOrderProcessView(), which causes the HtmlView to skip this page
 * and redirect straight to default_order_completed.
 */

if (!empty($this->event->onOrderProcessView)): ?>
    <div class="alfa-order-process">
        <div class="payment-process-view">
            <?php
            echo PluginLayoutHelper::pluginLayout(
                $this->event->onOrderProcessView->getLayoutPluginType(),
                $this->event->onOrderProcessView->getLayoutPluginName(),
                $this->event->onOrderProcessView->getLayout(),
            )->render($this->event->onOrderProcessView->getLayoutData());
            ?>
        </div>

        <div class="payment-retry">
            <button class="btn btn-primary" onclick="location.reload();">
                <?php echo Text::_('COM_ALFA_BUTTON_RETRY_PAYING'); ?>
            </button>
        </div>
    </div>
<?php endif; ?>
