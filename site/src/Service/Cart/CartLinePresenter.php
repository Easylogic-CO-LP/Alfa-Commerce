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
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Items\CartItemDisplayEvent;
use Alfa\Component\Alfa\Administrator\Plugin\ItemFieldsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Cart\CommercialPlugins;
use Alfa\Component\Alfa\Site\Service\Data\PluginPayload;
use Joomla\CMS\Application\CMSApplication;
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
 * @since  1.10.0
 */
final class CartLinePresenter
{
    /**
     * @param CMSApplication $app Boots the plugins.
     * @param User $customer Whose context decides which commercial plugins take part.
     *
     * @since  1.10.0
     */
    public function __construct(private readonly CMSApplication $app, private readonly User $customer)
    {
    }

    /**
     * Fill in the line's surfaces.
     *
     * @param object $cartItem The line.
     * @param object $item The configured, priced product on it.
     * @param array $payloads The stored payloads, by field id.
     *
     * @since  1.10.0
     */
    public function decorate(object $cartItem, object $item, array $payloads): void
    {
        $fieldsById = [];

        foreach ($item->fields ?? [] as $f) {
            $fieldsById[(int) $f->id] = $f;
        }

        $cartItem->surfaces = [];

        foreach ($cartItem->fields as $summary) {
            $fieldId = (int) $summary->field_id;
            $field = $fieldsById[$fieldId] ?? null;
            $fieldSurfaces = [];

            if ($field !== null) {
                try {
                    $plugin = $this->app->bootPlugin((string) $field->type, 'alfa-item-fields');
                } catch (Throwable $e) {
                    $plugin = null;
                }

                if ($plugin instanceof ItemFieldsPlugin) {
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

                    $plugin->onCartItemView($event);

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
                $cartItem->surfaces[$slot][] = $descriptor;
            }
        }

        foreach (CommercialPlugins::for($this->customer) as $alfaPlugin) {
            $plugin = $this->app->bootPlugin($alfaPlugin->name, 'alfa');

            if (!method_exists($plugin, 'onCartItemDisplay')) {
                continue;
            }

            $displayEvent = new CartItemDisplayEvent('onCartItemDisplay', [
                'line' => $cartItem,
                'item' => $item,
            ]);
            $plugin->onCartItemDisplay($displayEvent);

            Surfaces::appendTo($cartItem->surfaces, $displayEvent, 'alfa', $alfaPlugin->name, CartSurface::Main->value);
        }
    }
}
