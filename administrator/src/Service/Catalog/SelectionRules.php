<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

/**
 * The one rule for a selection on a product: which field and value pairs the product takes, and as what. A pair is a
 * dimension pair when its field is a variant dimension of the product's combinations, and an option pair when its
 * field is no dimension and the product offers the value as an option; each caller decides what it does with a pair
 * the product does not take.
 *
 * The rule is pure: the caller hands it the product's combinations and options as it reads them. The storefront hands
 * the published combinations a visitor may buy ({@see self::forItem()}: the product page, the card and its reload, the
 * cart); the administrator hands the current ones, published and unpublished (the related links' save and clean-up).
 *
 * @since  2.3.0
 */
final class SelectionRules
{
    /**
     * A value of a dimension of the product that one of its combinations carries.
     *
     * @since  2.3.0
     */
    public const DIMENSION = 'dimension';

    /**
     * A value the product offers as an option, of its own field.
     *
     * @since  2.3.0
     */
    public const OPTION = 'option';

    /**
     * The value is not a value of the field named (or no value at all).
     *
     * @since  2.3.0
     */
    public const NOT_A_VALUE_OF_FIELD = 'not_a_value_of_field';

    /**
     * A value of a dimension of the product that none of its combinations carries.
     *
     * @since  2.3.0
     */
    public const NOT_CARRIED = 'not_carried';

    /**
     * A value of an option field of the product that the product does not offer.
     *
     * @since  2.3.0
     */
    public const NOT_OFFERED = 'not_offered';

    /**
     * A field that is neither a dimension of the product nor the field of an option it offers.
     *
     * @since  2.3.0
     */
    public const NOT_A_FIELD_OF_PRODUCT = 'not_a_field_of_product';

    /**
     * @param array<int, int> $fieldOf The field of each value known: value id to field id.
     * @param array<int, array<int, true>> $dimensions Every value each dimension takes across the combinations.
     * @param array<int, true> $offered The values offered as options.
     * @param array<int, true> $optionFields The fields of the offered options that are no dimension.
     *
     * @since  2.3.0
     */
    private function __construct(
        private readonly array $fieldOf,
        private readonly array $dimensions,
        private readonly array $offered,
        private readonly array $optionFields,
    ) {
    }

    /**
     * The rule for a product, from plain lists.
     *
     * @param array<int, int> $fieldOf The field of each value the lists name, and of any value a selection names: value
     *                                 id to field id. A value it lacks belongs to no field.
     * @param array<int, list<int>> $combinations The value ids of each combination, by combination id.
     * @param list<int> $offered The value ids the product offers as options.
     *
     * @since  2.3.0
     */
    public static function of(array $fieldOf, array $combinations, array $offered): self
    {
        $dimensions = [];

        foreach ($combinations as $valueIds) {
            foreach ($valueIds as $valueId) {
                $fieldId = $fieldOf[(int) $valueId] ?? null;

                if ($fieldId !== null) {
                    $dimensions[$fieldId][(int) $valueId] = true;
                }
            }
        }

        $offeredSet = [];
        $optionFields = [];

        foreach ($offered as $valueId) {
            $offeredSet[(int) $valueId] = true;
            $fieldId = $fieldOf[(int) $valueId] ?? null;

            if ($fieldId !== null && !isset($dimensions[$fieldId])) {
                $optionFields[$fieldId] = true;
            }
        }

        return new self(fieldOf: $fieldOf, dimensions: $dimensions, offered: $offeredSet, optionFields: $optionFields);
    }

    /**
     * The rule for a product as the storefront loads it: its fields with their values, its combinations (each with
     * value_ids) and its options (each with value_id).
     *
     * @param object $item The product.
     *
     * @since  2.3.0
     */
    public static function forItem(object $item): self
    {
        $fieldOf = [];

        foreach ((array) ($item->fields ?? []) as $field) {
            foreach ((array) ($field->values ?? []) as $value) {
                $fieldOf[(int) $value->id] = (int) $field->id;
            }
        }

        $combinations = [];

        foreach ((array) ($item->combinations ?? []) as $combination) {
            $combinations[(int) $combination->id] = array_map('intval', (array) ($combination->value_ids ?? []));
        }

        return self::of(
            fieldOf: $fieldOf,
            combinations: $combinations,
            offered: array_map(static fn (object $option): int => (int) $option->value_id, array_values((array) ($item->options ?? []))),
        );
    }

    /**
     * Every value each dimension takes across the combinations.
     *
     * @return array<int, array<int, true>> Field id to value ids.
     *
     * @since  2.3.0
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Whether the field is a variant dimension of the product.
     *
     * @param int $fieldId The field.
     *
     * @since  2.3.0
     */
    public function isDimension(int $fieldId): bool
    {
        return isset($this->dimensions[$fieldId]);
    }

    /**
     * Whether the product offers the value as an option of that field, which is no dimension.
     *
     * @param int $fieldId The field.
     * @param int $valueId The value.
     *
     * @since  2.3.0
     */
    public function offersOption(int $fieldId, int $valueId): bool
    {
        return isset($this->offered[$valueId]) && ($this->fieldOf[$valueId] ?? null) === $fieldId && !$this->isDimension(fieldId: $fieldId);
    }

    /**
     * What a pair is on this product: {@see self::DIMENSION} or {@see self::OPTION} when the product takes it, else
     * why not.
     *
     * @param int $fieldId The field named.
     * @param int $valueId The value named for it.
     *
     * @since  2.3.0
     */
    public function verdict(int $fieldId, int $valueId): string
    {
        if ($this->isDimension(fieldId: $fieldId)) {
            if (($this->fieldOf[$valueId] ?? null) !== $fieldId) {
                return self::NOT_A_VALUE_OF_FIELD;
            }

            return isset($this->dimensions[$fieldId][$valueId]) ? self::DIMENSION : self::NOT_CARRIED;
        }

        if (isset($this->optionFields[$fieldId])) {
            if (($this->fieldOf[$valueId] ?? null) !== $fieldId) {
                return self::NOT_A_VALUE_OF_FIELD;
            }

            return $this->offersOption(fieldId: $fieldId, valueId: $valueId) ? self::OPTION : self::NOT_OFFERED;
        }

        return self::NOT_A_FIELD_OF_PRODUCT;
    }
}
