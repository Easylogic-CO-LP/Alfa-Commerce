<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use stdClass;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The ONE freeze path for an order line's item-field configuration.
 *
 * Writes the immutable #__alfa_order_item_fields snapshot for one order line:
 * frozen {lang: text} label maps across every content language, the opaque
 * plugin payload, and the analytical price freeze (delta rule + resolved
 * amounts in the order's currency). Both writers go through here: order
 * placement (cart selection -> new snapshot) and the admin order-line
 * re-configure (new selection -> replace snapshot), so the frozen rows can
 * never diverge between the two paths.
 *
 * Failure policy belongs to the caller: this helper THROWS on a write
 * failure. Order placement wraps it resiliently (the sale must not abort over
 * a snapshot); an admin re-edit lets it surface to the operator.
 *
 * @since  2.0.0
 */
class ItemFieldsSnapshot
{
    /**
     * The frozen snapshot table.
     *
     * @var string
     * @since  2.0.0
     */
    protected const TABLE = '#__alfa_order_item_fields';

    /**
     * Freeze an item-field selection onto an order line.
     *
     * Per selected field: snapshots the field label and value name as
     * {lang: text} maps (readable in any admin/customer language, immune to
     * later renames/deletes of the definitions), copies the opaque plugin
     * payload verbatim, and, when a price breakdown is supplied, freezes the
     * delta rule (operation/amount/apply_on) plus the exact resolved impacts
     * in the given currency (precision follows the coin, never a hardcoded
     * scale).
     *
     * @param int $orderItemId The #__alfa_order_items row id.
     * @param int $orderId The #__alfa_orders row id (denormalized for reporting).
     * @param array $rows Selection keyed by field id: ['value_id' => int|null, 'payload' => string|null].
     * @param object $item The configured item (fields + value pool) for fallback labels.
     * @param array|null $breakdown Analytical price breakdown (tax_factor + lines), or null to freeze without prices.
     * @param Currency|null $currency The order's currency for impact amounts (null degrades to 6dp, logged).
     * @param bool $replace Drop the line's existing snapshot rows first (a re-configure).
     *
     *
     * @throws Exception On a snapshot write failure; policy is the caller's.
     * @since  2.0.0
     */
    public static function freezeSelection(
        int $orderItemId,
        int $orderId,
        array $rows,
        object $item,
        ?array $breakdown = null,
        ?Currency $currency = null,
        bool $replace = false,
    ): void {
        if ($orderItemId <= 0 || !$rows) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Field/value display names come from the ONE resolver the live cart
        // reads, so the frozen label equals the name the shopper saw. Resolved
        // once for the whole line; the snapshot then stores the maps and never
        // reads a live name again.
        $valueIds = [];

        foreach ($rows as $snapshotRow) {
            if ($snapshotRow['value_id'] !== null) {
                $valueIds[] = (int) $snapshotRow['value_id'];
            }
        }

        $fieldLabelMaps = ItemFieldDisplayResolver::fieldLabelMaps(array_map('intval', array_keys($rows)));
        $valueTextMaps = ItemFieldDisplayResolver::valueTextMaps($valueIds);

        // Split the breakdown into per-option impacts and plugin contribution lines.
        $optionImpact = [];
        $contributionLines = [];
        $taxFactor = (float) ($breakdown['tax_factor'] ?? 1.0);

        foreach ($breakdown['lines'] ?? [] as $line) {
            if (($line['kind'] ?? '') === 'option') {
                $optionImpact[(int) ($line['value_id'] ?? 0)] = $line;
            } elseif (($line['kind'] ?? '') === 'contribution') {
                $contributionLines[] = $line;
            }
        }

        // Impact amounts follow the order's coin via the Money value object. A
        // null currency alongside priced fields is an anomaly: log it loudly and
        // degrade to 6dp rather than lose the committed snapshot.
        if ($currency === null && ($optionImpact !== [] || $contributionLines !== [])) {
            Log::add(
                'Order item-fields: priced fields but no currency on order item ' . $orderItemId
                . '; freezing impacts at 6dp.',
                Log::ERROR,
                'com_alfa.orders',
            );
        }

        $money = static fn (float $net): float => $currency !== null
            ? Money::of($net, $currency)->getAmount()
            : round($net, 6);

        // The same figure as its column is handed it: the exact decimal text of
        // its Money (at six places on the degraded path), never a float.
        $column = static fn (float $net): string => $currency !== null
            ? StoredDecimal::of(Money::of($net, $currency))
            : StoredDecimal::of(ExactAmount::ofNumber($net)->roundedAt(6));

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        // A re-configure REPLACES the line's snapshot: the old rows go first so
        // a removed field doesn't linger as a stale frozen row.
        if ($replace) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::TABLE))
                    ->where($db->quoteName('id_order_item') . ' = ' . $orderItemId),
            )->execute();
        }

        foreach ($rows as $fieldId => $row) {
            $fieldId = (int) $fieldId;
            $valueId = $row['value_id'] !== null ? (int) $row['value_id'] : null;

            $labelMap = $fieldLabelMaps[$fieldId] ?? ['#' . $fieldId];
            $valueMap = $valueId !== null ? ($valueTextMaps[$valueId] ?? []) : [];

            $fieldRow = new stdClass();
            $fieldRow->id_order_item = $orderItemId;
            $fieldRow->id_order = $orderId;
            $fieldRow->id_item_field = $fieldId ?: null;
            $fieldRow->id_item_field_value = $valueId;
            $fieldRow->field_label = json_encode($labelMap, $flags);
            $fieldRow->value_text = $valueId !== null ? json_encode($valueMap, $flags) : null;
            $fieldRow->payload = $row['payload'];

            if ($valueId !== null && isset($optionImpact[$valueId])) {
                // Option: freeze its delta rule + the exact resolved amounts.
                $line = $optionImpact[$valueId];
                $net = (float) ($line['impact_net'] ?? 0);
                $fieldRow->operation = $line['operation'] ?? null;
                // The rule's own amount (currency or percent), held at its eighth place.
                $fieldRow->amount = StoredDecimal::of(ExactAmount::ofNumber($line['amount'] ?? 0)->roundedAt(StoredDecimal::SCALE));
                $fieldRow->apply_on = $line['apply_on'] ?? null;
                $fieldRow->price_impact_tax_excl = $column($net);
                $fieldRow->price_impact_tax_incl = $column($net * $taxFactor);
            } elseif ($row['payload'] !== null && $contributionLines !== []) {
                // Plugin field: freeze the analytical contribution breakdown +
                // total; the plugin has the last word, we freeze what it returned.
                $breakdownLines = [];
                $sumNet = ExactAmount::of('0');

                foreach ($contributionLines as $contribution) {
                    $net = (float) ($contribution['impact_net'] ?? 0);
                    // The total is the exact sum of the nets, rounded once below.
                    $sumNet = $sumNet->plus(ExactAmount::ofNumber($net));
                    $breakdownLines[] = [
                        'label' => (string) ($contribution['label'] ?? ''),
                        'operation' => $contribution['operation'] ?? null,
                        'amount' => (float) ($contribution['amount'] ?? 0),
                        'price_impact_tax_excl' => $money($net),
                        'price_impact_tax_incl' => $money($net * $taxFactor),
                    ];
                }

                $fieldRow->breakdown = json_encode($breakdownLines, $flags);
                $fieldRow->price_impact_tax_excl = $currency !== null ? StoredDecimal::of($sumNet->toMoney($currency)) : $column((float) $sumNet->text());
                $fieldRow->price_impact_tax_incl = $column((float) $sumNet->text() * $taxFactor);
                $contributionLines = [];
            }

            $db->insertObject(self::TABLE, $fieldRow, 'id');
        }
    }

    /**
     * Freeze an order line's DISPLAY NAME as a {lang: text} map across every
     * content language, the effective sellable name: the combination's own
     * translated name when it has one, in that language or else in the default
     * language (the same override the storefront overlay applies), otherwise the
     * item's name. Stored as JSON in order_items.name so the line reads correctly
     * in any language and survives a later rename or delete of the catalog item /
     * combination.
     *
     * @param int $itemId The #__alfa_items row id.
     * @param int|null $combinationId The resolved combination, or null.
     *
     * @return array<string, string> Normalised lang tag => effective name.
     * @since  2.0.0
     */
    public static function freezeItemName(int $itemId, ?int $combinationId): array
    {
        $map = [];

        // Item name across languages (name_<tag> => value).
        try {
            foreach (MultilingualHelper::getMultilingualDataFlat($itemId, 'id_item', '#__alfa_items') as $key => $value) {
                if (str_starts_with((string) $key, 'name_')) {
                    $map[substr((string) $key, 5)] = (string) $value;
                }
            }
        } catch (Throwable $e) {
            // No language rows: fall back below.
        }

        // A combination's own translated name overrides the item's, per language; in a
        // language it has none, its default-language name does, as on the storefront.
        if ($combinationId > 0) {
            $combination = [];

            try {
                foreach (MultilingualHelper::getMultilingualDataFlat($combinationId, 'id_item_combination', '#__alfa_item_combinations') as $key => $value) {
                    if (str_starts_with((string) $key, 'name_') && (string) $value !== '') {
                        $combination[substr((string) $key, 5)] = (string) $value;
                    }
                }
            } catch (Throwable $e) {
                // No combination language rows: keep the item name.
            }

            $defaultName = $combination[MultilingualHelper::getDefaultLanguageTag()] ?? '';

            foreach (array_unique(array_merge(array_keys($map), array_keys($combination), MultilingualHelper::getLanguageTags())) as $lang) {
                $name = $combination[$lang] ?? $defaultName;

                if ($name !== '') {
                    $map[$lang] = $name;
                }
            }
        }

        if (!empty($map)) {
            return $map;
        }

        return [MultilingualHelper::getCurrentLanguageTag() => '#' . $itemId];
    }

    /**
     * Reconstruct an order line's stored selection from its frozen snapshot, in
     * the SAME shape the product page's request parser produces, so the admin
     * re-configure seeds the field render exactly like a cart-line re-edit does:
     * variant fields -> selection, plain values -> optionValueIds, plugin fields
     * -> payloads (each plugin's own serialized value, handed back uniformly via
     * the render event's current value).
     *
     * @param int $orderItemId The #__alfa_order_items row id.
     * @param object $item The configured item (fields + combinations) to split variant vs option.
     *
     * @return array{selection: array, optionValueIds: array, payloads: array}
     * @since  2.0.0
     */
    public static function lineSelection(int $orderItemId, object $item): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id_item_field'),
                    $db->quoteName('id_item_field_value'),
                    $db->quoteName('payload'),
                ])
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order_item') . ' = ' . $orderItemId),
        )->loadObjectList() ?: [];

        $variantFieldIds = self::variantFieldIds(item: $item);

        $selection = [];
        $optionValueIds = [];
        $payloads = [];

        foreach ($stored as $row) {
            $fieldId = (int) $row->id_item_field;

            if ($row->payload !== null) {
                $payloads[$fieldId] = $row->payload;
            } elseif (isset($variantFieldIds[$fieldId])) {
                $selection[$fieldId] = (int) $row->id_item_field_value;
            } else {
                $optionValueIds[$fieldId] = (int) $row->id_item_field_value;
            }
        }

        return [
            'selection' => $selection,
            'optionValueIds' => $optionValueIds,
            'payloads' => $payloads,
        ];
    }

    /**
     * The set of field ids that DEFINE the item's combinations (variant fields),
     * derived from the values used across its combinations.
     *
     * @param object $item The item (fields + combinations).
     *
     * @return array<int, bool> field id => true.
     * @since  2.0.0
     */
    public static function variantFieldIds(object $item): array
    {
        $valueToField = [];

        foreach ($item->fields ?? [] as $field) {
            foreach ($field->values ?? [] as $value) {
                $valueToField[(int) $value->id] = (int) $field->id;
            }
        }

        $ids = [];

        foreach ($item->combinations ?? [] as $combo) {
            foreach ($combo->value_ids ?? [] as $valueId) {
                if (isset($valueToField[(int) $valueId])) {
                    $ids[$valueToField[(int) $valueId]] = true;
                }
            }
        }

        return $ids;
    }
}
