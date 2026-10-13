<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * WHAT KIND of catalogue thing a search names: the one spelling of entity identity
 * shared by queries, results, hits, suggestions and (from Phase B) the index itself.
 *
 * THE BACKED VALUES ARE {@see DocumentId} TYPE SEGMENTS. `DocumentId::TYPE_ITEM`
 * reserved the `type` key segment precisely so more document kinds could arrive
 * "without every existing key changing"; this enum is the constructible spelling of
 * that reservation, so a document key, an index row and a result envelope can never
 * disagree about what an entity is called.
 *
 * THE WIRE SPEAKS A DIFFERENT DIALECT ON PURPOSE. Section keys are `products` /
 * `categories` / `manufacturers` (plural, shopper-facing, already asserted by the
 * contract suite), while the domain says `item` (#__alfa_items, ItemsModel,
 * DocumentId::forItem). {@see self::sectionKey()} is the bridge; neither vocabulary
 * leaks into the other, and no call site ever spells the mapping itself.
 *
 * A CLOSED SET, DELIBERATELY. An entity type implies a Site model to hydrate from, an
 * ACL policy, routing, a section vocabulary and analytics semantics: an open plugin
 * registry here would be a framework nobody asked for. Adding a case (articles,
 * supplier feeds) is a deliberate core change, and the two policy methods below force
 * each new case to decide its analytics and ranking behaviour ON ARRIVAL instead of
 * inheriting a silent downstream default: the same discipline
 * {@see SearchIntent::recordsAnalytics()} enforces for intents.
 *
 * NOT THE SAME CONCEPT AS {@see SearchScope}. `SearchScope::$categories` /
 * `$manufacturers` answer "may a PRODUCT be found by its brand/category name": they
 * gate FIELDS of product documents. This enum names WHOSE ROWS a search returns. The
 * two are orthogonal: a merchant may index brand names into products while no
 * manufacturer entity search exists at all.
 *
 * @since  2.0.0
 */
enum SearchEntity: string
{
    /**
     * A product: a #__alfa_items row.
     *
     * @since  2.0.0
     */
    case ITEM = 'item';

    /**
     * A category: a #__alfa_categories row.
     *
     * @since  2.0.0
     */
    case CATEGORY = 'category';

    /**
     * A manufacturer: a #__alfa_manufacturers row.
     *
     * @since  2.0.0
     */
    case MANUFACTURER = 'manufacturer';

    /**
     * The wire/section spelling of this entity.
     *
     * The canonical key a payload section carries and a surface styles by
     * (`search-section-products`), and the order-independent name the suite asserts.
     *
     * @return string `products` | `categories` | `manufacturers`.
     *
     * @since  2.0.0
     */
    public function sectionKey(): string
    {
        return match ($this) {
            self::ITEM => 'products',
            self::CATEGORY => 'categories',
            self::MANUFACTURER => 'manufacturers',
        };
    }

    /**
     * Whether a search FOR this entity mints the analytics search event.
     *
     * TRUE ONLY FOR ITEM, and the policy lives here so a future case must decide it
     * explicitly. One expressed search = one event, and the product retrieval is the
     * recorded fact of it: `results_count` means product hits, the meaning every
     * report (zero-result, CTR, funnel) was built on. If category retrieval also
     * recorded, one shopper search would write two rows and every denominator would
     * quietly double.
     *
     * @return bool True only for {@see self::ITEM}.
     *
     * @since  2.0.0
     */
    public function recordsSearchAnalytics(): bool
    {
        return $this === self::ITEM;
    }

    /**
     * Whether candidates of this entity flow through commercial re-ranking.
     *
     * TRUE ONLY FOR ITEM: the signals read product tables (`#__alfa_order_items`,
     * `#__alfa_items` stock), merchant rules act on product ids, and every structure in
     * the pipeline keys plain int item ids. A category id entering those maps would
     * collide with a product id silently: this policy is what makes that path
     * unreachable rather than merely untested.
     *
     * @return bool True only for {@see self::ITEM}.
     *
     * @since  2.0.0
     */
    public function participatesInCommercialRanking(): bool
    {
        return $this === self::ITEM;
    }
}
