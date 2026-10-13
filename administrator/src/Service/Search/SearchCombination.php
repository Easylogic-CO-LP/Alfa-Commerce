<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * A variant, PROJECTED for search, deliberately not the catalog combination.
 *
 * INVARIANT PROTECTED: the search layer can never acquire a reference to the
 * domain model. A document that carried the real combination would drag stock,
 * prices, media, policy flags and their invalidation rules into a payload that
 * gets serialised to a third-party engine, and every future change to the
 * catalog model would become a change to the published search contract. This type
 * is the wall: it holds only what makes a variant FINDABLE and IDENTIFIABLE.
 *
 * `label` is the merchant's per-language override for this variant, not the
 * derived "Red / XL" display string; the derived one is composed from the
 * dimension values, which travel as FieldValue entries on the document.
 *
 * Named SearchCombination rather than Combination so the projection is never
 * mistaken for the catalog entity at a call site.
 *
 * @since  2.0.0
 */
final class SearchCombination
{
    /**
     * @param int $id Combination id: stable identity for filtering and deep links.
     * @param string $label Per-language name override; '' when the variant has none.
     * @param string $sku Variant SKU; '' when it inherits the item's.
     * @param string $gtin Variant GTIN; '' when it inherits the item's.
     * @param string $mpn Variant MPN; '' when it inherits the item's.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $id,
        public readonly string $label = '',
        public readonly string $sku = '',
        public readonly string $gtin = '',
        public readonly string $mpn = '',
    ) {
    }
}
