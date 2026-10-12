<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Prepare For Cart Event (onPrepareForCart)
 *
 * Dispatched by the cart add and the order-line editor for every field that takes
 * a composition and was sent one, AFTER validation and BEFORE the line is
 * written. The plugin "materialises" its payload for storage: it persists any
 * uploaded files (so the stored payload references PATHS, never a base64 blob)
 * and returns the trimmed, storable payload. A plugin refuses a composition via
 * setError(); a plugin that throws refuses it too.
 *
 * Implementing onPrepareForCart is how a field plugin declares that its field
 * takes a composition at all: a field whose plugin has no such method takes none,
 * and a composition posted for it is refused before anything is stored.
 *
 *   // Boot-direct (item-fields-only, never globally subscribed) -> type the param,
 *   // no instanceof guard needed.
 *   public function onPrepareForCart(PrepareForCartEvent $event): void
 *   {
 *       $payload = json_decode($event->getPayload(), true) ?: [];
 *       foreach ($event->getFiles() as $slot => $file) {
 *           $payload['images'][$slot] = $this->store($file);  // -> path
 *       }
 *       $event->setStoredPayload($payload);
 *   }
 *
 * @since  1.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSelections;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class PrepareForCartEvent extends AbstractImmutableEvent
{
    use HasSelections;

    /**
     * The item being added to the cart.
     *
     * @since  1.3.0
     */
    public function getItem(): object
    {
        return $this->arguments['item'];
    }

    /**
     * The field definition row.
     *
     * @since  1.3.0
     */
    public function getField(): object
    {
        return $this->arguments['field'];
    }

    /**
     * The RAW full client payload for this field (untrusted): the composition to
     * be stored on the cart line, BEFORE files are turned into paths.
     *
     * @since  1.3.0
     */
    public function getPayload(): string
    {
        return (string) ($this->arguments['payload'] ?? '');
    }

    /**
     * Files uploaded for THIS field, normalised to slot => single-file array
     * (['name','type','tmp_name','error','size']). Empty when none were sent.
     *
     * @return array<int|string, array>
     *
     * @since  1.3.0
     */
    public function getFiles(): array
    {
        return (array) ($this->arguments['files'] ?? []);
    }

    /**
     * The per-item plugin config of this field.
     *
     * @since  1.3.0
     */
    public function getConfig(): Registry
    {
        return $this->arguments['config'] ?? new Registry();
    }

    /**
     * Reject the add with a buyer-facing message (hard failure).
     *
     * @param string $message Translated error message.
     *
     * @since  1.3.0
     */
    public function setError(string $message): void
    {
        $this->arguments['errorMessage'] = $message;
    }

    /**
     * @since  1.3.0
     */
    public function getError(): string
    {
        return $this->arguments['errorMessage'] ?? '';
    }

    /**
     * @since  1.3.0
     */
    public function isValid(): bool
    {
        return $this->getError() === '';
    }

    /**
     * Set the storable payload (files already turned into paths) persisted on the
     * cart line (#__alfa_cart_item_fields.payload).
     *
     * @param array|string $payload The data to persist (array is JSON-encoded).
     *
     * @since  1.3.0
     */
    public function setStoredPayload(array|string $payload): void
    {
        $this->arguments['storedPayloadResult'] = is_array($payload) ? json_encode($payload) : $payload;
    }

    /**
     * The payload to persist: the prepared one when set, else the raw payload
     * (a plain field that contributed nothing).
     *
     * @since  1.3.0
     */
    public function getStoredPayload(): string
    {
        return (string) ($this->arguments['storedPayloadResult'] ?? $this->getPayload());
    }
}
