<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceMap;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Items\CartItemDisplayEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Site\Service\Data\PluginPayload;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Throwable;

defined('_JEXEC') or die;

/**
 * What each line shows, as its plugins describe it.
 *
 * Each chosen field's own plugin is asked how the line should present that
 * field; the app's summary layout stands in where a plugin says nothing. Then
 * every commercial plugin the customer's context admits is asked what it adds
 * to the line. The line carries the answers in its surfaces, per slot.
 *
 * The line is handed over as it is: a detached object the plugins may write
 * surfaces onto. That is the extension contract, and this class is only
 * where it is orchestrated.
 *
 * @since  2.0.0
 */
final class CartLinePresenter
{
    /**
     * @param User $customer Whose context decides which commercial plugins take part (2.2.0: the plugins are booted
     *                       through the plugin helper, so no application is handed in).
     *
     * @since  2.0.0
     */
    public function __construct(private readonly User $customer)
    {
    }

    /**
     * Fill in the line's surfaces.
     *
     * @param object $cartItem The line.
     * @param object $item The configured, priced product on it.
     * @param array $payloads The stored payloads, by field id.
     *
     * @since  2.0.0
     */
    public function decorate(object $cartItem, object $item, array $payloads): void
    {
        $fieldsById = [];

        foreach ($item->fields ?? [] as $f) {
            $fieldsById[(int) $f->id] = $f;
        }

        // The line's surfaces are collected here and handed to the line as a read-only map after each field (2.2.1): a
        // plugin holding the line reads what the others contributed and cannot change it.
        $slots = [];
        $cartItem->surfaces = new SurfaceMap();

        foreach ($cartItem->fields as $summary) {
            $fieldId = (int) $summary->field_id;
            $field = $fieldsById[$fieldId] ?? null;
            $fieldSurfaces = [];

            if ($field !== null) {
                // The field's own plugin, through the plugin helper (2.2.0, mode B).
                $plugins = AlfaPluginHelper::get();

                try {
                    $plugin = $plugins->bootSingle(PluginId::from('alfa-item-fields', (string) $field->type));
                } catch (Throwable $e) {
                    $plugin = null;
                }

                if ($plugin !== null && $plugins->declares($plugin, 'onCartItemView')) {
                    // READ THROUGH THE SHARED PAYLOAD READER, handed on as the
                    // plain array the event contract has always carried; the
                    // field plugin's own shape is not this layer's to reshape.
                    // What changes is the failure: `?: []` turned a truncated
                    // payload into an empty one and rendered a configured line
                    // as though nothing had been configured.
                    $payload = $payloads[$fieldId] ?? null;
                    $snapshot = (
                        \is_string($payload)
                        ? PluginPayload::of($payload, 'item field #' . $fieldId . ' payload')
                        : PluginPayload::fromArray((array) $payload, 'item field #' . $fieldId . ' payload')
                    )->toArray();

                    $event = new CartItemViewEvent('onCartItemView', [
                        'subject' => $cartItem,
                        'method' => $field,
                        'snapshot' => $snapshot,
                        'config' => $field->config ?? new Registry($field->params_decoded ?? []),
                    ]);

                    $plugins->dispatchTo($plugin, $event);

                    $fieldSurfaces = Surfaces::collect($event, 'alfa-item-fields', (string) $field->type, CartSurface::Main->value);
                }
            }

            if (!isset($fieldSurfaces[CartSurface::Main->value])) {
                $fieldSurfaces[CartSurface::Main->value] = [
                    'layout' => 'cart.field',
                    'layoutData' => [
                        'label' => $summary->field,
                        'value' => $summary->value,
                        'has_payload' => $summary->has_payload,
                    ],
                    'layoutPluginType' => '',
                    'layoutPluginName' => '',
                ];
            }

            // Every descriptor here, the cart.field fallback included, is this
            // field's, so a template can pick or skip it (Surface::render()).
            foreach ($fieldSurfaces as $slot => $descriptor) {
                $descriptor['showon'] = '';
                $descriptor['fieldId'] = $fieldId;
                $descriptor['fieldName'] = $field !== null ? (string) ($field->field_name ?? '') : null;
                $slots[$slot][] = $descriptor;
            }

            $cartItem->surfaces = new SurfaceMap($slots);
        }

        // Feature plugins (onCartItemDisplay, 2.2.0): one event for the line, each
        // `alfa` plugin the customer's view levels admit called once, in their order, through
        // its own targeted declaration; a plugin that throws is logged and the next one runs.
        $plugins = AlfaPluginHelper::get();
        $displayEvent = new CartItemDisplayEvent('onCartItemDisplay', [
            'line' => $cartItem,
            'item' => $item,
        ]);
        $plugins->dispatchToEachIsolated($plugins->admitted($this->customer, 'alfa'), $displayEvent);
        $displayEvent->harvestInto($slots);
        $cartItem->surfaces = new SurfaceMap($slots);
    }
}
