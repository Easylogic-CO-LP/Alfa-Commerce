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
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Payment controller: handles the storefront return from a payment gateway.
 *
 * @since  1.0.0
 */
class PaymentController extends BaseController
{
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
        $paymentType = $this->input->getCmd('method', '');

        if ($paymentType === '' || !PluginHelper::isEnabled('alfa-payments', $paymentType)) {
            $app->enqueueMessage(Text::_('COM_ALFA_PAYMENT_ORDER_ID_MISSING'), 'error');
            // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
            $app->redirect(Uri::root());

            return;
        }

        // The gateway plugin may be uninstalled between checkout and return; a
        // missing plugin must not fatal the customer's return from the gateway.
        $paymentPlugin = $app->bootPlugin($paymentType, 'alfa-payments');

        if ($paymentPlugin === null || !method_exists($paymentPlugin, 'onPaymentResponse')) {
            $app->redirect(Route::_('index.php?option=com_alfa&view=cart', false));

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

        $paymentPlugin->onPaymentResponse($paymentEvent);

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
        $app = $this->app;

        // No CSRF token check: webhooks are posted by the gateway's server, which
        // cannot carry a Joomla session token. Authenticity is the plugin's duty
        // (gateway signature / shared secret / verification API call).

        $paymentType = $this->input->getCmd('method', '');

        $onWebhookPaymentEventName = 'onPaymentWebhook';

        // bootPlugin() never consults enabled; a DISABLED gateway must be dead
        // at its sessionless endpoint too, or disabling a compromised gateway
        // would not close it.
        if ($paymentType === '' || !PluginHelper::isEnabled('alfa-payments', $paymentType)) {
            $this->answerWebhook(404, 'Not found');
            return;
        }

        $paymentPlugin = $app->bootPlugin($paymentType, 'alfa-payments');

        // bootPlugin() returns a DummyPlugin (never null) for a missing
        // directory; method_exists is the only real existence test.
        if (!method_exists($paymentPlugin, $onWebhookPaymentEventName)) {
            $this->answerWebhook(404, 'Not found');
            return;
        }

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

        $paymentPlugin->{$onWebhookPaymentEventName}($paymentEvent);

        $this->answerWebhook(
            $paymentEvent->getResponseCode(),
            $paymentEvent->getResponseBody(),
            $paymentEvent->getContentType(),
        );
    }

    /**
     * Emit a raw HTTP answer to the gateway and terminate the request.
     *
     * @param int $code HTTP status code
     * @param string $body Response body
     * @param string $contentType Content-Type header value
     *
     *
     * @since  1.0.0
     */
    private function answerWebhook(int $code, string $body, string $contentType = 'text/plain; charset=utf-8'): void
    {
        $app = $this->app;

        $app->setHeader('status', $code, true);
        $app->setHeader('Content-Type', $contentType, true);
        $app->sendHeaders();

        echo $body;

        $app->close();
    }
}
