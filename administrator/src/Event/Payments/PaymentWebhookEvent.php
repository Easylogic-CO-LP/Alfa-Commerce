<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Administrator\Event\General\Traits\HasMethodArgumentTrait;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use BadMethodCallException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Gateway-webhook event (onPaymentWebhook), dispatched by the site PaymentController's
 * sessionless server-to-server entry. The twin of PaymentResponseEvent for the channel
 * with no buyer attached: instead of a redirect it carries the raw HTTP answer the
 * controller sends back to the gateway.
 *
 * Contract for the listening plugin:
 *  - VERIFY AUTHENTICITY YOURSELF: check the gateway signature / shared secret, or
 *    confirm through the gateway's verification API. Never trust amounts or statuses
 *    read from the raw payload without that verification.
 *  - BE IDEMPOTENT: gateways retry deliveries; processing the same notification
 *    twice must not complete, refund or charge an order twice.
 *  - ANSWER WITH THE RESPONSE CODE: 2xx acknowledges the notification and stops
 *    retries; 5xx asks the gateway to retry later.
 *
 * The subject is the loaded order, or NULL when the gateway payload must identify the
 * order itself (e.g. via the plugin's own transaction reference).
 *
 * @since  2.0.0
 */
class PaymentWebhookEvent extends PaymentsEvent
{
    use HasMethodArgumentTrait;

    /**
     * Constructor: requires 'method' argument; 'subject' may be NULL.
     *
     * @param string $name The event name
     * @param array $arguments Must include 'subject' (order or null) and 'method' (plugin element)
     *
     * @throws BadMethodCallException If 'subject' or 'method' is missing
     *
     * @since  2.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        // A null subject needs no special handling: GeneralEvent::onSetSubject
        // is ?object typed, so "not resolved yet" travels as itself.
        parent::__construct($name, $arguments);

        $this->requireMethodArgument($name);
    }

    /**
     * Get the order subject carried by the event: NULL when the plugin must
     * resolve the order from its own payload.
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
     * Set the HTTP status code answered to the gateway.
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
     * Get the HTTP status code answered to the gateway.
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
     * Set the response body answered to the gateway.
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
     * Get the response body answered to the gateway.
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
     * Convenience: answer the gateway with a JSON body; sets code, body and
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
