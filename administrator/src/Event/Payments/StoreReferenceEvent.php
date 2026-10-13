<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Store Payment Reference Event (recurring contract, step 1)
 *
 * Fired (boot-and-call, like every payments event) after a successful
 * CUSTOMER-PRESENT payment for a purchase that needs future merchant-
 * initiated charges (a subscription checkout). The gateway plugin extracts
 * whatever it will need to charge again later (e.g. for a card gateway, the initial
 * transaction id + sourceCode) and hands it back as an opaque array.
 *
 * The caller (subscriptions engine) persists the reference on ITS record;
 * plugins never store it themselves and never learn what it is for.
 *
 * Input:  getOrder(), getPayment() (the completed payment row)
 * Output: setReference(array) (opaque, JSON-serialized by the caller).
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class StoreReferenceEvent extends AbstractImmutableEvent
{
    /**
     * The order the customer just paid.
     *
     * @since  2.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * The completed `#__alfa_order_payments` row.
     *
     * @since  2.0.0
     */
    public function getPayment(): object
    {
        return $this->arguments['payment'];
    }

    /**
     * Hand back the opaque reference for future charges.
     *
     * The caller persists it as a `#__alfa_payment_references` vault row;
     * label and expiry become the row's customer-facing metadata.
     *
     * @param array $reference Gateway-specific data (e.g. for a card gateway:
     *                         ['transaction_id' => ..., 'source_code' => ...]).
     * @param string $label Customer-facing display, e.g. "Visa ---- 4242".
     * @param string|null $expiresAt Credential expiry (UTC SQL datetime) when
     *                               the gateway exposes it; enables expiry
     *                               pre-dunning. Null = unknown/none.
     *
     * @since  2.0.0
     */
    public function setReference(array $reference, string $label = '', ?string $expiresAt = null): void
    {
        $this->arguments['reference'] = $reference;
        $this->arguments['label'] = $label;
        $this->arguments['expiresAt'] = $expiresAt;
    }

    /**
     * Customer-facing label the plugin provided ('' when none).
     *
     * @since  2.0.0
     */
    public function getLabel(): string
    {
        return (string) ($this->arguments['label'] ?? '');
    }

    /**
     * Credential expiry (UTC SQL datetime) or null.
     *
     * @since  2.0.0
     */
    public function getExpiresAt(): ?string
    {
        return $this->arguments['expiresAt'] ?? null;
    }

    /**
     * The stored reference, or null when the plugin produced none.
     *
     *
     * @since  2.0.0
     */
    public function getReference(): ?array
    {
        return $this->arguments['reference'] ?? null;
    }

    /**
     * Whether the plugin produced a usable reference.
     *
     * @since  2.0.0
     */
    public function hasReference(): bool
    {
        return !empty($this->arguments['reference']);
    }
}
