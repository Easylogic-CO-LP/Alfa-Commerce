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
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

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
    use ProviderCallbacks;

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

        // The plugin the address names, booted through the plugin helper (2.2.0): never another one. A plugin that
        // is disabled, missing or cannot be loaded is refused with a neutral message.
        $plugins = AlfaPluginHelper::get();

        try {
            $shipmentPlugin = $plugins->bootSingle(PluginId::from('alfa-shipments', $shipmentType));
        } catch (PluginUnavailable|InvalidArgumentException) {
            $app->enqueueMessage(Text::_('COM_ALFA_SHIPMENT_METHOD_MISSING'), 'error');
            // The shop's home page under its own path (a Joomla in a subfolder keeps it); never the page itself.
            $app->redirect(Uri::root());

            return;
        }

        // subject = null, DELIBERATELY. See the class docblock: resolving the
        // order here would be this controller deciding which parcel a carrier
        // message belongs to, in carrier-agnostic code.
        $shipmentEvent = new ShipmentResponseEvent('onShipmentResponse', [
            'subject' => null,
            'method' => $shipmentType,
        ]);

        if (!$plugins->declares($shipmentPlugin, 'onShipmentResponse')) {
            $app->redirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return;
        }

        // What leaves the handler is the handler's own failure (Joomla's error page, as before 2.2.0).
        $plugins->dispatchTo($shipmentPlugin, $shipmentEvent);

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
        // No CSRF token check: webhooks are posted by the carrier's server,
        // which cannot carry a Joomla session token. Authenticity is the
        // plugin's duty (carrier signature / shared secret / tracking API).

        $shipmentType = $this->input->getCmd('method', '');

        $onWebhookShipmentEventName = 'onShipmentWebhook';

        // A DISABLED plugin is refused at its sessionless endpoint (2.2.0: 503 with Retry-After, so the provider
        // retries once it is enabled again, and an alert for staff), never handed the delivery.
        // subject = null, ALWAYS. A static webhook URL names no parcel, and the
        // only thing that can prove a message is genuine is the carrier's own
        // signature, which only the carrier's plugin knows how to check.
        $shipmentEvent = new ShipmentWebhookEvent($onWebhookShipmentEventName, [
            'subject' => null,
            'method' => $shipmentType,
        ]);

        // Delivered to the plugin of record only (2.2.0, design 2.15.5): unavailable, 503 with Retry-After and the
        // alert; a handler that throws, 500; no webhook handler, 404. Never a 2xx for a delivery not processed.
        $this->deliverWebhook('alfa-shipments', $shipmentType, $shipmentEvent);
    }
}
