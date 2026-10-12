<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\PrepareForCartEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ValidateCartAddEvent;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Variant\CombinationResolver;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The ONE configuration pipeline for a configured item selection, shared by
 * the storefront cart add (CartLineConfiguration) and the admin order-line editor
 * (OrderModel), so validation, payload materialisation and row building can
 * never drift between the two.
 *
 * Every method is side-effect-free towards the caller's messaging: errors are
 * RETURNED (never enqueued), because the storefront addresses a buyer and the
 * admin addresses an operator.
 *
 * A field takes a composition (a payload, its full form, its uploads) only when
 * its field plugin implements the payload handler, onPrepareForCart
 * ({@see self::payloadFields()}); a composition posted for any other field
 * refuses the configuration on both writers ({@see self::compositionRefusal()}).
 *
 * @since  1.3.0
 */
class ItemConfigurationHelper
{
    /**
     * Per-request cache of each item's field overrides (itemId => fieldId => Registry).
     *
     * @var array<int, array<int, \Joomla\Registry\Registry>>
     *
     * @since  1.3.0
     */
    protected static array $requiredOverrides = [];

    /**
     * Validate a selection before it may be stored: a combination product needs
     * a resolved combination AND a value in the request for every dimension that
     * has something to choose: the default combination the resolver fills for
     * display is never a buyer's choice; a fixed dimension (one value) is filled
     * and never counts as missing. Then each field's plugin gets onValidateCartAdd
     * to reject an invalid/incomplete configuration.
     *
     * A refusal comes back as its language key and parameters, so each reader words it for its
     * own audience ({@see self::refusalText()}); only a field plugin's own sentence comes back
     * as text, since only the plugin can word it.
     *
     * Before any of that, a composition posted for a field that takes none refuses the
     * selection ({@see self::compositionRefusal()}): no field and no plugin ever judges it.
     *
     * @param object $item The hydrated item (fields + combinations).
     * @param int|null $combinationId The resolved combination (null = none).
     * @param array $request requestSelection() output.
     * @param float $quantity Requested quantity.
     *
     * @return array{key: ?string, params: list<string>, text: ?string}|null The refusal, exactly one of
     *                                                                       key and text set; null when valid.
     * @since  1.3.0
     */
    public static function validate(object $item, ?int $combinationId, array $request, float $quantity): ?array
    {
        $request = self::withFixedDimensions(item: $item, request: $request);

        $refusal = self::compositionRefusal(item: $item, request: $request);

        if ($refusal !== null) {
            return $refusal;
        }

        if (!empty($item->combinations)) {
            if (!$combinationId) {
                return ['key' => 'COM_ALFA_CART_CHOOSE_OPTIONS', 'params' => [], 'text' => null];
            }

            foreach (array_keys(CombinationResolver::dimensionValues($item->fields, $item->combinations)) as $fieldId) {
                if (empty($request['selection'][$fieldId])) {
                    return ['key' => 'COM_ALFA_CART_CHOOSE_OPTIONS', 'params' => [], 'text' => null];
                }
            }
        }

        // REQUIRED fields (global flag, item-level override wins): the buyer
        // must have picked a value (variant/option) or provided a plugin
        // configuration before the line may enter.
        foreach ($item->fields as $field) {
            if (!self::isFieldRequired(itemId: (int) $item->id, field: $field)) {
                continue;
            }

            $fid = (int) $field->id;
            $hasChoice = !empty($request['selection'][$fid]) || !empty($request['optionValueIds'][$fid]);
            $hasPayload = self::isComposition(payload: $request['payloads'][$fid] ?? '');

            if (!$hasChoice && !$hasPayload) {
                return ['key' => 'COM_ALFA_CART_FIELD_REQUIRED', 'params' => [(string) ($field->display_name ?? $field->field_name)], 'text' => null];
            }
        }

        $app = Factory::getApplication();

        foreach ($item->fields as $field) {
            // Reset per field: a plugin that cannot be booted is logged as this
            // field's, never as the plugin of the field before it.
            $plugin = null;

            try {
                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');

                if (!$plugin || !method_exists($plugin, 'onValidateCartAdd')) {
                    continue;
                }

                $payload = $request['payloads'][(int) $field->id] ?? '';
                $event = new ValidateCartAddEvent('onValidateCartAdd', [
                    'item' => $item,
                    'field' => $field,
                    'payload' => is_string($payload) ? $payload : (string) json_encode($payload),
                    'config' => $field->config ?? new Registry($field->params_decoded ?? []),
                    'quantity' => $quantity,
                    'selections' => self::selectionsOf($request),
                ]);
                $plugin->onValidateCartAdd($event);

                if (!$event->isValid()) {
                    $error = $event->getError();

                    return $error
                        ? ['key' => null, 'params' => [], 'text' => (string) $error]
                        : ['key' => 'COM_ALFA_CART_INVALID_SELECTION', 'params' => [], 'text' => null];
                }
            } catch (Throwable $e) {
                // A plugin REPORTING a bad selection returns a sentence written
                // for a buyer. A plugin THROWING says nothing of the kind: the
                // text is a stack's worth of internals, and the cart enqueues
                // whatever comes back from here straight into the outcome card.
                // Same answer either way, because the outcome is the same: the
                // configuration cannot be accepted.
                self::logDispatchFailure('onValidateCartAdd', $plugin, $field, $e);

                return ['key' => 'COM_ALFA_CART_INVALID_SELECTION', 'params' => [], 'text' => null];
            }
        }

        return null;
    }

