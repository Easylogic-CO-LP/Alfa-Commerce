<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;

/**
 * The WIRE CONTRACT for catalogue search: {@see CatalogSearchResults} flattened to plain
 * arrays a JSON consumer can rely on.
 *
 * WHY THIS IS A SEPARATE CLASS FROM THE RESULT MODEL. The result model carries typed
 * hits and each hit its hydrated row: whatever columns those queries happen to select.
 * Handing rows to a client would publish the database schema as an API: a consumer would
 * bind to `short_desc`, and renaming a column would break a third-party integration.
 * This class is the one place that says what a search result IS to the outside world.
 *
 * THE CONTRACT IS VERSIONED BY THE INTEGER `contract` FIELD: currently 3 (contract 1
 * was the pre-entity-typing shape: string suggestions, no event/redirect keys; contract
 * 2 published the price enrichment's amounts and discount percentage as JSON numbers,
 * contract 3 as exact decimal strings).
 * ADDITIVE keys are legal within a contract number; a key removal, retype or semantic
 * change bumps it. The number is the one speculative field this payload admits, because
 * it is the field that makes every future change cheap: a consumer pins the number,
 * the suite asserts it, and drift is a failure instead of a surprise.
 *
 * ENRICHMENT IS REQUEST-OPT-IN, NEVER DEFAULT. The known next row additions (price,
 * image, stock) are per-user, per-currency, per-moment computations; landing them on
 * every consumer would make each keystroke's TYPEAHEAD call pay for them. When a surface
 * needs them it will ask (a `fields=`-style parameter, the same idiom as the per-section
 * flags), and the base row stays `{id, name, url, description}`.
 *
 * DESCRIPTIONS ARE CLEANED BUT NOT TRUNCATED (a property of the hit, decided at
 * hydration): stripping markup is the component's safety concern; deciding that 50
 * characters fit a dropdown is presentation.
 *
 * @since  2.0.0
 */
final class CatalogSearchPayload
{
    /**
     * The wire contract number this class emits.
     *
     * @var int
     *
     * @since  2.0.0
     */
    public const CONTRACT = 3;

    /**
     * Section keys, in the canonical order a surface presents them when the result
     * carries no other order. Kept as the section-name vocabulary the suite asserts;
     * the EMISSION order now follows the result's own section order.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    public const SECTIONS = ['products', 'categories', 'manufacturers'];

    /**
     * Flatten results into the wire shape.
     *
     * ONLY NON-EMPTY SECTIONS ARE EMITTED, IN THE RESULT'S PRESENTATION ORDER: whether
     * and where a section appears is a property of the result, not a decision each
     * consumer re-derives.
     *
     * @param CatalogSearchResults $results What the search produced.
     * @param string $query The keyword as typed, echoed back so a client
     *                      can discard a response that lost a race with a
     *                      later keystroke.
     * @param float|null $elapsedMs How long the server spent producing this
     *                              answer; null when the caller did not measure.
     * @param string[] $fields Requested row enrichments (subset of
     *                         {image, price}), the docblock's long-promised
     *                         opt-in. When a field is requested its keys
     *                         appear on EVERY row of every section (null
     *                         where the entity has no such thing), so the
     *                         row shape stays uniform within one response.
     *                         `price` is the shopper's own final unit price
     *                         as a MONEY STRUCTURE: amount (the exact
     *                         unit as a decimal string, as REST
     *                         publishes it), formatted,
     *                         currency (per user, group and currency: never
     *                         HTTP-cache an enriched response across
     *                         sessions); `price_base` and
     *                         `price_discount_percent` ride with it,
     *                         non-null only while a discount makes the
     *                         was-price worth showing.
     *
     * @return array{contract: int, query: string, total: int,
     *               event: int|string|null, redirect: string|null,
     *               sections: object, suggestions: list<array{text: string, type: string|null}>}
     *         `sections` is an object map of section name => list of
     *         `{id, name, url, description}`, carrying only the sections that have rows.
     *         `event` is the opaque attribution handle (null = search not recorded) a
     *         consumer rendering its own results page stamps on the click beacon.
     *         `redirect` non-null means: do not render sections; navigate there. Born
     *         with the contract so no consumer can treat a redirected search as empty.
     *
     * @since  2.0.0
     */
    public static function fromResults(CatalogSearchResults $results, string $query, ?float $elapsedMs = null, array $fields = []): array
    {
        $sections = [];

        $enrichers = self::enrichers($fields);

        foreach ($results->sections as $section) {
            if ($section->isEmpty()) {
                continue;
            }

            $sections[$section->entity->sectionKey()] = array_map(
                static function (CatalogSearchHit $hit) use ($enrichers): array {
                    $row = [
                        'id' => $hit->id,
                        'name' => $hit->title,
                        'url' => $hit->url,
                        'description' => $hit->description,
                    ];

                    foreach ($enrichers as $read) {
                        $row += $read($hit);
                    }

                    return $row;
                },
                array_values($section->hits),
            );
        }

        return [
            'contract' => self::CONTRACT,
            'query' => $query,
            'total' => $results->total(),
            'event' => $results->eventId,
            // How long the SERVER spent producing this answer, in milliseconds: the
            // docblock's long-reserved slot. Null when the caller did not measure;
            // always present so the empty and populated shapes stay identical.
            'elapsed' => $elapsedMs !== null ? round($elapsedMs, 2) : null,
            'redirect' => $results->redirect,
            // CAST SO THE WIRE TYPE IS STABLE. `sections` is a MAP keyed by section name, but
            // PHP's empty array encodes as JSON `[]` while a populated one encodes as `{}`,
            // so a no-results response would hand a typed consumer an array where it expects
            // an object, and only on the empty path. The cast makes it an object always.
            'sections' => (object) $sections,
            'suggestions' => self::suggestions($results->suggestions),
        ];
    }

