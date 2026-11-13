<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentResponseEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentWebhookEvent;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Payment controller: handles the storefront return from a payment gateway.
 *
 * @since  1.0.0
 */
class PaymentController extends BaseController
{
    use ProviderCallbacks;

    /**
     * Handle the payment gateway return: load the order stored in the session, dispatch the
     * onPaymentResponse event to the order's payment plugin, and follow any redirect it requests.
     * Redirects to the home page when the order id is missing or the order cannot be loaded.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function response()
    {
        $app = $this->app;

        // WHICH PLUGIN, from the URL, never from the order. The return URL a
        // gateway was handed carries &method=<element>, exactly as the webhook
        // route does, so routing costs no database read and does not depend on
        // an order this controller has no business loading.
        $paymentType = RequestScalar::cmd($this->input, 'method', '');

        // The plugin the address names, booted through the plugin helper (2.2.0): never another one. A plugin that
        // is disabled, missing or cannot be loaded is refused with a neutral message.
        $plugins = AlfaPluginHelper::get();

        try {
            $paymentPlugin = $plugins->bootSingle(PluginId::from('alfa-payments', $paymentType));
        } catch (PluginUnavailable|InvalidArgumentException) {
            $app->enqueueMessage(Text::_('COM_ALFA_PAYMENT_ORDER_ID_MISSING'), 'error');
            // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
            $app->redirect(Uri::root());

            return;
        }

        // subject = null, DELIBERATELY. The browser return is untrusted input,
        // and hydrating an order from it here would be this controller deciding
        // which payment a gateway message belongs to: gateway-specific
        // authentication, in gateway-agnostic code. Each plugin resolves and
        // authenticates its own message: a card gateway from its digest-verified
        // orderid, another gateway from its own reference or from the signed
        // ?id_order=&token= link via PaymentsPlugin::orderFromReturnLink().
        $paymentEvent = new PaymentResponseEvent('onPaymentResponse', [
            'subject' => null,
            'method' => $paymentType,
        ]);

        if (!$plugins->declares($paymentPlugin, 'onPaymentResponse')) {
            $app->redirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return;
        }

        // What leaves the handler is the handler's own failure (Joomla's error page, as before 2.2.0).
        $plugins->dispatchTo($paymentPlugin, $paymentEvent);

        if ($paymentEvent->hasRedirect()) {
            $app->redirect($paymentEvent->getRedirectUrl());
        }
    }

    /**
     * Sessionless server-to-server webhook entry for payment gateways (IPN/webhook):
     * index.php?option=com_alfa&task=payment.webhook&method=<element>[&id_order=..&token=..]
     * Boots the addressed payment plugin, dispatches onPaymentWebhook, and answers with
     * the HTTP response the plugin set on the event. Deliberately session-free: the
     * caller is the gateway's server, not the buyer's browser.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function webhook()
    {
        // No CSRF token check: webhooks are posted by the gateway's server, which
        // cannot carry a Joomla session token. Authenticity is the plugin's duty
        // (gateway signature / shared secret / verification API call).

        $paymentType = RequestScalar::cmd($this->input, 'method', '');

        $onWebhookPaymentEventName = 'onPaymentWebhook';

        // A DISABLED plugin is refused at its sessionless endpoint (2.2.0: 503 with Retry-After, so the provider
        // retries once it is enabled again, and an alert for staff), never handed the delivery.
        // subject = null, ALWAYS. This endpoint is addressed by the gateway's
        // server, and the only thing that can prove a message is genuine is the
        // gateway's own signature, which only the gateway's plugin knows how
        // to check. The token pair this used to verify here is never sent by a
        // real gateway callback (a static webhook URL carries no id_order at
        // all), so the check was skipped in the normal case and only ever
        // constrained our own test traffic.
        //
        // A plugin that wants the link hint asks for it itself, through
        // PaymentsPlugin::orderFromReturnLink().
        $paymentEvent = new PaymentWebhookEvent($onWebhookPaymentEventName, [
            'subject' => null,
            'method' => $paymentType,
        ]);

        // Delivered to the plugin of record only (2.2.0, design 2.15.5): unavailable, 503 with Retry-After and the
        // alert; a handler that throws, 500; no webhook handler, 404. Never a 2xx for a delivery not processed.
        $this->deliverWebhook('alfa-payments', $paymentType, $paymentEvent);
    }
}