    /**
     * A configuration refusal in words: its key in the reader's wording (storefront key => the
     * reader's key), else the storefront sentence; a field plugin's own sentence as written.
     * Escaping is the display's.
     *
     * @param array{key: ?string, params: list<string>, text: ?string} $refusal What validate() returned.
     * @param array<string, string> $wording Storefront key => the reader's key.
     *
     *
     * @since  1.14.0
     */
    public static function refusalText(array $refusal, array $wording = []): string
    {
        if ($refusal['key'] === null) {
            return (string) $refusal['text'];
        }

        $key = $wording[$refusal['key']] ?? $refusal['key'];

        return $refusal['params'] === [] ? Text::_($key) : Text::sprintf($key, ...$refusal['params']);
    }

    /**
     * The product's fields a buyer answers with a composition (a payload, its
     * full form, its uploads), as field id => true.
     *
     * A field takes a composition only when its field plugin implements the
     * payload handler ({@see self::payloadHandler()}), which turns the posted
     * composition into the validated value the line stores. The field's type
     * decides nothing. A dimension that offers a choice (two or more values
     * across the combinations) takes none even then: its answer is the
     * combination resolved.
     *
     * @param object $item The product, with its fields and combinations.
     *
     * @return array<int, true>
     *
     * @since  1.14.0
     */
    public static function payloadFields(object $item): array
    {
        $fields = (array) ($item->fields ?? []);
        $dimensions = CombinationResolver::dimensionValues(fields: $fields, combinations: (array) ($item->combinations ?? []));
        $taking = [];

        foreach ($fields as $field) {
            $fieldId = (int) $field->id;

            if (\count(value: $dimensions[$fieldId] ?? []) <= 1 && self::payloadHandler(field: $field) !== null) {
                $taking[$fieldId] = true;
            }
        }

        return $taking;
    }

    /**
     * The refusal of a composition posted for a field that takes none, or null.
     *
     * A payload, a full composition or an upload is accepted only for a field
     * of the product that takes one ({@see self::payloadFields()}). One that
     * carries anything for any other field (a field answered by choosing a
     * value, a field whose plugin has no payload handler, a field the product
     * does not have) refuses the whole configuration before anything is
     * prepared or stored: it is never handed to a plugin, never answers a
     * field and is never dropped quietly. An empty one carries nothing and is
     * not refused. The refusal names the first such field of the product.
     *
     * @param object $item The product, with its fields and combinations.
     * @param array $request requestSelection() output.
     * @param array $fullPayloads The full compositions, by field id.
     * @param array $files The uploads, by field id.
     *
     * @return array{key: ?string, params: list<string>, text: ?string}|null The refusal; null when there is none.
     *
     * @since  1.14.0
     */
    public static function compositionRefusal(object $item, array $request, array $fullPayloads = [], array $files = []): ?array
    {
        $posted = [];

        foreach ([(array) ($request['payloads'] ?? []), $fullPayloads] as $byField) {
            foreach ($byField as $fieldId => $payload) {
                if (self::isComposition(payload: $payload)) {
                    $posted[(int) $fieldId] = true;
                }
            }
        }

        foreach ($files as $fieldId => $upload) {
            if (!empty($upload)) {
                $posted[(int) $fieldId] = true;
            }
        }

        if ($posted === []) {
            return null;
        }

        $refused = array_diff_key($posted, self::payloadFields(item: $item));

        if ($refused === []) {
            return null;
        }

        foreach ((array) ($item->fields ?? []) as $field) {
            if (isset($refused[(int) $field->id])) {
                return ['key' => 'COM_ALFA_CART_FIELD_TAKES_NO_COMPOSITION', 'params' => [(string) ($field->display_name ?? $field->field_name)], 'text' => null];
            }
        }

        return ['key' => 'COM_ALFA_CART_INVALID_SELECTION', 'params' => [], 'text' => null];
    }

