<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Shipments\ShipmentResponseEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ShipmentWebhookEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;

/**
 * Shipment controller: the component-level entry point carriers come back to.
 *
 * WHY THE COMPONENT OWNS THIS. A Joomla plugin has no router and no controller
 * of its own, so a carrier cannot be pointed at one; there is no URL that
 * reaches a plugin directly. Carriers in another shop system declare their own routes in
 * etc/frontend/routes.xml; a Joomla shipment plugin cannot, which is exactly
 * why this exists. The component provides the two channels and addresses the
 * plugin by name.
 *
 * TRANSPORT ONLY, like its payments twin. It routes on `&method=` and hands
 * the plugin an event; it does not load an order, does not decide which parcel
 * a message is about, and does not authenticate anything. Authenticity is
 * carrier-specific (a signature, a shared secret, a callback to the carrier's
 * own tracking API) and only the carrier's plugin knows how to check it.
 *
 * @since  2.0.0
 */
class ShipmentController extends BaseController
{
    /**
     * The buyer's return from a carrier-hosted picker.
     *
     * A parcel-locker map, a pickup-point list, a delivery-slot chooser: the
     * buyer leaves the checkout, chooses on the carrier's page, and comes back
     * here. The plugin decides what that means and where they go next.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function response()
    {
        $app = $this->app;

        // WHICH PLUGIN, from the URL, never from the order. The return URL a
        // carrier was handed carries &method=<element>, exactly as the webhook
        // route does, so routing costs no database read and does not depend on
        // an order this controller has no business loading.
        $shipmentType = $this->input->getCmd('method', '');

        if ($shipmentType === '' || !PluginHelper::isEnabled('alfa-shipments', $shipmentType)) {
            $app->enqueueMessage(Text::_('COM_ALFA_SHIPMENT_METHOD_MISSING'), 'error');
            $app->redirect(Route::_('/index.php'));

            return;
        }

        // The plugin may be uninstalled between checkout and return; a missing
        // plugin must not fatal the buyer's return from the carrier.
        $shipmentPlugin = $app->bootPlugin($shipmentType, 'alfa-shipments');

        if (!method_exists($shipmentPlugin, 'onShipmentResponse')) {
            $app->redirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return;
        }

        // subject = null, DELIBERATELY. See the class docblock: resolving the
        // order here would be this controller deciding which parcel a carrier
        // message belongs to, in carrier-agnostic code.
        $shipmentEvent = new ShipmentResponseEvent('onShipmentResponse', [
            'subject' => null,
            'method' => $shipmentType,
        ]);

        $shipmentPlugin->onShipmentResponse($shipmentEvent);

        if ($shipmentEvent->hasRedirect()) {
            $app->redirect($shipmentEvent->getRedirectUrl());
        }
    }

    /**
     * Sessionless server-to-server webhook entry for carriers:
     * index.php?option=com_alfa&task=shipment.webhook&method=<element>
     *
     * This is how tracking actually arrives (picked up, in transit, delivered,
     * returned to sender) and how an async label or pickup-point confirmation
     * lands. Deliberately session-free: the caller is the carrier's server, not
     * the buyer's browser.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function webhook()
    {
        $app = $this->app;

        // No CSRF token check: webhooks are posted by the carrier's server,
        // which cannot carry a Joomla session token. Authenticity is the
        // plugin's duty (carrier signature / shared secret / tracking API).

        $shipmentType = $this->input->getCmd('method', '');

        $onWebhookShipmentEventName = 'onShipmentWebhook';

        // bootPlugin() never consults enabled; a DISABLED carrier must be dead
        // at its sessionless endpoint too, or disabling a compromised plugin
        // would not close it.
        if ($shipmentType === '' || !PluginHelper::isEnabled('alfa-shipments', $shipmentType)) {
            $this->answerWebhook(404, 'Not found');

            return;
        }

        $shipmentPlugin = $app->bootPlugin($shipmentType, 'alfa-shipments');

        // bootPlugin() returns a DummyPlugin (never null) for a missing
        // directory; method_exists is the only real existence test.
        if (!method_exists($shipmentPlugin, $onWebhookShipmentEventName)) {
            $this->answerWebhook(404, 'Not found');

            return;
        }

        // subject = null, ALWAYS. A static webhook URL names no parcel, and the
        // only thing that can prove a message is genuine is the carrier's own
        // signature, which only the carrier's plugin knows how to check.
        $shipmentEvent = new ShipmentWebhookEvent($onWebhookShipmentEventName, [
            'subject' => null,
            'method' => $shipmentType,
        ]);

        $shipmentPlugin->{$onWebhookShipmentEventName}($shipmentEvent);

        $this->answerWebhook(
            $shipmentEvent->getResponseCode(),
            $shipmentEvent->getResponseBody(),
            $shipmentEvent->getContentType(),
        );
    }

    /**
     * Emit a raw HTTP answer to the carrier and terminate the request.
     *
     * @param int $code HTTP status code
     * @param string $body Response body
     * @param string $contentType Content-Type header value
     *
     *
     * @since  2.0.0
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
