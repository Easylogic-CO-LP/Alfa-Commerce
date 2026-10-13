<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Administrator\Event\General\Traits\HasMethodArgumentTrait;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use BadMethodCallException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Carrier-return event (onShipmentResponse), dispatched by the site
 * ShipmentController, which has no view, so it is redirect-only.
 *
 * The shipments twin of PaymentResponseEvent, and deliberately the same
 * contract. Where a gateway sends the buyer back from a card form, a carrier
 * sends them back from a PICKER: a parcel-locker map, a pickup-point list, a
 * delivery-slot chooser. The plugin decides where they land next.
 *
 * 'method' names the plugin the URL addressed. The subject is NULL until the
 * plugin has authenticated the payload and resolved the order itself; a
 * browser return is untrusted input, and a dispatcher naming the order from it
 * would be choosing, on the carrier's behalf, which parcel a message is about.
 *
 * @since  2.0.0
 */
class ShipmentResponseEvent extends ShipmentsRedirectEvent
{
    use HasMethodArgumentTrait;

    /**
     * Constructor: requires 'method'; 'subject' may be NULL but must be present.
     *
     * @param string $name The event name
     * @param array $arguments Must include 'subject' (order or null) and 'method'
     *
     * @throws BadMethodCallException If 'subject' or 'method' is missing
     *
     * @since  2.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        $this->requireMethodArgument($name);
    }

    /**
     * Get the order the response is about: NULL until the plugin resolves it
     * from its own payload.
     *
     * @return Order|null The order, or null when unresolved
     *
     * @since  2.0.0
     */
    public function getOrder(): ?Order
    {
        $subject = $this->getSubject();

        return $subject === null ? null : Order::of($subject);
    }
}