    /**
     * The plugin of a field when it implements the payload handler; null otherwise.
     *
     * The one capability a field plugin opts into to take a composition: an
     * onPrepareForCart method ({@see PrepareForCartEvent}), which receives the
     * posted composition and its uploads and sets the validated value the line
     * stores, or refuses it. The base class has no such method, so only a
     * plugin that writes one takes a composition. A plugin without it, or one
     * that cannot be booted, takes none.
     *
     * @param object $field The field definition (carries ->type).
     *
     * @return object|null The booted plugin, or null.
     *
     * @since  1.14.0
     */
    private static function payloadHandler(object $field): ?object
    {
        try {
            $plugin = Factory::getApplication()->bootPlugin(plugin: (string) ($field->type ?? ''), type: 'alfa-item-fields');
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add(
                entry: 'Booting the item-fields plugin "' . (string) ($field->type ?? '') . '" failed for field #'
                    . (int) ($field->id ?? 0) . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.cart',
            );

            return null;
        }

        return \is_object(value: $plugin) && method_exists(object_or_class: $plugin, method: 'onPrepareForCart') ? $plugin : null;
    }

    /**
     * Whether a posted composition carries anything: blank, an empty object or
     * list and null carry nothing.
     *
     * @param mixed $payload A posted payload or full composition.
     *
     *
     * @since  1.14.0
     */
    private static function isComposition(mixed $payload): bool
    {
        $text = is_string(value: $payload) ? trim(string: $payload) : (string) json_encode(value: $payload);

        return !\in_array(needle: $text, haystack: ['', '{}', '[]', 'null'], strict: true);
    }

    /**
     * A field plugin threw while the buyer was configuring a product.
     *
     * Which plugin, which field and what it said: everything needed to fix it,
     * and none of it something a buyer should read. A plugin that could not be
     * booted is named by the field's type.
     *
     * @param string $event The event that was being dispatched.
     * @param object|null $plugin The plugin that threw; null when booting it threw.
     * @param object $field The field being configured.
     * @param Throwable $e The failure.
     *
     *
     * @since  1.10.0
     */
    private static function logDispatchFailure(string $event, ?object $plugin, object $field, Throwable $e): void
    {
        AlfaLog::register();
        Log::add(
            entry: $event . ' threw in plugin "' . ($plugin !== null ? ($plugin->name ?? \get_class($plugin)) : (string) ($field->type ?? ''))
                . '" for field #' . (int) ($field->id ?? 0) . ': ' . $e->getMessage(),
            priority: Log::ERROR,
            category: 'com_alfa.cart',
        );
    }

    /**
     * The effective REQUIRED flag of a field on an item: the item-level
     * override (FieldParams 'required': '' = inherit, '0'/'1') wins over the
     * field's global #__alfa_item_fields.required. An informational field is
     * never required: the buyer reads it and never picks it, so nothing could
     * ever answer it (the admin form hides the switch for such fields).
     *
     * @param int $itemId The item.
     * @param object $field The field definition (carries ->required).
     *
     *
     * @since  1.3.0
     */
    public static function isFieldRequired(int $itemId, object $field): bool
    {
        if (!empty($field->informational)) {
            return false;
        }

        // One overrides query per item per request (validate + render both
        // walk every field), the same batch discipline as the list models.
        self::$requiredOverrides[$itemId] ??= FieldParams::getItemAll($itemId);

        $override = (string) (self::$requiredOverrides[$itemId][(int) $field->id] ?? null)?->get('required', '');

        if ($override !== '') {
            return (bool) (int) $override;
        }

        return !empty($field->required);
    }

