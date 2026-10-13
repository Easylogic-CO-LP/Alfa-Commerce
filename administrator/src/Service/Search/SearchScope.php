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

use Joomla\CMS\Component\ComponentHelper;
use Throwable;

/**
 * WHAT the merchant has declared searchable. Never HOW an engine goes about it.
 *
 * INVARIANT PROTECTED: two engines can never disagree about what a search covers.
 * "Brand names are searchable" is a statement about the CATALOGUE, so it belongs to
 * the application; if each engine kept its own copy, moving a shop from the built-in
 * search to another engine would silently change which products a customer can find,
 * with nothing in the interface admitting it. The engine's job is to make the same
 * answer arrive faster, not to reach a different one.
 *
 * That division is why this type carries no storage, no query and no projection. An
 * engine reads it and decides for itself: the Indexed SQL engine folds the named parts into
 * one text column, an analysed-field engine maps them to separate fields with its own
 * weights, a join-based provider adds a predicate per part. All three honour the same
 * declaration.
 *
 * The UNCONDITIONAL core (product name, item and variant SKU/GTIN/MPN, and the
 * values of fields the merchant already marked searchable) is deliberately not
 * represented. Those are not preferences: a catalogue search that cannot match a
 * product's own name or the code printed on its label is broken rather than
 * configured, so there is no switch to get wrong.
 *
 * Aliases sit just outside that core: the alias is the URL form of the name (
 * lowercased, accent-free, transliterated), so searching it is what lets a query
 * typed without accents or in Latin characters match. That is wanted almost
 * everywhere, hence ON by default, but it is still a derived spelling rather than
 * the name itself, so the merchant may switch it off. One declaration covers every
 * alias the catalogue stores: item, brand, category and field-value aliases.
 *
 * @since  2.0.0
 */
final class SearchScope
{
    /** Aliases (URL slugs) of items, brands, categories and field values. */
    public const PART_ALIASES = 'aliases';

    /** Manufacturer / brand names. */
    public const PART_MANUFACTURERS = 'manufacturers';

    /** Assigned category names. */
    public const PART_CATEGORIES = 'categories';

    /** The merchant's short description. */
    public const PART_SHORT_DESC = 'short_desc';

    /** The long description. */
    public const PART_FULL_DESC = 'full_desc';

    /** Meta title and meta description. */
    public const PART_META_TEXT = 'meta_text';

    /**
     * Component parameter backing each part.
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    private const PARAM_KEYS = [
        self::PART_ALIASES => 'search_index_aliases',
        self::PART_MANUFACTURERS => 'search_index_manufacturers',
        self::PART_CATEGORIES => 'search_index_categories',
        self::PART_SHORT_DESC => 'search_index_short_desc',
        self::PART_FULL_DESC => 'search_index_full_desc',
        self::PART_META_TEXT => 'search_index_meta_text',
    ];

    /**
     * @param bool $aliases Search aliases: the URL slugs of items, brands,
     *                      categories and field values. ON by default: the alias
     *                      is the accent-free, transliterated form of the name,
     *                      which is what lets an unaccented or Latin-typed query
     *                      match. Off means names are matched only as written.
     * @param bool $manufacturers Search brand names. ON by default: a brand is a term
     *                            shoppers type with intent, brand sets are small, and
     *                            returning that brand's products is the wanted answer.
     * @param bool $categories Search category names. OFF by default: a matcher with
     *                         no notion of relevance returns everything inside the
     *                         category and buries the product named that way.
     * @param bool $shortDesc Search short descriptions. OFF by default; broadens
     *                        recall at the cost of precision.
     * @param bool $fullDesc Search long descriptions. OFF by default, and the most
     *                       damaging to precision: common words then appear in
     *                       nearly every product.
     * @param bool $metaText Search meta title and description. OFF by default:
     *                       written for robots and routinely duplicated.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly bool $aliases = true,
        public readonly bool $manufacturers = true,
        public readonly bool $categories = false,
        public readonly bool $shortDesc = false,
        public readonly bool $fullDesc = false,
        public readonly bool $metaText = false,
    ) {
    }

    /**
     * Read the merchant's declaration from the component configuration.
     *
     * Never throws. An unreadable configuration degrades to the documented defaults
     * rather than failing a storefront search or aborting a rebuild mid-flight.
     *
     *
     * @since  2.0.0
     */
    public static function fromConfig(): self
    {
        try {
            $params = ComponentHelper::getParams('com_alfa');

            return new self(
                aliases:       (bool) $params->get(self::PARAM_KEYS[self::PART_ALIASES], 1),
                manufacturers: (bool) $params->get(self::PARAM_KEYS[self::PART_MANUFACTURERS], 1),
                categories:    (bool) $params->get(self::PARAM_KEYS[self::PART_CATEGORIES], 0),
                shortDesc:     (bool) $params->get(self::PARAM_KEYS[self::PART_SHORT_DESC], 0),
                fullDesc:      (bool) $params->get(self::PARAM_KEYS[self::PART_FULL_DESC], 0),
                metaText:      (bool) $params->get(self::PARAM_KEYS[self::PART_META_TEXT], 0),
            );
        } catch (Throwable $e) {
            return new self();
        }
    }

    /**
     * Whether a named part is in scope.
     *
     * @param string $part One of the PART_* constants.
     *
     * @return bool False for an unknown part, so a caller asking about something this
     *              version has never heard of degrades instead of failing.
     *
     * @since  2.0.0
     */
    private function includes(string $part): bool
    {
        return match ($part) {
            self::PART_ALIASES => $this->aliases,
            self::PART_MANUFACTURERS => $this->manufacturers,
            self::PART_CATEGORIES => $this->categories,
            self::PART_SHORT_DESC => $this->shortDesc,
            self::PART_FULL_DESC => $this->fullDesc,
            self::PART_META_TEXT => $this->metaText,
            default => false,
        };
    }

    /**
     * The parts in scope, in a stable order.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public function enabled(): array
    {
        $enabled = array_values(array_filter(
            array_keys(self::PARAM_KEYS),
            fn (string $part): bool => $this->includes($part),
        ));

        sort($enabled);

        return $enabled;
    }

    /**
     * A fingerprint of the declaration, for engines that store a derived copy.
     *
     * Engine-agnostic on purpose: it covers what the merchant asked for and the shape
     * of the document that carries it, and nothing about how any one engine renders
     * that. An engine whose own output can change independently (because it altered
     * what it always includes, or its analyser, or its mapping) composes this with a
     * version of its own: the Indexed SQL engine composes this digest with a version
 * constant on its own text projection.
     *
     * Only parts in scope are listed, so introducing a new part that defaults off does
     * not demand a rebuild that would produce byte-identical results.
     *
     * @return string 64-character lowercase hex digest.
     *
     * @since  2.0.0
     */
    public function signature(): string
    {
        return hash('sha256', implode('|', [
            'doc:' . SearchDocument::PAYLOAD_VERSION,
            'scope:' . implode(',', $this->enabled()),
        ]));
    }
}
