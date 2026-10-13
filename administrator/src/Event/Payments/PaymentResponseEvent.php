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
 * Gateway-return event (onPaymentResponse), dispatched by the site PaymentController
 * which has no view, so it is redirect-only (no layout). The plugin decides where to
 * send the buyer after the bank; to show a result page, redirect to a view layout.
 *
 * The twin of PaymentWebhookEvent, and deliberately the same contract: 'method' names
 * the plugin the URL addressed, and the subject is NULL until the plugin has
 * authenticated the payload and resolved the order itself. The browser return is
 * untrusted input; the dispatcher naming the order from it would be choosing a
 * payment on the gateway's behalf.
 *
 * @since  1.0.0
 */
class PaymentResponseEvent extends PaymentsRedirectEvent
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
     * Get the order the buyer returned for: NULL until the plugin resolves it
     * from its own payload.
     *
     * @return Order|null The order, or null when unresolved
     *
     * @since  1.0.0
     */
    public function getOrder(): ?Order
    {
        $subject = $this->getSubject();

        return $subject === null ? null : Order::of($subject);
    }
}
