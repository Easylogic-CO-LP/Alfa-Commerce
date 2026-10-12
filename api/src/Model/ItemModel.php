<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Model\ItemModel as AdminItemModel;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;

/**
 * API Item model: the admin model plus resolved translations.
 *
 * Admin getItem() leaves translatable fields to the form layer (per-language
 * keys); an API item response needs them resolved in the request language.
 *
 * @since  1.9.0
 */
class ItemModel extends AdminItemModel
{
    /**
     * Get the item with translatable fields resolved to the current language.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.9.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->id)) {
            foreach (['name', 'alias', 'short_desc', 'full_desc', 'meta_title', 'meta_desc'] as $field) {
                $item->$field = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_items',
                    primaryColumnName: 'id_item',
                    field:             $field,
                );
            }

            // Full per-language map ({"en_gb": {...}, "el_gr": {...}}) so API
            // clients can read every translation and PATCH back the same
            // per-language keys the admin form posts (name_el_gr, ...).
            $item->translations = MultilingualHelper::getMultilingualData(
                currentId:         (int) $item->id,
                primaryColumnName: 'id_item',
                tableName:         '#__alfa_items',
            );

            $this->attachFieldGraph($item);
        }

        return $item;
    }

    /**
     * Attach the item's field / option / combination / informational graph.
     *
     * ITEM ROUTE ONLY: JsonApiView::displayList() runs prepareItem() per row,
     * so doing this on a list would multiply four batch queries by the page
     * size. ItemFieldsHelper is the same loader the storefront product page
     * uses and is safe here: its only params read goes through
     * ComponentHelper, not the SiteApplication-only $app->getParams().
     *
     * Everything is projected to explicit scalars. The raw rows carry Registry
     * objects (field->config) and decoded params blobs that either serialise
     * to {} or leak plugin-internal paths, so they are never passed through.
     *
     * @param object $item The item to enrich, modified in place.
     *
     *
     * @since  1.9.0
     */
    private function attachFieldGraph(object $item): void
    {
        $data = ItemFieldsHelper::getDataForItem((int) $item->id);

        $value = static fn ($v): ?array => $v === null ? null : [
            'id' => (int) ($v->id ?? 0),
            'item_field_id' => isset($v->item_field_id) ? (int) $v->item_field_id : null,
            'name' => $v->display_name ?? $v->name ?? '',
            'ordering' => isset($v->ordering) ? (int) $v->ordering : null,
        ];

        $item->fields = array_values(array_map(
            static fn ($f): array => [
                'id' => (int) ($f->id ?? 0),
                'field_name' => $f->field_name ?? '',
                'name' => $f->display_name ?? '',
                'type' => $f->type ?? '',
                'informational' => (int) ($f->informational ?? 0),
                'required' => (int) ($f->required ?? 0),
                'ordering' => (int) ($f->ordering ?? 0),
                'values' => array_values(array_filter(array_map($value, $f->values ?? []))),
            ],
            $data->fields ?? [],
        ));

        $item->combinations = array_values(array_map(
            static fn ($c): array => [
                'id' => (int) ($c->id ?? 0),
                'default_on' => (int) ($c->default_on ?? 0),
                'sku' => $c->sku ?? '',
                'gtin' => $c->gtin ?? '',
                'mpn' => $c->mpn ?? '',
                'stock' => $c->stock,
                'available_quantity' => $c->available_quantity,
                'manage_stock' => $c->manage_stock,
                'stock_action' => $c->stock_action,
                'backorder' => $c->backorder,
                'min_qty' => $c->min_qty,
                'weight' => $c->weight,
                'width' => $c->width,
                'height' => $c->height,
                'depth' => $c->depth,
                'quantity_min' => $c->quantity_min,
                'quantity_max' => $c->quantity_max,
                'quantity_step' => $c->quantity_step,
                'in_stock' => (bool) ($c->in_stock ?? false),
                'value_ids' => array_map('intval', $c->value_ids ?? []),
                'values' => array_values(array_filter(array_map($value, $c->values ?? []))),
            ],
            $data->combinations ?? [],
        ));

        $item->options = array_values(array_map(
            static fn ($o): array => [
                'id' => (int) ($o->id ?? 0),
                'value_id' => (int) ($o->value_id ?? 0),
                'operation' => $o->operation ?? null,
                'amount' => $o->amount ?? null,
                'apply_on' => $o->apply_on ?? null,
                'ordering' => (int) ($o->ordering ?? 0),
                'value' => $value($o->value ?? null),
            ],
            $data->options ?? [],
        ));

        $item->informational = array_values(array_map(
            static fn ($i): array => [
                'id' => (int) ($i->id ?? 0),
                'item_field_id' => isset($i->item_field_id) ? (int) $i->item_field_id : null,
                'value_id' => isset($i->value_id) ? (int) $i->value_id : null,
                'name' => $i->display_name ?? $i->name ?? '',
            ],
            $data->informational ?? [],
        ));
    }
}
