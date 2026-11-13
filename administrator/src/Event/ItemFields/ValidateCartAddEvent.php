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
 * client value and rejects the add with setError() (the client payload is
 * never trusted):
 *
 *   public function onValidateCartAdd($event): void
 *   {
 *       if (!$event instanceof ValidateCartAddEvent) { return; }
 *       $payload = json_decode($event->getPayload(), true);
 *       if (!$payload) { $event->setError(Text::_('...INVALID')); return; }
 *   }
 *
 * The event only validates. What the order line keeps is the field's own answer to
 * onPrepareForCart, and what the payload costs is its contribution to
 * onCalculateItemPrice, computed on the server. 2.2.1 removed setSnapshot(),
 * getSnapshot(), setPriceImpact() and getPriceImpact(): core never read what they
 * held, so a value set through them changed nothing.
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
}
