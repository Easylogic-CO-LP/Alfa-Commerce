<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\ItemConfigurationHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;

defined('_JEXEC') or die;

/**
 * A line's configuration, in both directions.
 *
 * Reading back what a stored line is configured as (the selection rows keyed
 * by field, split into the variant selection, the option values, the payloads
 * and the summary a template lists) and, on the way in, the steps that turn
 * a request into rows: validation, payload preparation, the rows themselves,
 * the signature two lines are merged on, and the search for a line that
 * already carries it. Framework-free: what the shopper is told about a
 * refusal is returned, not spoken.
 *
 * @since  1.10.0
 */
final class CartLineConfiguration
{
    /** @since 1.10.0 */
    public function __construct(private readonly CartRepository $repository)
    {
    }

    /**
     * The stored selection rows of a line, keyed by field id.
     *
     * @return array<int, array{value_id: ?int, payload: ?string}>
     *
     * @since  1.10.0
     */
    public function rows(int $lineId): array
    {
        $rows = [];

        foreach ($this->repository->loadSelectionRows($lineId) as $row) {
            $rows[(int) $row->id_item_field] = [
                'value_id' => $row->id_item_field_value !== null ? (int) $row->id_item_field_value : null,
                'payload' => $row->payload,
            ];
        }

        return $rows;
    }

    /**
     * What a stored line is configured as, against its product.
     *
     * @return array{0: array, 1: array, 2: array, 3: array} [selection, optionValueIds, payloads, summary]
     *
     * @since  1.10.0
     */
    public function of(object $item, int $lineId): array
    {
        $rows = $this->rows($lineId);
        $variantFieldIds = ItemFieldsSnapshot::variantFieldIds(item: $item);
        $fieldName = [];
        $valueName = [];

        foreach ($item->fields ?? [] as $field) {
            $fieldName[(int) $field->id] = $field->display_name ?? '';

            foreach ($field->values ?? [] as $value) {
                $valueName[(int) $value->id] = $value->display_name ?? '';
            }
        }

        $selection = [];
        $optionValueIds = [];
        $payloads = [];
        $summary = [];

        foreach ($rows as $fieldId => $row) {
            if ($row['payload'] !== null) {
                $payloads[$fieldId] = $row['payload'];
            } elseif (isset($variantFieldIds[$fieldId])) {
                $selection[$fieldId] = (int) $row['value_id'];
            } else {
                $optionValueIds[$fieldId] = (int) $row['value_id'];
            }

            $summary[] = (object) [
                'field_id' => $fieldId,
                'field' => $fieldName[$fieldId] ?? '',
                'value' => $valueName[(int) $row['value_id']] ?? '',
                'has_payload' => $row['payload'] !== null,
            ];
        }

        return [$selection, $optionValueIds, $payloads, $summary];
    }

    /**
     * Whether a line belongs to a cart. A cart or line nobody has is nobody's.
     *
     * @since  1.10.0
     */
    public function owned(int $cartId, int $lineId): bool
    {
        if ($cartId <= 0 || $lineId <= 0) {
            return false;
        }

        return $this->repository->lineBelongsTo($lineId, $cartId);
    }

    /**
     * A stored line's configuration, for re-opening it on the product page;
     * null when the line is not this cart's.
     *
     * @since  1.10.0
     */
    public function edit(int $cartId, object $item, int $lineId): ?array
    {
        if (!$this->owned($cartId, $lineId)) {
            return null;
        }

        [$selection, $optionValueIds, $payloads] = $this->of($item, $lineId);

        return [
            'selection' => $selection,
            'optionValueIds' => $optionValueIds,
            'payloads' => $payloads,
        ];
    }

    /**
     * The per-line tax breakdown the order freezes, priced under the given
     * context.
     *
     * @since  1.10.0
     */
    public function breakdown(object $item, int $lineId, int $quantity, PriceContext $context): array
    {
        [$selection, $optionValueIds, $payloads] = $this->of($item, $lineId);

        $variant = VariantHelper::resolve($item, $selection, array_values($optionValueIds), $payloads, $context);

        return (new PriceCalculator())->breakdownVariant((int) $item->id, max(1, $quantity), $variant, $context);
    }

    /**
     * Whether a request may become a line: the refusal to tell the shopper,
     * or null when it may.
     *
     * @since  1.10.0
     */
    public function validate(object $item, ?int $combinationId, array $request, float $quantity): ?string
    {
        $refusal = ItemConfigurationHelper::validate(
            item: $item,
            combinationId: $combinationId,
            request: $request,
            quantity: $quantity,
        );

        return $refusal === null ? null : ItemConfigurationHelper::refusalText(refusal: $refusal);
    }

    /**
     * The refusal to tell the shopper when a composition, a full composition
     * or an upload was posted for a field that takes none
     * ({@see ItemConfigurationHelper::compositionRefusal()}); null when every
     * one posted is for a field that takes it.
     *
     * @param object $item The product, with its fields and combinations.
     * @param array $request The selection as posted, requestSelection() shape.
     * @param array $fullPayloads The full compositions as posted, by field id.
     * @param array $files The uploads as posted, by field id.
     *
     *
     * @since  1.14.0
     */
    public function compositionRefusal(object $item, array $request, array $fullPayloads, array $files): ?string
    {
        $refusal = ItemConfigurationHelper::compositionRefusal(
            item: $item,
            request: $request,
            fullPayloads: $fullPayloads,
            files: $files,
        );

        return $refusal === null ? null : ItemConfigurationHelper::refusalText(refusal: $refusal);
    }

    /**
     * The request with its payloads prepared, or the refusal to tell the
     * shopper.
     *
     *
     * @since  1.10.0
     */
    public function prepare(object $item, array $request, array $fullPayloads, array $files): array|string
    {
        return ItemConfigurationHelper::preparePayloads(
            item: $item,
            request: $request,
            fullPayloads: $fullPayloads,
            files: $files,
        );
    }

    /**
     * The selection rows a request stores as.
     *
     * @since  1.10.0
     */
    public function rowsFor(object $item, array $request): array
    {
        return ItemConfigurationHelper::buildRows(item: $item, request: $request);
    }

    /**
     * What makes two lines the same line: the same variant with the same
     * configuration.
     *
     * @since  1.10.0
     */
    public function signature(?int $combinationId, array $rows): string
    {
        ksort($rows);
        $parts = [];

        foreach ($rows as $fieldId => $row) {
            $parts[] = $fieldId . ':' . ((int) ($row['value_id'] ?? 0)) . ':'
                . ($row['payload'] !== null && $row['payload'] !== '' ? md5((string) $row['payload']) : '');
        }

        return md5(((int) $combinationId) . '|' . implode('|', $parts));
    }

    /**
     * The line of this cart that already carries a signature, or 0.
     *
     * @since  1.10.0
     */
    public function find(int $cartId, int $itemId, string $signature): int
    {
        foreach ($this->repository->findLinesForItem($cartId, $itemId) as $candidate) {
            $rows = $this->rows((int) $candidate->id);
            $comboId = $candidate->id_combination !== null ? (int) $candidate->id_combination : null;

            if ($this->signature($comboId, $rows) === $signature) {
                return (int) $candidate->id;
            }
        }

        return 0;
    }
}
