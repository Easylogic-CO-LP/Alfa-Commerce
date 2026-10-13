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
 * The canonical, engine-agnostic description of one item in one language.
 *
 * This is the contract between Alfa Commerce and ANY search implementation. It
 * states semantic truth ("this is the product, and this is the data that may be
 * used for search") and never storage or retrieval strategy. It is deliberately
 * NOT "the payload one particular engine happens to store".
 *
 * INVARIANT PROTECTED: an engine can never be forced to re-query the catalog.
 * The acceptance test this type exists to pass is: *given only a SearchDocument,
 * can someone write a new engine's indexer and build their own mapping without
 * asking Commerce Core anything?* If a field is missing, the plugin grows a second,
 * hidden document builder, and the abstraction is worth nothing.
 *
 * WHAT IS DELIBERATELY ABSENT: any flattened, tokenised or lowercased text. The
 * flat `document` column the Indexed SQL engine stores was never a canonical
 * document; it was the first SQL projection, and it had already decided flattening
 * order, case strategy and what counts as important. Those are projection decisions:
 *
 *     SearchDocument -> a flat-string projection   (the Indexed SQL engine)
 *                    -> a FULLTEXT projection
 *                    -> an external-engine projection (nested fields, weights, analyzers)
 *
 * Core must not know that edge n-grams, analyzers or facet attributes exist.
 *
 * ADDITIVE-ONLY. A field may be appended (optional, with a default, at the end of
 * the constructor). A field may never be removed, renamed, retyped, or have its
 * meaning changed. Consumers must ignore fields they do not understand.
 *
 * Language is NOT carried separately: it lives in {@see DocumentId}, so it is
 * structurally impossible for a document's language to disagree with its own
 * identity. Read it through {@see self::language()}.
 *
 * @since  2.0.0
 */
final class SearchDocument
{
    /**
     * Semantic shape version. Consumers accept `payloadVersion <= own`. Bumped only
     * when an existing field changes meaning, which is a thing we have undertaken
     * not to do. Plugin-contributed data versions itself per namespace and never
     * moves this number.
     *
     * @var int
     *
     * @since  2.0.0
     */
    public const PAYLOAD_VERSION = 1;

    /**
     * @param DocumentId $id Identity: (type, item, language).
     * @param string $title Item name with the default-language fallback already applied
     *                      (the index must be findable by whatever the storefront renders).
     * @param string $alias Per-language URL slug; shoppers paste URLs into search boxes.
     * @param string $sku Item-level SKU. Exact-match identifier, never stemmed.
     * @param string $gtin Item-level GTIN.
     * @param string $mpn Item-level MPN.
     * @param FacetRef[] $manufacturers Filter ids plus per-language names. Plural because
     *                                  `#__alfa_items_manufacturers` is a pivot: the catalog
     *                                  permits several, so the contract must too.
     * @param FacetRef[] $categories ASSIGNED categories only, never the ancestor closure:
     *                               closure is a query-time concern, and baking it in makes one
     *                               category move invalidate every descendant's document.
     * @param FieldValue[] $itemFields Attributed field values: ids, not anonymous tokens.
     * @param SearchCombination[] $combinations Variants, projected. One document carries N of them;
     *                                          variant-grained documents do not exist in this payload version.
     * @param int $version Monotonic source version (item modified time as a unix
     *                     timestamp). Hosted engines apply writes asynchronously with no
     *                     external versioning, so two writes for one id can land out of
     *                     order and resurrect stale content. An engine adapter that can
     *                     compare versions must refuse the older write.
     * @param SearchDescriptions $descriptions Descriptive prose as PLAIN TEXT, kept in separate fields
     *                                         rather than merged: an engine that can weight has to be able
     *                                         to tell a summary from a specification sheet from robot-facing
     *                                         metadata. Markup is stripped once here, never per engine.
     *                                         Unlike title and alias, carries NO default-language fallback:
     *                                         an untranslated description is absent, not borrowed.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly DocumentId $id,
        public readonly string $title = '',
        public readonly string $alias = '',
        public readonly string $sku = '',
        public readonly string $gtin = '',
        public readonly string $mpn = '',
        public readonly array $manufacturers = [],
        public readonly array $categories = [],
        public readonly array $itemFields = [],
        public readonly array $combinations = [],
        public readonly int $version = 0,
        public readonly SearchDescriptions $descriptions = new SearchDescriptions(),
    ) {
    }

    /**
     * The document's content language, read from its identity so the two can never
     * disagree.
     *
     *
     * @since  2.0.0
     */
    public function language(): LanguageTag
    {
        return $this->id->language();
    }

    /**
     * The source item this document describes.
     *
     *
     * @since  2.0.0
     */
    public function itemId(): int
    {
        return $this->id->itemId();
    }
}
