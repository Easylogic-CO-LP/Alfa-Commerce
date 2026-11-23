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
 * One related product a product's page shows, as a value: the linked product and what its card opens on. A link names
 * the product alone (its card opens on its default, as a listing card does), one of its combinations, or values chosen
 * for some of its fields: values of its variant dimensions and values of the options it offers, one a field. A
 * combination link may carry option values too.
 *
 *     RelatedLink::to(42);                                         // the product, on its default
 *     RelatedLink::to(42)->onCombination(304);                     // that combination
 *     RelatedLink::to(42)->onCombination(304)->withValue(7, 71);   // that combination with the warranty option
 *     RelatedLink::to(42)->withValue(5, 12);                       // White, any storage
 *
 * Every method gives a new link. A link is a request, never trusted as it stands: the product save judges it against
 * the product's current combinations and options ({@see RelatedItems::refusals()}), and the storefront judges it again
 * on every read against what the visitor may buy.
 *
 * @since  2.3.0
 */
final readonly class RelatedLink
{
    /**
     * @param int $itemId The linked product.
     * @param int $combinationId The combination its card opens on; 0 for none.
     * @param array<int, int> $values The values its card opens with: field id to value id, in field id order.
     *
     * @since  2.3.0
     */
    private function __construct(
        public int $itemId,
        public int $combinationId,
        public array $values,
    ) {
    }

    /**
     * A link to a product, its card opening on the product's default.
     *
     * @param int $itemId The linked product.
     *
     * @since  2.3.0
     */
    public static function to(int $itemId): self
    {
        return new self(itemId: $itemId, combinationId: 0, values: []);
    }

    /**
     * The same link, its card opening on this combination of the product.
     *
     * @param int $combinationId The combination; 0 for none.
     *
     * @since  2.3.0
     */
    public function onCombination(int $combinationId): self
    {
        return new self(itemId: $this->itemId, combinationId: max(0, $combinationId), values: $this->values);
    }

    /**
     * The same link with one more value chosen: a value of a dimension of the product or of an option it offers. A
     * field holds one value; a second value of a field replaces the first.
     *
     * @param int $fieldId The item field.
     * @param int $valueId Its value.
     *
     * @since  2.3.0
     */
    public function withValue(int $fieldId, int $valueId): self
    {
        $values = $this->values;
        $values[$fieldId] = $valueId;
        ksort($values);

        return new self(itemId: $this->itemId, combinationId: $this->combinationId, values: $values);
    }

    /**
     * The same link with these values in place of its own.
     *
     * @param array<int, int> $values Field id to value id.
     *
     * @since  2.3.0
     */
    public function withValues(array $values): self
    {
        $link = new self(itemId: $this->itemId, combinationId: $this->combinationId, values: []);

        foreach ($values as $fieldId => $valueId) {
            $link = $link->withValue(fieldId: (int) $fieldId, valueId: (int) $valueId);
        }

        return $link;
    }

    /**
     * Whether the card opens on something chosen: a combination or values.
     *
     * @since  2.3.0
     */
    public function isPreselected(): bool
    {
        return $this->combinationId > 0 || $this->values !== [];
    }

    /**
     * The link as the item record and the Related tab hold it: {item_id, combination_id (0 for none), values (field id
     * to value id)}.
     *
     * @return array{item_id: int, combination_id: int, values: array<int, int>}
     *
     * @since  2.3.0
     */
    public function toRow(): array
    {
        return ['item_id' => $this->itemId, 'combination_id' => $this->combinationId, 'values' => $this->values];
    }
}
