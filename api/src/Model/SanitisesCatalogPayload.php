<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Joomla\CMS\Factory;

/**
 * Makes a storefront-assembled item safe and serialisable for the API.
 *
 * The site models build the product page object: priced, variant-resolved,
 * with fields, media and the buyer's available methods. Two things stop that
 * going on the wire as-is.
 *
 *  1. VALUE OBJECTS SERIALISE TO {}: PriceResult, Money and Currency hold
 *     only private properties and implement no JsonSerializable, and
 *     JoomlaSerializer copies public properties only. There is no error and
 *     no log: the client just receives an empty object. Each one is converted
 *     through its own toArray().
 *  2. SECRETS: AlfaHelper::getFilteredMethods() selects m.*, so every
 *     payment/shipment method carries its decoded `params`, i.e. gateway
 *     merchant ids, API keys and client secrets. Those are stripped here.
 *
 * @since  1.9.0
 */
trait SanitisesCatalogPayload
{
    /**
     * Apply the request's pricing context to the session the site models read.
     *
     * PriceContext::fromSession() is baked into the site models, and an API
     * request has no storefront session, so without this every price silently
     * comes back in the default currency with no place: plausible-looking and
     * wrong for any multi-currency or per-place shop. Letting the caller pass
     * ?currency= and ?place= makes the dimension explicit instead of implied.
     *
     *
     * @since  1.9.0
     */
    protected function applyPricingContext(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $session = $app->getSession();

        if ($currencyId = $input->getInt('currency', 0)) {
            $session->set('alfa.currency_id', $currencyId);
        }

        if ($placeId = $input->getInt('place', 0)) {
            $session->set('alfa.location_id', $placeId);
        }
    }

    /**
     * Convert every non-serialisable value on a storefront item and strip the
     * method credentials.
     *
     * @param object $item Item assembled by a site model, modified in place.
     *
     *
     * @since  1.9.0
     */
    protected function sanitiseCatalogItem(object $item): void
    {
        // The scale an option's money delta is stated at: the priced result's currency, read before it is converted.
        $scale = ($item->price ?? null) instanceof PriceResult || ($item->price ?? null) instanceof Money
            ? $item->price->getCurrency()->getDecimalPlaces()
            : null;

        if (isset($item->price)) {
            $item->price = $item->price instanceof PriceResult
                ? self::catalogPrice(price: $item->price)
                : ($item->price instanceof Money ? $item->price->toArray() : $item->price);
        }

        foreach (['payment_methods', 'shipment_methods'] as $methodSet) {
            if (empty($item->$methodSet) || !\is_array($item->$methodSet)) {
                continue;
            }

            $item->$methodSet = array_values(array_map(
                static fn ($method): array => [
                    'id' => (int) ($method->id ?? 0),
                    'name' => $method->name ?? '',
                    'type' => $method->type ?? '',
                    'color' => $method->color ?? '',
                    'bg_color' => $method->bg_color ?? '',
                    'show_on_product' => $method->show_on_product ?? null,
                    'ordering' => (int) ($method->ordering ?? 0),
                ],
                $item->$methodSet,
            ));
        }

        // The resolver's verdict, flattened to plain scalars/arrays.
        if (isset($item->variant) && \is_object($item->variant)) {
            $variant = $item->variant;

            $item->variant = [
                'selection' => (array) ($variant->selection ?? []),
                'variantFieldIds' => array_map('intval', (array) ($variant->variantFieldIds ?? [])),
                'availableValueIds' => (array) ($variant->availableValueIds ?? []),
                'inStockValueIds' => (array) ($variant->inStockValueIds ?? []),
                'matchingCount' => (int) ($variant->matchingCount ?? 0),
                'complete' => (bool) ($variant->complete ?? false),
                'canAddToCart' => (bool) ($variant->canAddToCart ?? false),
                'activeCombinationId' => isset($variant->activeCombination->id)
                    ? (int) $variant->activeCombination->id
                    : null,
            ];
        }

        $this->projectFieldGraph(item: $item, scale: $scale);

        // Rendered HTML surfaces and the plugin payload bag are storefront-only.
        unset($item->surfaces, $item->fieldPayloads, $item->fieldSelections, $item->editLine);
    }

    /**
     * A priced result as the catalogue publishes it, its unit prices as data.
     *
     * A unit price is published as its exact value (the stated or payable unit
     * where the figure is that unit, else the line per piece at the fewest
     * decimals that give the line back), never the money of one piece: a payable
     * 0.00496 is "0.00496", not "0.00". Every figure is an exact decimal string
     * ({@see PublishedDecimal}). Its formatted text is the storefront's, in the
     * Auto form. minor_units is set only when the value sits on the scale.
     *
     * @param PriceResult $price The priced result.
     *
     *
     * @since  1.14.0
     */
    private static function catalogPrice(PriceResult $price): array
    {
        $payload = $price->toArray();
        $texts = PriceUnits::page(price: $price, figures: ['final', 'discount'], auto: true);
        $payload['price'] = PriceUnits::published(price: $price, figure: 'final', text: $texts['final']);
        $saving = PriceUnits::published(price: $price, figure: 'discount', text: $texts['discount']);
        $payload['savings']['price_amount'] = $saving['amount'];
        $payload['savings']['price_formatted'] = $saving['formatted'];

        return $payload;
    }