    /**
     * Resolve the item-level override maps of a whole page of items at once.
     *
     * {@see self::isFieldRequired()} reads overrides one item at a time, and a
     * listing asks it about every card that carries fields: a query per card on
     * the storefront's busiest page, beside the batched read the field loader
     * already makes of the same table. This fills the same memo in one query.
     *
     * Each entry is the item's COMPLETE map, exactly what FieldParams::getItemAll()
     * returns, never narrowed to the fields on the page: a later caller on the same
     * request (cart validation) must not get a partial answer. An item with no
     * override rows is remembered as such, or it would fall through to its own
     * query anyway. Items already resolved are left alone.
     *
     * Calling this is an OPTIMISATION, never a requirement: isFieldRequired() still
     * reads any item it was not warmed with.
     *
     * @param int[] $itemIds The items about to be resolved.
     *
     *
     * @since  1.10.0
     */
    public static function warmRequiredOverrides(array $itemIds): void
    {
        $itemIds = array_values(array_filter(
            array_unique(array_map('intval', $itemIds)),
            static fn (int $id): bool => $id > 0 && !isset(self::$requiredOverrides[$id]),
        ));

        if ($itemIds === []) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['item_id', 'field_id', 'params']))
                ->from($db->quoteName('#__alfa_item_field_overrides'))
                ->whereIn($db->quoteName('item_id'), $itemIds),
        );

        $maps = array_fill_keys($itemIds, []);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $maps[(int) $row->item_id][(int) $row->field_id] = new Registry((string) $row->params);
        }

        foreach ($maps as $itemId => $map) {
            self::$requiredOverrides[$itemId] = $map;
        }
    }

    /**
     * The add-to-cart REQUIRED state of an item at render time: everything
     * the cart layout needs to gate the button, precomputed here so the
     * layout stays presentation-only:
     *
     *   choiceIds  -> required CHOICE fields (answered by picking a value)
     *   payloadIds -> required payload-nature fields (judged by their hidden
     *                composition input client-side, always server-side on add)
     *   missing    -> display names of unanswered choice fields right now
     *   labels     -> fieldId => display label (payload fields get $payloadLabel)
     *
     * A field is payload-nature when it takes a composition ({@see self::payloadFields()});
     * every other field with values is answered by picking one.
     *
     * @param object $item The hydrated item (fields, variant, selectedOptionValueIds).
     * @param string $payloadLabel The label standing in for payload fields (e.g. "your design").
     *
     * @return array{choiceIds: int[], payloadIds: int[], missing: string[], labels: array<int, string>}
     *
     * @since  1.3.0
     */
    public static function requiredState(object $item, string $payloadLabel): array
    {
        $out = ['choiceIds' => [], 'payloadIds' => [], 'missing' => [], 'labels' => []];
        $chosenOptions = array_map('intval', (array) ($item->selectedOptionValueIds ?? []));
        $variant = $item->variant ?? null;
        $taking = null;

        foreach ((array) ($item->fields ?? []) as $field) {
            if (!self::isFieldRequired(itemId: (int) $item->id, field: $field)) {
                continue;
            }

            $fid = (int) $field->id;
            $taking ??= self::payloadFields(item: $item);

            if (isset($taking[$fid]) || empty($field->values)) {
                $out['payloadIds'][] = $fid;
                $out['labels'][$fid] = $payloadLabel;
                continue;
            }

            $out['choiceIds'][] = $fid;
            $out['labels'][$fid] = $field->display_name ?? $field->field_name;

            $valueIds = array_map(static fn ($v) => (int) $v->id, (array) $field->values);
            $chosen = !empty($variant->selection[$fid])
                || array_intersect($valueIds, $chosenOptions) !== [];

            if (!$chosen) {
                $out['missing'][] = $out['labels'][$fid];
            }
        }

        return $out;
    }

    /**
     * Let each payload-nature field's plugin materialise its FULL composition
     * for storage (onPrepareForCart), chiefly turning uploaded files into
     * paths so the stored payload never holds a base64 blob. The prepared
     * result replaces the field's payload in the returned request.
     *
     * Fails closed: a composition or an upload for a field that takes none is
     * refused here too ({@see self::compositionRefusal()}), so nothing is
     * stored for it whichever writer calls, and what is left for such a field
     * (an empty one) is removed, so it stores no row.
     *
     * @param object $item The hydrated item (carries ->fields).
     * @param array $request requestSelection() output (payloads get overwritten).
     * @param array $fullPayloads jform[itemfields_payload]: the full compositions.
     * @param array $files conf_file uploads, keyed field id => slot => file.
     *
     * @return array|string The updated request, or an error message on a hard failure.
     * @since  1.3.0
     */
    public static function preparePayloads(object $item, array $request, array $fullPayloads, array $files): array|string
    {
        $request = self::withFixedDimensions(item: $item, request: $request);
        $refusal = self::compositionRefusal(item: $item, request: $request, fullPayloads: $fullPayloads, files: $files);

        if ($refusal !== null) {
            return self::refusalText(refusal: $refusal);
        }

        $taking = self::payloadFields(item: $item);
        $request['payloads'] = array_intersect_key((array) ($request['payloads'] ?? []), $taking);

        foreach ($item->fields as $field) {
            $fieldId = (int) $field->id;

            if (!isset($taking[$fieldId])) {
                continue;
            }

            $payload = $fullPayloads[$fieldId] ?? ($request['payloads'][$fieldId] ?? '');
            $fieldFiles = $files[$fieldId] ?? [];

            // Nothing posted for this field.
            if ($payload === '' && $fieldFiles === [] && !isset($request['payloads'][$fieldId])) {
                continue;
            }

            $payload = is_string($payload) ? $payload : (string) json_encode($payload);
            $plugin = self::payloadHandler(field: $field);

            // The field took a composition a moment ago; a handler gone since stores nothing.
            if ($plugin === null) {
                return Text::_(string: 'COM_ALFA_CART_INVALID_SELECTION');
            }

            $event = new PrepareForCartEvent('onPrepareForCart', [
                'item' => $item,
                'field' => $field,
                'payload' => $payload,
                'files' => $fieldFiles,
                'config' => $field->config ?? new Registry($field->params_decoded ?? []),
                'selections' => self::selectionsOf($request),
            ]);

            try {
                $plugin->onPrepareForCart($event);
            } catch (Throwable $e) {
                self::logDispatchFailure('onPrepareForCart', $plugin, $field, $e);

                return Text::_('COM_ALFA_CART_INVALID_SELECTION');
            }

            if (!$event->isValid()) {
                return $event->getError() ?: Text::_('COM_ALFA_CART_INVALID_SELECTION');
            }

            $request['payloads'][$fieldId] = $event->getStoredPayload();
        }

        return $request;
    }

    /**
     * Build the chosen field values as storage rows keyed by field id: variant
     * + option selections (value_id) plus plugin payload fields (the field's
     * single offered value id + the serialized payload). A fixed dimension the
     * request left out is stored with its only value, so the line reads the same
     * (and merges the same) whether or not the surface drew it.
     *
     * @param object $item The hydrated item (carries ->fields).
     * @param array $request requestSelection() output.
     *
     * @return array<int, array{value_id: int|null, payload: string|null}>
     * @since  1.3.0
     */
    public static function buildRows(object $item, array $request): array
    {
        $request = self::withFixedDimensions(item: $item, request: $request);
        $rows = [];

        foreach (($request['selection'] ?? []) as $fieldId => $valueId) {
            if ((int) $valueId > 0) {
                $rows[(int) $fieldId] = ['value_id' => (int) $valueId, 'payload' => null];
            }
        }

        foreach (($request['optionValueIds'] ?? []) as $fieldId => $valueId) {
            if ((int) $valueId > 0) {
                $rows[(int) $fieldId] = ['value_id' => (int) $valueId, 'payload' => null];
            }
        }

        foreach (($request['payloads'] ?? []) as $fieldId => $payload) {
            $rows[(int) $fieldId] = [
                'value_id' => self::fieldValueId(item: $item, fieldId: (int) $fieldId),
                'payload' => is_string($payload) ? $payload : (string) json_encode($payload),
            ];
        }

        return $rows;
    }

    /**
     * A plugin field's single offered value id (a configurator carries one
     * value), used as the row's value_id alongside its payload.
     *
     * @param object $item The hydrated item.
     * @param int $fieldId The field id.
     *
     * @since  1.3.0
     */
    protected static function fieldValueId(object $item, int $fieldId): ?int
    {
        foreach ($item->fields ?? [] as $field) {
            if ((int) $field->id === $fieldId) {
                $values = $field->values ?? [];

                return $values ? (int) $values[0]->id : null;
            }
        }

        return null;
    }

    /**
     * The HasSelections event argument from a requestSelection()-shaped array:
     * the one adapter between the request parse and the events' contract.
     *
     * @param array $request requestSelection() output.
     *
     * @return array{sel: array<int, int>, opt: int[]}
     * @since  1.3.0
     */
    protected static function selectionsOf(array $request): array
    {
        return [
            'sel' => (array) ($request['selection'] ?? []),
            'opt' => array_map('intval', (array) ($request['optionValueIds'] ?? [])),
        ];
    }

    /**
     * The request with every FIXED dimension of a combination item (one value
     * across its combinations) filled where the selection leaves it out: the
     * same fill the resolver applies, so validation, the plugins and the stored
     * rows all see it.
     *
     * @param object $item The hydrated item (fields + combinations).
     * @param array $request requestSelection() output.
     *
     * @return array The request, selection filled.
     * @since  1.10.0
     */
    protected static function withFixedDimensions(object $item, array $request): array
    {
        if (empty($item->combinations)) {
            return $request;
        }

        $request['selection'] = CombinationResolver::withFixedDimensions(
            (array) ($item->fields ?? []),
            $item->combinations,
            (array) ($request['selection'] ?? []),
        );

        return $request;
    }
}
