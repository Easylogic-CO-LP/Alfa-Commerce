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
 * Carrier-webhook event (onShipmentWebhook), dispatched by the site
 * ShipmentController's sessionless server-to-server entry.
 *
 * The twin of ShipmentResponseEvent for the channel with no buyer attached:
 * instead of a redirect it carries the raw HTTP answer the controller sends
 * back to the carrier. This is how tracking actually arrives (picked up, in
 * transit, delivered, returned to sender), and how an async label or a
 * pickup-point confirmation lands.
 *
 * Contract for the listening plugin:
 *  - VERIFY AUTHENTICITY YOURSELF: check the carrier's signature or shared
 *    secret, or confirm through its tracking API. A status read from an
 *    unverified payload is a status anybody on the internet can set.
 *  - BE IDEMPOTENT: carriers redeliver. Use
 *    {@see \Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin::settleAttempt()}
 *    so a repeated 'delivered' transitions once and answers 200 the second time.
 *  - ANSWER WITH THE RESPONSE CODE: 2xx acknowledges and stops retries; 5xx
 *    asks the carrier to try again later.
 *
 * The subject is NULL until the plugin resolves the parcel from its own
 * payload; a static webhook URL names no order, by design.
 *
 * @since  2.0.0
 */
class ShipmentWebhookEvent extends ShipmentsEvent
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
     * Get the order subject: NULL when the plugin must resolve it from its
     * own payload.
     *
     * @return Order|null The loaded order object, or null
     *
     * @since  2.0.0
     */
    public function getOrder(): ?Order
    {
        $subject = $this->getSubject();

        return $subject === null ? null : Order::of($subject);
    }

    /**
     * Set the HTTP status code answered to the carrier.
     *
     * @param int $code HTTP status code (2xx acknowledges, 5xx requests a retry)
     *
     * @since  2.0.0
     */
    public function setResponseCode(int $code): void
    {
        $this->arguments['responseCode'] = $code;
    }

    /**
     * Get the HTTP status code answered to the carrier.
     *
     * @return int Defaults to 200 (acknowledge)
     *
     * @since  2.0.0
     */
    public function getResponseCode(): int
    {
        return (int) ($this->arguments['responseCode'] ?? 200);
    }

    /**
     * Set the response body answered to the carrier.
     *
     * @param string $body Response body
     *
     * @since  2.0.0
     */
    public function setResponseBody(string $body): void
    {
        $this->arguments['responseBody'] = $body;
    }

    /**
     * Get the response body answered to the carrier.
     *
     * @return string Defaults to 'OK'
     *
     * @since  2.0.0
     */
    public function getResponseBody(): string
    {
        return (string) ($this->arguments['responseBody'] ?? 'OK');
    }

    /**
     * Set the Content-Type of the answer.
     *
     * @param string $contentType Content-Type header value
     *
     * @since  2.0.0
     */
    public function setContentType(string $contentType): void
    {
        $this->arguments['contentType'] = $contentType;
    }

    /**
     * Get the Content-Type of the answer.
     *
     * @return string Defaults to 'text/plain; charset=utf-8'
     *
     * @since  2.0.0
     */
    public function getContentType(): string
    {
        return (string) ($this->arguments['contentType'] ?? 'text/plain; charset=utf-8');
    }

    /**
     * Convenience: answer the carrier with a JSON body; sets code, body and
     * Content-Type in one call.
     *
     * @param array $payload Data to encode as the JSON body
     * @param int $code HTTP status code
     *
     * @since  2.0.0
     */
    public function setJsonResponse(array $payload, int $code = 200): void
    {
        $this->setResponseCode($code);
        $this->setResponseBody((string) json_encode($payload));
        $this->setContentType('application/json; charset=utf-8');
    }
}