    /**
     * Project the field / option / combination graph to explicit safe keys.
     *
     * The raw rows carry a Registry (field->config, which serialises to {}),
     * back-office columns (checked_out, created_by), the raw params JSON string
     * beside its decoded twin, and whatever a field plugin chose to store:
     * the configurator3d plugin, for one, keeps admin_path/customer_path there.
     * Presentation params are therefore WHITELISTED rather than filtered: an
     * unknown key a plugin adds tomorrow stays private by default.
     *
     * An option's amount is a money delta ('+', '-') at the priced result's
     * currency scale, never fewer decimals ("5.00", "0.125"), or a percentage
     * ('%', '-%') in its own digits ("10"). With no priced result no scale is
     * stated, and a delta keeps its own digits.
     *
     * @param object $item The item being sanitised, modified in place.
     * @param int|null $scale The priced result's currency scale, or null when the item has none.
     *
     *
     * @since  1.9.0
     */
    private function projectFieldGraph(object $item, ?int $scale): void
    {
        // Keys a storefront genuinely needs to render a selector.
        static $fieldParamKeys = ['render_as', 'unavailable_display', 'filter_render_as', 'listing_render_as', 'showon', 'showon_effect'];
        static $valueParamKeys = ['swatch_color', 'swatch_image'];

        $pick = static function ($params, array $allowed): array {
            $params = \is_string($params) ? (json_decode($params, true) ?: []) : (array) $params;

            return array_intersect_key($params, array_flip($allowed));
        };

        $value = static fn ($v): ?array => $v === null ? null : [
            'id' => (int) ($v->id ?? 0),
            'item_field_id' => isset($v->item_field_id) ? (int) $v->item_field_id : null,
            'name' => $v->display_name ?? $v->name ?? '',
            'params' => $pick($v->params_decoded ?? $v->params ?? [], $valueParamKeys),
        ];

        if (!empty($item->fields) && \is_array($item->fields)) {
            $item->fields = array_values(array_map(
                static fn ($f): array => [
                    'id' => (int) ($f->id ?? 0),
                    'field_name' => $f->field_name ?? '',
                    'name' => $f->display_name ?? '',
                    'type' => $f->type ?? '',
                    'informational' => (int) ($f->informational ?? 0),
                    'required' => (int) ($f->required ?? 0),
                    'filterable' => (int) ($f->filterable ?? 0),
                    'show_on_listing' => (int) ($f->show_on_listing ?? 0),
                    'ordering' => (int) ($f->ordering ?? 0),
                    'params' => $pick($f->params_decoded ?? $f->params ?? [], $fieldParamKeys),
                    'values' => array_values(array_filter(array_map($value, $f->values ?? []))),
                ],
                $item->fields,
            ));
        }

        if (!empty($item->combinations) && \is_array($item->combinations)) {
            $item->combinations = array_values(array_map(
                static fn ($c): array => [
                    'id' => (int) ($c->id ?? 0),
                    'default_on' => (int) ($c->default_on ?? 0),
                    'sku' => $c->sku ?? '',
                    'gtin' => $c->gtin ?? '',
                    'mpn' => $c->mpn ?? '',
                    'stock' => $c->stock ?? null,
                    'available_quantity' => $c->available_quantity ?? null,
                    'in_stock' => (bool) ($c->in_stock ?? false),
                    'weight' => $c->weight ?? null,
                    'width' => $c->width ?? null,
                    'height' => $c->height ?? null,
                    'depth' => $c->depth ?? null,
                    'quantity_min' => $c->quantity_min ?? null,
                    'quantity_max' => $c->quantity_max ?? null,
                    'quantity_step' => $c->quantity_step ?? null,
                    'value_ids' => array_map('intval', $c->value_ids ?? []),
                    'values' => array_values(array_filter(array_map($value, $c->values ?? []))),
                ],
                $item->combinations,
            ));
        }

        $amount = static fn ($o): ?string => match (true) {
            !is_numeric($o->amount ?? null) => null,
            $scale !== null && \in_array($o->operation ?? null, ['+', '-'], true) => PublishedDecimal::unit(value: ExactAmount::ofNumber($o->amount), scale: $scale),
            default => PublishedDecimal::decimal(value: ExactAmount::ofNumber($o->amount)),
        };

        if (!empty($item->options) && \is_array($item->options)) {
            $item->options = array_values(array_map(
                static fn ($o): array => [
                    'id' => (int) ($o->id ?? 0),
                    'value_id' => (int) ($o->value_id ?? 0),
                    'operation' => $o->operation ?? null,
                    'amount' => $amount($o),
                    'apply_on' => $o->apply_on ?? null,
                    'ordering' => (int) ($o->ordering ?? 0),
                    'value' => $value($o->value ?? null),
                ],
                $item->options,
            ));
        }

        if (!empty($item->informational) && \is_array($item->informational)) {
            $item->informational = array_values(array_filter(array_map($value, $item->informational)));
        }
    }
}