    /**
     * The wire readers for each opt-in row enrichment: THE one list to extend when a
     * new optional field joins the contract (stock, badge, rating, ...): add an entry
     * here and its name to the consumer-facing whitelist; the row map never changes.
     *
     * Each reader returns a key=>value FRAGMENT merged onto the base row, so one
     * enrichment may emit several related keys (price travels with its was-price).
     * Readers tolerate every entity: a category has no media or price, so its rows
     * carry honest nulls under the same keys: uniform shape within one response.
     *
     * @param string[] $fields Requested enrichment names; unknown names are ignored.
     *
     * @return array<string, callable(CatalogSearchHit): array<string, string|null>>
     *
     * @since  2.0.0
     */
    private static function enrichers(array $fields): array
    {
        $available = [
            // The variant-aware storefront thumbnail (placeholder included).
            'image' => static fn (CatalogSearchHit $hit): array => [
                'image' => $hit->row->medias[0]->thumbnail_url ?? null,
            ],
            // MONEY STRUCTURES, not bare strings: `formatted` inside each is the
            // shop's own rendering (server-authoritative: no consumer reimplements
            // currency patterns in JS), while amount/currency stay machine-readable
            // for consumers that compute: badges, sorting, structured data. The
            // surface decides WHAT to show; the shop decides how money reads.
            // getPrice()/getBasePriceWithTax() are the exact pair the storefront
            // price layout displays: final vs was; was + percent only while
            // discounted. Their amounts are the exact units, published as REST
            // publishes them (PriceUnits::published(): exact decimal strings,
            // minor units only on the scale), and the percentage its two-place
            // text; their `formatted` is the unit text the price layout prints
            // for the same figure.
            'price' => static function (CatalogSearchHit $hit): array {
                $price = $hit->row->price ?? null;
                $discounted = $price && $price->hasDiscount();
                $units = $price ? PriceUnits::page(price: $price, figures: ['final', 'base_with_tax']) : null;

                return [
                    'price' => $price ? array_replace($price->getPrice()->toArray(), PriceUnits::published(price: $price, figure: 'final', text: $units['final'])) : null,
                    'price_base' => $discounted ? array_replace($price->getBasePriceWithTax()->toArray(), PriceUnits::published(price: $price, figure: 'base_with_tax', text: $units['base_with_tax'])) : null,
                    'price_discount_percent' => $discounted ? PublishedDecimal::percent($price->getSavingsPercent()) : null,
                ];
            },
        ];

        return array_intersect_key($available, array_flip($fields));
    }

    /**
     * Flatten suggestions into the wire shape.
     *
     * PUBLIC BECAUSE THE SUGGEST ENDPOINT EMITS THE SAME ROWS: one mapping site, so the
     * two endpoints can never drift apart on what a suggestion looks like. The entity
     * claim travels in the WIRE vocabulary (`sectionKey()` spellings): a raw enum encode
     * would leak `manufacturer` where the wire says `manufacturers`, and the wire speaks
     * one dialect.
     *
     * @param SearchSuggestion[] $suggestions Typed suggestions.
     *
     * @return list<array{text: string, type: string|null}>
     *
     * @since  2.0.0
     */
    public static function suggestions(array $suggestions): array
    {
        return array_map(
            static fn (SearchSuggestion $s): array => [
                'text' => $s->text,
                'type' => $s->entity?->sectionKey(),
            ],
            array_values($suggestions),
        );
    }
}
