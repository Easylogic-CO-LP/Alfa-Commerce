<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Validate Cart Add Event (onValidateCartAdd)
 *
 * Dispatched by the cart add for every payload-nature field of the item.
 * The plugin is the ONLY authority over its payload: it validates the raw
 * client value, rejects with setError(), and sets the SNAPSHOT that gets
 * stored on the order line plus the price impact computed SERVER-SIDE
 * (the client payload is never trusted for money):
 *
 *   public function onValidateCartAdd($event): void
 *   {
 *       if (!$event instanceof ValidateCartAddEvent) { return; }
 *       $payload = json_decode($event->getPayload(), true);
 *       if (!$payload) { $event->setError(Text::_('...INVALID')); return; }
 *       $event->setSnapshot($payload);
 *       $event->setPriceImpact('+', 5.0);  // optional delta on the line
 *   }
 *
 * Dispatch lands with the cart stage (task #7); the contract is fixed now
 * so plugins can already implement against it.
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSelections;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class ValidateCartAddEvent extends AbstractImmutableEvent
{
    use HasSelections;

    /**
     * The item being added to the cart.
     *
     * @since  2.0.0
     */
    public function getItem(): object
    {
        return $this->arguments['item'];
    }

    /**
     * The field definition row.
     *
     * @since  2.0.0
     */
    public function getField(): object
    {
        return $this->arguments['field'];
    }

    /**
     * The RAW client payload for this field (untrusted).
     *
     * @since  2.0.0
     */
    public function getPayload(): string
    {
        return (string) ($this->arguments['payload'] ?? '');
    }

    /**
     * The per-item plugin config of this field.
     *
     * @since  2.0.0
     */
    public function getConfig(): Registry
    {
        return $this->arguments['config'] ?? new Registry();
    }

    /**
     * The requested quantity.
     *
     * @since  2.0.0
     */
    public function getQuantity(): float
    {
        return (float) ($this->arguments['quantity'] ?? 1);
    }

    /**
     * Reject the add with a buyer-facing message.
     *
     * @param string $message Translated error message.
     *
     * @since  2.0.0
     */
    public function setError(string $message): void
    {
        $this->arguments['errorMessage'] = $message;
    }

    /**
     * @since  2.0.0
     */
    public function getError(): string
    {
        return $this->arguments['errorMessage'] ?? '';
    }

    /**
     * @since  2.0.0
     */
    public function isValid(): bool
    {
        return $this->getError() === '';
    }

    /**
     * Set the validated snapshot stored on the order line
     * (#__alfa_order_item_fields.payload). Defaults to the raw payload.
     *
     * @param array $snapshot The data to persist.
     *
     * @since  2.0.0
     */
    public function setSnapshot(array $snapshot): void
    {
        $this->arguments['snapshotResult'] = $snapshot;
    }

    /**
     * @since  2.0.0
     */
    public function getSnapshot(): array
    {
        return $this->arguments['snapshotResult'] ?? ['raw' => $this->getPayload()];
    }

    /**
     * Set the price impact this payload puts ON TOP of the line price:
     * same operation semantics as everywhere ('+', '-', '%', '-%').
     *
     * @param string $operation One of '+', '-', '%', '-%'.
     * @param float $amount The delta amount.
     *
     * @since  2.0.0
     */
    public function setPriceImpact(string $operation, float $amount): void
    {
        $this->arguments['impactResult'] = ['operation' => $operation, 'amount' => $amount];
    }

    /**
     * The price impact, or null when the payload is free.
     *
     * @return array{operation: string, amount: float}|null
     *
     * @since  2.0.0
     */
    public function getPriceImpact(): ?array
    {
        return $this->arguments['impactResult'] ?? null;
    }
}
